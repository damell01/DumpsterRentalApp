<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office', 'dispatcher');
$pdo = get_db();

// ── Pre-fill from Quote ───────────────────────────────────────────────────────
$quote_id   = null;
$from_quote = null;

// ── Default field values ──────────────────────────────────────────────────────
$wo_footer_default = get_setting('wo_footer', '');

$old = [
    'cust_name'       => $from_quote['cust_name']       ?? '',
    'cust_phone'      => $from_quote['cust_phone']       ?? '',
    'cust_email'      => $from_quote['cust_email']       ?? '',
    'service_address' => $from_quote['service_address']  ?? '',
    'service_city'    => $from_quote['service_city']     ?? '',
    'service_state'   => '',
    'service_zip'     => '',
    'size'            => $from_quote['size']             ?? '',
    'project_type'    => $from_quote['project_type']     ?? '',
    'dumpster_id'     => '',
    'delivery_date'   => '',
    'pickup_date'     => '',
    'assigned_driver' => '',
    'amount'          => $from_quote['total']            ?? '',
    'priority'        => 'normal',
    'internal_notes'  => '',
    'footer_notes'    => $wo_footer_default,
];

$errors = [];

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $old = [
        'cust_name'       => trim($_POST['cust_name']       ?? ''),
        'cust_phone'      => trim($_POST['cust_phone']      ?? ''),
        'cust_email'      => trim($_POST['cust_email']      ?? ''),
        'service_address' => trim($_POST['service_address'] ?? ''),
        'service_city'    => trim($_POST['service_city']    ?? ''),
        'service_state'   => trim($_POST['service_state']   ?? ''),
        'service_zip'     => trim($_POST['service_zip']     ?? ''),
        'size'            => trim($_POST['size']            ?? ''),
        'project_type'    => trim($_POST['project_type']    ?? ''),
        'dumpster_id'     => trim($_POST['dumpster_id']     ?? ''),
        'delivery_date'   => trim($_POST['delivery_date']   ?? ''),
        'pickup_date'     => trim($_POST['pickup_date']     ?? ''),
        'assigned_driver' => trim($_POST['assigned_driver'] ?? ''),
        'amount'          => trim($_POST['amount']          ?? ''),
        'priority'        => trim($_POST['priority']        ?? 'normal'),
        'internal_notes'  => trim($_POST['internal_notes']  ?? ''),
        'footer_notes'    => trim($_POST['footer_notes']    ?? ''),
    ];

    // Validation
    if ($old['cust_name'] === '') {
        $errors[] = 'Customer name is required.';
    }
    if ($old['service_address'] === '') {
        $errors[] = 'Service address is required.';
    }
    if ($old['delivery_date'] === '') {
        $errors[] = 'Delivery date is required.';
    }

    $valid_priorities = ['normal', 'high', 'urgent'];
    if (!in_array($old['priority'], $valid_priorities)) {
        $old['priority'] = 'normal';
    }

    if (empty($errors)) {
        $wo_number  = next_number('WO', 'work_orders', 'wo_number');
        $quote_id_v = !empty($_POST['quote_id']) ? (int)$_POST['quote_id'] : null;

        $dumpster_id_v  = $old['dumpster_id'] !== '' ? (int)$old['dumpster_id'] : null;
        $driver_id_v    = $old['assigned_driver'] !== '' ? (int)$old['assigned_driver'] : null;
        $amount_v       = $old['amount'] !== '' ? (float)$old['amount'] : null;
        $pickup_date_v  = $old['pickup_date'] !== '' ? $old['pickup_date'] : null;

        $wo_id = db_insert('work_orders', [
            'wo_number'       => $wo_number,
            'quote_id'        => $quote_id_v,
            'customer_id'     => null,
            'cust_name'       => $old['cust_name'],
            'cust_phone'      => $old['cust_phone'],
            'cust_email'      => $old['cust_email'],
            'service_address' => $old['service_address'],
            'service_city'    => $old['service_city'],
            'service_state'   => $old['service_state'],
            'service_zip'     => $old['service_zip'],
            'size'            => $old['size'],
            'project_type'    => $old['project_type'],
            'dumpster_id'     => $dumpster_id_v,
            'delivery_date'   => $old['delivery_date'],
            'pickup_date'     => $pickup_date_v,
            'assigned_driver' => $driver_id_v,
            'amount'          => $amount_v,
            'status'          => 'scheduled',
            'priority'        => $old['priority'],
            'internal_notes'  => $old['internal_notes'],
            'footer_notes'    => $old['footer_notes'],
            'created_by'      => $_SESSION['user_id'],
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        // Reserve the selected dumpster
        if ($dumpster_id_v) {
            $pdo->prepare('UPDATE dumpsters SET status = ? WHERE id = ?')
                ->execute(['reserved', $dumpster_id_v]);
        }

        log_activity('create_work_order', 'Created work order ' . $wo_number, 'work_order', (int)$wo_id);
        flash_success('Work Order ' . $wo_number . ' created successfully.');
        redirect('view.php?id=' . $wo_id);
    }
}

// ── Fetch supporting data ─────────────────────────────────────────────────────
$sizes         = dumpster_sizes();
$project_types = project_types();

$dumpsters_stmt = $pdo->query(
    "SELECT id, unit_code, size, status
     FROM dumpsters
     WHERE status IN ('available', 'reserved')
     ORDER BY unit_code"
);
$dumpsters = $dumpsters_stmt->fetchAll(PDO::FETCH_ASSOC);

$drivers_stmt = $pdo->query(
    "SELECT id, name FROM users
     WHERE role IN ('admin', 'office', 'dispatcher', 'driver') AND active = 1
     ORDER BY name"
);
$drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);

layout_start('New Work Order', 'work_orders');
?>

<style>
.wo-section-title {
    font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
    color:var(--gy,#6b7280);padding-bottom:.5rem;margin-bottom:1rem;
    border-bottom:1px solid var(--st,#e5e7eb);
}
.tp-form-card { background:var(--dk1,#fff);border:1px solid var(--st,#e5e7eb);border-radius:10px;padding:1.25rem 1.5rem;margin-bottom:1rem; }
@media(max-width:576px){ .tp-form-card{padding:1rem;} }
</style>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">New Work Order</h5>
    <a href="index.php" class="btn-tp-ghost btn-tp-sm">
        <i class="fa-solid fa-arrow-left"></i> <span class="d-none d-sm-inline">Back</span>
    </a>
</div>

<?php if ($from_quote): ?>
<div class="alert alert-info mb-3">
    <i class="fa-solid fa-circle-info me-2"></i>
    Pre-filled from Quote <strong><?= htmlspecialchars($from_quote['quote_number']) ?></strong>.
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-3">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" action="" id="wo-create-form">
    <?= csrf_field() ?>
    <?php if ($quote_id): ?>
        <input type="hidden" name="quote_id" value="<?= (int)$quote_id ?>">
    <?php endif; ?>

    <!-- ── Customer Information ── -->
    <div class="tp-form-card">
        <div class="wo-section-title"><i class="fa-solid fa-user me-1"></i> Customer Information</div>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="cust_name">Customer Name <span class="text-danger">*</span></label>
                <input type="text" id="cust_name" name="cust_name" class="form-control"
                       value="<?= htmlspecialchars($old['cust_name']) ?>" required>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="cust_phone">Phone</label>
                <input type="text" id="cust_phone" name="cust_phone" class="form-control"
                       value="<?= htmlspecialchars($old['cust_phone']) ?>">
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="cust_email">Email</label>
                <input type="email" id="cust_email" name="cust_email" class="form-control"
                       value="<?= htmlspecialchars($old['cust_email']) ?>">
            </div>
        </div>
    </div>

    <!-- ── Service Location ── -->
    <div class="tp-form-card">
        <div class="wo-section-title"><i class="fa-solid fa-map-location-dot me-1"></i> Service Location</div>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="service_address">Service Address <span class="text-danger">*</span></label>
                <input type="text" id="service_address" name="service_address" class="form-control"
                       value="<?= htmlspecialchars($old['service_address']) ?>" required>
            </div>
            <div class="col-sm-5">
                <label class="form-label" for="service_city">City</label>
                <input type="text" id="service_city" name="service_city" class="form-control"
                       value="<?= htmlspecialchars($old['service_city']) ?>">
            </div>
            <div class="col-sm-3">
                <label class="form-label" for="service_state">State</label>
                <input type="text" id="service_state" name="service_state" class="form-control"
                       value="<?= htmlspecialchars($old['service_state']) ?>" maxlength="2" placeholder="TX">
            </div>
            <div class="col-sm-4">
                <label class="form-label" for="service_zip">ZIP</label>
                <input type="text" id="service_zip" name="service_zip" class="form-control"
                       value="<?= htmlspecialchars($old['service_zip']) ?>">
            </div>
        </div>
    </div>

    <!-- ── Job Details ── -->
    <div class="tp-form-card">
        <div class="wo-section-title"><i class="fa-solid fa-dumpster me-1"></i> Job Details</div>
        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label" for="size">Dumpster Size</label>
                <select id="size" name="size" class="form-select">
                    <option value="">— Select Size —</option>
                    <?php foreach ($sizes as $sz): ?>
                        <option value="<?= htmlspecialchars($sz) ?>" <?= $old['size'] === $sz ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sz) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="project_type">Project Type</label>
                <select id="project_type" name="project_type" class="form-select">
                    <option value="">— Select Type —</option>
                    <?php foreach ($project_types as $pt): ?>
                        <option value="<?= htmlspecialchars($pt) ?>" <?= $old['project_type'] === $pt ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="dumpster_id">Assign Dumpster</label>
                <select id="dumpster_id" name="dumpster_id" class="form-select">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($dumpsters as $d): ?>
                        <option value="<?= (int)$d['id'] ?>"
                            <?= (string)$old['dumpster_id'] === (string)$d['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['unit_code']) ?> (<?= htmlspecialchars($d['size'] ?? 'unknown') ?>) — <?= ucfirst($d['status']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- ── Scheduling & Assignment ── -->
    <div class="tp-form-card">
        <div class="wo-section-title"><i class="fa-solid fa-calendar-days me-1"></i> Scheduling &amp; Assignment</div>
        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label" for="delivery_date">Delivery Date <span class="text-danger">*</span></label>
                <input type="date" id="delivery_date" name="delivery_date" class="form-control"
                       value="<?= htmlspecialchars($old['delivery_date']) ?>" required>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="pickup_date">Scheduled Pickup Date</label>
                <input type="date" id="pickup_date" name="pickup_date" class="form-control"
                       value="<?= htmlspecialchars($old['pickup_date']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="assigned_driver">Assigned Driver</label>
                <select id="assigned_driver" name="assigned_driver" class="form-select">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($drivers as $drv): ?>
                        <option value="<?= (int)$drv['id'] ?>"
                            <?= (string)$old['assigned_driver'] === (string)$drv['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($drv['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- ── Amount & Priority ── -->
    <div class="tp-form-card">
        <div class="wo-section-title"><i class="fa-solid fa-dollar-sign me-1"></i> Amount &amp; Priority</div>
        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label" for="amount">Amount</label>
                <div class="input-group">
                    <span class="input-group-text" style="background:var(--dk3,#111827);border-color:var(--input-border,#374151);color:var(--muted,#6b7280);">$</span>
                    <input type="number" id="amount" name="amount" class="form-control"
                           step="0.01" min="0" value="<?= htmlspecialchars($old['amount']) ?>">
                </div>
            </div>
            <div class="col-sm-6">
                <label class="form-label">Priority</label>
                <div class="d-flex gap-3 pt-1">
                    <?php foreach (['normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $pv => $pl): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="priority"
                               id="priority_<?= $pv ?>" value="<?= $pv ?>"
                            <?= $old['priority'] === $pv ? 'checked' : '' ?>>
                        <label class="form-check-label" for="priority_<?= $pv ?>"><?= $pl ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Notes ── -->
    <div class="tp-form-card">
        <div class="wo-section-title"><i class="fa-solid fa-note-sticky me-1"></i> Notes</div>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="internal_notes">Internal Notes</label>
                <textarea id="internal_notes" name="internal_notes" class="form-control" rows="3"
                          placeholder="Not visible to the customer."><?= htmlspecialchars($old['internal_notes']) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="footer_notes">Footer / Work Order Notes</label>
                <textarea id="footer_notes" name="footer_notes" class="form-control" rows="3"
                          placeholder="Printed on the work order document."><?= htmlspecialchars($old['footer_notes']) ?></textarea>
            </div>
        </div>
    </div>

    <!-- ── Save buttons (desktop) ── -->
    <div class="d-flex gap-2 flex-wrap mb-4">
        <button type="submit" class="btn-tp-primary btn-tp-sm" style="padding:.6rem 1.5rem;font-size:.95rem;">
            <i class="fa-solid fa-floppy-disk"></i> Create Work Order
        </button>
        <a href="index.php" class="btn-tp-ghost btn-tp-sm">Cancel</a>
    </div>

    <!-- ── Sticky save bar (mobile only) ── -->
    <div class="tp-sticky-bar">
        <button type="submit" class="btn-tp-primary btn-tp-sm flex-grow-1" style="justify-content:center;">
            <i class="fa-solid fa-floppy-disk"></i> Create Work Order
        </button>
        <a href="index.php" class="btn-tp-ghost btn-tp-sm">Cancel</a>
    </div>
    <div class="tp-sticky-bar-spacer"></div>

</form>

<?php layout_end(); ?>
