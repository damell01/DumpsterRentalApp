<?php
/**
 * Work Orders – Dispatch Map
 * Shows today's deliveries and pickups on an interactive map.
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();

$today = date('Y-m-d');

// Deliveries today: scheduled delivery_date = today and not yet completed/canceled
$deliveries = db_fetchall(
    "SELECT id, wo_number, cust_name, service_address, status, delivery_date, pickup_date, dumpster_size
     FROM work_orders
     WHERE delivery_date = ? AND status NOT IN ('completed','canceled','picked_up')
     ORDER BY wo_number ASC",
    [$today]
);

// Pickups today: pickup_date = today OR pickup_requested and overdue
$pickups = db_fetchall(
    "SELECT id, wo_number, cust_name, service_address, status, delivery_date, pickup_date, dumpster_size
     FROM work_orders
     WHERE (
         (pickup_date = ? AND status NOT IN ('completed','canceled','picked_up'))
         OR (pickup_date < ? AND status = 'pickup_requested')
     )
     ORDER BY pickup_date ASC, wo_number ASC",
    [$today, $today]
);

// All jobs combined for the map
$all_jobs = [];
foreach ($deliveries as $wo) {
    $all_jobs[] = array_merge($wo, ['job_type' => 'delivery']);
}
foreach ($pickups as $wo) {
    $all_jobs[] = array_merge($wo, ['job_type' => 'pickup']);
}

$company_address = get_setting('company_address', '');

layout_start('Dispatch Map', 'work_orders');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Dispatch Map</h5>
        <small style="color:var(--gy);"><?= date('l, F j, Y') ?> &mdash; <?= count($deliveries) ?> deliver<?= count($deliveries) === 1 ? 'y' : 'ies' ?>, <?= count($pickups) ?> pickup<?= count($pickups) === 1 ? '' : 's' ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <span class="tp-badge" style="background:rgba(249,115,22,.15);color:#f97316;border:1px solid rgba(249,115,22,.3);">
            <i class="fa-solid fa-truck me-1"></i> Delivery
        </span>
        <span class="tp-badge" style="background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid rgba(59,130,246,.3);">
            <i class="fa-solid fa-box-open me-1"></i> Pickup
        </span>
        <span class="tp-badge" style="background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3);">
            <i class="fa-solid fa-circle-exclamation me-1"></i> Overdue
        </span>
        <a href="index.php" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-list"></i> List View
        </a>
    </div>
</div>

<!-- Map -->
<div class="tp-card p-0 mb-3" style="overflow:hidden;">
    <div id="dispatch-map" style="height:520px;width:100%;"></div>
</div>

<?php if (empty($all_jobs)): ?>
<div class="tp-card text-center py-5">
    <i class="fa-solid fa-truck-fast" style="font-size:2.5rem;color:var(--gl);margin-bottom:1rem;display:block;"></i>
    <div style="font-weight:600;color:var(--wh);margin-bottom:.35rem;">No jobs scheduled for today</div>
    <div style="color:var(--gy);font-size:.9rem;">Check the <a href="index.php">work orders list</a> for upcoming jobs.</div>
</div>
<?php else: ?>

<!-- Job cards -->
<div class="row g-3">
    <?php if (!empty($deliveries)): ?>
    <div class="col-md-6">
        <div class="tp-card p-0" style="overflow:hidden;">
            <div class="tp-card-header" style="background:linear-gradient(90deg,rgba(249,115,22,.12) 0%,transparent 70%);">
                <h5 class="mb-0" style="font-size:.95rem;">
                    <i class="fa-solid fa-truck me-2" style="color:#f97316;"></i>
                    Deliveries Today (<?= count($deliveries) ?>)
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table tp-table mb-0">
                    <thead>
                        <tr>
                            <th>WO #</th>
                            <th>Customer</th>
                            <th>Address</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deliveries as $wo): ?>
                        <tr data-href="view.php?id=<?= (int)$wo['id'] ?>" class="dispatch-row" data-job-id="<?= (int)$wo['id'] ?>" style="cursor:pointer;">
                            <td><strong><?= e($wo['wo_number']) ?></strong></td>
                            <td><?= e($wo['cust_name']) ?></td>
                            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem;color:var(--gy);"><?= e($wo['service_address']) ?></td>
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
                    Pickups Today (<?= count($pickups) ?>)
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table tp-table mb-0">
                    <thead>
                        <tr>
                            <th>WO #</th>
                            <th>Customer</th>
                            <th>Address</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pickups as $wo): ?>
                        <?php $overdue = ($wo['pickup_date'] < $today && $wo['status'] === 'pickup_requested'); ?>
                        <tr data-href="view.php?id=<?= (int)$wo['id'] ?>" class="dispatch-row" data-job-id="<?= (int)$wo['id'] ?>" style="cursor:pointer;<?= $overdue ? 'background:rgba(239,68,68,.04)!important;' : '' ?>">
                            <td><strong><?= e($wo['wo_number']) ?></strong><?= $overdue ? ' <i class="fa-solid fa-circle-exclamation" style="color:#ef4444;font-size:.75rem;" title="Overdue"></i>' : '' ?></td>
                            <td><?= e($wo['cust_name']) ?></td>
                            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem;color:var(--gy);"><?= e($wo['service_address']) ?></td>
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

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>

<style>
.leaflet-popup-content-wrapper {
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0,0,0,.18);
    font-family: var(--font-body, sans-serif);
    font-size: .85rem;
}
.dispatch-popup-title { font-weight: 700; font-size: .9rem; margin-bottom: .25rem; }
.dispatch-popup-addr  { color: #6b7280; font-size: .78rem; margin-bottom: .4rem; }
.dispatch-popup-link  { color: #f97316; font-weight: 600; text-decoration: none; font-size: .8rem; }
.dispatch-popup-link:hover { text-decoration: underline; }
.dispatch-row.map-highlight > td { background: rgba(249,115,22,.08) !important; }
</style>

<script>
(function () {
    var jobs = <?= json_encode(array_values($all_jobs), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var companyAddress = <?= json_encode($company_address) ?>;
    var appUrl = <?= json_encode(defined('APP_URL') ? APP_URL : '') ?>;
    var today = <?= json_encode($today) ?>;

    // Default center: try company address, fall back to continental US
    var map = L.map('dispatch-map', { zoomControl: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 18
    }).addTo(map);

    // Custom marker icons
    function makeIcon(color) {
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="36" viewBox="0 0 26 36">'
            + '<path d="M13 0C5.82 0 0 5.82 0 13c0 9.75 13 23 13 23S26 22.75 26 13C26 5.82 20.18 0 13 0z" fill="' + color + '"/>'
            + '<circle cx="13" cy="13" r="5.5" fill="#fff"/>'
            + '</svg>';
        return L.divIcon({
            html: svg,
            className: '',
            iconSize:   [26, 36],
            iconAnchor: [13, 36],
            popupAnchor:[0, -34]
        });
    }

    var icons = {
        delivery: makeIcon('#f97316'),
        pickup:   makeIcon('#3b82f6'),
        overdue:  makeIcon('#ef4444'),
    };

    var markers = [];
    var bounds  = [];
    var geocodeQueue = jobs.slice();
    var rowMap = {};

    // Build row map for highlight on marker click
    document.querySelectorAll('.dispatch-row').forEach(function (row) {
        rowMap[row.dataset.jobId] = row;
    });

    function pickIcon(job) {
        if (job.job_type === 'delivery') return icons.delivery;
        if (job.pickup_date && job.pickup_date < today && job.status === 'pickup_requested') return icons.overdue;
        return icons.pickup;
    }

    function addMarker(job, lat, lng) {
        var icon   = pickIcon(job);
        var marker = L.marker([lat, lng], { icon: icon }).addTo(map);
        var label  = job.job_type === 'delivery' ? 'Delivery' : (job.pickup_date < today ? 'Overdue Pickup' : 'Pickup');
        var popup  = '<div class="dispatch-popup-title">' + escHtml(job.wo_number) + ' &mdash; ' + label + '</div>'
                   + '<div style="font-size:.82rem;margin-bottom:.2rem;">' + escHtml(job.cust_name) + '</div>'
                   + '<div class="dispatch-popup-addr">' + escHtml(job.service_address) + '</div>'
                   + '<a href="' + appUrl + '/modules/work_orders/view.php?id=' + job.id + '" class="dispatch-popup-link">Open Work Order &rarr;</a>';
        marker.bindPopup(popup);
        marker.on('click', function () {
            var row = rowMap[job.id];
            if (row) {
                document.querySelectorAll('.dispatch-row').forEach(function (r) { r.classList.remove('map-highlight'); });
                row.classList.add('map-highlight');
                row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        });
        markers.push(marker);
        bounds.push([lat, lng]);
        if (bounds.length === 1) {
            map.setView([lat, lng], 13);
        } else {
            map.fitBounds(bounds, { padding: [40, 40] });
        }
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // Geocode via OpenStreetMap Nominatim (1 req/s to respect rate limit)
    var geocodeIndex = 0;
    function geocodeNext() {
        if (geocodeIndex >= geocodeQueue.length) return;
        var job = geocodeQueue[geocodeIndex++];
        if (!job.service_address) { setTimeout(geocodeNext, 200); return; }

        fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(job.service_address), {
            headers: { 'Accept-Language': 'en' }
        })
        .then(function (r) { return r.json(); })
        .then(function (results) {
            if (results && results.length > 0) {
                addMarker(job, parseFloat(results[0].lat), parseFloat(results[0].lon));
            }
        })
        .catch(function () {})
        .finally(function () {
            setTimeout(geocodeNext, 1100); // 1.1s to stay under Nominatim 1 req/s limit
        });
    }

    if (jobs.length === 0) {
        // No jobs: center on company address or US
        if (companyAddress) {
            fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(companyAddress))
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.length) map.setView([parseFloat(d[0].lat), parseFloat(d[0].lon)], 12);
                    else map.setView([39.5, -98.35], 4);
                })
                .catch(function () { map.setView([39.5, -98.35], 4); });
        } else {
            map.setView([39.5, -98.35], 4);
        }
    } else {
        // Set initial view while geocoding runs
        map.setView([39.5, -98.35], 4);
        geocodeNext();
    }

    // Clicking a table row highlights the marker
    document.querySelectorAll('.dispatch-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('a, button')) return;
            window.location.href = row.dataset.href;
        });
    });
}());
</script>

<?php layout_end(); ?>
