<?php
// =========================================================
// VegBasket - Central Configuration
// =========================================================

// ---- Database settings (edit these for your server) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'u437666696_Vegbasket');
define('DB_USER', 'u437666696_yogesh');
define('DB_PASS', 'Python@9753');
//define('DB_NAME', 'vegbasket');
//define('DB_USER', 'root');
//define('DB_PASS', '');

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

$myvegbasket_maps = require __DIR__ . '/config/maps.php';
define('MAP_PROVIDER', $myvegbasket_maps['provider'] ?? 'leaflet');
define('GOOGLE_MAPS_JS_API_KEY', $myvegbasket_maps['google_maps_js_api_key'] ?? '');
define('GOOGLE_ROUTES_API_KEY', $myvegbasket_maps['google_routes_api_key'] ?? '');

define('STORE_LAT', (float)($myvegbasket_maps['default_store']['lat'] ?? 18.5011));
define('STORE_LNG', (float)($myvegbasket_maps['default_store']['lng'] ?? 73.9268));

define('STORE_NAME', $myvegbasket_maps['default_store']['name'] ?? 'Hadapsar Store');

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
function require_csrf($token = null) {
    if ($token !== null) {
        $_POST['csrf_token'] = $token;
    }
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

function get_order_status_options() {
    return [
        'pending'                    => 'Pending',
        'placed'                     => 'Order Placed',
        'processing'                 => 'Preparing',
        'ready_for_pickup'          => 'Ready for Pickup',
        'delivery_partner_assigned' => 'Delivery Partner Assigned',
        'out_for_delivery'          => 'Out for Delivery',
        'arriving_soon'             => 'Arriving Soon',
        'delivered'                 => 'Delivered',
        'cancelled'                 => 'Cancelled',
    ];
}

function get_delivery_status_steps() {
    return [
        'placed'                     => 'Order Placed',
        'processing'                 => 'Preparing',
        'ready_for_pickup'          => 'Ready for Pickup',
        'delivery_partner_assigned' => 'Delivery Partner Assigned',
        'out_for_delivery'          => 'Out for Delivery',
        'arriving_soon'             => 'Arriving Soon',
        'delivered'                 => 'Delivered',
    ];
}

function normalize_order_status($status) {
    $status = strtolower(trim((string) $status));
    $aliases = [
        'packing'                  => 'processing',
        'preparing'                => 'processing',
        'ready_to_dispatch'        => 'ready_for_pickup',
        'dispatched'               => 'out_for_delivery',
        'picked_up'                => 'out_for_delivery',
        'on_the_way'               => 'out_for_delivery',
        'driver_assigned'          => 'delivery_partner_assigned',
        'delivery_partner_assigned' => 'delivery_partner_assigned',
        'arriving'                 => 'arriving_soon',
        'completed'                => 'delivered',
    ];

    if (isset($aliases[$status])) {
        return $aliases[$status];
    }

    return array_key_exists($status, get_order_status_options()) ? $status : 'placed';
}

function haversine_km($lat1, $lng1, $lat2, $lng2) {
    $lat1 = (float)$lat1;
    $lng1 = (float)$lng1;
    $lat2 = (float)$lat2;
    $lng2 = (float)$lng2;
    $radius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
    return $radius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function estimate_eta_minutes($distanceKm) {
    $distanceKm = max((float)$distanceKm, 0.1);
    return (int)max(4, round(($distanceKm / 24) * 60));
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
        if ($hasTable('order_items')) {
            if (!$hasColumn('order_items', 'cost_price')) $pdo->exec("ALTER TABLE order_items ADD COLUMN cost_price DECIMAL(10,2) NOT NULL DEFAULT 0");
            if (!$hasColumn('order_items', 'updated_at')) $pdo->exec("ALTER TABLE order_items ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL");
            if (!$hasColumn('order_items', 'measured_quantity')) $pdo->exec("ALTER TABLE order_items ADD COLUMN measured_quantity DECIMAL(10,3) DEFAULT NULL");
        }
        if ($hasTable('orders')) {
            if (!$hasColumn('orders', 'discount_amount')) $pdo->exec("ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
            if (!$hasColumn('orders', 'updated_at')) $pdo->exec("ALTER TABLE orders ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            if (!$hasColumn('orders', 'assigned_picker_id')) $pdo->exec("ALTER TABLE orders ADD COLUMN assigned_picker_id INT DEFAULT NULL");
            if (!$hasColumn('orders', 'rider_id')) $pdo->exec("ALTER TABLE orders ADD COLUMN rider_id INT DEFAULT NULL");
            if (!$hasColumn('orders', 'manifest_id')) $pdo->exec("ALTER TABLE orders ADD COLUMN manifest_id INT DEFAULT NULL");
            if (!$hasColumn('orders', 'delivery_lat')) $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_lat DECIMAL(10,7) DEFAULT NULL");
            if (!$hasColumn('orders', 'delivery_lng')) $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_lng DECIMAL(10,7) DEFAULT NULL");
            if (!$hasColumn('orders', 'location_updated_at')) $pdo->exec("ALTER TABLE orders ADD COLUMN location_updated_at TIMESTAMP NULL DEFAULT NULL");
            if (!$hasColumn('orders', 'delivered_at')) $pdo->exec("ALTER TABLE orders ADD COLUMN delivered_at TIMESTAMP NULL DEFAULT NULL");
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS vendors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            type ENUM('farmer','vendor','aggregator') NOT NULL DEFAULT 'vendor',
            phone VARCHAR(20) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            gst_no VARCHAR(50) DEFAULT NULL,
            payment_terms VARCHAR(50) DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS farmers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            village VARCHAR(150) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            payment_terms VARCHAR(50) DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT DEFAULT NULL,
            farmer_id INT DEFAULT NULL,
            source_type ENUM('mandi','farm','direct') NOT NULL DEFAULT 'mandi',
            market_name VARCHAR(120) DEFAULT NULL,
            purchase_date DATE NOT NULL,
            item_name VARCHAR(150) NOT NULL,
            quantity_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            rate_per_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            agent_commission DECIMAL(10,2) NOT NULL DEFAULT 0,
            market_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
            transport_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_terms VARCHAR(50) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
            FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS inward_goods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT NOT NULL,
            received_date DATE NOT NULL,
            gross_weight_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            tare_weight_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            net_weight_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            quality_grade ENUM('A','B','C') NOT NULL DEFAULT 'A',
            wastage_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM('received','qc_pending','rejected') NOT NULL DEFAULT 'received',
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (purchase_id) REFERENCES purchase_entries(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS vehicles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_no VARCHAR(50) NOT NULL,
            type VARCHAR(50) NOT NULL,
            max_capacity_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            driver_name VARCHAR(100) DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS dispatch_trips (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            route_name VARCHAR(150) DEFAULT NULL,
            dispatch_date DATE NOT NULL,
            order_weight_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            usable_weight_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            capacity_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            fuel_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            toll_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            driver_name VARCHAR(100) DEFAULT NULL,
            status ENUM('planned','dispatched','completed','blocked') NOT NULL DEFAULT 'planned',
            trip_cost_per_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB");

        if (!$hasTable('riders')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS riders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                vehicle VARCHAR(120) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                password_hash VARCHAR(255) DEFAULT NULL,
                pin_code VARCHAR(20) DEFAULT NULL,
                last_login_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        if (!$hasTable('delivery_manifests')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_manifests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rider_id INT DEFAULT NULL,
                created_by INT DEFAULT NULL,
                total_orders INT NOT NULL DEFAULT 0,
                total_km DECIMAL(8,2) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        if (!$hasTable('delivery_confirmations')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_confirmations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                manifest_id INT DEFAULT NULL,
                rider_id INT DEFAULT NULL,
                confirmed_by VARCHAR(120) DEFAULT NULL,
                method VARCHAR(40) DEFAULT 'manual',
                note TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        if ($hasTable('riders')) {
            if (!$hasColumn('riders', 'password_hash')) $pdo->exec("ALTER TABLE riders ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL");
            if (!$hasColumn('riders', 'pin_code')) $pdo->exec("ALTER TABLE riders ADD COLUMN pin_code VARCHAR(20) DEFAULT NULL");
            if (!$hasColumn('riders', 'last_login_at')) $pdo->exec("ALTER TABLE riders ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL");
            $riderCount = (int)$pdo->query("SELECT COUNT(*) FROM riders")->fetchColumn();
            if ($riderCount === 0) {
                $pdo->exec("INSERT INTO riders (name, phone, vehicle, is_active, password_hash, pin_code) VALUES ('Default Rider', '0000000000', 'Bike', 1, NULL, '1234')");
            }
        }

        if ($hasTable('vegetables')) {
            if (!$hasColumn('vegetables', 'min_buffer_stock')) $pdo->exec("ALTER TABLE vegetables ADD COLUMN min_buffer_stock DECIMAL(10,2) NOT NULL DEFAULT 0");
            if (!$hasColumn('vegetables', 'sale_price')) $pdo->exec("ALTER TABLE vegetables ADD COLUMN sale_price DECIMAL(10,2) DEFAULT NULL");
            $pdo->exec("UPDATE vegetables SET cost_price = ROUND(price / 1.45, 2) WHERE cost_price = 0 AND price > 0");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS procurement_inward (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vegetable_id INT NOT NULL,
            source_type ENUM('mandi','farmer','direct') NOT NULL DEFAULT 'mandi',
            source_name VARCHAR(150) DEFAULT NULL,
            purchase_date DATE NOT NULL,
            raw_weight_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            usable_weight_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            wastage_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            mandi_rate_per_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_procurement_inward_date (purchase_date),
            KEY idx_procurement_inward_veg (vegetable_id),
            CONSTRAINT fk_procurement_inward_veg FOREIGN KEY (vegetable_id) REFERENCES vegetables(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS dynamic_prices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vegetable_id INT NOT NULL,
            selling_price DECIMAL(10,2) NOT NULL,
            effective_date DATE NOT NULL,
            is_current TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_dynamic_prices_veg_current (vegetable_id, is_current),
            CONSTRAINT fk_dynamic_prices_veg FOREIGN KEY (vegetable_id) REFERENCES vegetables(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
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

function is_rider_logged_in() {
    return !empty($_SESSION['rider_id']);
}

function current_rider() {
    static $rider = null;
    static $loaded = false;
    if ($loaded) return $rider;
    $loaded = true;

    if (empty($_SESSION['rider_id'])) return null;

    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM riders WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$_SESSION['rider_id']]);
    $rider = $stmt->fetch() ?: null;
    return $rider;
}

function rider_login($pdo, $phone, $pin) {
    $phone = preg_replace('/\D+/', '', trim((string)$phone));
    $pin = trim((string)$pin);
    if ($phone === '' || $pin === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT * FROM riders WHERE phone = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$phone]);
    $rider = $stmt->fetch();
    if (!$rider) {
        return false;
    }

    $hash = $rider['password_hash'] ?? '';
    $legacyCode = (string)($rider['pin_code'] ?? '');
    if ($hash !== '' && password_verify($pin, $hash)) {
        $_SESSION['rider_id'] = (int)$rider['id'];
        $_SESSION['rider_name'] = $rider['name'];
        $pdo->prepare('UPDATE riders SET last_login_at = NOW() WHERE id = ?')->execute([$rider['id']]);
        return true;
    }
    if ($legacyCode !== '' && hash_equals($legacyCode, $pin)) {
        $_SESSION['rider_id'] = (int)$rider['id'];
        $_SESSION['rider_name'] = $rider['name'];
        $pdo->prepare('UPDATE riders SET last_login_at = NOW() WHERE id = ?')->execute([$rider['id']]);
        return true;
    }
    return false;
}

function require_rider_login() {
    if (!is_rider_logged_in()) {
        redirect('login.php');
    }
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
    $address = trim((string) $address);
    if ($address === '') {
        return ['lat' => 18.5011, 'lng' => 73.9268];
    }
    $fallback = function ($addr) {
        $cityHints = [
            'hadapsar' => [18.5011, 73.9268],
            'pune' => [18.5204, 73.8567],
            'wakad' => [18.5998, 73.7423],
            'kharadi' => [18.5512, 73.9370],
            'hinjewadi' => [18.5913, 73.7376],
            'baner' => [18.5595, 73.7796],
            'chinchwad' => [18.6315, 73.7998],
            'mumbai' => [19.0760, 72.8777],
            'nashik' => [20.5937, 78.9629],
            'aurangabad' => [19.8762, 75.3433],
            'solapur' => [17.6599, 75.9064],
            'satara' => [17.6809, 74.0183],
        ];

        $lower = strtolower($addr);
        foreach ($cityHints as $token => [$lat, $lng]) {
            if (strpos($lower, $token) !== false) {
                return ['lat' => (float)$lat, 'lng' => (float)$lng];
            }
        }

        return ['lat' => 18.5011, 'lng' => 73.9268];
    };

    if (!function_exists('curl_init')) {
        return $fallback($address);
    }

    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($address);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_USERAGENT      => 'VegBasketOrderTracking/1.0 (' . (defined('ADMIN_ALERT_EMAIL') ? ADMIN_ALERT_EMAIL : 'contact@example.com') . ')',
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    if (!$result) return $fallback($address);
    $data = json_decode($result, true);
    if (!empty($data[0]['lat']) && !empty($data[0]['lon'])) {
        return ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
    }
    return $fallback($address);
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
function starts_with($haystack, $needle) {
    return $needle === '' || strpos($haystack, $needle) === 0;
}

function size_fraction_of_base_unit($label, $baseUnit) {
    $baseUnit = strtolower(trim((string)$baseUnit));
    if ($baseUnit === 'grams') $baseUnit = 'gram';
    if ($baseUnit === 'kilograms' || $baseUnit === 'kgs' || $baseUnit === 'kilo') $baseUnit = 'kg';
    if ($baseUnit === 'liters' || $baseUnit === 'litres') $baseUnit = 'litre';
    if (!preg_match('/^([\d.]+)\s*(kilogram|kilograms|kgs|kg|gram|grams|gm|g|litre|litres|liter|liters|l|millilitre|millilitres|ml)\b/i', trim((string)$label), $m)) {
        return null;
    }
    $value = (float)$m[1];
    $unit = strtolower($m[2]);
    if ($unit === 'gm') $unit = 'g';

    $grams = null;
    $ml = null;
    if ($unit === 'kg' || starts_with($unit, 'kilogram')) {
        $grams = $value * 1000;
    } elseif ($unit === 'g' || starts_with($unit, 'gram')) {
        $grams = $value;
    } elseif ($unit === 'l' || starts_with($unit, 'litre') || starts_with($unit, 'liter')) {
        $ml = $value * 1000;
    } elseif ($unit === 'ml' || starts_with($unit, 'millilitre')) {
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

    try {
        $stmt = $pdo->prepare("SELECT id, label FROM vegetable_variants WHERE vegetable_id = ?");
        $stmt->execute([$vegId]);
        $variants = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['updated' => 0, 'skipped' => 0];
    }

    if (!$variants) return ['updated' => 0, 'skipped' => 0];

    try {
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
    } catch (Throwable $e) {
        return ['updated' => 0, 'skipped' => 0];
    }
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

// Renders the product thumbnail: a local image from the /img directory when
// available, otherwise a curated fallback, then the emoji fallback.
function veg_thumb_html($veg) {
    $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $veg['name']));

    static $localAliasMap = [
        'greenpeas' => 'peas',
        'greenchilli' => 'chilli',
        'sweetcorn' => 'corn',
        'greenbeans' => 'greenbeans',
        'bottlegourd' => 'bottlegourd',
        'bittergourd' => 'bittergourd',
        'curryleaves' => 'curryleaves',
    ];

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

    $candidates = array_values(array_unique(array_filter([$slug, $localAliasMap[$slug] ?? null])));

    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        foreach ($candidates as $fileName) {
            $relative = "/img/$fileName.$ext";
            if (file_exists(__DIR__ . $relative)) {
                return '<img src="' . BASE_URL . $relative . '" alt="' . h($veg['name']) . '" loading="lazy" decoding="async" '
                     . 'style="width:100%; height:100%; object-fit:cover; display:block; border-radius:10px;">';
            }
        }
    }

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
