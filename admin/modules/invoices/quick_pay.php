<?php
/**
 * Invoices – Quick Pay (POST-only)
 * Lets admin record cash/check payments for invoices quickly.
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_role('admin', 'office');
csrf_check();

$id     = (int)($_POST['id'] ?? 0);
$action = trim($_POST['action'] ?? '');

if ($id <= 0) {
    flash_error('Invalid invoice ID.');
    redirect('index.php');
}

$inv = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$id]);
if (!$inv) {
    flash_error('Invoice not found.');
    redirect('index.php');
}

$payment_notes = trim($_POST['payment_notes'] ?? '');

$allowed = [
    'mark_paid_cash'  => ['status' => 'paid',  'payment_method' => 'cash'],
    'mark_paid_check' => ['status' => 'paid',  'payment_method' => 'check'],
    'mark_sent'       => ['status' => 'sent',  'payment_method' => null],
    'mark_draft'      => ['status' => 'draft', 'payment_method' => null],
    'mark_canceled'   => ['status' => 'canceled', 'payment_method' => null],
];

if (!array_key_exists($action, $allowed)) {
    flash_error('Invalid action.');
    redirect('view.php?id=' . $id);
}

$update = [
    'status'     => $allowed[$action]['status'],
    'updated_at' => date('Y-m-d H:i:s'),
];

if ($allowed[$action]['payment_method'] !== null) {
    $update['payment_method'] = $allowed[$action]['payment_method'];
}

if ($allowed[$action]['status'] === 'paid') {
    $update['paid_at'] = date('Y-m-d H:i:s');
}

if ($payment_notes !== '') {
    $update['payment_notes'] = $payment_notes;
}

db_update('invoices', $update, 'id', $id);

$label = ucfirst($allowed[$action]['status']);
if ($allowed[$action]['payment_method']) {
    $label .= ' via ' . ucfirst($allowed[$action]['payment_method']);
}

log_activity('update', "Invoice {$inv['invoice_number']} quick status → {$allowed[$action]['status']}", 'invoice', $id);
flash_success("Invoice {$inv['invoice_number']} updated: $label.");
redirect('view.php?id=' . $id);
