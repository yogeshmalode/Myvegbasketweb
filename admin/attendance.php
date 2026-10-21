<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../lib/worker_attendance.php';
require_role(['admin', 'staff', 'delivery']);
$page_title = 'Worker Attendance';
$isAdmin = admin_role() === 'admin';
$workerId = (int)($_SESSION['admin_id'] ?? 0);
close_due_worker_attendance($pdo, $isAdmin ? null : 'staff', $isAdmin ? null : $workerId);

function attendance_local_datetime_to_utc($value) {
    $value = trim((string)$value);
    $local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Kolkata'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$local || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $local->format('Y-m-d\TH:i') !== $value) {
        return null;
    }
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['decide_overtime'])) {
        require_csrf();
        if (!$isAdmin) {
            http_response_code(403);
            exit('Access denied.');
        }

        $requestId = (int)($_POST['overtime_request_id'] ?? 0);
        $decision = $_POST['overtime_decision'] ?? '';
        if ($requestId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Choose a valid overtime request decision.'];
            header('Location: attendance.php');
            exit;
        }

        try {
            $pdo->beginTransaction();
            $requestStmt = $pdo->prepare("SELECT request.id, request.status, attendance.worker_type,
                    attendance.worker_id, attendance.clock_in_at, compensation.shift_work_minutes,
                    compensation.break_minutes
                FROM worker_overtime_requests request
                JOIN worker_attendance attendance ON attendance.id = request.attendance_id
                LEFT JOIN worker_compensation compensation
                  ON compensation.worker_type = attendance.worker_type AND compensation.worker_id = attendance.worker_id
                WHERE request.id = ? AND attendance.clock_out_at IS NULL
                FOR UPDATE");
            $requestStmt->execute([$requestId]);
            $overtimeRequest = $requestStmt->fetch();
            if (!$overtimeRequest || $overtimeRequest['status'] !== 'pending') {
                throw new RuntimeException('This overtime request is no longer pending or the shift has ended.');
            }
            if ($decision === 'approved') {
                $scheduledEnd = (new DateTimeImmutable($overtimeRequest['clock_in_at'], new DateTimeZone('UTC')))
                    ->modify('+' . ((int)($overtimeRequest['shift_work_minutes'] ?? 480) + (int)($overtimeRequest['break_minutes'] ?? 60)) . ' minutes');
                if ($scheduledEnd <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                    throw new RuntimeException('Overtime must be approved before the regular shift ends.');
                }
            }
            $pdo->prepare('UPDATE worker_overtime_requests SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$decision, $workerId, $requestId]);
            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Overtime request ' . $decision . '.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['type' => 'error', 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Overtime decision could not be saved.'];
            if (!$e instanceof RuntimeException) error_log('Overtime decision failed: ' . $e->getMessage());
        }
        header('Location: attendance.php');
        exit;
    }

    if (isset($_POST['save_common_gig_km'])) {
        require_csrf();
        if (!$isAdmin) {
            http_response_code(403);
            exit('Access denied.');
        }
        $commonKm = filter_var($_POST['common_billable_km'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($commonKm === false || $commonKm <= 0 || $commonKm > 500) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Enter shared billable distance greater than 0 and no more than 500 km.'];
            header('Location: attendance.php');
            exit;
        }
        $saveKm = $pdo->prepare("INSERT INTO gig_payout_settings (setting_id, common_billable_km, updated_by)
            VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE common_billable_km = VALUES(common_billable_km),
                updated_by = VALUES(updated_by)");
        $saveKm->execute([$commonKm, $workerId]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Shared gig billable distance saved. It will be used for all gig rider payouts.'];
        header('Location: attendance.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_worker_compensation'])) {
        require_csrf();
        if (!$isAdmin) {
            http_response_code(403);
            exit('Access denied.');
        }

        $type = $_POST['worker_type'] ?? '';
        $id = (int)($_POST['worker_id'] ?? 0);
        $employmentType = $_POST['employment_type'] ?? '';
        $payMode = $_POST['pay_mode'] ?? '';
        $workHours = filter_var($_POST['shift_work_hours'] ?? null, FILTER_VALIDATE_FLOAT);
        $breakMinutes = filter_var($_POST['break_minutes'] ?? null, FILTER_VALIDATE_INT);
        $hourlyRate = trim((string)($_POST['hourly_rate'] ?? ''));
        $monthlySalary = trim((string)($_POST['monthly_salary'] ?? ''));
        $perKmRate = trim((string)($_POST['per_km_rate'] ?? ''));

        $valid = in_array($type, ['staff', 'rider'], true)
            && $id > 0
            && in_array($employmentType, ['full_time', 'part_time', 'gig'], true)
            && in_array($payMode, ['attendance_only', 'hourly', 'monthly', 'per_km'], true)
            && $workHours !== false && $workHours >= 1 && $workHours <= 16
            && $breakMinutes !== false && $breakMinutes >= 0 && $breakMinutes <= 240;
        if ($employmentType === 'gig') {
            $valid = $valid && $type === 'rider' && $payMode === 'per_km'
                && is_numeric($perKmRate) && (float)$perKmRate >= 0;
        } else {
            $valid = $valid && $payMode !== 'per_km';
            if ($payMode === 'hourly') $valid = $valid && is_numeric($hourlyRate) && (float)$hourlyRate >= 0;
            if ($payMode === 'monthly') $valid = $valid && is_numeric($monthlySalary) && (float)$monthlySalary >= 0;
        }
        if (!$valid) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Check the worker type, shift hours, break minutes, and pay rate. Gig pay is only available for riders.'];
            header('Location: attendance.php');
            exit;
        }

        try {
            if ($type === 'rider') {
                $workerCheck = $pdo->prepare('SELECT id FROM riders WHERE id = ?');
            } else {
                $workerCheck = $pdo->prepare("SELECT id FROM admins WHERE id = ? AND role IN ('staff', 'delivery')");
            }
            $workerCheck->execute([$id]);
            if (!$workerCheck->fetchColumn()) {
                throw new RuntimeException('Worker account not found.');
            }
            $save = $pdo->prepare("INSERT INTO worker_compensation
                (worker_type, worker_id, employment_type, pay_mode, shift_work_minutes, break_minutes,
                 hourly_rate, monthly_salary, per_km_rate, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE employment_type = VALUES(employment_type),
                    pay_mode = VALUES(pay_mode), shift_work_minutes = VALUES(shift_work_minutes),
                    break_minutes = VALUES(break_minutes), hourly_rate = VALUES(hourly_rate),
                    monthly_salary = VALUES(monthly_salary), per_km_rate = VALUES(per_km_rate),
                    updated_by = VALUES(updated_by)");
            $save->execute([
                $type,
                $id,
                $employmentType,
                $payMode,
                (int)round((float)$workHours * 60),
                $breakMinutes,
                $payMode === 'hourly' ? (float)$hourlyRate : null,
                $payMode === 'monthly' ? (float)$monthlySalary : null,
                $payMode === 'per_km' ? (float)$perKmRate : null,
                $workerId,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Worker type, shift, and pay settings saved.'];
        } catch (Throwable $e) {
            if (!$e instanceof RuntimeException) error_log('Worker compensation update failed: ' . $e->getMessage());
            $_SESSION['flash'] = ['type' => 'error', 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Worker settings could not be saved.'];
        }
        header('Location: attendance.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_gig_payout'])) {
        require_csrf();
        if (!$isAdmin) {
            http_response_code(403);
            exit('Access denied.');
        }

        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Select a valid gig order for payout.'];
            header('Location: attendance.php');
            exit;
        }

        try {
            $commonKmStmt = $pdo->query('SELECT common_billable_km FROM gig_payout_settings WHERE setting_id = 1');
            $billableKm = $commonKmStmt->fetchColumn();
            if ($billableKm === false || (float)$billableKm <= 0) {
                throw new RuntimeException('Set the shared billable kilometre amount before confirming gig payouts.');
            }
            $orderStmt = $pdo->prepare("SELECT o.id, o.rider_id, o.order_status, r.name AS rider_name,
                    compensation.per_km_rate
                FROM orders o
                JOIN riders r ON r.id = o.rider_id
                JOIN worker_compensation compensation
                  ON compensation.worker_type = 'rider' AND compensation.worker_id = r.id
                WHERE o.id = ? AND o.order_status = 'delivered'
                  AND compensation.employment_type = 'gig' AND compensation.pay_mode = 'per_km'");
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            if (!$order || $order['per_km_rate'] === null) {
                throw new RuntimeException('Order is not a delivered order for a gig rider with a per-km rate.');
            }
            $rate = (float)$order['per_km_rate'];
            $payout = round((float)$billableKm * $rate, 2);
            $insert = $pdo->prepare('INSERT INTO gig_order_payouts
                (order_id, rider_id, billable_km, rate_per_km, payout_amount, confirmed_by)
                VALUES (?, ?, ?, ?, ?, ?)');
            $insert->execute([$orderId, (int)$order['rider_id'], $billableKm, $rate, $payout, $workerId]);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => number_format((float)$billableKm, 2) . ' shared km × ₹' . number_format($rate, 2) . ' = ₹' . number_format($payout, 2) . ' gig payout confirmed for order #' . $orderId . '.',
            ];
        } catch (Throwable $e) {
            if (!$e instanceof RuntimeException) error_log('Gig payout confirmation failed: ' . $e->getMessage());
            $_SESSION['flash'] = ['type' => 'error', 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Payout was already entered or could not be saved.'];
        }
        header('Location: attendance.php');
        exit;
    }

    require_csrf();
    if (!$isAdmin) {
        http_response_code(403);
        exit('Access denied.');
    }
    $attendanceId = (int)($_POST['attendance_id'] ?? 0);
    $clockOutUtc = attendance_local_datetime_to_utc($_POST['clock_out_at'] ?? '');
    $reason = trim((string)($_POST['reason'] ?? ''));
    if (!isset($_POST['adjust_attendance']) || $attendanceId <= 0 || !$clockOutUtc || $reason === '') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Enter a valid clock-out time and a reason for the correction.'];
        header('Location: attendance.php');
        exit;
    }

    try {
        $pdo->beginTransaction();
        $attendanceStmt = $pdo->prepare('SELECT id, worker_type, worker_id, clock_in_at, clock_out_at FROM worker_attendance WHERE id = ? FOR UPDATE');
        $attendanceStmt->execute([$attendanceId]);
        $attendance = $attendanceStmt->fetch();
        if (!$attendance) {
            throw new RuntimeException('Attendance record not found.');
        }

        $clockIn = new DateTimeImmutable($attendance['clock_in_at'], new DateTimeZone('UTC'));
        $clockOut = new DateTimeImmutable($clockOutUtc, new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($clockOut <= $clockIn || $clockOut > $now) {
            throw new RuntimeException('Clock-out must be after clock-in and cannot be in the future.');
        }

        $overlapStmt = $pdo->prepare('SELECT COUNT(*) FROM worker_attendance
            WHERE worker_type = ? AND worker_id = ? AND id <> ?
              AND clock_in_at < ? AND (clock_out_at IS NULL OR clock_out_at > ?)');
        $overlapStmt->execute([
            $attendance['worker_type'],
            (int)$attendance['worker_id'],
            $attendanceId,
            $clockOutUtc,
            $attendance['clock_in_at'],
        ]);
        if ((int)$overlapStmt->fetchColumn() > 0) {
            throw new RuntimeException('This correction would overlap another attendance shift.');
        }

        $auditStmt = $pdo->prepare('INSERT INTO worker_attendance_adjustments
            (attendance_id, adjusted_by, old_clock_out_at, new_clock_out_at, reason)
            VALUES (?, ?, ?, ?, ?)');
        $auditStmt->execute([
            $attendanceId,
            $workerId,
            $attendance['clock_out_at'],
            $clockOutUtc,
            $reason,
        ]);
        $pdo->prepare('UPDATE worker_attendance
            SET clock_out_at = ?, correction_reason = ?, corrected_by = ?, corrected_at = UTC_TIMESTAMP()
            WHERE id = ?')->execute([$clockOutUtc, $reason, $workerId, $attendanceId]);
        $pdo->commit();
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Attendance corrected and the change was recorded in the audit history.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash'] = ['type' => 'error', 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Attendance could not be corrected.'];
        if (!$e instanceof RuntimeException) {
            error_log('Attendance correction failed: ' . $e->getMessage());
        }
    }
    header('Location: attendance.php');
    exit;
}

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$start = $_GET['start'] ?? (new DateTimeImmutable('first day of this month', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$end = $_GET['end'] ?? $today;
if (!is_string($start)) $start = $today;
if (!is_string($end)) $end = $today;
foreach ([$start, $end] as $date) {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Kolkata'));
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        if ($date === $start) $start = $today;
        if ($date === $end) $end = $today;
    }
}
if ($start > $end) {
    [$start, $end] = [$end, $start];
}
$rangeStartUtc = (new DateTimeImmutable($start . ' 00:00:00', new DateTimeZone('Asia/Kolkata')))
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$rangeEndUtc = (new DateTimeImmutable($end . ' 00:00:00', new DateTimeZone('Asia/Kolkata')))
    ->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$selfShiftStmt = $pdo->prepare("SELECT attendance.id, attendance.clock_in_at,
        overtime.status AS overtime_status, overtime.requested_minutes AS overtime_minutes
    FROM worker_attendance attendance
    LEFT JOIN worker_overtime_requests overtime ON overtime.attendance_id = attendance.id
    WHERE attendance.worker_type = 'staff' AND attendance.worker_id = ? AND attendance.clock_out_at IS NULL
    ORDER BY attendance.id DESC LIMIT 1");
$selfShiftStmt->execute([$workerId]);
$selfOpenShift = $selfShiftStmt->fetch() ?: null;
$selfHistoryStmt = $pdo->prepare("SELECT id, clock_in_at, clock_out_at, correction_reason, shift_summary
    FROM worker_attendance WHERE worker_type = 'staff' AND worker_id = ?
    ORDER BY clock_in_at DESC LIMIT 20");
$selfHistoryStmt->execute([$workerId]);
$selfHistory = $selfHistoryStmt->fetchAll();
$selfCompensationStmt = $pdo->prepare('SELECT employment_type, shift_work_minutes, break_minutes FROM worker_compensation WHERE worker_type = ? AND worker_id = ?');
$selfCompensationStmt->execute(['staff', $workerId]);
$selfCompensation = $selfCompensationStmt->fetch() ?: ['employment_type' => 'full_time', 'shift_work_minutes' => 480, 'break_minutes' => 60];

$workers = [];
$shiftRows = [];
$workerStats = [];
$compensationProfiles = [];
$completedOrders = [];
$totalWorkedSeconds = 0;
$totalOvertimeSeconds = 0;
$gigPayoutOrders = [];
$gigPayoutTotals = [];
$commonBillableKmStmt = $pdo->query('SELECT common_billable_km FROM gig_payout_settings WHERE setting_id = 1');
$commonBillableKm = $commonBillableKmStmt->fetchColumn();
$overtimeRequests = [];
if ($isAdmin) {
    $workerRows = $pdo->query("SELECT
            CONVERT('staff' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS worker_type,
            a.id AS worker_id,
            CONVERT(a.username USING utf8mb4) COLLATE utf8mb4_unicode_ci AS worker_name,
            CONVERT(a.role USING utf8mb4) COLLATE utf8mb4_unicode_ci AS worker_role,
            CONVERT(a.email USING utf8mb4) COLLATE utf8mb4_unicode_ci AS contact,
            CONVERT(ds.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS store_name,
            a.dark_store_id IS NOT NULL AS has_store
        FROM admins a
        LEFT JOIN dark_stores ds ON ds.id = a.dark_store_id
        WHERE a.role IN ('staff', 'delivery')
        UNION ALL
        SELECT
            CONVERT('rider' USING utf8mb4) COLLATE utf8mb4_unicode_ci,
            r.id,
            CONVERT(r.name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
            CONVERT('rider' USING utf8mb4) COLLATE utf8mb4_unicode_ci,
            CONVERT(r.phone USING utf8mb4) COLLATE utf8mb4_unicode_ci,
            CONVERT(ds.name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
            r.dark_store_id IS NOT NULL
        FROM riders r
        LEFT JOIN dark_stores ds ON ds.id = r.dark_store_id
        ORDER BY worker_type, worker_name")->fetchAll();
    foreach ($workerRows as $worker) {
        $key = $worker['worker_type'] . ':' . (int)$worker['worker_id'];
        $workers[$key] = $worker;
        $workerStats[$key] = ['seconds' => 0, 'presence_seconds' => 0, 'overtime_seconds' => 0, 'shifts' => 0, 'open' => false, 'open_since' => null];
        $completedOrders[$key] = 0;
    }
    $overtimeRequests = $pdo->query("SELECT request.id, request.requested_minutes, request.requested_at,
            attendance.worker_type, attendance.worker_id, attendance.clock_in_at
        FROM worker_overtime_requests request
        JOIN worker_attendance attendance ON attendance.id = request.attendance_id
        WHERE request.status = 'pending' AND attendance.clock_out_at IS NULL
        ORDER BY request.requested_at ASC")->fetchAll();

    $compStmt = $pdo->query('SELECT * FROM worker_compensation');
    foreach ($compStmt->fetchAll() as $profile) {
        $compensationProfiles[$profile['worker_type'] . ':' . (int)$profile['worker_id']] = $profile;
    }

    $riderOrdersStmt = $pdo->prepare("SELECT rider_id, COUNT(*) AS order_count FROM orders
        WHERE order_status = 'delivered' AND delivered_at >= ? AND delivered_at < ?
          AND rider_id IS NOT NULL GROUP BY rider_id");
    $riderOrdersStmt->execute([$rangeStartUtc, $rangeEndUtc]);
    foreach ($riderOrdersStmt->fetchAll() as $row) {
        $key = 'rider:' . (int)$row['rider_id'];
        if (isset($completedOrders[$key])) $completedOrders[$key] = (int)$row['order_count'];
    }

    $pickerOrdersStmt = $pdo->prepare("SELECT assigned_picker_id, COUNT(*) AS order_count FROM orders
        WHERE packing_completed_at >= ? AND packing_completed_at < ?
          AND assigned_picker_id IS NOT NULL GROUP BY assigned_picker_id");
    $pickerOrdersStmt->execute([$rangeStartUtc, $rangeEndUtc]);
    foreach ($pickerOrdersStmt->fetchAll() as $row) {
        $key = 'staff:' . (int)$row['assigned_picker_id'];
        if (isset($completedOrders[$key])) $completedOrders[$key] = (int)$row['order_count'];
    }

    $shiftStmt = $pdo->prepare("SELECT attendance.id, attendance.worker_type, attendance.worker_id,
            attendance.clock_in_at, attendance.clock_out_at, attendance.correction_reason, attendance.shift_summary,
            overtime.status AS overtime_status, overtime.requested_minutes AS overtime_minutes
        FROM worker_attendance attendance
        LEFT JOIN worker_overtime_requests overtime ON overtime.attendance_id = attendance.id
        WHERE attendance.clock_in_at < ? AND (attendance.clock_out_at IS NULL OR attendance.clock_out_at > ?)
        ORDER BY attendance.clock_in_at DESC");
    $shiftStmt->execute([$rangeEndUtc, $rangeStartUtc]);
    $shiftRows = $shiftStmt->fetchAll();
    $rangeStart = new DateTimeImmutable($rangeStartUtc, new DateTimeZone('UTC'));
    $rangeEnd = new DateTimeImmutable($rangeEndUtc, new DateTimeZone('UTC'));
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach ($shiftRows as $shift) {
        $key = $shift['worker_type'] . ':' . (int)$shift['worker_id'];
        if (!isset($workerStats[$key])) continue;
        $profile = $compensationProfiles[$key] ?? [
            'employment_type' => 'full_time',
            'pay_mode' => 'attendance_only',
            'shift_work_minutes' => 480,
            'break_minutes' => 60,
            'hourly_rate' => null,
            'monthly_salary' => null,
        ];
        $clockIn = new DateTimeImmutable($shift['clock_in_at'], new DateTimeZone('UTC'));
        $clockOut = $shift['clock_out_at']
            ? new DateTimeImmutable($shift['clock_out_at'], new DateTimeZone('UTC'))
            : $nowUtc;
        $clippedStart = $clockIn > $rangeStart ? $clockIn : $rangeStart;
        $clippedEnd = $clockOut < $rangeEnd ? $clockOut : $rangeEnd;
        $presenceSeconds = max(0, $clippedEnd->getTimestamp() - $clippedStart->getTimestamp());
        $breakSeconds = $profile['employment_type'] === 'gig' ? 0 : (int)$profile['break_minutes'] * 60;
        $scheduledGrossSeconds = ((int)$profile['shift_work_minutes'] + (int)$profile['break_minutes']) * 60;
        $deductBreak = $presenceSeconds > 0
            && ($shift['clock_out_at'] !== null || $presenceSeconds >= $scheduledGrossSeconds)
            ? min($breakSeconds, $presenceSeconds)
            : 0;
        $overtimeSeconds = 0;
        if ($shift['overtime_status'] === 'approved') {
            $overtimeStart = $clockIn->modify('+' . $scheduledGrossSeconds . ' seconds');
            $overtimeLimit = $overtimeStart->modify('+' . ((int)$shift['overtime_minutes'] * 60) . ' seconds');
            $overtimeStart = $overtimeStart > $clippedStart ? $overtimeStart : $clippedStart;
            $overtimeEnd = $clippedEnd < $overtimeLimit ? $clippedEnd : $overtimeLimit;
            $overtimeSeconds = max(0, $overtimeEnd->getTimestamp() - $overtimeStart->getTimestamp());
        }
        $workerStats[$key]['presence_seconds'] += $presenceSeconds;
        $workerStats[$key]['seconds'] += max(0, $presenceSeconds - $deductBreak - $overtimeSeconds);
        $workerStats[$key]['overtime_seconds'] += $overtimeSeconds;
        $workerStats[$key]['shifts']++;
        $workerStats[$key]['open'] = $workerStats[$key]['open'] || $shift['clock_out_at'] === null;
        if ($shift['clock_out_at'] === null) {
            $workerStats[$key]['open_since'] = $shift['clock_in_at'];
        }
    }
    foreach ($workerStats as $stat) {
        $totalWorkedSeconds += $stat['seconds'];
        $totalOvertimeSeconds += $stat['overtime_seconds'];
    }

    $gigOrderStmt = $pdo->prepare("SELECT o.id, o.rider_id, o.customer_name, o.delivered_at, r.name AS rider_name,
            compensation.per_km_rate, payout.billable_km, payout.rate_per_km, payout.payout_amount, payout.confirmed_at
        FROM orders o
        JOIN riders r ON r.id = o.rider_id
        JOIN worker_compensation compensation
          ON compensation.worker_type = 'rider' AND compensation.worker_id = r.id
        LEFT JOIN gig_order_payouts payout ON payout.order_id = o.id
        WHERE o.order_status = 'delivered' AND o.delivered_at >= ? AND o.delivered_at < ?
          AND compensation.employment_type = 'gig'
        ORDER BY o.delivered_at DESC");
    $gigOrderStmt->execute([$rangeStartUtc, $rangeEndUtc]);
    $gigPayoutOrders = $gigOrderStmt->fetchAll();
    foreach ($gigPayoutOrders as $payout) {
        if ($payout['payout_amount'] !== null) {
            $key = 'rider:' . (int)$payout['rider_id'];
            $gigPayoutTotals[$key] = ($gigPayoutTotals[$key] ?? 0) + (float)$payout['payout_amount'];
        }
    }

    $auditStmt = $pdo->prepare("SELECT adj.attendance_id, adj.old_clock_out_at, adj.new_clock_out_at,
            adj.reason, adj.created_at, admin.username AS adjusted_by,
            attendance.worker_type, attendance.worker_id
        FROM worker_attendance_adjustments adj
        JOIN worker_attendance attendance ON attendance.id = adj.attendance_id
        JOIN admins admin ON admin.id = adj.adjusted_by
        WHERE adj.created_at >= ? AND adj.created_at < ?
        ORDER BY adj.created_at DESC LIMIT 200");
    $auditStmt->execute([$rangeStartUtc, $rangeEndUtc]);
    $adjustmentRows = $auditStmt->fetchAll();
}

function attendance_duration_label($seconds) {
    $seconds = max(0, (int)$seconds);
    return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm ' . ($seconds % 60) . 's';
}

function attendance_worker_key($value) {
    if (!is_string($value) || !preg_match('/^(staff|rider):([1-9][0-9]*)$/', $value, $matches)) {
        return null;
    }
    return $matches[1] . ':' . (int)$matches[2];
}

$selectedWorkerKey = $isAdmin ? attendance_worker_key($_GET['worker'] ?? null) : null;
$selectedWorker = $selectedWorkerKey !== null ? ($workers[$selectedWorkerKey] ?? null) : null;
$attendanceChartRows = [];
$totalCompletedOrders = array_sum($completedOrders);
if ($isAdmin) {
    foreach ($workers as $key => $worker) {
        $profile = $compensationProfiles[$key] ?? [
            'employment_type' => 'full_time',
            'shift_work_minutes' => 480,
            'break_minutes' => 60,
        ];
        $attendanceChartRows[] = [
            'name' => $worker['worker_name'],
            'key' => $key,
            'role' => $worker['worker_role'],
            'minutes' => (int)floor($workerStats[$key]['seconds'] / 60),
            'overtime_minutes' => (int)floor($workerStats[$key]['overtime_seconds'] / 60),
            'target_minutes' => $profile['employment_type'] === 'gig'
                ? 0
                : (int)$profile['shift_work_minutes'],
            'open' => $workerStats[$key]['open'],
        ];
    }
    usort($attendanceChartRows, static fn($a, $b) => $b['minutes'] <=> $a['minutes']);
    $attendanceChartRows = array_slice($attendanceChartRows, 0, 6);
}
$chartMaxMinutes = max(60, ...array_map(
    static fn($row) => max($row['minutes'] + $row['overtime_minutes'], $row['target_minutes']),
    $attendanceChartRows ?: [['minutes' => 60, 'target_minutes' => 60]]
));
$selectedWorkerPayLabel = '';
if ($selectedWorker) {
    $profile = $compensationProfiles[$selectedWorkerKey] ?? [
        'employment_type' => 'full_time', 'pay_mode' => 'attendance_only',
        'hourly_rate' => null, 'monthly_salary' => null,
    ];
    if ($profile['pay_mode'] === 'hourly' && $profile['hourly_rate'] !== null) {
        $selectedWorkerPayLabel = 'Estimated ₹' . number_format(($workerStats[$selectedWorkerKey]['seconds'] / 3600) * (float)$profile['hourly_rate'], 2);
    } elseif ($profile['pay_mode'] === 'monthly' && $profile['monthly_salary'] !== null) {
        $selectedWorkerPayLabel = '₹' . number_format((float)$profile['monthly_salary'], 2) . ' monthly salary';
    } elseif ($profile['pay_mode'] === 'per_km') {
        $selectedWorkerPayLabel = '₹' . number_format((float)($gigPayoutTotals[$selectedWorkerKey] ?? 0), 2) . ' confirmed gig payouts';
    } else {
        $selectedWorkerPayLabel = 'Attendance only';
    }
}
$selectedWorkerShifts = [];
$selectedWorkerOrders = [];
if ($isAdmin && $selectedWorker) {
    foreach ($shiftRows as $shift) {
        if ($shift['worker_type'] . ':' . (int)$shift['worker_id'] === $selectedWorkerKey) {
            $selectedWorkerShifts[] = $shift;
        }
    }
    if (substr($selectedWorkerKey, 0, 6) === 'rider:') {
        $ordersStmt = $pdo->prepare("SELECT id, customer_name, order_status, delivered_at AS completed_at
            FROM orders
            WHERE rider_id = ? AND order_status = 'delivered'
              AND delivered_at >= ? AND delivered_at < ?
            ORDER BY delivered_at DESC");
    } else {
        $ordersStmt = $pdo->prepare("SELECT id, customer_name, order_status, packing_completed_at AS completed_at
            FROM orders
            WHERE assigned_picker_id = ? AND packing_completed_at >= ? AND packing_completed_at < ?
            ORDER BY packing_completed_at DESC");
    }
    $ordersStmt->execute([(int)substr($selectedWorkerKey, strpos($selectedWorkerKey, ':') + 1), $rangeStartUtc, $rangeEndUtc]);
    $selectedWorkerOrders = $ordersStmt->fetchAll();
}

if ($isAdmin && isset($_GET['worker_report'])) {
    if ($_GET['worker_report'] !== 'csv' || !$selectedWorker) {
        http_response_code(400);
        exit('A valid worker must be selected to generate a report.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="worker_report_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $selectedWorker['worker_name']) . '_' . $start . '_to_' . $end . '.csv"');
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Worker', $selectedWorker['worker_name']]);
    fputcsv($output, ['Role', $selectedWorker['worker_role']]);
    fputcsv($output, ['Report period', $start . ' to ' . $end]);
    fputcsv($output, ['Attendance hours', attendance_duration_label($workerStats[$selectedWorkerKey]['presence_seconds'])]);
    fputcsv($output, ['Productive hours', attendance_duration_label($workerStats[$selectedWorkerKey]['seconds'])]);
    fputcsv($output, ['Approved overtime', attendance_duration_label($workerStats[$selectedWorkerKey]['overtime_seconds'])]);
    fputcsv($output, ['Orders completed', count($selectedWorkerOrders)]);
    fputcsv($output, ['Pay', $selectedWorkerPayLabel]);
    fputcsv($output, []);
    fputcsv($output, ['Shift clock-in', 'Shift clock-out', 'Presence', 'Approved overtime', 'Shift summary', 'Correction reason']);
    foreach ($selectedWorkerShifts as $shift) {
        $endTime = $shift['clock_out_at'] ?: gmdate('Y-m-d H:i:s');
        $seconds = max(0, (new DateTimeImmutable($endTime, new DateTimeZone('UTC')))->getTimestamp()
            - (new DateTimeImmutable($shift['clock_in_at'], new DateTimeZone('UTC')))->getTimestamp());
        $overtimeSeconds = 0;
        if ($shift['overtime_status'] === 'approved') {
            $regularEnd = (new DateTimeImmutable($shift['clock_in_at'], new DateTimeZone('UTC')))
                ->modify('+' . (((int)($profile['shift_work_minutes'] ?? 480) + (int)($profile['break_minutes'] ?? 60)) * 60) . ' seconds');
            $overtimeLimit = $regularEnd->modify('+' . ((int)$shift['overtime_minutes'] * 60) . ' seconds');
            $overtimeSeconds = max(0, min(
                (new DateTimeImmutable($endTime, new DateTimeZone('UTC')))->getTimestamp(),
                $overtimeLimit->getTimestamp()
            ) - $regularEnd->getTimestamp());
        }
        fputcsv($output, [
            format_ist($shift['clock_in_at']),
            $shift['clock_out_at'] ? format_ist($shift['clock_out_at']) : 'In progress',
            attendance_duration_label($seconds),
            attendance_duration_label($overtimeSeconds),
            $shift['shift_summary'] ?? '',
            $shift['correction_reason'] ?? '',
        ]);
    }
    fputcsv($output, []);
    fputcsv($output, ['Order ID', 'Customer', 'Status', 'Completed at']);
    foreach ($selectedWorkerOrders as $order) {
        fputcsv($output, [$order['id'], $order['customer_name'], $order['order_status'], format_ist($order['completed_at'])]);
    }
    fclose($output);
    exit;
}

include __DIR__ . '/includes/admin_header.php';
?>
<style>
  .attendance-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:14px; margin:18px 0; }
  .attendance-card { background:#fff; border:1px solid #dfe8df; border-radius:16px; padding:18px; box-shadow:0 8px 22px rgba(18,52,40,.04); }
  .attendance-card strong { color:#163a2e; }
  .attendance-form { display:flex; flex-wrap:wrap; gap:10px; align-items:end; }
  .attendance-form .form-group { margin:0; }
  .attendance-form input, .attendance-form button { min-height:40px; }
  .attendance-summary { font-size:1.55rem; font-weight:800; color:#0c6a4a; }
  .attendance-dashboard { --att-green:#15803d; --att-ink:#14291f; --att-muted:#708078; color:var(--att-ink); }
  .attendance-hero { position:relative; overflow:hidden; display:flex; align-items:center; justify-content:space-between; gap:20px; margin:10px 0 20px; padding:25px 28px; border-radius:22px; color:#fff; background:linear-gradient(118deg,#103f2d 0%,#147947 62%,#36a765 100%); box-shadow:0 16px 36px rgba(12,78,48,.16); }
  .attendance-hero:after { content:""; position:absolute; width:250px; height:250px; right:-54px; top:-136px; border:1px solid rgba(255,255,255,.2); border-radius:50%; box-shadow:0 0 0 28px rgba(255,255,255,.055),0 0 0 58px rgba(255,255,255,.04); }
  .attendance-hero h2 { margin:3px 0 6px; color:#fff; font-size:clamp(1.45rem,3vw,2rem); letter-spacing:-.04em; }
  .attendance-hero p { max-width:680px; margin:0; color:rgba(255,255,255,.8); font-size:.9rem; line-height:1.6; }
  .attendance-hero .attendance-hero-period { position:relative; z-index:1; flex:none; padding:10px 14px; border:1px solid rgba(255,255,255,.28); border-radius:12px; background:rgba(255,255,255,.12); font-size:.82rem; font-weight:700; }
  .attendance-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:13px; margin:0 0 20px; }
  .attendance-kpi { position:relative; overflow:hidden; min-height:118px; padding:17px 18px; border:1px solid #e7eee8; border-radius:17px; background:#fff; box-shadow:0 5px 18px rgba(19,55,37,.035); }
  .attendance-kpi:after { content:""; position:absolute; right:-24px; bottom:-42px; width:100px; height:100px; border-radius:50%; background:var(--kpi-tint,#eff8f1); }
  .attendance-kpi-top { display:flex; align-items:center; justify-content:space-between; color:#738078; font-size:.75rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
  .attendance-kpi-icon { display:grid; place-items:center; width:33px; height:33px; border-radius:11px; color:var(--kpi-color,#15803d); background:var(--kpi-tint,#eff8f1); font-size:1rem; }
  .attendance-kpi-value { position:relative; z-index:1; margin-top:12px; color:#163a2e; font-size:1.65rem; font-weight:800; letter-spacing:-.04em; line-height:1; }
  .attendance-kpi-note { position:relative; z-index:1; margin-top:7px; color:#7b8981; font-size:.75rem; }
  .attendance-insights { display:grid; grid-template-columns:minmax(0,1.45fr) minmax(260px,.8fr); gap:14px; margin:0 0 20px; }
  .attendance-panel { min-width:0; padding:19px 20px; border:1px solid #e7eee8; border-radius:18px; background:#fff; box-shadow:0 5px 18px rgba(19,55,37,.035); }
  .attendance-panel-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:17px; }
  .attendance-panel-head h3 { margin:0; color:#183c2e; font-size:1rem; }
  .attendance-panel-head p { margin:4px 0 0; color:#829087; font-size:.77rem; }
  .attendance-chart-legend { display:flex; flex-wrap:wrap; gap:12px; color:#68776e; font-size:.72rem; }
  .attendance-chart-legend span { display:inline-flex; align-items:center; gap:5px; }
  .attendance-chart-dot { display:inline-block; width:8px; height:8px; border-radius:50%; background:#29a267; }
  .attendance-chart-dot.is-overtime { background:#f0a13c; }
  .attendance-bars { display:grid; gap:13px; }
  .attendance-bar-row { display:grid; grid-template-columns:minmax(90px,145px) minmax(0,1fr) 58px; align-items:center; gap:10px; }
  .attendance-bar-name { overflow:hidden; color:#47584e; font-size:.78rem; font-weight:700; text-overflow:ellipsis; white-space:nowrap; }
  .attendance-bar-track { display:flex; overflow:hidden; height:11px; border-radius:99px; background:#f0f4f1; }
  .attendance-bar-work { display:block; height:100%; border-radius:99px; background:linear-gradient(90deg,#16834b,#38b877); }
  .attendance-bar-overtime { display:block; height:100%; background:#f0a13c; }
  .attendance-bar-value { color:#496054; font-size:.73rem; font-weight:800; text-align:right; white-space:nowrap; }
  .attendance-chart-axis { display:flex; justify-content:space-between; margin:10px 66px 0 155px; color:#99a49d; font-size:.65rem; }
  .attendance-status-list { display:grid; gap:11px; }
  .attendance-status-item { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:11px 12px; border:1px solid #edf1ee; border-radius:12px; background:#fcfdfc; }
  .attendance-status-person { display:flex; align-items:center; min-width:0; gap:10px; }
  .attendance-status-avatar { display:grid; flex:none; place-items:center; width:34px; height:34px; border-radius:12px; color:#157343; background:#eaf6ee; font-size:.78rem; font-weight:800; }
  .attendance-status-name { overflow:hidden; color:#344a3d; font-size:.78rem; font-weight:700; text-overflow:ellipsis; white-space:nowrap; }
  .attendance-status-time { margin-top:2px; color:#87938b; font-size:.69rem; }
  .attendance-status-time .worker-live-clock { color:#60776a; font-size:inherit; font-weight:700; }
  .attendance-pill { flex:none; padding:5px 8px; border-radius:99px; color:#176f43; background:#eaf7ef; font-size:.68rem; font-weight:800; }
  .attendance-pill.is-warning { color:#a15b0b; background:#fff3dd; }
  .attendance-pill.is-muted { color:#68766d; background:#f0f3f1; }
  .attendance-toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin:22px 0 10px; }
  .attendance-toolbar h3 { margin:0; color:#173c2d; font-size:1.05rem; }
  .attendance-toolbar p { margin:4px 0 0; color:#7d8982; font-size:.78rem; }
  .attendance-search { position:relative; min-width:min(100%,260px); }
  .attendance-search input { width:100%; min-height:40px; padding:8px 12px 8px 36px; border:1px solid #dfe8e1; border-radius:11px; background:#fff; }
  .attendance-search:before { content:"⌕"; position:absolute; top:5px; left:12px; color:#718078; font-size:1.25rem; }
  .attendance-filter-row { display:flex; flex-wrap:wrap; gap:7px; margin-bottom:12px; }
  .attendance-filter { padding:7px 11px; border:1px solid #e1e9e3; border-radius:99px; color:#5e7065; background:#fff; font-size:.74rem; font-weight:700; cursor:pointer; }
  .attendance-filter.is-active { border-color:#176f43; color:#fff; background:#176f43; }
  .attendance-table-wrap { border:1px solid #e7eee8; border-radius:15px; background:#fff; box-shadow:0 5px 18px rgba(19,55,37,.035); }
  .attendance-table { min-width:980px; }
  .attendance-table th { background:#f7faf7; color:#77837b; font-size:.68rem; letter-spacing:.04em; }
  .attendance-table tbody tr { transition:background .15s ease; }
  .attendance-table tbody tr:hover { background:#f8fbf8; }
  .attendance-table td { color:#48594f; font-size:.81rem; }
  .attendance-table td strong { color:#203c2e; }
  .attendance-table .attendance-worker-link { color:#176f43; text-decoration:none; }
  .attendance-table .attendance-worker-link:hover { text-decoration:underline; }
  .attendance-table-wrap { overflow-x:auto; }
  .attendance-table { width:100%; min-width:850px; border-collapse:collapse; }
  .attendance-table th,.attendance-table td { padding:11px 12px; border-bottom:1px solid #e8eee9; text-align:left; vertical-align:top; }
  .attendance-table th { background:#f2f7f3; color:#46584e; font-size:.76rem; text-transform:uppercase; }
  .attendance-correction { display:grid; gap:6px; min-width:220px; }
  .attendance-correction input { min-height:34px; }
  .attendance-muted { color:#66736b; font-size:.82rem; }
  .worker-settings-form { display:grid; gap:6px; min-width:260px; }
  .worker-settings-form input,.worker-settings-form select { min-height:34px; width:100%; }
  .worker-time-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(245px,1fr)); gap:12px; margin:18px 0; }
  .worker-time-card { display:block; color:inherit; text-decoration:none; transition:transform .15s ease,box-shadow .15s ease; }
  .worker-time-card:hover { transform:translateY(-2px); box-shadow:0 12px 28px rgba(18,52,40,.11); }
  .worker-time-card h4 { margin:0 0 5px; color:#163a2e; }
  .worker-time-card .attendance-summary { font-size:1.2rem; }
  .worker-live-clock { color:#0c6a4a; font-weight:800; font-variant-numeric:tabular-nums; font-size:1.35rem; }
  .worker-report-section { scroll-margin-top:20px; }
  .worker-report-section table { min-width:650px; }
  @media print {
    body.print-worker-report { visibility:hidden; background:#fff!important; }
    body.print-worker-report #worker-report,
    body.print-worker-report #worker-report * { visibility:visible; }
    body.print-worker-report #worker-report { position:absolute; left:0; top:0; width:100%; }
    body.print-worker-report #worker-report .no-print { display:none!important; }
    .attendance-card { box-shadow:none; }
  }
  @media(max-width:900px) { .attendance-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } .attendance-insights { grid-template-columns:1fr; } }
  @media(max-width:640px) { .attendance-form > * { flex:1 1 100%; } .attendance-hero { align-items:flex-start; flex-direction:column; padding:21px; } .attendance-hero .attendance-hero-period { align-self:flex-start; } .attendance-kpis { gap:9px; } .attendance-kpi { min-height:106px; padding:13px; } .attendance-kpi-value { font-size:1.4rem; } .attendance-panel { padding:16px 13px; } .attendance-bar-row { grid-template-columns:minmax(75px,105px) minmax(0,1fr) 48px; gap:7px; } .attendance-chart-axis { margin-left:112px; margin-right:55px; } .attendance-search { width:100%; } }
</style>

<?php if ($isAdmin): ?>
  <section class="attendance-hero">
    <div><div style="font-size:.72rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.72);">People analytics</div><h2>Attendance, at a glance</h2><p>Track your team's time, shifts and approved overtime in one clear view. Use the date range below to explore another period.</p></div>
    <div class="attendance-hero-period"><?= h(date('d M', strtotime($start))) ?> — <?= h(date('d M Y', strtotime($end))) ?></div>
  </section>
<?php else: ?>
  <div class="section-head"><h2>Worker Attendance &amp; Performance</h2>
    <p>Shift presence is measured from clock-in to clock-out. Configured break time is deducted to calculate productive hours; permanent workers add a shift summary at clock-out. For automatic clock-out when dashboards are closed, schedule <code>cron/close_worker_attendance.php</code> to run every minute in your hosting control panel.</p>
  </div>
<?php endif; ?>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-<?= h($_SESSION['flash']['type']) ?>"><?= h($_SESSION['flash']['message']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (!$isAdmin): ?>
  <section class="attendance-card">
    <h3>My Shift</h3>
    <p class="attendance-muted">
      <?= $selfOpenShift ? 'Clocked in since ' . h(format_ist($selfOpenShift['clock_in_at'])) : 'You are currently clocked out.' ?>
    </p>
    <p class="attendance-muted" data-auto-clockout-message aria-live="polite"></p>
    <div class="attendance-muted">Elapsed this shift</div>
    <div class="worker-live-clock" <?= $selfOpenShift ? 'data-live-start="' . (new DateTimeImmutable($selfOpenShift['clock_in_at'], new DateTimeZone('UTC')))->getTimestamp() . '" data-auto-end="' . worker_attendance_end_at($selfOpenShift + $selfCompensation)->getTimestamp() . '"' : '' ?>>
      <?= $selfOpenShift ? '00:00:00' : '00:00:00' ?>
    </div>
    <?php if ($selfOpenShift): ?><p class="attendance-muted">Scheduled clock-out (IST): <?= h(format_ist(worker_attendance_end_at($selfOpenShift + $selfCompensation)->format('Y-m-d H:i:s'))) ?></p><?php endif; ?>
    <button class="btn btn-primary" type="button" data-attendance-action="<?= $selfOpenShift ? 'clock_out' : 'clock_in' ?>" data-requires-summary="<?= $selfOpenShift && $selfCompensation['employment_type'] !== 'gig' ? '1' : '0' ?>">
      <?= $selfOpenShift ? 'Clock Out' : 'Clock In' ?>
    </button>
    <?php if ($selfOpenShift && $selfCompensation['employment_type'] !== 'gig'): ?>
      <p class="attendance-muted">A work summary is required when ending this shift.</p>
      <?php if (!empty($selfOpenShift['overtime_status'])): ?>
        <p class="attendance-muted">Overtime request: <?= h(ucfirst($selfOpenShift['overtime_status'])) ?> · <?= (int)$selfOpenShift['overtime_minutes'] ?> minutes.</p>
      <?php else: ?>
        <div class="attendance-form">
          <select data-overtime-minutes aria-label="Requested overtime duration">
            <option value="30">30 minutes</option><option value="60">1 hour</option><option value="120">2 hours</option>
          </select>
          <button class="btn" type="button" data-attendance-action="request_overtime">Request overtime</button>
        </div>
        <p class="attendance-muted">Request overtime before your regular shift ends. It starts only after admin approval.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <div class="section-head"><h3>My Recent Shifts</h3></div>
  <div class="table-wrap attendance-table-wrap"><table class="attendance-table">
    <thead><tr><th>Clock In</th><th>Clock Out</th><th>Hours</th><th>Shift summary</th><th>Correction</th></tr></thead>
    <tbody>
    <?php foreach ($selfHistory as $shift):
      $seconds = $shift['clock_out_at']
          ? max(0, (new DateTimeImmutable($shift['clock_out_at'], new DateTimeZone('UTC')))->getTimestamp() - (new DateTimeImmutable($shift['clock_in_at'], new DateTimeZone('UTC')))->getTimestamp())
          : time() - (new DateTimeImmutable($shift['clock_in_at'], new DateTimeZone('UTC')))->getTimestamp();
    ?>
      <tr>
        <td><?= h(format_ist($shift['clock_in_at'])) ?></td>
        <td><?= $shift['clock_out_at'] ? h(format_ist($shift['clock_out_at'])) : 'In progress' ?></td>
        <td><?= h(attendance_duration_label($seconds)) ?></td>
        <td><?= nl2br(h($shift['shift_summary'] ?: '—')) ?></td>
        <td><?= h($shift['correction_reason'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$selfHistory): ?><tr><td colspan="5">No attendance records yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
<?php else: ?>
  <form method="get" class="attendance-card attendance-form" id="attendanceFilters" style="margin-bottom:18px;">
    <div class="form-group"><label for="attendanceStart">From</label><input id="attendanceStart" type="date" name="start" value="<?= h($start) ?>" required></div>
    <div class="form-group"><label for="attendanceEnd">To</label><input id="attendanceEnd" type="date" name="end" value="<?= h($end) ?>" required></div>
    <div class="form-group">
      <label for="attendanceWorker">Select employee</label>
      <select id="attendanceWorker" name="worker">
        <option value="">All employees overview</option>
        <?php foreach ($workers as $key => $worker): ?>
          <option value="<?= h($key) ?>" <?= $selectedWorkerKey === $key ? 'selected' : '' ?>>
            <?= h($worker['worker_name']) ?> · <?= h(ucfirst($worker['worker_role'])) ?> · <?= h($worker['worker_type']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary"><?= $selectedWorker ? 'View employee report' : 'View overview' ?></button>
    <?php if (!$selectedWorker): ?>
      <div class="attendance-filter-row" style="flex-basis:100%;margin:2px 0 0;">
        <button class="attendance-filter" type="button" data-date-preset="today">Today</button>
        <button class="attendance-filter" type="button" data-date-preset="7days">Last 7 days</button>
        <button class="attendance-filter" type="button" data-date-preset="month">This month</button>
      </div>
    <?php endif; ?>
  </form>
  <?php if (!$selectedWorker): ?>
  <section class="attendance-dashboard" aria-label="Attendance analytics">
    <div class="attendance-kpis">
      <article class="attendance-kpi" style="--kpi-color:#187746;--kpi-tint:#eaf6ee;">
        <div class="attendance-kpi-top"><span>Team members</span><span class="attendance-kpi-icon">♙</span></div>
        <div class="attendance-kpi-value"><?= count($workers) ?></div>
        <div class="attendance-kpi-note">Staff and delivery partners</div>
      </article>
      <article class="attendance-kpi" style="--kpi-color:#1673a5;--kpi-tint:#eaf4fa;">
        <div class="attendance-kpi-top"><span>Productive hours</span><span class="attendance-kpi-icon">◷</span></div>
        <div class="attendance-kpi-value"><?= h(number_format($totalWorkedSeconds / 3600, 1)) ?><small style="font-size:.83rem;font-weight:700;"> hrs</small></div>
        <div class="attendance-kpi-note">Break time excluded</div>
      </article>
      <article class="attendance-kpi" style="--kpi-color:#bf7318;--kpi-tint:#fff4df;">
        <div class="attendance-kpi-top"><span>Approved overtime</span><span class="attendance-kpi-icon">↗</span></div>
        <div class="attendance-kpi-value"><?= h(number_format($totalOvertimeSeconds / 3600, 1)) ?><small style="font-size:.83rem;font-weight:700;"> hrs</small></div>
        <div class="attendance-kpi-note"><?= count($overtimeRequests) ?> request<?= count($overtimeRequests) === 1 ? '' : 's' ?> waiting for approval</div>
      </article>
      <article class="attendance-kpi" style="--kpi-color:#6e56a3;--kpi-tint:#f1edfa;">
        <div class="attendance-kpi-top"><span>Orders completed</span><span class="attendance-kpi-icon">✓</span></div>
        <div class="attendance-kpi-value"><?= (int)$totalCompletedOrders ?></div>
        <div class="attendance-kpi-note"><?= count(array_filter($workerStats, static fn($stat) => $stat['open'])) ?> team members clocked in now</div>
      </article>
    </div>
    <div class="attendance-insights">
      <section class="attendance-panel">
        <div class="attendance-panel-head">
          <div><h3>Productive hours by employee</h3><p>Top team members for this date range · up to 6 people</p></div>
          <div class="attendance-chart-legend"><span><i class="attendance-chart-dot"></i> Productive</span><span><i class="attendance-chart-dot is-overtime"></i> Overtime</span></div>
        </div>
        <?php if ($attendanceChartRows): ?>
          <div class="attendance-bars">
            <?php foreach ($attendanceChartRows as $bar):
              $productiveWidth = min(100, ($bar['minutes'] / $chartMaxMinutes) * 100);
              $overtimeWidth = min(max(0, 100 - $productiveWidth), ($bar['overtime_minutes'] / $chartMaxMinutes) * 100);
              $barMinutes = $bar['minutes'] + $bar['overtime_minutes'];
            ?>
              <div class="attendance-bar-row" title="<?= h($bar['name']) ?> · <?= h(attendance_duration_label($barMinutes * 60)) ?>">
                <span class="attendance-bar-name"><?= h($bar['name']) ?></span>
                <span class="attendance-bar-track" role="img" aria-label="<?= h(attendance_duration_label($barMinutes * 60)) ?> total">
                  <i class="attendance-bar-work" style="width:<?= h((string)$productiveWidth) ?>%"></i>
                  <i class="attendance-bar-overtime" style="width:<?= h((string)$overtimeWidth) ?>%"></i>
                </span>
                <span class="attendance-bar-value"><?= h(number_format($barMinutes / 60, 1)) ?>h</span>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="attendance-chart-axis"><span>0h</span><span><?= h(number_format($chartMaxMinutes / 120, 1)) ?>h</span><span><?= h(number_format($chartMaxMinutes / 60, 1)) ?>h</span></div>
        <?php else: ?>
          <p class="attendance-muted">No attendance data for this date range yet.</p>
        <?php endif; ?>
      </section>
      <section class="attendance-panel">
        <div class="attendance-panel-head"><div><h3>Who's on shift</h3><p>Live team status</p></div><span class="attendance-pill"><?= count(array_filter($workerStats, static fn($stat) => $stat['open'])) ?> active</span></div>
        <div class="attendance-status-list">
          <?php $activeWorkerCount = 0; foreach ($workers as $key => $worker): if (!$workerStats[$key]['open']) continue; $activeWorkerCount++; ?>
            <?php $openStart = $workerStats[$key]['open_since'] ? (new DateTimeImmutable($workerStats[$key]['open_since'], new DateTimeZone('UTC')))->getTimestamp() : null; ?>
            <a class="attendance-status-item" href="<?= h('attendance.php?' . http_build_query(['start' => $start, 'end' => $end, 'worker' => $key])) ?>#worker-report" style="text-decoration:none;">
              <span class="attendance-status-person">
                <span class="attendance-status-avatar"><?= h(strtoupper(substr($worker['worker_name'], 0, 1))) ?></span>
                <span style="min-width:0;"><span class="attendance-status-name" style="display:block;"><?= h($worker['worker_name']) ?></span><span class="attendance-status-time"><?= h(ucfirst($worker['worker_role'])) ?> · since <span class="worker-live-clock" <?= $openStart ? 'data-live-start="' . $openStart . '"' : '' ?>>00:00:00</span></span></span>
              </span>
              <span class="attendance-pill"><?= $worker['worker_type'] === 'rider' ? 'Rider' : 'On shift' ?></span>
            </a>
          <?php endforeach; ?>
          <?php if ($activeWorkerCount === 0): ?><div class="attendance-status-item"><span class="attendance-status-name">No active shifts right now</span><span class="attendance-pill is-muted">All clear</span></div><?php endif; ?>
        </div>
      </section>
    </div>
  </section>
  <div class="attendance-toolbar">
    <div><h3>Team attendance</h3><p>Search, filter, or open a person's report for shift and order details.</p></div>
    <label class="attendance-search"><span class="visually-hidden">Search employees</span><input type="search" id="attendanceSearch" placeholder="Search employee or role…" autocomplete="off"></label>
  </div>
  <div class="attendance-filter-row" id="attendanceStateFilters">
    <button class="attendance-filter is-active" type="button" data-state-filter="all">Everyone <span><?= count($workers) ?></span></button>
    <button class="attendance-filter" type="button" data-state-filter="active">On shift <span><?= count(array_filter($workerStats, static fn($stat) => $stat['open'])) ?></span></button>
    <button class="attendance-filter" type="button" data-state-filter="overtime">Overtime <span><?= count(array_filter($workerStats, static fn($stat) => $stat['overtime_seconds'] > 0)) ?></span></button>
  </div>
  <?php endif; ?>

  <?php if (isset($_GET['worker']) && !$selectedWorker): ?>
    <div class="alert alert-error">The selected worker could not be found. Choose a worker from the cards below.</div>
  <?php endif; ?>

  <?php if ($selectedWorker): ?>
    <section class="attendance-card worker-report-section" id="worker-report">
      <div class="rider-topline no-print">
        <div><h3 style="margin:0;"><?= h($selectedWorker['worker_name']) ?> performance report</h3><div class="attendance-muted"><?= h(ucfirst($selectedWorker['worker_role'])) ?> · <?= h($selectedWorker['worker_type']) ?> · <?= h($selectedWorker['store_name'] ?: 'Not assigned') ?> · <?= h($start) ?> to <?= h($end) ?></div></div>
        <div class="attendance-form">
          <a class="btn" href="<?= h('attendance.php?' . http_build_query(['start' => $start, 'end' => $end, 'worker_report' => 'csv', 'worker' => $selectedWorkerKey])) ?>">Download CSV report</a>
          <button class="btn btn-primary" type="button" onclick="printWorkerReport()">Print / Save PDF</button>
          <a class="btn" href="attendance.php?<?= h(http_build_query(['start' => $start, 'end' => $end])) ?>">Close report</a>
        </div>
      </div>
      <div class="attendance-grid">
        <div class="attendance-card">
          <div class="attendance-muted">Current shift</div>
          <?php if ($workerStats[$selectedWorkerKey]['open_since']): ?>
            <div class="attendance-muted">Clocked in since <?= h(format_ist($workerStats[$selectedWorkerKey]['open_since'])) ?></div>
            <div class="worker-live-clock" data-live-start="<?= (new DateTimeImmutable($workerStats[$selectedWorkerKey]['open_since'], new DateTimeZone('UTC')))->getTimestamp() ?>">00:00:00</div>
          <?php else: ?>
            <div class="attendance-summary">Clocked out</div>
          <?php endif; ?>
        </div>
        <div class="attendance-card"><div class="attendance-muted">Presence</div><div class="attendance-summary"><?= h(attendance_duration_label($workerStats[$selectedWorkerKey]['presence_seconds'])) ?></div></div>
        <div class="attendance-card"><div class="attendance-muted">Productive time</div><div class="attendance-summary"><?= h(attendance_duration_label($workerStats[$selectedWorkerKey]['seconds'])) ?></div></div>
        <div class="attendance-card"><div class="attendance-muted">Approved overtime</div><div class="attendance-summary"><?= h(attendance_duration_label($workerStats[$selectedWorkerKey]['overtime_seconds'])) ?></div></div>
        <div class="attendance-card"><div class="attendance-muted">Completed orders</div><div class="attendance-summary"><?= count($selectedWorkerOrders) ?></div></div>
        <div class="attendance-card"><div class="attendance-muted">Shifts in period</div><div class="attendance-summary"><?= count($selectedWorkerShifts) ?></div></div>
        <div class="attendance-card"><div class="attendance-muted">Pay summary</div><div class="attendance-summary" style="font-size:1.1rem;"><?= h($selectedWorkerPayLabel) ?></div></div>
      </div>
      <h4>Shift details</h4>
      <div class="table-wrap attendance-table-wrap"><table class="attendance-table">
        <thead><tr><th>Clock in</th><th>Clock out</th><th>Presence</th><th>Approved overtime</th><th>Shift summary</th><th>Correction reason</th></tr></thead>
        <tbody>
        <?php foreach ($selectedWorkerShifts as $shift):
          $shiftEnd = $shift['clock_out_at'] ?: gmdate('Y-m-d H:i:s');
          $seconds = max(0, (new DateTimeImmutable($shiftEnd, new DateTimeZone('UTC')))->getTimestamp()
              - (new DateTimeImmutable($shift['clock_in_at'], new DateTimeZone('UTC')))->getTimestamp());
        ?>
          <?php
            $shiftOvertimeSeconds = 0;
            if ($shift['overtime_status'] === 'approved') {
                $shiftClockIn = new DateTimeImmutable($shift['clock_in_at'], new DateTimeZone('UTC'));
                $regularEnd = $shiftClockIn->modify('+' . (((int)($profile['shift_work_minutes'] ?? 480) + (int)($profile['break_minutes'] ?? 60)) * 60) . ' seconds');
                $shiftEndAt = new DateTimeImmutable($shiftEnd, new DateTimeZone('UTC'));
                $otEnd = $regularEnd->modify('+' . ((int)$shift['overtime_minutes'] * 60) . ' seconds');
                $shiftOvertimeSeconds = max(0, min($shiftEndAt->getTimestamp(), $otEnd->getTimestamp()) - $regularEnd->getTimestamp());
            }
          ?>
          <tr><td><?= h(format_ist($shift['clock_in_at'])) ?></td><td><?= $shift['clock_out_at'] ? h(format_ist($shift['clock_out_at'])) : 'Open shift' ?></td><td><?= h(attendance_duration_label($seconds)) ?></td><td><?= h(attendance_duration_label($shiftOvertimeSeconds)) ?><?= $shift['overtime_status'] ? ' · ' . h(ucfirst($shift['overtime_status'])) : '' ?></td><td><?= nl2br(h($shift['shift_summary'] ?: '—')) ?></td><td><?= h($shift['correction_reason'] ?: '—') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$selectedWorkerShifts): ?><tr><td colspan="6">No shifts in this report period.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
      <h4 style="margin-top:22px;">Completed orders</h4>
      <div class="table-wrap attendance-table-wrap"><table class="attendance-table">
        <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Completed at</th></tr></thead>
        <tbody>
        <?php foreach ($selectedWorkerOrders as $order): ?>
          <tr><td>#ORD-<?= str_pad((string)$order['id'], 5, '0', STR_PAD_LEFT) ?></td><td><?= h($order['customer_name']) ?></td><td><?= h(ucfirst(str_replace('_', ' ', $order['order_status']))) ?></td><td><?= h(format_ist($order['completed_at'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$selectedWorkerOrders): ?><tr><td colspan="4">No completed orders in this report period.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </section>
  <?php endif; ?>
  <?php if (!$selectedWorker): ?>
  <?php if ($overtimeRequests): ?>
    <div class="section-head" style="margin-top:24px;"><h3>Overtime approval requests</h3><p>Approve before the regular shift ends. Approved time extends the employee's automatic clock-out.</p></div>
    <div class="table-wrap attendance-table-wrap"><table class="attendance-table">
      <thead><tr><th>Worker</th><th>Shift started</th><th>Requested overtime</th><th>Decision</th></tr></thead>
      <tbody>
      <?php foreach ($overtimeRequests as $request):
        $requestWorker = $workers[$request['worker_type'] . ':' . (int)$request['worker_id']] ?? null;
        if (!$requestWorker) continue;
      ?>
        <tr>
          <td><?= h($requestWorker['worker_name']) ?></td>
          <td><?= h(format_ist($request['clock_in_at'])) ?></td>
          <td><?= (int)$request['requested_minutes'] ?> minutes</td>
          <td>
            <form method="post" class="attendance-form">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="decide_overtime" value="1">
              <input type="hidden" name="overtime_request_id" value="<?= (int)$request['id'] ?>">
              <button class="btn btn-primary" name="overtime_decision" value="approved">Approve</button>
              <button class="btn" name="overtime_decision" value="rejected">Reject</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <div class="section-head"><h3>Attendance &amp; Completed Work · <?= h($start) ?> to <?= h($end) ?></h3></div>
  <div class="table-wrap attendance-table-wrap"><table class="attendance-table">
    <thead><tr><th>Worker</th><th>Role</th><th>Store</th><th>Shifts</th><th>Presence</th><th>Productive hours</th><th>Approved overtime</th><th>Orders completed</th><th>Pay estimate</th><th>Shift status</th></tr></thead>
    <tbody>
    <?php foreach ($workers as $key => $worker):
      $workerSearchText = strtolower($worker['worker_name'] . ' ' . $worker['worker_role'] . ' ' . $worker['worker_type'] . ' ' . $worker['contact']);
    ?>
      <tr data-attendance-row data-name="<?= h($workerSearchText) ?>" data-active="<?= $workerStats[$key]['open'] ? '1' : '0' ?>" data-overtime="<?= $workerStats[$key]['overtime_seconds'] > 0 ? '1' : '0' ?>">
        <td><a class="attendance-worker-link" href="<?= h('attendance.php?' . http_build_query(['start' => $start, 'end' => $end, 'worker' => $key])) ?>#worker-report"><strong><?= h($worker['worker_name']) ?></strong></a><br><span class="attendance-muted"><?= h($worker['contact'] ?: '—') ?></span></td>
        <td><?= h(ucfirst($worker['worker_role'])) ?></td>
        <td><?= h($worker['store_name'] ?: 'Not assigned') ?></td>
        <td><?= (int)$workerStats[$key]['shifts'] ?></td>
        <td><?= h(attendance_duration_label($workerStats[$key]['presence_seconds'])) ?></td>
        <td><?= h(attendance_duration_label($workerStats[$key]['seconds'])) ?></td>
        <td><?= h(attendance_duration_label($workerStats[$key]['overtime_seconds'])) ?></td>
        <td><?= (int)$completedOrders[$key] ?></td>
        <td>
          <?php
          $profile = $compensationProfiles[$key] ?? ['employment_type' => 'full_time', 'pay_mode' => 'attendance_only', 'hourly_rate' => null, 'monthly_salary' => null];
          if ($profile['pay_mode'] === 'hourly' && $profile['hourly_rate'] !== null):
              $amount = ($workerStats[$key]['seconds'] / 3600) * (float)$profile['hourly_rate'];
          ?>
            ₹<?= number_format($amount, 2) ?>
          <?php elseif ($profile['pay_mode'] === 'monthly' && $profile['monthly_salary'] !== null): ?>
            ₹<?= number_format((float)$profile['monthly_salary'], 2) ?> / month
          <?php elseif ($profile['pay_mode'] === 'per_km'): ?>
            ₹<?= number_format((float)($gigPayoutTotals[$key] ?? 0), 2) ?> confirmed
          <?php else: ?>
            Attendance only
          <?php endif; ?>
        </td>
        <td><span class="attendance-pill <?= $workerStats[$key]['open'] ? '' : 'is-muted' ?>"><?= $workerStats[$key]['open'] ? 'On shift' : 'Clocked out' ?></span></td>
      </tr>
    <?php endforeach; ?>
    <tr id="attendanceNoResults" hidden><td colspan="10" style="padding:24px;text-align:center;color:#75837a;">No employees match these filters.</td></tr>
    <?php if (!$workers): ?><tr><td colspan="10">No staff or rider accounts found.</td></tr><?php endif; ?>
    </tbody>
  </table></div>

    <div class="section-head" style="margin-top:28px;"><h3>Worker Type, Shift &amp; Pay Settings</h3><p>Full-time defaults to 8 work hours plus a 60-minute break; part-time defaults to 4 work hours plus a 30-minute break. Edit each worker's settings here.</p></div>
  <div class="table-wrap attendance-table-wrap"><table class="attendance-table">
      <thead><tr><th>Worker</th><th>Employment type</th><th>Pay mode</th><th>Work hours / break</th><th>Hourly rate (₹)</th><th>Monthly salary (₹)</th><th>Gig rate (₹/km)</th><th>Save</th></tr></thead>
      <tbody>
      <?php foreach ($workers as $key => $worker):
        $profile = $compensationProfiles[$key] ?? [
            'employment_type' => 'full_time', 'pay_mode' => 'attendance_only',
            'shift_work_minutes' => 480, 'break_minutes' => 60,
            'hourly_rate' => '', 'monthly_salary' => '', 'per_km_rate' => '',
        ];
      ?>
        <tr>
          <td><strong><?= h($worker['worker_name']) ?></strong><br><span class="attendance-muted"><?= h(ucfirst($worker['worker_role'])) ?> · <?= h($worker['worker_type']) ?></span></td>
          <td colspan="7">
            <form method="post" class="worker-settings-form">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="save_worker_compensation" value="1">
              <input type="hidden" name="worker_type" value="<?= h($worker['worker_type']) ?>">
              <input type="hidden" name="worker_id" value="<?= (int)$worker['worker_id'] ?>">
              <div class="attendance-form">
                <select name="employment_type" aria-label="Employment type">
                  <option value="full_time" <?= $profile['employment_type'] === 'full_time' ? 'selected' : '' ?>>Full-time</option>
                  <option value="part_time" <?= $profile['employment_type'] === 'part_time' ? 'selected' : '' ?>>Part-time</option>
                  <?php if ($worker['worker_type'] === 'rider'): ?><option value="gig" <?= $profile['employment_type'] === 'gig' ? 'selected' : '' ?>>Gig rider</option><?php endif; ?>
                </select>
                <select name="pay_mode" aria-label="Pay mode">
                  <option value="attendance_only" <?= $profile['pay_mode'] === 'attendance_only' ? 'selected' : '' ?>>Attendance only</option>
                  <option value="hourly" <?= $profile['pay_mode'] === 'hourly' ? 'selected' : '' ?>>Hourly</option>
                  <option value="monthly" <?= $profile['pay_mode'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                  <?php if ($worker['worker_type'] === 'rider'): ?><option value="per_km" <?= $profile['pay_mode'] === 'per_km' ? 'selected' : '' ?>>Per kilometre</option><?php endif; ?>
                </select>
                <input type="number" name="shift_work_hours" min="1" max="16" step="0.25" value="<?= h(number_format((int)$profile['shift_work_minutes'] / 60, 2, '.', '')) ?>" aria-label="Productive work hours" required>
                <input type="number" name="break_minutes" min="0" max="240" step="1" value="<?= (int)$profile['break_minutes'] ?>" aria-label="Break minutes" required>
                <input type="number" name="hourly_rate" min="0" step="0.01" value="<?= h((string)($profile['hourly_rate'] ?? '')) ?>" placeholder="Hourly rate" aria-label="Hourly rate">
                <input type="number" name="monthly_salary" min="0" step="0.01" value="<?= h((string)($profile['monthly_salary'] ?? '')) ?>" placeholder="Monthly salary" aria-label="Monthly salary">
                <input type="number" name="per_km_rate" min="0" step="0.01" value="<?= h((string)($profile['per_km_rate'] ?? '')) ?>" placeholder="Gig rate / km" aria-label="Per-kilometre rate">
                <button class="btn" type="submit">Save settings</button>
              </div>
              <span class="attendance-muted">Hours = productive work time before break. Gig payout uses admin-entered billable kilometres.</span>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$workers): ?><tr><td colspan="8">No worker accounts to configure.</td></tr><?php endif; ?>
      </tbody>
    </table></div>

    <div class="section-head" style="margin-top:28px;"><h3>Gig Rider Payouts</h3><p>Set billable kilometres once; that shared admin-entered distance is used for each payout when confirmed. Confirmed payouts retain their saved distance and rate. This is not GPS-measured mileage, and each rider's own per-kilometre rate is used.</p></div>
    <form method="post" class="attendance-card attendance-form" style="margin-bottom:16px;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="save_common_gig_km" value="1">
      <div class="form-group">
        <label for="commonBillableKm">Shared billable distance (km)</label>
        <input id="commonBillableKm" type="number" name="common_billable_km" min="0.01" max="500" step="0.01" value="<?= $commonBillableKm !== false ? h((string)$commonBillableKm) : '' ?>" required>
      </div>
      <button class="btn btn-primary" type="submit">Save shared distance</button>
      <?php if ($commonBillableKm === false): ?><span class="attendance-muted">Set this once before confirming any gig payout.</span><?php endif; ?>
    </form>
    <div class="table-wrap attendance-table-wrap"><table class="attendance-table">
      <thead><tr><th>Order</th><th>Rider</th><th>Delivered</th><th>Rate</th><th>Billable km</th><th>Amount</th><th>Action / Status</th></tr></thead>
      <tbody>
      <?php foreach ($gigPayoutOrders as $payout): ?>
        <tr>
          <td>#ORD-<?= str_pad((string)$payout['id'], 5, '0', STR_PAD_LEFT) ?></td>
          <td><?= h($payout['rider_name']) ?></td>
          <td><?= h(format_ist($payout['delivered_at'])) ?></td>
          <td>₹<?= number_format((float)($payout['rate_per_km'] ?? $payout['per_km_rate']), 2) ?>/km</td>
          <td><?= $payout['billable_km'] !== null ? number_format((float)$payout['billable_km'], 2) : ($commonBillableKm !== false ? number_format((float)$commonBillableKm, 2) . ' shared' : 'Set above') ?></td>
          <td><?= $payout['payout_amount'] !== null ? '₹' . number_format((float)$payout['payout_amount'], 2) : 'Pending' ?></td>
          <td>
            <?php if ($payout['payout_amount'] === null): ?>
              <form method="post" class="attendance-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="confirm_gig_payout" value="1">
                <input type="hidden" name="order_id" value="<?= (int)$payout['id'] ?>">
                <button class="btn" type="submit" <?= $commonBillableKm === false ? 'disabled title="Set the shared billable distance first"' : '' ?>>Confirm payout</button>
              </form>
            <?php else: ?>
              <span class="attendance-muted">Confirmed <?= h(format_ist($payout['confirmed_at'])) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$gigPayoutOrders): ?><tr><td colspan="7">No gig rider deliveries in this date range.</td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr><th colspan="5" style="text-align:right;">Confirmed total</th><th>₹<?= number_format(array_sum($gigPayoutTotals), 2) ?></th><th></th></tr></tfoot>
    </table></div>

    <div class="section-head" style="margin-top:28px;"><h3>Shift Details &amp; Corrections</h3><p>Corrections are recorded in an audit log with the admin, old time, new time, and reason.</p></div>
    <div class="table-wrap attendance-table-wrap"><table class="attendance-table">
      <thead><tr><th>Worker</th><th>Clock In</th><th>Clock Out</th><th>Presence</th><th>Shift summary</th><th>Correction</th><th>Correct Clock Out</th></tr></thead>
    <tbody>
    <?php foreach ($shiftRows as $shift):
      $key = $shift['worker_type'] . ':' . (int)$shift['worker_id'];
      $worker = $workers[$key] ?? null;
      if (!$worker) continue;
      $shiftEnd = $shift['clock_out_at'] ?: gmdate('Y-m-d H:i:s');
      $seconds = max(0, (new DateTimeImmutable($shiftEnd, new DateTimeZone('UTC')))->getTimestamp() - (new DateTimeImmutable($shift['clock_in_at'], new DateTimeZone('UTC')))->getTimestamp());
      $currentClockOutLocal = $shift['clock_out_at']
          ? (new DateTimeImmutable($shift['clock_out_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d\TH:i')
          : '';
    ?>
      <tr>
        <td><?= h($worker['worker_name']) ?> <span class="attendance-muted">(<?= h($shift['worker_type']) ?>)</span></td>
        <td><?= h(format_ist($shift['clock_in_at'])) ?></td>
        <td><?= $shift['clock_out_at'] ? h(format_ist($shift['clock_out_at'])) : 'Open shift' ?></td>
        <td><?= h(attendance_duration_label($seconds)) ?></td>
        <td><?= nl2br(h($shift['shift_summary'] ?: '—')) ?></td>
        <td><?= h($shift['correction_reason'] ?: '—') ?></td>
        <td>
          <form method="post" class="attendance-correction">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="adjust_attendance" value="1">
            <input type="hidden" name="attendance_id" value="<?= (int)$shift['id'] ?>">
            <input type="datetime-local" name="clock_out_at" value="<?= h($currentClockOutLocal) ?>" required>
            <input type="text" name="reason" maxlength="500" placeholder="Reason for correction" required>
            <button class="btn" type="submit">Save correction</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$shiftRows): ?><tr><td colspan="7">No shifts in this date range.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="section-head" style="margin-top:28px;"><h3>Correction Audit History</h3></div>
  <div class="table-wrap attendance-table-wrap"><table class="attendance-table">
    <thead><tr><th>Worker</th><th>Previous Clock Out</th><th>Corrected Clock Out</th><th>Reason</th><th>Changed By</th><th>Changed At</th></tr></thead>
    <tbody>
    <?php foreach ($adjustmentRows as $adjustment):
      $key = $adjustment['worker_type'] . ':' . (int)$adjustment['worker_id'];
      $worker = $workers[$key] ?? null;
    ?>
      <tr>
        <td><?= h($worker['worker_name'] ?? 'Worker #' . (int)$adjustment['worker_id']) ?></td>
        <td><?= $adjustment['old_clock_out_at'] ? h(format_ist($adjustment['old_clock_out_at'])) : 'Open shift' ?></td>
        <td><?= h(format_ist($adjustment['new_clock_out_at'])) ?></td>
        <td><?= h($adjustment['reason']) ?></td>
        <td><?= h($adjustment['adjusted_by']) ?></td>
        <td><?= h(format_ist($adjustment['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$adjustmentRows): ?><tr><td colspan="6">No corrections in this date range.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
<?php endif; ?>

<script>
document.querySelectorAll('[data-attendance-action]').forEach((button) => {
  button.addEventListener('click', async () => {
    const action = button.dataset.attendanceAction;
    let shiftSummary = '';
    if (action === 'clock_out' && button.dataset.requiresSummary === '1') {
      shiftSummary = prompt('Enter a short summary of your work and completed tasks:') || '';
      if (!shiftSummary.trim()) {
        alert('A shift summary is required before clocking out.');
        return;
      }
    }
    button.disabled = true;
    try {
      await submitAttendanceAction(action, shiftSummary, button);
    } catch (error) {
      alert(error.message || 'Network error while recording attendance.');
      button.disabled = false;
    }
  });
});

async function submitAttendanceAction(action, shiftSummary, button) {
  const overtimeSelect = document.querySelector('[data-overtime-minutes]');
  const response = await fetch('../ajax/worker_attendance_action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      action,
      shift_summary: shiftSummary,
      requested_minutes: overtimeSelect ? overtimeSelect.value : null,
      csrf_token: '<?= h(csrf_token()) ?>'
    })
  });
  const data = await response.json();
  if (!response.ok || !data.success) throw new Error(data.error || 'Attendance could not be saved.');
  if (action === 'auto_clock_out' && !data.closed) {
    const activeShiftClock = document.querySelector('[data-auto-end]');
    if (data.next_end && activeShiftClock) {
      activeShiftClock.dataset.autoEnd = String(data.next_end);
      autoClockOutSent = false;
      return;
    }
    const message = document.querySelector('[data-auto-clockout-message]');
    if (message) message.textContent = 'Shift limit reached. Complete your active delivery to clock out automatically.';
    return;
  }
  if (data.assigned_order_id) alert('Clocked in. Order #' + data.assigned_order_id + ' was automatically assigned to you.');
  location.reload();
}

function updateAttendanceClocks() {
  const nowSeconds = Math.floor(Date.now() / 1000);
  document.querySelectorAll('[data-live-start]').forEach((clock) => {
    const elapsed = Math.max(0, nowSeconds - Number(clock.dataset.liveStart));
    const hours = Math.floor(elapsed / 3600);
    const minutes = Math.floor((elapsed % 3600) / 60);
    const seconds = elapsed % 60;
    clock.textContent = String(hours).padStart(2, '0') + ':' +
      String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
  });
}
updateAttendanceClocks();
window.setInterval(updateAttendanceClocks, 1000);

const attendanceSearch = document.getElementById('attendanceSearch');
const attendanceRows = [...document.querySelectorAll('[data-attendance-row]')];
const attendanceNoResults = document.getElementById('attendanceNoResults');
let activeAttendanceFilter = 'all';

function filterAttendanceRows() {
  const query = attendanceSearch ? attendanceSearch.value.trim().toLowerCase() : '';
  let visibleRows = 0;
  attendanceRows.forEach((row) => {
    const matchesSearch = (row.dataset.name || '').includes(query);
    const matchesFilter = activeAttendanceFilter === 'all'
      || (activeAttendanceFilter === 'active' && row.dataset.active === '1')
      || (activeAttendanceFilter === 'overtime' && row.dataset.overtime === '1');
    const visible = matchesSearch && matchesFilter;
    row.hidden = !visible;
    if (visible) visibleRows++;
  });
  if (attendanceNoResults) attendanceNoResults.hidden = visibleRows !== 0;
}

if (attendanceSearch) attendanceSearch.addEventListener('input', filterAttendanceRows);
document.querySelectorAll('[data-state-filter]').forEach((button) => {
  button.addEventListener('click', () => {
    activeAttendanceFilter = button.dataset.stateFilter;
    document.querySelectorAll('[data-state-filter]').forEach((filter) => {
      const selected = filter === button;
      filter.classList.toggle('is-active', selected);
      filter.setAttribute('aria-pressed', String(selected));
    });
    filterAttendanceRows();
  });
});

const attendanceFilterForm = document.getElementById('attendanceFilters');
document.querySelectorAll('[data-date-preset]').forEach((button) => {
  button.addEventListener('click', () => {
    const today = new Date();
    const toDateInput = (date) => {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    };
    const endInput = document.getElementById('attendanceEnd');
    const startInput = document.getElementById('attendanceStart');
    if (!attendanceFilterForm || !endInput || !startInput) return;
    endInput.value = toDateInput(today);
    if (button.dataset.datePreset === 'today') {
      startInput.value = endInput.value;
    } else if (button.dataset.datePreset === '7days') {
      const start = new Date(today);
      start.setDate(start.getDate() - 6);
      startInput.value = toDateInput(start);
    } else {
      startInput.value = toDateInput(new Date(today.getFullYear(), today.getMonth(), 1));
    }
    attendanceFilterForm.requestSubmit();
  });
});

let autoClockOutSent = false;
window.setInterval(() => {
  const activeShiftClock = document.querySelector('[data-auto-end]');
  if (!activeShiftClock || autoClockOutSent || Date.now() < Number(activeShiftClock.dataset.autoEnd) * 1000) return;
  autoClockOutSent = true;
  submitAttendanceAction('auto_clock_out', '', null).catch((error) => {
    autoClockOutSent = false;
    console.error(error);
  });
}, 15000);

function printWorkerReport() {
  document.body.classList.add('print-worker-report');
  window.setTimeout(() => window.print(), 0);
}
window.addEventListener('afterprint', () => document.body.classList.remove('print-worker-report'));

document.querySelectorAll('.worker-settings-form').forEach((form) => {
  const employmentType = form.querySelector('[name="employment_type"]');
  const workHours = form.querySelector('[name="shift_work_hours"]');
  const breakMinutes = form.querySelector('[name="break_minutes"]');
  employmentType.addEventListener('change', () => {
    if (employmentType.value === 'full_time') {
      workHours.value = '8';
      breakMinutes.value = '60';
    } else if (employmentType.value === 'part_time') {
      workHours.value = '4';
      breakMinutes.value = '30';
    }
  });
});
</script>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
