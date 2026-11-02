<?php
if (!isset($pdo)) require_once __DIR__ . '/../../config.php';
if (!is_tenant_request()) ensure_management_schema($pdo);
if (!is_admin_logged_in()) {
    if (defined('JSON_API_REQUEST') && JSON_API_REQUEST) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Your admin session expired. Sign in again and retry.']);
        exit;
    }
    header('Location: login.php');
    exit;
}
if (!empty($_SESSION['admin_must_change_password']) && basename($_SERVER['PHP_SELF']) !== 'change_password.php') {
    if (defined('JSON_API_REQUEST') && JSON_API_REQUEST) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Change your password before managing orders.']);
        exit;
    }
    header('Location: change_password.php'); exit;
}
require_page_access($pdo, basename($_SERVER['PHP_SELF']));
function require_role($roles) {
    $roles = (array)$roles;
    if (!in_array(admin_role(), $roles, true)) { http_response_code(403); exit('Access denied.'); }
}
