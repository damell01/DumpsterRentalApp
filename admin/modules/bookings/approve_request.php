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

function create_invoice_from_booking(array $booking, bool $markSent = true): array
{
    // Load all units in this booking group (shared booking_number).
    $group = db_fetchall(
        'SELECT * FROM bookings WHERE booking_number = ? ORDER BY id',
        [$booking['booking_number']]
    );
    if (empty($group)) {
        $group = [$booking];
    }

    $subtotal = round(array_sum(array_column($group, 'total_amount')), 2);
    if ($subtotal <= 0) {
        throw new RuntimeException('Booking total must be greater than $0 to create an invoice.');
    }

    $customerId = !empty($booking['customer_id']) ? (int)$booking['customer_id'] : null;
    $paymentMethod = (string)($booking['payment_method'] ?? 'stripe');
    $invoiceStatus = ($markSent && !empty($booking['customer_email'])) ? 'sent' : 'draft';
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

        // One line item per unit in the group.
        foreach ($group as $row) {
            $lineDescription = trim(
                ((string)($row['unit_size'] ?? '') !== '' ? (string)$row['unit_size'] : 'Dumpster rental')
                . (((string)($row['unit_code'] ?? '') !== '') ? ' - ' . (string)$row['unit_code'] : '')
                . ' (' . fmt_date((string)$row['rental_start']) . ' to ' . fmt_date((string)$row['rental_end']) . ')'
            );
            $lineAmount = round((float)($row['total_amount'] ?? 0), 2);
            db_insert('invoice_items', [
                'invoice_id' => $invoiceId,
                'description' => $lineDescription,
                'quantity' => 1,
                'unit_price' => $lineAmount,
                'amount' => $lineAmount,
                'rate_type' => 'fixed',
            ]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    try {
        $stripeKey = trim(get_setting('stripe_secret_key', ''));
        if ($stripeKey !== '' && str_starts_with($stripeKey, 'sk_')) {
            $invoiceRow = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$invoiceId]);
            if ($invoiceRow) {
                $baseUrl = rtrim(APP_URL, '/');
                $successUrl = $baseUrl . '/modules/invoices/view.php?id=' . $invoiceId . '&paid=1';
                $cancelUrl = $baseUrl . '/modules/invoices/view.php?id=' . $invoiceId;
                $checkoutMethod = in_array($paymentMethod, ['stripe', 'ach'], true) ? $paymentMethod : 'stripe';
                $session = stripe_create_invoice_checkout($invoiceRow, $successUrl, $cancelUrl, $checkoutMethod);
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
    return [
        'invoice_id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'email_sent' => false,
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

    // Load all units in the booking group for the WO summary.
    $woGroup = db_fetchall(
        'SELECT * FROM bookings WHERE booking_number = ? ORDER BY id',
        [$booking['booking_number']]
    );
    if (empty($woGroup)) {
        $woGroup = [$booking];
    }
    $woTotal      = round(array_sum(array_column($woGroup, 'total_amount')), 2);
    $unitCodes    = implode(', ', array_filter(array_column($woGroup, 'unit_code')));
    $unitSizes    = implode(', ', array_unique(array_filter(array_column($woGroup, 'unit_size'))));
    $pickupDate   = !empty($booking['rental_end']) ? (string)$booking['rental_end'] : null;

    $woNumber = next_number('WO', 'work_orders', 'wo_number');

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
        'size' => $unitSizes ?: ($booking['unit_size'] ?: null),
        'project_type' => 'Dumpster Rental',
        'dumpster_id' => !empty($booking['dumpster_id']) ? (int)$booking['dumpster_id'] : null,
        'delivery_date' => $booking['rental_start'] ?: null,
        'pickup_date' => $pickupDate,
        'assigned_driver' => null,
        'amount' => $woTotal,
        'status' => 'scheduled',
        'priority' => 'normal',
        'internal_notes' => trim(
            'Created from approved booking request ' . ($booking['booking_number'] ?? '')
            . (count($woGroup) > 1 ? ' (' . count($woGroup) . ' units: ' . $unitCodes . ')' : '')
            . '.'
            . (($booking['notes'] ?? '') !== '' ? "\n\nBooking notes: " . trim((string)$booking['notes']) : '')
        ),
        'footer_notes' => get_setting('wo_footer', ''),
        'created_by' => $_SESSION['user_id'] ?? null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return [
        'work_order_id' => $workOrderId,
        'wo_number' => $woNumber,
        'already_exists' => false,
    ];
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash_error('Invalid booking ID.');
    redirect('index.php');
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

// Fetch all bookings sharing the same booking_number (multi-unit groups).
$sibling_bookings = db_fetchall(
    'SELECT * FROM bookings WHERE booking_number = ? ORDER BY id',
    [$booking['booking_number']]
);
if (empty($sibling_bookings)) {
    $sibling_bookings = [$booking];
}

$paymentMethod = (string)($booking['payment_method'] ?? 'stripe');
$paymentStatus = match ($paymentMethod) {
    'cash' => 'pending_cash',
    'check' => 'pending_check',
    default => 'unpaid',
};

// Confirm all rows in the group and mark their dumpsters reserved.
foreach ($sibling_bookings as $sb) {
    db_update('bookings', [
        'booking_status' => 'confirmed',
        'payment_status' => $paymentStatus,
        'updated_at'     => date('Y-m-d H:i:s'),
    ], 'id', (int)$sb['id']);

    if (!empty($sb['dumpster_id'])) {
        db_execute(
            "UPDATE dumpsters SET status = 'reserved', updated_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), (int)$sb['dumpster_id']]
        );
    }
}

$autoSend = get_setting('approval_auto_send_invoice', '1') === '1';

try {
    // Always create a work order for the booking group.
    $workOrder = create_work_order_from_booking($booking);

    // Always create an invoice (one per booking group, with one line per unit).
    $existingInvoice = booking_existing_invoice($booking);
    if ($existingInvoice) {
        $invoice = ['invoice_id' => (int)$existingInvoice['id'], 'invoice_number' => $existingInvoice['invoice_number'], 'email_sent' => false];
    } else {
        $invoice = create_invoice_from_booking($booking, $autoSend);
    }

    $invoiceRow = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [(int)$invoice['invoice_id']]) ?: [];
    $approvalEmailSent = false;
    if ($autoSend && !empty($booking['customer_email']) && !empty($invoiceRow)) {
        try {
            $approvalEmailSent = notify_booking_approved($booking, $invoiceRow);
        } catch (\Throwable $e) {
            error_log('[Booking approval] Approval email failed: ' . $e->getMessage());
        }
    }

    log_activity(
        'approve_booking_request',
        'Approved booking request ' . ($booking['booking_number'] ?? '')
            . ' — WO ' . $workOrder['wo_number']
            . ', Invoice ' . $invoice['invoice_number'],
        'booking',
        $id
    );

    $msgs = [];
    $msgs[] = 'Work order ' . $workOrder['wo_number'] . ($workOrder['already_exists'] ? ' (already existed)' : ' created') . '.';
    if (!empty($existingInvoice)) {
        $msgs[] = 'Invoice ' . $invoice['invoice_number'] . ' already existed.';
    } elseif ($approvalEmailSent) {
        $msgs[] = 'Invoice ' . $invoice['invoice_number'] . ' created and approval email sent to customer.';
    } else {
        $msgs[] = 'Invoice ' . $invoice['invoice_number'] . ' created as draft — send manually when ready.';
    }
    flash_success('Booking approved. ' . implode(' ', $msgs));
    redirect('../invoices/view.php?id=' . $invoice['invoice_id']);
} catch (\Throwable $e) {
    // Roll back all rows in the group.
    foreach ($sibling_bookings as $sb) {
        db_update('bookings', [
            'booking_status' => 'pending',
            'payment_status' => in_array($paymentMethod, ['cash', 'check'], true)
                ? ($paymentMethod === 'cash' ? 'pending_cash' : 'pending_check')
                : 'unpaid',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', (int)$sb['id']);
        if (!empty($sb['dumpster_id'])) {
            db_execute(
                "UPDATE dumpsters SET status = 'available', updated_at = ? WHERE id = ? AND status = 'reserved'",
                [date('Y-m-d H:i:s'), (int)$sb['dumpster_id']]
            );
        }
    }

    flash_error('Could not complete approval action: ' . $e->getMessage());
    redirect(booking_back_url($id));
}
