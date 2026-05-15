<?php
/**
 * Return active Stripe Tax Rate objects as JSON.
 * Used by the Settings page Sync button.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_role('admin', 'office');

header('Content-Type: application/json');

if (!get_setting('stripe_secret_key')) {
    echo json_encode(['error' => 'Stripe secret key is not configured.']);
    exit;
}

try {
    require_once INC_PATH . '/stripe.php';
    $result = stripe_client()->taxRates->all(['active' => true, 'limit' => 25]);
    $list = [];
    foreach ($result->data as $r) {
        $list[] = [
            'id'           => $r->id,
            'display_name' => $r->display_name,
            'percentage'   => $r->percentage,
            'jurisdiction' => $r->jurisdiction ?? '',
        ];
    }
    echo json_encode(['rates' => $list]);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
