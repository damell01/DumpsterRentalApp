<?php
/**
 * Invoices – Update Status (quick action)
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_role('admin', 'office');
csrf_check();

$id     = (int)($_POST['id']     ?? 0);
$status = trim($_POST['status']  ?? '');

if ($id <= 0 || !in_array($status, ['draft', 'sent', 'paid', 'void', 'canceled'], true)) {
    flash_error('Invalid request.');
    redirect('index.php');
}

$inv = db_fetch('SELECT id, invoice_number FROM invoices WHERE id = ? LIMIT 1', [$id]);
if (!$inv) { flash_error('Invoice not found.'); redirect('index.php'); }

$updates = [
    'status'     => $status,
    'updated_at' => date('Y-m-d H:i:s'),
];
if ($status === 'paid') {
    $updates['paid_at'] = date('Y-m-d H:i:s');
}

db_update('invoices', $updates, 'id', $id);

log_activity('update_invoice_status', 'Invoice ' . $inv['invoice_number'] . ' → ' . $status, 'invoice', $id);
flash_success('Invoice ' . $inv['invoice_number'] . ' marked as ' . ucfirst($status) . '.');
redirect('view.php?id=' . $id);
