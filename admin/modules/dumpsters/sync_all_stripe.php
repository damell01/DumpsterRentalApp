<?php
/**
 * Dumpsters – Sync All to Stripe (POST handler)
 * Creates or updates Stripe products/prices for all active dumpsters.
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_role('admin', 'office');
csrf_check();

$stripe_key = trim(get_setting('stripe_secret_key', ''));
if ($stripe_key === '') {
    flash_error('Stripe is not configured. Add your Stripe secret key in Settings.');
    redirect('index.php');
}

require_once INC_PATH . '/stripe.php';

$dumpsters = db_fetchall('SELECT * FROM dumpsters WHERE active = 1 ORDER BY unit_code ASC');

if (empty($dumpsters)) {
    flash_error('No active dumpsters found to sync.');
    redirect('index.php');
}

$success_count = 0;
$fail_count    = 0;
$fail_msgs     = [];

foreach ($dumpsters as $dumpster) {
    try {
        $result = stripe_sync_dumpster_product($dumpster);

        db_update('dumpsters', [
            'stripe_product_id' => $result['stripe_product_id'],
            'stripe_price_id'   => $result['stripe_price_id'],
            'updated_at'        => date('Y-m-d H:i:s'),
        ], 'id', (int)$dumpster['id']);

        log_activity(
            'update',
            "Synced dumpster {$dumpster['unit_code']} to Stripe (product: {$result['stripe_product_id']})",
            'dumpster',
            (int)$dumpster['id']
        );

        $success_count++;
    } catch (\Throwable $e) {
        $fail_count++;
        $fail_msgs[] = $dumpster['unit_code'] . ': ' . $e->getMessage();
        error_log('[SyncAllStripe] Failed for dumpster ' . $dumpster['unit_code'] . ': ' . $e->getMessage());
    }
}

if ($fail_count === 0) {
    flash_success("All {$success_count} dumpster(s) synced to Stripe successfully, including recurring catalog prices where rates were set.");
} elseif ($success_count > 0) {
    flash_warning("{$success_count} dumpster(s) synced successfully. {$fail_count} failed: " . implode('; ', $fail_msgs));
} else {
    flash_error("Stripe sync failed for all dumpsters. " . implode('; ', $fail_msgs));
}

redirect('index.php');
