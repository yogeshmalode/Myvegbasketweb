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

try {
    $locationAddress = reverse_geocode_coordinates($latitude, $longitude);
} catch (Throwable $e) {
    error_log('Checkout reverse geocoding failed: ' . $e->getMessage());
    $locationAddress = null;
}

if ($serviceableStore) {
    echo json_encode([
        'success' => true,
        'serviceable' => true,
        'address' => $locationAddress['address'] ?? null,
        'postcode' => $locationAddress['postcode'] ?? '',
        'message' => $locationAddress
            ? 'Delivery is available. Check that the detected PIN matches your address and select the correct delivery area.'
            : 'Delivery is available, but the map returned only a PIN code or incomplete address. Please enter your street and locality manually.',
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'serviceable' => false,
    'address' => $locationAddress['address'] ?? null,
    'postcode' => $locationAddress['postcode'] ?? '',
    'message' => 'Sorry, we do not deliver to your current location yet.'
        . ($locationAddress ? ' Check the detected PIN and correct the address manually if needed.' : ' The map returned only a PIN code or incomplete address; please enter your street and locality manually.'),
]);
