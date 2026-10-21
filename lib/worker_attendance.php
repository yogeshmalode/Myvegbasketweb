<?php

function worker_attendance_end_at(array $shift): DateTimeImmutable
{
    $clockIn = new DateTimeImmutable($shift['clock_in_at'], new DateTimeZone('UTC'));
    $workMinutes = max(1, (int)($shift['shift_work_minutes'] ?? 480));
    $breakMinutes = max(0, (int)($shift['break_minutes'] ?? 60));
    $overtimeMinutes = ($shift['overtime_status'] ?? '') === 'approved'
        ? max(0, (int)($shift['overtime_minutes'] ?? 0))
        : 0;

    return $clockIn->modify('+' . ($workMinutes + $breakMinutes + $overtimeMinutes) . ' minutes');
}

function close_due_worker_attendance(PDO $pdo, ?string $workerType = null, ?int $workerId = null): int
{
    $conditions = ['attendance.clock_out_at IS NULL'];
    $parameters = [];
    if ($workerType !== null) {
        $conditions[] = 'attendance.worker_type = ?';
        $parameters[] = $workerType;
    }
    if ($workerId !== null) {
        $conditions[] = 'attendance.worker_id = ?';
        $parameters[] = $workerId;
    }

    $stmt = $pdo->prepare("SELECT attendance.id, attendance.worker_type, attendance.worker_id,
            attendance.clock_in_at, compensation.shift_work_minutes, compensation.break_minutes,
            overtime.status AS overtime_status, overtime.requested_minutes AS overtime_minutes
        FROM worker_attendance attendance
        LEFT JOIN worker_compensation compensation
          ON compensation.worker_type = attendance.worker_type AND compensation.worker_id = attendance.worker_id
        LEFT JOIN worker_overtime_requests overtime ON overtime.attendance_id = attendance.id
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY attendance.id");
    $stmt->execute($parameters);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $closed = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $shift) {
        $scheduledEnd = worker_attendance_end_at($shift);
        if ($scheduledEnd > $now) {
            continue;
        }

        $close = $pdo->prepare("UPDATE worker_attendance
            SET clock_out_at = ?, correction_reason = COALESCE(correction_reason, 'Automatically clocked out at scheduled shift end')
            WHERE id = ? AND clock_out_at IS NULL");
        $close->execute([$scheduledEnd->format('Y-m-d H:i:s'), (int)$shift['id']]);
        if ($close->rowCount() !== 1) {
            continue;
        }

        if ($shift['worker_type'] === 'rider') {
            $pdo->prepare("UPDATE riders SET availability_status = 'offline' WHERE id = ?")
                ->execute([(int)$shift['worker_id']]);
        }
        $closed++;
    }

    return $closed;
}
