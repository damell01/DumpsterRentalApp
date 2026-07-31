<?php
/**
 * Bookings – Issue Refund (GET = confirmation form, POST = process refund)
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_once INC_PATH . '/stripe.php';
require_login();
require_role('admin', 'office');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    flash_error('Invalid booking ID.');
    redirect('index.php');
}

$booking = db_fetch('SELECT * FROM bookings WHERE id = ? LIMIT 1', [$id]);
if (!$booking) {
    flash_error('Booking not found.');
    redirect('index.php');
}

// Must be a Stripe payment that has been paid
if ($booking['payment_method'] !== 'stripe') {
    flash_error('Refunds via this form only apply to Stripe payments.');
    redirect('view.php?id=' . $id);
}
if (!in_array($booking['payment_status'], ['paid', 'refunded'], true)) {
    flash_error('Only paid bookings can be refunded.');
    redirect('view.php?id=' . $id);
}

$payment_id = trim($booking['stripe_payment_id'] ?? $booking['stripe_session_id'] ?? '');
if ($payment_id === '') {
    flash_error('No Stripe payment ID found for this booking. Please issue the refund directly in Stripe.');
    redirect('view.php?id=' . $id);
}

// ── POST: process refund ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $refund_type   = $_POST['refund_type'] ?? 'full';
    $custom_amount = trim($_POST['custom_amount'] ?? '');
    $reason        = $_POST['reason'] ?? 'requested_by_customer';

    $allowed_reasons = ['requested_by_customer', 'duplicate', 'fraudulent'];
    if (!in_array($reason, $allowed_reasons, true)) {
        $reason = 'requested_by_customer';
    }

    $amount_cents = null;
    if ($refund_type === 'partial') {
        $parsed = (float)str_replace(['$', ','], '', $custom_amount);
        if ($parsed <= 0) {
            flash_error('Please enter a valid refund amount.');
            redirect('refund.php?id=' . $id);
        }
        $max = (float)$booking['total_amount'];
        if ($parsed > $max) {
            flash_error('Refund amount cannot exceed the booking total of ' . fmt_money($max) . '.');
            redirect('refund.php?id=' . $id);
        }
        $amount_cents = (int)round($parsed * 100);
    }

    try {
        // If the payment_id is a Checkout Session, retrieve the underlying Payment Intent
        $pid = $payment_id;
        if (str_starts_with($pid, 'cs_')) {
            $session = stripe_client()->checkout->sessions->retrieve($pid);
            $pid     = $session->payment_intent ?? '';
            if (empty($pid)) {
                throw new \RuntimeException('Could not resolve Payment Intent from Checkout Session.');
            }
        }

        $refund = stripe_issue_refund($pid, $amount_cents, $reason);

        $refunded_amount_num = $amount_cents !== null ? $amount_cents / 100 : (float)$booking['total_amount'];
        $is_full_refund = $amount_cents === null || $refunded_amount_num >= (float)$booking['total_amount'];

        // payment_status has no "partially refunded" state — only flip it to
        // 'refunded' when the full amount was returned, so a partial refund
        // doesn't misrepresent the booking as fully unpaid/refunded.
        if ($is_full_refund) {
            db_update('bookings', [
                'payment_status' => 'refunded',
                'updated_at'     => date('Y-m-d H:i:s'),
            ], 'id', $id);
        }

        $refunded_amount = fmt_money($refunded_amount_num);

        log_activity(
            'refund',
            "Issued {$refunded_amount} refund for booking {$booking['booking_number']}"
                . ($is_full_refund ? '' : ' (partial — booking remains marked paid)')
                . " (Stripe refund {$refund->id})",
            'booking',
            $id
        );

        flash_success("Refund of {$refunded_amount} issued successfully. Stripe refund ID: " . e($refund->id));
        redirect('view.php?id=' . $id);

    } catch (\Stripe\Exception\ApiErrorException $e) {
        flash_error('Stripe error: ' . $e->getMessage());
        redirect('refund.php?id=' . $id);
    } catch (\Throwable $e) {
        flash_error('Refund failed: ' . $e->getMessage());
        redirect('refund.php?id=' . $id);
    }
}

// ── GET: show confirmation form ───────────────────────────────────────────────
layout_start('Issue Refund', 'bookings');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Issue Refund — Booking <?= e($booking['booking_number']) ?></h5>
        <small class="text-muted"><?= e($booking['customer_name']) ?></small>
    </div>
    <a href="view.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-sm">
        <i class="fa-solid fa-arrow-left"></i> Back to Booking
    </a>
</div>

<?php render_flash(); ?>

<div class="row g-4">
    <div class="col-12 col-lg-6">

        <!-- Booking Summary -->
        <div class="tp-card mb-4">
            <div class="tp-card-header">
                <i class="fa-solid fa-dollar-sign me-2 text-muted"></i> Payment Summary
            </div>
            <div class="tp-card-body">
                <table class="w-100" style="font-size:.9rem;">
                    <tr>
                        <td class="text-muted py-1">Booking</td>
                        <td class="text-end fw-semibold"><?= e($booking['booking_number']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted py-1">Customer</td>
                        <td class="text-end"><?= e($booking['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted py-1">Payment Status</td>
                        <td class="text-end"><?= payment_badge($booking['payment_status']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted py-1">Payment Method</td>
                        <td class="text-end">Stripe</td>
                    </tr>
                    <tr style="border-top:1px solid var(--st);">
                        <td class="py-1 fw-bold">Charged Amount</td>
                        <td class="text-end fw-bold" style="color:var(--or);font-size:1.1rem;">
                            <?= e(fmt_money($booking['total_amount'])) ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Refund Form -->
        <div class="tp-card">
            <div class="tp-card-header">
                <i class="fa-solid fa-rotate-left me-2 text-muted"></i> Refund Options
            </div>
            <div class="tp-card-body">
                <form method="POST" action="refund.php" onsubmit="return confirmRefund(this);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.9rem;">Refund Amount</label>
                        <div class="d-flex flex-column gap-2">
                            <label style="font-size:.9rem;">
                                <input type="radio" name="refund_type" value="full" id="rt_full" checked
                                       onchange="toggleCustomAmount(false)">
                                &nbsp;Full refund — <?= e(fmt_money($booking['total_amount'])) ?>
                            </label>
                            <label style="font-size:.9rem;">
                                <input type="radio" name="refund_type" value="partial" id="rt_partial"
                                       onchange="toggleCustomAmount(true)">
                                &nbsp;Partial refund
                            </label>
                        </div>
                    </div>

                    <div class="mb-3" id="custom_amount_wrap" style="display:none;">
                        <label class="form-label" style="font-size:.85rem;" for="custom_amount">
                            Amount to refund (max <?= e(fmt_money($booking['total_amount'])) ?>)
                        </label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">$</span>
                            <input type="number" name="custom_amount" id="custom_amount"
                                   class="form-control"
                                   min="0.01" max="<?= e($booking['total_amount']) ?>" step="0.01"
                                   placeholder="0.00">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" style="font-size:.9rem;" for="reason">Reason</label>
                        <select name="reason" id="reason" class="form-select form-select-sm">
                            <option value="requested_by_customer">Requested by Customer</option>
                            <option value="duplicate">Duplicate Charge</option>
                            <option value="fraudulent">Fraudulent</option>
                        </select>
                    </div>

                    <div class="alert alert-warning" style="font-size:.85rem;">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>
                        Refunds are processed immediately through Stripe and <strong>cannot be reversed</strong>.
                        Funds typically appear on the customer's statement within 5–10 business days.
                    </div>

                    <button type="submit" class="btn-tp-danger">
                        <i class="fa-solid fa-rotate-left me-1"></i> Issue Refund
                    </button>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
function toggleCustomAmount(show) {
    document.getElementById('custom_amount_wrap').style.display = show ? '' : 'none';
    const inp = document.getElementById('custom_amount');
    inp.required = show;
    if (!show) inp.value = '';
}

function confirmRefund(form) {
    const isPartial = document.getElementById('rt_partial').checked;
    const amount    = isPartial
        ? '$' + parseFloat(document.getElementById('custom_amount').value || 0).toFixed(2)
        : '<?= e(fmt_money($booking['total_amount'])) ?>';
    return confirm('Issue a ' + amount + ' refund to ' + <?= json_encode($booking['customer_name']) ?> + '?\n\nThis action cannot be reversed.');
}
</script>

<?php
layout_end();
