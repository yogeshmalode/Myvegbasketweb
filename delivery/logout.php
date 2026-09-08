<?php
require_once __DIR__ . '/../config.php';
unset($_SESSION['rider_id'], $_SESSION['rider_name']);
redirect('login.php');
