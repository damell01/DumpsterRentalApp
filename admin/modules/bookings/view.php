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

// Load all units sharing the same booking number (multi-unit orders).
$group_bookings = db_fetchall(
    'SELECT * FROM bookings WHERE booking_number = ? ORDER BY id',
    [$booking['booking_number']]
);
if (empty($group_bookings)) {
    $group_bookings = [$booking];
}
$group_total = round(array_sum(array_column($group_bookings, 'total_amount')), 2);
$is_multi    = count($group_bookings) > 1;

$stripe_payment_url = stripe_dashboard_url($booking['stripe_payment_id'] ?? '');
$stripe_session_url = stripe_dashboard_url($booking['stripe_session_id'] ?? '');
$invoice = db_fetch(
    'SELECT * FROM invoices WHERE notes LIKE ? ORDER BY id DESC LIMIT 1',
    ['%Booking ' . ($booking['booking_number'] ?? '') . '%']
);
$customer = !empty($booking['customer_id'])
    ? db_fetch('SELECT * FROM customers WHERE id = ? LIMIT 1', [(int)$booking['customer_id']])
    : null;
$activity_rows = db_fetchall(
    'SELECT al.*, u.name AS user_name
     FROM activity_log al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.entity_type = ? AND al.entity_id = ?
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT 25',
    ['booking', $id]
);

function booking_activity_icon(string $action): string
{
    $map = [
        'create' => 'fa-plus',
        'booking_created' => 'fa-calendar-plus',
        'booking_request_submitted' => 'fa-paper-plane',
        'booking_request_emailed' => 'fa-envelope',
        'booking_request_resent' => 'fa-envelope-open-text',
        'booking_confirmation_emailed' => 'fa-circle-check',
        'booking_approved_emailed' => 'fa-circle-check',
        'booking_approval_resend' => 'fa-paper-plane',
        'invoice_resend' => 'fa-file-invoice-dollar',
        'portal_link_emailed' => 'fa-link',
        'portal_link_resend' => 'fa-link',
        'update' => 'fa-pen',
        'cancel' => 'fa-ban',
        'delete' => 'fa-trash',
    ];

    return $map[$action] ?? 'fa-clock-rotate-left';
}

layout_start('Booking Detail', 'bookings');
?>

<style>
.bk-summary-strip {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: .85rem;
    margin-bottom: 1rem;
}
.bk-summary-chip {
    background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
    border: 1px solid var(--st);
    border-radius: 14px;
    padding: .95rem 1rem;
    min-height: 92px;
}
.bk-summary-label {
    font-size: .72rem;
    color: var(--gy);
    text-transform: uppercase;
    letter-spacing: .08em;
    font-family: var(--font-cond);
}
.bk-summary-value {
    margin-top: .45rem;
    color: var(--wh);
    font-size: 1rem;
    font-weight: 700;
}
.bk-summary-value.is-money { color: var(--or); font-size: 1.2rem; }
.bk-summary-sub {
    margin-top: .35rem;
    font-size: .78rem;
    color: var(--gl);
}
.bk-action-groups {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .85rem;
    margin-bottom: 1.25rem;
}
.bk-action-card {
    background: var(--dk1);
    border: 1px solid var(--st);
    border-radius: 14px;
    padding: 1rem;
}
.bk-action-title {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: .75rem;
    font-family: var(--font-cond);
    font-size: .9rem;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    color: var(--wh);
}
.bk-action-title i { color: var(--or); }
.bk-action-list {
    display: flex;
    flex-direction: column;
    gap: .55rem;
}
.bk-action-list .btn-tp-sm {
    width: 100%;
    justify-content: center;
}
.invoice-spotlight {
    background: linear-gradient(135deg, rgba(249,115,22,.14), rgba(249,115,22,.05));
    border: 1px solid rgba(249,115,22,.26);
    border-radius: 14px;
    padding: 1rem 1.05rem;
}
.invoice-spotlight-top {
    display: flex;
    justify-content: space-between;
    align-items: start;
    gap: 1rem;
    margin-bottom: .85rem;
}
.invoice-spotlight-title {
    font-family: var(--font-cond);
    font-size: 1rem;
    font-weight: 800;
    letter-spacing: .04em;
    color: var(--wh);
    text-transform: uppercase;
}
.invoice-spotlight-sub {
    font-size: .82rem;
    color: #ffd2b0;
    margin-top: .18rem;
}
.invoice-spotlight-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .75rem;
    margin-bottom: .95rem;
}
.invoice-metric {
    background: rgba(12,14,20,.26);
    border: 1px solid rgba(255,255,255,.06);
    border-radius: 10px;
    padding: .8rem .85rem;
}
.invoice-metric-label {
    font-size: .7rem;
    color: #ffddb9;
    text-transform: uppercase;
    letter-spacing: .08em;
    font-family: var(--font-cond);
}
.invoice-metric-value {
    margin-top: .4rem;
    color: var(--wh);
    font-weight: 700;
}
.invoice-spotlight-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .55rem;
}
.invoice-spotlight-actions .btn-tp-xs,
.invoice-spotlight-actions .btn-tp-sm {
    justify-content: center;
}
@media (max-width: 991px) {
    .bk-summary-strip,
    .bk-action-groups,
    .invoice-spotlight-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 575px) {
    .bk-summary-strip,
    .bk-action-groups,
    .invoice-spotlight-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="bk-summary-strip">
    <div class="bk-summary-chip">
        <div class="bk-summary-label">Booking Status</div>
        <div class="bk-summary-value"><?= status_badge($booking['booking_status']) ?></div>
        <div class="bk-summary-sub">Updated <?= e(fmt_datetime($booking['updated_at'])) ?></div>
    </div>
    <div class="bk-summary-chip">
        <div class="bk-summary-label">Payment Status</div>
        <div class="bk-summary-value"><?= payment_badge($booking['payment_status']) ?></div>
        <div class="bk-summary-sub"><?= e(payment_method_label($booking['payment_method'] ?? '')) ?></div>
    </div>
    <div class="bk-summary-chip">
        <div class="bk-summary-label">Order Value</div>
        <div class="bk-summary-value is-money"><?= e(fmt_money($group_total)) ?></div>
        <div class="bk-summary-sub"><?= $is_multi ? count($group_bookings) . ' units in this order' : 'Single-unit booking' ?></div>
    </div>
    <div class="bk-summary-chip">
        <div class="bk-summary-label">Rental Window</div>
        <div class="bk-summary-value"><?= e(fmt_date($booking['rental_start'])) ?></div>
        <div class="bk-summary-sub">to <?= e(fmt_date($booking['rental_end'])) ?> · <?= (int)$booking['rental_days'] ?> day<?= (int)$booking['rental_days'] !== 1 ? 's' : '' ?></div>
    </div>
    <div class="bk-summary-chip">
        <div class="bk-summary-label">Invoice</div>
        <div class="bk-summary-value"><?= !empty($invoice['invoice_number']) ? e($invoice['invoice_number']) : 'Not created' ?></div>
        <div class="bk-summary-sub"><?= !empty($invoice['status']) ? 'Status: ' . e(ucfirst((string)$invoice['status'])) : 'Create on approval or from invoices' ?></div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Booking <?= e($booking['booking_number']) ?></h5>
        <small class="text-muted">Created <?= e(fmt_datetime($booking['created_at'])) ?></small>
    </div>
    <a href="index.php" class="btn-tp-ghost btn-tp-sm">
        <i class="fa-solid fa-arrow-left"></i> Back
    </a>
</div>

<div class="bk-action-groups">
    <div class="bk-action-card">
        <div class="bk-action-title"><i class="fa-solid fa-calendar-check"></i> Booking Actions</div>
        <div class="bk-action-list">
            <a href="edit.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-sm">
                <i class="fa-solid fa-pen"></i> Edit Booking
            </a>
            <?php if (has_role('admin', 'office') && ($booking['booking_status'] ?? '') === 'pending'): ?>
            <a href="approve_edit_prices.php?id=<?= $id ?>" class="btn-tp-primary btn-tp-sm w-100">
                <i class="fa-solid fa-circle-check"></i> Approve & Check Prices
            </a>
            <?php endif; ?>
            <?php if ($booking['booking_status'] !== 'canceled'): ?>
            <form method="POST" action="cancel.php">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn-tp-danger btn-tp-sm w-100"
                        data-confirm="Cancel booking <?= e($booking['booking_number']) ?>?">
                    <i class="fa-solid fa-ban"></i> Cancel Booking
                </button>
            </form>
            <?php endif; ?>
            <?php if (has_role('admin')): ?>
            <a href="delete.php?id=<?= $id ?>"
               class="btn-tp-danger btn-tp-sm"
               data-confirm="Permanently delete booking <?= e($booking['booking_number']) ?>? This cannot be undone.">
                <i class="fa-solid fa-trash"></i> Delete Booking
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="bk-action-card">
        <div class="bk-action-title"><i class="fa-solid fa-wallet"></i> Payment Actions</div>
        <div class="bk-action-list">
            <a href="update_payment.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-sm">
                <i class="fa-solid fa-credit-card"></i> Update Payment Status
            </a>
            <?php if (!empty($invoice['id'])): ?>
            <a href="<?= e(APP_URL) ?>/modules/invoices/view.php?id=<?= (int)$invoice['id'] ?>" class="btn-tp-ghost btn-tp-sm">
                <i class="fa-solid fa-file-invoice-dollar"></i> Open Invoice
            </a>
            <?php endif; ?>
            <?php if (
                $booking['payment_method'] === 'stripe' &&
                $booking['payment_status'] === 'paid' &&
                ($booking['stripe_payment_id'] || $booking['stripe_session_id'])
            ): ?>
            <a href="refund.php?id=<?= $id ?>" class="btn-tp-sm"
               style="display:flex;align-items:center;justify-content:center;gap:.45rem;color:#dc3545;border:1px solid #dc3545;border-radius:10px;padding:.55rem .75rem;text-decoration:none;">
                <i class="fa-solid fa-rotate-left"></i> Issue Refund
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="bk-action-card">
        <div class="bk-action-title"><i class="fa-solid fa-paper-plane"></i> Customer Communication</div>
        <div class="bk-action-list">
            <?php if (($booking['booking_status'] ?? '') === 'pending'): ?>
            <form method="POST" action="resend_email.php">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="redirect_to" value="view.php?id=<?= $id ?>">
                <input type="hidden" name="email_action" value="request_received">
                <button type="submit" class="btn-tp-ghost btn-tp-sm w-100" data-confirm="Resend the request received email to this customer?">
                    <i class="fa-solid fa-envelope"></i> Resend Request Received
                </button>
            </form>
            <?php endif; ?>
            <?php if (($booking['booking_status'] ?? '') !== 'pending' && !empty($booking['customer_email'])): ?>
            <form method="POST" action="resend_email.php">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="redirect_to" value="view.php?id=<?= $id ?>">
                <input type="hidden" name="email_action" value="approval">
                <button type="submit" class="btn-tp-primary btn-tp-sm w-100" data-confirm="Resend the booking approval email and attached terms PDF to this customer?">
                    <i class="fa-solid fa-circle-check"></i> Resend Approval + Terms
                </button>
            </form>
            <?php endif; ?>
            <?php if (!empty($invoice)): ?>
            <form method="POST" action="resend_email.php">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="redirect_to" value="view.php?id=<?= $id ?>">
                <input type="hidden" name="email_action" value="invoice">
                <button type="submit" class="btn-tp-ghost btn-tp-sm w-100" data-confirm="Resend invoice <?= e($invoice['invoice_number'] ?? '') ?> to this customer?">
                    <i class="fa-solid fa-file-invoice-dollar"></i> Resend Invoice Only
                </button>
            </form>
            <?php endif; ?>
            <?php if (!empty($customer)): ?>
            <form method="POST" action="resend_email.php">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="redirect_to" value="view.php?id=<?= $id ?>">
                <input type="hidden" name="email_action" value="portal_link">
                <button type="submit" class="btn-tp-ghost btn-tp-sm w-100" data-confirm="Resend the billing portal link to this customer?">
                    <i class="fa-solid fa-link"></i> Send Secure Portal Link
                </button>
            </form>
            <?php endif; ?>
        </div>
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
                <i class="fa-solid fa-dumpster me-2 text-muted"></i>
                <?= $is_multi ? count($group_bookings) . ' Units' : 'Unit Information' ?>
            </div>
            <div class="tp-card-body p-0">
                <table class="table table-sm mb-0" style="color:var(--gray-light);font-size:.875rem;">
                    <thead>
                        <tr style="border-color:var(--steel);">
                            <th style="padding:.6rem 1rem;font-weight:600;">Unit</th>
                            <th>Size</th>
                            <th>Type</th>
                            <th class="text-end" style="padding-right:1rem;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group_bookings as $gu): ?>
                        <tr style="border-color:var(--steel);">
                            <td style="padding:.6rem 1rem;" class="fw-semibold"><?= e($gu['unit_code'] ?? '—') ?></td>
                            <td><?= e($gu['unit_size'] ?? '—') ?></td>
                            <td><?= $gu['unit_type'] ? e(ucfirst($gu['unit_type'])) : '—' ?></td>
                            <td class="text-end" style="padding-right:1rem;"><?= fmt_money($gu['total_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($is_multi): ?>
                        <tr style="border-top:2px solid var(--steel);border-color:var(--steel);">
                            <td colspan="3" style="padding:.6rem 1rem;" class="fw-bold text-muted">Order Total</td>
                            <td class="text-end fw-bold" style="padding-right:1rem;color:var(--orange);"><?= fmt_money($group_total) ?></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
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
                    <?php if (!empty($booking['terms_accepted_at'])): ?>
                    <div class="col-12" style="border-top:1px solid var(--st2);padding-top:.75rem;margin-top:.25rem;">
                        <div class="text-muted" style="font-size:.8rem;">
                            <i class="fa-solid fa-file-contract me-1"></i> Terms Accepted
                        </div>
                        <div style="font-size:.85rem;">
                            <?= e(date('F j, Y', strtotime((string)$booking['terms_accepted_at']))) ?>
                            <?php if (!empty($booking['terms_accepted_ip'])): ?>
                            <span class="text-muted" style="font-size:.75rem;"> &mdash; IP: <?= e($booking['terms_accepted_ip']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Financial -->
        <div class="tp-card mb-4">
            <div class="tp-card-header">
                <i class="fa-solid fa-dollar-sign me-2 text-muted"></i> Financial
            </div>
            <div class="tp-card-body">
                <?php if (!empty($invoice)): ?>
                <div class="invoice-spotlight mb-3">
                    <div class="invoice-spotlight-top">
                        <div>
                            <div class="invoice-spotlight-title">Invoice Ready</div>
                            <div class="invoice-spotlight-sub">Use this area to review billing, resend the invoice, or open the live payment link.</div>
                        </div>
                        <div><?= status_badge($invoice['status'] ?? 'draft') ?></div>
                    </div>
                    <div class="invoice-spotlight-grid">
                        <div class="invoice-metric">
                            <div class="invoice-metric-label">Invoice Number</div>
                            <div class="invoice-metric-value"><?= e($invoice['invoice_number'] ?? '—') ?></div>
                        </div>
                        <div class="invoice-metric">
                            <div class="invoice-metric-label">Amount Due</div>
                            <div class="invoice-metric-value"><?= e(fmt_money($invoice['total'] ?? 0)) ?></div>
                        </div>
                        <div class="invoice-metric">
                            <div class="invoice-metric-label">Payment Link</div>
                            <div class="invoice-metric-value"><?= !empty($invoice['stripe_payment_link']) ? 'Ready' : 'Not generated' ?></div>
                        </div>
                    </div>
                    <div class="invoice-spotlight-actions">
                        <a href="<?= e(APP_URL) ?>/modules/invoices/view.php?id=<?= (int)$invoice['id'] ?>" class="btn-tp-primary btn-tp-xs">
                            <i class="fa-solid fa-eye"></i> Open Invoice
                        </a>
                        <?php if (!empty($invoice['stripe_payment_link'])): ?>
                        <a href="<?= e($invoice['stripe_payment_link']) ?>" target="_blank" rel="noopener noreferrer" class="btn-tp-ghost btn-tp-xs">
                            <i class="fa-solid fa-up-right-from-square"></i> Open Payment Link
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
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

<div class="tp-card mt-4">
    <div class="tp-card-header">
        <i class="fa-solid fa-clock-rotate-left me-2 text-muted"></i> Booking Activity
    </div>
    <div class="tp-card-body">
        <?php if (empty($activity_rows)): ?>
        <div class="text-muted" style="font-size:.88rem;">No booking activity has been logged yet.</div>
        <?php else: ?>
        <div class="tp-timeline">
            <?php foreach ($activity_rows as $row): ?>
            <div class="tl-item">
                <div class="tl-dot"></div>
                <div class="tl-meta">
                    <?= e(fmt_datetime($row['created_at'])) ?>
                    <?php if (!empty($row['user_name'])): ?>
                    &nbsp;&middot;&nbsp; <?= e($row['user_name']) ?>
                    <?php elseif (!empty($row['user_id'])): ?>
                    &nbsp;&middot;&nbsp; User #<?= (int)$row['user_id'] ?>
                    <?php else: ?>
                    &nbsp;&middot;&nbsp; System
                    <?php endif; ?>
                </div>
                <div class="tl-text">
                    <i class="fa-solid <?= e(booking_activity_icon((string)$row['action'])) ?>" style="color:var(--or);margin-right:.4rem;"></i>
                    <?= e($row['description'] ?: ucfirst(str_replace('_', ' ', (string)$row['action']))) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
layout_end();
