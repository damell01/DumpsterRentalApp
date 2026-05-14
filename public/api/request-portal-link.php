<?php

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$_admin_root = dirname(__DIR__, 2) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/mailer.php';
require_once INC_PATH . '/billing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$email = strtolower(trim((string)($data['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'A valid email address is required.']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$emailFingerprint = 'email:' . hash('sha256', $email);

try {
    $recentByIp = db_fetch(
        "SELECT COUNT(*) AS cnt
         FROM activity_log
         WHERE action = 'portal_link_request'
           AND ip_address = ?
           AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        [$ip]
    );
    $recentByEmail = db_fetch(
        "SELECT COUNT(*) AS cnt
         FROM activity_log
         WHERE action = 'portal_link_request'
           AND description = ?
           AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        [$emailFingerprint]
    );

    if ((int)($recentByIp['cnt'] ?? 0) >= 10 || (int)($recentByEmail['cnt'] ?? 0) >= 5) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many portal link requests. Please try again later.']);
        exit;
    }

    db_insert('activity_log', [
        'user_id' => 0,
        'action' => 'portal_link_request',
        'description' => $emailFingerprint,
        'entity_type' => 'customer',
        'entity_id' => 0,
        'ip_address' => $ip,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    // Non-fatal. Continue so transient logging issues do not block the request flow.
}

$customer = db_fetch('SELECT * FROM customers WHERE LOWER(email) = ? LIMIT 1', [$email]);
if ($customer) {
    $token = billing_portal_access_service()->issueTokenForCustomer((int)$customer['id'], (int)get_setting('portal_link_ttl_minutes', '30'));
    $basePublicUrl = preg_replace('#/admin$#', '', APP_URL);
    $portalUrl = rtrim((string)$basePublicUrl, '/') . '/public/portal/index.php?customer_id=' . (int)$customer['id'] . '&token=' . urlencode($token);
    $html = email_template(
        'Customer Billing Portal',
        '<p>Your secure Trash Panda billing portal link is ready.</p><p>This link expires automatically.</p>',
        'Open Billing Portal',
        $portalUrl
    );
    send_email($email, 'Your Trash Panda Billing Portal Link', $html);
}

echo json_encode(['success' => true]);
