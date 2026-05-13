<?php
/**
 * Dumpsters – Create
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office');

$errors = [];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $unit_code   = trim($_POST['unit_code']    ?? '');
    $product_name = trim($_POST['product_name'] ?? '');
    $type        = trim($_POST['type']         ?? 'dumpster');
    $size        = trim($_POST['size']         ?? '');
    $description = trim($_POST['description']  ?? '');
    $daily_rate     = (float)($_POST['daily_rate']     ?? 0.00);
    $weekly_rate    = (float)($_POST['weekly_rate']    ?? 0.00);
    $monthly_rate   = (float)($_POST['monthly_rate']   ?? 0.00);
    $base_price     = (float)($_POST['base_price']     ?? 0.00);
    $rental_days    = max(1, (int)($_POST['rental_days']  ?? 7));
    $extra_day_price_raw = trim($_POST['extra_day_price'] ?? '');
    $extra_day_price = $extra_day_price_raw !== '' ? (float)$extra_day_price_raw : null;
    $delivery_fee   = (float)($_POST['delivery_fee']  ?? 0.00);
    $pickup_fee     = (float)($_POST['pickup_fee']    ?? 0.00);
    $mileage_fee_raw = trim($_POST['mileage_fee'] ?? '');
    $mileage_fee    = $mileage_fee_raw !== '' ? (float)$mileage_fee_raw : null;
    $active    = isset($_POST['active']) ? 1 : 0;
    $status    = trim($_POST['status']    ?? 'available');
    $condition = trim($_POST['condition'] ?? 'good');
    $notes     = trim($_POST['notes']     ?? '');
    $image     = null;

    // Required field validation
    if ($unit_code === '') {
        $errors[] = 'Unit Code is required.';
    }
    if ($size === '') {
        $errors[] = 'Size is required.';
    }
    if (!in_array($type, ['dumpster', 'trailer'], true)) {
        $errors[] = 'Invalid type selected.';
    }

    // Validate size is in allowed list
    if ($size !== '' && !in_array($size, dumpster_sizes(), true)) {
        $errors[] = 'Invalid size selected.';
    }

    // Handle image upload
    if (!empty($_FILES['image']['name'])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size      = 5 * 1024 * 1024; // 5 MB
        $upload_dir    = dirname(__DIR__, 3) . '/public/uploads/dumpsters/';

        $file_type = mime_content_type($_FILES['image']['tmp_name']);
        if (!in_array($file_type, $allowed_types, true)) {
            $errors[] = 'Invalid image type. Allowed: JPG, PNG, GIF, WebP.';
        } elseif ($_FILES['image']['size'] > $max_size) {
            $errors[] = 'Image must be under 5 MB.';
        } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Image upload failed. Please try again.';
        } else {
            $ext      = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $filename = 'dumpster_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $filename)) {
                $errors[] = 'Failed to save image. Check directory permissions.';
            } else {
                $image = '/uploads/dumpsters/' . $filename;
            }
        }
    }

    // Check unit_code uniqueness
    if ($unit_code !== '' && empty($errors)) {
        $existing = db_fetch('SELECT id FROM dumpsters WHERE unit_code = ? LIMIT 1', [$unit_code]);
        if ($existing) {
            flash_error('A dumpster with that Unit Code already exists. Please use a unique code.');
            redirect('create.php');
        }
    }

    if (empty($errors)) {
        $insert_data = [
            'unit_code'       => $unit_code,
            'product_name'    => $product_name !== '' ? $product_name : null,
            'type'            => $type,
            'size'            => $size,
            'description'     => $description !== '' ? $description : null,
            'daily_rate'      => $daily_rate,
            'weekly_rate'     => $weekly_rate,
            'monthly_rate'    => $monthly_rate,
            'base_price'      => $base_price,
            'rental_days'     => $rental_days,
            'extra_day_price' => $extra_day_price,
            'delivery_fee'    => $delivery_fee,
            'pickup_fee'      => $pickup_fee,
            'mileage_fee'     => $mileage_fee,
            'tax_rate'        => 0,
            'active'          => $active,
            'status'          => $status,
            'condition'       => $condition,
            'notes'           => $notes,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];
        if ($image !== null) {
            $insert_data['image'] = $image;
        }
        $id = db_insert('dumpsters', $insert_data);

        log_activity('create', "Created dumpster $unit_code ($size)", 'dumpster', (int)$id);
        flash_success("Dumpster $unit_code added successfully.");
        redirect('index.php');
    }
}

// ── Pre-fill form values on validation failure ────────────────────────────────
$f = [
    'unit_code'       => $_POST['unit_code']       ?? '',
    'product_name'    => $_POST['product_name']    ?? '',
    'type'            => $_POST['type']             ?? 'dumpster',
    'size'            => $_POST['size']             ?? '',
    'description'     => $_POST['description']     ?? '',
    'daily_rate'      => $_POST['daily_rate']       ?? '0.00',
    'weekly_rate'     => $_POST['weekly_rate']      ?? '0.00',
    'monthly_rate'    => $_POST['monthly_rate']     ?? '0.00',
    'base_price'      => $_POST['base_price']       ?? '0.00',
    'rental_days'     => $_POST['rental_days']      ?? '7',
    'extra_day_price' => $_POST['extra_day_price']  ?? '',
    'delivery_fee'    => $_POST['delivery_fee']     ?? '0.00',
    'pickup_fee'      => $_POST['pickup_fee']       ?? '0.00',
    'mileage_fee'     => $_POST['mileage_fee']      ?? '',
    'active'          => isset($_POST['active']) ? 1 : (isset($_POST['unit_code']) ? 0 : 1),
    'status'          => $_POST['status']           ?? 'available',
    'condition'       => $_POST['condition']        ?? 'good',
    'notes'           => $_POST['notes']            ?? '',
];

layout_start('Add Dumpster', 'inventory');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Add Dumpster</h5>
    <a href="index.php" class="btn-tp-ghost btn-tp-sm">
        <i class="fa-solid fa-arrow-left"></i> Back to Inventory
    </a>
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

<div class="tp-card" style="max-width:1160px;width:100%;">
    <form method="POST" action="create.php" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="row g-3">

            <!-- Unit Code -->
            <div class="col-md-6">
                <label class="form-label" for="unit_code">
                    Unit Code <span class="text-danger">*</span>
                </label>
                <input type="text"
                       id="unit_code"
                       name="unit_code"
                       class="form-control"
                       value="<?= e($f['unit_code']) ?>"
                       placeholder="e.g. TP-001"
                       required>
            </div>

            <!-- Product Name -->
            <div class="col-md-6">
                <label class="form-label" for="product_name">Product Name <small class="text-muted">optional</small></label>
                <input type="text"
                       id="product_name"
                       name="product_name"
                       class="form-control"
                       value="<?= e($f['product_name']) ?>"
                       placeholder="e.g. 10 Yard Residential Dumpster">
                <div class="form-text">Display name shown in Stripe and customer-facing views</div>
            </div>

            <!-- Size -->
            <div class="col-md-6">
                <label class="form-label" for="size">
                    Size <span class="text-danger">*</span>
                </label>
                <select id="size" name="size" class="form-select" required>
                    <option value="">— Select size —</option>
                    <?php foreach (dumpster_sizes() as $sz): ?>
                    <option value="<?= e($sz) ?>" <?= $f['size'] === $sz ? 'selected' : '' ?>>
                        <?= e($sz) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Type -->
            <div class="col-md-6 col-lg-4">
                <label class="form-label" for="type">Type</label>
                <select id="type" name="type" class="form-select">
                    <option value="dumpster" <?= $f['type'] === 'dumpster' ? 'selected' : '' ?>>Dumpster</option>
                    <option value="trailer"  <?= $f['type'] === 'trailer'  ? 'selected' : '' ?>>Trailer</option>
                </select>
            </div>

            <!-- Description -->
            <div class="col-12">
                <label class="form-label" for="description">Description <small class="text-muted">optional</small></label>
                <textarea id="description"
                          name="description"
                          class="form-control"
                          rows="2"
                          placeholder="Brief description of this dumpster size/type…"><?= e($f['description']) ?></textarea>
            </div>

            <!-- Pricing section header -->
            <div class="col-12 mt-2">
                <h6 class="mb-0" style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--gy,#9ca3af);">
                    Booking Pricing
                </h6>
                <hr class="mt-1 mb-0" style="border-color:rgba(255,255,255,.07);">
            </div>
            <div class="col-12">
                <div class="alert alert-light border mb-0 py-2 px-3" style="font-size:.9rem;">
                    After saving, use the inventory Stripe sync to publish this item’s booking price plus weekly, bi-weekly, and monthly recurring prices.
                </div>
            </div>

            <!-- Base Price -->
            <div class="col-md-4">
                <label class="form-label" for="base_price">Base Price ($) <span class="text-danger">*</span></label>
                <input type="number"
                       id="base_price"
                       name="base_price"
                       class="form-control"
                       step="0.01"
                       min="0"
                       value="<?= e(number_format((float)$f['base_price'], 2, '.', '')) ?>"
                       placeholder="e.g. 350.00">
                <div class="form-text">Flat rental price shown to customers</div>
            </div>

            <!-- Rental Days -->
            <div class="col-md-4">
                <label class="form-label" for="rental_days">Included Days</label>
                <input type="number"
                       id="rental_days"
                       name="rental_days"
                       class="form-control"
                       step="1"
                       min="1"
                       value="<?= (int)$f['rental_days'] ?>"
                       placeholder="7">
                <div class="form-text">Days included in base price</div>
            </div>

            <!-- Extra Day Price -->
            <div class="col-md-4">
                <label class="form-label" for="extra_day_price">Extra Day Price ($) <small class="text-muted">optional</small></label>
                <input type="number"
                       id="extra_day_price"
                       name="extra_day_price"
                       class="form-control"
                       step="0.01"
                       min="0"
                       value="<?= $f['extra_day_price'] !== '' ? e(number_format((float)$f['extra_day_price'], 2, '.', '')) : '' ?>"
                       placeholder="e.g. 40.00">
                <div class="form-text">Per day beyond included days</div>
            </div>

            <!-- Delivery / Pickup Fees -->
            <div class="col-12 mt-2">
                <h6 class="mb-0" style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--gy,#9ca3af);">
                    Fees &amp; Taxes
                </h6>
                <hr class="mt-1 mb-0" style="border-color:rgba(255,255,255,.07);">
            </div>

            <div class="col-md-3">
                <label class="form-label" for="delivery_fee">Delivery Fee ($)</label>
                <input type="number"
                       id="delivery_fee"
                       name="delivery_fee"
                       class="form-control"
                       step="0.01"
                       min="0"
                       value="<?= e(number_format((float)$f['delivery_fee'], 2, '.', '')) ?>"
                       placeholder="0.00">
            </div>

            <div class="col-md-3">
                <label class="form-label" for="pickup_fee">Pickup Fee ($)</label>
                <input type="number"
                       id="pickup_fee"
                       name="pickup_fee"
                       class="form-control"
                       step="0.01"
                       min="0"
                       value="<?= e(number_format((float)$f['pickup_fee'], 2, '.', '')) ?>"
                       placeholder="0.00">
            </div>

            <div class="col-md-3">
                <label class="form-label" for="mileage_fee">Mileage/Trip Fee ($) <small class="text-muted">optional</small></label>
                <input type="number"
                       id="mileage_fee"
                       name="mileage_fee"
                       class="form-control"
                       step="0.01"
                       min="0"
                       value="<?= $f['mileage_fee'] !== '' ? e(number_format((float)$f['mileage_fee'], 2, '.', '')) : '' ?>"
                       placeholder="0.00">
            </div>

            <!-- Legacy rates section -->
            <div class="col-12 mt-2">
                <h6 class="mb-0" style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--gy,#9ca3af);">
                    Legacy Rates <small class="text-muted" style="text-transform:none;letter-spacing:0;">(work order pricing)</small>
                </h6>
                <hr class="mt-1 mb-0" style="border-color:rgba(255,255,255,.07);">
            </div>

            <!-- Daily Rate -->
            <div class="col-md-4">
                <label class="form-label" for="daily_rate">Daily Rate ($)</label>
                <input type="number"
                       id="daily_rate"
                       name="daily_rate"
                       class="form-control"
                       step="0.01"
                       min="0"
                       value="<?= e(number_format((float)$f['daily_rate'], 2, '.', '')) ?>"
                       placeholder="0.00">
            </div>

            <!-- Weekly Rate -->
            <div class="col-md-4">
                <label class="form-label" for="weekly_rate">Weekly Rate ($) <small class="text-muted">optional</small></label>
                <input type="number"
                       id="weekly_rate"
                       name="weekly_rate"
                       class="form-control"
                       step="0.01"
                       min="0"
                       value="<?= e(number_format((float)$f['weekly_rate'], 2, '.', '')) ?>"
                       placeholder="0.00">
            </div>

            <!-- Monthly Rate -->
            <div class="col-md-4">
                <label class="form-label" for="monthly_rate">Monthly Rate ($) <small class="text-muted">optional</small></label>
                <input type="number"
                       id="monthly_rate"
                       name="monthly_rate"
                       class="form-control"
                       step="0.01"
                       min="0"
                       value="<?= e(number_format((float)$f['monthly_rate'], 2, '.', '')) ?>"
                       placeholder="0.00">
            </div>

            <!-- Active -->
            <div class="col-md-4 d-flex align-items-end pb-1">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="active" name="active" value="1"
                           <?= $f['active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="active">Active (available for booking)</label>
                </div>
            </div>

            <!-- Status -->
            <div class="col-md-6">
                <label class="form-label" for="status">Status</label>
                <select id="status" name="status" class="form-select">
                    <?php foreach (['available' => 'Available', 'reserved' => 'Reserved', 'in_use' => 'In Use', 'maintenance' => 'Maintenance'] as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= $f['status'] === $val ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Condition -->
            <div class="col-md-6">
                <label class="form-label" for="condition">Condition</label>
                <select id="condition" name="condition" class="form-select">
                    <?php foreach (['excellent' => 'Excellent', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor'] as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= $f['condition'] === $val ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Notes -->
            <div class="col-12">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes"
                          name="notes"
                          class="form-control"
                          rows="3"
                          placeholder="Optional notes about this dumpster…"><?= e($f['notes']) ?></textarea>
            </div>

            <!-- Image -->
            <div class="col-12">
                <label class="form-label" for="image">
                    Dumpster Photo <span style="color:var(--gy);font-size:.8rem;">(optional, max 5 MB — JPG/PNG/GIF/WebP)</span>
                </label>
                <input type="file" id="image" name="image" class="form-control"
                       accept="image/jpeg,image/png,image/gif,image/webp">
            </div>

            <!-- Submit -->
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn-tp-primary">
                    <i class="fa-solid fa-plus"></i> Add Dumpster
                </button>
                <a href="index.php" class="btn-tp-ghost">Cancel</a>
            </div>

        </div>
    </form>
</div>

<?php
layout_end();
