<?php
/**
 * Work Orders - Create a real invoice record from a work order
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INC_PATH . '/stripe.php';

require_login();
require_role('admin', 'office', 'dispatcher');
csrf_check();

$woId = (int)($_POST['wo_id'] ?? 0);
if ($woId <= 0) {
    flash_error('Invalid work order.');
    redirect('index.php');
}

$workOrder = db_fetch(
    'SELECT wo.*, c.email AS customer_email_db, c.phone AS customer_phone_db, c.billing_address AS customer_billing_address
     FROM work_orders wo
     LEFT JOIN customers c ON c.id = wo.customer_id
     WHERE wo.id = ? LIMIT 1',
    [$woId]
);

if (!$workOrder) {
    flash_error('Work order not found.');
    redirect('index.php');
}

$existingInvoice = db_fetch(
    'SELECT id, invoice_number
     FROM invoices
     WHERE work_order_id = ?
     ORDER BY id DESC
     LIMIT 1',
    [$woId]
);

if (!$existingInvoice) {
    $existingInvoice = db_fetch(
        'SELECT id, invoice_number
         FROM invoices
         WHERE notes LIKE ?
         ORDER BY id DESC
         LIMIT 1',
        ['%Work Order ' . ($workOrder['wo_number'] ?? '') . '%']
    );
}

if ($existingInvoice) {
    flash_warning('Invoice ' . $existingInvoice['invoice_number'] . ' already exists for this work order.');
    redirect('../invoices/view.php?id=' . (int)$existingInvoice['id']);
}

$subtotal = round((float)($workOrder['amount'] ?? 0), 2);
if ($subtotal <= 0) {
    flash_error('Work order amount must be greater than $0 to create an invoice.');
    redirect('view.php?id=' . $woId);
}

$paymentMethod = trim((string)($_POST['payment_method'] ?? ($workOrder['payment_method'] ?? 'stripe')));
if (!in_array($paymentMethod, ['stripe', 'ach', 'cash', 'check'], true)) {
    $paymentMethod = 'stripe';
}

$customerName = trim((string)($workOrder['cust_name'] ?? ''));
$customerEmail = trim((string)($workOrder['cust_email'] ?? $workOrder['customer_email_db'] ?? ''));
$customerPhone = trim((string)($workOrder['cust_phone'] ?? $workOrder['customer_phone_db'] ?? ''));
$customerAddress = trim((string)($workOrder['service_address'] ?? $workOrder['customer_billing_address'] ?? ''));
if (!empty($workOrder['service_city'])) {
    $customerAddress .= ($customerAddress !== '' ? ', ' : '') . trim((string)$workOrder['service_city']);
}
if (!empty($workOrder['service_state'])) {
    $customerAddress .= (!empty($workOrder['service_city']) ? ', ' : ' ') . trim((string)$workOrder['service_state']);
}
if (!empty($workOrder['service_zip'])) {
    $customerAddress .= ' ' . trim((string)$workOrder['service_zip']);
}

$invoiceNumber = next_number('INV', 'invoices', 'invoice_number');
$invoiceStatus = $customerEmail !== '' ? 'sent' : 'draft';
$notes = trim(
    'Created from Work Order ' . ($workOrder['wo_number'] ?? '') . '.'
    . (!empty($workOrder['internal_notes']) ? "\n\nWork order notes: " . trim((string)$workOrder['internal_notes']) : '')
);

$invoiceId = (int)db_insert('invoices', [
    'invoice_number' => $invoiceNumber,
    'customer_id' => !empty($workOrder['customer_id']) ? (int)$workOrder['customer_id'] : null,
    'work_order_id' => $woId,
    'cust_name' => $customerName ?: null,
    'cust_email' => $customerEmail ?: null,
    'cust_phone' => $customerPhone ?: null,
    'cust_address' => $customerAddress ?: null,
    'subtotal' => $subtotal,
    'tax_rate' => 0,
    'tax_amount' => 0,
    'total' => $subtotal,
    'notes' => $notes,
    'terms' => get_setting('invoice_terms', 'Payment is due within 30 days of invoice date. Thank you for your business!'),
    'status' => $invoiceStatus,
    'due_date' => date('Y-m-d', strtotime('+15 days')),
    'payment_method' => $paymentMethod,
    'payment_notes' => null,
    'created_by' => $_SESSION['user_id'] ?? null,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

$description = trim(
    'Work Order ' . ($workOrder['wo_number'] ?? '')
    . ' - '
    . (($workOrder['size'] ?? '') !== '' ? (string)$workOrder['size'] : 'Dumpster rental service')
    . (!empty($workOrder['project_type']) ? ' (' . trim((string)$workOrder['project_type']) . ')' : '')
);

db_insert('invoice_items', [
    'invoice_id' => $invoiceId,
    'description' => $description,
    'quantity' => 1,
    'unit_price' => $subtotal,
    'amount' => $subtotal,
    'rate_type' => 'fixed',
]);

try {
    $stripeKey = trim(get_setting('stripe_secret_key', ''));
    if ($stripeKey !== '' && str_starts_with($stripeKey, 'sk_') && in_array($paymentMethod, ['stripe', 'ach'], true)) {
        $invoiceRow = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$invoiceId]);
        if ($invoiceRow) {
            $baseUrl = rtrim(APP_URL, '/');
            $successUrl = $baseUrl . '/modules/invoices/view.php?id=' . $invoiceId . '&paid=1';
            $cancelUrl = $baseUrl . '/modules/invoices/view.php?id=' . $invoiceId;
            $session = stripe_create_invoice_checkout($invoiceRow, $successUrl, $cancelUrl, $paymentMethod);
            db_update('invoices', [
                'stripe_payment_link' => $session->url,
                'stripe_session_id' => $session->id,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id', $invoiceId);
        }
    }
} catch (\Throwable $e) {
    error_log('[Work order invoice] Stripe payment link generation failed: ' . $e->getMessage());
}

log_activity(
    'create_invoice',
    'Created invoice ' . $invoiceNumber . ' from work order ' . ($workOrder['wo_number'] ?? ''),
    'work_order',
    $woId
);

flash_success('Invoice ' . $invoiceNumber . ' created from work order successfully.');
redirect('../invoices/view.php?id=' . $invoiceId);
