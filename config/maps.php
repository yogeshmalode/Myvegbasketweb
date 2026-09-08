<?php
return [
    'provider' => 'leaflet',
    'google_maps_js_api_key' => getenv('GOOGLE_MAPS_JS_API_KEY') ?: '',
    'google_routes_api_key' => getenv('GOOGLE_ROUTES_API_KEY') ?: '',
    'default_store' => [
        'name' => 'Hadapsar Store',
        'lat' => 18.5011,
        'lng' => 73.9268,
    ],
    'default_center' => [
        'lat' => 18.5011,
        'lng' => 73.9268,
    ],
];
