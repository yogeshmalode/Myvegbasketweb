<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
if ($latitude === false || $longitude === false
    || $latitude === null || $longitude === null
    || $latitude < -90 || $latitude > 90
    || $longitude < -180 || $longitude > 180) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Could not verify your location. Please try again or choose your area manually.']);
    exit;
}

try {
    $serviceableStore = find_serviceable_dark_store($pdo, $latitude, $longitude);
} catch (Throwable $e) {
    error_log('Delivery location check failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'We could not check delivery coverage right now. Please choose your area manually or try again.']);
    exit;
}

if ($serviceableStore) {
    try {
        $address = reverse_geocode_coordinates($latitude, $longitude);
        $message = 'Delivery is available. Your current address was filled in; please add your house or flat details if needed.';
    } catch (Throwable $e) {
        error_log('Checkout reverse geocoding failed: ' . $e->getMessage());
        $address = null;
        $message = 'Delivery is available, but we could not fetch the address. Please enter it manually.';
    }

    echo json_encode([
        'success' => true,
        'serviceable' => true,
        'address' => $address,
        'message' => $message,
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'serviceable' => false,
    'message' => 'Sorry, we do not deliver to your current location yet.',
]);
