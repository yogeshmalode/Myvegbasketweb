<?php
// =========================================================
// VegBasket - Central Configuration
// =========================================================

// ---- Database settings (edit these for your server) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'vegbasket');
define('DB_USER', 'root');
define('DB_PASS', '');

// ---- Razorpay settings (Test Mode) ----
// Get your test keys from https://dashboard.razorpay.com/app/keys
// NEVER commit your live keys to a public repo.
define('RAZORPAY_KEY_ID', 'rzp_test_xxxxxxxxxxxxxx');
define('RAZORPAY_KEY_SECRET', 'your_test_key_secret_here');

// ---- Site settings ----
define('SITE_NAME', 'VegBasket');
define('SITE_CURRENCY', '₹');
define('BASE_URL', '/vegbasket'); // change if hosted in a sub-folder

// ---- Start session (needed for cart + admin login) ----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Database connection (PDO) ----
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed. Please check config.php -> ' . htmlspecialchars($e->getMessage()));
}

// ---- Small helpers used across the site ----
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function cart_count() {
    if (empty($_SESSION['cart'])) return 0;
    return array_sum(array_column($_SESSION['cart'], 'qty'));
}

function cart_total() {
    if (empty($_SESSION['cart'])) return 0;
    $total = 0;
    foreach ($_SESSION['cart'] as $item) {
        $total += $item['price'] * $item['qty'];
    }
    return $total;
}

function is_admin_logged_in() {
    return !empty($_SESSION['admin_id']);
}

function redirect($url) {
    header("Location: $url");
    exit;
}

// Simple emoji lookup so the catalog looks lively without needing
// real product photos. Falls back to a generic veggie emoji.
function veg_emoji($name) {
    $map = [
        'tomato' => '🍅', 'potato' => '🥔', 'onion' => '🧅', 'carrot' => '🥕',
        'spinach' => '🥬', 'cauliflower' => '🥦', 'capsicum' => '🫑', 'brinjal' => '🍆',
        'cucumber' => '🥒', 'cabbage' => '🥬', 'green peas' => '🫛', 'ginger' => '🫚',
        'garlic' => '🧄', 'corn' => '🌽', 'chilli' => '🌶️', 'pumpkin' => '🎃',
    ];
    $key = strtolower($name);
    return $map[$key] ?? '🥗';
}
