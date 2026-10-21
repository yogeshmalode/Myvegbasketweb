<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI access only.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/worker_attendance.php';

$closedCount = close_due_worker_attendance($pdo);
echo 'Automatically closed ' . $closedCount . " worker shift(s).\n";
