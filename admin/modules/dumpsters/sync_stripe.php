<?php
/**
 * Dumpsters – Sync to Stripe (POST handler)
 * Creates or updates the Stripe product and price for a dumpster.
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_role('admin', 'office');
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash_error('Invalid dumpster ID.');
    redirect('index.php');
}

$dumpster = db_fetch('SELECT * FROM dumpsters WHERE id = ? LIMIT 1', [$id]);
if (!$dumpster) {
    flash_error('Dumpster not found.');
    redirect('index.php');
}

$stripe_key = trim(get_setting('stripe_secret_key', ''));
if ($stripe_key === '') {
    flash_error('Stripe is not configured. Add your Stripe secret key in Settings.');
    redirect('edit.php?id=' . $id);
}

try {
    require_once INC_PATH . '/stripe.php';

    $result = stripe_sync_dumpster_product($dumpster);

    db_update('dumpsters', [
        'stripe_product_id' => $result['stripe_product_id'],
        'stripe_price_id'   => $result['stripe_price_id'],
        'updated_at'        => date('Y-m-d H:i:s'),
    ], 'id', $id);

    log_activity('update', "Synced dumpster {$dumpster['unit_code']} to Stripe (product: {$result['stripe_product_id']})", 'dumpster', $id);

    $priceSummary = !empty($result['price_map']) ? implode(', ', array_keys($result['price_map'])) : 'product only';
    flash_success("Dumpster {$dumpster['unit_code']} synced to Stripe successfully. Synced: {$priceSummary}.");
} catch (\Throwable $e) {
    flash_error('Stripe sync failed: ' . $e->getMessage());
}

redirect('edit.php?id=' . $id);
