<?php
/**
 * Bookings – Edit
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
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

$linked_customer = !empty($booking['customer_id'])
    ? db_fetch('SELECT id, address, city, state, zip FROM customers WHERE id = ? LIMIT 1', [(int)$booking['customer_id']])
    : null;

$errors = [];

$units = db_fetchall(
    "SELECT id, unit_code, type, size, daily_rate, base_price, rental_days, extra_day_price, weekly_rate, monthly_rate
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
    $customer_state   = strtoupper(trim($_POST['customer_state'] ?? ''));
    $customer_zip     = trim($_POST['customer_zip'] ?? '');
    $dumpster_id      = (int)($_POST['dumpster_id']     ?? 0);
    $rental_start     = trim($_POST['rental_start']     ?? '');
    $rental_end       = trim($_POST['rental_end']       ?? '');
    $payment_method   = trim($_POST['payment_method']   ?? 'stripe');
    $worker_id        = (int)($_POST['worker_id']       ?? 0) ?: null;
    $notes            = trim($_POST['notes']            ?? '');

    if ($customer_name === '') {
        $errors[] = 'Customer Name is required.';
    }
    if ($dumpster_id <= 0) {
        $errors[] = 'Please select a unit.';
    }
    if ($rental_start === '') {
        $errors[] = 'Start Date is required.';
    }
    if ($rental_end === '') {
        $errors[] = 'End Date is required.';
    }
    if (!in_array($payment_method, ['stripe', 'cash', 'check'], true)) {
        $errors[] = 'Invalid payment method.';
    }
    if (empty($errors) && $rental_start > $rental_end) {
        $errors[] = 'End Date must be on or after Start Date.';
    }

    $unit = null;
    if (empty($errors) && $dumpster_id > 0) {
        $unit = db_fetch(
            "SELECT id, unit_code, type, size, daily_rate, base_price, rental_days, extra_day_price, weekly_rate, monthly_rate, active, status
             FROM dumpsters WHERE id = ? LIMIT 1",
            [$dumpster_id]
        );
        if (!$unit) {
            $errors[] = 'Selected unit not found.';
        } elseif (!$unit['active'] || $unit['status'] === 'maintenance') {
            $errors[] = 'Selected unit is not available.';
        }
    }

    if (empty($errors) && $unit) {
        // Overlap check excluding this booking
        $overlap = db_fetch(
            "SELECT COUNT(*) AS cnt FROM bookings
             WHERE dumpster_id = ?
               AND id != ?
               AND booking_status != 'canceled'
               AND rental_start <= ? AND rental_end >= ?",
            [$dumpster_id, $id, $rental_end, $rental_start]
        );
        if ((int)($overlap['cnt'] ?? 0) > 0) {
            $errors[] = 'That unit is already booked during the selected dates.';
        }

        if (empty($errors)) {
            $block = db_fetch(
                "SELECT COUNT(*) AS cnt FROM inventory_blocks
                 WHERE dumpster_id = ?
                   AND block_start <= ? AND block_end >= ?",
                [$dumpster_id, $rental_end, $rental_start]
            );
            if ((int)($block['cnt'] ?? 0) > 0) {
                $errors[] = 'That unit has a scheduled block during the selected dates.';
            }
        }
    }

    if (empty($errors) && $unit) {
        $d1         = new \DateTime($rental_start);
        $d2         = new \DateTime($rental_end);
        $days       = max(1, (int)$d1->diff($d2)->days);
        $daily_rate = (float)$unit['daily_rate'];
        $total = calculate_unit_rental_total($unit, $days);

        db_update('bookings', [
            'customer_name'    => $customer_name,
            'customer_phone'   => $customer_phone ?: null,
            'customer_email'   => $customer_email ?: null,
            'customer_address' => $customer_address ?: null,
            'customer_city'    => $customer_city ?: null,
            'dumpster_id'      => $dumpster_id,
            'unit_code'        => $unit['unit_code'],
            'unit_type'        => $unit['type'],
            'unit_size'        => $unit['size'],
            'rental_start'     => $rental_start,
            'rental_end'       => $rental_end,
            'rental_days'      => $days,
            'daily_rate'       => $daily_rate,
            'total_amount'     => $total,
            'payment_method'   => $payment_method,
            'worker_id'        => $worker_id,
            'notes'            => $notes ?: null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ], 'id', $id);

        if (!empty($booking['customer_id'])) {
            db_update('customers', [
                'address'    => $customer_address ?: null,
                'city'       => $customer_city ?: null,
                'state'      => $customer_state ?: null,
                'zip'        => $customer_zip ?: null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id', (int)$booking['customer_id']);
        }

        log_activity('update', "Updated booking {$booking['booking_number']}", 'booking', $id);
        flash_success("Booking {$booking['booking_number']} updated.");
        redirect('view.php?id=' . $id);
    }

    // Re-populate for form re-display
    $booking = array_merge($booking, [
        'customer_name'    => $customer_name,
        'customer_phone'   => $customer_phone,
        'customer_email'   => $customer_email,
        'customer_address' => $customer_address,
        'customer_city'    => $customer_city,
        'customer_state'   => $customer_state,
        'customer_zip'     => $customer_zip,
        'dumpster_id'      => $dumpster_id,
        'rental_start'     => $rental_start,
        'rental_end'       => $rental_end,
        'payment_method'   => $payment_method,
        'worker_id'        => $worker_id,
        'notes'            => $notes,
    ]);
}

layout_start('Edit Booking', 'bookings');
?>

<style>
.bk-section-title {
    font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
    color:var(--gy,#6b7280);padding-bottom:.5rem;margin-bottom:1rem;
    border-bottom:1px solid var(--st,#e5e7eb);
}
.bk-form-card { background:var(--dk1,#fff);border:1px solid var(--st,#e5e7eb);border-radius:10px;padding:1.25rem 1.5rem;margin-bottom:1rem; }
@media(max-width:576px){ .bk-form-card{padding:1rem;} }
.tp-address-suggest {
    margin-top: .5rem;
    background: var(--dk1,#fff);
    border: 1px solid var(--st,#e5e7eb);
    border-radius: 10px;
    overflow: hidden;
}
.tp-address-option {
    width: 100%;
    border: 0;
    background: transparent;
    text-align: left;
    padding: .75rem .9rem;
    display: flex;
    flex-direction: column;
    gap: .15rem;
}
.tp-address-option + .tp-address-option { border-top: 1px solid var(--st,#e5e7eb); }
.tp-address-option:hover { background: rgba(249,115,22,.08); }
.tp-address-option span { font-size: .8rem; color: var(--gy,#6b7280); }
</style>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">
        Edit Booking
        <span style="color:var(--accent,#f97316);font-weight:600;"><?= e($booking['booking_number']) ?></span>
    </h5>
    <div class="d-flex gap-2">
        <a href="view.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-eye"></i> <span class="d-none d-sm-inline">View</span>
        </a>
        <a href="index.php" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-arrow-left"></i> <span class="d-none d-sm-inline">Back</span>
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-3">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $err): ?>
            <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="edit.php" id="bk-edit-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <!-- ── Customer Information ── -->
    <div class="bk-form-card">
        <div class="bk-section-title"><i class="fa-solid fa-user me-1"></i> Customer Information</div>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="customer_name">Customer Name <span class="text-danger">*</span></label>
                <input type="text" id="customer_name" name="customer_name" class="form-control"
                       value="<?= e($booking['customer_name']) ?>" required>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="customer_phone">Phone</label>
                <input type="text" id="customer_phone" name="customer_phone" class="form-control"
                       value="<?= e($booking['customer_phone'] ?? '') ?>">
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="customer_email">Email</label>
                <input type="email" id="customer_email" name="customer_email" class="form-control"
                       value="<?= e($booking['customer_email'] ?? '') ?>">
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="customer_address">Address</label>
                <input type="text" id="customer_address" name="customer_address" class="form-control"
                       value="<?= e($booking['customer_address'] ?? ($linked_customer['address'] ?? '')) ?>" autocomplete="street-address">
                <div id="customer_address_suggest" class="tp-address-suggest" hidden></div>
            </div>
            <div class="col-sm-3">
                <label class="form-label" for="customer_city">City</label>
                <input type="text" id="customer_city" name="customer_city" class="form-control"
                       value="<?= e($booking['customer_city'] ?? ($linked_customer['city'] ?? '')) ?>" autocomplete="address-level2">
            </div>
            <div class="col-sm-3">
                <label class="form-label" for="customer_state">State</label>
                <input type="text" id="customer_state" name="customer_state" class="form-control"
                       value="<?= e($booking['customer_state'] ?? ($linked_customer['state'] ?? '')) ?>" maxlength="2" placeholder="AL" autocomplete="address-level1">
            </div>
            <div class="col-sm-3">
                <label class="form-label" for="customer_zip">ZIP</label>
                <input type="text" id="customer_zip" name="customer_zip" class="form-control"
                       value="<?= e($booking['customer_zip'] ?? ($linked_customer['zip'] ?? '')) ?>" maxlength="10" placeholder="36551" autocomplete="postal-code">
            </div>
        </div>
    </div>

    <!-- ── Unit & Dates ── -->
    <div class="bk-form-card">
        <div class="bk-section-title"><i class="fa-solid fa-dumpster me-1"></i> Unit &amp; Dates</div>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="dumpster_id">Unit <span class="text-danger">*</span></label>
                <select id="dumpster_id" name="dumpster_id" class="form-select" required onchange="updateTotal()">
                    <option value="">— Select unit —</option>
                    <?php foreach ($units as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"
                            data-rate="<?= e($u['daily_rate']) ?>"
                            data-base="<?= e($u['base_price'] ?? 0) ?>"
                            data-incl="<?= (int)($u['rental_days'] ?? 7) ?>"
                            data-extra="<?= e($u['extra_day_price'] ?? '') ?>"
                            data-weekly="<?= e($u['weekly_rate'] ?? 0) ?>"
                            data-monthly="<?= e($u['monthly_rate'] ?? 0) ?>"
                            <?= (int)$booking['dumpster_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= e($u['unit_code']) ?> — <?= e($u['size']) ?> (<?= e(ucfirst($u['type'])) ?>)
                        <?php if ((float)($u['base_price'] ?? 0) > 0): ?>
                            — <?= e(fmt_money($u['base_price'])) ?> / <?= (int)($u['rental_days'] ?? 7) ?> days
                        <?php else: ?>
                            — <?= e(fmt_money($u['daily_rate'])) ?>/day
                        <?php endif; ?>
                        <?php if ((float)($u['weekly_rate'] ?? 0) > 0): ?> · <?= e(fmt_money($u['weekly_rate'])) ?>/wk<?php endif; ?>
                        <?php if ((float)($u['monthly_rate'] ?? 0) > 0): ?> · <?= e(fmt_money($u['monthly_rate'])) ?>/mo<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-4">
                <label class="form-label" for="rental_start">Start Date <span class="text-danger">*</span></label>
                <input type="date" id="rental_start" name="rental_start" class="form-control"
                       value="<?= e($booking['rental_start']) ?>" required onchange="updateTotal()">
            </div>
            <div class="col-sm-4">
                <label class="form-label" for="rental_end">End Date <span class="text-danger">*</span></label>
                <input type="date" id="rental_end" name="rental_end" class="form-control"
                       value="<?= e($booking['rental_end']) ?>" required onchange="updateTotal()">
            </div>
            <div class="col-sm-4">
                <label class="form-label">Estimated Total</label>
                <div id="totalDisplay" class="form-control"
                     style="background:var(--dk3,#111827);color:var(--or,#f97316);font-weight:700;">—</div>
            </div>
        </div>
    </div>

    <!-- ── Payment & Notes ── -->
    <div class="bk-form-card">
        <div class="bk-section-title"><i class="fa-solid fa-credit-card me-1"></i> Payment &amp; Notes</div>
        <div class="row g-3">
            <div class="col-sm-6 col-md-4">
                <label class="form-label" for="payment_method">Payment Method</label>
                <select id="payment_method" name="payment_method" class="form-select">
                    <option value="stripe" <?= $booking['payment_method'] === 'stripe' ? 'selected' : '' ?>>Stripe (Online)</option>
                    <option value="cash"   <?= $booking['payment_method'] === 'cash'   ? 'selected' : '' ?>>Cash</option>
                    <option value="check"  <?= $booking['payment_method'] === 'check'  ? 'selected' : '' ?>>Check</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="3"
                          placeholder="Internal notes…"><?= e($booking['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- ── Save buttons (desktop) ── -->
    <div class="d-flex gap-2 flex-wrap mb-4">
        <button type="submit" class="btn-tp-primary btn-tp-sm" style="padding:.6rem 1.5rem;font-size:.95rem;">
            <i class="fa-solid fa-floppy-disk"></i> Save Changes
        </button>
        <a href="view.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-sm">Cancel</a>
    </div>

    <!-- ── Sticky save bar (mobile only) ── -->
    <div class="tp-sticky-bar">
        <button type="submit" class="btn-tp-primary btn-tp-sm flex-grow-1" style="justify-content:center;">
            <i class="fa-solid fa-floppy-disk"></i> Save Changes
        </button>
        <a href="view.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-sm">Cancel</a>
    </div>
    <div class="tp-sticky-bar-spacer"></div>

</form>

<script>
function setupAddressAutocomplete(opts) {
    var address = document.getElementById(opts.addressId);
    var city = document.getElementById(opts.cityId);
    var state = document.getElementById(opts.stateId);
    var zip = document.getElementById(opts.zipId);
    var box = document.getElementById(opts.boxId);
    if (!address || !city || !state || !zip || !box) return;

    var timer = null;
    var controller = null;

    function esc(s) {
        return String(s).replace(/[&<>"]/g, function(ch) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch];
        });
    }

    function hideBox() {
        box.hidden = true;
        box.innerHTML = '';
    }

    function partsFor(item) {
        var a = item.address || {};
        var street = [a.house_number || '', a.road || a.pedestrian || a.footway || a.cycleway || a.path || '']
            .join(' ').trim();
        if (!street) {
            street = String(item.display_name || '').split(',')[0].trim();
        }
        return {
            street: street,
            city: a.city || a.town || a.village || a.hamlet || a.county || '',
            state: (a.state_code || a.state || '').toString().trim(),
            zip: (a.postcode || '').toString().trim()
        };
    }

    function choose(item) {
        var parts = partsFor(item);
        address.value = parts.street;
        if (parts.city) city.value = parts.city;
        if (parts.state) state.value = parts.state.length === 2 ? parts.state.toUpperCase() : parts.state;
        if (parts.zip) zip.value = parts.zip;
        hideBox();
    }

    address.addEventListener('input', function() {
        var q = address.value.trim();
        if (controller) controller.abort();
        clearTimeout(timer);
        if (q.length < 5) { hideBox(); return; }

        timer = setTimeout(function() {
            controller = new AbortController();
            fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&countrycodes=us&limit=5&viewbox=-88.5,31.2,-86.5,30.0&bounded=0&q=' + encodeURIComponent(q), {
                signal: controller.signal,
                headers: { 'Accept': 'application/json' }
            })
            .then(function(r) { return r.ok ? r.json() : []; })
            .then(function(items) {
                if (!Array.isArray(items) || items.length === 0) { hideBox(); return; }
                box.innerHTML = items.map(function(item, idx) {
                    var parts = partsFor(item);
                    var subtitle = [parts.city, parts.state, parts.zip].filter(Boolean).join(', ');
                    return '<button type="button" class="tp-address-option" data-idx="' + idx + '">'
                        + '<strong>' + esc(parts.street || item.display_name || 'Address') + '</strong>'
                        + (subtitle ? '<span>' + esc(subtitle) + '</span>' : '')
                        + '</button>';
                }).join('');
                box.hidden = false;
                box.querySelectorAll('.tp-address-option').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        choose(items[parseInt(btn.dataset.idx, 10)]);
                    });
                });
            })
            .catch(function() { hideBox(); });
        }, 250);
    });

    address.addEventListener('blur', function() {
        setTimeout(hideBox, 150);
    });
}

function updateTotal() {
    var sel   = document.getElementById('dumpster_id');
    var start = document.getElementById('rental_start').value;
    var end   = document.getElementById('rental_end').value;
    var disp  = document.getElementById('totalDisplay');

    if (!sel.value || !start || !end) { disp.textContent = '—'; return; }
    var opt      = sel.options[sel.selectedIndex];
    var rate     = parseFloat(opt.dataset.rate)  || 0;
    var base     = parseFloat(opt.dataset.base)  || 0;
    var incl     = parseInt(opt.dataset.incl, 10) || 7;
    var extra    = opt.dataset.extra !== '' ? parseFloat(opt.dataset.extra) : null;
    var weekly   = parseFloat(opt.dataset.weekly)  || 0;
    var monthly  = parseFloat(opt.dataset.monthly) || 0;
    var overage  = extra !== null ? extra : rate;

    var startUTC = Date.UTC.apply(null, start.split('-').map(function(v,i){ return i===1?parseInt(v,10)-1:parseInt(v,10); }));
    var endUTC   = Date.UTC.apply(null, end.split('-').map(function(v,i){ return i===1?parseInt(v,10)-1:parseInt(v,10); }));
    var days     = Math.round((endUTC - startUTC) / 86400000);
    if (days <= 0) { disp.textContent = 'Invalid dates'; return; }

    var total;
    if (days >= 30 && monthly > 0) {
        total = Math.floor(days / 30) * monthly + (days % 30) * overage;
    } else if (days >= 7 && weekly > 0) {
        total = Math.floor(days / 7) * weekly + (days % 7) * overage;
    } else if (base > 0) {
        var extraDays = Math.max(0, days - incl);
        total = base + (extraDays * (extra || 0));
    } else {
        total = rate * days;
    }
    disp.textContent = days + ' day' + (days !== 1 ? 's' : '') + ' — $' + total.toFixed(2);
}
document.addEventListener('DOMContentLoaded', updateTotal);
document.addEventListener('DOMContentLoaded', function() {
    setupAddressAutocomplete({
        addressId: 'customer_address',
        cityId: 'customer_city',
        stateId: 'customer_state',
        zipId: 'customer_zip',
        boxId: 'customer_address_suggest'
    });
});
</script>

<?php
layout_end();
