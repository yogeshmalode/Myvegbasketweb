<?php
require_once __DIR__ . '/config.php';

unset($_SESSION['customer_id'], $_SESSION['customer_name']);

redirect(BASE_URL . '/index.php');
