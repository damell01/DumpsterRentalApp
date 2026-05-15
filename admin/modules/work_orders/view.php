<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
$pdo = get_db();

// ── Fetch work order ──────────────────────────────────────────────────────────
$id   = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT wo.*,
            u.name      AS driver_name,
            d.unit_code AS dumpster_code,
            d.size      AS dumpster_size,
            d.status    AS dumpster_status,
            cb.name     AS created_by_name
     FROM work_orders wo
     LEFT JOIN users u  ON wo.assigned_driver = u.id
     LEFT JOIN dumpsters d ON wo.dumpster_id = d.id
     LEFT JOIN users cb ON wo.created_by = cb.id
     WHERE wo.id = ?
     LIMIT 1'
);
$stmt->execute([$id]);
$wo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$wo) {
    flash_error('Work order not found.');
    redirect('index.php');
}

// ── Fetch photos ─────────────────────────────────────────────────────────────
$photos = db_fetchall(
    'SELECT p.*, u.name AS uploader_name
     FROM work_order_photos p
     LEFT JOIN users u ON p.uploaded_by = u.id
     WHERE p.wo_id = ?
     ORDER BY p.created_at ASC',
    [$id]
);

// ── Fetch notes timeline ──────────────────────────────────────────────────────
$notes_stmt = $pdo->prepare(
    'SELECT wn.*, u.name AS author_name
     FROM work_order_notes wn
     LEFT JOIN users u ON wn.user_id = u.id
     WHERE wn.wo_id = ?
     ORDER BY wn.created_at ASC'
);
$notes_stmt->execute([$id]);
$notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

$linked_invoice = db_fetch(
    'SELECT id, invoice_number, status, total, stripe_payment_link
     FROM invoices
     WHERE work_order_id = ?
     ORDER BY id DESC
     LIMIT 1',
    [$id]
);

if (!$linked_invoice) {
    $linked_invoice = db_fetch(
        'SELECT id, invoice_number, status, total, stripe_payment_link
         FROM invoices
         WHERE notes LIKE ?
         ORDER BY id DESC
         LIMIT 1',
        ['%Work Order ' . ($wo['wo_number'] ?? '') . '%']
    );
}

// ── Print mode ────────────────────────────────────────────────────────────────
$is_print = isset($_GET['print']);

if ($is_print):
    $company_name    = get_setting('company_name',    'Trash Panda Roll-Offs');
    $company_phone   = get_setting('company_phone',   '');
    $company_email   = get_setting('company_email',   '');
    $company_address = get_setting('company_address', '');
    $wo_footer       = get_setting('wo_footer',       '');
    $logo_url_print  = get_setting('logo_url', '') ?: get_setting('logo_path', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work Order <?= htmlspecialchars($wo['wo_number']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #222; background: #fff; }
        .page { max-width: 800px; margin: 0 auto; padding: 30px 40px; }

        .letterhead { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #16a34a; padding-bottom: 16px; margin-bottom: 24px; }
        .company-name { font-size: 22px; font-weight: bold; color: #16a34a; }
        .company-info { font-size: 12px; color: #555; margin-top: 4px; line-height: 1.6; }
        .wo-label { text-align: right; }
        .wo-label h2 { font-size: 24px; color: #16a34a; letter-spacing: 2px; text-transform: uppercase; }
        .wo-label .wo-num { font-size: 18px; font-weight: bold; color: #111; }
        .wo-label .wo-meta { font-size: 12px; color: #555; margin-top: 4px; line-height: 1.7; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 20px; }
        .info-block h4 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .info-block p { font-size: 13px; line-height: 1.9; }

        .details-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .details-table th { background: #16a34a; color: #fff; padding: 8px 12px; text-align: left; font-size: 12px; }
        .details-table td { padding: 8px 12px; border-bottom: 1px solid #e5e7eb; }
        .details-table td:first-child { color: #666; font-size: 12px; width: 40%; }
        .details-table td:last-child { font-weight: 600; }

        .notes-section { margin-bottom: 20px; }
        .notes-section h4 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: 8px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .note-item { margin-bottom: 8px; padding: 8px 10px; background: #f9fafb; border-left: 3px solid #d1d5db; font-size: 12px; }
        .note-item .note-meta { color: #888; font-size: 11px; margin-bottom: 3px; }

        .footer-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px; padding: 12px; margin-bottom: 20px; font-size: 12px; color: #555; white-space: pre-wrap; }

        .print-btn { display: block; text-align: center; margin: 20px auto 0; }
        .print-btn button { padding: 10px 30px; background: #16a34a; color: #fff; border: none; border-radius: 4px; font-size: 14px; cursor: pointer; }

        @media print {
            .print-btn { display: none !important; }
            body { padding: 0; }
            .page { padding: 20px; }
        }
    </style>
</head>
<body>
<div class="page">

    <!-- Letterhead -->
    <div class="letterhead">
        <div>
            <?php if (!empty($logo_url_print)): ?>
            <img src="<?= htmlspecialchars($logo_url_print, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($company_name) ?>"
                 style="max-height:55px;max-width:180px;object-fit:contain;margin-bottom:4px;display:block;"
                 onerror="this.style.display='none';">
            <?php endif; ?>
            <div class="company-name"><?= htmlspecialchars($company_name) ?></div>
            <div class="company-info">
                <?php if ($company_address): ?><?= nl2br(htmlspecialchars($company_address)) ?><br><?php endif; ?>
                <?php if ($company_phone): ?>Phone: <?= htmlspecialchars($company_phone) ?><br><?php endif; ?>
                <?php if ($company_email): ?>Email: <?= htmlspecialchars($company_email) ?><?php endif; ?>
            </div>
        </div>
        <div class="wo-label">
            <h2>Work Order</h2>
            <div class="wo-num"><?= htmlspecialchars($wo['wo_number']) ?></div>
            <div class="wo-meta">
                Created: <?= date('m/d/Y', strtotime($wo['created_at'])) ?><br>
                Status: <?= ucfirst(str_replace('_', ' ', $wo['status'])) ?>
            </div>
        </div>
    </div>

    <!-- Customer & Service -->
    <div class="info-grid">
        <div class="info-block">
            <h4>Customer</h4>
            <p>
                <strong><?= htmlspecialchars($wo['cust_name']) ?></strong><br>
                <?php if (!empty($wo['cust_phone'])): ?><?= htmlspecialchars($wo['cust_phone']) ?><br><?php endif; ?>
                <?php if (!empty($wo['cust_email'])): ?><?= htmlspecialchars($wo['cust_email']) ?><?php endif; ?>
            </p>
        </div>
        <div class="info-block">
            <h4>Service Address</h4>
            <p>
                <?= htmlspecialchars($wo['service_address'] ?? '') ?>
                <?php if (!empty($wo['service_city']) || !empty($wo['service_state'])): ?>
                <br><?= htmlspecialchars(implode(', ', array_filter([$wo['service_city'] ?? '', $wo['service_state'] ?? '']))) ?>
                <?php if (!empty($wo['service_zip'])): ?> <?= htmlspecialchars($wo['service_zip']) ?><?php endif; ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Job Details Table -->
    <table class="details-table">
        <thead>
            <tr><th colspan="2">Job Details</th></tr>
        </thead>
        <tbody>
            <?php if (!empty($wo['size'])): ?>
            <tr><td>Container Size</td><td><?= htmlspecialchars($wo['size']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($wo['project_type'])): ?>
            <tr><td>Project Type</td><td><?= htmlspecialchars($wo['project_type']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($wo['dumpster_code'])): ?>
            <tr><td>Unit Code</td><td><?= htmlspecialchars($wo['dumpster_code']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($wo['delivery_date'])): ?>
            <tr><td>Delivery Date</td><td><?= date('m/d/Y', strtotime($wo['delivery_date'])) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($wo['pickup_date'])): ?>
            <tr><td>Scheduled Pickup</td><td><?= date('m/d/Y', strtotime($wo['pickup_date'])) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($wo['actual_pickup'])): ?>
            <tr><td>Actual Pickup</td><td><?= date('m/d/Y', strtotime($wo['actual_pickup'])) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($wo['driver_name'])): ?>
            <tr><td>Driver</td><td><?= htmlspecialchars($wo['driver_name']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($wo['amount'])): ?>
            <tr><td>Amount</td><td>$<?= number_format((float)$wo['amount'], 2) ?></td></tr>
            <?php endif; ?>
            <tr><td>Priority</td><td><?= ucfirst($wo['priority'] ?? 'normal') ?></td></tr>
        </tbody>
    </table>

    <!-- Notes -->
    <?php $visible_notes = array_filter($notes, fn($n) => $n['note_type'] === 'note'); ?>
    <?php if (!empty($visible_notes)): ?>
    <div class="notes-section">
        <h4>Notes</h4>
        <?php foreach ($visible_notes as $n): ?>
        <div class="note-item">
            <div class="note-meta">
                <?= htmlspecialchars($n['author_name'] ?? 'System') ?>
                — <?= date('m/d/Y g:i A', strtotime($n['created_at'])) ?>
            </div>
            <?= nl2br(htmlspecialchars($n['note'])) ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <?php $footer = !empty($wo['footer_notes']) ? $wo['footer_notes'] : $wo_footer; ?>
    <?php if (!empty($footer)): ?>
    <div class="footer-box"><?= htmlspecialchars($footer) ?></div>
    <?php endif; ?>

</div>

<div class="print-btn">
    <button onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save PDF</button>
</div>
</body>
</html>
<?php
    exit;
endif;

// ── Helper functions ──────────────────────────────────────────────────────────
function wov_status_badge(string $status): string
{
    $map = [
        'scheduled'        => ['Scheduled',       'bg-primary'],
        'delivered'        => ['Delivered',        'bg-info text-dark'],
        'active'           => ['Active',           'bg-success'],
        'pickup_requested' => ['Pickup Requested', 'bg-warning text-dark'],
        'picked_up'        => ['Picked Up',        'bg-secondary'],
        'completed'        => ['Completed',        'bg-dark'],
        'canceled'         => ['Canceled',         'bg-danger'],
    ];
    [$label, $cls] = $map[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'bg-secondary'];
    return '<span class="badge fs-6 ' . $cls . '">' . htmlspecialchars($label) . '</span>';
}

function wov_note_dot_class(string $type): string
{
    return match ($type) {
        'status_change' => 'bg-primary',
        'system'        => 'bg-secondary',
        default         => 'bg-warning',
    };
}

layout_start('WO: ' . $wo['wo_number'], 'work_orders');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">
            <?= htmlspecialchars($wo['wo_number']) ?>
            <?= wov_status_badge($wo['status']) ?>
            <?php
            $pmap = ['high' => ['High', 'bg-warning text-dark'], 'urgent' => ['Urgent', 'bg-danger']];
            if (isset($pmap[$wo['priority']])):
                [$pl, $pc] = $pmap[$wo['priority']];
            ?>
            <span class="badge <?= $pc ?>"><?= $pl ?></span>
            <?php endif; ?>
        </h1>
        <p class="text-muted mb-0">
            Created <?= date('m/d/Y \a\t g:i A', strtotime($wo['created_at'])) ?>
            by <?= htmlspecialchars($wo['created_by_name'] ?? 'Unknown') ?>
        </p>
    </div>
    <a href="index.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Back to Work Orders
    </a>
</div>

<div class="row g-4">

    <!-- Left Column (8 cols) -->
    <div class="col-lg-8">

        <!-- WO Detail Card -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="fas fa-clipboard-list me-2"></i>Work Order Details</h5>
                <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-edit me-1"></i>Edit
                </a>
            </div>
            <div class="card-body">
                <div class="row g-0">
                    <div class="col-md-6 border-end pe-4">
                        <h6 class="text-uppercase text-muted small mb-3">Customer</h6>
                        <dl class="row mb-0">
                            <dt class="col-sm-5 text-muted small">Name</dt>
                            <dd class="col-sm-7 fw-semibold"><?= htmlspecialchars($wo['cust_name']) ?></dd>
                            <?php if (!empty($wo['cust_phone'])): ?>
                            <dt class="col-sm-5 text-muted small">Phone</dt>
                            <dd class="col-sm-7"><?= htmlspecialchars($wo['cust_phone']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($wo['cust_email'])): ?>
                            <dt class="col-sm-5 text-muted small">Email</dt>
                            <dd class="col-sm-7"><?= htmlspecialchars($wo['cust_email']) ?></dd>
                            <?php endif; ?>
                        </dl>
                        <hr class="my-3">
                        <h6 class="text-uppercase text-muted small mb-3">Service Location</h6>
                        <dl class="row mb-0">
                            <?php if (!empty($wo['service_address'])): ?>
                            <dt class="col-sm-5 text-muted small">Address</dt>
                            <dd class="col-sm-7"><?= htmlspecialchars($wo['service_address']) ?></dd>
                            <?php endif; ?>
                            <?php
                            $city_line = implode(', ', array_filter([$wo['service_city'] ?? '', $wo['service_state'] ?? '']));
                            if (!empty($wo['service_zip'])) $city_line .= ' ' . $wo['service_zip'];
                            ?>
                            <?php if (trim($city_line)): ?>
                            <dt class="col-sm-5 text-muted small">City/State</dt>
                            <dd class="col-sm-7"><?= htmlspecialchars(trim($city_line)) ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                    <div class="col-md-6 ps-4">
                        <h6 class="text-uppercase text-muted small mb-3">Job Details</h6>
                        <dl class="row mb-0">
                            <?php if (!empty($wo['size'])): ?>
                            <dt class="col-sm-6 text-muted small">Size</dt>
                            <dd class="col-sm-6"><?= htmlspecialchars($wo['size']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($wo['project_type'])): ?>
                            <dt class="col-sm-6 text-muted small">Project</dt>
                            <dd class="col-sm-6"><?= htmlspecialchars($wo['project_type']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($wo['dumpster_code'])): ?>
                            <dt class="col-sm-6 text-muted small">Dumpster</dt>
                            <dd class="col-sm-6">
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($wo['dumpster_code']) ?></span>
                            </dd>
                            <?php endif; ?>
                            <?php if (!empty($wo['delivery_date'])): ?>
                            <dt class="col-sm-6 text-muted small">Delivery</dt>
                            <dd class="col-sm-6"><?= date('m/d/Y', strtotime($wo['delivery_date'])) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($wo['pickup_date'])): ?>
                            <dt class="col-sm-6 text-muted small">Sched. Pickup</dt>
                            <dd class="col-sm-6"><?= date('m/d/Y', strtotime($wo['pickup_date'])) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($wo['actual_pickup'])): ?>
                            <dt class="col-sm-6 text-muted small">Actual Pickup</dt>
                            <dd class="col-sm-6"><?= date('m/d/Y', strtotime($wo['actual_pickup'])) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($wo['driver_name'])): ?>
                            <dt class="col-sm-6 text-muted small">Driver</dt>
                            <dd class="col-sm-6"><?= htmlspecialchars($wo['driver_name']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($wo['amount'])): ?>
                            <dt class="col-sm-6 text-muted small">Amount</dt>
                            <dd class="col-sm-6 fw-semibold">$<?= number_format((float)$wo['amount'], 2) ?></dd>
                            <?php endif; ?>

                        </dl>
                    </div>
                </div>

                <?php if (!empty($wo['internal_notes'])): ?>
                <hr>
                <h6 class="text-uppercase text-muted small mb-2">Internal Notes</h6>
                <p class="mb-0"><?= nl2br(htmlspecialchars($wo['internal_notes'])) ?></p>
                <?php endif; ?>

                <?php if (!empty($wo['footer_notes'])): ?>
                <hr>
                <h6 class="text-uppercase text-muted small mb-2">Footer Notes</h6>
                <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars($wo['footer_notes'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Photos -->
        <div class="card shadow-sm mb-4" id="photos">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="fas fa-camera me-2"></i>Photos
                    <?php if (!empty($photos)): ?>
                    <span class="badge bg-secondary ms-1"><?= count($photos) ?></span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">

                <?php if (!empty($photos)): ?>
                <div class="row g-2 mb-4">
                    <?php foreach ($photos as $idx => $photo): ?>
                    <?php $photo_url = '/uploads/wo_photos/' . $wo['id'] . '/' . rawurlencode($photo['filename']); ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="position-relative" style="border-radius:6px;overflow:hidden;background:#1a1d27;">
                            <img src="<?= e($photo_url) ?>"
                                 alt="<?= e($photo['caption'] ?? 'Photo ' . ($idx + 1)) ?>"
                                 class="img-fluid w-100"
                                 style="height:140px;object-fit:cover;cursor:pointer;display:block;"
                                 onclick="openLightbox('<?= e($photo_url) ?>', '<?= e(addslashes($photo['caption'] ?? '')) ?>')"
                                 loading="lazy">
                            <div class="position-absolute bottom-0 start-0 end-0 px-2 py-1"
                                 style="background:rgba(0,0,0,.55);font-size:.72rem;color:#e5e7eb;">
                                <?php if (!empty($photo['caption'])): ?>
                                <div class="text-truncate"><?= e($photo['caption']) ?></div>
                                <?php endif; ?>
                                <div style="color:#9ca3af;"><?= e(date('m/d/Y', strtotime($photo['created_at']))) ?>
                                    <?php if (!empty($photo['uploader_name'])): ?>&nbsp;· <?= e($photo['uploader_name']) ?><?php endif; ?>
                                </div>
                            </div>
                            <!-- Delete button -->
                            <form method="POST" action="delete_photo.php"
                                  class="position-absolute top-0 end-0 m-1"
                                  onsubmit="return confirm('Delete this photo?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>">
                                <input type="hidden" name="wo_id"    value="<?= $id ?>">
                                <button type="submit"
                                        class="btn btn-sm"
                                        style="background:rgba(0,0,0,.6);color:#f87171;border:none;padding:2px 6px;line-height:1.2;"
                                        title="Delete photo">
                                    <i class="fas fa-trash" style="font-size:.7rem;"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted mb-3" style="font-size:.9rem;">No photos yet. Upload delivery, placement, or pickup photos below.</p>
                <?php endif; ?>

                <!-- Upload Form -->
                <form method="POST" action="upload_photo.php" enctype="multipart/form-data" id="photo-upload-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="wo_id" value="<?= $id ?>">

                    <div class="upload-drop-zone mb-2" id="dropZone"
                         onclick="document.getElementById('photo-file-input').click()"
                         style="border:2px dashed #374151;border-radius:8px;padding:24px;text-align:center;cursor:pointer;transition:border-color .2s;">
                        <i class="fas fa-cloud-upload-alt fa-2x mb-2" style="color:#6b7280;"></i>
                        <div style="color:#9ca3af;font-size:.9rem;">
                            Click to select photos, or drag &amp; drop here<br>
                            <small>JPEG, PNG, WebP, GIF &bull; Up to 15 MB each &bull; Multiple allowed</small>
                        </div>
                        <div id="photo-file-names" class="mt-2" style="color:#f97316;font-size:.85rem;display:none;"></div>
                    </div>

                    <input type="file" id="photo-file-input" name="photos[]"
                           accept="image/*" multiple
                           class="d-none"
                           onchange="showFileNames(this)">

                    <div class="row g-2 mt-1">
                        <div class="col-md-8">
                            <input type="text" name="caption" class="form-control form-control-sm"
                                   placeholder="Caption (optional — applies to all photos in this upload)">
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary btn-sm w-100" id="upload-btn" disabled>
                                <i class="fas fa-upload me-1"></i> Upload
                            </button>
                        </div>
                    </div>
                </form>

            </div>
        </div>

        <!-- Notes Timeline -->
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-history me-2"></i>Activity &amp; Notes</h5>
            </div>
            <div class="card-body">
                <?php if (empty($notes)): ?>
                <p class="text-muted mb-0">No notes yet.</p>
                <?php else: ?>
                <div class="timeline">
                    <?php foreach ($notes as $note): ?>
                    <div class="tl-item d-flex gap-3 mb-3">
                        <div class="tl-dot mt-1">
                            <span class="d-inline-block rounded-circle <?= wov_note_dot_class($note['note_type']) ?>"
                                  style="width:12px;height:12px;"></span>
                        </div>
                        <div class="tl-body flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="fw-semibold small"><?= htmlspecialchars($note['author_name'] ?? 'System') ?></span>
                                    <span class="text-muted small ms-2"><?= date('m/d/Y g:i A', strtotime($note['created_at'])) ?></span>
                                </div>
                                <?php if ($note['note_type'] !== 'note'): ?>
                                <span class="badge bg-light text-muted border small">
                                    <?= ucfirst(str_replace('_', ' ', $note['note_type'])) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="mb-0 mt-1"><?= nl2br(htmlspecialchars($note['note'])) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Add Note Form -->
                <hr>
                <h6 class="text-uppercase text-muted small mb-3">Add Note</h6>
                <form method="post" action="add_note.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="wo_id" value="<?= $id ?>">
                    <div class="mb-3">
                        <textarea name="note" class="form-control" rows="3"
                                  placeholder="Add a note…" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus me-1"></i>Add Note
                    </button>
                </form>
            </div>
        </div>

    </div>

    <!-- Right Column (4 cols) -->
    <div class="col-lg-4">

        <!-- Status Updater -->
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-sync me-2"></i>Update Status</h5>
            </div>
            <div class="card-body">
                <p class="mb-3">Current: <?= wov_status_badge($wo['status']) ?></p>
                <form method="post" action="update_status.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="wo_id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label for="new_status" class="form-label">New Status</label>
                        <select id="new_status" name="new_status" class="form-select">
                            <?php
                            $all_statuses = [
                                'scheduled'        => 'Scheduled',
                                'delivered'        => 'Delivered',
                                'active'           => 'Active',
                                'pickup_requested' => 'Pickup Requested',
                                'picked_up'        => 'Picked Up',
                                'completed'        => 'Completed',
                                'canceled'         => 'Canceled',
                            ];
                            foreach ($all_statuses as $sv => $sl):
                            ?>
                                <option value="<?= $sv ?>" <?= $wo['status'] === $sv ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-check me-1"></i>Update Status
                    </button>
                </form>
                <p class="text-muted small mt-2 mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    Progression: Scheduled → Delivered → Active → Pickup Requested → Picked Up → Completed
                </p>
            </div>
        </div>

        <!-- Assignment Card -->
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-user-hard-hat me-2"></i>Assignment</h5>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted small">Driver</dt>
                    <dd class="col-sm-7">
                        <?= !empty($wo['driver_name'])
                            ? htmlspecialchars($wo['driver_name'])
                            : '<span class="text-muted">Unassigned</span>' ?>
                    </dd>
                    <dt class="col-sm-5 text-muted small">Dumpster</dt>
                    <dd class="col-sm-7">
                        <?php if (!empty($wo['dumpster_code'])): ?>
                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($wo['dumpster_code']) ?></span>
                        <?php if (!empty($wo['dumpster_size'])): ?>
                        <small class="text-muted ms-1"><?= htmlspecialchars($wo['dumpster_size']) ?></small>
                        <?php endif; ?>
                        <?php if (!empty($wo['dumpster_status'])): ?>
                        <br><small class="text-muted">Status: <?= ucfirst($wo['dumpster_status']) ?></small>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted">Unassigned</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
            </div>
            <div class="card-body d-grid gap-2">
                <a href="?id=<?= $id ?>&print=1" target="_blank" class="btn btn-outline-secondary">
                    <i class="fas fa-print me-1"></i>Print / PDF
                </a>
                <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-primary">
                    <i class="fas fa-edit me-1"></i>Edit Work Order
                </a>
                <?php if (empty($wo['customer_id'])): ?>
                <a href="../customers/create.php?wo_id=<?= $id ?>" class="btn btn-outline-success">
                    <i class="fas fa-user-plus me-1"></i>Convert to Customer
                </a>
                <?php endif; ?>

                <a href="delete.php?id=<?= $id ?>" class="btn btn-outline-danger"
                   data-confirm="Delete this work order? This cannot be undone.">
                    <i class="fas fa-trash me-1"></i>Delete Work Order
                </a>
            </div>
        </div>

        <!-- Invoice Card -->
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-file-invoice me-2"></i>Invoice</h5>
            </div>
            <div class="card-body">
                <?php if ($linked_invoice): ?>
                <p class="text-muted small mb-2">A real invoice has already been created for this work order.</p>
                <div class="mb-3 p-2 rounded border bg-light">
                    <div class="fw-semibold"><?= e($linked_invoice['invoice_number']) ?></div>
                    <div class="small text-muted">
                        <?= e(fmt_money($linked_invoice['total'] ?? 0)) ?> · <?= e(ucfirst((string)($linked_invoice['status'] ?? 'draft'))) ?>
                    </div>
                </div>
                <a href="<?= e(APP_URL) ?>/modules/invoices/view.php?id=<?= (int)$linked_invoice['id'] ?>" class="btn btn-sm btn-primary w-100 mb-2">
                    <i class="fas fa-eye me-1"></i> View Real Invoice
                </a>
                <?php else: ?>
                <p class="text-muted small mb-3">Create a real invoice from this work order with an online payment link, or print the standalone document version.</p>
                <form method="POST" action="create_invoice.php" class="mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="wo_id" value="<?= $id ?>">
                    <div class="mb-2">
                        <label class="form-label small mb-1">Invoice Payment Method</label>
                        <select name="payment_method" class="form-select form-select-sm">
                            <option value="stripe">Card Checkout</option>
                            <option value="ach">ACH Checkout</option>
                            <option value="cash">Cash</option>
                            <option value="check">Check</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="fas fa-file-circle-plus me-1"></i> Create Real Invoice
                    </button>
                </form>
                <?php endif; ?>
                <a href="<?= e(APP_URL) ?>/modules/work_orders/invoice.php?wo_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary w-100">
                    <i class="fas fa-print me-1"></i> Print Standalone Document
                </a>
            </div>
        </div>

        <!-- WO Meta -->
        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-info-circle me-2"></i>Work Order Info</h5>
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-5 text-muted">WO #</dt>
                    <dd class="col-sm-7 fw-semibold"><?= htmlspecialchars($wo['wo_number']) ?></dd>

                    <dt class="col-sm-5 text-muted">Created</dt>
                    <dd class="col-sm-7"><?= date('m/d/Y', strtotime($wo['created_at'])) ?></dd>

                    <dt class="col-sm-5 text-muted">Updated</dt>
                    <dd class="col-sm-7"><?= date('m/d/Y', strtotime($wo['updated_at'])) ?></dd>

                    <dt class="col-sm-5 text-muted">Created By</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars($wo['created_by_name'] ?? '—') ?></dd>

                    <dt class="col-sm-5 text-muted">Priority</dt>
                    <dd class="col-sm-7"><?= ucfirst($wo['priority'] ?? 'normal') ?></dd>
                </dl>
            </div>
        </div>

    </div>
</div>

<!-- Lightbox Modal -->
<div class="modal fade" id="lightboxModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="background:#0d0f1a;border:none;">
            <div class="modal-header" style="border-color:#1f2337;padding:.75rem 1rem;">
                <span id="lightboxCaption" class="text-muted" style="font-size:.9rem;"></span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2 text-center">
                <img id="lightboxImg" src="" alt="" style="max-width:100%;max-height:80vh;object-fit:contain;border-radius:4px;">
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
    const files = Array.from(input.files);
    const nameEl = document.getElementById('photo-file-names');
    const btn    = document.getElementById('upload-btn');
    if (files.length) {
        nameEl.textContent = files.length === 1
            ? files[0].name
            : files.length + ' files selected';
        nameEl.style.display = '';
        btn.disabled = false;
        document.getElementById('dropZone').style.borderColor = '#f97316';
    } else {
        nameEl.style.display = 'none';
        btn.disabled = true;
        document.getElementById('dropZone').style.borderColor = '#374151';
    }
}

// Drag & drop support
(function() {
    const zone  = document.getElementById('dropZone');
    const input = document.getElementById('photo-file-input');
    if (!zone || !input) return;

    zone.addEventListener('dragover', e => {
        e.preventDefault();
        zone.style.borderColor = '#f97316';
    });
    zone.addEventListener('dragleave', () => {
        if (!input.files.length) zone.style.borderColor = '#374151';
    });
    zone.addEventListener('drop', e => {
        e.preventDefault();
        const dt = e.dataTransfer;
        if (dt && dt.files.length) {
            input.files = dt.files;
            showFileNames(input);
        }
    });
})();
</script>

<?php layout_end(); ?>
