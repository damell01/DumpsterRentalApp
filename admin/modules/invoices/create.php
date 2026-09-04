<?php
/**
 * Invoices – Create
 * Trash Panda Roll-Offs
 *
 * Supports custom line items (description, qty, unit price, rate type)
 * and optional Stripe Payment Link generation.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office');

$pdo = get_db();

// ── Pre-fill from customer if provided ───────────────────────────────────────
$prefill_customer_id = (int)($_GET['customer_id'] ?? 0);
$prefill = [
    'cust_name'    => '',
    'cust_email'   => '',
    'cust_phone'   => '',
    'cust_address' => '',
];
if ($prefill_customer_id > 0) {
    $pc = db_fetch('SELECT * FROM customers WHERE id = ? LIMIT 1', [$prefill_customer_id]);
    if ($pc) {
        $prefill['cust_name']    = $pc['name']    ?? '';
        $prefill['cust_email']   = $pc['email']   ?? '';
        $prefill['cust_phone']   = $pc['phone']   ?? '';
        $prefill['cust_address'] = $pc['billing_address'] ?? $pc['address'] ?? '';
    } else {
        $prefill_customer_id = 0;
    }
}

$default_terms = get_setting('invoice_terms', 'Payment is due within 30 days of invoice date. Thank you for your business!');

$errors = [];

$old = array_merge($prefill, [
    'customer_id'  => $prefill_customer_id ?: '',
    'cust_address' => $prefill['cust_address'],
    'due_date'     => date('Y-m-d', strtotime('+30 days')),
    'notes'        => '',
    'terms'        => $default_terms,
    'status'       => 'draft',
]);

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $old = [
        'customer_id'  => (int)($_POST['customer_id']  ?? 0) ?: null,
        'cust_name'    => trim($_POST['cust_name']      ?? ''),
        'cust_email'   => trim($_POST['cust_email']     ?? ''),
        'cust_phone'   => trim($_POST['cust_phone']     ?? ''),
        'cust_address' => trim($_POST['cust_address']   ?? ''),
        'due_date'     => trim($_POST['due_date']       ?? ''),
        'notes'        => trim($_POST['notes']          ?? ''),
        'terms'        => trim($_POST['terms']          ?? ''),
        'status'       => trim($_POST['status']         ?? 'draft'),
    ];

    // Line items (parallel arrays from form)
    $item_descs   = $_POST['item_desc']      ?? [];
    $item_qtys    = $_POST['item_qty']       ?? [];
    $item_prices  = $_POST['item_price']     ?? [];
    $item_types   = $_POST['item_rate_type'] ?? [];

    // Validation
    if ($old['cust_name'] === '') {
        $errors[] = 'Customer name is required.';
    }
    if (!in_array($old['status'], ['draft', 'sent', 'paid', 'void'], true)) {
        $errors[] = 'Invalid status.';
    }
    if (empty($item_descs) || count(array_filter(array_map('trim', $item_descs))) === 0) {
        $errors[] = 'At least one line item is required.';
    }

    // Build validated line items
    $items = [];
    foreach ($item_descs as $i => $desc) {
        $desc  = trim($desc);
        if ($desc === '') continue;
        $qty   = max(0, (float)($item_qtys[$i]   ?? 1));
        $price = max(0, (float)($item_prices[$i]  ?? 0));
        $type  = in_array($item_types[$i] ?? '', ['fixed','daily','weekly','monthly'], true)
                     ? $item_types[$i] : 'fixed';
        $items[] = [
            'description' => $desc,
            'quantity'    => $qty,
            'unit_price'  => $price,
            'amount'      => round($qty * $price, 2),
            'rate_type'   => $type,
        ];
    }

    if (empty($items)) {
        $errors[] = 'At least one valid line item with a description is required.';
    }

    if (empty($errors)) {
        // Calculate totals — tax is handled by Stripe, not the app
        $subtotal = array_sum(array_column($items, 'amount'));

        // Card processing fee (only when payment method is card/ACH)
        $payment_method_post = trim($_POST['payment_method'] ?? 'stripe');
        $card_fee_pct = in_array($payment_method_post, ['stripe', 'ach', 'card'], true)
            ? (float)get_setting('card_fee_percent', '0') : 0.0;
        $card_fee_amount = round($subtotal * $card_fee_pct / 100, 2);
        $total    = round($subtotal + $card_fee_amount, 2);

        $invoice_number = next_number('INV', 'invoices', 'invoice_number');

        // Insert the invoice first (without payment link) to get the real ID
        $inv_id = db_insert('invoices', [
            'invoice_number'      => $invoice_number,
            'customer_id'         => $old['customer_id'],
            'cust_name'           => $old['cust_name'],
            'cust_email'          => $old['cust_email'],
            'cust_phone'          => $old['cust_phone'],
            'cust_address'        => $old['cust_address'],
            'subtotal'            => $subtotal,
            'tax_rate'            => 0,
            'tax_amount'          => 0,
            'card_fee_rate'       => $card_fee_pct,
            'card_fee_amount'     => $card_fee_amount,
            'total'               => $total,
            'notes'               => $old['notes'],
            'terms'               => $old['terms'],
            'status'              => $old['status'],
            'due_date'            => $old['due_date'] ?: null,
            'stripe_payment_link' => null,
            'stripe_session_id'   => null,
            'created_by'          => $_SESSION['user_id'],
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        // Generate stable payment link if Stripe is configured
        // The actual Stripe Checkout session is created on-demand when customer clicks (see /pay-invoice.php)
        // This ensures the 24h expiry window starts from when they click, not when invoice was created
        $stripe_key = trim(get_setting('stripe_secret_key', ''));
        if ($stripe_key !== '' && str_starts_with($stripe_key, 'sk_') && $total > 0 && (int)$inv_id > 0) {
            require_once INC_PATH . '/stripe.php';
            db_update('invoices', [
                'stripe_payment_link' => invoice_pay_url((int)$inv_id),
                'updated_at'          => date('Y-m-d H:i:s'),
            ], 'id', (int)$inv_id);
        }

        // Insert line items
        foreach ($items as $it) {
            db_insert('invoice_items', [
                'invoice_id'  => (int)$inv_id,
                'description' => $it['description'],
                'quantity'    => $it['quantity'],
                'unit_price'  => $it['unit_price'],
                'amount'      => $it['amount'],
                'rate_type'   => $it['rate_type'],
            ]);
        }

        log_activity('create_invoice', 'Created invoice ' . $invoice_number, 'invoice', (int)$inv_id);

        if (!empty($errors)) {
            // Had Stripe error but still saved — warn
            flash_warning('Invoice ' . $invoice_number . ' saved, but: ' . implode(' ', $errors));
        } else {
            flash_success('Invoice ' . $invoice_number . ' created successfully.');
        }
        redirect('view.php?id=' . $inv_id);
    }
}

// ── Customers list for autocomplete ──────────────────────────────────────────
$customers = db_fetchall('SELECT id, name, email, phone, billing_address FROM customers ORDER BY name ASC');

// ── Inventory for quick item lookup ──────────────────────────────────────────
$dumpsters = db_fetchall(
    'SELECT id, unit_code, size, daily_rate, weekly_rate, monthly_rate FROM dumpsters WHERE active = 1 ORDER BY unit_code ASC'
);

layout_start('New Invoice', 'invoices');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">New Invoice</h1>
    <a href="index.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to Invoices
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <strong>Please fix the following errors:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" action="" id="invoiceForm">
    <?= csrf_field() ?>

    <div class="row g-4">

        <!-- LEFT: Customer + Items -->
        <div class="col-lg-8">

            <!-- Customer Information -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-user me-2"></i>Bill To</h5>
                </div>
                <div class="card-body">
                    <!-- Quick-fill from existing customer -->
                    <div class="mb-3">
                        <label class="form-label">Fill from existing customer</label>
                        <select id="customerSelect" class="form-select">
                            <option value="">— Select a customer to auto-fill —</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                    data-name="<?= e($c['name']) ?>"
                                    data-email="<?= e($c['email'] ?? '') ?>"
                                    data-phone="<?= e($c['phone'] ?? '') ?>"
                                    data-address="<?= e($c['billing_address'] ?? '') ?>"
                                    <?= ((int)($old['customer_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                                <?= e($c['name']) ?><?= $c['email'] ? ' — ' . e($c['email']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="customer_id" id="hCustomerId"
                           value="<?= (int)($old['customer_id'] ?? 0) ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                            <input type="text" name="cust_name" class="form-control"
                                   value="<?= e($old['cust_name']) ?>" required
                                   placeholder="Full name or company">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="cust_email" class="form-control"
                                   value="<?= e($old['cust_email']) ?>"
                                   placeholder="customer@example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="cust_phone" class="form-control"
                                   value="<?= e($old['cust_phone']) ?>"
                                   placeholder="(555) 000-0000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Billing Address</label>
                            <input type="text" name="cust_address" class="form-control"
                                   value="<?= e($old['cust_address']) ?>"
                                   placeholder="123 Main St, City, ST 00000">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Line Items -->
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="fas fa-list me-2"></i>Line Items</h5>
                    <div class="d-flex gap-2">
                        <!-- Quick add from inventory -->
                        <?php if (!empty($dumpsters)): ?>
                        <select id="quickAddUnit" class="form-select form-select-sm" style="max-width:220px;">
                            <option value="">+ Add inventory unit…</option>
                            <?php foreach ($dumpsters as $du): ?>
                            <option value="<?= (int)$du['id'] ?>"
                                    data-code="<?= e($du['unit_code']) ?>"
                                    data-size="<?= e($du['size']) ?>"
                                    data-daily="<?= e($du['daily_rate'] ?? 0) ?>"
                                    data-weekly="<?= e($du['weekly_rate'] ?? 0) ?>"
                                    data-monthly="<?= e($du['monthly_rate'] ?? 0) ?>">
                                <?= e($du['unit_code']) ?> (<?= e($du['size']) ?>)
                                — D:$<?= number_format((float)$du['daily_rate'], 2) ?>
                                <?= $du['weekly_rate']  > 0 ? ' W:$' . number_format((float)$du['weekly_rate'],  2) : '' ?>
                                <?= $du['monthly_rate'] > 0 ? ' M:$' . number_format((float)$du['monthly_rate'], 2) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <button type="button" class="btn-tp-ghost btn-tp-sm" id="btnAddRow">
                            <i class="fa-solid fa-plus"></i> Add Row
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table tp-table mb-0" id="itemsTable">
                            <thead>
                                <tr>
                                    <th style="width:40%">Description</th>
                                    <th style="width:10%">Qty</th>
                                    <th style="width:15%">Unit Price</th>
                                    <th style="width:13%">Rate Type</th>
                                    <th style="width:12%">Amount</th>
                                    <th style="width:10%"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                                <!-- rows injected by JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-sticky-note me-2"></i>Notes &amp; Terms</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Notes (visible on invoice)</label>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="Optional notes for the customer…"><?= e($old['notes']) ?></textarea>
                    </div>
                    <div>
                        <label class="form-label">Terms</label>
                        <textarea name="terms" class="form-control" rows="3"><?= e($old['terms']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Totals + Settings -->
        <div class="col-lg-4">

            <!-- Invoice Settings -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-cog me-2"></i>Invoice Settings</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach (['draft' => 'Draft', 'sent' => 'Sent', 'paid' => 'Paid', 'void' => 'Void'] as $val => $lbl): ?>
                            <option value="<?= $val ?>" <?= ($old['status'] === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" id="inv_payment_method" class="form-select" onchange="updateCalcTotal()">
                            <option value="stripe">Card (Stripe)</option>
                            <option value="ach">ACH / Bank Transfer</option>
                            <option value="cash">Cash</option>
                            <option value="check">Check</option>
                        </select>
                        <?php if ((float)get_setting('card_fee_percent', '0') > 0): ?>
                        <div class="form-text">
                            A <?= e(number_format((float)get_setting('card_fee_percent', '0'), 2)) ?>% card fee is added for Card/ACH.
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="due_date" class="form-control"
                               value="<?= e($old['due_date']) ?>">
                    </div>
                </div>
            </div>

            <!-- Totals -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-calculator me-2"></i>Totals</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-muted">Subtotal</td>
                            <td class="text-end" id="calcSubtotal">$0.00</td>
                        </tr>
                        <tr id="calcFeeRow" style="display:none;">
                            <td class="text-muted" id="calcFeeLabel">Card Fee (0%)</td>
                            <td class="text-end" id="calcFeeAmt">$0.00</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Total</td>
                            <td class="text-end fw-bold fs-5" id="calcTotal">$0.00</td>
                        </tr>
                    </table>
                    <small class="text-muted d-block mt-2">
                        <i class="fa-brands fa-stripe me-1"></i>
                        Tax is handled automatically by Stripe based on your account settings.
                    </small>
                </div>
            </div>

            <!-- Stripe note -->
            <?php if (trim(get_setting('stripe_secret_key', '')) !== ''): ?>
            <div class="alert alert-info" style="font-size:.85rem;">
                <i class="fa-brands fa-stripe me-1"></i>
                <strong>Stripe:</strong> A payment link will be automatically generated for this invoice.
            </div>
            <?php endif; ?>

            <!-- Submit -->
            <div class="d-grid gap-2">
                <button type="submit" class="btn-tp-primary">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Invoice
                </button>
                <a href="index.php" class="btn-tp-ghost text-center">Cancel</a>
            </div>
        </div>

    </div>
</form>

<script>
// ── Line item row template ────────────────────────────────────────────────────
var rowCount = 0;

function fmtMoney(n) {
    return '$' + (Math.round(n * 100) / 100).toFixed(2);
}

function addRow(desc, qty, price, type) {
    desc  = desc  || '';
    qty   = qty   != null ? qty   : 1;
    price = price != null ? price : 0;
    type  = type  || 'fixed';
    rowCount++;
    var i = rowCount;
    var html = '<tr id="row' + i + '">'
        + '<td><input type="text" class="form-control form-control-sm" name="item_desc[]" value="' + escAttr(desc) + '" placeholder="Description…" required></td>'
        + '<td><input type="number" class="form-control form-control-sm item-qty" name="item_qty[]" value="' + qty + '" min="0" step="0.01"></td>'
        + '<td><input type="number" class="form-control form-control-sm item-price" name="item_price[]" value="' + price.toFixed(2) + '" min="0" step="0.01" placeholder="0.00"></td>'
        + '<td><select class="form-select form-select-sm" name="item_rate_type[]">'
        + '<option value="fixed"'   + (type==='fixed'   ? ' selected':'') + '>Fixed</option>'
        + '<option value="daily"'   + (type==='daily'   ? ' selected':'') + '>Daily</option>'
        + '<option value="weekly"'  + (type==='weekly'  ? ' selected':'') + '>Weekly</option>'
        + '<option value="monthly"' + (type==='monthly' ? ' selected':'') + '>Monthly</option>'
        + '</select></td>'
        + '<td class="item-amt fw-semibold" style="vertical-align:middle;">$0.00</td>'
        + '<td class="text-end"><button type="button" class="btn-tp-ghost btn-tp-xs text-danger" onclick="removeRow(' + i + ')" title="Remove"><i class="fa-solid fa-trash"></i></button></td>'
        + '</tr>';
    document.getElementById('itemsBody').insertAdjacentHTML('beforeend', html);
    recalcRow(i);
}

function removeRow(i) {
    var r = document.getElementById('row' + i);
    if (r) r.remove();
    recalcAll();
}

function recalcRow(i) {
    var row = document.getElementById('row' + i);
    if (!row) return 0;
    var qty   = parseFloat(row.querySelector('.item-qty').value)   || 0;
    var price = parseFloat(row.querySelector('.item-price').value) || 0;
    var amt   = Math.round(qty * price * 100) / 100;
    row.querySelector('.item-amt').textContent = fmtMoney(amt);
    return amt;
}

var CARD_FEE_PCT = <?= (float)get_setting('card_fee_percent', '0') ?>;

function updateCalcTotal() { recalcAll(); }

function recalcAll() {
    var subtotal = 0;
    document.querySelectorAll('#itemsBody tr').forEach(function(row) {
        var id = parseInt(row.id.replace('row', ''), 10);
        subtotal += recalcRow(id);
    });
    document.getElementById('calcSubtotal').textContent = fmtMoney(subtotal);

    var pm = document.getElementById('inv_payment_method');
    var isCard = pm && (pm.value === 'stripe' || pm.value === 'ach');
    var feeAmt = (isCard && CARD_FEE_PCT > 0) ? Math.round(subtotal * CARD_FEE_PCT / 100 * 100) / 100 : 0;
    var feeRow = document.getElementById('calcFeeRow');
    if (feeAmt > 0) {
        document.getElementById('calcFeeLabel').textContent = 'Card Fee (' + CARD_FEE_PCT.toFixed(2) + '%)';
        document.getElementById('calcFeeAmt').textContent  = fmtMoney(feeAmt);
        feeRow.style.display = '';
    } else {
        feeRow.style.display = 'none';
    }

    document.getElementById('calcTotal').textContent = fmtMoney(subtotal + feeAmt);
}

function escAttr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Add initial empty row
addRow();

// Wire up recalculation on any input change
document.getElementById('itemsBody').addEventListener('input', function(e) {
    var row = e.target.closest('tr');
    if (row) recalcAll();
});
document.getElementById('btnAddRow').addEventListener('click', function() { addRow(); });

// Quick-add from inventory
var quickAdd = document.getElementById('quickAddUnit');
if (quickAdd) {
    quickAdd.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        if (!opt.value) return;
        var code    = opt.dataset.code  || '';
        var size    = opt.dataset.size  || '';
        var daily   = parseFloat(opt.dataset.daily)   || 0;
        var weekly  = parseFloat(opt.dataset.weekly)  || 0;
        var monthly = parseFloat(opt.dataset.monthly) || 0;

        // Default to daily; if weekly > 0 show a quick pick dialog
        var rateType  = 'daily';
        var ratePrice = daily;
        if (weekly > 0 || monthly > 0) {
            var choice = prompt(
                'Select rate for ' + code + ' (' + size + '):\n'
                + '1 = Daily ($' + daily.toFixed(2) + ')\n'
                + (weekly  > 0 ? '2 = Weekly ($'  + weekly.toFixed(2)  + ')\n' : '')
                + (monthly > 0 ? '3 = Monthly ($' + monthly.toFixed(2) + ')\n' : '')
                , '1'
            );
            if (choice === '2' && weekly  > 0) { rateType = 'weekly';  ratePrice = weekly; }
            if (choice === '3' && monthly > 0) { rateType = 'monthly'; ratePrice = monthly; }
        }
        addRow(code + ' — ' + size + ' Dumpster Rental', 1, ratePrice, rateType);
        this.value = '';
        recalcAll();
    });
}

// Customer auto-fill
var custSel = document.getElementById('customerSelect');
if (custSel) {
    custSel.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        if (!opt.value) return;
        document.querySelector('[name="cust_name"]').value    = opt.dataset.name    || '';
        document.querySelector('[name="cust_email"]').value   = opt.dataset.email   || '';
        document.querySelector('[name="cust_phone"]').value   = opt.dataset.phone   || '';
        document.querySelector('[name="cust_address"]').value = opt.dataset.address || '';
        document.getElementById('hCustomerId').value          = opt.value;
    });
}
</script>

<?php layout_end(); ?>
