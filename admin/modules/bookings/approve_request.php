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
    $bookingId = (int)($booking['id'] ?? 0);

    // Prefer the reliable booking_id FK (added in upgrade 22b)
    if ($bookingId > 0) {
        $byId = db_fetch(
            'SELECT id, invoice_number FROM invoices WHERE booking_id = ? ORDER BY id DESC LIMIT 1',
            [$bookingId]
        );
        if ($byId) {
            return $byId;
        }
    }

    // Fall back to notes LIKE only when booking_number is non-empty
    $bkNum = trim((string)($booking['booking_number'] ?? ''));
    if ($bkNum === '') {
        return null;
    }

    return db_fetch(
        'SELECT id, invoice_number FROM invoices
         WHERE notes LIKE ? ORDER BY id DESC LIMIT 1',
        ['%Booking ' . $bkNum . '%']
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

    // Card processing fee
    $cardFeePct = in_array($paymentMethod, ['stripe', 'ach', 'card'], true)
        ? (float)get_setting('card_fee_percent', '0') : 0.0;
    $cardFeeAmount = round($subtotal * $cardFeePct / 100, 2);
    $invoiceTotal  = round($subtotal + $cardFeeAmount, 2);
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
            'booking_id'     => (int)$booking['id'] > 0 ? (int)$booking['id'] : null,
            'customer_id' => $customerId,
            'cust_name' => $booking['customer_name'] ?: null,
            'cust_email' => $booking['customer_email'] ?: null,
            'cust_phone' => $booking['customer_phone'] ?: null,
            'cust_address' => trim(((string)($booking['customer_address'] ?? '')) . (((string)($booking['customer_city'] ?? '')) !== '' ? ', ' . (string)$booking['customer_city'] : '')),
            'subtotal'        => $subtotal,
            'tax_rate'        => 0,
            'tax_amount'      => 0,
            'card_fee_rate'   => $cardFeePct,
            'card_fee_amount' => $cardFeeAmount,
            'total'           => $invoiceTotal,
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

    // Store a stable pay link now; the actual Stripe Checkout session is
    // created on demand when the customer clicks it (see /pay-invoice.php),
    // so it always gets a fresh 24h expiry instead of expiring in-inbox.
    $stripeKey = trim(get_setting('stripe_secret_key', ''));
    if ($stripeKey !== '' && str_starts_with($stripeKey, 'sk_')) {
        db_update('invoices', [
            'stripe_payment_link' => invoice_pay_url($invoiceId),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', $invoiceId);
    }

    $invoice = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$invoiceId]) ?: ['id' => $invoiceId, 'invoice_number' => $invoiceNumber];
    return [
        'invoice_id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'email_sent' => false,
    ];
}

function booking_ensure_invoice_payment_link(array $invoice, string $paymentMethod): array
{
    $invoiceId = (int)($invoice['id'] ?? 0);
    if ($invoiceId <= 0 || !empty($invoice['stripe_payment_link'])) {
        return $invoice;
    }

    $stripeKey = trim(get_setting('stripe_secret_key', ''));
    if ($stripeKey === '' || !str_starts_with($stripeKey, 'sk_') || (float)($invoice['total'] ?? 0) <= 0) {
        return $invoice;
    }

    $paymentMethod = trim($paymentMethod);
    if (!in_array($paymentMethod, ['stripe', 'ach', 'card'], true)) {
        $paymentMethod = 'stripe';
    }

    db_update('invoices', [
        'stripe_payment_link' => invoice_pay_url($invoiceId),
        'payment_method'      => $paymentMethod,
        'updated_at'          => date('Y-m-d H:i:s'),
    ], 'id', $invoiceId);

    $fresh = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$invoiceId]);
    return $fresh ?: $invoice;
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
    $customerRow  = !empty($booking['customer_id'])
        ? db_fetch('SELECT address, city, state, zip, billing_address, billing_city, billing_state, billing_zip FROM customers WHERE id = ? LIMIT 1', [(int)$booking['customer_id']])
        : null;

    $woNumber = next_number('WO', 'work_orders', 'wo_number');

    $workOrderId = (int)db_insert('work_orders', [
        'wo_number' => $woNumber,
        'customer_id' => !empty($booking['customer_id']) ? (int)$booking['customer_id'] : null,
        'cust_name' => $booking['customer_name'] ?: null,
        'cust_phone' => $booking['customer_phone'] ?: null,
        'cust_email' => $booking['customer_email'] ?: null,
        'service_address' => $booking['customer_address'] ?: ($customerRow['address'] ?? $customerRow['billing_address'] ?? null),
        'service_city' => $booking['customer_city'] ?: ($customerRow['city'] ?? $customerRow['billing_city'] ?? null),
        'service_state' => $customerRow['state'] ?? $customerRow['billing_state'] ?? null,
        'service_zip' => $customerRow['zip'] ?? $customerRow['billing_zip'] ?? null,
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

// Apply any edited prices from the price editing form
$edited_prices = $_POST['prices'] ?? [];
if (!empty($edited_prices) && is_array($edited_prices)) {
    foreach ($sibling_bookings as $idx => $sb) {
        if (isset($edited_prices[$idx])) {
            $newPrice = (float)$edited_prices[$idx];
            if ($newPrice > 0) {
                $sibling_bookings[$idx]['total_amount'] = round($newPrice, 2);
            }
        }
    }
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
        'total_amount'   => (float)($sb['total_amount'] ?? 0),
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
    if (!empty($invoiceRow)) {
        $invoiceRow = booking_ensure_invoice_payment_link($invoiceRow, (string)($booking['payment_method'] ?? 'stripe'));
    }
    $approvalEmailSent = false;
    if ($autoSend && !empty($booking['customer_email']) && !empty($invoiceRow)) {
        // 1. Send the formal invoice email (line items, amount due, payment link)
        try {
            $approvalEmailSent = send_invoice_email_to_customer($invoiceRow);
        } catch (\Throwable $e) {
            error_log('[Booking approval] Invoice email failed: ' . $e->getMessage());
        }

        // 2. Send the booking-confirmed notification (includes terms PDF if customer accepted)
        try {
            notify_booking_approved($booking, $invoiceRow);
        } catch (\Throwable $e) {
            error_log('[Booking approval] Booking approval notification failed: ' . $e->getMessage());
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
