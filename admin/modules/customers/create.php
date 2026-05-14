<?php
/**
 * Customers – Create
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';

require_login();
require_role('admin', 'office');

$lead_id = 0;
$lead    = null;

$errors = [];
$data   = [
    'name'             => '',
    'company'          => '',
    'email'            => '',
    'phone'            => '',
    'address'          => '',
    'city'             => '',
    'state'            => '',
    'zip'              => '',
    'billing_address'  => '',
    'billing_city'     => '',
    'billing_state'    => '',
    'billing_zip'      => '',
    'type'             => 'residential',
    'notes'            => '',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $lead_id = (int)($_GET['lead_id'] ?? 0);
    if ($lead_id > 0) {
        $lead = db_fetch('SELECT * FROM leads WHERE id = ? LIMIT 1', [$lead_id]);
        if ($lead) {
            $data = [
                'name'             => trim((string)($lead['name'] ?? '')),
                'company'          => '',
                'email'            => trim((string)($lead['email'] ?? '')),
                'phone'            => trim((string)($lead['phone'] ?? '')),
                'address'          => trim((string)($lead['address'] ?? '')),
                'city'             => trim((string)($lead['city'] ?? '')),
                'state'            => trim((string)($lead['state'] ?? '')),
                'zip'              => trim((string)($lead['zip'] ?? '')),
                'billing_address'  => trim((string)($lead['address'] ?? '')),
                'billing_city'     => trim((string)($lead['city'] ?? '')),
                'billing_state'    => trim((string)($lead['state'] ?? '')),
                'billing_zip'      => trim((string)($lead['zip'] ?? '')),
                'type'             => 'residential',
                'notes'            => trim((string)($lead['message'] ?? '')),
            ];
            $page_title = 'Convert Lead to Customer';
        }
    }
}

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $lead_id = (int)($_POST['lead_id'] ?? 0);

    $data = [
        'name'            => trim($_POST['name']            ?? ''),
        'company'         => trim($_POST['company']         ?? ''),
        'email'           => trim($_POST['email']           ?? ''),
        'phone'           => trim($_POST['phone']           ?? ''),
        'address'         => trim($_POST['address']         ?? ''),
        'city'            => trim($_POST['city']            ?? ''),
        'state'           => trim($_POST['state']           ?? ''),
        'zip'             => trim($_POST['zip']             ?? ''),
        'billing_address' => trim($_POST['billing_address'] ?? ''),
        'billing_city'    => trim($_POST['billing_city']    ?? ''),
        'billing_state'   => trim($_POST['billing_state']   ?? ''),
        'billing_zip'     => trim($_POST['billing_zip']     ?? ''),
        'type'            => trim($_POST['type']            ?? 'residential'),
        'notes'           => trim($_POST['notes']           ?? ''),
    ];

    if (!empty($_POST['billing_same'])) {
        $data['billing_address'] = $data['address'];
        $data['billing_city']    = $data['city'];
        $data['billing_state']   = $data['state'];
        $data['billing_zip']     = $data['zip'];
    }

    $errors = validate_required(['name'], $data);

    if (empty($errors)) {
        $valid_types = ['residential', 'commercial', 'contractor'];
        $insert = [
            'name'            => $data['name'],
            'company'         => $data['company'],
            'email'           => $data['email'],
            'phone'           => $data['phone'],
            'address'         => $data['address'],
            'city'            => $data['city'],
            'state'           => $data['state'],
            'zip'             => $data['zip'],
            'billing_address' => $data['billing_address'],
            'billing_city'    => $data['billing_city'],
            'billing_state'   => $data['billing_state'],
            'billing_zip'     => $data['billing_zip'],
            'type'            => in_array($data['type'], $valid_types, true) ? $data['type'] : 'residential',
            'notes'           => $data['notes'],
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        $customer_id = (int)db_insert('customers', $insert);

        if ($lead_id > 0) {
            db_query(
                "UPDATE leads SET converted_to = ?, status = 'won', updated_at = NOW() WHERE id = ?",
                [$customer_id, $lead_id]
            );
        }

        log_activity('create', 'Created customer: ' . $data['name'], 'customer', $customer_id);
        flash_success('Customer "' . $data['name'] . '" created successfully.');
        redirect(APP_URL . '/modules/customers/view.php?id=' . $customer_id);
    }
}

$page_title = $page_title ?? 'New Customer';
layout_start($page_title, 'customers');
?>

<form method="post" action="<?= APP_URL ?>/modules/customers/create.php" novalidate id="createCustomerForm">
<?= csrf_field() ?>
<?php if ($lead_id > 0): ?>
    <input type="hidden" name="lead_id" value="<?= (int)$lead_id ?>">
<?php endif; ?>

<!-- Page heading -->
<div class="tp-form-heading">
    <a href="<?= APP_URL ?>/modules/customers/index.php" class="tp-back-link">
        <i class="fa-solid fa-arrow-left"></i> Back to Customers
    </a>
    <h1><?= e($page_title) ?></h1>
</div>

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

        <!-- Contact Information -->
        <div class="tp-form-section">
            <div class="tp-form-section-head">
                <span class="section-icon"><i class="fa-solid fa-user"></i></span>
                <h6>Contact Information</h6>
            </div>
            <div class="tp-form-section-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" id="name" name="name" class="form-control"
                               value="<?= e($data['name']) ?>" required
                               placeholder="e.g. Jane Smith">
                    </div>
                    <div class="col-md-6">
                        <label for="company" class="form-label">Company <small class="text-muted">optional</small></label>
                        <input type="text" id="company" name="company" class="form-control"
                               value="<?= e($data['company']) ?>"
                               placeholder="Business name if applicable">
                    </div>
                    <div class="col-md-4">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="tel" id="phone" name="phone" class="form-control"
                               value="<?= e($data['phone']) ?>"
                               placeholder="(555) 000-0000">
                    </div>
                    <div class="col-md-5">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" class="form-control"
                               value="<?= e($data['email']) ?>"
                               placeholder="jane@example.com">
                    </div>
                    <div class="col-md-3">
                        <label for="type" class="form-label">Customer Type</label>
                        <select id="type" name="type" class="form-select">
                            <?php foreach (['residential' => 'Residential', 'commercial' => 'Commercial', 'contractor' => 'Contractor'] as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= $data['type'] === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Service Address -->
        <div class="tp-form-section">
            <div class="tp-form-section-head">
                <span class="section-icon"><i class="fa-solid fa-location-dot"></i></span>
                <h6>Service Address <span class="section-hint">— where the dumpster will be delivered</span></h6>
            </div>
            <div class="tp-form-section-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label for="address" class="form-label">Street Address</label>
                        <input type="text" id="address" name="address" class="form-control"
                               value="<?= e($data['address']) ?>"
                               placeholder="123 Main St">
                    </div>
                    <div class="col-md-5">
                        <label for="city" class="form-label">City</label>
                        <input type="text" id="city" name="city" class="form-control"
                               value="<?= e($data['city']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="state" class="form-label">State</label>
                        <input type="text" id="state" name="state" class="form-control"
                               value="<?= e($data['state']) ?>" maxlength="2" placeholder="AL">
                    </div>
                    <div class="col-md-4">
                        <label for="zip" class="form-label">ZIP</label>
                        <input type="text" id="zip" name="zip" class="form-control"
                               value="<?= e($data['zip']) ?>" maxlength="10" placeholder="36551">
                    </div>
                </div>
            </div>
        </div>

        <!-- Billing Address -->
        <div class="tp-form-section">
            <div class="tp-form-section-head">
                <span class="section-icon"><i class="fa-solid fa-file-invoice"></i></span>
                <h6>Billing Address</h6>
                <div class="ms-auto form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="billing_same"
                           name="billing_same" value="1"
                           <?= (!empty($data['billing_address']) && $data['billing_address'] === $data['address']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="billing_same" style="font-size:.82rem;font-weight:400;text-transform:none;letter-spacing:0;">
                        Same as service address
                    </label>
                </div>
            </div>
            <div class="tp-form-section-body" id="billing-fields">
                <div class="row g-3">
                    <div class="col-12">
                        <label for="billing_address" class="form-label">Street Address</label>
                        <input type="text" id="billing_address" name="billing_address" class="form-control"
                               value="<?= e($data['billing_address']) ?>">
                    </div>
                    <div class="col-md-5">
                        <label for="billing_city" class="form-label">City</label>
                        <input type="text" id="billing_city" name="billing_city" class="form-control"
                               value="<?= e($data['billing_city']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="billing_state" class="form-label">State</label>
                        <input type="text" id="billing_state" name="billing_state" class="form-control"
                               value="<?= e($data['billing_state']) ?>" maxlength="2">
                    </div>
                    <div class="col-md-4">
                        <label for="billing_zip" class="form-label">ZIP</label>
                        <input type="text" id="billing_zip" name="billing_zip" class="form-control"
                               value="<?= e($data['billing_zip']) ?>" maxlength="10">
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="tp-form-section">
            <div class="tp-form-section-head">
                <span class="section-icon"><i class="fa-solid fa-note-sticky"></i></span>
                <h6>Notes <span class="section-hint">— internal only, not shown to customer</span></h6>
            </div>
            <div class="tp-form-section-body">
                <textarea id="notes" name="notes" class="form-control" rows="3"
                          placeholder="Anything the office should know about this customer…"><?= e($data['notes']) ?></textarea>
            </div>
        </div>

    </div><!-- /tp-form-main -->

    <!-- ── Sidebar ────────────────────────────────────────────────────────── -->
    <div class="tp-form-sidebar">
        <div class="tp-sidebar-card">
            <div class="tp-sidebar-card-head">
                <i class="fa-solid fa-circle-info me-1" style="color:var(--or);"></i> Tips
            </div>
            <div class="tp-sidebar-card-body" style="font-size:.85rem;color:var(--gy);line-height:1.55;">
                <p class="mb-2"><strong style="color:var(--wh);">Full Name</strong> is required and will appear on all work orders and invoices.</p>
                <p class="mb-2"><strong style="color:var(--wh);">Service Address</strong> is where the dumpster is delivered. Billing address can be different.</p>
                <p class="mb-2"><strong style="color:var(--wh);">Customer Type</strong> affects the workflow — commercial accounts can have recurring billing set up after creation.</p>
                <p class="mb-0"><strong style="color:var(--wh);">After saving</strong>, you can immediately create a booking or work order from the customer's profile.</p>
            </div>
        </div>
        <?php if ($lead_id > 0 && $lead): ?>
        <div class="tp-sidebar-card">
            <div class="tp-sidebar-card-head">
                <i class="fa-solid fa-user-plus me-1" style="color:var(--or);"></i> Converting Lead
            </div>
            <div class="tp-sidebar-card-body" style="font-size:.85rem;">
                <p class="mb-1 text-muted">Lead info pre-filled from:</p>
                <strong><?= e($lead['name'] ?? '') ?></strong>
                <?php if (!empty($lead['email'])): ?>
                <div class="text-muted" style="font-size:.8rem;"><?= e($lead['email']) ?></div>
                <?php endif; ?>
                <?php if (!empty($lead['message'])): ?>
                <div class="mt-2 p-2 rounded" style="background:var(--dk2);font-size:.8rem;color:var(--gy);">
                    "<?= e(mb_strimwidth($lead['message'], 0, 120, '…')) ?>"
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /tp-form-layout -->

<!-- Sticky save bar -->
<div class="tp-sticky-bar">
    <div class="tp-sticky-bar-info">
        <strong>New Customer</strong> — fill in the required fields above then save.
    </div>
    <div class="tp-sticky-bar-actions">
        <a href="<?= APP_URL ?>/modules/customers/index.php" class="btn-tp-ghost">Cancel</a>
        <button type="submit" class="btn-tp-primary">
            <i class="fa-solid fa-floppy-disk"></i> Create Customer
        </button>
    </div>
</div>

</form>

<script>
(function () {
    var chk    = document.getElementById('billing_same');
    var fields = document.getElementById('billing-fields');

    function toggle() {
        fields.style.opacity      = chk.checked ? '0.45' : '1';
        fields.style.pointerEvents = chk.checked ? 'none'  : '';
    }

    chk.addEventListener('change', toggle);
    toggle();
}());
</script>

<?php layout_end(); ?>
