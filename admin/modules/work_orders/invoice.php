<?php
/**
 * Work Orders – Printable Invoice / Work Order Document
 * Trash Panda Roll-Offs
 *
 * Generates a printable invoice document for a work order.
 * Payments are handled outside the system by the business.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();

$wo_id = (int)($_GET['wo_id'] ?? 0);
if (!$wo_id) {
    flash_error('Invalid work order.');
    redirect(APP_URL . '/modules/work_orders/index.php');
}

$wo = db_fetch(
    'SELECT wo.*,
            c.name AS cust_name_db, c.email AS cust_email_db,
            c.address AS cust_address, c.city AS cust_city, c.state AS cust_state, c.zip AS cust_zip,
            c.phone AS cust_phone_db
     FROM work_orders wo
     LEFT JOIN customers c ON wo.customer_id = c.id
     WHERE wo.id = ? LIMIT 1',
    [$wo_id]
);

if (!$wo) {
    flash_error('Work order not found.');
    redirect(APP_URL . '/modules/work_orders/index.php');
}

$linked_invoice = db_fetch(
    'SELECT id, invoice_number, status
     FROM invoices
     WHERE work_order_id = ?
     ORDER BY id DESC
     LIMIT 1',
    [$wo_id]
);

if (!$linked_invoice) {
    $linked_invoice = db_fetch(
        'SELECT id, invoice_number, status
         FROM invoices
         WHERE notes LIKE ?
         ORDER BY id DESC
         LIMIT 1',
        ['%Work Order ' . ($wo['wo_number'] ?? '') . '%']
    );
}

// ── Company settings ──────────────────────────────────────────────────────────
$company_name    = get_setting('company_name',    'Trash Panda Roll-Offs');
$company_phone   = get_setting('company_phone',   '');
$company_email   = get_setting('company_email',   '');
$company_address = get_setting('company_address', '');
$tax_rate        = (float)get_setting('tax_rate', '0');

// Derived values
$cust_name  = $wo['cust_name']  ?: ($wo['cust_name_db']  ?? '—');
$cust_email = $wo['cust_email'] ?: ($wo['cust_email_db'] ?? '—');
$cust_phone = $wo['cust_phone'] ?: ($wo['cust_phone_db'] ?? '');

$subtotal   = (float)($wo['amount'] ?? 0);
$tax_amount = $tax_rate > 0 ? round($subtotal * $tax_rate / 100, 2) : 0;
$total      = $subtotal + $tax_amount;

$is_print = isset($_GET['print']);

if ($is_print):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice — WO# <?= e($wo['wo_number']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        @media print { .no-print { display: none !important; } body { margin: 0; } }
        body { font-family: Arial, sans-serif; color: #1f2937; }
        .inv-header { background: #0f2a44; color: #fff; padding: 24px 32px; margin-bottom: 24px; }
        .inv-header h1 { color: #f97316; font-size: 1.5rem; font-weight: 700; margin: 0; }
    </style>
</head>
<body>
<div class="no-print text-center mt-3 mb-2">
    <button onclick="window.print()" class="btn btn-sm btn-secondary">🖨 Print</button>
    <a href="invoice.php?wo_id=<?= $wo_id ?>" class="btn btn-sm btn-outline-dark ms-2">← Back</a>
</div>
<?php else: ?>
<?php layout_start('Invoice — WO# ' . e($wo['wo_number']), 'work_orders'); ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Invoice — WO# <?= e($wo['wo_number']) ?></h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="view.php?id=<?= $wo_id ?>" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-arrow-left"></i> Back to Work Order
        </a>
        <?php if ($linked_invoice): ?>
        <a href="<?= e(APP_URL) ?>/modules/invoices/view.php?id=<?= (int)$linked_invoice['id'] ?>" class="btn-tp-primary btn-tp-sm">
            <i class="fa-solid fa-file-invoice-dollar"></i> View Real Invoice
        </a>
        <?php endif; ?>
        <a href="invoice.php?wo_id=<?= $wo_id ?>&print=1" class="btn-tp-ghost btn-tp-sm" target="_blank">
            <i class="fa-solid fa-print"></i> Print
        </a>
    </div>
</div>
<?php if ($linked_invoice): ?>
<div class="alert alert-info py-2 px-3">
    A real invoice already exists for this work order as <strong><?= e($linked_invoice['invoice_number']) ?></strong>. This page is the printable standalone document version.
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Invoice document -->
<div style="max-width:780px;background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);overflow:hidden;margin-bottom:24px;">

    <!-- Header -->
    <div style="background:#0f2a44;color:#e5e7eb;padding:28px 36px;">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h2 style="color:#f97316;font-size:1.5rem;font-weight:700;margin:0 0 4px;">
                    🗑 <?= e($company_name) ?>
                </h2>
                <?php if ($company_address): ?><div style="font-size:.85rem;color:#9ca3af;"><?= e($company_address) ?></div><?php endif; ?>
                <?php if ($company_phone): ?><div style="font-size:.85rem;color:#9ca3af;">📞 <?= e($company_phone) ?></div><?php endif; ?>
                <?php if ($company_email): ?><div style="font-size:.85rem;color:#9ca3af;">✉ <?= e($company_email) ?></div><?php endif; ?>
            </div>
            <div style="text-align:right;">
                <div style="font-size:1.3rem;font-weight:700;color:#f97316;">INVOICE</div>
                <div style="font-size:.9rem;color:#9ca3af;">
                    WO# <?= e($wo['wo_number']) ?><br>
                    Date: <?= e(date('M j, Y', strtotime($wo['created_at']))) ?><br>
                    <?php if ($wo['delivery_date']): ?>Delivery: <?= e(date('M j, Y', strtotime($wo['delivery_date']))) ?><br><?php endif; ?>
                </div>
                <?php
                $status_colors = [
                    'scheduled'        => 'primary',
                    'delivered'        => 'info',
                    'active'           => 'success',
                    'pickup_requested' => 'warning',
                    'picked_up'        => 'secondary',
                    'completed'        => 'success',
                    'canceled'         => 'danger',
                ];
                $sc = $status_colors[$wo['status']] ?? 'secondary';
                ?>
                <span class="badge bg-<?= $sc ?> mt-1" style="font-size:.85rem;padding:6px 12px;">
                    <?= e(ucfirst(str_replace('_', ' ', $wo['status']))) ?>
                </span>
            </div>
        </div>
    </div>

    <div style="padding:28px 36px;">

        <!-- Bill To / Service -->
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <h6 style="font-weight:700;color:#374151;margin-bottom:8px;">Bill To</h6>
                <div style="font-weight:600;"><?= e($cust_name) ?></div>
                <?php if ($cust_phone): ?><div style="font-size:.9rem;color:#6b7280;"><?= e(fmt_phone($cust_phone)) ?></div><?php endif; ?>
                <?php if ($cust_email && $cust_email !== '—'): ?><div style="font-size:.9rem;color:#6b7280;"><?= e($cust_email) ?></div><?php endif; ?>
                <?php if ($wo['service_address']): ?>
                <div style="font-size:.9rem;color:#6b7280;"><?= e($wo['service_address']) ?><?= $wo['service_city'] ? ', ' . e($wo['service_city']) : '' ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <h6 style="font-weight:700;color:#374151;margin-bottom:8px;">Service Details</h6>
                <div style="font-size:.9rem;">
                    <div>Work Order: <strong><?= e($wo['wo_number']) ?></strong></div>
                    <div>Address: <?= e($wo['service_address'] . ($wo['service_city'] ? ', '.$wo['service_city'] : '')) ?></div>
                    <?php if ($wo['size']): ?><div>Dumpster Size: <?= e($wo['size']) ?></div><?php endif; ?>
                    <?php if ($wo['project_type']): ?><div>Project Type: <?= e($wo['project_type']) ?></div><?php endif; ?>
                    <?php if ($wo['delivery_date']): ?><div>Delivery: <?= e(date('M j, Y', strtotime($wo['delivery_date']))) ?></div><?php endif; ?>
                    <?php if ($wo['pickup_date']): ?><div>Pickup: <?= e(date('M j, Y', strtotime($wo['pickup_date']))) ?></div><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Line items -->
        <table class="table" style="font-size:.9rem;">
            <thead style="background:#f9fafb;">
                <tr>
                    <th>Description</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Dumpster Rental — <?= e($wo['size'] ?? 'Standard') ?>
                        <?php if ($wo['project_type']): ?><br><small style="color:#6b7280;"><?= e($wo['project_type']) ?></small><?php endif; ?>
                    </td>
                    <td class="text-end"><?= fmt_money($subtotal) ?></td>
                </tr>
            </tbody>
            <tfoot style="border-top:2px solid #e5e7eb;">
                <tr>
                    <td class="text-end fw-semibold" style="padding-top:12px;">Subtotal</td>
                    <td class="text-end fw-semibold" style="padding-top:12px;"><?= fmt_money($subtotal) ?></td>
                </tr>
                <?php if ($tax_rate > 0): ?>
                <tr>
                    <td class="text-end text-muted">Tax (<?= e(number_format($tax_rate, 2)) ?>%)</td>
                    <td class="text-end text-muted"><?= fmt_money($tax_amount) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="text-end fw-bold" style="font-size:1.1rem;">Total</td>
                    <td class="text-end fw-bold" style="color:#f97316;font-size:1.1rem;"><?= fmt_money($total) ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Notes -->
        <?php if ($wo['footer_notes']): ?>
        <div style="margin-top:20px;padding:16px;background:#f9fafb;border-radius:6px;font-size:.85rem;color:#4b5563;">
            <strong>Notes:</strong><br>
            <?= nl2br(e($wo['footer_notes'])) ?>
        </div>
        <?php endif; ?>

        <!-- Payment instructions -->
        <div style="margin-top:20px;padding:16px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;font-size:.85rem;color:#9a3412;">
            <strong>Payment:</strong> Payment is due upon completion of service. Please contact us to arrange payment.
            <?php if ($company_phone): ?> Call us at <?= e($company_phone) ?>. <?php endif; ?>
        </div>

    </div>
</div>

<?php if ($is_print): ?>
<script>window.onload = function() { window.print(); };</script>
</body>
</html>
<?php else: ?>
<?php layout_end(); ?>
<?php endif; ?>
