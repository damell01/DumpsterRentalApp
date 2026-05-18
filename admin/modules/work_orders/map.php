<?php
/**
 * Work Orders – Dispatch Map
 * Trash Panda Roll-Offs
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();

// ── View mode & date ──────────────────────────────────────────────────────────
$view_mode = ($_GET['view'] ?? 'day') === 'active' ? 'active' : 'day';

$raw_date  = trim($_GET['date'] ?? '');
$view_date = ($raw_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_date)) ? $raw_date : date('Y-m-d');
$today     = date('Y-m-d');
$is_today  = ($view_date === $today);

// ── Queries ───────────────────────────────────────────────────────────────────
$addr_cols = 'wo.service_address, wo.service_city, wo.service_state, wo.service_zip, wo.cust_phone';

if ($view_mode === 'active') {
    // All dumpsters currently on-site, regardless of date
    $all_jobs = db_try_fetchall(
        "SELECT wo.id, wo.wo_number, wo.cust_name, wo.status,
                wo.delivery_date, wo.pickup_date, wo.priority,
                $addr_cols, d.size AS dumpster_size, d.unit_code AS dumpster_code
         FROM work_orders wo
         LEFT JOIN dumpsters d ON wo.dumpster_id = d.id
         WHERE wo.status IN ('scheduled','delivered','active','pickup_requested','picked_up')
         ORDER BY wo.status ASC, wo.wo_number ASC"
    );
    $deliveries = [];
    $pickups    = [];
    // Group for display
    foreach ($all_jobs as $wo) {
        if (in_array($wo['status'], ['scheduled', 'delivered', 'active'], true)) {
            $deliveries[] = array_merge($wo, ['job_type' => 'delivery']);
        } else {
            $pickups[] = array_merge($wo, ['job_type' => 'pickup']);
        }
    }
    $all_jobs = array_map(fn($wo) => array_merge($wo, [
        'job_type' => in_array($wo['status'], ['scheduled', 'delivered', 'active'], true) ? 'delivery' : 'pickup',
    ]), $all_jobs);
} else {
    $deliveries = db_try_fetchall(
        "SELECT wo.id, wo.wo_number, wo.cust_name, wo.status,
                wo.delivery_date, wo.pickup_date, wo.priority,
                $addr_cols, d.size AS dumpster_size, d.unit_code AS dumpster_code
         FROM work_orders wo
         LEFT JOIN dumpsters d ON wo.dumpster_id = d.id
         WHERE wo.delivery_date = ? AND wo.status NOT IN ('completed','canceled','picked_up')
         ORDER BY wo.wo_number ASC",
        [$view_date]
    );
    $pickups = db_try_fetchall(
        "SELECT wo.id, wo.wo_number, wo.cust_name, wo.status,
                wo.delivery_date, wo.pickup_date, wo.priority,
                $addr_cols, d.size AS dumpster_size, d.unit_code AS dumpster_code
         FROM work_orders wo
         LEFT JOIN dumpsters d ON wo.dumpster_id = d.id
         WHERE (
             (wo.pickup_date = ? AND wo.status NOT IN ('completed','canceled','picked_up'))
             OR (wo.pickup_date < ? AND wo.status = 'pickup_requested')
         )
         ORDER BY wo.pickup_date ASC, wo.wo_number ASC",
        [$view_date, $view_date]
    );
    $all_jobs = [];
    foreach ($deliveries as $wo) { $all_jobs[] = array_merge($wo, ['job_type' => 'delivery']); }
    foreach ($pickups  as $wo) { $all_jobs[] = array_merge($wo, ['job_type' => 'pickup']); }
}

// ── Geocode cache lookup ──────────────────────────────────────────────────────
function build_full_address(array $wo): string
{
    return strtolower(trim(implode(', ', array_filter([
        $wo['service_address'],
        $wo['service_city'],
        $wo['service_state'],
        $wo['service_zip'],
    ]))));
}

if (!empty($all_jobs)) {
    $hashes = array_map(fn($wo) => md5(build_full_address($wo)), $all_jobs);
    $ph     = implode(',', array_fill(0, count($hashes), '?'));
    try {
        $cached = db_fetchall("SELECT address_hash, lat, lng FROM geocode_cache WHERE address_hash IN ($ph)", $hashes);
    } catch (\Throwable $e) {
        $cached = [];
    }
    $cache_map = array_column($cached, null, 'address_hash');

    foreach ($all_jobs as &$wo) {
        $full = build_full_address($wo);
        $hash = md5($full);
        $wo['full_address'] = trim(implode(', ', array_filter([
            $wo['service_address'],
            $wo['service_city'],
            $wo['service_state'],
            $wo['service_zip'],
        ])));
        $wo['lat'] = isset($cache_map[$hash]) ? (float)$cache_map[$hash]['lat'] : null;
        $wo['lng'] = isset($cache_map[$hash]) ? (float)$cache_map[$hash]['lng'] : null;
    }
    unset($wo);
    // Re-sync deliveries/pickups arrays with enriched all_jobs
    $id_map = array_column($all_jobs, null, 'id');
    foreach ($deliveries as &$d) { if (isset($id_map[$d['id']])) $d = $id_map[$d['id']]; } unset($d);
    foreach ($pickups   as &$p) { if (isset($id_map[$p['id']])) $p = $id_map[$p['id']]; } unset($p);
}

$cached_count    = count(array_filter($all_jobs, fn($wo) => $wo['lat'] !== null));
$missing_count   = count($all_jobs) - $cached_count;
$company_address = get_setting('company_address', '');
$company_name    = get_setting('company_name', 'Dispatch');

// ── Print route sheet ─────────────────────────────────────────────────────────
if (isset($_GET['print']) && !empty($all_jobs)) {
    // Reorder jobs if a route order was passed from the map JS
    $ordered_jobs = $all_jobs;
    if (!empty($_GET['order'])) {
        $raw_order = preg_replace('/[^0-9,]/', '', $_GET['order']);
        $order_ids = array_filter(array_map('intval', explode(',', $raw_order)));
        $id_index  = array_column($all_jobs, null, 'id');
        $arranged  = [];
        foreach ($order_ids as $oid) {
            if (isset($id_index[$oid])) { $arranged[] = $id_index[$oid]; unset($id_index[$oid]); }
        }
        // Append any jobs not in the order param (safety net)
        $ordered_jobs = array_merge($arranged, array_values($id_index));
    }

    $print_title = $view_mode === 'active'
        ? 'Active Dumpsters — ' . date('M j, Y')
        : 'Route Sheet — ' . date('l, M j, Y', strtotime($view_date));

    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($print_title) ?> | <?= htmlspecialchars($company_name) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,Helvetica,sans-serif;font-size:11pt;color:#111;padding:18px 24px;}
h1{font-size:15pt;margin-bottom:2px;}
.meta{color:#555;font-size:9.5pt;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:9.5pt;}
th{background:#f3f4f6;border:1px solid #d1d5db;padding:5px 7px;text-align:left;font-size:9pt;}
td{border:1px solid #d1d5db;padding:5px 7px;vertical-align:top;}
.type-d{color:#c2410c;font-weight:700;}
.type-p{color:#1d4ed8;font-weight:700;}
tr:nth-child(even) td{background:#fafafa;}
.phone{color:#374151;}
@media print{body{padding:0;}@page{margin:.6in;}}
</style>
</head>
<body>
<h1><?= htmlspecialchars($company_name) ?> — <?= htmlspecialchars($print_title) ?></h1>
<div class="meta">Printed <?= date('M j, Y g:i A') ?> · <?= count($ordered_jobs) ?> stops</div>
<table>
<thead><tr>
    <th>#</th><th>WO #</th><th>Customer</th><th>Address</th>
    <th>Phone</th><th>Size / Unit</th><th>Type</th><th>Status</th>
</tr></thead>
<tbody>
<?php foreach ($ordered_jobs as $i => $wo):
    $type = ($wo['job_type'] ?? 'delivery') === 'delivery' ? 'Delivery' : 'Pickup';
    $cls  = $type === 'Delivery' ? 'type-d' : 'type-p';
    $dumpster = trim(($wo['dumpster_size'] ?? '') . ($wo['dumpster_code'] ? ' · ' . $wo['dumpster_code'] : ''));
?>
<tr>
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($wo['wo_number']) ?></td>
    <td><?= htmlspecialchars($wo['cust_name']) ?></td>
    <td><?= htmlspecialchars($wo['full_address'] ?? $wo['service_address'] ?? '') ?></td>
    <td class="phone"><?= htmlspecialchars($wo['cust_phone'] ?? '') ?></td>
    <td><?= htmlspecialchars($dumpster ?: '—') ?></td>
    <td class="<?= $cls ?>"><?= $type ?></td>
    <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $wo['status']))) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<script>window.print();</script>
</body>
</html>
<?php
    exit;
}

layout_start('Dispatch Map', 'dispatch_map');
?>

<!-- ── Header ──────────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Dispatch Map</h5>
        <small style="color:var(--gy);">
            <?php if ($view_mode === 'active'): ?>
                All active dumpsters — <?= count($all_jobs) ?> on-site
            <?php else: ?>
                <?= date('l, F j, Y', strtotime($view_date)) ?>
                &mdash;
                <?= count($deliveries) ?> deliver<?= count($deliveries) === 1 ? 'y' : 'ies' ?>,
                <?= count($pickups) ?> pickup<?= count($pickups) === 1 ? '' : 's' ?>
            <?php endif; ?>
            <?php if ($missing_count > 0): ?>
                <span id="geocode-status" class="ms-2" style="color:var(--gl);">
                    <i class="fa-solid fa-circle-notch fa-spin" style="font-size:.7rem;"></i>
                    Loading <span id="geocode-remaining"><?= $missing_count ?></span> address<?= $missing_count === 1 ? '' : 'es' ?>…
                </span>
            <?php endif; ?>
        </small>
    </div>
    <!-- Tab switcher -->
    <div class="d-flex" style="border:1px solid var(--bd);border-radius:6px;overflow:hidden;">
        <button id="tab-btn-dispatch" onclick="switchMapTab('dispatch')"
                class="btn-tp-xs px-3 py-2 btn-tp-primary" style="border-radius:0;border:none;">
            <i class="fa-solid fa-calendar-day me-1"></i>Dispatch
        </button>
        <button id="tab-btn-builder" onclick="switchMapTab('builder')"
                class="btn-tp-xs px-3 py-2 btn-tp-ghost" style="border-radius:0;border:none;border-left:1px solid var(--bd);">
            <i class="fa-solid fa-route me-1"></i>Route Builder
        </button>
    </div>
</div>

<!-- ── Dispatch tab controls ────────────────────────────────────────────────── -->
<div id="tab-dispatch-bar" class="d-flex gap-2 flex-wrap align-items-center mb-3">
    <div class="d-flex" style="border:1px solid var(--bd);border-radius:6px;overflow:hidden;">
        <a href="?view=day&date=<?= urlencode($view_date) ?>"
           class="btn-tp-xs px-3 py-1 text-decoration-none <?= $view_mode === 'day' ? 'btn-tp-primary' : 'btn-tp-ghost' ?>"
           style="border-radius:0;">
            <i class="fa-solid fa-calendar-day me-1"></i>By Date
        </a>
        <a href="?view=active"
           class="btn-tp-xs px-3 py-1 text-decoration-none <?= $view_mode === 'active' ? 'btn-tp-primary' : 'btn-tp-ghost' ?>"
           style="border-radius:0;border-left:1px solid var(--bd);">
            <i class="fa-solid fa-dumpster me-1"></i>All Active
        </a>
    </div>

    <?php if ($view_mode === 'day'): ?>
    <input type="date" id="date-picker" class="form-control form-control-sm"
           style="width:150px;" value="<?= htmlspecialchars($view_date) ?>"
           title="View a different date">
    <?php endif; ?>

    <span class="tp-badge d-none d-sm-inline" style="background:rgba(249,115,22,.15);color:#f97316;border:1px solid rgba(249,115,22,.3);">
        <i class="fa-solid fa-truck me-1"></i>Delivery
    </span>
    <span class="tp-badge d-none d-sm-inline" style="background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid rgba(59,130,246,.3);">
        <i class="fa-solid fa-box-open me-1"></i>Pickup
    </span>

    <?php if (count($all_jobs) >= 2): ?>
    <button id="btn-optimize" class="btn-tp-primary btn-tp-sm"
            <?= $missing_count > 0 ? 'disabled title="Waiting for addresses to load…"' : '' ?>>
        <i class="fa-solid fa-route"></i> Optimize Route
    </button>
    <button id="btn-clear-route" class="btn-tp-ghost btn-tp-sm" style="display:none;">
        <i class="fa-solid fa-xmark"></i> Clear
    </button>
    <?php endif; ?>

    <a href="index.php" class="btn-tp-ghost btn-tp-sm">
        <i class="fa-solid fa-list"></i> List
    </a>
</div>

<!-- ── Dispatch tab content ─────────────────────────────────────────────────── -->
<div id="tab-dispatch-content">

<!-- ── Map ─────────────────────────────────────────────────────────────────── -->
<div class="tp-card p-0 mb-3" style="overflow:hidden;position:relative;">
    <div id="dispatch-map" style="height:540px;width:100%;"></div>
    <div id="map-spinner" style="display:none;position:absolute;top:10px;right:10px;
         background:rgba(15,15,15,.85);color:#fff;padding:.4rem .8rem;border-radius:8px;
         font-size:.8rem;z-index:1000;backdrop-filter:blur(4px);">
        <i class="fa-solid fa-circle-notch fa-spin me-1"></i>
        <span id="spinner-msg">Calculating route…</span>
    </div>
</div>

<!-- ── Route panel ─────────────────────────────────────────────────────────── -->
<div id="route-panel" class="tp-card mb-3" style="display:none;">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h6 class="mb-0"><i class="fa-solid fa-route me-2" style="color:#f97316;"></i>Optimized Route</h6>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span id="route-meta" style="color:var(--gy);font-size:.82rem;"></span>
            <a id="btn-gmaps" href="#" target="_blank" rel="noopener" class="btn-tp-ghost btn-tp-sm" style="display:none;">
                <i class="fa-solid fa-map-location-dot"></i> Google Maps
            </a>
            <a id="btn-print" href="#" target="_blank" rel="noopener" class="btn-tp-ghost btn-tp-sm" style="display:none;">
                <i class="fa-solid fa-print"></i> Print Sheet
            </a>
        </div>
    </div>
    <ol id="route-list" class="mb-0 ps-4"></ol>
</div>

<?php if (empty($all_jobs)): ?>
<div class="tp-card text-center py-5">
    <i class="fa-solid fa-truck-fast" style="font-size:2.5rem;color:var(--gl);margin-bottom:1rem;display:block;"></i>
    <div style="font-weight:600;color:var(--wh);margin-bottom:.35rem;">
        <?= $view_mode === 'active' ? 'No dumpsters currently on-site' : 'No jobs scheduled for this date' ?>
    </div>
    <div style="color:var(--gy);font-size:.9rem;">
        <?php if ($view_mode === 'day'): ?>
            <a href="?view=active">View all active dumpsters</a> or
            <a href="index.php">check the work orders list</a>.
        <?php else: ?>
            <a href="index.php">Check the work orders list</a> for upcoming jobs.
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<!-- ── Job cards ───────────────────────────────────────────────────────────── -->
<div class="row g-3">
    <?php if (!empty($deliveries)): ?>
    <div class="col-md-6">
        <div class="tp-card p-0" style="overflow:hidden;">
            <div class="tp-card-header" style="background:linear-gradient(90deg,rgba(249,115,22,.12) 0%,transparent 70%);">
                <h5 class="mb-0" style="font-size:.95rem;">
                    <i class="fa-solid fa-truck me-2" style="color:#f97316;"></i>
                    <?= $view_mode === 'active' ? 'On-Site / Deliveries' : 'Deliveries' ?>
                    (<?= count($deliveries) ?>)
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table tp-table mb-0">
                    <thead><tr>
                        <th style="width:2rem;">#</th>
                        <th>WO #</th>
                        <th>Customer</th>
                        <th class="d-none d-lg-table-cell">Address</th>
                        <th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($deliveries as $wo): ?>
                    <tr class="dispatch-row" data-job-id="<?= (int)$wo['id'] ?>"
                        data-href="view.php?id=<?= (int)$wo['id'] ?>" style="cursor:pointer;">
                        <td class="route-step text-muted" style="font-size:.75rem;">—</td>
                        <td><strong><?= e($wo['wo_number']) ?></strong></td>
                        <td><?= e($wo['cust_name']) ?></td>
                        <td class="d-none d-lg-table-cell" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem;color:var(--gy);">
                            <?= e($wo['service_address'] ?? '—') ?>
                        </td>
                        <td><?= status_badge($wo['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($pickups)): ?>
    <div class="col-md-6">
        <div class="tp-card p-0" style="overflow:hidden;">
            <div class="tp-card-header" style="background:linear-gradient(90deg,rgba(59,130,246,.1) 0%,transparent 70%);">
                <h5 class="mb-0" style="font-size:.95rem;">
                    <i class="fa-solid fa-box-open me-2" style="color:#3b82f6;"></i>
                    Pickups<?= $view_mode === 'active' ? ' / Pickup Requested' : '' ?>
                    (<?= count($pickups) ?>)
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table tp-table mb-0">
                    <thead><tr>
                        <th style="width:2rem;">#</th>
                        <th>WO #</th>
                        <th>Customer</th>
                        <th class="d-none d-lg-table-cell">Address</th>
                        <th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($pickups as $wo): ?>
                    <?php $overdue = ($wo['pickup_date'] ?? '') < $today && $wo['status'] === 'pickup_requested'; ?>
                    <tr class="dispatch-row" data-job-id="<?= (int)$wo['id'] ?>"
                        data-href="view.php?id=<?= (int)$wo['id'] ?>" style="cursor:pointer;<?= $overdue ? 'background:rgba(239,68,68,.04)!important;' : '' ?>">
                        <td class="route-step text-muted" style="font-size:.75rem;">—</td>
                        <td><strong><?= e($wo['wo_number']) ?></strong><?= $overdue ? ' <i class="fa-solid fa-circle-exclamation" style="color:#ef4444;font-size:.75rem;" title="Overdue"></i>' : '' ?></td>
                        <td><?= e($wo['cust_name']) ?></td>
                        <td class="d-none d-lg-table-cell" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem;color:var(--gy);">
                            <?= e($wo['service_address'] ?? '—') ?>
                        </td>
                        <td><?= status_badge($wo['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
</div><!-- /tab-dispatch-content -->

<!-- ── Route Builder tab ───────────────────────────────────────────────────── -->
<div id="tab-builder-content" style="display:none;">

    <!-- Controls bar -->
    <div class="tp-card mb-3" style="padding:.75rem 1rem;">
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <div class="d-flex gap-1" style="flex:1;min-width:200px;max-width:480px;">
                <div id="rb-ac-wrap" style="position:relative;flex:1;min-width:0;">
                    <input id="rb-addr-input" type="text" class="form-control form-control-sm"
                           placeholder="Search address to add as a stop…" autocomplete="off">
                    <ul id="rb-ac-list" style="display:none;"></ul>
                </div>
                <button id="rb-addr-btn" class="btn-tp-primary btn-tp-sm" style="white-space:nowrap;">
                    <i class="fa-solid fa-plus me-1"></i>Add
                </button>
            </div>
            <button id="rb-click-toggle" class="btn-tp-ghost btn-tp-sm">
                <i class="fa-solid fa-map-pin me-1"></i>Click Map to Pin
            </button>
            <label class="d-flex align-items-center gap-2 mb-0" style="font-size:.84rem;cursor:pointer;user-select:none;">
                <input type="checkbox" id="rb-use-depot"> Start from depot
            </label>
            <div class="ms-auto d-flex gap-2 flex-wrap">
                <button id="rb-optimize-btn" class="btn-tp-primary btn-tp-sm" disabled>
                    <i class="fa-solid fa-route me-1"></i>Optimize
                </button>
                <button id="rb-clear-route-btn" class="btn-tp-ghost btn-tp-sm" style="display:none;">
                    <i class="fa-solid fa-xmark me-1"></i>Clear Route
                </button>
                <button id="rb-clear-all-btn" class="btn-tp-ghost btn-tp-sm" style="display:none;">
                    <i class="fa-solid fa-trash me-1"></i>Clear All
                </button>
            </div>
        </div>
        <div id="rb-addr-error" style="display:none;color:#ef4444;font-size:.8rem;margin-top:.35rem;"></div>
    </div>

    <!-- Map + stop list -->
    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="tp-card p-0" style="overflow:hidden;position:relative;">
                <div id="builder-map" style="height:490px;width:100%;"></div>
                <div id="rb-spinner" style="display:none;position:absolute;top:10px;right:10px;
                     background:rgba(15,15,15,.85);color:#fff;padding:.35rem .8rem;border-radius:8px;
                     font-size:.8rem;z-index:1000;backdrop-filter:blur(4px);">
                    <i class="fa-solid fa-circle-notch fa-spin me-1"></i>
                    <span id="rb-spinner-msg">Loading…</span>
                </div>
                <div id="rb-click-hint" style="display:none;position:absolute;top:10px;left:50%;
                     transform:translateX(-50%);background:rgba(249,115,22,.92);color:#fff;
                     padding:.3rem 1rem;border-radius:20px;font-size:.8rem;font-weight:600;
                     z-index:1000;pointer-events:none;white-space:nowrap;">
                    <i class="fa-solid fa-map-pin me-1"></i>Click anywhere to drop a stop — press Esc to exit
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="tp-card" style="height:490px;overflow-y:auto;padding:.85rem;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0" style="font-size:.88rem;">
                        <i class="fa-solid fa-list-ol me-1" style="color:#f97316;"></i>
                        Stops
                        <span id="rb-stop-count" class="tp-badge ms-1"
                              style="background:rgba(249,115,22,.15);color:#f97316;font-size:.7rem;">0</span>
                    </h6>
                    <small style="color:var(--gy);font-size:.74rem;">Drag to reorder</small>
                </div>
                <ul id="rb-stop-list" class="list-unstyled mb-0"
                    ondragover="event.preventDefault()" ondrop="rbHandleDrop(event)">
                    <li id="rb-no-stops" style="color:var(--gy);font-size:.84rem;text-align:center;padding:2rem 0;line-height:1.7;">
                        No stops yet.<br>
                        <small>Search an address above<br>or click the map to drop a pin.</small>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Route result panel -->
    <div id="rb-route-panel" class="tp-card mb-3" style="display:none;">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <h6 class="mb-0">
                <i class="fa-solid fa-route me-2" style="color:#f97316;"></i>Optimized Route
            </h6>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span id="rb-route-meta" style="color:var(--gy);font-size:.82rem;"></span>
                <a id="rb-btn-gmaps" href="#" target="_blank" rel="noopener"
                   class="btn-tp-ghost btn-tp-sm" style="display:none;">
                    <i class="fa-solid fa-map-location-dot"></i> Google Maps
                </a>
            </div>
        </div>
        <ol id="rb-route-list" class="mb-0 ps-4"></ol>
    </div>

</div><!-- /tab-builder-content -->

<!-- ── Assets ──────────────────────────────────────────────────────────────── -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>

<style>
/* Popup */
.leaflet-popup-content-wrapper {
    border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.22);
    font-family:var(--font-body,sans-serif);font-size:.84rem;min-width:210px;
}
.leaflet-popup-content { margin:12px 14px; }
.dp-title   { font-weight:700;font-size:.9rem;margin-bottom:.15rem; }
.dp-sub     { color:#6b7280;font-size:.78rem;margin-bottom:.1rem; }
.dp-addr    { color:#6b7280;font-size:.78rem;margin-bottom:.5rem; }
.dp-actions { display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.6rem; }
.dp-btn     { padding:.25rem .6rem;border-radius:5px;font-size:.75rem;font-weight:600;
              cursor:pointer;border:none;line-height:1.3;transition:opacity .15s; }
.dp-btn:hover { opacity:.8; }
.dp-btn-view { background:rgba(249,115,22,.15);color:#f97316; }
.dp-btn-status { background:rgba(59,130,246,.15);color:#3b82f6; }
.dp-btn-status.done { background:rgba(34,197,94,.15);color:#16a34a; }
/* Row highlight */
.dispatch-row.map-highlight > td { background:rgba(249,115,22,.08)!important; }
/* Route panel */
.rp-step { display:flex;align-items:flex-start;gap:.45rem;margin-bottom:.6rem;font-size:.87rem;line-height:1.4; }
.rp-num  { display:inline-flex;align-items:center;justify-content:center;
           min-width:1.4rem;height:1.4rem;border-radius:50%;font-size:.7rem;
           font-weight:700;color:#fff;flex-shrink:0;margin-top:.05rem; }
/* View toggle active */
.btn-tp-xs { font-size:.78rem;line-height:1; }
#tab-btn-dispatch, #tab-btn-builder { cursor:pointer; transition:background .15s,color .15s; }
/* ── Route Builder ──────────────────────────────────────────────────────────── */
.rb-stop-item {
    display:flex;align-items:center;gap:.5rem;padding:.4rem .2rem;
    border-bottom:1px solid var(--bd);cursor:default;border-radius:4px;transition:background .1s;
}
.rb-stop-item:last-child { border-bottom:none; }
.rb-stop-item:hover { background:rgba(249,115,22,.05); }
.rb-stop-item.rb-drag-over-top    { border-top:2px solid #f97316!important; }
.rb-stop-item.rb-drag-over-bottom { border-bottom:2px solid #f97316!important; }
.rb-drag-handle { color:var(--gy);font-size:.88rem;cursor:grab;flex-shrink:0;padding:0 .15rem;line-height:1; }
.rb-drag-handle:active { cursor:grabbing; }
.rb-stop-num {
    display:inline-flex;align-items:center;justify-content:center;
    min-width:1.5rem;height:1.5rem;border-radius:50%;
    background:#f97316;color:#fff;font-size:.7rem;font-weight:700;flex-shrink:0;
}
.rb-stop-label { flex:1;font-size:.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0; }
.rb-stop-actions { display:flex;gap:.2rem;flex-shrink:0; }
.rb-btn-rm {
    display:inline-flex;align-items:center;justify-content:center;
    width:1.4rem;height:1.4rem;border:none;border-radius:4px;
    background:rgba(239,68,68,.12);color:#ef4444;font-size:.72rem;cursor:pointer;padding:0;
}
.rb-btn-rm:hover { background:rgba(239,68,68,.28); }
#builder-map.rb-crosshair,
#builder-map.rb-crosshair .leaflet-container { cursor:crosshair!important; }
/* ── Route Builder autocomplete ─────────────────────────────────────────────── */
#rb-ac-list {
    position:absolute;top:calc(100% + 1px);left:0;right:0;z-index:3000;
    background:var(--dk1,#fff);border:1px solid var(--bd,#d1d5db);
    border-radius:0 0 8px 8px;box-shadow:0 6px 18px rgba(0,0,0,.14);
    list-style:none;margin:0;padding:0;max-height:290px;overflow-y:auto;
}
.rb-ac-item {
    padding:.5rem .8rem;cursor:pointer;border-bottom:1px solid var(--bd,#e5e7eb);
    font-size:.82rem;line-height:1.35;transition:background .1s;
}
.rb-ac-item:last-child { border-bottom:none; }
.rb-ac-item.ac-active,.rb-ac-item:hover { background:rgba(249,115,22,.09); }
.rb-ac-name { font-weight:600;color:var(--wh,#111827); }
.rb-ac-det  { font-size:.75rem;color:var(--gy,#6b7280);margin-top:.1rem; }
.rb-ac-type { display:inline-block;font-size:.68rem;font-weight:600;padding:.05rem .35rem;
              border-radius:4px;background:rgba(249,115,22,.12);color:#c2410c;margin-right:.35rem;vertical-align:.05em; }
</style>

<script>
(function () {
'use strict';

// ── PHP data ────────────────────────────────────────────────────────────────
var jobs           = <?= json_encode(array_values($all_jobs), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var csrfToken      = <?= json_encode(csrf_token()) ?>;
var appUrl         = <?= json_encode(defined('APP_URL') ? APP_URL : '') ?>;
var companyAddress = <?= json_encode($company_address) ?>;
var today          = <?= json_encode($today) ?>;
var viewDate       = <?= json_encode($view_date) ?>;

// ── Map init ────────────────────────────────────────────────────────────────
var map = L.map('dispatch-map', { zoomControl: true });
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 18
}).addTo(map);
map.setView([39.5, -98.35], 4);

// ── Icon builders ────────────────────────────────────────────────────────────
function jobColor(job) {
    if (job.job_type === 'delivery') return '#f97316';
    if (job.pickup_date && job.pickup_date < today && job.status === 'pickup_requested') return '#ef4444';
    return '#3b82f6';
}

function pinSvg(color, label) {
    if (label !== undefined) {
        // Numbered pin
        return '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="38" viewBox="0 0 28 38">'
            + '<path d="M14 0C6.27 0 0 6.27 0 14c0 10.5 14 24 14 24S28 24.5 28 14C28 6.27 21.73 0 14 0z" fill="' + color + '" stroke="#fff" stroke-width="1.5"/>'
            + '<text x="14" y="17" text-anchor="middle" dominant-baseline="middle" fill="#fff" font-size="11" font-weight="700" font-family="sans-serif">' + label + '</text>'
            + '</svg>';
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="36" viewBox="0 0 26 36">'
        + '<path d="M13 0C5.82 0 0 5.82 0 13c0 9.75 13 23 13 23S26 22.75 26 13C26 5.82 20.18 0 13 0z" fill="' + color + '"/>'
        + '<circle cx="13" cy="13" r="5.5" fill="#fff"/>'
        + '</svg>';
}

function makeIcon(color, label) {
    var n = label !== undefined;
    return L.divIcon({
        html: pinSvg(color, label), className: '',
        iconSize:   [n ? 28 : 26, n ? 38 : 36],
        iconAnchor: [n ? 14 : 13, n ? 38 : 36],
        popupAnchor:[0, n ? -36 : -34]
    });
}

function depotIcon() {
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30">'
        + '<circle cx="15" cy="15" r="14" fill="#6b7280" stroke="#fff" stroke-width="2"/>'
        + '<text x="15" y="15" text-anchor="middle" dominant-baseline="middle" fill="#fff" font-size="11" font-weight="700" font-family="sans-serif">D</text>'
        + '</svg>';
    return L.divIcon({ html: svg, className: '', iconSize: [30, 30], iconAnchor: [15, 30], popupAnchor: [0, -32] });
}

// ── Status helpers ───────────────────────────────────────────────────────────
var statusLabels = {
    scheduled:'Scheduled', delivered:'Delivered', active:'Active',
    pickup_requested:'Pickup Requested', picked_up:'Picked Up',
    completed:'Completed', canceled:'Canceled'
};

var nextStatus = {
    scheduled:        [{s:'delivered',      label:'Mark Delivered',  cls:'dp-btn-status'}],
    delivered:        [{s:'active',         label:'Mark Active',     cls:'dp-btn-status'},
                       {s:'pickup_requested',label:'Request Pickup', cls:'dp-btn-status done'}],
    active:           [{s:'pickup_requested',label:'Request Pickup', cls:'dp-btn-status done'}],
    pickup_requested: [{s:'picked_up',      label:'Mark Picked Up',  cls:'dp-btn-status done'}],
    picked_up:        [{s:'completed',      label:'Mark Complete',   cls:'dp-btn-status done'}],
};

function statusColor(s) {
    return {scheduled:'#6b7280',delivered:'#22c55e',active:'#16a34a',
            pickup_requested:'#f59e0b',picked_up:'#3b82f6',completed:'#374151',canceled:'#ef4444'}[s] || '#6b7280';
}

// ── State ────────────────────────────────────────────────────────────────────
var geocodedPts   = [];   // {job, lat, lng, marker}
var depotLatLng   = null;
var depotMarker   = null;
var routeLine     = null;
var routeActive   = false;
var bounds        = [];
var rowMap        = {};
var geocodePending = 0;
var geocodeTotal   = 0;

document.querySelectorAll('.dispatch-row').forEach(function (r) { rowMap[r.dataset.jobId] = r; });

// ── Popup builder ────────────────────────────────────────────────────────────
function buildPopup(job) {
    var type  = job.job_type === 'delivery' ? 'Delivery' : 'Pickup';
    var color = jobColor(job);
    var addr  = job.full_address || job.service_address;
    var html  = '<div class="dp-title" style="color:' + color + ';">'
              + esc(job.wo_number) + ' — ' + type + '</div>'
              + '<div class="dp-sub">' + esc(job.cust_name) + '</div>';
    if (job.cust_phone) {
        html += '<div class="dp-sub"><a href="tel:' + esc(job.cust_phone) + '" style="color:#3b82f6;text-decoration:none;">'
              + '<i class="fa-solid fa-phone" style="font-size:.7rem;margin-right:.25rem;"></i>' + esc(job.cust_phone) + '</a></div>';
    }
    html += '<div class="dp-addr">' + esc(addr) + '</div>';
    var dumpInfo = [job.dumpster_size, job.dumpster_code].filter(Boolean).join(' · ');
    if (dumpInfo) {
        html += '<div style="font-size:.78rem;color:#6b7280;margin-bottom:.35rem;">'
              + '<i class="fa-solid fa-dumpster" style="font-size:.7rem;margin-right:.25rem;"></i>' + esc(dumpInfo) + '</div>';
    }
    html += '<div style="font-size:.78rem;">Status: <strong>' + (statusLabels[job.status] || job.status) + '</strong></div>';

    var actions = nextStatus[job.status] || [];
    if (actions.length) {
        html += '<div class="dp-actions">';
        actions.forEach(function (a) {
            html += '<button class="dp-btn ' + a.cls + '" '
                  + 'data-wo="' + job.id + '" data-status="' + a.s + '">'
                  + a.label + '</button>';
        });
        html += '</div>';
    }
    html += '<div class="dp-actions">'
          + '<a href="' + appUrl + '/modules/work_orders/view.php?id=' + job.id + '" class="dp-btn dp-btn-view">Open WO →</a>'
          + '</div>';
    return html;
}

// ── Add marker ───────────────────────────────────────────────────────────────
function addMarker(job, lat, lng) {
    var marker = L.marker([lat, lng], { icon: makeIcon(jobColor(job)) }).addTo(map);
    var popup  = L.popup({ maxWidth: 260, closeButton: true });
    popup.setContent(buildPopup(job));
    marker.bindPopup(popup);

    marker.on('popupopen', function () {
        // Wire status buttons after popup opens
        document.querySelectorAll('.dp-btn[data-wo]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                updateStatus(job, btn.dataset.status, marker, popup);
            });
        });
        // Highlight table row
        highlightRow(job.id);
    });

    geocodedPts.push({ job: job, lat: lat, lng: lng, marker: marker });
    bounds.push([lat, lng]);
    if (bounds.length === 1) { map.setView([lat, lng], 13); }
    else                     { map.fitBounds(bounds, { padding: [40, 40] }); }
}

function highlightRow(jobId) {
    document.querySelectorAll('.dispatch-row').forEach(function (r) { r.classList.remove('map-highlight'); });
    var row = rowMap[jobId];
    if (row) { row.classList.add('map-highlight'); row.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
}

// ── Status update ────────────────────────────────────────────────────────────
function updateStatus(job, newStatus, marker, popup) {
    var body = new URLSearchParams({
        [<?= json_encode(CSRF_TOKEN_NAME) ?>]: csrfToken,
        wo_id:      job.id,
        new_status: newStatus,
    });
    fetch('map_status.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { alert(d.error || 'Update failed.'); return; }
            job.status = newStatus;
            // Refresh marker icon
            marker.setIcon(makeIcon(jobColor(job)));
            // Refresh popup
            popup.setContent(buildPopup(job));
            popup.update();
            // Refresh table row badge
            var row = rowMap[job.id];
            if (row) {
                var badge = row.querySelector('td:last-child');
                if (badge) badge.innerHTML = '<span class="tp-badge">' + esc(d.label) + '</span>';
            }
        })
        .catch(function () { alert('Network error. Please refresh.'); });
}

// ── Geocoding helpers ────────────────────────────────────────────────────────
function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fetchWithTimeout(url, opts, ms) {
    var ctrl = new AbortController();
    var t = setTimeout(function () { ctrl.abort(); }, ms);
    return fetch(url, Object.assign({}, opts, { signal: ctrl.signal }))
        .finally(function () { clearTimeout(t); });
}

function saveToCache(address, lat, lng) {
    var body = new URLSearchParams({
        [<?= json_encode(CSRF_TOKEN_NAME) ?>]: csrfToken,
        address: address, lat: lat, lng: lng,
    });
    fetch('map_geocode.php', { method: 'POST', body: body }).catch(function () {});
}

function geocodeAddress(addr) {
    return fetchWithTimeout(
        'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(addr),
        { headers: { 'Accept-Language': 'en' } },
        8000
    ).then(function (r) { return r.json(); });
}

// ── Sequential geocoder (only for cache misses) ──────────────────────────────
var geocodeQueue = jobs.filter(function (j) { return j.lat === null; });
geocodeTotal   = geocodeQueue.length;
geocodePending = geocodeTotal;
var geocodeIndex = 0;

function updateGeocodeProgress() {
    var el = document.getElementById('geocode-remaining');
    if (el) el.textContent = geocodePending;
    if (geocodePending <= 0) {
        var s = document.getElementById('geocode-status');
        if (s) s.style.display = 'none';
        // Enable optimize button if we have 2+ points
        if (geocodedPts.length >= 2) {
            var btn = document.getElementById('btn-optimize');
            if (btn) { btn.disabled = false; btn.title = ''; }
        }
    }
}

function geocodeNext() {
    if (geocodeIndex >= geocodeQueue.length) return;
    var job = geocodeQueue[geocodeIndex++];
    var addr = job.full_address || job.service_address;
    if (!addr) { geocodePending--; updateGeocodeProgress(); setTimeout(geocodeNext, 100); return; }

    geocodeAddress(addr)
        .then(function (results) {
            if (results && results.length > 0) {
                var lat = parseFloat(results[0].lat);
                var lng = parseFloat(results[0].lon);
                addMarker(job, lat, lng);
                saveToCache(addr, lat, lng);
            }
        })
        .catch(function () {})
        .finally(function () {
            geocodePending--;
            updateGeocodeProgress();
            setTimeout(geocodeNext, 1100); // Nominatim 1 req/s limit
        });
}

// Cached jobs load immediately
jobs.filter(function (j) { return j.lat !== null; })
    .forEach(function (j) { addMarker(j, j.lat, j.lng); });

// ── Route optimization ───────────────────────────────────────────────────────
function haversine(a, b) {
    var R = 6371, dL = (b.lat - a.lat) * Math.PI / 180, dG = (b.lng - a.lng) * Math.PI / 180;
    var x = Math.sin(dL/2)**2 + Math.cos(a.lat*Math.PI/180)*Math.cos(b.lat*Math.PI/180)*Math.sin(dG/2)**2;
    return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
}

function nearestNeighbor(pts, sLat, sLng) {
    var rem = pts.slice(), route = [], lat = sLat, lng = sLng;
    while (rem.length) {
        var bi = 0, bd = Infinity;
        rem.forEach(function (p, i) { var d = haversine({lat:lat,lng:lng}, p); if (d < bd) { bd = d; bi = i; } });
        var n = rem.splice(bi, 1)[0]; route.push(n); lat = n.lat; lng = n.lng;
    }
    return route;
}

function setSpinner(msg) {
    var el = document.getElementById('map-spinner');
    document.getElementById('spinner-msg').textContent = msg || '';
    el.style.display = msg ? '' : 'none';
}

function optimizeWithOSRM(pts, depotLL) {
    var waypoints = [];
    if (depotLL) waypoints.push(depotLL[1] + ',' + depotLL[0]);
    pts.forEach(function (p) { waypoints.push(p.lng + ',' + p.lat); });

    var url = 'https://router.project-osrm.org/trip/v1/driving/' + waypoints.join(';')
            + '?roundtrip=false&source=first&destination=last&overview=full&geometries=geojson&annotations=false';

    return fetchWithTimeout(url, {}, 9000)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.code !== 'Ok' || !data.waypoints || !data.trips || !data.trips[0]) {
                throw new Error('OSRM error: ' + data.code);
            }
            var offset  = depotLL ? 1 : 0;
            var ordered = data.waypoints
                .map(function (w, i) { return { i: i, tripIdx: w.waypoint_index }; })
                .filter(function (x) { return x.i >= offset; })
                .sort(function (a, b) { return a.tripIdx - b.tripIdx; })
                .map(function (x) { return pts[x.i - offset]; });
            return {
                ordered:  ordered,
                distance: data.trips[0].distance,   // metres
                duration: data.trips[0].duration,   // seconds
                geometry: data.trips[0].geometry,   // GeoJSON LineString
            };
        });
}

function fmtDuration(secs) {
    var h = Math.floor(secs / 3600), m = Math.floor((secs % 3600) / 60);
    return h > 0 ? h + 'h ' + m + 'm' : m + ' min';
}

function fmtDist(metres) {
    return metres >= 1000 ? (metres / 1609.34).toFixed(1) + ' mi' : Math.round(metres) + ' m';
}

function estimateDriveDuration(distanceMetres) {
    // Rough fallback when live routing duration is unavailable.
    var estimatedRoadMetres = distanceMetres * 1.25;
    var averageMetresPerSecond = 30 * 1609.34 / 3600;
    return Math.max(60, Math.round(estimatedRoadMetres / averageMetresPerSecond));
}

function clearRoute() {
    if (routeLine)   { map.removeLayer(routeLine); routeLine = null; }
    if (depotMarker) { map.removeLayer(depotMarker); depotMarker = null; }
    geocodedPts.forEach(function (pt) { pt.marker.setIcon(makeIcon(jobColor(pt.job))); });
    document.querySelectorAll('.route-step').forEach(function (el) { el.textContent = '—'; });
    document.getElementById('route-panel').style.display = 'none';
    document.getElementById('btn-clear-route').style.display = 'none';
    document.getElementById('btn-optimize').innerHTML = '<i class="fa-solid fa-route"></i> Optimize Route';
    var gBtn = document.getElementById('btn-gmaps'); if (gBtn) gBtn.style.display = 'none';
    var pBtn = document.getElementById('btn-print'); if (pBtn) pBtn.style.display = 'none';
    routeActive = false;
}

function drawRoute(ordered, distance, duration, geometry, depotLL) {
    // Draw polyline
    if (geometry) {
        routeLine = L.geoJSON(geometry, {
            style: { color: '#f97316', weight: 4, opacity: 0.75 }
        }).addTo(map);
    } else {
        var lls = depotLL ? [depotLL] : [];
        ordered.forEach(function (p) { lls.push([p.lat, p.lng]); });
        routeLine = L.polyline(lls, { color: '#f97316', weight: 3, opacity: 0.7, dashArray: '7 6' }).addTo(map);
    }

    // Depot marker
    if (depotLL) {
        depotMarker = L.marker(depotLL, { icon: depotIcon() })
            .bindPopup('<strong>Depot</strong><br><small>' + esc(companyAddress) + '</small>')
            .addTo(map);
    }

    // Numbered markers
    ordered.forEach(function (pt, i) { pt.marker.setIcon(makeIcon(jobColor(pt.job), i + 1)); });

    // Table step column
    ordered.forEach(function (pt, i) {
        var row = rowMap[pt.job.id];
        if (row) { var c = row.querySelector('.route-step'); if (c) c.textContent = i + 1; }
    });

    // Route panel
    var meta = '';
    if (duration) meta += '<i class="fa-solid fa-clock me-1"></i>' + fmtDuration(duration);
    if (distance) meta += (meta ? '  &nbsp;·&nbsp;  ' : '') + '<i class="fa-solid fa-road me-1"></i>' + fmtDist(distance);
    if (!meta && distance) meta = '~' + fmtDist(distance) + ' (straight-line)';
    document.getElementById('route-meta').innerHTML = meta;

    var list = document.getElementById('route-list');
    list.innerHTML = '';
    ordered.forEach(function (pt, i) {
        var color = jobColor(pt.job);
        var li = document.createElement('li');
        li.className = 'rp-step';
        li.innerHTML = '<span class="rp-num" style="background:' + color + ';">' + (i + 1) + '</span>'
                     + '<span><strong>' + esc(pt.job.wo_number) + '</strong> — '
                     + esc(statusLabels[pt.job.job_type === 'delivery' ? 'delivered' : 'pickup_requested'] || pt.job.job_type)
                     + ' — ' + esc(pt.job.cust_name)
                     + '<br><span style="color:var(--gy);font-size:.8rem;">' + esc(pt.job.full_address || pt.job.service_address) + '</span></span>';
        list.appendChild(li);
    });

    // Google Maps multi-stop URL
    var mapsUrl = 'https://www.google.com/maps/dir/';
    if (depotLL) mapsUrl += depotLL[0] + ',' + depotLL[1] + '/';
    ordered.forEach(function (pt) { mapsUrl += pt.lat + ',' + pt.lng + '/'; });
    var gBtn = document.getElementById('btn-gmaps');
    if (gBtn) { gBtn.href = mapsUrl; gBtn.style.display = ''; }

    // Print route sheet URL (passes the job order so PHP renders in the same order)
    var orderIds = ordered.map(function (pt) { return pt.job.id; }).join(',');
    var printBase = '?print=1&view=<?= urlencode($view_mode) ?>&date=<?= urlencode($view_date) ?>';
    var pBtn = document.getElementById('btn-print');
    if (pBtn) { pBtn.href = printBase + '&order=' + orderIds; pBtn.style.display = ''; }

    document.getElementById('route-panel').style.display = 'block';
    document.getElementById('btn-clear-route').style.display = '';
    document.getElementById('btn-optimize').innerHTML = '<i class="fa-solid fa-route"></i> Re-Optimize';
    routeActive = true;

    if (routeLine) { map.fitBounds(routeLine.getBounds(), { padding: [40, 40] }); }
    else if (bounds.length) { map.fitBounds(bounds, { padding: [40, 40] }); }
}

function runOptimize() {
    if (geocodedPts.length < 2) {
        setSpinner('Need at least 2 mapped stops to optimize.');
        setTimeout(function () { setSpinner(null); }, 2200);
        return;
    }
    setSpinner('Calculating route…');

    var depotLL = depotLatLng;
    var sLat = depotLL ? depotLL[0] : geocodedPts[0].lat;
    var sLng = depotLL ? depotLL[1] : geocodedPts[0].lng;

    optimizeWithOSRM(geocodedPts, depotLL)
        .then(function (result) {
            if (!result || !Array.isArray(result.ordered) || result.ordered.length < 2) {
                throw new Error('Not enough routed stops returned.');
            }
            setSpinner(null);
            drawRoute(result.ordered, result.distance, result.duration, result.geometry, depotLL);
        })
        .catch(function () {
            // Fallback: nearest-neighbor with straight-line distances
            var ordered = nearestNeighbor(geocodedPts, sLat, sLng);
            if (!ordered || ordered.length < 2) {
                setSpinner('Need at least 2 mapped stops to optimize.');
                setTimeout(function () { setSpinner(null); }, 2200);
                return;
            }
            setSpinner(null);
            var dist = 0, lat = sLat, lng = sLng;
            ordered.forEach(function (p) { dist += haversine({lat:lat,lng:lng}, p) * 1000; lat = p.lat; lng = p.lng; });
            drawRoute(ordered, dist, estimateDriveDuration(dist), null, depotLL);
        });
}

// ── Buttons ──────────────────────────────────────────────────────────────────
var btnOpt   = document.getElementById('btn-optimize');
var btnClear = document.getElementById('btn-clear-route');
if (btnOpt)   { btnOpt.addEventListener('click', function () { clearRoute(); runOptimize(); }); }
if (btnClear) { btnClear.addEventListener('click', clearRoute); }

// ── Table row → navigate ─────────────────────────────────────────────────────
document.querySelectorAll('.dispatch-row').forEach(function (row) {
    row.addEventListener('click', function (e) {
        if (e.target.closest('a,button')) return;
        window.location.href = row.dataset.href;
    });
});

// ── Date picker ──────────────────────────────────────────────────────────────
var dp = document.getElementById('date-picker');
if (dp) {
    dp.addEventListener('change', function () {
        if (dp.value) window.location.href = '?view=day&date=' + encodeURIComponent(dp.value);
    });
}

// ── Startup ──────────────────────────────────────────────────────────────────
if (jobs.length === 0) {
    var fallback = function (d) { d && d.length ? map.setView([parseFloat(d[0].lat), parseFloat(d[0].lon)], 12) : map.setView([39.5,-98.35],4); };
    if (companyAddress) {
        geocodeAddress(companyAddress).then(fallback).catch(function () { map.setView([39.5,-98.35],4); });
    } else {
        map.setView([39.5,-98.35],4);
    }
} else {
    // Geocode company address for depot (doesn't count against rate limit display)
    var afterDepot = function () {
        // Disable optimize button until enough points are geocoded
        if (geocodedPts.length < 2 && geocodePending > 0) {
            var btn = document.getElementById('btn-optimize');
            if (btn) btn.disabled = true;
        }
        if (geocodeQueue.length > 0) { setTimeout(geocodeNext, companyAddress ? 1100 : 0); }
        else if (geocodedPts.length >= 2) {
            var btn = document.getElementById('btn-optimize');
            if (btn) btn.disabled = false;
        }
    };

    if (companyAddress) {
        geocodeAddress(companyAddress)
            .then(function (d) { if (d && d.length) depotLatLng = [parseFloat(d[0].lat), parseFloat(d[0].lon)]; })
            .catch(function () {})
            .finally(afterDepot);
    } else {
        afterDepot();
    }
}


// ── Tab switcher ─────────────────────────────────────────────────────────────
window.switchMapTab = function (tab) {
    var isDispatch = tab === 'dispatch';
    document.getElementById('tab-dispatch-content').style.display = isDispatch ? '' : 'none';
    document.getElementById('tab-dispatch-bar').style.display     = isDispatch ? '' : 'none';
    document.getElementById('tab-builder-content').style.display  = isDispatch ? 'none' : '';
    document.getElementById('tab-btn-dispatch').className =
        'btn-tp-xs px-3 py-2 ' + (isDispatch ? 'btn-tp-primary' : 'btn-tp-ghost');
    document.getElementById('tab-btn-builder').className =
        'btn-tp-xs px-3 py-2 ' + (isDispatch ? 'btn-tp-ghost' : 'btn-tp-primary');
    document.getElementById('tab-btn-dispatch').style.cssText = 'border-radius:0;border:none;';
    document.getElementById('tab-btn-builder').style.cssText  = 'border-radius:0;border:none;border-left:1px solid var(--bd);';
    if (!isDispatch) {
        rbInitMap();
        setTimeout(function () { if (rbMap) rbMap.invalidateSize(); }, 150);
    }
};

// ── Route Builder state ───────────────────────────────────────────────────────
var rbMap        = null;
var rbMapInited  = false;
var rbStops      = [];   // [{id, label, lat, lng, marker}]
var rbCounter    = 0;
var rbClickMode  = false;
var rbRouteLine  = null;
var rbDepotMark  = null;
var rbDragId     = null; // stop id being dragged

function rbInitMap() {
    if (rbMapInited) return;
    rbMapInited = true;
    rbMap = L.map('builder-map', { zoomControl: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 18
    }).addTo(rbMap);
    if (depotLatLng) { rbMap.setView(depotLatLng, 12); }
    else             { rbMap.setView([39.5, -98.35], 4); }

    rbMap.on('click', function (e) {
        if (!rbClickMode) return;
        var lat = e.latlng.lat, lng = e.latlng.lng;
        rbSetSpinner('Getting address…');
        rbReverseGeocode(lat, lng)
            .then(function (label) { rbAddStop(label, lat, lng); })
            .finally(function () { rbSetSpinner(null); });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && rbClickMode) rbToggleClickMode(false);
    });

    // Wire depot checkbox visibility
    var depotCb = document.getElementById('rb-use-depot');
    if (depotCb && !depotLatLng) {
        depotCb.disabled = true;
        depotCb.parentElement.title = 'Set company address in Settings to use this feature';
    }
}

function rbReverseGeocode(lat, lng) {
    return fetchWithTimeout(
        'https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng,
        { headers: { 'Accept-Language': 'en' } },
        8000
    ).then(function (r) { return r.json(); })
     .then(function (d) {
         if (!d || d.error) return 'Stop ' + (rbStops.length + 1);
         var parts = (d.display_name || '').split(', ').filter(Boolean);
         // Keep road + city + state — skip country
         return parts.slice(0, 3).join(', ') || ('Stop ' + (rbStops.length + 1));
     })
     .catch(function () { return 'Stop ' + (rbStops.length + 1); });
}

function rbAddStop(label, lat, lng) {
    var id = ++rbCounter;
    var num = rbStops.length + 1;
    var marker = L.marker([lat, lng], { icon: rbMakeIcon(num) })
        .addTo(rbMap)
        .bindPopup('<strong>' + esc(label) + '</strong><br><small style="color:#6b7280;">'
            + lat.toFixed(5) + ', ' + lng.toFixed(5) + '</small>'
            + '<br><a href="#" onclick="rbRemoveStop(' + id + ');return false;" '
            + 'style="color:#ef4444;font-size:.8rem;">Remove stop</a>');
    rbStops.push({ id: id, label: label, lat: lat, lng: lng, marker: marker });
    rbClearRoute();
    rbRenumber();
    rbRenderList();
    rbFitBounds();
}

function rbRemoveStop(id) {
    var idx = rbStops.findIndex(function (s) { return s.id === id; });
    if (idx === -1) return;
    rbMap.removeLayer(rbStops[idx].marker);
    rbStops.splice(idx, 1);
    rbRenumber();
    rbRenderList();
    rbClearRoute();
    rbFitBounds();
}
window.rbRemoveStop = rbRemoveStop;

function rbRenumber() {
    rbStops.forEach(function (s, i) { s.marker.setIcon(rbMakeIcon(i + 1)); });
    var btn = document.getElementById('rb-optimize-btn');
    if (btn) btn.disabled = rbStops.length < 2;
    var clr = document.getElementById('rb-clear-all-btn');
    if (clr) clr.style.display = rbStops.length > 0 ? '' : 'none';
}

function rbRenderList() {
    var ul    = document.getElementById('rb-stop-list');
    var count = document.getElementById('rb-stop-count');
    if (count) count.textContent = rbStops.length;
    if (rbStops.length === 0) {
        ul.innerHTML = '<li id="rb-no-stops" style="color:var(--gy);font-size:.84rem;text-align:center;padding:2rem 0;line-height:1.7;">'
            + 'No stops yet.<br><small>Search an address above<br>or click the map to drop a pin.</small></li>';
        return;
    }
    ul.innerHTML = '';
    rbStops.forEach(function (s, i) {
        var li = document.createElement('li');
        li.className = 'rb-stop-item';
        li.dataset.rbId = s.id;
        li.draggable = true;
        li.innerHTML =
            '<span class="rb-drag-handle" title="Drag to reorder">&#8942;&#8942;</span>'
            + '<span class="rb-stop-num">' + (i + 1) + '</span>'
            + '<span class="rb-stop-label" title="' + esc(s.label) + '">' + esc(s.label) + '</span>'
            + '<div class="rb-stop-actions">'
            + '<button class="rb-btn-rm" title="Remove stop" onclick="rbRemoveStop(' + s.id + ')">&#10005;</button>'
            + '</div>';
        li.addEventListener('click', function (e) {
            if (e.target.closest('button')) return;
            rbMap.setView([s.lat, s.lng], 14);
            s.marker.openPopup();
        });
        li.addEventListener('dragstart', function (e) {
            rbDragId = s.id;
            e.dataTransfer.effectAllowed = 'move';
            setTimeout(function () { li.style.opacity = '.4'; }, 0);
        });
        li.addEventListener('dragend', function () {
            li.style.opacity = '';
            document.querySelectorAll('.rb-stop-item').forEach(function (el) {
                el.classList.remove('rb-drag-over-top', 'rb-drag-over-bottom');
            });
        });
        li.addEventListener('dragover', function (e) {
            e.preventDefault();
            var rect    = li.getBoundingClientRect();
            var midY    = rect.top + rect.height / 2;
            var isAbove = e.clientY < midY;
            document.querySelectorAll('.rb-stop-item').forEach(function (el) {
                el.classList.remove('rb-drag-over-top', 'rb-drag-over-bottom');
            });
            li.classList.add(isAbove ? 'rb-drag-over-top' : 'rb-drag-over-bottom');
        });
        li.addEventListener('drop', function (e) {
            e.preventDefault();
            document.querySelectorAll('.rb-stop-item').forEach(function (el) {
                el.classList.remove('rb-drag-over-top', 'rb-drag-over-bottom');
            });
            if (rbDragId === null || rbDragId === s.id) return;
            var fromIdx = rbStops.findIndex(function (x) { return x.id === rbDragId; });
            var toIdx   = i;
            if (fromIdx === -1) return;
            var rect  = li.getBoundingClientRect();
            var after = e.clientY >= rect.top + rect.height / 2;
            var item  = rbStops.splice(fromIdx, 1)[0];
            var dest  = after ? toIdx : toIdx;
            // Adjust dest after splice
            var insertAt = (fromIdx < toIdx)
                ? (after ? toIdx : toIdx - 1)
                : (after ? toIdx + 1 : toIdx);
            rbStops.splice(Math.max(0, Math.min(rbStops.length, insertAt)), 0, item);
            rbRenumber();
            rbRenderList();
            rbClearRoute();
        });
        ul.appendChild(li);
    });
}
window.rbHandleDrop = function (e) { e.preventDefault(); };

function rbFitBounds() {
    if (rbStops.length === 0) return;
    if (rbStops.length === 1) { rbMap.setView([rbStops[0].lat, rbStops[0].lng], 14); return; }
    rbMap.fitBounds(rbStops.map(function (s) { return [s.lat, s.lng]; }), { padding: [40, 40] });
}

function rbMakeIcon(num) {
    return L.divIcon({
        html: pinSvg('#f97316', num), className: '',
        iconSize: [28, 38], iconAnchor: [14, 38], popupAnchor: [0, -36]
    });
}

function rbSetSpinner(msg) {
    var el = document.getElementById('rb-spinner');
    if (!el) return;
    document.getElementById('rb-spinner-msg').textContent = msg || '';
    el.style.display = msg ? '' : 'none';
}

function rbToggleClickMode(force) {
    rbClickMode = (force !== undefined) ? force : !rbClickMode;
    var btn  = document.getElementById('rb-click-toggle');
    var hint = document.getElementById('rb-click-hint');
    var mapEl = document.getElementById('builder-map');
    if (rbClickMode) {
        if (btn)   { btn.innerHTML = '<i class="fa-solid fa-xmark me-1"></i>Exit Pin Mode'; btn.classList.add('btn-tp-primary'); btn.classList.remove('btn-tp-ghost'); }
        if (hint)  hint.style.display = '';
        if (mapEl) mapEl.classList.add('rb-crosshair');
    } else {
        if (btn)   { btn.innerHTML = '<i class="fa-solid fa-map-pin me-1"></i>Click Map to Pin'; btn.classList.remove('btn-tp-primary'); btn.classList.add('btn-tp-ghost'); }
        if (hint)  hint.style.display = 'none';
        if (mapEl) mapEl.classList.remove('rb-crosshair');
    }
}

function rbClearRoute() {
    if (rbRouteLine)  { rbMap.removeLayer(rbRouteLine);  rbRouteLine  = null; }
    if (rbDepotMark)  { rbMap.removeLayer(rbDepotMark);  rbDepotMark  = null; }
    // Restore default icons
    rbStops.forEach(function (s, i) { s.marker.setIcon(rbMakeIcon(i + 1)); });
    document.getElementById('rb-route-panel').style.display = 'none';
    var crBtn = document.getElementById('rb-clear-route-btn');
    if (crBtn) crBtn.style.display = 'none';
    var optBtn = document.getElementById('rb-optimize-btn');
    if (optBtn) optBtn.innerHTML = '<i class="fa-solid fa-route me-1"></i>Optimize';
}

function rbClearAll() {
    rbClearRoute();
    rbStops.forEach(function (s) { rbMap.removeLayer(s.marker); });
    rbStops = [];
    rbRenderList();
    rbRenumber();
}

function rbRunOptimize() {
    if (rbStops.length < 2) {
        rbSetSpinner('Need at least 2 stops to optimize.');
        setTimeout(function () { rbSetSpinner(null); }, 2200);
        return;
    }
    rbClearRoute();
    var useDepot = document.getElementById('rb-use-depot').checked;
    var dLL = (useDepot && depotLatLng) ? depotLatLng : null;
    var sLat = dLL ? dLL[0] : rbStops[0].lat;
    var sLng = dLL ? dLL[1] : rbStops[0].lng;
    rbSetSpinner('Calculating route…');

    optimizeWithOSRM(rbStops, dLL)
        .then(function (res) {
            if (!res || !Array.isArray(res.ordered) || res.ordered.length < 2) {
                throw new Error('Not enough routed stops returned.');
            }
            rbSetSpinner(null);
            rbDrawRoute(res.ordered, res.distance, res.duration, res.geometry, dLL);
        })
        .catch(function () {
            var ord  = nearestNeighbor(rbStops, sLat, sLng);
            if (!ord || ord.length < 2) {
                rbSetSpinner('Need at least 2 stops to optimize.');
                setTimeout(function () { rbSetSpinner(null); }, 2200);
                return;
            }
            rbSetSpinner(null);
            var dist = 0, la = sLat, ln = sLng;
            ord.forEach(function (s) { dist += haversine({lat:la,lng:ln}, s) * 1000; la = s.lat; ln = s.lng; });
            rbDrawRoute(ord, dist, estimateDriveDuration(dist), null, dLL);
        });
}

function rbDrawRoute(ordered, distance, duration, geometry, dLL) {
    rbStops = ordered.slice();
    rbRenumber();
    rbRenderList();

    if (geometry) {
        rbRouteLine = L.geoJSON(geometry, { style: { color: '#f97316', weight: 4, opacity: 0.78 } }).addTo(rbMap);
    } else {
        var lls = dLL ? [dLL] : [];
        ordered.forEach(function (s) { lls.push([s.lat, s.lng]); });
        rbRouteLine = L.polyline(lls, { color: '#f97316', weight: 3, opacity: 0.7, dashArray: '7 6' }).addTo(rbMap);
    }
    if (dLL) {
        rbDepotMark = L.marker(dLL, { icon: depotIcon() })
            .bindPopup('<strong>Depot</strong><br><small>' + esc(companyAddress) + '</small>')
            .addTo(rbMap);
    }
    // Renumber markers in optimized order
    ordered.forEach(function (s, i) { s.marker.setIcon(rbMakeIcon(i + 1)); });

    // Route panel
    var meta = '';
    if (duration) meta += '<i class="fa-solid fa-clock me-1"></i>' + fmtDuration(duration);
    if (distance) meta += (meta ? '&nbsp;&nbsp;·&nbsp;&nbsp;' : '') + '<i class="fa-solid fa-road me-1"></i>' + fmtDist(distance);
    document.getElementById('rb-route-meta').innerHTML = meta;

    var list = document.getElementById('rb-route-list');
    list.innerHTML = '';
    ordered.forEach(function (s, i) {
        var li = document.createElement('li');
        li.className = 'rp-step';
        li.innerHTML = '<span class="rp-num" style="background:#f97316;">' + (i + 1) + '</span>'
            + '<span><strong>' + esc(s.label) + '</strong>'
            + '<br><span style="color:var(--gy);font-size:.79rem;">'
            + s.lat.toFixed(5) + ', ' + s.lng.toFixed(5) + '</span></span>';
        list.appendChild(li);
    });

    // Google Maps multi-stop URL
    var mapsUrl = 'https://www.google.com/maps/dir/';
    if (dLL) mapsUrl += dLL[0] + ',' + dLL[1] + '/';
    ordered.forEach(function (s) { mapsUrl += s.lat + ',' + s.lng + '/'; });
    var gBtn = document.getElementById('rb-btn-gmaps');
    if (gBtn) { gBtn.href = mapsUrl; gBtn.style.display = ''; }

    document.getElementById('rb-route-panel').style.display = 'block';
    var crBtn = document.getElementById('rb-clear-route-btn');
    if (crBtn) crBtn.style.display = '';
    var optBtn = document.getElementById('rb-optimize-btn');
    if (optBtn) optBtn.innerHTML = '<i class="fa-solid fa-route me-1"></i>Re-Optimize';

    if (rbRouteLine) rbMap.fitBounds(rbRouteLine.getBounds(), { padding: [40, 40] });
}

// ── Address geocode helper ────────────────────────────────────────────────────
function rbGeocode(addr) {
    return fetchWithTimeout(
        'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(addr),
        { headers: { 'Accept-Language': 'en' } },
        8000
    ).then(function (r) { return r.json(); });
}

// ── Wire Route Builder buttons ────────────────────────────────────────────────
(function () {
    var addrInput  = document.getElementById('rb-addr-input');
    var addrBtn    = document.getElementById('rb-addr-btn');
    var clickToggle= document.getElementById('rb-click-toggle');
    var optimizeBtn= document.getElementById('rb-optimize-btn');
    var clearRouteBtn= document.getElementById('rb-clear-route-btn');
    var clearAllBtn= document.getElementById('rb-clear-all-btn');
    var errEl      = document.getElementById('rb-addr-error');

    function showErr(msg) {
        if (!errEl) return;
        errEl.textContent = msg;
        errEl.style.display = msg ? '' : 'none';
    }

    function doAddAddress() {
        var addr = (addrInput ? addrInput.value : '').trim();
        if (!addr) return;
        showErr(null);
        rbSetSpinner('Geocoding…');
        if (addrBtn) addrBtn.disabled = true;
        rbGeocode(addr)
            .then(function (results) {
                if (!results || results.length === 0) {
                    showErr('Address not found. Try a more specific address.');
                    return;
                }
                var lat   = parseFloat(results[0].lat);
                var lng   = parseFloat(results[0].lon);
                var label = results[0].display_name.split(', ').slice(0, 3).join(', ');
                rbAddStop(label, lat, lng);
                if (addrInput) addrInput.value = '';
            })
            .catch(function () { showErr('Network error. Try again.'); })
            .finally(function () {
                rbSetSpinner(null);
                if (addrBtn) addrBtn.disabled = false;
            });
    }

    if (addrBtn) addrBtn.addEventListener('click', doAddAddress);
    // Store for autocomplete fallback; Enter key handled by autocomplete IIFE
    window._rbDoAddAddress = doAddAddress;
    if (clickToggle)  clickToggle.addEventListener('click', function () { rbInitMap(); rbToggleClickMode(); });
    if (optimizeBtn)  optimizeBtn.addEventListener('click', rbRunOptimize);
    if (clearRouteBtn)clearRouteBtn.addEventListener('click', function () { rbClearRoute(); rbRenumber(); });
    if (clearAllBtn)  clearAllBtn.addEventListener('click', rbClearAll);
}());


// ── Route Builder address autocomplete ───────────────────────────────────────
(function () {
    var inp  = document.getElementById('rb-addr-input');
    var list = document.getElementById('rb-ac-list');
    if (!inp || !list) return;

    var acData = [];
    var acIdx  = -1;
    var debounce = null;

    // ── Format helpers ────────────────────────────────────────────────────────
    function acName(r) {
        var a = r.address || {};
        if (a.house_number && a.road) return a.house_number + ' ' + a.road;
        if (a.road)    return a.road;
        if (r.name)    return r.name;
        return (r.display_name || '').split(',')[0].trim();
    }
    function acDetail(r) {
        var a = r.address || {};
        return [a.city || a.town || a.village || a.county, a.state, a.postcode]
            .filter(Boolean).join(', ');
    }
    function acTypeLabel(r) {
        var t = r.type || r.class || '';
        var map = { house:'Address', road:'Road', city:'City', town:'Town',
                    village:'Village', county:'County', state:'State',
                    postcode:'ZIP', neighbourhood:'Area', suburb:'Area',
                    building:'Building', commercial:'Business', industrial:'Area' };
        return map[t] || '';
    }

    // ── Render / hide ─────────────────────────────────────────────────────────
    function hide() {
        list.style.display = 'none';
        list.innerHTML = '';
        acData = [];
        acIdx  = -1;
    }

    function render(results) {
        acData = results;
        acIdx  = -1;
        if (!results.length) { hide(); return; }
        list.innerHTML = '';
        results.forEach(function (r, i) {
            var li   = document.createElement('li');
            var name = acName(r);
            var det  = acDetail(r);
            var lbl  = acTypeLabel(r);
            li.className = 'rb-ac-item';
            li.dataset.idx = i;
            li.innerHTML =
                '<div class="rb-ac-name">'
                + (lbl ? '<span class="rb-ac-type">' + esc(lbl) + '</span>' : '')
                + esc(name) + '</div>'
                + (det ? '<div class="rb-ac-det">' + esc(det) + '</div>' : '');
            li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(i); });
            li.addEventListener('mouseenter', function () { setActive(i); });
            list.appendChild(li);
        });
        list.style.display = '';
    }

    function setActive(i) {
        list.querySelectorAll('.rb-ac-item').forEach(function (el, j) {
            el.classList.toggle('ac-active', j === i);
        });
        acIdx = i;
    }

    // ── Select a result ───────────────────────────────────────────────────────
    function pick(i) {
        var r = acData[i];
        if (!r) return;
        var label = acDetail(r) ? acName(r) + ', ' + acDetail(r) : acName(r);
        inp.value = '';
        hide();
        rbInitMap();
        rbAddStop(label, parseFloat(r.lat), parseFloat(r.lon));
        var errEl = document.getElementById('rb-addr-error');
        if (errEl) errEl.style.display = 'none';
    }

    // ── Keyboard navigation ───────────────────────────────────────────────────
    inp.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(Math.min(acData.length - 1, acIdx + 1));
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(Math.max(0, acIdx - 1));
            return;
        }
        if (e.key === 'Escape') { hide(); return; }
        if (e.key === 'Enter') {
            e.preventDefault();
            if (acData.length && acIdx >= 0) {
                pick(acIdx);
            } else if (acData.length === 1) {
                pick(0);
            } else {
                // No suggestion highlighted — fall back to full geocode
                hide();
                if (window._rbDoAddAddress) window._rbDoAddAddress();
            }
        }
    });

    // ── Fetch on input ────────────────────────────────────────────────────────
    inp.addEventListener('input', function () {
        var q = inp.value.trim();
        clearTimeout(debounce);
        if (q.length < 3) { hide(); return; }
        debounce = setTimeout(function () {
            // Bias results toward the company service area
            var viewbox = '';
            if (companyAddress) {
                // Use a broad US South / Gulf Coast box as default bias
                viewbox = '&viewbox=-88.5,31.2,-86.5,30.0&bounded=0';
            }
            var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=7'
                    + '&addressdetails=1&countrycodes=us' + viewbox
                    + '&q=' + encodeURIComponent(q);
            fetchWithTimeout(url, { headers: { 'Accept-Language': 'en' } }, 6000)
                .then(function (r) { return r.json(); })
                .then(render)
                .catch(function () { hide(); });
        }, 320);
    });

    // ── Close on blur / outside click ─────────────────────────────────────────
    inp.addEventListener('blur', function () { setTimeout(hide, 200); });
    document.addEventListener('click', function (e) {
        var wrap = document.getElementById('rb-ac-wrap');
        if (wrap && !wrap.contains(e.target)) hide();
    });
}());

}());
</script>

<?php layout_end(); ?>
