<?php
/**
 * Invoices – Edit
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { flash_error('Invalid invoice ID.'); redirect('index.php'); }

$inv = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$id]);
if (!$inv) { flash_error('Invoice not found.'); redirect('index.php'); }

$existing_items = db_fetchall(
    'SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC', [$id]
);

$errors = [];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $upd = [
        'customer_id'  => (int)($_POST['customer_id'] ?? 0) ?: null,
        'cust_name'    => trim($_POST['cust_name']    ?? ''),
        'cust_email'   => trim($_POST['cust_email']   ?? ''),
        'cust_phone'   => trim($_POST['cust_phone']   ?? ''),
        'cust_address' => trim($_POST['cust_address'] ?? ''),
        'due_date'     => trim($_POST['due_date']     ?? ''),
        'notes'        => trim($_POST['notes']        ?? ''),
        'terms'        => trim($_POST['terms']        ?? ''),
        'status'       => trim($_POST['status']       ?? 'draft'),
        'regen_stripe' => !empty($_POST['regen_stripe']) ? '1' : '',
    ];

    $item_descs   = $_POST['item_desc']      ?? [];
    $item_qtys    = $_POST['item_qty']       ?? [];
    $item_prices  = $_POST['item_price']     ?? [];
    $item_types   = $_POST['item_rate_type'] ?? [];

    if ($upd['cust_name'] === '') $errors[] = 'Customer name is required.';
    if (!in_array($upd['status'], ['draft', 'sent', 'paid', 'void'], true)) $errors[] = 'Invalid status.';

    $items = [];
    foreach ($item_descs as $i => $desc) {
        $desc = trim($desc);
        if ($desc === '') continue;
        $qty   = max(0, (float)($item_qtys[$i]  ?? 1));
        $price = max(0, (float)($item_prices[$i] ?? 0));
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
    if (empty($items)) $errors[] = 'At least one valid line item is required.';

    if (empty($errors)) {
        $subtotal = array_sum(array_column($items, 'amount'));
        $card_fee_pct = in_array((string)($inv['payment_method'] ?? ''), ['stripe', 'ach', 'card'], true)
            ? (float)get_setting('card_fee_percent', '0') : 0.0;
        $card_fee_amount = round($subtotal * $card_fee_pct / 100, 2);
        $total = round($subtotal + $card_fee_amount, 2);

        // Stripe Payment Link — generate stable link if not yet present
        // The actual Stripe Checkout session is created on-demand when customer clicks (see /pay-invoice.php)
        // This ensures the 24h expiry window starts from when they click, not when invoice was created
        $stripe_payment_link = $inv['stripe_payment_link']; // keep existing by default
        $stripe_key = trim(get_setting('stripe_secret_key', ''));
        if ($stripe_key !== '' && str_starts_with($stripe_key, 'sk_') && $total > 0 && empty($stripe_payment_link)) {
            require_once INC_PATH . '/stripe.php';
            $stripe_payment_link = invoice_pay_url($id);
        }

        db_update('invoices', [
            'customer_id'         => $upd['customer_id'],
            'cust_name'           => $upd['cust_name'],
            'cust_email'          => $upd['cust_email'],
            'cust_phone'          => $upd['cust_phone'],
            'cust_address'        => $upd['cust_address'],
            'subtotal'            => $subtotal,
            'tax_rate'            => 0,
            'tax_amount'          => 0,
            'card_fee_rate'       => $card_fee_pct,
            'card_fee_amount'     => $card_fee_amount,
            'total'               => $total,
            'notes'               => $upd['notes'],
            'terms'               => $upd['terms'],
            'status'              => $upd['status'],
            'due_date'            => $upd['due_date'] ?: null,
            'stripe_payment_link' => $stripe_payment_link,
            'updated_at'          => date('Y-m-d H:i:s'),
        ], 'id', $id);

        // Replace all line items
        db_execute('DELETE FROM invoice_items WHERE invoice_id = ?', [$id]);
        foreach ($items as $it) {
            db_insert('invoice_items', [
                'invoice_id'  => $id,
                'description' => $it['description'],
                'quantity'    => $it['quantity'],
                'unit_price'  => $it['unit_price'],
                'amount'      => $it['amount'],
                'rate_type'   => $it['rate_type'],
            ]);
        }

        log_activity('update_invoice', 'Updated invoice ' . $inv['invoice_number'], 'invoice', $id);

        if (!empty($errors)) {
            flash_warning('Invoice saved, but: ' . implode(' ', $errors));
        } else {
            flash_success('Invoice ' . $inv['invoice_number'] . ' updated.');
        }
        redirect('view.php?id=' . $id);
    }
    // On error, re-populate with POST data
    $inv = array_merge($inv, [
        'cust_name'    => $upd['cust_name'],
        'cust_email'   => $upd['cust_email'],
        'cust_phone'   => $upd['cust_phone'],
        'cust_address' => $upd['cust_address'],
        'due_date'     => $upd['due_date'],
        'tax_rate'     => $upd['tax_rate'],
        'notes'        => $upd['notes'],
        'terms'        => $upd['terms'],
        'status'       => $upd['status'],
    ]);
    $existing_items = $items;
}

$customers = db_fetchall('SELECT id, name, email, phone, billing_address FROM customers ORDER BY name ASC');
$dumpsters = db_fetchall(
    'SELECT id, unit_code, size, daily_rate, weekly_rate, monthly_rate FROM dumpsters WHERE active = 1 ORDER BY unit_code ASC'
);

layout_start('Edit Invoice ' . $inv['invoice_number'], 'invoices');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Edit Invoice <?= e($inv['invoice_number']) ?></h1>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back
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
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="row g-4">

        <div class="col-lg-8">

            <!-- Customer -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-user me-2"></i>Bill To</h5>
                </div>
                <div class="card-body">
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
                                    <?= ((int)($inv['customer_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                                <?= e($c['name']) ?><?= $c['email'] ? ' — ' . e($c['email']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="customer_id" id="hCustomerId"
                           value="<?= (int)($inv['customer_id'] ?? 0) ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                            <input type="text" name="cust_name" class="form-control"
                                   value="<?= e($inv['cust_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="cust_email" class="form-control"
                                   value="<?= e($inv['cust_email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="cust_phone" class="form-control"
                                   value="<?= e($inv['cust_phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Billing Address</label>
                            <input type="text" name="cust_address" class="form-control"
                                   value="<?= e($inv['cust_address'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Line Items -->
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="fas fa-list me-2"></i>Line Items</h5>
                    <div class="d-flex gap-2">
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
                            <tbody id="itemsBody"></tbody>
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
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"><?= e($inv['notes'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="form-label">Terms</label>
                        <textarea name="terms" class="form-control" rows="3"><?= e($inv['terms'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">

            <!-- Settings -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-cog me-2"></i>Invoice Settings</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach (['draft'=>'Draft','sent'=>'Sent','paid'=>'Paid','void'=>'Void'] as $val=>$lbl): ?>
                            <option value="<?= $val ?>" <?= ($inv['status'] === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="due_date" class="form-control"
                               value="<?= e($inv['due_date'] ?? '') ?>">
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
                        <tr class="table-active"><td class="fw-bold">Total</td><td class="text-end fw-bold fs-5" id="calcTotal">$0.00</td></tr>
                    </table>
                    <small class="text-muted d-block mt-2">
                        <i class="fa-brands fa-stripe me-1"></i>
                        Tax is handled automatically by Stripe.
                    </small>
                </div>
            </div>

            <!-- Stripe -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fab fa-stripe me-2"></i>Stripe Payment Link</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($inv['stripe_payment_link'])): ?>
                    <div class="mb-2" style="word-break:break-all;font-size:.8rem;color:var(--gl);">
                        Current: <a href="<?= e($inv['stripe_payment_link']) ?>" target="_blank"><?= e($inv['stripe_payment_link']) ?></a>
                    </div>
                    <?php endif; ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="regen_stripe" id="regenStripe" value="1">
                        <label class="form-check-label" for="regenStripe">
                            <?= !empty($inv['stripe_payment_link']) ? 'Re-generate' : 'Generate' ?> Stripe Payment Link
                        </label>
                    </div>
                    <small class="text-muted d-block mt-1" style="font-size:.8rem;">
                        This will create a new Stripe Payment Link and replace the existing one.
                    </small>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn-tp-primary">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                </button>
                <a href="view.php?id=<?= $id ?>" class="btn-tp-ghost text-center">Cancel</a>
            </div>
        </div>

    </div>
</form>

<script>
var rowCount = 0;
function fmtMoney(n) { return '$' + (Math.round(n*100)/100).toFixed(2); }
function escAttr(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function addRow(desc, qty, price, type) {
    desc  = desc  || '';
    qty   = qty   != null ? qty   : 1;
    price = price != null ? price : 0;
    type  = type  || 'fixed';
    rowCount++;
    var i = rowCount;
    document.getElementById('itemsBody').insertAdjacentHTML('beforeend',
        '<tr id="row'+i+'">'
        + '<td><input type="text" class="form-control form-control-sm" name="item_desc[]" value="'+escAttr(desc)+'" required></td>'
        + '<td><input type="number" class="form-control form-control-sm item-qty" name="item_qty[]" value="'+qty+'" min="0" step="0.01"></td>'
        + '<td><input type="number" class="form-control form-control-sm item-price" name="item_price[]" value="'+price.toFixed(2)+'" min="0" step="0.01"></td>'
        + '<td><select class="form-select form-select-sm" name="item_rate_type[]">'
        + '<option value="fixed"'+(type==='fixed'?' selected':'')+'>Fixed</option>'
        + '<option value="daily"'+(type==='daily'?' selected':'')+'>Daily</option>'
        + '<option value="weekly"'+(type==='weekly'?' selected':'')+'>Weekly</option>'
        + '<option value="monthly"'+(type==='monthly'?' selected':'')+'>Monthly</option>'
        + '</select></td>'
        + '<td class="item-amt fw-semibold" style="vertical-align:middle;">$0.00</td>'
        + '<td class="text-end"><button type="button" class="btn-tp-ghost btn-tp-xs text-danger" onclick="removeRow('+i+')" title="Remove"><i class="fa-solid fa-trash"></i></button></td>'
        + '</tr>'
    );
    recalcAll();
}

function removeRow(i) { var r=document.getElementById('row'+i); if(r)r.remove(); recalcAll(); }

function recalcAll() {
    var subtotal=0;
    document.querySelectorAll('#itemsBody tr').forEach(function(row){
        var id=parseInt(row.id.replace('row',''),10);
        var qty=parseFloat(row.querySelector('.item-qty').value)||0;
        var price=parseFloat(row.querySelector('.item-price').value)||0;
        var amt=Math.round(qty*price*100)/100;
        row.querySelector('.item-amt').textContent=fmtMoney(amt);
        subtotal+=amt;
    });
    document.getElementById('calcTotal').textContent=fmtMoney(subtotal);
}

// Pre-populate existing items
<?php foreach ($existing_items as $it): ?>
addRow(<?= json_encode($it['description'] ?? '') ?>, <?= (float)($it['quantity'] ?? 1) ?>, <?= (float)($it['unit_price'] ?? 0) ?>, <?= json_encode($it['rate_type'] ?? 'fixed') ?>);
<?php endforeach; ?>
if (rowCount === 0) addRow();

document.getElementById('itemsBody').addEventListener('input', recalcAll);
document.getElementById('btnAddRow').addEventListener('click', function(){ addRow(); });

// Quick-add inventory
var qa = document.getElementById('quickAddUnit');
if (qa) {
    qa.addEventListener('change', function(){
        var opt=this.options[this.selectedIndex];
        if(!opt.value) return;
        var daily=parseFloat(opt.dataset.daily)||0, weekly=parseFloat(opt.dataset.weekly)||0, monthly=parseFloat(opt.dataset.monthly)||0;
        var rt='daily', rp=daily;
        if(weekly>0||monthly>0){
            var ch=prompt('Select rate for '+opt.dataset.code+' ('+opt.dataset.size+'):\n1=Daily($'+daily.toFixed(2)+')\n'+(weekly>0?'2=Weekly($'+weekly.toFixed(2)+')\n':'')+(monthly>0?'3=Monthly($'+monthly.toFixed(2)+')\n':''),'1');
            if(ch==='2'&&weekly>0){rt='weekly';rp=weekly;}
            if(ch==='3'&&monthly>0){rt='monthly';rp=monthly;}
        }
        addRow(opt.dataset.code+' — '+opt.dataset.size+' Dumpster Rental',1,rp,rt);
        this.value='';
    });
}

// Customer fill
var cs=document.getElementById('customerSelect');
if(cs){
    cs.addEventListener('change',function(){
        var opt=this.options[this.selectedIndex];
        if(!opt.value) return;
        document.querySelector('[name="cust_name"]').value=opt.dataset.name||'';
        document.querySelector('[name="cust_email"]').value=opt.dataset.email||'';
        document.querySelector('[name="cust_phone"]').value=opt.dataset.phone||'';
        document.querySelector('[name="cust_address"]').value=opt.dataset.address||'';
        document.getElementById('hCustomerId').value=opt.value;
    });
}
</script>

<?php layout_end(); ?>
