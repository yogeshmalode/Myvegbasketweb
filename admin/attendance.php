<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin', 'staff', 'delivery']);
$page_title = 'Worker Attendance';
$isAdmin = admin_role() === 'admin';
$workerId = (int)($_SESSION['admin_id'] ?? 0);

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

$selfShiftStmt = $pdo->prepare("SELECT id, clock_in_at FROM worker_attendance
    WHERE worker_type = 'staff' AND worker_id = ? AND clock_out_at IS NULL
    ORDER BY id DESC LIMIT 1");
$selfShiftStmt->execute([$workerId]);
$selfOpenShift = $selfShiftStmt->fetch() ?: null;
$selfHistoryStmt = $pdo->prepare("SELECT id, clock_in_at, clock_out_at, correction_reason, shift_summary
    FROM worker_attendance WHERE worker_type = 'staff' AND worker_id = ?
    ORDER BY clock_in_at DESC LIMIT 20");
$selfHistoryStmt->execute([$workerId]);
$selfHistory = $selfHistoryStmt->fetchAll();
$selfCompensationStmt = $pdo->prepare('SELECT employment_type FROM worker_compensation WHERE worker_type = ? AND worker_id = ?');
$selfCompensationStmt->execute(['staff', $workerId]);
$selfCompensation = $selfCompensationStmt->fetch() ?: ['employment_type' => 'full_time'];

$workers = [];
$shiftRows = [];
$workerStats = [];
$compensationProfiles = [];
$completedOrders = [];
$totalWorkedSeconds = 0;
$gigPayoutOrders = [];
$gigPayoutTotals = [];
$commonBillableKmStmt = $pdo->query('SELECT common_billable_km FROM gig_payout_settings WHERE setting_id = 1');
$commonBillableKm = $commonBillableKmStmt->fetchColumn();
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
        $workerStats[$key] = ['seconds' => 0, 'presence_seconds' => 0, 'shifts' => 0, 'open' => false, 'open_since' => null];
        $completedOrders[$key] = 0;
    }

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

    $shiftStmt = $pdo->prepare("SELECT id, worker_type, worker_id, clock_in_at, clock_out_at, correction_reason, shift_summary
        FROM worker_attendance
        WHERE clock_in_at < ? AND (clock_out_at IS NULL OR clock_out_at > ?)
        ORDER BY clock_in_at DESC");
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
        $workerStats[$key]['presence_seconds'] += $presenceSeconds;
        $workerStats[$key]['seconds'] += $presenceSeconds - $deductBreak;
        $workerStats[$key]['shifts']++;
        $workerStats[$key]['open'] = $workerStats[$key]['open'] || $shift['clock_out_at'] === null;
        if ($shift['clock_out_at'] === null) {
            $workerStats[$key]['open_since'] = $shift['clock_in_at'];
        }
    }
    foreach ($workerStats as $stat) {
        $totalWorkedSeconds += $stat['seconds'];
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
    fputcsv($output, ['Orders completed', count($selectedWorkerOrders)]);
    fputcsv($output, ['Pay', $selectedWorkerPayLabel]);
    fputcsv($output, []);
    fputcsv($output, ['Shift clock-in', 'Shift clock-out', 'Presence', 'Shift summary', 'Correction reason']);
    foreach ($selectedWorkerShifts as $shift) {
        $endTime = $shift['clock_out_at'] ?: gmdate('Y-m-d H:i:s');
        $seconds = max(0, (new DateTimeImmutable($endTime, new DateTimeZone('UTC')))->getTimestamp()
            - (new DateTimeImmutable($shift['clock_in_at'], new DateTimeZone('UTC')))->getTimestamp());
        fputcsv($output, [
            format_ist($shift['clock_in_at']),
            $shift['clock_out_at'] ? format_ist($shift['clock_out_at']) : 'In progress',
            attendance_duration_label($seconds),
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
  @media(max-width:640px) { .attendance-form > * { flex:1 1 100%; } }
</style>

<div class="section-head"><h2>Worker Attendance &amp; Performance</h2>
  <p>Shift presence is measured from clock-in to clock-out. Configured break time is deducted to calculate productive hours; permanent workers add a shift summary at clock-out.</p>
</div>

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
    <div class="attendance-muted">Elapsed this shift</div>
    <div class="worker-live-clock" <?= $selfOpenShift ? 'data-live-start="' . (new DateTimeImmutable($selfOpenShift['clock_in_at'], new DateTimeZone('UTC')))->getTimestamp() . '"' : '' ?>>
      <?= $selfOpenShift ? '00:00:00' : '00:00:00' ?>
    </div>
    <button class="btn btn-primary" type="button" data-attendance-action="<?= $selfOpenShift ? 'clock_out' : 'clock_in' ?>" data-requires-summary="<?= $selfOpenShift && $selfCompensation['employment_type'] !== 'gig' ? '1' : '0' ?>">
      <?= $selfOpenShift ? 'Clock Out' : 'Clock In' ?>
    </button>
    <?php if ($selfOpenShift && $selfCompensation['employment_type'] !== 'gig'): ?>
      <p class="attendance-muted">A work summary is required when ending this shift.</p>
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
  <form method="get" class="attendance-card attendance-form" style="margin-bottom:18px;">
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
  </form>
  <?php if (!$selectedWorker): ?>
  <div class="section-head"><h3>Live Worker Time Cards</h3><p>Each card shows the selected period's presence and productive time plus a live timer for anyone currently clocked in. Select a worker to open their full report.</p></div>
  <div class="worker-time-grid">
    <?php foreach ($workers as $key => $worker):
      $cardUrl = 'attendance.php?' . http_build_query(['start' => $start, 'end' => $end, 'worker' => $key]) . '#worker-report';
      $openStart = $workerStats[$key]['open_since']
          ? (new DateTimeImmutable($workerStats[$key]['open_since'], new DateTimeZone('UTC')))->getTimestamp()
          : null;
    ?>
      <a class="attendance-card worker-time-card" href="<?= h($cardUrl) ?>">
        <h4><?= h($worker['worker_name']) ?></h4>
        <div class="attendance-muted"><?= h(ucfirst($worker['worker_role'])) ?> · <?= h($worker['worker_type']) ?> · <?= h($worker['store_name'] ?: 'Not assigned') ?></div>
        <div class="attendance-muted" style="margin-top:12px;">Presence in period</div>
        <div class="attendance-summary"><?= h(attendance_duration_label($workerStats[$key]['presence_seconds'])) ?></div>
        <div class="attendance-muted">Productive time (break deducted)</div>
        <div><strong><?= h(attendance_duration_label($workerStats[$key]['seconds'])) ?></strong></div>
        <div class="attendance-muted" style="margin-top:10px;"><?= $openStart ? 'Current shift · updating live' : 'Currently clocked out' ?></div>
        <div class="worker-live-clock" <?= $openStart ? 'data-live-start="' . $openStart . '"' : '' ?>>
          <?= $openStart ? '00:00:00' : '00:00:00' ?>
        </div>
        <div class="attendance-muted"><?= (int)$completedOrders[$key] ?> completed orders · <?= (int)$workerStats[$key]['shifts'] ?> shifts</div>
      </a>
    <?php endforeach; ?>
    <?php if (!$workers): ?><div class="attendance-card">No staff or rider accounts found.</div><?php endif; ?>
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
        <div class="attendance-card"><div class="attendance-muted">Completed orders</div><div class="attendance-summary"><?= count($selectedWorkerOrders) ?></div></div>
        <div class="attendance-card"><div class="attendance-muted">Shifts in period</div><div class="attendance-summary"><?= count($selectedWorkerShifts) ?></div></div>
        <div class="attendance-card"><div class="attendance-muted">Pay summary</div><div class="attendance-summary" style="font-size:1.1rem;"><?= h($selectedWorkerPayLabel) ?></div></div>
      </div>
      <h4>Shift details</h4>
      <div class="table-wrap attendance-table-wrap"><table class="attendance-table">
        <thead><tr><th>Clock in</th><th>Clock out</th><th>Presence</th><th>Shift summary</th><th>Correction reason</th></tr></thead>
        <tbody>
        <?php foreach ($selectedWorkerShifts as $shift):
          $shiftEnd = $shift['clock_out_at'] ?: gmdate('Y-m-d H:i:s');
          $seconds = max(0, (new DateTimeImmutable($shiftEnd, new DateTimeZone('UTC')))->getTimestamp()
              - (new DateTimeImmutable($shift['clock_in_at'], new DateTimeZone('UTC')))->getTimestamp());
        ?>
          <tr><td><?= h(format_ist($shift['clock_in_at'])) ?></td><td><?= $shift['clock_out_at'] ? h(format_ist($shift['clock_out_at'])) : 'Open shift' ?></td><td><?= h(attendance_duration_label($seconds)) ?></td><td><?= nl2br(h($shift['shift_summary'] ?: '—')) ?></td><td><?= h($shift['correction_reason'] ?: '—') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$selectedWorkerShifts): ?><tr><td colspan="5">No shifts in this report period.</td></tr><?php endif; ?>
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
  <div class="attendance-grid">
    <div class="attendance-card"><div class="attendance-muted">Workers</div><div class="attendance-summary"><?= count($workers) ?></div></div>
    <div class="attendance-card"><div class="attendance-muted">Productive Worker Hours (break deducted)</div><div class="attendance-summary"><?= h(attendance_duration_label($totalWorkedSeconds)) ?></div></div>
    <div class="attendance-card"><div class="attendance-muted">Open Shifts</div><div class="attendance-summary"><?= count(array_filter($workerStats, static fn($stat) => $stat['open'])) ?></div></div>
  </div>
  <div class="section-head"><h3>Attendance &amp; Completed Work · <?= h($start) ?> to <?= h($end) ?></h3></div>
  <div class="table-wrap attendance-table-wrap"><table class="attendance-table">
    <thead><tr><th>Worker</th><th>Role</th><th>Store</th><th>Shifts</th><th>Presence</th><th>Productive hours</th><th>Orders completed</th><th>Pay estimate</th><th>Shift status</th></tr></thead>
    <tbody>
    <?php foreach ($workers as $key => $worker): ?>
      <tr>
        <td><a href="<?= h('attendance.php?' . http_build_query(['start' => $start, 'end' => $end, 'worker' => $key])) ?>#worker-report"><strong><?= h($worker['worker_name']) ?></strong></a><br><span class="attendance-muted"><?= h($worker['contact'] ?: '—') ?></span></td>
        <td><?= h(ucfirst($worker['worker_role'])) ?></td>
        <td><?= h($worker['store_name'] ?: 'Not assigned') ?></td>
        <td><?= (int)$workerStats[$key]['shifts'] ?></td>
        <td><?= h(attendance_duration_label($workerStats[$key]['presence_seconds'])) ?></td>
        <td><?= h(attendance_duration_label($workerStats[$key]['seconds'])) ?></td>
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
        <td><?= $workerStats[$key]['open'] ? 'Clocked in' : 'Clocked out' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$workers): ?><tr><td colspan="9">No staff or rider accounts found.</td></tr><?php endif; ?>
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
    let shiftSummary = '';
    if (button.dataset.attendanceAction === 'clock_out' && button.dataset.requiresSummary === '1') {
      shiftSummary = prompt('Enter a short summary of your work and completed tasks:') || '';
      if (!shiftSummary.trim()) {
        alert('A shift summary is required before clocking out.');
        return;
      }
    }
    button.disabled = true;
    try {
      const response = await fetch('../ajax/worker_attendance_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          action: button.dataset.attendanceAction,
          shift_summary: shiftSummary,
          csrf_token: '<?= h(csrf_token()) ?>'
        })
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.error || 'Attendance could not be saved.');
      }
      if (data.assigned_order_id) {
        alert('Clocked in. Order #' + data.assigned_order_id + ' was automatically assigned to you.');
      }
      location.reload();
    } catch (error) {
      alert(error.message || 'Network error while recording attendance.');
      button.disabled = false;
    }
  });
});

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
