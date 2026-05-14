<?php
/**
 * Invoices – Send Invoice Email to Customer
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_role('admin', 'office');
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$send_to = trim((string)($_POST['send_to'] ?? ''));
$include_link = !empty($_POST['include_payment_link']);

if ($id <= 0) {
    flash_error('Invalid invoice ID.');
    redirect('index.php');
}

if ($send_to === '' || !filter_var($send_to, FILTER_VALIDATE_EMAIL)) {
    flash_error('A valid email address is required to send the invoice.');
    redirect('view.php?id=' . $id);
}

$inv = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$id]);
if (!$inv) {
    flash_error('Invoice not found.');
    redirect('index.php');
}

// Allow overriding the recipient without changing the stored cust_email
$inv_send = $inv;
$inv_send['cust_email'] = $send_to;
if (!$include_link) {
    $inv_send['stripe_payment_link'] = '';
}

$sent = send_invoice_email_to_customer($inv_send);

if ($sent) {
    // Auto-advance draft → sent
    if ($inv['status'] === 'draft') {
        db_update('invoices', [
            'status'     => 'sent',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', $id);
    }
    log_activity('update', "Invoice {$inv['invoice_number']} emailed to {$send_to}", 'invoice', $id);
    flash_success("Invoice {$inv['invoice_number']} sent to {$send_to}.");
} else {
    flash_error('Failed to send the invoice email. Check your SMTP settings in Settings → Email.');
}

redirect('view.php?id=' . $id);
