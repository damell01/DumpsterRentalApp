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
        <?php if (count($all_jobs) >= 2): ?>
        <button id="btn-optimize" class="btn-tp-primary btn-tp-sm" disabled title="Waiting for addresses to load…">
            <i class="fa-solid fa-route"></i> <span id="btn-optimize-label">Optimize Route</span>
        </button>
        <button id="btn-clear-route" class="btn-tp-ghost btn-tp-sm" style="display:none;">
            <i class="fa-solid fa-xmark"></i> Clear Route
        </button>
        <?php endif; ?>
        <a href="index.php" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-list"></i> List View
        </a>
    </div>
</div>

<!-- Map -->
<div class="tp-card p-0 mb-3" style="overflow:hidden;">
    <div id="dispatch-map" style="height:520px;width:100%;"></div>
</div>

<!-- Route Panel (hidden until Optimize is clicked) -->
<div id="route-panel" class="tp-card mb-3" style="display:none;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0"><i class="fa-solid fa-route me-2" style="color:#f97316;"></i>Optimized Route</h6>
        <small id="route-distance" style="color:var(--gy);"></small>
    </div>
    <ol id="route-list" class="mb-0" style="padding-left:1.4rem;"></ol>
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
                            <th style="width:2rem;">#</th>
                            <th>WO #</th>
                            <th>Customer</th>
                            <th>Address</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deliveries as $wo): ?>
                        <tr data-href="view.php?id=<?= (int)$wo['id'] ?>" class="dispatch-row" data-job-id="<?= (int)$wo['id'] ?>" style="cursor:pointer;">
                            <td class="route-step text-muted" style="font-size:.75rem;">—</td>
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
                            <th style="width:2rem;">#</th>
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
                            <td class="route-step text-muted" style="font-size:.75rem;">—</td>
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
#route-list li { margin-bottom: .55rem; font-size: .88rem; line-height: 1.4; }
#route-list li small { color: var(--gy); }
.route-step-badge {
    display: inline-flex; align-items: center; justify-content: center;
    width: 1.35rem; height: 1.35rem; border-radius: 50%;
    font-size: .7rem; font-weight: 700; color: #fff;
    margin-right: .3rem; flex-shrink: 0;
}
</style>

<script>
(function () {
    var jobs          = <?= json_encode(array_values($all_jobs), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var companyAddress = <?= json_encode($company_address) ?>;
    var appUrl        = <?= json_encode(defined('APP_URL') ? APP_URL : '') ?>;
    var today         = <?= json_encode($today) ?>;

    var map = L.map('dispatch-map', { zoomControl: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 18
    }).addTo(map);

    // ── Icon builders ────────────────────────────────────────────────────────
    function makeIcon(color) {
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="36" viewBox="0 0 26 36">'
            + '<path d="M13 0C5.82 0 0 5.82 0 13c0 9.75 13 23 13 23S26 22.75 26 13C26 5.82 20.18 0 13 0z" fill="' + color + '"/>'
            + '<circle cx="13" cy="13" r="5.5" fill="#fff"/>'
            + '</svg>';
        return L.divIcon({ html: svg, className: '', iconSize: [26, 36], iconAnchor: [13, 36], popupAnchor: [0, -34] });
    }

    function makeNumberedIcon(color, num) {
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="38" viewBox="0 0 28 38">'
            + '<path d="M14 0C6.27 0 0 6.27 0 14c0 10.5 14 24 14 24S28 24.5 28 14C28 6.27 21.73 0 14 0z" fill="' + color + '" stroke="#fff" stroke-width="1.5"/>'
            + '<text x="14" y="17" text-anchor="middle" dominant-baseline="middle" fill="#fff" font-size="11" font-weight="700" font-family="sans-serif">' + num + '</text>'
            + '</svg>';
        return L.divIcon({ html: svg, className: '', iconSize: [28, 38], iconAnchor: [14, 38], popupAnchor: [0, -36] });
    }

    function makeDepotIcon() {
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30">'
            + '<circle cx="15" cy="15" r="14" fill="#6b7280" stroke="#fff" stroke-width="2"/>'
            + '<text x="15" y="15" text-anchor="middle" dominant-baseline="middle" fill="#fff" font-size="11" font-weight="700" font-family="sans-serif">D</text>'
            + '</svg>';
        return L.divIcon({ html: svg, className: '', iconSize: [30, 30], iconAnchor: [15, 30], popupAnchor: [0, -32] });
    }

    var baseIcons = {
        delivery: makeIcon('#f97316'),
        pickup:   makeIcon('#3b82f6'),
        overdue:  makeIcon('#ef4444'),
    };

    function jobColor(job) {
        if (job.job_type === 'delivery') return '#f97316';
        if (job.pickup_date && job.pickup_date < today && job.status === 'pickup_requested') return '#ef4444';
        return '#3b82f6';
    }

    function pickBaseIcon(job) {
        if (job.job_type === 'delivery') return baseIcons.delivery;
        if (job.pickup_date && job.pickup_date < today && job.status === 'pickup_requested') return baseIcons.overdue;
        return baseIcons.pickup;
    }

    // ── State ────────────────────────────────────────────────────────────────
    var geocodedPoints = [];   // [{job, lat, lng, marker}]
    var depotLatLng    = null; // company address coords if available
    var depotMarker    = null;
    var routePolyline  = null;
    var geocodedCount  = 0;
    var routeActive    = false;

    var rowMap = {};
    document.querySelectorAll('.dispatch-row').forEach(function (row) {
        rowMap[row.dataset.jobId] = row;
    });

    // ── Helper: Haversine distance (km) ──────────────────────────────────────
    function haversine(lat1, lng1, lat2, lng2) {
        var R  = 6371;
        var dL = (lat2 - lat1) * Math.PI / 180;
        var dG = (lng2 - lng1) * Math.PI / 180;
        var a  = Math.sin(dL / 2) * Math.sin(dL / 2)
                 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180)
                 * Math.sin(dG / 2) * Math.sin(dG / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    // ── Nearest-neighbor TSP ─────────────────────────────────────────────────
    function nearestNeighbor(points, startLat, startLng) {
        var remaining = points.slice();
        var route     = [];
        var curLat    = startLat;
        var curLng    = startLng;

        while (remaining.length > 0) {
            var bestIdx  = 0;
            var bestDist = Infinity;
            for (var i = 0; i < remaining.length; i++) {
                var d = haversine(curLat, curLng, remaining[i].lat, remaining[i].lng);
                if (d < bestDist) { bestDist = d; bestIdx = i; }
            }
            var next = remaining.splice(bestIdx, 1)[0];
            route.push(next);
            curLat = next.lat;
            curLng = next.lng;
        }
        return route;
    }

    function totalDistance(route, startLat, startLng) {
        var dist = 0;
        var lat  = startLat;
        var lng  = startLng;
        for (var i = 0; i < route.length; i++) {
            dist += haversine(lat, lng, route[i].lat, route[i].lng);
            lat   = route[i].lat;
            lng   = route[i].lng;
        }
        return dist;
    }

    // ── Draw / clear route ───────────────────────────────────────────────────
    function clearRoute() {
        if (routePolyline) { map.removeLayer(routePolyline); routePolyline = null; }
        if (depotMarker)   { map.removeLayer(depotMarker);   depotMarker   = null; }

        // Restore base icons and reset step labels
        geocodedPoints.forEach(function (pt) {
            pt.marker.setIcon(pickBaseIcon(pt.job));
        });
        document.querySelectorAll('.route-step').forEach(function (el) {
            el.textContent = '—';
        });

        document.getElementById('route-panel').style.display = 'none';
        document.getElementById('btn-clear-route').style.display = 'none';
        routeActive = false;
    }

    function drawRoute() {
        clearRoute();
        if (geocodedPoints.length === 0) return;

        var startLat = depotLatLng ? depotLatLng[0] : geocodedPoints[0].lat;
        var startLng = depotLatLng ? depotLatLng[1] : geocodedPoints[0].lng;

        var ordered = nearestNeighbor(geocodedPoints, startLat, startLng);
        var dist    = totalDistance(ordered, startLat, startLng);

        // Draw polyline
        var latlngs = [[startLat, startLng]];
        ordered.forEach(function (pt) { latlngs.push([pt.lat, pt.lng]); });
        routePolyline = L.polyline(latlngs, {
            color: '#f97316', weight: 3, opacity: 0.7, dashArray: '6 6'
        }).addTo(map);

        // Depot marker
        if (depotLatLng) {
            depotMarker = L.marker(depotLatLng, { icon: makeDepotIcon() })
                .bindPopup('<strong>Depot</strong><br><small>' + escHtml(companyAddress) + '</small>')
                .addTo(map);
        }

        // Numbered markers
        ordered.forEach(function (pt, idx) {
            pt.marker.setIcon(makeNumberedIcon(jobColor(pt.job), idx + 1));
        });

        // Step labels in table rows
        ordered.forEach(function (pt, idx) {
            var row = rowMap[pt.job.id];
            if (row) {
                var cell = row.querySelector('.route-step');
                if (cell) cell.textContent = idx + 1;
            }
        });

        // Route panel
        var distLabel = dist < 1
            ? Math.round(dist * 1000) + ' m'
            : dist.toFixed(1) + ' km';
        document.getElementById('route-distance').textContent =
            'Approx. ' + distLabel + ' straight-line distance';

        var list = document.getElementById('route-list');
        list.innerHTML = '';
        ordered.forEach(function (pt, idx) {
            var color  = jobColor(pt.job);
            var type   = pt.job.job_type === 'delivery' ? 'Delivery' : 'Pickup';
            var li     = document.createElement('li');
            li.innerHTML = '<span class="route-step-badge" style="background:' + color + ';">' + (idx + 1) + '</span>'
                + '<strong>' + escHtml(pt.job.wo_number) + '</strong>'
                + ' &mdash; ' + type
                + ' &mdash; ' + escHtml(pt.job.cust_name)
                + '<br><small>' + escHtml(pt.job.service_address) + '</small>';
            li.style.display = 'flex';
            li.style.alignItems = 'flex-start';
            li.style.flexWrap = 'wrap';
            list.appendChild(li);
        });

        document.getElementById('route-panel').style.display = 'block';
        document.getElementById('btn-clear-route').style.display = '';
        routeActive = true;
        map.fitBounds(routePolyline.getBounds(), { padding: [40, 40] });
    }

    // ── escHtml ──────────────────────────────────────────────────────────────
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── Add marker ───────────────────────────────────────────────────────────
    var bounds = [];

    function addMarker(job, lat, lng) {
        var icon   = pickBaseIcon(job);
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

        geocodedPoints.push({ job: job, lat: lat, lng: lng, marker: marker });
        bounds.push([lat, lng]);
        if (bounds.length === 1) {
            map.setView([lat, lng], 13);
        } else {
            map.fitBounds(bounds, { padding: [40, 40] });
        }

        geocodedCount++;
        checkGeocodeComplete();
    }

    function checkGeocodeComplete() {
        if (geocodedCount >= geocodeQueue.length) {
            var btn = document.getElementById('btn-optimize');
            if (btn && geocodedPoints.length >= 2) {
                btn.disabled = false;
                btn.title = 'Optimize stop order using nearest-neighbor routing';
            }
        }
    }

    // ── Geocode queue ────────────────────────────────────────────────────────
    var geocodeQueue = jobs.slice();
    var geocodeIndex = 0;

    function geocodeNext() {
        if (geocodeIndex >= geocodeQueue.length) return;
        var job = geocodeQueue[geocodeIndex++];
        if (!job.service_address) {
            geocodedCount++;
            checkGeocodeComplete();
            setTimeout(geocodeNext, 200);
            return;
        }

        fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(job.service_address), {
            headers: { 'Accept-Language': 'en' }
        })
        .then(function (r) { return r.json(); })
        .then(function (results) {
            if (results && results.length > 0) {
                addMarker(job, parseFloat(results[0].lat), parseFloat(results[0].lon));
            } else {
                geocodedCount++;
                checkGeocodeComplete();
            }
        })
        .catch(function () {
            geocodedCount++;
            checkGeocodeComplete();
        })
        .finally(function () {
            setTimeout(geocodeNext, 1100); // respect Nominatim 1 req/s
        });
    }

    // ── Init ─────────────────────────────────────────────────────────────────
    if (jobs.length === 0) {
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
        map.setView([39.5, -98.35], 4);

        // Geocode company address first (for route start point), then jobs
        if (companyAddress) {
            fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(companyAddress), {
                headers: { 'Accept-Language': 'en' }
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.length) {
                    depotLatLng = [parseFloat(d[0].lat), parseFloat(d[0].lon)];
                }
            })
            .catch(function () {})
            .finally(function () {
                setTimeout(geocodeNext, 1100);
            });
        } else {
            geocodeNext();
        }
    }

    // ── Button wiring ─────────────────────────────────────────────────────────
    var btnOptimize = document.getElementById('btn-optimize');
    var btnClear    = document.getElementById('btn-clear-route');

    if (btnOptimize) {
        btnOptimize.addEventListener('click', function () {
            if (routeActive) {
                clearRoute();
            } else {
                drawRoute();
            }
        });
    }

    if (btnClear) {
        btnClear.addEventListener('click', clearRoute);
    }

    // ── Table row click ───────────────────────────────────────────────────────
    document.querySelectorAll('.dispatch-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('a, button')) return;
            window.location.href = row.dataset.href;
        });
    });
}());
</script>

<?php layout_end(); ?>
