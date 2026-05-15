<?php
/**
 * Secure Terms PDF download for booking/order confirmations.
 */

$_admin_root = dirname(__DIR__) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/mailer.php';

$idsStr = trim((string)($_GET['ids'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));

if ($idsStr === '' || $token === '') {
    http_response_code(404);
    exit('Not found');
}

$expected = hash_hmac('sha256', $idsStr, defined('PORTAL_SIGNING_KEY') ? PORTAL_SIGNING_KEY : 'booking-token-secret');
if (!hash_equals($expected, $token)) {
    http_response_code(403);
    exit('Invalid token');
}

$idParts = array_values(array_filter(array_map('intval', explode(',', $idsStr)), static fn(int $id): bool => $id > 0));
if (empty($idParts)) {
    http_response_code(404);
    exit('Not found');
}

$booking = db_fetch('SELECT * FROM bookings WHERE id = ? LIMIT 1', [$idParts[0]]);
if (!$booking) {
    http_response_code(404);
    exit('Not found');
}

$attachment = booking_terms_pdf_attachment($booking);
if ($attachment === null || empty($attachment['content'])) {
    http_response_code(404);
    exit('Terms PDF unavailable');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename((string)$attachment['name']) . '"');
header('Content-Length: ' . strlen((string)$attachment['content']));
header('X-Content-Type-Options: nosniff');
echo $attachment['content'];
exit;
