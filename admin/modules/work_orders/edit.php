<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office', 'dispatcher');
$pdo = get_db();

// ── Fetch work order ──────────────────────────────────────────────────────────
$id   = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM work_orders WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$wo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$wo) {
    flash_error('Work order not found.');
    redirect('index.php');
}

$errors = [];
$old    = $wo;

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

    if ($old['cust_name'] === '')        $errors[] = 'Customer name is required.';
    if ($old['service_address'] === '')  $errors[] = 'Service address is required.';
    if ($old['delivery_date'] === '')    $errors[] = 'Delivery date is required.';

    $valid_priorities = ['normal', 'high', 'urgent'];
    if (!in_array($old['priority'], $valid_priorities)) $old['priority'] = 'normal';

    if (empty($errors)) {
        $new_dumpster_id = $old['dumpster_id'] !== '' ? (int)$old['dumpster_id'] : null;
        $old_dumpster_id = !empty($wo['dumpster_id']) ? (int)$wo['dumpster_id'] : null;

        if ($old_dumpster_id !== $new_dumpster_id) {
            if ($old_dumpster_id) {
                $pdo->prepare('UPDATE dumpsters SET status = ? WHERE id = ?')->execute(['available', $old_dumpster_id]);
            }
            if ($new_dumpster_id) {
                $pdo->prepare('UPDATE dumpsters SET status = ? WHERE id = ?')->execute(['reserved', $new_dumpster_id]);
            }
        }

        $driver_id_v   = $old['assigned_driver'] !== '' ? (int)$old['assigned_driver'] : null;
        $amount_v      = $old['amount'] !== '' ? (float)$old['amount'] : null;
        $pickup_date_v = $old['pickup_date'] !== '' ? $old['pickup_date'] : null;

        $pdo->prepare(
            'UPDATE work_orders SET
                cust_name=?,cust_phone=?,cust_email=?,
                service_address=?,service_city=?,service_state=?,service_zip=?,
                size=?,project_type=?,dumpster_id=?,
                delivery_date=?,pickup_date=?,assigned_driver=?,
                amount=?,priority=?,internal_notes=?,footer_notes=?,updated_at=NOW()
             WHERE id=?'
        )->execute([
            $old['cust_name'], $old['cust_phone'], $old['cust_email'],
            $old['service_address'], $old['service_city'], $old['service_state'], $old['service_zip'],
            $old['size'], $old['project_type'], $new_dumpster_id,
            $old['delivery_date'], $pickup_date_v, $driver_id_v,
            $amount_v, $old['priority'], $old['internal_notes'], $old['footer_notes'],
            $id,
        ]);

        log_activity('update_work_order', 'Updated work order ' . $wo['wo_number'], $id);
        flash_success('Work Order ' . $wo['wo_number'] . ' updated.');
        redirect('view.php?id=' . $id);
    }
}

// ── Supporting data ───────────────────────────────────────────────────────────
$sizes         = dumpster_sizes();
$project_types = project_types();

$dumpsters = $pdo->query(
    "SELECT id, unit_code, size, status FROM dumpsters
     WHERE status IN ('available','reserved') ORDER BY unit_code"
)->fetchAll(PDO::FETCH_ASSOC);

// Include currently assigned dumpster even if status changed
if (!empty($wo['dumpster_id'])) {
    $in_list = array_filter($dumpsters, fn($d) => (int)$d['id'] === (int)$wo['dumpster_id']);
    if (empty($in_list)) {
        $cur = $pdo->prepare('SELECT id,unit_code,size,status FROM dumpsters WHERE id=? LIMIT 1');
        $cur->execute([$wo['dumpster_id']]);
        $cur = $cur->fetch(PDO::FETCH_ASSOC);
        if ($cur) array_unshift($dumpsters, $cur);
    }
}

$drivers = $pdo->query(
    "SELECT id, name FROM users WHERE role IN ('admin','office','dispatcher','driver') AND active=1 ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Photos ────────────────────────────────────────────────────────────────────
$photos = [];
try {
    $photos = db_fetchall(
        'SELECT p.*, u.name AS uploader_name
         FROM work_order_photos p
         LEFT JOIN users u ON p.uploaded_by = u.id
         WHERE p.wo_id = ? ORDER BY p.created_at ASC',
        [$id]
    );
} catch (\Throwable $e) { /* table may not exist yet */ }

$upload_base = APP_URL . '/uploads/wo_photos/' . $id . '/';

layout_start('Edit WO: ' . $wo['wo_number'], 'work_orders');
?>

<style>
/* ── Edit form tweaks ── */
.wo-section-title {
    font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
    color:var(--muted, #6b7280);padding-bottom:.5rem;margin-bottom:1rem;
    border-bottom:1px solid var(--st, #2a2f3e);
}
.tp-form-card { background:var(--card-bg,#1e2230);border:1px solid var(--st,#2a2f3e);border-radius:10px;padding:1.25rem 1.5rem;margin-bottom:1rem; }
@media(max-width:576px){
    .tp-form-card{padding:1rem;}
    .wo-actions-bar .btn-tp-ghost,.wo-actions-bar .btn-tp-primary{font-size:.75rem;padding:.35rem .65rem;}
}
/* Photo grid */
.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.75rem;margin-bottom:1rem;}
.photo-thumb{position:relative;border-radius:6px;overflow:hidden;background:#111827;}
.photo-thumb img{width:100%;height:120px;object-fit:cover;cursor:pointer;display:block;transition:opacity .15s;}
.photo-thumb img:hover{opacity:.85;}
.photo-caption{position:absolute;bottom:0;left:0;right:0;padding:4px 6px;background:rgba(0,0,0,.6);font-size:.7rem;color:#e5e7eb;}
.photo-del{position:absolute;top:4px;right:4px;}
.drop-zone{border:2px dashed #374151;border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;}
.drop-zone:hover,.drop-zone.dragover{border-color:#f97316;background:rgba(249,115,22,.05);}
</style>

<!-- Header bar -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 wo-actions-bar">
    <div>
        <h5 class="mb-0">
            Edit Work Order
            <span style="color:var(--accent,#f97316);font-weight:600;"><?= e($wo['wo_number']) ?></span>
        </h5>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="view.php?id=<?= $id ?>#photos" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-camera"></i> <span class="d-none d-sm-inline">Photos</span>
        </a>
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
    <strong>Please fix:</strong>
    <ul class="mb-0 mt-1 ps-3">
        <?php foreach ($errors as $err): ?>
            <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="" id="wo-edit-form">
    <?= csrf_field() ?>

    <!-- ── Customer Information ──────────────────────────────────────────────── -->
    <div class="tp-form-card">
        <div class="wo-section-title"><i class="fa-solid fa-user me-1"></i> Customer Information</div>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="cust_name">Customer Name <span class="text-danger">*</span></label>
                <input type="text" id="cust_name" name="cust_name" class="form-control"
                       value="<?= e($old['cust_name']) ?>" required>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="cust_phone">Phone</label>
                <input type="text" id="cust_phone" name="cust_phone" class="form-control"
                       value="<?= e($old['cust_phone'] ?? '') ?>">
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="cust_email">Email</label>
                <input type="email" id="cust_email" name="cust_email" class="form-control"
                       value="<?= e($old['cust_email'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- ── Service Location ──────────────────────────────────────────────────── -->
    <div class="tp-form-card">
        <div class="wo-section-title"><i class="fa-solid fa-map-location-dot me-1"></i> Service Location</div>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="service_address">Address <span class="text-danger">*</span></label>
                <input type="text" id="service_address" name="service_address" class="form-control"
                       value="<?= e($old['service_address'] ?? '') ?>" required>
            </div>
            <div class="col-sm-6 col-md-5">
                <label class="form-label" for="service_city">City</label>
                <input type="text" id="service_city" name="service_city" class="form-control"
                       value="<?= e($old['service_city'] ?? '') ?>">
            </div>
            <div class="col-sm-3 col-md-3">
                <label class="form-label" for="service_state">State</label>
                <input type="text" id="service_state" name="service_state" class="form-control"
                       value="<?= e($old['service_state'] ?? '') ?>" maxlength="2" placeholder="AL">
            </div>
            <div class="col-sm-3 col-md-4">
                <label class="form-label" for="service_zip">ZIP</label>
                <input type="text" id="service_zip" name="service_zip" class="form-control"
                       value="<?= e($old['service_zip'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- ── Job Details ───────────────────────────────────────────────────────── -->
    <div class="tp-form-card">
        <div class="wo-section-title"><i class="fa-solid fa-dumpster me-1"></i> Job Details</div>
        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label" for="size">Dumpster Size</label>
                <select id="size" name="size" class="form-select">
                    <option value="">— Select Size —</option>
                    <?php foreach ($sizes as $sz): ?>
                    <option value="<?= e($sz) ?>" <?= ($old['size'] ?? '') === $sz ? 'selected' : '' ?>>
                        <?= e($sz) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="project_type">Project Type</label>
                <select id="project_type" name="project_type" class="form-select">
                    <option value="">— Select Type —</option>
                    <?php foreach ($project_types as $pt): ?>
                    <option value="<?= e($pt) ?>" <?= ($old['project_type'] ?? '') === $pt ? 'selected' : '' ?>>
                        <?= e($pt) ?>
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
                        <?= (string)($old['dumpster_id'] ?? '') === (string)$d['id'] ? 'selected' : '' ?>>
                        <?= e($d['unit_code']) ?> (<?= e($d['size'] ?? 'unknown') ?>) — <?= ucfirst($d['status']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($wo['dumpster_id'])): ?>
                <div class="form-text" style="color:#f97316;">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    Changing the dumpster will update inventory status automatically.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Scheduling & Assignment ───────────────────────────────────────────── -->
    <div class="tp-form-card">
        <div class="wo-section-title"><i class="fa-solid fa-calendar-days me-1"></i> Scheduling &amp; Assignment</div>
        <div class="row g-3">
            <div class="col-sm-6 col-md-4">
                <label class="form-label" for="delivery_date">Delivery Date <span class="text-danger">*</span></label>
                <input type="date" id="delivery_date" name="delivery_date" class="form-control"
                       value="<?= e($old['delivery_date'] ?? '') ?>" required>
            </div>
            <div class="col-sm-6 col-md-4">
                <label class="form-label" for="pickup_date">Scheduled Pickup</label>
                <input type="date" id="pickup_date" name="pickup_date" class="form-control"
                       value="<?= e($old['pickup_date'] ?? '') ?>">
            </div>
            <div class="col-sm-12 col-md-4">
                <label class="form-label" for="assigned_driver">Assigned Driver</label>
                <select id="assigned_driver" name="assigned_driver" class="form-select">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($drivers as $drv): ?>
                    <option value="<?= (int)$drv['id'] ?>"
                        <?= (string)($old['assigned_driver'] ?? '') === (string)$drv['id'] ? 'selected' : '' ?>>
                        <?= e($drv['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- ── Amount & Priority ─────────────────────────────────────────────────── -->
    <div class="tp-form-card">
        <div class="wo-section-title"><i class="fa-solid fa-dollar-sign me-1"></i> Amount &amp; Priority</div>
        <div class="row g-3 align-items-end">
            <div class="col-sm-5 col-md-4">
                <label class="form-label" for="amount">Amount</label>
                <div class="input-group">
                    <span class="input-group-text">$</span>
                    <input type="number" id="amount" name="amount" class="form-control"
                           step="0.01" min="0" value="<?= e($old['amount'] ?? '') ?>">
                </div>
            </div>
            <div class="col-sm-7 col-md-8">
                <label class="form-label d-block">Priority</label>
                <div class="d-flex gap-3 flex-wrap">
                    <?php foreach (['normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $pv => $pl): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="priority"
                               id="priority_<?= $pv ?>" value="<?= $pv ?>"
                               <?= ($old['priority'] ?? 'normal') === $pv ? 'checked' : '' ?>>
                        <label class="form-check-label" for="priority_<?= $pv ?>"><?= $pl ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Notes ─────────────────────────────────────────────────────────────── -->
    <div class="tp-form-card">
        <div class="wo-section-title"><i class="fa-solid fa-note-sticky me-1"></i> Notes</div>
        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label" for="internal_notes">Internal Notes</label>
                <textarea id="internal_notes" name="internal_notes" class="form-control" rows="4"
                          placeholder="Visible to staff only…"><?= e($old['internal_notes'] ?? '') ?></textarea>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="footer_notes">Footer Notes</label>
                <textarea id="footer_notes" name="footer_notes" class="form-control" rows="4"
                          placeholder="Printed on the work order document…"><?= e($old['footer_notes'] ?? '') ?></textarea>
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

<!-- ── Photos ────────────────────────────────────────────────────────────────── -->
<div class="tp-form-card" id="photos">
    <div class="wo-section-title"><i class="fa-solid fa-camera me-1"></i> Photos
        <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.8rem;margin-left:.5rem;">
            (<?= count($photos) ?> uploaded)
        </span>
    </div>

    <?php if (!empty($photos)): ?>
    <div class="photo-grid">
        <?php foreach ($photos as $photo):
            $photo_url = $upload_base . rawurlencode($photo['filename']);
        ?>
        <div class="photo-thumb">
            <img src="<?= e($photo_url) ?>"
                 alt="<?= e($photo['caption'] ?? 'Photo') ?>"
                 onclick="openLightbox('<?= e($photo_url) ?>','<?= e(addslashes($photo['caption'] ?? '')) ?>')"
                 loading="lazy">
            <div class="photo-caption">
                <?php if (!empty($photo['caption'])): ?>
                <div class="text-truncate"><?= e($photo['caption']) ?></div>
                <?php endif; ?>
                <div style="color:#9ca3af;"><?= e(date('m/d/Y', strtotime($photo['created_at']))) ?></div>
            </div>
            <form method="POST" action="delete_photo.php" class="photo-del"
                  onsubmit="return confirm('Delete this photo?')">
                <?= csrf_field() ?>
                <input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>">
                <input type="hidden" name="wo_id"    value="<?= $id ?>">
                <input type="hidden" name="back"     value="edit">
                <button type="submit"
                        style="background:rgba(0,0,0,.65);color:#f87171;border:none;border-radius:4px;padding:2px 6px;line-height:1.4;"
                        title="Delete photo">
                    <i class="fa-solid fa-trash" style="font-size:.65rem;"></i>
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="text-muted mb-3" style="font-size:.875rem;">No photos yet. Upload delivery, placement, or pickup photos below.</p>
    <?php endif; ?>

    <!-- Upload form -->
    <form method="POST" action="upload_photo.php" enctype="multipart/form-data" id="photo-upload-form">
        <?= csrf_field() ?>
        <input type="hidden" name="wo_id" value="<?= $id ?>">

        <div class="drop-zone mb-2" id="dropZone"
             onclick="document.getElementById('photo-file-input').click()">
            <i class="fa-solid fa-cloud-arrow-up fa-2x mb-2" style="color:#6b7280;"></i>
            <div style="color:#9ca3af;font-size:.875rem;">
                Tap to select photos, or drag &amp; drop here<br>
                <small>JPEG, PNG, WebP, GIF &bull; Up to 15 MB each &bull; Multiple allowed</small>
            </div>
            <div id="photo-file-names" class="mt-2" style="color:#f97316;font-size:.85rem;display:none;"></div>
        </div>

        <input type="file" id="photo-file-input" name="photos[]"
               accept="image/*" multiple class="d-none"
               onchange="showFileNames(this)">

        <div class="row g-2 mt-1">
            <div class="col-8 col-md-9">
                <input type="text" name="caption" class="form-control form-control-sm"
                       placeholder="Caption (optional)">
            </div>
            <div class="col-4 col-md-3">
                <button type="submit" class="btn-tp-primary btn-tp-sm w-100" id="upload-btn" disabled style="height:31px;">
                    <i class="fa-solid fa-upload me-1"></i> Upload
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Lightbox modal -->
<div class="modal fade" id="lightboxModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content" style="background:#111827;border:none;">
            <div class="modal-header" style="border-bottom:1px solid #1f2937;padding:.75rem 1rem;">
                <small id="lightboxCaption" class="text-muted"></small>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2 text-center">
                <img id="lightboxImg" src="" alt="" style="max-width:100%;max-height:80vh;object-fit:contain;">
            </div>
        </div>
    </div>
</div>

<script>
function openLightbox(src, caption) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightboxCaption').textContent = caption || '';
    new bootstrap.Modal(document.getElementById('lightboxModal')).show();
}

function showFileNames(input) {
    var names = Array.from(input.files).map(f => f.name);
    var el = document.getElementById('photo-file-names');
    if (names.length) {
        el.textContent = names.length === 1 ? names[0] : names.length + ' files selected';
        el.style.display = 'block';
        document.getElementById('upload-btn').disabled = false;
    } else {
        el.style.display = 'none';
        document.getElementById('upload-btn').disabled = true;
    }
}

// Drag & drop
var dz = document.getElementById('dropZone');
if (dz) {
    dz.addEventListener('dragover', function(e) { e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', function() { dz.classList.remove('dragover'); });
    dz.addEventListener('drop', function(e) {
        e.preventDefault();
        dz.classList.remove('dragover');
        var fi = document.getElementById('photo-file-input');
        fi.files = e.dataTransfer.files;
        showFileNames(fi);
    });
}
</script>

<?php layout_end(); ?>
