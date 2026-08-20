<?php
/**
 * Sync work order amounts to invoices
 * Helps keep invoice totals in sync when work order prices are updated
 */

/**
 * Find invoice(s) related to a booking/work order
 * Looks for invoices created from the same booking
 */
function find_related_invoice($booking_number) {
    if (empty($booking_number)) {
        return null;
    }
    return db_fetch(
        'SELECT * FROM invoices
         WHERE notes LIKE ? OR booking_id IN (
            SELECT id FROM bookings WHERE booking_number = ?
         )
         ORDER BY id DESC LIMIT 1',
        ['%Booking ' . $booking_number . '%', $booking_number]
    ) ?: null;
}

/**
 * Find invoice related to a work order
 * Looks for invoices created from the same booking
 */
function find_invoice_from_work_order($wo_id) {
    $wo = db_fetch('SELECT internal_notes FROM work_orders WHERE id = ? LIMIT 1', [$wo_id]);
    if (!$wo) {
        return null;
    }

    // Extract booking number from internal_notes
    if (preg_match('/Booking\s+([A-Z0-9\-]+)/i', $wo['internal_notes'], $matches)) {
        $booking_number = $matches[1];
        return find_related_invoice($booking_number);
    }

    return null;
}

/**
 * Sync work order amount to invoice
 * Updates invoice line items and totals when work order amount changes
 */
function sync_work_order_to_invoice($wo_id, $new_amount = null) {
    $wo = db_fetch('SELECT * FROM work_orders WHERE id = ? LIMIT 1', [$wo_id]);
    if (!$wo) {
        return false;
    }

    $invoice = find_invoice_from_work_order($wo_id);
    if (!$invoice) {
        return false;
    }

    $amount = $new_amount !== null ? (float)$new_amount : (float)($wo['amount'] ?? 0);
    if ($amount <= 0) {
        return false;
    }

    // Update invoice items - update the first/main line item with the new amount
    $items = db_fetchall(
        'SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id',
        [(int)$invoice['id']]
    );

    if (empty($items)) {
        return false;
    }

    // Update the main line item (typically the dumpster rental)
    $main_item = $items[0];
    $new_subtotal = $amount;

    // Calculate card fee if applicable
    $card_fee_pct = in_array((string)($invoice['payment_method'] ?? ''), ['stripe', 'ach', 'card'], true)
        ? (float)get_setting('card_fee_percent', '0') : 0.0;
    $card_fee_amount = round($new_subtotal * $card_fee_pct / 100, 2);
    $new_total = round($new_subtotal + $card_fee_amount, 2);

    // Update the invoice
    db_update('invoices', [
        'subtotal'        => $new_subtotal,
        'card_fee_amount' => $card_fee_amount,
        'total'           => $new_total,
        'updated_at'      => date('Y-m-d H:i:s'),
    ], 'id', (int)$invoice['id']);

    // Update the main line item
    db_update('invoice_items', [
        'amount'     => $amount,
        'unit_price' => $amount,
    ], 'id', (int)$main_item['id']);

    // Regenerate Stripe payment link if needed
    if (!empty($invoice['stripe_payment_link']) && $new_total > 0) {
        $stripe_key = trim(get_setting('stripe_secret_key', ''));
        if ($stripe_key !== '' && str_starts_with($stripe_key, 'sk_')) {
            try {
                require_once dirname(__FILE__) . '/stripe.php';
                $new_link = invoice_pay_url((int)$invoice['id']);
                db_update('invoices', [
                    'stripe_payment_link' => $new_link,
                    'updated_at'          => date('Y-m-d H:i:s'),
                ], 'id', (int)$invoice['id']);
            } catch (\Throwable $e) {
                error_log('[Sync] Failed to regenerate Stripe link: ' . $e->getMessage());
            }
        }
    }

    return true;
}
