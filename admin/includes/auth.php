<?php
if (!isset($pdo)) require_once __DIR__ . '/../../config.php';
ensure_management_schema($pdo);
if (!is_admin_logged_in()) { header('Location: login.php'); exit; }
require_page_access($pdo, basename($_SERVER['PHP_SELF']));
function require_role($roles) {
    $roles = (array)$roles;
    if (!in_array(admin_role(), $roles, true)) { http_response_code(403); exit('Access denied.'); }
}
