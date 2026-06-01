<?php
/**
 * Admin API: Subscribe / Unsubscribe to Push Notifications (Admin Users)
 * POST /admin/api/push-subscribe.php
 *
 * Requires an active admin session.
 *
 * Body (JSON):
 *  {
 *    "action":       "getVapidKey" | "subscribe" | "unsubscribe",
 *    "subscription": { "endpoint": "...", "keys": { "p256dh": "...", "auth": "..." } }
 *  }
 *
 * Returns: { "success": true } or { "success": false, "error": "..." }
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/auth.php';

// Load push helper (needs autoloader for WebPush library)
$_autoload = ROOT_PATH . '/vendor/autoload.php';
if (file_exists($_autoload)) {
    require_once $_autoload;
}
require_once INC_PATH . '/push.php';

function admin_push_api_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_push_api_error('Method not allowed.', 405);
}

// Require a valid admin session
session_init();
if (!is_logged_in()) {
    admin_push_api_error('Unauthorized.', 401);
}

$user = current_user();
if (!$user) {
    admin_push_api_error('Unauthorized.', 401);
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    admin_push_api_error('Invalid JSON body.');
}

$action       = trim($data['action'] ?? '');
$subscription = $data['subscription'] ?? null;

// Return VAPID public key for the admin shell bootstrap flow.
if ($action === 'getVapidKey') {
    echo json_encode([
        'success' => true,
        'vapidPublicKey' => push_vapid_public_key(),
    ]);
    exit;
}

// ── Unsubscribe ────────────────────────────────────────────────────────────
if ($action === 'unsubscribe') {
    $endpoint = trim($subscription['endpoint'] ?? '');
    if (empty($endpoint)) {
        admin_push_api_error('Missing endpoint.');
    }
    push_delete_subscription($endpoint);
    echo json_encode(['success' => true]);
    exit;
}

// ── Subscribe ──────────────────────────────────────────────────────────────
if ($action !== 'subscribe') {
    admin_push_api_error('Invalid action. Use getVapidKey, subscribe, or unsubscribe.');
}

if (!is_array($subscription) || empty($subscription['endpoint'])) {
    admin_push_api_error('Missing subscription object.');
}

push_save_subscription(
    'admin',
    (string)$user['id'],
    $subscription,
    $_SERVER['HTTP_USER_AGENT'] ?? ''
);

$pub = push_vapid_public_key();
echo json_encode(['success' => true, 'vapidPublicKey' => $pub]);
