<?php
/**
 * Bookings – View
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_error('Invalid booking ID.');
    redirect('index.php');
}

$booking = db_fetch('SELECT * FROM bookings WHERE id = ? LIMIT 1', [$id]);
if (!$booking) {
    flash_error('Booking not found.');
    redirect('index.php');
}

$stripe_payment_url = stripe_dashboard_url($booking['stripe_payment_id'] ?? '');
$stripe_session_url = stripe_dashboard_url($booking['stripe_session_id'] ?? '');

layout_start('Booking Detail', 'bookings');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Booking <?= e($booking['booking_number']) ?></h5>
        <small class="text-muted">Created <?= e(fmt_datetime($booking['created_at'])) ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="index.php" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
        <a href="edit.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-pen"></i> Edit
        </a>
        <a href="update_payment.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-credit-card"></i> Update Payment
        </a>
        <?php if (has_role('admin', 'office') && ($booking['booking_status'] ?? '') === 'pending'): ?>
        <form method="POST" action="approve_request.php" class="d-inline"
              onsubmit="return confirm('Approve this booking request?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="approval_action" value="approve_only">
            <button type="submit" class="btn-tp-ghost btn-tp-sm">
                <i class="fa-solid fa-check"></i> Approve Only
            </button>
        </form>
        <form method="POST" action="approve_request.php" class="d-inline"
              onsubmit="return confirm('Approve this booking request and create an invoice?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="approval_action" value="invoice">
            <button type="submit" class="btn-tp-primary btn-tp-sm">
                <i class="fa-solid fa-file-invoice-dollar"></i> Approve + Invoice
            </button>
        </form>
        <form method="POST" action="approve_request.php" class="d-inline"
              onsubmit="return confirm('Approve this booking request and create a work order?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="approval_action" value="work_order">
            <button type="submit" class="btn-tp-ghost btn-tp-sm">
                <i class="fa-solid fa-clipboard-list"></i> Approve + Work Order
            </button>
        </form>
        <?php endif; ?>
        <?php if (
            $booking['payment_method'] === 'stripe' &&
            $booking['payment_status'] === 'paid' &&
            ($booking['stripe_payment_id'] || $booking['stripe_session_id'])
        ): ?>
        <a href="refund.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-sm"
           style="color:#dc3545;border-color:#dc3545;">
            <i class="fa-solid fa-rotate-left"></i> Issue Refund
        </a>
        <?php endif; ?>
        <?php if ($booking['booking_status'] !== 'canceled'): ?>
        <form method="POST" action="cancel.php" class="d-inline"
              onsubmit="return confirm('Cancel this booking?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn-tp-danger btn-tp-sm">
                <i class="fa-solid fa-ban"></i> Cancel Booking
            </button>
        </form>
        <?php endif; ?>
        <?php if (has_role('admin')): ?>
        <a href="delete.php?id=<?= $id ?>"
           class="btn-tp-danger btn-tp-sm"
           onclick="return confirm('Permanently delete booking <?= e($booking['booking_number']) ?>? This cannot be undone.')">
            <i class="fa-solid fa-trash"></i> Delete
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">

    <!-- Left column: Customer + Unit -->
    <div class="col-12 col-lg-7">

        <!-- Customer Info -->
        <div class="tp-card mb-4">
            <div class="tp-card-header">
                <i class="fa-solid fa-user me-2 text-muted"></i> Customer Information
            </div>
            <div class="tp-card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="text-muted" style="font-size:.8rem;">Name</div>
                        <div class="fw-semibold"><?= e($booking['customer_name']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted" style="font-size:.8rem;">Phone</div>
                        <div><?= $booking['customer_phone'] ? e(fmt_phone($booking['customer_phone'])) : '<span class="text-muted">—</span>' ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted" style="font-size:.8rem;">Email</div>
                        <div><?= $booking['customer_email'] ? '<a href="mailto:' . e($booking['customer_email']) . '">' . e($booking['customer_email']) . '</a>' : '<span class="text-muted">—</span>' ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted" style="font-size:.8rem;">Address</div>
                        <div>
                            <?php if ($booking['customer_address'] || $booking['customer_city']): ?>
                                <?= e($booking['customer_address'] ?? '') ?>
                                <?php if ($booking['customer_city']): ?>, <?= e($booking['customer_city']) ?><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Unit Info -->
        <div class="tp-card mb-4">
            <div class="tp-card-header">
                <i class="fa-solid fa-dumpster me-2 text-muted"></i> Unit Information
            </div>
            <div class="tp-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="text-muted" style="font-size:.8rem;">Unit Code</div>
                        <div class="fw-semibold"><?= $booking['unit_code'] ? e($booking['unit_code']) : '<span class="text-muted">—</span>' ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted" style="font-size:.8rem;">Type</div>
                        <div><?= $booking['unit_type'] ? e(ucfirst($booking['unit_type'])) : '<span class="text-muted">—</span>' ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted" style="font-size:.8rem;">Size</div>
                        <div><?= $booking['unit_size'] ? e($booking['unit_size']) : '<span class="text-muted">—</span>' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dates -->
        <div class="tp-card mb-4">
            <div class="tp-card-header">
                <i class="fa-solid fa-calendar me-2 text-muted"></i> Rental Period
            </div>
            <div class="tp-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="text-muted" style="font-size:.8rem;">Start Date</div>
                        <div class="fw-semibold"><?= e(fmt_date($booking['rental_start'])) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted" style="font-size:.8rem;">End Date</div>
                        <div class="fw-semibold"><?= e(fmt_date($booking['rental_end'])) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted" style="font-size:.8rem;">Days</div>
                        <div class="fw-semibold"><?= (int)$booking['rental_days'] ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($booking['notes']): ?>
        <div class="tp-card">
            <div class="tp-card-header">
                <i class="fa-solid fa-note-sticky me-2 text-muted"></i> Notes
            </div>
            <div class="tp-card-body">
                <p class="mb-0" style="white-space:pre-wrap;"><?= e($booking['notes']) ?></p>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Right column: Financial + Status -->
    <div class="col-12 col-lg-5">

        <!-- Status -->
        <div class="tp-card mb-4">
            <div class="tp-card-header">
                <i class="fa-solid fa-circle-info me-2 text-muted"></i> Status
            </div>
            <div class="tp-card-body">
                <?php if (($booking['booking_status'] ?? '') === 'pending' && has_role('admin', 'office')): ?>
                <div class="alert alert-info py-2 px-3 mb-3" role="alert">
                    This request is waiting for approval. You can approve it only, approve it and invoice it, or approve it and turn it into a work order.
                </div>
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="text-muted" style="font-size:.8rem;">Booking Status</div>
                        <div class="mt-1"><?= status_badge($booking['booking_status']) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted" style="font-size:.8rem;">Payment Status</div>
                        <div class="mt-1"><?= payment_badge($booking['payment_status']) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted" style="font-size:.8rem;">Payment Method</div>
                        <div><?= e(ucfirst($booking['payment_method'])) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted" style="font-size:.8rem;">Updated</div>
                        <div><?= e(fmt_datetime($booking['updated_at'])) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial -->
        <div class="tp-card mb-4">
            <div class="tp-card-header">
                <i class="fa-solid fa-dollar-sign me-2 text-muted"></i> Financial
            </div>
            <div class="tp-card-body">
                <table class="w-100" style="font-size:.9rem;">
                    <tr>
                        <td class="text-muted py-1">Daily Rate</td>
                        <td class="text-end fw-semibold"><?= e(fmt_money($booking['daily_rate'])) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted py-1">Days</td>
                        <td class="text-end fw-semibold"><?= (int)$booking['rental_days'] ?></td>
                    </tr>
                    <tr style="border-top:1px solid var(--st);">
                        <td class="py-1 fw-bold">Total</td>
                        <td class="text-end fw-bold" style="color:var(--or);font-size:1.1rem;">
                            <?= e(fmt_money($booking['total_amount'])) ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Quick Cash / Check Actions -->
        <?php if (
            has_role('admin', 'office') &&
            !in_array($booking['payment_status'], ['paid', 'refunded'], true) &&
            $booking['booking_status'] !== 'canceled'
        ): ?>
        <div class="tp-card mb-4">
            <div class="tp-card-header">
                <i class="fa-solid fa-money-bill-wave me-2 text-muted"></i> Record Manual Payment
            </div>
            <div class="tp-card-body">
                <form method="POST" action="quick_pay.php" id="quick-pay-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" id="quick-pay-action" value="">
                    <div class="mb-2">
                        <label class="form-label" style="font-size:.82rem;">Payment Note <small class="text-muted">optional</small></label>
                        <input type="text" name="payment_notes" class="form-control form-control-sm"
                               placeholder="e.g. Paid at office, check #1042…"
                               value="<?= e($booking['payment_notes'] ?? '') ?>">
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <?php if (!in_array($booking['payment_status'], ['paid_cash'], true)): ?>
                        <button type="button" class="btn-tp-primary btn-tp-xs"
                                onclick="submitQuickPay('mark_paid_cash')">
                            <i class="fa-solid fa-check"></i> Mark Paid (Cash)
                        </button>
                        <?php endif; ?>
                        <?php if (!in_array($booking['payment_status'], ['paid_check'], true)): ?>
                        <button type="button" class="btn-tp-primary btn-tp-xs"
                                onclick="submitQuickPay('mark_paid_check')">
                            <i class="fa-solid fa-check"></i> Mark Paid (Check)
                        </button>
                        <?php endif; ?>
                        <?php if (!in_array($booking['payment_status'], ['pending_cash'], true)): ?>
                        <button type="button" class="btn-tp-ghost btn-tp-xs"
                                onclick="submitQuickPay('mark_pending_cash')">
                            <i class="fa-solid fa-clock"></i> Pending Cash
                        </button>
                        <?php endif; ?>
                        <?php if (!in_array($booking['payment_status'], ['pending_check'], true)): ?>
                        <button type="button" class="btn-tp-ghost btn-tp-xs"
                                onclick="submitQuickPay('mark_pending_check')">
                            <i class="fa-solid fa-clock"></i> Pending Check
                        </button>
                        <?php endif; ?>
                        <?php if ($booking['payment_status'] !== 'unpaid'): ?>
                        <button type="button" class="btn-tp-ghost btn-tp-xs"
                                style="color:#6c757d;border-color:#6c757d;"
                                onclick="submitQuickPay('revert_unpaid')">
                            <i class="fa-solid fa-rotate-left"></i> Revert to Unpaid
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if (!empty($booking['payment_notes'])): ?>
                <div class="mt-2 pt-2" style="border-top:1px solid var(--st2);font-size:.82rem;">
                    <span class="text-muted">Note:</span> <?= e($booking['payment_notes']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <script>
        function submitQuickPay(action) {
            document.getElementById('quick-pay-action').value = action;
            document.getElementById('quick-pay-form').submit();
        }
        </script>
        <?php elseif (!empty($booking['payment_notes'])): ?>
        <div class="tp-card mb-4">
            <div class="tp-card-header">
                <i class="fa-solid fa-note-sticky me-2 text-muted"></i> Payment Note
            </div>
            <div class="tp-card-body" style="font-size:.88rem;"><?= e($booking['payment_notes']) ?></div>
        </div>
        <?php endif; ?>

        <!-- Stripe Info -->
        <?php if ($booking['stripe_session_id'] || $booking['stripe_payment_id']): ?>
        <div class="tp-card">
            <div class="tp-card-header">
                <i class="fa-brands fa-stripe me-2 text-muted"></i> Stripe
            </div>
            <div class="tp-card-body">
                <?php if ($booking['stripe_session_id']): ?>
                <div class="mb-2">
                    <div class="text-muted" style="font-size:.8rem;">Session ID</div>
                    <div style="font-size:.8rem;word-break:break-all;"><?= e($booking['stripe_session_id']) ?></div>
                    <?php if ($stripe_session_url): ?>
                    <a href="<?= e($stripe_session_url) ?>" target="_blank" rel="noopener noreferrer"
                       class="btn-tp-ghost btn-tp-xs mt-2">
                        <i class="fa-brands fa-stripe"></i> Open Session in Stripe
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($booking['stripe_payment_id']): ?>
                <div>
                    <div class="text-muted" style="font-size:.8rem;">Payment ID</div>
                    <div style="font-size:.8rem;word-break:break-all;"><?= e($booking['stripe_payment_id']) ?></div>
                    <?php if ($stripe_payment_url): ?>
                    <a href="<?= e($stripe_payment_url) ?>" target="_blank" rel="noopener noreferrer"
                       class="btn-tp-ghost btn-tp-xs mt-2">
                        <i class="fa-brands fa-stripe"></i> Open Payment in Stripe
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($booking['payment_status'] === 'refunded'): ?>
                <div class="mt-3 p-2 rounded" style="background:#fff3cd;font-size:.85rem;">
                    <i class="fa-solid fa-rotate-left me-1 text-warning"></i>
                    <strong>Refund issued.</strong> Check your Stripe dashboard for details.
                </div>
                <?php elseif ($booking['payment_method'] === 'stripe' && $booking['payment_status'] === 'paid'): ?>
                <div class="mt-3">
                    <a href="refund.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-xs"
                       style="color:#dc3545;border-color:#dc3545;">
                        <i class="fa-solid fa-rotate-left"></i> Issue Refund
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php
layout_end();
