<?php
/**
 * Invoices – Generate Payment Link
 * Stores a stable pay-invoice.php URL; the actual Stripe Checkout session
 * is created lazily when the customer clicks it (see /pay-invoice.php).
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

// The actual Stripe Checkout session is created on demand when the customer
// clicks the link (see /pay-invoice.php), so it always gets a fresh 24h
// expiry window instead of expiring while the invoice sits unpaid.
db_update('invoices', [
    'stripe_payment_link' => invoice_pay_url($id),
    'payment_method'      => $paymentMethod,
    'updated_at'          => date('Y-m-d H:i:s'),
], 'id', $id);

log_activity('update', "Generated payment link for invoice {$inv['invoice_number']} ({$paymentMethod})", 'invoice', $id);
flash_success("Payment link generated for invoice {$inv['invoice_number']} using " . payment_method_label($paymentMethod) . '.');

redirect('view.php?id=' . $id);
