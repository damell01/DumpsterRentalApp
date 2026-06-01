<?php
/**
 * AJAX – Save a geocoded address to the cache.
 * POST: _csrf, address, lat, lng
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

if (!csrf_verify()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$address = trim($_POST['address'] ?? '');
$lat     = (float)($_POST['lat'] ?? 0);
$lng     = (float)($_POST['lng'] ?? 0);

if ($address === '' || $lat === 0.0 || $lng === 0.0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$hash = md5(strtolower($address));

try {
    db_execute(
        "INSERT INTO geocode_cache (address_hash, address, lat, lng, created_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng)",
        [$hash, $address, $lat, $lng]
    );
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false]);
}
