<?php
/**
 * Invoices – Sync payment status from Stripe (manual reconciliation)
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INC_PATH . '/stripe.php';
require_login();
require_role('admin', 'office');
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { flash_error('Invalid invoice ID.'); redirect('index.php'); }

$inv = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$id]);
if (!$inv) { flash_error('Invoice not found.'); redirect('index.php'); }

if ($inv['status'] === 'paid') {
    flash_success('Invoice ' . $inv['invoice_number'] . ' is already marked as paid.');
    redirect('view.php?id=' . $id);
}

$sessionId = trim($inv['stripe_session_id'] ?? '');
if ($sessionId === '') {
    flash_error('No Stripe Checkout session on record for this invoice. Payment may have been taken another way.');
    redirect('view.php?id=' . $id);
}

try {
    $session = stripe_client()->checkout->sessions->retrieve($sessionId);

    $paid       = ($session->payment_status ?? '') === 'paid';
    $achPending = ($session->status ?? '') === 'complete' && ($session->payment_status ?? '') === 'unpaid';

    if ($paid) {
        db_update('invoices', [
            'status'     => 'paid',
            'paid_at'    => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', $id);
        log_activity(
            'stripe_sync',
            'Manually synced ' . $inv['invoice_number'] . ' → paid (Stripe session ' . $sessionId . ')',
            'invoice',
            $id
        );
        flash_success('Invoice ' . $inv['invoice_number'] . ' synced from Stripe and marked as paid.');
    } elseif ($achPending) {
        flash_warning('Stripe shows the ACH transfer is still processing. The invoice will be marked paid automatically once the bank transfer settles (usually 3–5 business days).');
    } else {
        flash_error(
            'Stripe shows this session has not been paid '
            . '(payment_status: ' . ($session->payment_status ?? 'unknown') . ', '
            . 'status: ' . ($session->status ?? 'unknown') . '). '
            . 'No changes made.'
        );
    }
} catch (\Throwable $e) {
    flash_error('Stripe sync failed: ' . $e->getMessage());
    error_log('[stripe_sync] Invoice ' . $id . ': ' . $e->getMessage());
}

redirect('view.php?id=' . $id);
