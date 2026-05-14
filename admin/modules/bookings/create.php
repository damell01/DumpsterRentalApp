<?php
/**
 * Bookings – Create (Admin Manual Booking)
 * Auto-creates a work order for each confirmed booking.
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office');

$errors = [];

// ── Load active dumpsters ─────────────────────────────────────────────────────
$units = db_fetchall(
    "SELECT id, unit_code, type, size, daily_rate, base_price, rental_days, extra_day_price
     FROM dumpsters
     WHERE active = 1 AND status != 'maintenance'
     ORDER BY size, unit_code"
);

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $customer_name    = trim($_POST['customer_name']    ?? '');
    $customer_phone   = trim($_POST['customer_phone']   ?? '');
    $customer_email   = trim($_POST['customer_email']   ?? '');
    $customer_address = trim($_POST['customer_address'] ?? '');
    $customer_city    = trim($_POST['customer_city']    ?? '');
    $rental_start     = trim($_POST['rental_start']     ?? '');
    $rental_end       = trim($_POST['rental_end']       ?? '');
    $payment_method   = trim($_POST['payment_method']   ?? 'stripe');
    $worker_id        = (int)($_POST['worker_id']       ?? 0) ?: null;
    $notes            = trim($_POST['notes']            ?? '');

    // Support both single dumpster_id and multi dumpster_ids[]
    $selected_ids = [];
    if (!empty($_POST['dumpster_ids']) && is_array($_POST['dumpster_ids'])) {
        $selected_ids = array_values(array_filter(array_map('intval', $_POST['dumpster_ids'])));
    } elseif (!empty($_POST['dumpster_id'])) {
        $single_id = (int)$_POST['dumpster_id'];
        if ($single_id > 0) $selected_ids = [$single_id];
    }

    if ($customer_name === '') $errors[] = 'Customer Name is required.';
    if (empty($selected_ids))  $errors[] = 'Please select at least one unit.';
    if ($rental_start === '')  $errors[] = 'Start Date is required.';
    if ($rental_end === '')    $errors[] = 'End Date is required.';
    if (!in_array($payment_method, ['stripe', 'cash', 'check'], true)) $errors[] = 'Invalid payment method.';
    if (empty($errors) && $rental_start > $rental_end) $errors[] = 'End Date must be on or after Start Date.';

    $validated_units = [];
    if (empty($errors)) {
        foreach ($selected_ids as $did) {
            $unit = db_fetch(
                "SELECT id, unit_code, type, size, daily_rate, base_price, rental_days, extra_day_price, active, status
                 FROM dumpsters WHERE id = ? LIMIT 1",
                [$did]
            );
            if (!$unit) { $errors[] = "Unit ID $did not found."; break; }
            if (!$unit['active'] || $unit['status'] === 'maintenance') {
                $errors[] = "Unit {$unit['unit_code']} is not available."; break;
            }
            $overlap = db_fetch(
                "SELECT COUNT(*) AS cnt FROM bookings
                 WHERE dumpster_id = ? AND booking_status != 'canceled'
                   AND rental_start <= ? AND rental_end >= ?",
                [$did, $rental_end, $rental_start]
            );
            if ((int)($overlap['cnt'] ?? 0) > 0) {
                $errors[] = "Unit {$unit['unit_code']} is already booked during those dates."; break;
            }
            $block = db_fetch(
                "SELECT COUNT(*) AS cnt FROM inventory_blocks
                 WHERE dumpster_id = ? AND block_start <= ? AND block_end >= ?",
                [$did, $rental_end, $rental_start]
            );
            if ((int)($block['cnt'] ?? 0) > 0) {
                $errors[] = "Unit {$unit['unit_code']} has a scheduled block during those dates."; break;
            }
            $validated_units[] = $unit;
        }
    }

    if (empty($errors) && !empty($validated_units)) {
        $d1   = new \DateTime($rental_start);
        $d2   = new \DateTime($rental_end);
        $days = max(1, (int)$d1->diff($d2)->days);

        $pay_status_map = ['stripe' => 'pending', 'cash' => 'pending_cash', 'check' => 'pending_check'];
        $payment_status = $pay_status_map[$payment_method] ?? 'pending';

        // Find or create the customer record (deduplicates by email/phone).
        $customer_id = null;
        try {
            $customer_id = billing_customer_service()->findOrCreateByBooking([
                'customer_name'    => $customer_name,
                'customer_phone'   => $customer_phone,
                'customer_email'   => $customer_email,
                'customer_address' => $customer_address,
                'customer_city'    => $customer_city,
            ]);
        } catch (\Throwable $e) {
            error_log('[Admin Booking] Customer dedup failed: ' . $e->getMessage());
        }

        // One booking number shared across all units in this order.
        $booking_number = next_number('BK', 'bookings', 'booking_number');
        $booking_group_id = count($validated_units) > 1 ? bin2hex(random_bytes(8)) : null;

        $created_booking_ids = [];
        $created_numbers     = [];
        $created_wo_numbers  = [];

        $wo_footer = get_setting('wo_footer', '');

        foreach ($validated_units as $unit) {
            $daily_rate      = (float)$unit['daily_rate'];
            $base_price      = (float)($unit['base_price'] ?? 0);
            $incl_days       = max(1, (int)($unit['rental_days'] ?? 7));
            $extra_day_price = ($unit['extra_day_price'] !== null) ? (float)$unit['extra_day_price'] : null;

            if ($base_price > 0) {
                $extra_days = max(0, $days - $incl_days);
                $total = round($base_price + ($extra_days * ($extra_day_price ?? 0)), 2);
            } else {
                $total = round($daily_rate * $days, 2);
            }

            $booking_id = (int)db_insert('bookings', [
                'booking_number'   => $booking_number,
                'customer_id'      => $customer_id,
                'customer_name'    => $customer_name,
                'customer_phone'   => $customer_phone ?: null,
                'customer_email'   => $customer_email ?: null,
                'customer_address' => $customer_address ?: null,
                'customer_city'    => $customer_city ?: null,
                'booking_group_id' => $booking_group_id,
                'dumpster_id'      => (int)$unit['id'],
                'unit_code'        => $unit['unit_code'],
                'unit_type'        => $unit['type'],
                'unit_size'        => $unit['size'],
                'rental_start'     => $rental_start,
                'rental_end'       => $rental_end,
                'rental_days'      => $days,
                'daily_rate'       => $daily_rate,
                'total_amount'     => $total,
                'payment_method'   => $payment_method,
                'payment_status'   => $payment_status,
                'booking_status'   => 'confirmed',
                'worker_id'        => $worker_id,
                'notes'            => $notes ?: null,
                'created_by'       => (int)($_SESSION['user_id'] ?? 0),
                'created_at'       => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);

            log_activity('create', "Created booking $booking_number for $customer_name", 'booking', $booking_id);

            db_update('dumpsters', ['status' => 'reserved', 'updated_at' => date('Y-m-d H:i:s')], 'id', (int)$unit['id']);

            // ── Auto-create work order ────────────────────────────────────────
            $wo_number = next_number('WO', 'work_orders', 'wo_number');
            $wo_id = (int)db_insert('work_orders', [
                'wo_number'       => $wo_number,
                'customer_id'     => $customer_id,
                'cust_name'       => $customer_name,
                'cust_phone'      => $customer_phone ?: null,
                'cust_email'      => $customer_email ?: null,
                'service_address' => $customer_address ?: null,
                'service_city'    => $customer_city ?: null,
                'service_state'   => null,
                'service_zip'     => null,
                'size'            => $unit['size'],
                'project_type'    => null,
                'dumpster_id'     => (int)$unit['id'],
                'delivery_date'   => $rental_start,
                'pickup_date'     => $rental_end,
                'assigned_driver' => $worker_id,
                'amount'          => $total,
                'status'          => 'scheduled',
                'priority'        => 'normal',
                'internal_notes'  => "Auto-created from booking $booking_number" . ($notes ? ". Notes: $notes" : ''),
                'footer_notes'    => $wo_footer ?: null,
                'created_by'      => (int)($_SESSION['user_id'] ?? 0),
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            log_activity('create', "Auto-created work order $wo_number from booking $booking_number", 'work_order', $wo_id);

            $created_booking_ids[] = $booking_id;
            $created_numbers[]     = $booking_number;
            $created_wo_numbers[]  = $wo_number;
        }

        $count = count($created_booking_ids);
        if ($count === 1) {
            flash_success("Booking {$created_numbers[0]} confirmed — work order {$created_wo_numbers[0]} auto-created.");
            redirect('view.php?id=' . $created_booking_ids[0]);
        } else {
            $bk_list = implode(', ', $created_numbers);
            $wo_list = implode(', ', $created_wo_numbers);
            flash_success("$count bookings created ($bk_list) — work orders auto-created ($wo_list).");
            redirect('index.php');
        }
    }
}

// ── Pre-fill from customer if linked ─────────────────────────────────────────
$prefill_customer = null;
if (!$_POST && ($cid = (int)($_GET['customer_id'] ?? 0)) > 0) {
    $prefill_customer = db_fetch('SELECT * FROM customers WHERE id = ? LIMIT 1', [$cid]);
}

$f = [
    'customer_name'    => $_POST['customer_name']    ?? ($prefill_customer['name']    ?? ''),
    'customer_phone'   => $_POST['customer_phone']   ?? ($prefill_customer['phone']   ?? ''),
    'customer_email'   => $_POST['customer_email']   ?? ($prefill_customer['email']   ?? ''),
    'customer_address' => $_POST['customer_address'] ?? ($prefill_customer['address'] ?? ''),
    'customer_city'    => $_POST['customer_city']    ?? ($prefill_customer['city']    ?? ''),
    'selected_ids'     => array_map('intval', (array)($_POST['dumpster_ids'] ?? [])),
    'rental_start'     => $_POST['rental_start']     ?? '',
    'rental_end'       => $_POST['rental_end']       ?? '',
    'payment_method'   => $_POST['payment_method']   ?? 'stripe',
    'worker_id'        => (int)($_POST['worker_id']  ?? 0),
    'notes'            => $_POST['notes']            ?? '',
];

layout_start('New Booking', 'bookings');
?>

<form method="POST" action="create.php" id="bookingForm">
<?= csrf_field() ?>

<!-- Page heading -->
<div class="tp-form-heading">
    <a href="index.php" class="tp-back-link">
        <i class="fa-solid fa-arrow-left"></i> Back to Bookings
    </a>
    <h1>New Booking</h1>
</div>

<?php if (!empty($prefill_customer)): ?>
<div class="alert alert-info mb-4" style="font-size:.875rem;">
    <i class="fa-solid fa-circle-info me-1"></i>
    Pre-filled from customer <strong><?= e($prefill_customer['name']) ?></strong>.
    <a href="<?= e(APP_URL) ?>/modules/customers/view.php?id=<?= (int)$prefill_customer['id'] ?>" class="ms-2">View Profile</a>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-4">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="tp-form-layout">

    <!-- ── Main form column ───────────────────────────────────────────────── -->
    <div class="tp-form-main">

        <!-- Customer -->
        <div class="tp-form-section">
            <div class="tp-form-section-head">
                <span class="section-icon"><i class="fa-solid fa-user"></i></span>
                <h6>Customer Information</h6>
            </div>
            <div class="tp-form-section-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="customer_name">
                            Customer Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="customer_name" name="customer_name" class="form-control"
                               value="<?= e($f['customer_name']) ?>" required
                               placeholder="Full name or company name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="customer_phone">Phone</label>
                        <input type="text" id="customer_phone" name="customer_phone" class="form-control"
                               value="<?= e($f['customer_phone']) ?>"
                               placeholder="(555) 000-0000">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="customer_email">Email</label>
                        <input type="email" id="customer_email" name="customer_email" class="form-control"
                               value="<?= e($f['customer_email']) ?>"
                               placeholder="customer@example.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="customer_city">City</label>
                        <input type="text" id="customer_city" name="customer_city" class="form-control"
                               value="<?= e($f['customer_city']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="customer_address">Service Address</label>
                        <input type="text" id="customer_address" name="customer_address" class="form-control"
                               value="<?= e($f['customer_address']) ?>"
                               placeholder="Where the dumpster will be placed">
                    </div>
                </div>
            </div>
        </div>

        <!-- Units -->
        <div class="tp-form-section">
            <div class="tp-form-section-head">
                <span class="section-icon"><i class="fa-solid fa-dumpster"></i></span>
                <h6>Select Unit(s) <span class="section-hint">— choose one or more</span></h6>
            </div>
            <div class="tp-form-section-body">
                <div class="tp-unit-grid" id="unitGrid">
                    <?php foreach ($units as $u): ?>
                    <?php $checked = in_array((int)$u['id'], $f['selected_ids'], true); ?>
                    <label class="tp-unit-card<?= $checked ? ' selected' : '' ?>"
                           data-id="<?= (int)$u['id'] ?>"
                           data-rate="<?= e($u['daily_rate']) ?>"
                           data-base="<?= e($u['base_price'] ?? 0) ?>"
                           data-incl="<?= (int)($u['rental_days'] ?? 7) ?>"
                           data-extra="<?= e($u['extra_day_price'] ?? '') ?>"
                           data-code="<?= e($u['unit_code']) ?>"
                           data-size="<?= e($u['size']) ?>">
                        <input type="checkbox" name="dumpster_ids[]"
                               value="<?= (int)$u['id'] ?>"
                               <?= $checked ? 'checked' : '' ?>
                               onchange="refreshSummary(); this.closest('.tp-unit-card').classList.toggle('selected', this.checked);">
                        <div class="tp-unit-code"><?= e($u['unit_code']) ?></div>
                        <div class="tp-unit-size"><?= e($u['size']) ?></div>
                        <div class="tp-unit-price">
                            <?php if ((float)($u['base_price'] ?? 0) > 0): ?>
                                <?= e(fmt_money($u['base_price'])) ?> / <?= (int)($u['rental_days'] ?? 7) ?> days
                            <?php else: ?>
                                <?= e(fmt_money($u['daily_rate'])) ?>/day
                            <?php endif; ?>
                        </div>
                        <?php if ($u['status'] !== 'available'): ?>
                        <div class="tp-unit-status"><?= e(ucfirst($u['status'])) ?></div>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="mt-2 d-flex gap-2">
                    <button type="button" class="btn-tp-ghost btn-tp-xs"
                            onclick="document.querySelectorAll('input[name=\'dumpster_ids[]\']').forEach(function(c){c.checked=true;c.closest('.tp-unit-card').classList.add('selected');}); refreshSummary();">
                        Select All
                    </button>
                    <button type="button" class="btn-tp-ghost btn-tp-xs"
                            onclick="document.querySelectorAll('input[name=\'dumpster_ids[]\']').forEach(function(c){c.checked=false;c.closest('.tp-unit-card').classList.remove('selected');}); refreshSummary();">
                        Clear All
                    </button>
                </div>
            </div>
        </div>

        <!-- Dates & Pricing -->
        <div class="tp-form-section">
            <div class="tp-form-section-head">
                <span class="section-icon"><i class="fa-solid fa-calendar-days"></i></span>
                <h6>Rental Dates</h6>
            </div>
            <div class="tp-form-section-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label" for="rental_start">
                            Start Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="rental_start" name="rental_start" class="form-control"
                               value="<?= e($f['rental_start']) ?>"
                               min="<?= date('Y-m-d') ?>" required onchange="refreshSummary()">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="rental_end">
                            End Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="rental_end" name="rental_end" class="form-control"
                               value="<?= e($f['rental_end']) ?>"
                               min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required onchange="refreshSummary()">
                    </div>
                    <div class="col-md-2 d-flex align-items-end pb-1">
                        <div id="daysDisplay" class="text-muted" style="font-size:.85rem;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment & Notes -->
        <div class="tp-form-section">
            <div class="tp-form-section-head">
                <span class="section-icon"><i class="fa-solid fa-credit-card"></i></span>
                <h6>Payment &amp; Notes</h6>
            </div>
            <div class="tp-form-section-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label" for="payment_method">Payment Method</label>
                        <select id="payment_method" name="payment_method" class="form-select">
                            <option value="stripe" <?= $f['payment_method'] === 'stripe' ? 'selected' : '' ?>>Stripe (Online)</option>
                            <option value="cash"   <?= $f['payment_method'] === 'cash'   ? 'selected' : '' ?>>Cash</option>
                            <option value="check"  <?= $f['payment_method'] === 'check'  ? 'selected' : '' ?>>Check</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Internal Notes</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3"
                                  placeholder="Delivery instructions, access info, anything relevant…"><?= e($f['notes']) ?></textarea>
                    </div>
                </div>
                <div class="mt-3 p-2 rounded" style="background:var(--dk2);border:1px solid var(--st);font-size:.8rem;color:var(--gy);">
                    <i class="fa-solid fa-circle-check me-1" style="color:var(--gr);"></i>
                    A <strong>work order</strong> will be automatically scheduled when this booking is confirmed.
                </div>
            </div>
        </div>

    </div><!-- /tp-form-main -->

    <!-- ── Live Order Summary Sidebar ────────────────────────────────────── -->
    <div class="tp-form-sidebar">
        <div class="tp-sidebar-card">
            <div class="tp-sidebar-card-head">
                <i class="fa-solid fa-receipt me-1" style="color:var(--or);"></i> Order Summary
            </div>
            <div class="tp-sidebar-card-body" id="orderSummaryBody">
                <div class="tp-summary-empty">
                    <i class="fa-solid fa-dumpster"></i>
                    Select units and dates to see your order summary.
                </div>
            </div>
        </div>
    </div>

</div><!-- /tp-form-layout -->

<!-- Sticky save bar -->
<div class="tp-sticky-bar">
    <div class="tp-sticky-bar-info">
        <strong id="stickyTotal">Select units &amp; dates</strong>
        <span id="stickySub" style="color:var(--gy);margin-left:.5rem;"></span>
    </div>
    <div class="tp-sticky-bar-actions">
        <a href="index.php" class="btn-tp-ghost">Cancel</a>
        <button type="submit" class="btn-tp-primary">
            <i class="fa-solid fa-calendar-check"></i> Create Booking
        </button>
    </div>
</div>

</form>

<script>
function calcDays() {
    var s = document.getElementById('rental_start').value;
    var e = document.getElementById('rental_end').value;
    if (!s || !e) return 0;
    var st = Date.UTC.apply(null, s.split('-').map(function(v,i){ return i===1?+v-1:+v; }));
    var en = Date.UTC.apply(null, e.split('-').map(function(v,i){ return i===1?+v-1:+v; }));
    return Math.max(0, Math.round((en - st) / 86400000));
}

function calcUnitTotal(card, days) {
    var base  = parseFloat(card.dataset.base)  || 0;
    var incl  = parseInt(card.dataset.incl, 10) || 7;
    var extra = card.dataset.extra !== '' ? parseFloat(card.dataset.extra) : 0;
    var rate  = parseFloat(card.dataset.rate)  || 0;
    if (base > 0) {
        return base + Math.max(0, days - incl) * extra;
    }
    return rate * days;
}

function fmtMoney(n) {
    return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function refreshSummary() {
    var days = calcDays();
    var daysEl = document.getElementById('daysDisplay');
    daysEl.textContent = days > 0 ? days + ' day' + (days !== 1 ? 's' : '') : '';

    var checked = document.querySelectorAll('input[name="dumpster_ids[]"]:checked');
    var body    = document.getElementById('orderSummaryBody');
    var stickyTotal = document.getElementById('stickyTotal');
    var stickySub   = document.getElementById('stickySub');

    if (checked.length === 0 || days <= 0) {
        body.innerHTML = '<div class="tp-summary-empty"><i class="fa-solid fa-dumpster"></i>Select units and dates to see your order summary.</div>';
        stickyTotal.innerHTML = 'Select units &amp; dates';
        stickySub.textContent = '';
        return;
    }

    var html = '<div class="tp-order-summary">';
    var start = document.getElementById('rental_start').value;
    var end   = document.getElementById('rental_end').value;

    if (start && end) {
        html += '<div class="tp-order-summary-line">'
             + '<span class="tp-order-summary-label">Dates</span>'
             + '<span class="tp-order-summary-val">' + start + ' – ' + end + '</span>'
             + '</div>';
        html += '<div class="tp-order-summary-line">'
             + '<span class="tp-order-summary-label">Duration</span>'
             + '<span class="tp-order-summary-val">' + days + ' day' + (days !== 1 ? 's' : '') + '</span>'
             + '</div>';
    }

    var grandTotal = 0;
    checked.forEach(function(cb) {
        var card = cb.closest('.tp-unit-card');
        if (!card) return;
        var sub = calcUnitTotal(card, days);
        grandTotal += sub;
        html += '<div class="tp-order-summary-line">'
             + '<span class="tp-order-summary-label">' + card.dataset.code + ' <span style="color:var(--gl);font-size:.78rem;">' + card.dataset.size + '</span></span>'
             + '<span class="tp-order-summary-val">' + fmtMoney(sub) + '</span>'
             + '</div>';
    });

    html += '</div>';
    html += '<div class="tp-order-total"><span>Estimated Total</span><span class="tp-order-total-val">' + fmtMoney(grandTotal) + '</span></div>';

    body.innerHTML = html;
    stickyTotal.textContent = fmtMoney(grandTotal);
    stickySub.textContent   = checked.length + ' unit' + (checked.length !== 1 ? 's' : '') + ' · ' + days + ' days';
}

// Init on load in case form is pre-filled
refreshSummary();
</script>

<?php layout_end(); ?>
