<?php
/**
 * Bookings - Approve pending request with flexible next step
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INC_PATH . '/stripe.php';
require_once INC_PATH . '/mailer.php';

require_login();
require_role('admin', 'office');
csrf_check();

function booking_back_url(int $bookingId): string
{
    $redirectTo = trim((string)($_POST['redirect_to'] ?? ''));
    if ($redirectTo !== '') {
        return $redirectTo;
    }
    return 'view.php?id=' . $bookingId;
}

function booking_invoice_notes(array $booking): string
{
    return trim(
        'Booking ' . ($booking['booking_number'] ?? '') . ' approved from request flow.'
        . (($booking['notes'] ?? '') !== '' ? "\n\nBooking notes: " . trim((string)$booking['notes']) : '')
    );
}

function booking_existing_invoice(array $booking): ?array
{
    return db_fetch(
        'SELECT i.id, i.invoice_number
         FROM invoices i
         WHERE i.notes LIKE ?
         ORDER BY i.id DESC
         LIMIT 1',
        ['%Booking ' . ($booking['booking_number'] ?? '') . '%']
    ) ?: null;
}

function booking_existing_work_order(array $booking): ?array
{
    return db_fetch(
        'SELECT wo.id, wo.wo_number
         FROM work_orders wo
         WHERE wo.internal_notes LIKE ?
         ORDER BY wo.id DESC
         LIMIT 1',
        ['%Booking ' . ($booking['booking_number'] ?? '') . '%']
    ) ?: null;
}

function create_invoice_from_booking(array $booking): array
{
    $subtotal = round((float)($booking['total_amount'] ?? 0), 2);
    if ($subtotal <= 0) {
        throw new RuntimeException('Booking total must be greater than $0 to create an invoice.');
    }

    $customerId = !empty($booking['customer_id']) ? (int)$booking['customer_id'] : null;
    $paymentMethod = (string)($booking['payment_method'] ?? 'stripe');
    $invoiceStatus = !empty($booking['customer_email']) ? 'sent' : 'draft';
    $invoiceId = 0;
    $invoiceNumber = '';

    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $invoiceNumber = next_number('INV', 'invoices', 'invoice_number');
        $terms = get_setting('invoice_terms', 'Payment is due within 30 days of invoice date. Thank you for your business!');
        $dueDate = date('Y-m-d', strtotime('+15 days'));

        $invoiceId = (int)db_insert('invoices', [
            'invoice_number' => $invoiceNumber,
            'customer_id' => $customerId,
            'cust_name' => $booking['customer_name'] ?: null,
            'cust_email' => $booking['customer_email'] ?: null,
            'cust_phone' => $booking['customer_phone'] ?: null,
            'cust_address' => trim(((string)($booking['customer_address'] ?? '')) . (((string)($booking['customer_city'] ?? '')) !== '' ? ', ' . (string)$booking['customer_city'] : '')),
            'subtotal' => $subtotal,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => $subtotal,
            'notes' => booking_invoice_notes($booking),
            'terms' => $terms,
            'status' => $invoiceStatus,
            'due_date' => $dueDate,
            'payment_method' => in_array($paymentMethod, ['ach', 'stripe', 'cash', 'check'], true) ? $paymentMethod : 'stripe',
            'payment_notes' => null,
            'created_by' => $_SESSION['user_id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $lineDescription = trim(
            ((string)($booking['unit_size'] ?? '') !== '' ? (string)$booking['unit_size'] : 'Dumpster rental')
            . (((string)($booking['unit_code'] ?? '') !== '') ? ' - ' . (string)$booking['unit_code'] : '')
            . ' (' . fmt_date((string)$booking['rental_start']) . ' to ' . fmt_date((string)$booking['rental_end']) . ')'
        );

        db_insert('invoice_items', [
            'invoice_id' => $invoiceId,
            'description' => $lineDescription,
            'quantity' => 1,
            'unit_price' => $subtotal,
            'amount' => $subtotal,
            'rate_type' => 'fixed',
        ]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

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
        error_log('[Booking approval] Stripe payment link generation failed: ' . $e->getMessage());
    }

    $invoice = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$invoiceId]) ?: ['id' => $invoiceId, 'invoice_number' => $invoiceNumber];
    $emailSent = false;
    if (!empty($invoice['cust_email'])) {
        try {
            $emailSent = send_invoice_email_to_customer($invoice);
        } catch (\Throwable $e) {
            error_log('[Booking approval] Invoice email failed: ' . $e->getMessage());
        }
    }

    return [
        'invoice_id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'email_sent' => $emailSent,
    ];
}

function create_work_order_from_booking(array $booking): array
{
    $existing = booking_existing_work_order($booking);
    if ($existing) {
        return [
            'work_order_id' => (int)$existing['id'],
            'wo_number' => (string)$existing['wo_number'],
            'already_exists' => true,
        ];
    }

    $woNumber = next_number('WO', 'work_orders', 'wo_number');
    $pickupDate = !empty($booking['rental_end']) ? (string)$booking['rental_end'] : null;

    $workOrderId = (int)db_insert('work_orders', [
        'wo_number' => $woNumber,
        'customer_id' => !empty($booking['customer_id']) ? (int)$booking['customer_id'] : null,
        'cust_name' => $booking['customer_name'] ?: null,
        'cust_phone' => $booking['customer_phone'] ?: null,
        'cust_email' => $booking['customer_email'] ?: null,
        'service_address' => $booking['customer_address'] ?: null,
        'service_city' => $booking['customer_city'] ?: null,
        'service_state' => null,
        'service_zip' => null,
        'size' => $booking['unit_size'] ?: null,
        'project_type' => 'Dumpster Rental',
        'dumpster_id' => !empty($booking['dumpster_id']) ? (int)$booking['dumpster_id'] : null,
        'delivery_date' => $booking['rental_start'] ?: null,
        'pickup_date' => $pickupDate,
        'assigned_driver' => null,
        'amount' => (float)($booking['total_amount'] ?? 0),
        'status' => 'scheduled',
        'priority' => 'normal',
        'internal_notes' => trim(
            'Created from approved booking request ' . ($booking['booking_number'] ?? '') . '.'
            . (($booking['notes'] ?? '') !== '' ? "\n\nBooking notes: " . trim((string)$booking['notes']) : '')
        ),
        'footer_notes' => get_setting('wo_footer', ''),
        'created_by' => $_SESSION['user_id'] ?? null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    if (!empty($booking['dumpster_id'])) {
        db_execute(
            "UPDATE dumpsters SET status = 'reserved', updated_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), (int)$booking['dumpster_id']]
        );
    }

    return [
        'work_order_id' => $workOrderId,
        'wo_number' => $woNumber,
        'already_exists' => false,
    ];
}

$id = (int)($_POST['id'] ?? 0);
$action = trim((string)($_POST['approval_action'] ?? 'invoice'));
if ($id <= 0) {
    flash_error('Invalid booking ID.');
    redirect('index.php');
}

$allowedActions = ['approve_only', 'invoice', 'work_order'];
if (!in_array($action, $allowedActions, true)) {
    $action = 'invoice';
}

$booking = db_fetch('SELECT * FROM bookings WHERE id = ? LIMIT 1', [$id]);
if (!$booking) {
    flash_error('Booking not found.');
    redirect('index.php');
}

if (($booking['booking_status'] ?? '') !== 'pending') {
    flash_error('Only pending booking requests can be approved from this action.');
    redirect(booking_back_url($id));
}

$paymentMethod = (string)($booking['payment_method'] ?? 'stripe');
$paymentStatus = match ($paymentMethod) {
    'cash' => 'pending_cash',
    'check' => 'pending_check',
    default => 'unpaid',
};

db_update('bookings', [
    'booking_status' => 'confirmed',
    'payment_status' => $paymentStatus,
    'updated_at' => date('Y-m-d H:i:s'),
], 'id', $id);

try {
    if ($action === 'approve_only') {
        log_activity(
            'approve_booking_request',
            'Approved booking request ' . ($booking['booking_number'] ?? '') . ' without creating follow-up records',
            'booking',
            $id
        );
        flash_success('Booking request approved.');
        redirect('view.php?id=' . $id);
    }

    if ($action === 'work_order') {
        $workOrder = create_work_order_from_booking($booking);
        log_activity(
            'approve_booking_request',
            'Approved booking request ' . ($booking['booking_number'] ?? '') . ' and created work order ' . $workOrder['wo_number'],
            'booking',
            $id
        );

        if (!empty($workOrder['already_exists'])) {
            flash_warning('Booking approved. Work order ' . $workOrder['wo_number'] . ' already existed for this request.');
        } else {
            flash_success('Booking approved. Work order ' . $workOrder['wo_number'] . ' created successfully.');
        }
        redirect('../work_orders/view.php?id=' . $workOrder['work_order_id']);
    }

    $existingInvoice = booking_existing_invoice($booking);
    if ($existingInvoice) {
        flash_warning('Booking approved. Invoice ' . $existingInvoice['invoice_number'] . ' already existed for this request.');
        redirect('../invoices/view.php?id=' . (int)$existingInvoice['id']);
    }

    $invoice = create_invoice_from_booking($booking);
    log_activity(
        'approve_booking_request',
        'Approved booking request ' . ($booking['booking_number'] ?? '') . ' and created invoice ' . $invoice['invoice_number'],
        'booking',
        $id
    );

    if (!empty($invoice['email_sent'])) {
        flash_success('Booking approved. Invoice ' . $invoice['invoice_number'] . ' was created and emailed to the customer.');
    } else {
        flash_success('Booking approved. Invoice ' . $invoice['invoice_number'] . ' is ready for review and sending.');
    }
    redirect('../invoices/view.php?id=' . $invoice['invoice_id']);
} catch (\Throwable $e) {
    db_update('bookings', [
        'booking_status' => 'pending',
        'payment_status' => in_array($paymentMethod, ['cash', 'check'], true)
            ? ($paymentMethod === 'cash' ? 'pending_cash' : 'pending_check')
            : 'unpaid',
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id', $id);

    flash_error('Could not complete approval action: ' . $e->getMessage());
    redirect(booking_back_url($id));
}
