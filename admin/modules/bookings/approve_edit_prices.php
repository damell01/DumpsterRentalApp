<?php
/**
 * Bookings - Edit prices before approval
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';

require_login();
require_role('admin', 'office');

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

if (($booking['booking_status'] ?? '') !== 'pending') {
    flash_error('Only pending booking requests can be approved.');
    redirect('view.php?id=' . $id);
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
$is_multi = count($group_bookings) > 1;

layout_start('Approve Booking - Edit Prices', 'bookings');
?>

<style>
.price-review-card {
    background: linear-gradient(135deg, rgba(249,115,22,.1), rgba(249,115,22,.04));
    border: 1px solid rgba(249,115,22,.3);
    border-radius: 14px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}
.price-review-header {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin-bottom: 1.25rem;
}
.price-review-header h4 {
    margin: 0;
    font-family: var(--font-cond);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.price-unit-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1rem;
}
.price-unit-table th,
.price-unit-table td {
    padding: .8rem 1rem;
    text-align: left;
    border-bottom: 1px solid rgba(255,255,255,.1);
}
.price-unit-table th {
    background: rgba(0,0,0,.2);
    font-weight: 600;
    font-size: .85rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.price-unit-table td.unit-code {
    font-weight: 600;
}
.price-unit-table input[type="number"] {
    width: 100%;
    padding: .5rem .75rem;
    border: 1px solid var(--st);
    border-radius: 8px;
    background: rgba(12,14,20,.4);
    color: var(--wh);
    font-weight: 600;
}
.price-unit-table input[type="number"]:focus {
    outline: none;
    border-color: var(--or);
    box-shadow: 0 0 0 2px rgba(249,115,22,.15);
}
.price-summary {
    background: rgba(0,0,0,.3);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 10px;
    padding: 1rem;
    margin-top: 1rem;
}
.price-summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: .5rem 0;
    font-size: .95rem;
}
.price-summary-row.total {
    border-top: 2px solid rgba(255,255,255,.15);
    padding-top: .75rem;
    margin-top: .75rem;
    font-weight: 700;
    font-size: 1.1rem;
    color: var(--or);
}
.action-buttons {
    display: flex;
    gap: .75rem;
    margin-top: 1.5rem;
}
.action-buttons .btn-tp-primary,
.action-buttons .btn-tp-ghost {
    flex: 1;
}
.warning-banner {
    background: rgba(220,53,69,.1);
    border: 1px solid rgba(220,53,69,.3);
    border-radius: 10px;
    padding: 1rem;
    margin-bottom: 1rem;
    display: flex;
    gap: .75rem;
    align-items: start;
}
.warning-banner i {
    color: #dc3545;
    flex-shrink: 0;
    margin-top: .25rem;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="fa-solid fa-circle-check me-2" style="color:var(--or);"></i> Approve Booking & Edit Prices</h4>
        <small class="text-muted">Review and adjust prices before creating the work order and invoice</small>
    </div>
    <a href="view.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-sm">
        <i class="fa-solid fa-arrow-left"></i> Back
    </a>
</div>

<?php if ($is_multi): ?>
<div class="warning-banner">
    <i class="fa-solid fa-info-circle"></i>
    <div>
        <strong>Multi-unit booking:</strong> This order contains <?= count($group_bookings) ?> units. You can edit the price for each unit individually below.
    </div>
</div>
<?php endif; ?>

<div class="price-review-card">
    <div class="price-review-header">
        <i class="fa-solid fa-dollar-sign"></i>
        <h4>Rental Unit Pricing</h4>
    </div>

    <form method="POST" action="approve_request.php" id="approvalForm">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="redirect_to" value="view.php?id=<?= $id ?>">

        <table class="price-unit-table">
            <thead>
                <tr>
                    <th>Unit</th>
                    <th>Size</th>
                    <th>Dates</th>
                    <th>Days</th>
                    <th>Daily Rate</th>
                    <th class="text-end">Total Price</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($group_bookings as $idx => $gu): ?>
                <tr>
                    <td class="unit-code"><?= e($gu['unit_code'] ?? '—') ?></td>
                    <td><?= e($gu['unit_size'] ?? '—') ?></td>
                    <td>
                        <?= e(fmt_date($gu['rental_start'])) ?> to <?= e(fmt_date($gu['rental_end'])) ?>
                    </td>
                    <td><?= (int)$gu['rental_days'] ?></td>
                    <td><?= fmt_money($gu['daily_rate']) ?></td>
                    <td class="text-end">
                        <input
                            type="number"
                            name="prices[<?= $idx ?>]"
                            value="<?= (float)$gu['total_amount'] ?>"
                            step="0.01"
                            min="0"
                            data-original="<?= (float)$gu['total_amount'] ?>"
                            class="price-input"
                        >
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="price-summary">
            <div class="price-summary-row">
                <span>Original Total:</span>
                <span id="originalTotal"><?= fmt_money($group_total) ?></span>
            </div>
            <div class="price-summary-row total">
                <span>New Total:</span>
                <span id="newTotal"><?= fmt_money($group_total) ?></span>
            </div>
        </div>

        <div class="action-buttons">
            <a href="view.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-sm">
                <i class="fa-solid fa-times"></i> Cancel
            </a>
            <button type="submit" class="btn-tp-primary btn-tp-sm"
                    data-confirm="Approve booking <?= e($booking['booking_number']) ?> and create work order and invoice?">
                <i class="fa-solid fa-circle-check"></i> Approve & Create Documents
            </button>
        </div>
    </form>
</div>

<script>
document.querySelectorAll('.price-input').forEach(input => {
    input.addEventListener('change', updateTotal);
    input.addEventListener('input', updateTotal);
});

function updateTotal() {
    const inputs = document.querySelectorAll('.price-input');
    let newTotal = 0;

    inputs.forEach(input => {
        const value = parseFloat(input.value) || 0;
        newTotal += value;
    });

    document.getElementById('newTotal').textContent = formatMoney(newTotal);

    const form = document.getElementById('approvalForm');
    if (Math.abs(newTotal - parseFloat(inputs[0].dataset.original || 0)) > 0.01) {
        // Prices have changed, update visual feedback if needed
    }
}

function formatMoney(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(amount);
}
</script>

<?php
layout_end();
?>
