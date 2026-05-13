<?php
/**
 * Invoices – Generate Stripe Payment Link
 * Creates a Stripe Checkout session and stores the URL for the invoice.
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INC_PATH . '/stripe.php';
require_login();
require_role('admin', 'office');
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$paymentMethod = trim((string)($_POST['payment_method'] ?? 'stripe'));
if ($id <= 0) {
    flash_error('Invalid invoice ID.');
    redirect('index.php');
}

$inv = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$id]);
if (!$inv) {
    flash_error('Invoice not found.');
    redirect('index.php');
}

$stripe_key = trim(get_setting('stripe_secret_key', ''));
if ($stripe_key === '') {
    flash_error('Stripe is not configured. Add your Stripe secret key in Settings.');
    redirect('view.php?id=' . $id);
}

if ((float)$inv['total'] <= 0) {
    flash_error('Invoice total must be greater than $0 to generate a payment link.');
    redirect('view.php?id=' . $id);
}

try {
    $base_url    = rtrim(APP_URL, '/');
    $success_url = $base_url . '/modules/invoices/view.php?id=' . $id . '&paid=1';
    $cancel_url  = $base_url . '/modules/invoices/view.php?id=' . $id;

    $session = stripe_create_invoice_checkout($inv, $success_url, $cancel_url, $paymentMethod);

    db_update('invoices', [
        'stripe_payment_link' => $session->url,
        'stripe_session_id'   => $session->id,
        'payment_method'      => $paymentMethod,
        'updated_at'          => date('Y-m-d H:i:s'),
    ], 'id', $id);

    log_activity('update', "Generated Stripe payment link for invoice {$inv['invoice_number']} ({$paymentMethod}, session: {$session->id})", 'invoice', $id);
    flash_success("Stripe payment link generated for invoice {$inv['invoice_number']} using " . payment_method_label($paymentMethod) . '.');
} catch (\Throwable $e) {
    flash_error('Stripe error: ' . $e->getMessage());
}

redirect('view.php?id=' . $id);
