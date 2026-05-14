<?php
/**
 * Dumpsters – Inventory Listing
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();

// ── Status filter ─────────────────────────────────────────────────────────────
$status_filter = trim($_GET['status'] ?? '');
$valid_statuses = ['available', 'reserved', 'in_use', 'maintenance'];

$where  = ['1=1'];
$params = [];

if ($status_filter !== '' && in_array($status_filter, $valid_statuses, true)) {
    $where[]  = 'd.status = ?';
    $params[] = $status_filter;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// ── Fetch dumpsters with active work order joined ─────────────────────────────
$dumpsters = db_fetchall(
    "SELECT d.*,
            wo.id        AS wo_id,
            wo.wo_number AS wo_number
     FROM dumpsters d
     LEFT JOIN work_orders wo
           ON  wo.dumpster_id = d.id
           AND wo.status NOT IN ('completed','canceled','picked_up')
     $where_sql
     ORDER BY d.unit_code ASC",
    $params
);

// ── Tab counts ────────────────────────────────────────────────────────────────
$status_counts = [];
$count_rows = db_fetchall("SELECT status, COUNT(*) AS cnt FROM dumpsters GROUP BY status");
foreach ($count_rows as $cr) {
    $status_counts[$cr['status']] = (int)$cr['cnt'];
}
$total_all = array_sum($status_counts);

$tabs = [
    ''            => ['label' => 'All',         'count' => $total_all],
    'available'   => ['label' => 'Available',   'count' => $status_counts['available']   ?? 0],
    'reserved'    => ['label' => 'Reserved',    'count' => $status_counts['reserved']    ?? 0],
    'in_use'      => ['label' => 'In Use',      'count' => $status_counts['in_use']      ?? 0],
    'maintenance' => ['label' => 'Maintenance', 'count' => $status_counts['maintenance'] ?? 0],
];

$stripeConfigured = trim(get_setting('stripe_secret_key', '')) !== '';
$can_edit = has_role('admin', 'office');

// All dumpsters for the bulk-edit modal (unfiltered)
$all_for_bulk = $can_edit
    ? db_fetchall(
        "SELECT id, unit_code, size, base_price, rental_days, extra_day_price,
                delivery_fee, pickup_fee, weekly_rate, monthly_rate
         FROM dumpsters ORDER BY unit_code ASC"
      )
    : [];

// ── Layout ────────────────────────────────────────────────────────────────────
layout_start('Inventory', 'inventory');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Dumpster Inventory</h5>
    <?php if ($can_edit): ?>
    <div class="d-flex gap-2 flex-wrap">
        <div class="dropdown">
            <button class="btn-tp-ghost btn-tp-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fa-solid fa-sliders me-1"></i> Actions
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <a class="dropdown-item" href="create.php">
                        <i class="fa-solid fa-plus me-2"></i>Add Dumpster
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#bulkEditModal">
                        <i class="fa-solid fa-table-cells me-2"></i>Bulk Edit Pricing
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <?php if ($stripeConfigured): ?>
                <li>
                    <form method="POST" action="sync_all_stripe.php" id="syncAllForm">
                        <?= csrf_field() ?>
                        <button type="submit"
                                class="dropdown-item"
                                onclick="return confirm('Sync all dumpsters to Stripe? This will create or update Stripe products and booking/recurring prices for all active dumpsters.')"
                                id="syncAllBtn">
                            <i class="fa-brands fa-stripe me-2"></i>Sync Inventory to Stripe
                        </button>
                    </form>
                </li>
                <?php else: ?>
                <li>
                    <span class="dropdown-item-text text-muted">
                        <i class="fa-brands fa-stripe me-2"></i>Stripe sync unavailable
                    </span>
                </li>
                <li>
                    <a class="dropdown-item" href="<?= e(APP_URL) ?>/modules/settings/index.php">
                        <i class="fa-solid fa-gear me-2"></i>Add Stripe key in Settings
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <a href="create.php" class="btn-tp-primary btn-tp-sm">
            <i class="fa-solid fa-plus"></i> Add Dumpster
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if ($stripeConfigured): ?>
<div class="alert alert-info py-2 px-3 mb-3" role="alert">
    <strong>Stripe catalog sync:</strong> this sync pushes each active dumpster to Stripe as a product and keeps booking, weekly, bi-weekly, and monthly prices aligned from your local inventory rates. Inline pricing edits auto-sync immediately.
</div>
<?php elseif ($can_edit): ?>
<div class="alert alert-warning py-2 px-3 mb-3" role="alert">
    <strong>Stripe sync is hidden until configured:</strong> add your Stripe secret key in <a href="<?= e(APP_URL) ?>/modules/settings/index.php" class="alert-link">Settings</a>, then use the new <strong>Actions</strong> menu to sync inventory pricing.
</div>
<?php endif; ?>

<!-- Status filter tabs -->
<div class="tp-filter-tabs mb-3">
    <?php foreach ($tabs as $key => $tab): ?>
    <a class="tp-filter-tab <?= $status_filter === $key ? 'active' : '' ?>"
       href="?status=<?= urlencode($key) ?>">
        <?= e($tab['label']) ?>
        <span class="tab-count"><?= $tab['count'] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Dumpsters table -->
<div class="tp-card">
    <?php if (empty($dumpsters)): ?>
        <p class="text-muted p-3 mb-0">No dumpsters found.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table tp-table mb-0">
            <thead>
                <tr>
                    <th>Unit Code</th>
                    <th>Size</th>
                    <th>Status</th>
                    <th>Condition</th>
                    <th>Base Price</th>
                    <th>Incl. Days</th>
                    <th>Extra Day</th>
                    <th>Current WO#</th>
                    <th>Notes</th>
                    <?php if ($can_edit): ?>
                    <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($dumpsters as $d): ?>
                <tr>
                    <td><strong><?= e($d['unit_code']) ?></strong></td>
                    <td><?= e($d['size']) ?></td>
                    <td><?= status_badge($d['status']) ?></td>
                    <td><?= e(ucfirst($d['condition'] ?? '')) ?></td>

                    <!-- Base Price (inline-editable for admins) -->
                    <?php if ($can_edit): ?>
                    <td class="price-cell"
                        data-id="<?= (int)$d['id'] ?>"
                        data-field="base_price"
                        data-val="<?= e(number_format((float)($d['base_price'] ?? 0), 2, '.', '')) ?>">
                        <span class="pv">
                            <?php if (!empty($d['base_price']) && (float)$d['base_price'] > 0): ?>
                                <strong>$<?= number_format((float)$d['base_price'], 2) ?></strong>
                            <?php elseif (!empty($d['daily_rate'])): ?>
                                <span class="text-muted" title="Legacy daily rate">$<?= number_format((float)$d['daily_rate'], 2) ?>/day</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </span>
                        <span class="pe-hint" title="Click to edit"><i class="fa-solid fa-pencil fa-2xs"></i></span>
                    </td>
                    <?php else: ?>
                    <td>
                        <?php if (!empty($d['base_price']) && (float)$d['base_price'] > 0): ?>
                            <strong>$<?= number_format((float)$d['base_price'], 2) ?></strong>
                        <?php elseif (!empty($d['daily_rate'])): ?>
                            <span class="text-muted" title="Legacy daily rate">$<?= number_format((float)$d['daily_rate'], 2) ?>/day</span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>

                    <!-- Incl. Days (inline-editable for admins) -->
                    <?php if ($can_edit): ?>
                    <td class="price-cell"
                        data-id="<?= (int)$d['id'] ?>"
                        data-field="rental_days"
                        data-val="<?= (int)($d['rental_days'] ?? 7) ?>">
                        <span class="pv">
                            <?= !empty($d['rental_days']) ? (int)$d['rental_days'] . ' days' : '<span class="text-muted">—</span>' ?>
                        </span>
                        <span class="pe-hint" title="Click to edit"><i class="fa-solid fa-pencil fa-2xs"></i></span>
                    </td>
                    <?php else: ?>
                    <td><?= !empty($d['rental_days']) ? (int)$d['rental_days'] . ' days' : '<span class="text-muted">—</span>' ?></td>
                    <?php endif; ?>

                    <!-- Extra Day (inline-editable for admins) -->
                    <?php if ($can_edit): ?>
                    <td class="price-cell"
                        data-id="<?= (int)$d['id'] ?>"
                        data-field="extra_day_price"
                        data-val="<?= $d['extra_day_price'] !== null ? e(number_format((float)$d['extra_day_price'], 2, '.', '')) : '' ?>">
                        <span class="pv">
                            <?= ($d['extra_day_price'] !== null && (float)$d['extra_day_price'] > 0)
                                ? '$' . number_format((float)$d['extra_day_price'], 2)
                                : '<span class="text-muted">—</span>' ?>
                        </span>
                        <span class="pe-hint" title="Click to edit"><i class="fa-solid fa-pencil fa-2xs"></i></span>
                    </td>
                    <?php else: ?>
                    <td><?= ($d['extra_day_price'] !== null && (float)$d['extra_day_price'] > 0) ? '$' . number_format((float)$d['extra_day_price'], 2) : '<span class="text-muted">—</span>' ?></td>
                    <?php endif; ?>

                    <td>
                        <?php if (!empty($d['wo_id'])): ?>
                            <a href="<?= e(APP_URL) ?>/modules/work_orders/view.php?id=<?= (int)$d['wo_id'] ?>">
                                <?= e($d['wo_number']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($d['notes'])): ?>
                            <span class="tp-text-tooltip" title="<?= e($d['notes']) ?>">
                                <?= e(mb_strimwidth($d['notes'], 0, 40, '…')) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($can_edit): ?>
                    <td class="text-end">
                        <a href="edit.php?id=<?= (int)$d['id'] ?>"
                           class="btn-tp-ghost btn-tp-sm">
                            <i class="fa-solid fa-pencil"></i> Edit
                        </a>
                        <?php $sp_url = stripe_dashboard_url($d['stripe_product_id'] ?? ''); if ($sp_url): ?>
                        <a href="<?= e($sp_url) ?>" target="_blank" rel="noopener noreferrer"
                           class="btn-tp-ghost btn-tp-sm">
                            <i class="fa-brands fa-stripe me-1"></i> Stripe
                        </a>
                        <?php endif; ?>
                        <a href="delete.php?id=<?= (int)$d['id'] ?>"
                           class="btn-tp-ghost btn-tp-sm text-danger"
                           onclick="return confirm('Delete dumpster <?= e($d['unit_code']) ?>?')">
                            <i class="fa-solid fa-trash"></i> Delete
                        </a>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($can_edit): ?>
<!-- ── Bulk Edit Pricing Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="bulkEditModal" tabindex="-1" aria-labelledby="bulkEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="background:var(--dark2,#161b27);border:1px solid var(--steel,#374151);">
            <div class="modal-header" style="border-bottom:1px solid var(--steel,#374151);">
                <h6 class="modal-title mb-0" id="bulkEditModalLabel">
                    <i class="fa-solid fa-table-cells me-2"></i>Bulk Edit Pricing
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="px-3 py-2" style="font-size:.82rem;color:var(--gray,#9ca3af);border-bottom:1px solid var(--steel,#374151);">
                    Edit any field below. Only changed rows are sent on save.
                    <?php if ($stripeConfigured): ?>
                    <i class="fa-brands fa-stripe ms-2"></i> All saved rows will auto-sync to Stripe.
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table tp-table mb-0" id="bulkEditTable">
                        <thead>
                            <tr>
                                <th>Unit</th>
                                <th>Size</th>
                                <th>Base Price ($)</th>
                                <th>Incl. Days</th>
                                <th>Extra Day ($)</th>
                                <th>Delivery ($)</th>
                                <th>Pickup ($)</th>
                                <th>Weekly ($)</th>
                                <th>Monthly ($)</th>
                                <th style="min-width:80px;"></th>
                            </tr>
                        </thead>
                        <tbody id="bulkEditBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--steel,#374151);gap:.5rem;">
                <span id="bulkSaveStatus" style="font-size:.83rem;flex:1 1 auto;"></span>
                <button type="button" class="btn-tp-ghost btn-tp-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-tp-primary btn-tp-sm" id="bulkSaveBtn">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Save All Changes
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* ── Inline pricing cells ─────────────────────────────────────────────────── */
td.price-cell {
    cursor: pointer;
    white-space: nowrap;
}
td.price-cell .pe-hint {
    opacity: 0;
    font-size: .68rem;
    color: var(--gray, #9ca3af);
    transition: opacity .15s;
    vertical-align: middle;
    margin-left: 4px;
}
td.price-cell:hover .pe-hint,
td.price-cell.editing .pe-hint { opacity: 1; }
.price-inline-input {
    width: 80px;
    background: var(--dark, #0f1117);
    border: 1px solid var(--orange, #f97316);
    border-radius: 4px;
    color: inherit;
    padding: 2px 6px;
    font-size: .85rem;
}
.price-inline-input:focus { outline: none; }
.price-inline-save,
.price-inline-cancel {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0 3px;
    font-size: .8rem;
    vertical-align: middle;
}
.price-inline-save  { color: #4ade80; }
.price-inline-cancel { color: var(--orange, #f97316); }

/* ── Bulk edit modal inputs ───────────────────────────────────────────────── */
.be-input {
    width: 88px;
    background: var(--dark, #0f1117);
    border: 1px solid var(--steel, #374151);
    border-radius: 4px;
    color: inherit;
    padding: 3px 6px;
    font-size: .83rem;
    transition: border-color .15s;
}
.be-input:focus {
    outline: none;
    border-color: var(--orange, #f97316);
}
.be-input.changed {
    border-color: #60a5fa;
}
</style>

<script>
var TP_CSRF    = <?= json_encode(csrf_token()) ?>;
var TP_STRIPE  = <?= json_encode($stripeConfigured) ?>;
var TP_BULK    = <?= json_encode(array_map(function($d) {
    return [
        'id'            => (int)$d['id'],
        'unit_code'     => $d['unit_code'],
        'size'          => $d['size'],
        'base_price'    => number_format((float)($d['base_price']    ?? 0), 2, '.', ''),
        'rental_days'   => (int)($d['rental_days']   ?? 7),
        'extra_day_price' => $d['extra_day_price'] !== null ? number_format((float)$d['extra_day_price'], 2, '.', '') : '',
        'delivery_fee'  => number_format((float)($d['delivery_fee'] ?? 0), 2, '.', ''),
        'pickup_fee'    => number_format((float)($d['pickup_fee']   ?? 0), 2, '.', ''),
        'weekly_rate'   => number_format((float)($d['weekly_rate']  ?? 0), 2, '.', ''),
        'monthly_rate'  => number_format((float)($d['monthly_rate'] ?? 0), 2, '.', ''),
    ];
}, $all_for_bulk)) ?>;

function escHtml(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtCellVal(field, val) {
    if (val === null || val === '') return '<span class="text-muted">—</span>';
    if (field === 'rental_days') return parseInt(val, 10) + ' days';
    var n = parseFloat(val);
    if (isNaN(n) || n <= 0) return '<span class="text-muted">—</span>';
    return '<strong>$' + n.toFixed(2) + '</strong>';
}

// ── Inline cell editing ────────────────────────────────────────────────────
document.querySelectorAll('td.price-cell').forEach(function(cell) {
    var display = cell.querySelector('.pv');
    var hint    = cell.querySelector('.pe-hint');

    function startEdit() {
        if (cell.classList.contains('editing')) return;
        cell.classList.add('editing');

        var field  = cell.dataset.field;
        var origVal = cell.dataset.val;
        var isDay  = field === 'rental_days';

        var inp = document.createElement('input');
        inp.type      = 'number';
        inp.step      = isDay ? '1' : '0.01';
        inp.min       = isDay ? '1' : '0';
        inp.value     = origVal;
        inp.className = 'price-inline-input';

        var okBtn = document.createElement('button');
        okBtn.innerHTML  = '<i class="fa-solid fa-check"></i>';
        okBtn.className  = 'price-inline-save';
        okBtn.title      = 'Save';

        var xBtn = document.createElement('button');
        xBtn.innerHTML  = '<i class="fa-solid fa-xmark"></i>';
        xBtn.className  = 'price-inline-cancel';
        xBtn.title      = 'Cancel';

        display.style.display = 'none';
        if (hint) hint.style.visibility = 'hidden';
        cell.appendChild(inp);
        cell.appendChild(okBtn);
        cell.appendChild(xBtn);
        inp.focus(); inp.select();

        function cancel() {
            inp.remove(); okBtn.remove(); xBtn.remove();
            display.style.display = '';
            if (hint) hint.style.visibility = '';
            cell.classList.remove('editing');
        }

        function save() {
            var newVal = inp.value.trim();
            if (newVal === origVal) { cancel(); return; }

            inp.disabled     = true;
            xBtn.style.display = 'none';
            okBtn.innerHTML  = '<i class="fa-solid fa-spinner fa-spin"></i>';
            okBtn.style.color = 'var(--gray)';

            var fields = {};
            fields[field] = newVal === '' ? null : newVal;

            fetch('inline_update.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ [TP_CSRF.constructor.name]: TP_CSRF, tp_csrf: TP_CSRF, id: parseInt(cell.dataset.id, 10), fields: fields }),
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) throw new Error(data.message || 'Save failed');

                cell.dataset.val = newVal;
                display.innerHTML = fmtCellVal(field, newVal);
                inp.remove(); xBtn.remove();

                var ic = document.createElement('span');
                ic.style.cssText = 'font-size:.78rem;margin-left:4px;';
                var s = data.stripe || {};
                if (s.active && s.synced) {
                    ic.innerHTML = '<i class="fa-brands fa-stripe" style="color:#4ade80;" title="Synced to Stripe"></i>';
                } else if (s.active && !s.synced) {
                    ic.innerHTML = '<i class="fa-solid fa-check" style="color:#4ade80;" title="Saved — ' + escHtml(s.error || 'Stripe sync failed') + '"></i>';
                } else {
                    ic.innerHTML = '<i class="fa-solid fa-check" style="color:#4ade80;"></i>';
                }
                okBtn.replaceWith(ic);
                display.style.display = '';
                if (hint) hint.style.visibility = '';
                cell.classList.remove('editing');
                setTimeout(function() { ic.remove(); }, 2200);
            })
            .catch(function(err) {
                okBtn.innerHTML  = '<i class="fa-solid fa-triangle-exclamation"></i>';
                okBtn.style.color = '#f87171';
                okBtn.title       = err.message;
                inp.disabled      = false;
                xBtn.style.display = '';
            });
        }

        okBtn.addEventListener('click', save);
        xBtn.addEventListener('click', cancel);
        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Enter')  { e.preventDefault(); save();   }
            if (e.key === 'Escape') { e.preventDefault(); cancel(); }
        });
    }

    cell.addEventListener('click', function(e) {
        if (e.target.closest('.price-inline-save,.price-inline-cancel,.price-inline-input')) return;
        startEdit();
    });
});

// ── Bulk edit modal ────────────────────────────────────────────────────────
document.getElementById('bulkEditModal').addEventListener('show.bs.modal', function() {
    var tbody = document.getElementById('bulkEditBody');
    tbody.innerHTML = '';

    TP_BULK.forEach(function(d) {
        var tr = document.createElement('tr');
        tr.dataset.id = d.id;

        function numInput(name, val, step, min, placeholder) {
            return '<input type="number" class="be-input" step="' + step + '" min="' + min + '"'
                 + ' name="' + name + '" value="' + escHtml(val) + '"'
                 + ' data-orig="' + escHtml(val) + '"'
                 + (placeholder ? ' placeholder="' + placeholder + '"' : '') + '>';
        }

        tr.innerHTML =
            '<td><strong>' + escHtml(d.unit_code) + '</strong></td>' +
            '<td>' + escHtml(d.size) + '</td>' +
            '<td>' + numInput('base_price',      d.base_price,      '0.01', '0', '0.00') + '</td>' +
            '<td>' + numInput('rental_days',     d.rental_days,     '1',    '1', '7')    + '</td>' +
            '<td>' + numInput('extra_day_price', d.extra_day_price, '0.01', '0', '—')    + '</td>' +
            '<td>' + numInput('delivery_fee',    d.delivery_fee,    '0.01', '0', '0.00') + '</td>' +
            '<td>' + numInput('pickup_fee',      d.pickup_fee,      '0.01', '0', '0.00') + '</td>' +
            '<td>' + numInput('weekly_rate',     d.weekly_rate,     '0.01', '0', '0.00') + '</td>' +
            '<td>' + numInput('monthly_rate',    d.monthly_rate,    '0.01', '0', '0.00') + '</td>' +
            '<td class="be-status"></td>';

        tbody.appendChild(tr);
    });

    // Highlight changed cells
    tbody.addEventListener('input', function(e) {
        var inp = e.target;
        if (!inp.classList.contains('be-input')) return;
        inp.classList.toggle('changed', inp.value !== inp.dataset.orig);
    });

    document.getElementById('bulkSaveStatus').textContent = '';
    document.getElementById('bulkSaveBtn').disabled = false;
    document.getElementById('bulkSaveBtn').innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save All Changes';
});

document.getElementById('bulkSaveBtn').addEventListener('click', function() {
    var rows = [];
    document.querySelectorAll('#bulkEditBody tr').forEach(function(tr) {
        var id = parseInt(tr.dataset.id, 10);
        var row = {id: id};
        var changed = false;
        tr.querySelectorAll('.be-input').forEach(function(inp) {
            if (inp.value !== inp.dataset.orig) {
                row[inp.name] = inp.value === '' ? null : inp.value;
                changed = true;
            }
        });
        if (changed) rows.push(row);
    });

    if (rows.length === 0) {
        document.getElementById('bulkSaveStatus').textContent = 'No changes detected.';
        return;
    }

    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving…';
    document.getElementById('bulkSaveStatus').textContent = '';

    fetch('inline_update.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({tp_csrf: TP_CSRF, rows: rows}),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save All Changes';

        (data.updated || []).forEach(function(id) {
            var tr = document.querySelector('#bulkEditBody tr[data-id="' + id + '"]');
            if (!tr) return;
            var sc = tr.querySelector('.be-status');
            var s  = (data.stripe_results || {})[id] || {};
            if (s.active) {
                if (s.synced) {
                    sc.innerHTML = '<span style="color:#4ade80;font-size:.8rem;"><i class="fa-brands fa-stripe me-1"></i>Synced</span>';
                } else {
                    sc.innerHTML = '<span style="color:#fb923c;font-size:.8rem;" title="' + escHtml(s.error || '') + '"><i class="fa-solid fa-check me-1"></i>Saved</span>';
                }
            } else {
                sc.innerHTML = '<span style="color:#4ade80;font-size:.8rem;"><i class="fa-solid fa-check me-1"></i>Saved</span>';
            }
            tr.querySelectorAll('.be-input').forEach(function(inp) {
                inp.dataset.orig = inp.value;
                inp.classList.remove('changed');
            });
        });

        var errMsg = (data.errors || []).length ? ' Errors: ' + data.errors.join('; ') : '';
        document.getElementById('bulkSaveStatus').innerHTML =
            '<span style="color:#4ade80;">' + escHtml(data.message || '') + '</span>' + escHtml(errMsg);

        // Reload after a short pause so inline table reflects new values
        setTimeout(function() { location.reload(); }, 1600);
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save All Changes';
        document.getElementById('bulkSaveStatus').innerHTML =
            '<span style="color:#f87171;">Save failed: ' + escHtml(err.message) + '</span>';
    });
});
</script>
<?php endif; ?>

<?php
layout_end();
