<?php
/**
 * Production-grade Stripe webhook dispatcher.
 */

$_admin_root = dirname(__DIR__, 2) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/billing.php';

$autoload = $_admin_root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'TrashPanda\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

header('Content-Type: application/json');

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($payload === '' || $signature === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing webhook payload or signature.']);
    exit;
}

try {
    $event = billing_webhook_service()->constructEvent($payload, $signature);
    $result = billing_webhook_service()->process($event, $payload);
    http_response_code(200);
    echo json_encode(['received' => true, 'duplicate' => !empty($result['duplicate'])]);
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    app_log('stripe_webhook', 'Signature verification failed', ['error' => $e->getMessage()]);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Stripe signature.']);
} catch (\Throwable $e) {
    app_log('stripe_webhook', 'Unhandled webhook exception', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Webhook processing failed.']);
}
