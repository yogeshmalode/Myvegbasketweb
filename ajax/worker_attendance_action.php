<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    require_csrf(trim((string)($input['csrf_token'] ?? '')));
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Session verification failed. Refresh and try again.']);
    exit;
}

$action = trim((string)($input['action'] ?? ''));
if (!in_array($action, ['clock_in', 'clock_out'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported attendance action']);
    exit;
}
$shiftSummary = trim((string)($input['shift_summary'] ?? ''));
if (strlen($shiftSummary) > 3000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Shift summary must be 3000 characters or fewer']);
    exit;
}

if (is_rider_logged_in()) {
    $workerType = 'rider';
    $workerId = (int)$_SESSION['rider_id'];
} elseif (is_admin_logged_in() && in_array(admin_role(), ['staff', 'delivery'], true)) {
    $workerType = 'staff';
    $workerId = (int)$_SESSION['admin_id'];
} else {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only signed-in riders and staff can record attendance']);
    exit;
}

try {
    $pdo->beginTransaction();

    $workerTable = $workerType === 'rider' ? 'riders' : 'admins';
    $workerCondition = $workerType === 'rider' ? ' AND is_active = 1' : " AND role IN ('staff', 'delivery')";
    $workerStmt = $pdo->prepare("SELECT id FROM $workerTable WHERE id = ?$workerCondition FOR UPDATE");
    $workerStmt->execute([$workerId]);
    if (!$workerStmt->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Worker account was not found']);
        exit;
    }

    $compStmt = $pdo->prepare("SELECT employment_type, pay_mode FROM worker_compensation
        WHERE worker_type = ? AND worker_id = ?");
    $compStmt->execute([$workerType, $workerId]);
    $compensation = $compStmt->fetch() ?: [
        'employment_type' => 'full_time',
        'pay_mode' => 'attendance_only',
    ];

    $openStmt = $pdo->prepare("SELECT id, clock_in_at FROM worker_attendance
        WHERE worker_type = ? AND worker_id = ? AND clock_out_at IS NULL
        ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $openStmt->execute([$workerType, $workerId]);
    $openShift = $openStmt->fetch();

    if ($action === 'clock_in') {
        if ($openShift) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'You are already clocked in']);
            exit;
        }

        $pdo->prepare('INSERT INTO worker_attendance (worker_type, worker_id, clock_in_at) VALUES (?, ?, UTC_TIMESTAMP())')
            ->execute([$workerType, $workerId]);

        if ($workerType === 'rider') {
            $activeOrderStmt = $pdo->prepare("SELECT 1 FROM orders
                WHERE rider_id = ? AND order_status IN ('delivery_partner_assigned', 'out_for_delivery', 'arriving_soon')
                LIMIT 1");
            $activeOrderStmt->execute([$workerId]);
            $hasActiveOrder = (bool)$activeOrderStmt->fetchColumn();
            $pdo->prepare('UPDATE riders SET availability_status = ? WHERE id = ?')
                ->execute([$hasActiveOrder ? 'busy' : 'available', $workerId]);
        }

        $pdo->commit();
        $assignedOrderId = $workerType === 'rider' && !$hasActiveOrder
            ? auto_assign_next_order_to_rider($pdo, $workerId)
            : null;
        echo json_encode([
            'success' => true,
            'action' => 'clock_in',
            'assigned_order_id' => $assignedOrderId ? (int)$assignedOrderId : null,
        ]);
        exit;
    }

    if (!$openShift) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'You are not clocked in']);
        exit;
    }
    if ($compensation['employment_type'] !== 'gig' && $shiftSummary === '') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Enter a short shift summary before clocking out']);
        exit;
    }

    if ($workerType === 'rider') {
        $activeOrderStmt = $pdo->prepare("SELECT id FROM orders
            WHERE rider_id = ? AND order_status IN ('delivery_partner_assigned', 'out_for_delivery', 'arriving_soon')
            LIMIT 1 FOR UPDATE");
        $activeOrderStmt->execute([$workerId]);
        if ($activeOrderStmt->fetchColumn()) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Complete or reject your active delivery before clocking out']);
            exit;
        }
        $pdo->prepare("UPDATE riders SET availability_status = 'offline' WHERE id = ?")->execute([$workerId]);
    }

    $pdo->prepare('UPDATE worker_attendance SET clock_out_at = UTC_TIMESTAMP(), shift_summary = ? WHERE id = ? AND clock_out_at IS NULL')
        ->execute([$shiftSummary !== '' ? $shiftSummary : null, (int)$openShift['id']]);
    $pdo->commit();
    echo json_encode(['success' => true, 'action' => 'clock_out']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Worker attendance action failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Attendance could not be saved. Please try again.']);
}
