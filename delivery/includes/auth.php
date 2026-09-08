<?php
require_once __DIR__ . '/../../config.php';
if (!is_rider_logged_in()) {
    redirect('login.php');
}
