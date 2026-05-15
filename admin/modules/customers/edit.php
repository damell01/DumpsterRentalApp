<?php
/**
 * Customers – Edit
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';

require_login();
require_role('admin', 'office');

// ── Fetch customer ───────────────────────────────────────────────────────────
$id   = (int)($_GET['id'] ?? 0);
$cust = $id ? db_fetch("SELECT * FROM customers WHERE id = ? LIMIT 1", [$id]) : false;

if (!$cust) {
    http_response_code(404);
    die('<h1>404 – Customer not found.</h1>');
}

$errors = [];

// Seed form data from existing customer
$data = [
    'name'            => $cust['name'],
    'company'         => $cust['company']         ?? '',
    'email'           => $cust['email']            ?? '',
    'phone'           => $cust['phone']            ?? '',
    'address'         => $cust['address']          ?? '',
    'city'            => $cust['city']             ?? '',
    'state'           => $cust['state']            ?? '',
    'zip'             => $cust['zip']              ?? '',
    'billing_address' => $cust['billing_address']  ?? '',
    'billing_city'    => $cust['billing_city']     ?? '',
    'billing_state'   => $cust['billing_state']    ?? '',
    'billing_zip'     => $cust['billing_zip']      ?? '',
    'type'            => $cust['type']             ?? 'residential',
    'notes'           => $cust['notes']            ?? '',
];

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

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

    // Same-as-service-address checkbox
    if (!empty($_POST['billing_same'])) {
        $data['billing_address'] = $data['address'];
        $data['billing_city']    = $data['city'];
        $data['billing_state']   = $data['state'];
        $data['billing_zip']     = $data['zip'];
    }

    $errors = validate_required(['name'], $data);

    if (empty($errors)) {
        $valid_types = ['residential', 'commercial', 'contractor'];
        $update = [
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
            'type'            => in_array($data['type'], $valid_types, true)
                                    ? $data['type'] : 'residential',
            'notes'           => $data['notes'],
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        db_update('customers', $update, 'id', $id);

        log_activity('update', 'Updated customer: ' . $data['name'], 'customer', $id);

        flash_success('Customer updated successfully.');
        redirect(APP_URL . '/modules/customers/view.php?id=' . $id);
    }
}

layout_start('Edit Customer', 'customers');
?>

<div class="tp-page-header d-flex align-items-center justify-content-between mb-3">
    <div>
        <a href="<?= APP_URL ?>/modules/customers/view.php?id=<?= (int)$cust['id'] ?>"
           class="text-muted small text-decoration-none">
            <i class="fa-solid fa-arrow-left"></i> Back to Customer
        </a>
        <h2 class="tp-page-title mb-0 mt-1">Edit Customer: <?= e($cust['name']) ?></h2>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
            <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="tp-card">
    <form method="post"
          action="<?= APP_URL ?>/modules/customers/edit.php?id=<?= (int)$cust['id'] ?>"
          novalidate>
        <?= csrf_field() ?>

        <!-- Contact Information -->
        <div class="tp-card-header mb-3">
            <h5 class="mb-0">Contact Information</h5>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label for="name" class="form-label">
                    Full Name <span class="text-danger">*</span>
                </label>
                <input type="text" id="name" name="name" class="form-control"
                       value="<?= e($data['name']) ?>" required>
            </div>

            <div class="col-md-6">
                <label for="company" class="form-label">Company</label>
                <input type="text" id="company" name="company" class="form-control"
                       value="<?= e($data['company']) ?>">
            </div>

            <div class="col-md-4">
                <label for="phone" class="form-label">Phone</label>
                <input type="tel" id="phone" name="phone" class="form-control"
                       value="<?= e($data['phone']) ?>">
            </div>

            <div class="col-md-4">
                <label for="email" class="form-label">Email</label>
                <input type="email" id="email" name="email" class="form-control"
                       value="<?= e($data['email']) ?>">
            </div>

            <div class="col-md-4">
                <label for="type" class="form-label">Customer Type</label>
                <select id="type" name="type" class="form-select">
                    <?php foreach (['residential' => 'Residential', 'commercial' => 'Commercial', 'contractor' => 'Contractor'] as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= $data['type'] === $val ? 'selected' : '' ?>>
                            <?= e($lbl) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <hr class="my-4">

        <!-- Service Address -->
        <div class="tp-card-header mb-3">
            <h5 class="mb-0">Service Address</h5>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label for="address" class="form-label">Street Address</label>
                <input type="text" id="address" name="address" class="form-control"
                       value="<?= e($data['address']) ?>">
            </div>

            <div class="col-md-3">
                <label for="city" class="form-label">City</label>
                <input type="text" id="city" name="city" class="form-control"
                       value="<?= e($data['city']) ?>">
            </div>

            <div class="col-md-1">
                <label for="state" class="form-label">State</label>
                <input type="text" id="state" name="state" class="form-control"
                       value="<?= e($data['state']) ?>" maxlength="2">
            </div>

            <div class="col-md-2">
                <label for="zip" class="form-label">ZIP</label>
                <input type="text" id="zip" name="zip" class="form-control"
                       value="<?= e($data['zip']) ?>" maxlength="10">
            </div>
        </div>

        <hr class="my-4">

        <!-- Billing Address -->
        <div class="tp-card-header mb-3 d-flex align-items-center justify-content-between">
            <h5 class="mb-0">Billing Address</h5>
            <?php
            $billing_same = !empty($data['billing_address'])
                && $data['billing_address'] === $data['address']
                && $data['billing_city']    === $data['city']
                && $data['billing_state']   === $data['state']
                && $data['billing_zip']     === $data['zip'];
            ?>
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="billing_same"
                       name="billing_same" value="1"
                       <?= $billing_same ? 'checked' : '' ?>>
                <label class="form-check-label" for="billing_same">
                    Same as service address
                </label>
            </div>
        </div>

        <div class="row g-3" id="billing-fields">
            <div class="col-md-6">
                <label for="billing_address" class="form-label">Street Address</label>
                <input type="text" id="billing_address" name="billing_address" class="form-control"
                       value="<?= e($data['billing_address']) ?>">
            </div>

            <div class="col-md-3">
                <label for="billing_city" class="form-label">City</label>
                <input type="text" id="billing_city" name="billing_city" class="form-control"
                       value="<?= e($data['billing_city']) ?>">
            </div>

            <div class="col-md-1">
                <label for="billing_state" class="form-label">State</label>
                <input type="text" id="billing_state" name="billing_state" class="form-control"
                       value="<?= e($data['billing_state']) ?>" maxlength="2">
            </div>

            <div class="col-md-2">
                <label for="billing_zip" class="form-label">ZIP</label>
                <input type="text" id="billing_zip" name="billing_zip" class="form-control"
                       value="<?= e($data['billing_zip']) ?>" maxlength="10">
            </div>
        </div>

        <hr class="my-4">

        <!-- Notes -->
        <div class="col-12">
            <label for="notes" class="form-label">Notes</label>
            <textarea id="notes" name="notes" class="form-control" rows="4"><?= e($data['notes']) ?></textarea>
        </div>

        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn-tp-primary">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
            <a href="<?= APP_URL ?>/modules/customers/view.php?id=<?= (int)$cust['id'] ?>"
               class="btn-tp-ghost">
                Cancel
            </a>
            <?php if (has_role('admin', 'office')): ?>
            <a href="<?= APP_URL ?>/modules/customers/delete.php?id=<?= (int)$cust['id'] ?>"
               class="btn-tp-ghost text-danger ms-auto"
               data-confirm="Delete this customer? This cannot be undone.">
                <i class="fa-solid fa-trash"></i> Delete Customer
            </a>
            <?php endif; ?>
        </div>

    </form>
</div>

<script>
(function () {
    var chk    = document.getElementById('billing_same');
    var fields = document.getElementById('billing-fields');

    function toggle() {
        if (chk.checked) {
            fields.style.opacity       = '0.4';
            fields.style.pointerEvents = 'none';
        } else {
            fields.style.opacity       = '1';
            fields.style.pointerEvents = '';
        }
    }

    chk.addEventListener('change', toggle);
    toggle();
}());
</script>

<?php layout_end(); ?>
