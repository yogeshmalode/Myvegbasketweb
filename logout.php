<?php
require_once __DIR__ . '/config.php';

revoke_customer_remember_token();
unset($_SESSION['customer_id'], $_SESSION['customer_name']);

redirect(BASE_URL . '/index.php');
