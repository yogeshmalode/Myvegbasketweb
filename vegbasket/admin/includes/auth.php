<?php
// Include this at the top of every protected admin page.
if (!isset($pdo)) { require_once __DIR__ . '/../../config.php'; }

if (!is_admin_logged_in()) {
    header('Location: login.php');
    exit;
}
