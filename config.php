<?php
// =========================================================
// VegBasket - Central Configuration
// =========================================================

// ---- Database settings (edit these for your server) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'u437666696_Vegbasket');
define('DB_USER', 'u437666696_yogesh');
define('DB_PASS', 'Python@9753');

// ---- Razorpay settings (Test Mode) ----
// Get your test keys from https://dashboard.razorpay.com/app/keys
// NEVER commit your live keys to a public repo.
define('RAZORPAY_KEY_ID', 'rzp_test_xxxxxxxxxxxxxx');
define('RAZORPAY_KEY_SECRET', 'your_test_key_secret_here');

// ---- UPI QR payment settings ----
// PAYMENT_MODE controls what checkout.php shows:
//   'upi_qr'   -> show your QR code, customer pays manually, order saved as "awaiting verification"
//   'razorpay' -> use the original Razorpay checkout flow
define('PAYMENT_MODE', 'upi_qr');
// Image shown on the checkout page. Put your QR code file at this path.
define('PAYMENT_QR_IMAGE', '/assets/images/payment-qr.jpg');
// Name printed under the QR code (should match the QR's registered UPI name)
define('PAYMENT_QR_NAME', 'Yogesh Bhausaheb Malode');

// ---- Mobile OTP login (2Factor.in) ----
// Sign up free at https://2factor.in, verify your account, and paste your
// API key below (Dashboard -> API Keys). 2Factor handles OTP generation,
// SMS delivery, and verification for you — nothing to store yourself.
define('TWOFACTOR_API_KEY', 'your_2factor_api_key_here');

// ---- Mobile OTP login (Firebase Phone Auth) ----
// From console.firebase.google.com: create a project -> Authentication ->
// Sign-in method -> enable Phone -> Project settings -> add a Web app ->
// copy the config values below. NOTE: Firebase requires the Blaze
// (pay-as-you-go) plan with a billing card attached to send real SMS —
// it is NOT free beyond 10 test SMS/day. See our conversation for details.
define('FIREBASE_API_KEY', 'your_firebase_api_key_here');
define('FIREBASE_AUTH_DOMAIN', 'your-project.firebaseapp.com');
define('FIREBASE_PROJECT_ID', 'your-project-id');

// ---- Order alert settings ----
// Email address that receives a notification every time a new order is placed.
// Change this to your own email address.
define('ADMIN_ALERT_EMAIL', 'myvegbasketcare@gmail.com');
// Turn email alerts on/off (set to false if your host doesn't support mail())
define('ADMIN_ALERT_EMAIL_ENABLED', true);

// ---- Site settings ----
define('SITE_NAME', 'MyVegBasket');
define('SITE_CURRENCY', '₹');
define('BASE_URL', ''); // change if hosted in a sub-folder

// Used for SEO meta tags (canonical URLs, Open Graph, sitemap, robots.txt).
// Change this if the domain ever changes.
define('SITE_URL', 'https://myvegbasket.com');
define('SITE_DESCRIPTION', 'Farm-fresh vegetables and fruits delivered to your door the same day. Order online and pay easily via UPI.');

// ---- Start session (needed for cart + admin login) ----
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax'
    ]);
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

// ---- Application security helpers ----
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verify_csrf($token) {
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
function require_csrf() {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('Invalid security token. Please go back and try again.');
    }
}
function admin_role() {
    return $_SESSION['admin_role'] ?? null;
}
function is_admin_role() {
    return admin_role() === 'admin';
}
function can_manage_inventory() {
    return in_array(admin_role(), ['admin','staff'], true);
}
function auto_sale_price($cost) {
    return round((float)$cost * 1.45, 2);
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

// ---- Delivery charge ----
// Free delivery from DELIVERY_FREE_THRESHOLD upwards; a flat charge below it.
define('DELIVERY_CHARGE', 25);
define('DELIVERY_FREE_THRESHOLD', 99);

function get_delivery_charge($subtotal) {
    return $subtotal >= DELIVERY_FREE_THRESHOLD ? 0 : DELIVERY_CHARGE;
}

function cart_grand_total() {
    $subtotal = cart_total();
    return $subtotal + get_delivery_charge($subtotal);
}

// ---- Offers / coupon codes ----

// Fetches every currently-redeemable offer (active, not expired) for
// display on the public Offers page.
function get_active_offers($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM offers WHERE is_active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE()) ORDER BY created_at DESC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // Table doesn't exist yet (migration not run) — degrade to "no
        // offers" instead of taking the whole homepage down.
        return [];
    }
}

// Validates a coupon code against the cart subtotal and returns what it's
// worth, without trusting anything from the browser. Returns:
//   ['valid' => true, 'offer' => [...], 'discount' => 12.50, 'free_delivery' => bool]
// or ['valid' => false, 'message' => '...']
function validate_coupon($pdo, $code, $subtotal) {
    $code = strtoupper(trim($code));
    if ($code === '') {
        return ['valid' => false, 'message' => 'Enter a coupon code.'];
    }

    $stmt = $pdo->prepare("SELECT * FROM offers WHERE coupon_code = ? AND is_active = 1");
    try {
        $stmt->execute([$code]);
    } catch (PDOException $e) {
        return ['valid' => false, 'message' => 'Coupons aren\'t set up yet on this site.'];
    }
    $offer = $stmt->fetch();

    if (!$offer) {
        return ['valid' => false, 'message' => 'Invalid or expired coupon code.'];
    }
    if ($offer['valid_until'] && $offer['valid_until'] < date('Y-m-d')) {
        return ['valid' => false, 'message' => 'This coupon has expired.'];
    }
    if ($subtotal < $offer['min_order_amount']) {
        return ['valid' => false, 'message' => 'This coupon needs a minimum order of ' . SITE_CURRENCY . number_format($offer['min_order_amount'], 2) . '.'];
    }

    $discount = 0;
    $freeDelivery = false;

    if ($offer['discount_type'] === 'percent') {
        $discount = round($subtotal * ($offer['discount_value'] / 100), 2);
    } elseif ($offer['discount_type'] === 'flat') {
        $discount = min((float)$offer['discount_value'], $subtotal);
    } elseif ($offer['discount_type'] === 'free_delivery') {
        $freeDelivery = true;
    }

    return ['valid' => true, 'offer' => $offer, 'discount' => $discount, 'free_delivery' => $freeDelivery];
}

// MySQL's CURRENT_TIMESTAMP stores time in the server's own timezone
// (UTC on most hosts, including Hostinger by default), but the shop and
// its customers are in India. Use this everywhere a stored date/time is
// shown to a person, instead of raw date(strtotime(...)), which just
// reads the UTC clock time as if it were already IST.
function format_ist($mysqlDatetime, $format = 'd M Y, h:i A') {
    if (!$mysqlDatetime) return '';
    try {
        $dt = new DateTime($mysqlDatetime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $dt->format($format);
    } catch (Exception $e) {
        return date($format, strtotime($mysqlDatetime));
    }
}

// Sends an OTP to an Indian mobile number via 2Factor.in. Returns the
// session_id needed to verify it later, or null on failure. 2Factor
// generates and tracks the actual OTP code themselves — we never see or
// store the code, only this session token.
function send_otp_sms($phone) {
    if (!function_exists('curl_init')) return null;
    if (!defined('TWOFACTOR_API_KEY') || TWOFACTOR_API_KEY === 'your_2factor_api_key_here') return null;

    $url = 'https://2factor.in/API/V1/' . TWOFACTOR_API_KEY . '/SMS/' . urlencode($phone) . '/AUTOGEN';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!$response) return null;

    $data = json_decode($response, true);
    if (($data['Status'] ?? '') === 'Success') {
        return $data['Details']; // this is the session_id
    }
    return null;
}

// Verifies an OTP the customer typed in against the session_id 2Factor
// gave us when the SMS was sent. Returns true/false.
function verify_otp_sms($sessionId, $otp) {
    if (!function_exists('curl_init')) return false;
    if (!defined('TWOFACTOR_API_KEY') || TWOFACTOR_API_KEY === 'your_2factor_api_key_here') return false;

    $url = 'https://2factor.in/API/V1/' . TWOFACTOR_API_KEY . '/SMS/VERIFY/' . urlencode($sessionId) . '/' . urlencode($otp);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!$response) return false;

    $data = json_decode($response, true);
    return ($data['Status'] ?? '') === 'Success';
}

// Sends a one-time login code to a customer's email — completely free,
// using the same built-in mail() the order-alert emails already use.
// No third-party SMS provider or account needed.
function send_email_otp($email, $otp) {
    $subject = 'Your ' . SITE_NAME . ' login code: ' . $otp;
    $body = "Your one-time login code is:\n\n    $otp\n\nThis code expires in 10 minutes. If you didn't request this, you can safely ignore this email.\n\n— " . SITE_NAME;
    $headers = 'From: ' . SITE_NAME . ' <no-reply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n";

    return @mail($email, $subject, $body, $headers);
}

function ensure_management_schema($pdo) {
    // The management pages are backward-compatible with older MyVegBasket databases.
    // We only add missing columns/tables; existing data is preserved.
    try {
        $db = DB_NAME;
        $hasColumn = function($table, $column) use ($pdo, $db) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $q->execute([$db, $table, $column]);
            return (int)$q->fetchColumn() > 0;
        };
        $hasTable = function($table) use ($pdo, $db) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
            $q->execute([$db, $table]);
            return (int)$q->fetchColumn() > 0;
        };

        if ($hasTable('admins') && !$hasColumn('admins', 'role')) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN role ENUM('admin','staff') NOT NULL DEFAULT 'admin'");
        }
        if ($hasTable('vegetables')) {
            if (!$hasColumn('vegetables', 'supplier_name')) $pdo->exec("ALTER TABLE vegetables ADD COLUMN supplier_name VARCHAR(120) DEFAULT NULL");
            if (!$hasColumn('vegetables', 'cost_price')) $pdo->exec("ALTER TABLE vegetables ADD COLUMN cost_price DECIMAL(10,2) NOT NULL DEFAULT 0");
        }
        if ($hasTable('order_items') && !$hasColumn('order_items', 'cost_price')) {
            $pdo->exec("ALTER TABLE order_items ADD COLUMN cost_price DECIMAL(10,2) NOT NULL DEFAULT 0");
        }
        if ($hasTable('orders') && !$hasColumn('orders', 'discount_amount')) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS wastage (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vegetable_id INT NOT NULL,
            quantity INT NOT NULL,
            reason ENUM('Expired','Damaged','Spoiled','Other') NOT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vegetable_id) REFERENCES vegetables(id) ON DELETE RESTRICT,
            FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vegetable_id INT NOT NULL,
            movement_type ENUM('purchase','adjustment','sale','wastage') NOT NULL,
            quantity INT NOT NULL,
            reference_id INT DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vegetable_id) REFERENCES vegetables(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        if ($hasTable('vegetables')) {
            $pdo->exec("UPDATE vegetables SET cost_price = ROUND(price / 1.45, 2) WHERE cost_price = 0 AND price > 0");
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function is_admin_logged_in() {
    return !empty($_SESSION['admin_id']) && !empty($_SESSION['admin_role']);
}

// ---- Firebase Phone Auth: server-side ID token verification ----
// Firebase doesn't publish an official PHP Admin SDK, so we verify the ID
// token ourselves: it's a standard JWT signed by Google, checkable against
// Google's published public keys. This avoids needing Composer or any
// extra library on your host.

function base64url_decode_firebase($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

// Google's public keys rotate periodically; cache them for an hour so we
// aren't fetching this on every single login attempt.
function get_firebase_public_certs() {
    $cacheFile = sys_get_temp_dir() . '/vegbasket_firebase_certs.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }
    if (!function_exists('curl_init')) return null;

    $ch = curl_init('https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return null;

    $certs = json_decode($resp, true);
    if ($certs) @file_put_contents($cacheFile, $resp);
    return $certs;
}

// Verifies a Firebase ID token's signature and standard claims. Returns
// the decoded payload (containing phone_number, sub/uid, etc.) on success,
// or null if the token is invalid, expired, or from a different project.
function verify_firebase_id_token($idToken) {
    if (!defined('FIREBASE_PROJECT_ID') || FIREBASE_PROJECT_ID === 'your-project-id') return null;

    $parts = explode('.', $idToken);
    if (count($parts) !== 3) return null;
    [$headerB64, $payloadB64, $sigB64] = $parts;

    $header  = json_decode(base64url_decode_firebase($headerB64), true);
    $payload = json_decode(base64url_decode_firebase($payloadB64), true);
    if (!$header || !$payload || empty($header['kid'])) return null;

    $certs = get_firebase_public_certs();
    if (!$certs || !isset($certs[$header['kid']])) return null;

    $publicKey = openssl_pkey_get_public($certs[$header['kid']]);
    if (!$publicKey) return null;

    $signedData = $headerB64 . '.' . $payloadB64;
    $signature  = base64url_decode_firebase($sigB64);
    if (openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) return null;

    // Standard Firebase ID token claim checks
    if (($payload['aud'] ?? '') !== FIREBASE_PROJECT_ID) return null;
    if (($payload['iss'] ?? '') !== 'https://securetoken.google.com/' . FIREBASE_PROJECT_ID) return null;
    if (($payload['exp'] ?? 0) < time()) return null;
    if (($payload['iat'] ?? PHP_INT_MAX) > time() + 60) return null;
    if (empty($payload['phone_number'])) return null;

    return $payload;
}

function is_customer_logged_in() {
    return !empty($_SESSION['customer_id']);
}

// Fetches the logged-in customer's row (name, email, phone, etc), or null
// if nobody's logged in. Cached per-request so it only hits the DB once.
function current_customer() {
    static $customer = null;
    static $loaded = false;
    if ($loaded) return $customer;
    $loaded = true;

    if (empty($_SESSION['customer_id'])) return null;

    global $pdo;
    $stmt = $pdo->prepare("SELECT id, name, email, phone FROM customers WHERE id = ?");
    $stmt->execute([$_SESSION['customer_id']]);
    $customer = $stmt->fetch() ?: null;
    return $customer;
}

function redirect($url) {
    header("Location: $url");
    exit;
}

// Sends a plain-text email to ADMIN_ALERT_EMAIL whenever a new order comes in
// (or a customer confirms they've paid). Uses PHP's built-in mail() function,
// which most shared hosts (Hostinger, etc.) support out of the box.
// If your host blocks mail(), set ADMIN_ALERT_EMAIL_ENABLED to false in
// config.php and rely on the admin/orders.php dashboard instead.
function send_order_alert($subject, $lines) {
    if (!defined('ADMIN_ALERT_EMAIL_ENABLED') || !ADMIN_ALERT_EMAIL_ENABLED) return false;
    if (!defined('ADMIN_ALERT_EMAIL') || !ADMIN_ALERT_EMAIL) return false;

    $body = implode("\n", $lines);
    $headers = 'From: ' . SITE_NAME . ' <no-reply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n";

    // @ suppresses warnings if mail() isn't configured on this host — we don't
    // want a failed email to break order placement.
    return @mail(ADMIN_ALERT_EMAIL, $subject, $body, $headers);
}

// Looks up latitude/longitude for a text address using OpenStreetMap's free
// Nominatim geocoder (no API key needed). Used once per order to place a pin
// at the delivery address on the tracking map, then cached in the orders
// table so we don't call it again. Nominatim's usage policy allows light,
// non-bulk use like this; don't call it in a loop over many orders at once.
function geocode_address($address) {
    if (!function_exists('curl_init')) return null;

    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($address);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_USERAGENT      => 'VegBasketOrderTracking/1.0 (' . (defined('ADMIN_ALERT_EMAIL') ? ADMIN_ALERT_EMAIL : 'contact@example.com') . ')',
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    if (!$result) return null;
    $data = json_decode($result, true);
    if (!empty($data[0]['lat']) && !empty($data[0]['lon'])) {
        return ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
    }
    return null;
}

// Fetches an order's row and makes sure its delivery address has a cached
// lat/lng (geocoding it on first use if missing). Returns the order array
// (with fresh address_lat/address_lng) or null if the order doesn't exist.
function get_order_with_geocoded_address($pdo, $orderId) {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) return null;

    if (!$order['address_lat'] || !$order['address_lng']) {
        $geo = geocode_address($order['address']);
        if ($geo) {
            $upd = $pdo->prepare("UPDATE orders SET address_lat = ?, address_lng = ? WHERE id = ?");
            $upd->execute([$geo['lat'], $geo['lng'], $orderId]);
            $order['address_lat'] = $geo['lat'];
            $order['address_lng'] = $geo['lng'];
        }
    }
    return $order;
}

// Returns the price a customer actually pays for a product right now —
// the sale_price if one is set and genuinely lower than the normal price,
// otherwise the normal price. Centralizing this so the shop page, cart,
// and order total all agree on what "the price" means.
// Parses a size label like "250 g", "500g", "1 kg", "1 litre", "250 ml"
// into what fraction of the given base unit (kg/gram/litre) it represents.
// Returns null if the label can't be understood or doesn't match that
// unit's family (e.g. a "piece"-style label). Mirrors the JS version used
// in the Add/Edit Vegetable pages so both produce identical results.
function size_fraction_of_base_unit($label, $baseUnit) {
    if (!preg_match('/^([\d.]+)\s*(kilogram|kilograms|kg|gram|grams|g|litre|litres|liter|liters|l|millilitre|millilitres|ml)\b/i', trim((string)$label), $m)) {
        return null;
    }
    $value = (float)$m[1];
    $unit = strtolower($m[2]);

    $grams = null;
    $ml = null;
    if ($unit === 'kg' || str_starts_with($unit, 'kilogram')) {
        $grams = $value * 1000;
    } elseif ($unit === 'g' || str_starts_with($unit, 'gram')) {
        $grams = $value;
    } elseif ($unit === 'l' || str_starts_with($unit, 'litre') || str_starts_with($unit, 'liter')) {
        $ml = $value * 1000;
    } elseif ($unit === 'ml' || str_starts_with($unit, 'millilitre')) {
        $ml = $value;
    }

    if ($baseUnit === 'kg' && $grams !== null) return $grams / 1000;
    if ($baseUnit === 'gram' && $grams !== null) return $grams;
    if ($baseUnit === 'litre' && $ml !== null) return $ml / 1000;
    return null;
}

// Rescales every size-option price for ONE product to match its current
// base price, e.g. if 1 kg is now ₹62, a "250 g" option becomes ₹15.50.
// Returns how many variants were updated vs. left alone (labels it
// couldn't parse, like "piece"-based sizes, are skipped untouched).
function recalculate_variant_prices($pdo, $vegId, $basePrice, $baseUnit) {
    if (!in_array($baseUnit, ['kg', 'gram', 'litre'], true) || $basePrice <= 0) {
        return ['updated' => 0, 'skipped' => 0];
    }
    $stmt = $pdo->prepare("SELECT id, label FROM vegetable_variants WHERE vegetable_id = ?");
    $stmt->execute([$vegId]);
    $variants = $stmt->fetchAll();
    if (!$variants) return ['updated' => 0, 'skipped' => 0];

    $update = $pdo->prepare("UPDATE vegetable_variants SET price = ? WHERE id = ?");
    $updated = 0;
    $skipped = 0;
    foreach ($variants as $v) {
        $fraction = size_fraction_of_base_unit($v['label'], $baseUnit);
        if ($fraction === null) {
            $skipped++;
            continue;
        }
        $update->execute([round($basePrice * $fraction, 2), $v['id']]);
        $updated++;
    }
    return ['updated' => $updated, 'skipped' => $skipped];
}

// Runs recalculate_variant_prices() across every product that currently
// has size options, using each product's own live price/unit. This is
// the "one click for all products" bulk action.
function recalculate_all_variant_prices($pdo) {
    $vegetables = $pdo->query("SELECT DISTINCT v.id, v.price, v.unit
        FROM vegetables v
        INNER JOIN vegetable_variants vv ON vv.vegetable_id = v.id")->fetchAll();

    $totalUpdated = 0;
    $totalSkipped = 0;
    $productsTouched = 0;
    foreach ($vegetables as $veg) {
        $result = recalculate_variant_prices($pdo, $veg['id'], (float)$veg['price'], $veg['unit']);
        $totalUpdated += $result['updated'];
        $totalSkipped += $result['skipped'];
        if ($result['updated'] > 0) $productsTouched++;
    }
    return ['products' => $productsTouched, 'updated' => $totalUpdated, 'skipped' => $totalSkipped];
}

function get_effective_price($veg) {
    if (!empty($veg['sale_price']) && (float)$veg['sale_price'] > 0 && (float)$veg['sale_price'] < (float)$veg['price']) {
        return (float)$veg['sale_price'];
    }
    return (float)$veg['price'];
}

// Fetches size/weight variants (e.g. 250 g, 500 g, 1 kg) for a set of
// vegetable IDs in one query, grouped by vegetable_id. Products with no
// rows in vegetable_variants simply won't appear in the returned array —
// callers should fall back to the product's normal price/unit in that case.
function get_variants_by_vegetable($pdo, array $vegIds) {
    $vegIds = array_values(array_unique(array_filter($vegIds)));
    if (!$vegIds) return [];
    try {
        $placeholders = implode(',', array_fill(0, count($vegIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM vegetable_variants WHERE vegetable_id IN ($placeholders) ORDER BY sort_order ASC, price ASC");
        $stmt->execute($vegIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['vegetable_id']][] = $row;
        }
        return $out;
    } catch (PDOException $e) {
        // Table doesn't exist yet (migration_variants.sql not run) — degrade
        // to "no size options" instead of taking the whole page down.
        return [];
    }
}

// Simple emoji lookup so the catalog looks lively without needing
// real product photos. Falls back to a generic veggie emoji.
function veg_emoji($name) {
    $map = [
        'tomato' => '🍅', 'potato' => '🥔', 'onion' => '🧅', 'carrot' => '🥕',
        'spinach' => '🥬', 'cauliflower' => '🥦', 'capsicum' => '🫑', 'brinjal' => '🍆',
        'cucumber' => '🥒', 'cabbage' => '🥬', 'green peas' => '🫛', 'ginger' => '🫚',
        'garlic' => '🧄', 'corn' => '🌽', 'sweet corn' => '🌽', 'chilli' => '🌶️',
        'green chilli' => '🌶️', 'pumpkin' => '🎃', 'beetroot' => '🍠', 'radish' => '🥕',
        'sweet potato' => '🍠', 'ladyfinger' => '🥒', 'bottle gourd' => '🥒',
        'bitter gourd' => '🥒', 'green beans' => '🫛', 'broccoli' => '🥦',
        // Fruits
        'apple' => '🍎', 'banana' => '🍌', 'mango' => '🥭', 'orange' => '🍊',
        'grapes' => '🍇', 'papaya' => '🫐', 'watermelon' => '🍉', 'pomegranate' => '🍎',
        'guava' => '🍈', 'pineapple' => '🍍',
        // Dairy
        'milk' => '🥛',
        // Leafy greens
        'coriander' => '🌿', 'fenugreek' => '🌿', 'mint' => '🌿',
        'curry leaves' => '🌿', 'amaranth' => '🥬', 'lettuce' => '🥬',
    ];
    $key = strtolower($name);
    return $map[$key] ?? '🥗';
}

// Renders the product thumbnail: a real photo if one exists on disk for
// this product, otherwise the emoji fallback.
//
// To add a real photo for a product, just save an image file named after
// the product (lowercase, spaces/punctuation removed) into assets/images/
// — e.g. a photo for "Green Peas" should be saved as greenpeas.jpg. Any of
// .jpg / .jpeg / .png / .webp work. No code or database change needed —
// it starts showing automatically the moment the file exists.
function veg_thumb_html($veg) {
    $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $veg['name']));

    // Curated Wikimedia Commons photographs. These are real photographs and
    // are used instead of the original SVG illustrations. The source/licence
    // page for every photograph is recorded in photo_credits.php.
    static $photoMap = [
        'tomato' => 'Tomato (1).jpg',
        'potato' => 'A Potato.jpg',
        'onion' => 'Onions 700x530.jpg',
        'carrot' => 'CARROT.jpg',
        'spinach' => 'Spinach.jpg',
        'cauliflower' => '19 - cauliflower.jpg',
        'capsicum' => 'Capsicum.jpg',
        'brinjal' => 'Brinjal eggplant.jpg',
        'cucumber' => 'Cucumber.jpg',
        'cabbage' => 'Cabbage.jpg',
        'greenpeas' => 'Green Pea.jpg',
        'ginger' => 'Ginger Root.jpg',
        'garlic' => 'Garlic image.jpg',
        'beetroot' => 'Beetroot fruit.jpg',
        'radish' => 'Radish.jpg',
        'sweetpotato' => 'A sweet potato.jpg',
        'ladyfinger' => 'Ladyfinger.jpg',
        'bottlegourd' => 'Bottle gourd.jpg',
        'bittergourd' => 'Bitter gourd.jpg',
        'greenbeans' => 'French-beans.jpg',
        'broccoli' => 'Broccoli.jpg',
        'greenchilli' => 'Green Chilli.jpg',
        'sweetcorn' => 'Sweet corn.jpg',
        'apple' => 'Apple Fruit.jpg',
        'banana' => 'Banana.jpg',
        'mango' => 'Mango.jpg',
        'orange' => 'Orange (1).jpg',
        'grapes' => 'Ripe grapes.jpg',
        'papaya' => 'Papaya.jpg',
        'watermelon' => 'Watermelon.jpg',
        'pomegranate' => 'Pomegranate photo.jpg',
        'guava' => 'Guava.jpg',
        'pineapple' => 'Pineapple.jpg',
        'milk' => 'Milk (24299977096).jpg',
        'coriander' => 'Coriander leaves.jpg',
        'fenugreek' => 'Fenugreek.jpg',
        'mint' => 'Mint.jpg',
        'curryleaves' => 'Curry leaves.jpg',
        'amaranth' => 'Leaves of Amaranthus tricolor.jpg',
        'lettuce' => 'Raw lettuce.jpg',
    ];

    // Local merchant photos always override the curated remote defaults.
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $relative = "/assets/images/$slug.$ext";
        if (file_exists(__DIR__ . $relative)) {
            return '<img src="' . BASE_URL . $relative . '" alt="' . h($veg['name']) . '" loading="lazy" decoding="async" '
                 . 'style="width:100%; height:100%; object-fit:cover; display:block; border-radius:10px;">';
        }
    }

    if (isset($photoMap[$slug])) {
        $file = $photoMap[$slug];
        $remote = 'https://commons.wikimedia.org/wiki/Special:Redirect/file/' . rawurlencode($file);
        $fallback = veg_emoji($veg['name']);
        return '<img src="' . h($remote) . '" alt="' . h($veg['name']) . '" loading="lazy" decoding="async" referrerpolicy="no-referrer" '
             . 'onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\';" '
             . 'style="width:100%; height:100%; object-fit:cover; display:block; border-radius:10px;">'
             . '<span aria-hidden="true" style="display:none; width:100%; height:100%; align-items:center; justify-content:center; font-size:3.4rem;">'
             . $fallback . '</span>';
    }

    return '<span style="font-size:3.4rem;">' . veg_emoji($veg['name']) . '</span>';
}
