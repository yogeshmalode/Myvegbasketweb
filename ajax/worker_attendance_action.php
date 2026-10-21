<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/worker_attendance.php';
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
if (!in_array($action, ['clock_in', 'clock_out', 'request_overtime', 'auto_clock_out'], true)) {
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
    close_due_worker_attendance($pdo, $workerType, $workerId);
    if ($action === 'auto_clock_out') {
        $openCheck = $pdo->prepare("SELECT attendance.clock_in_at, compensation.shift_work_minutes,
                compensation.break_minutes, overtime.status AS overtime_status,
                overtime.requested_minutes AS overtime_minutes
            FROM worker_attendance attendance
            LEFT JOIN worker_compensation compensation
              ON compensation.worker_type = attendance.worker_type AND compensation.worker_id = attendance.worker_id
            LEFT JOIN worker_overtime_requests overtime ON overtime.attendance_id = attendance.id
            WHERE attendance.worker_type = ? AND attendance.worker_id = ? AND attendance.clock_out_at IS NULL
            ORDER BY attendance.id DESC LIMIT 1");
        $openCheck->execute([$workerType, $workerId]);
        $openShift = $openCheck->fetch();
        $response = ['success' => true, 'action' => 'auto_clock_out', 'closed' => !$openShift];
        if ($openShift) {
            $scheduledEnd = worker_attendance_end_at($openShift);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($scheduledEnd > $now) {
                $response['next_end'] = $scheduledEnd->getTimestamp();
            }
        }
        echo json_encode($response);
        exit;
    }

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

    $compStmt = $pdo->prepare("SELECT employment_type, pay_mode, shift_work_minutes, break_minutes FROM worker_compensation
        WHERE worker_type = ? AND worker_id = ?");
    $compStmt->execute([$workerType, $workerId]);
    $compensation = $compStmt->fetch() ?: [
        'employment_type' => 'full_time',
        'pay_mode' => 'attendance_only',
        'shift_work_minutes' => 480,
        'break_minutes' => 60,
    ];

    $openStmt = $pdo->prepare("SELECT attendance.id, attendance.clock_in_at,
            overtime.status AS overtime_status, overtime.requested_minutes AS overtime_minutes
        FROM worker_attendance attendance
        LEFT JOIN worker_overtime_requests overtime ON overtime.attendance_id = attendance.id
        WHERE attendance.worker_type = ? AND attendance.worker_id = ? AND attendance.clock_out_at IS NULL
        ORDER BY attendance.id DESC LIMIT 1 FOR UPDATE");
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

    if ($action === 'request_overtime') {
        $requestedMinutes = filter_var($input['requested_minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($requestedMinutes === false || !in_array($requestedMinutes, [30, 60, 120], true)) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Choose 30, 60, or 120 minutes of overtime']);
            exit;
        }
        if ($compensation['employment_type'] === 'gig') {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Overtime requests are for permanent and part-time workers']);
            exit;
        }
        if (!$openShift) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Clock in before requesting overtime']);
            exit;
        }
        if (!empty($openShift['overtime_status'])) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'An overtime request has already been submitted for this shift']);
            exit;
        }
        $baseShiftEnd = new DateTimeImmutable($openShift['clock_in_at'], new DateTimeZone('UTC'));
        $baseShiftEnd = $baseShiftEnd->modify('+' . ((int)$compensation['shift_work_minutes'] + (int)$compensation['break_minutes']) . ' minutes');
        if ($baseShiftEnd <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'The regular shift has ended. Overtime must be approved before shift end.']);
            exit;
        }
        $request = $pdo->prepare("INSERT INTO worker_overtime_requests (attendance_id, requested_minutes)
            VALUES (?, ?)");
        $request->execute([(int)$openShift['id'], $requestedMinutes]);
        $pdo->commit();
        echo json_encode(['success' => true, 'action' => 'request_overtime']);
        exit;
    }

    if (!$openShift) {
        $pdo->rollBack();
        if ($action === 'clock_out') {
            echo json_encode(['success' => true, 'action' => 'auto_clock_out']);
            exit;
        }
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

    $clockOutAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $scheduledEnd = worker_attendance_end_at($openShift + [
        'shift_work_minutes' => $compensation['shift_work_minutes'],
        'break_minutes' => $compensation['break_minutes'],
    ]);
    if ($clockOutAt > $scheduledEnd) {
        $clockOutAt = $scheduledEnd;
    }
    $pdo->prepare('UPDATE worker_attendance SET clock_out_at = ?, shift_summary = ? WHERE id = ? AND clock_out_at IS NULL')
        ->execute([$clockOutAt->format('Y-m-d H:i:s'), $shiftSummary !== '' ? $shiftSummary : null, (int)$openShift['id']]);
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
