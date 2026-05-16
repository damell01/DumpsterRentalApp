<?php
/**
 * Public API: Subscribe / Unsubscribe to Push Notifications (Customer)
 * POST /api/push-subscribe.php
 *
 * Body (JSON):
 *  {
 *    "action":       "subscribe" | "unsubscribe",
 *    "subscription": { "endpoint": "...", "keys": { "p256dh": "...", "auth": "..." } },
 *    "identifier":   "customer@email.com" or "2515551234"  (email or phone)
 *  }
 *
 * Returns: { "success": true } or { "success": false, "error": "..." }
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$_admin_root = dirname(__DIR__, 2) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';

// Load push helper (needs autoloader for WebPush library)
$_autoload = $_admin_root . '/vendor/autoload.php';
if (file_exists($_autoload)) {
    require_once $_autoload;
}
require_once INC_PATH . '/push.php';
require_once INC_PATH . '/api_rate_limit.php';

// 10 subscribe/unsubscribe actions per IP per hour
api_rate_limit('push_subscribe', 10, 3600);

function push_api_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    push_api_error('Method not allowed.', 405);
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    push_api_error('Invalid JSON body.');
}

$action       = trim($data['action'] ?? '');
$subscription = $data['subscription'] ?? null;
$identifier   = trim($data['identifier'] ?? '');

// ── Get VAPID public key ───────────────────────────────────────────────────
if ($action === 'getVapidKey') {
    $pub = push_vapid_public_key();
    echo json_encode(['success' => true, 'vapidPublicKey' => $pub]);
    exit;
}

// ── Unsubscribe ────────────────────────────────────────────────────────────
if ($action === 'unsubscribe') {
    $endpoint = trim($subscription['endpoint'] ?? '');
    if (empty($endpoint)) {
        push_api_error('Missing endpoint.');
    }
    push_delete_subscription($endpoint);
    echo json_encode(['success' => true]);
    exit;
}

// ── Subscribe ──────────────────────────────────────────────────────────────
if ($action !== 'subscribe') {
    push_api_error('Invalid action. Use getVapidKey, subscribe, or unsubscribe.');
}

if (!is_array($subscription) || empty($subscription['endpoint'])) {
    push_api_error('Missing subscription object.');
}

// Identifier validation: must be a valid email or at least 7-digit phone
if (empty($identifier)) {
    push_api_error('Customer identifier (email or phone) is required.');
}

$is_email = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
$digits   = preg_replace('/\D/', '', $identifier);
$is_phone = strlen($digits) >= 7;

if (!$is_email && !$is_phone) {
    push_api_error('Identifier must be a valid email address or phone number.');
}

// Normalise phone to digits-only for consistent look-up
$normalised = $is_email ? strtolower($identifier) : $digits;

push_save_subscription(
    'customer',
    $normalised,
    $subscription,
    $_SERVER['HTTP_USER_AGENT'] ?? ''
);

// Return the VAPID public key so the client can use it (useful on first subscribe)
$pub = push_vapid_public_key();
echo json_encode(['success' => true, 'vapidPublicKey' => $pub]);
