<?php
/**
 * Dashboard – Trash Panda Roll-Offs
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once INC_PATH . '/launch_readiness.php';
require_once TMPL_PATH . '/layout.php';

require_login();

// ── KPI Queries ──────────────────────────────────────────────────────────────

$wo_active = (int)(db_fetch(
    "SELECT COUNT(*) AS cnt FROM work_orders WHERE status IN ('scheduled','delivered','active','pickup_requested')"
)['cnt'] ?? 0);

$wo_today_deliveries = (int)(db_fetch(
    "SELECT COUNT(*) AS cnt FROM work_orders
     WHERE delivery_date = CURDATE() AND status NOT IN ('canceled','completed')"
)['cnt'] ?? 0);

$wo_today_pickups = (int)(db_fetch(
    "SELECT COUNT(*) AS cnt FROM work_orders
     WHERE pickup_date = CURDATE() AND status NOT IN ('canceled','completed')"
)['cnt'] ?? 0);

$revenue_row = db_fetch(
    "SELECT COALESCE(SUM(amount), 0) AS total FROM work_orders
     WHERE status = 'completed'
       AND MONTH(updated_at) = MONTH(NOW())
       AND YEAR(updated_at)  = YEAR(NOW())"
);
$revenue_month = (float)($revenue_row['total'] ?? 0);

// Revenue from paid bookings this month (cash, check, or Stripe paid)
$booking_revenue_month = 0.0;
try {
    $br = db_fetch(
        "SELECT COALESCE(SUM(total_amount), 0) AS total FROM bookings
         WHERE payment_status IN ('paid','paid_cash','paid_check')
           AND booking_status != 'canceled'
           AND MONTH(updated_at) = MONTH(NOW())
           AND YEAR(updated_at)  = YEAR(NOW())"
    );
    $booking_revenue_month = (float)($br['total'] ?? 0);
} catch (\Throwable $e) {
    // bookings table not yet installed
}

// Revenue from paid invoices this month
$invoice_revenue_month = 0.0;
try {
    $ir = db_fetch(
        "SELECT COALESCE(SUM(total), 0) AS total FROM invoices
         WHERE status = 'paid'
           AND MONTH(updated_at) = MONTH(NOW())
           AND YEAR(updated_at)  = YEAR(NOW())"
    );
    $invoice_revenue_month = (float)($ir['total'] ?? 0);
} catch (\Throwable $e) {
    // invoices table not yet installed
}

// Revenue from work orders this month (amount field, not payments table)
$revenue_payments_month = $revenue_month + $booking_revenue_month + $invoice_revenue_month;

$launch_readiness = launch_readiness_data();
$dumpsters_available = (int)$launch_readiness['dumpsters_available'];

// ── Booking KPIs (wrapped in try/catch in case bookings table not yet installed) ──
$bookings_total      = 0;
$bookings_this_month = 0;
$bookings_upcoming   = 0;
$bookings_unpaid     = 0;
$recent_bookings     = [];
try {
    $bookings_total      = (int)(db_fetch("SELECT COUNT(*) AS cnt FROM bookings WHERE booking_status != 'canceled'")['cnt'] ?? 0);
    $bookings_this_month = (int)(db_fetch(
        "SELECT COUNT(*) AS cnt FROM bookings
         WHERE booking_status != 'canceled'
           AND MONTH(created_at) = MONTH(NOW())
           AND YEAR(created_at)  = YEAR(NOW())"
    )['cnt'] ?? 0);
    $bookings_upcoming = (int)(db_fetch("SELECT COUNT(*) AS cnt FROM bookings WHERE rental_start >= CURDATE() AND booking_status NOT IN ('canceled','completed')")['cnt'] ?? 0);
    $bookings_unpaid   = (int)(db_fetch("SELECT COUNT(*) AS cnt FROM bookings WHERE payment_status IN ('unpaid','pending','pending_cash','pending_check') AND booking_status != 'canceled'")['cnt'] ?? 0);
    $recent_bookings   = db_fetchall(
        "SELECT b.id, b.booking_number, b.customer_name, b.unit_size,
                b.rental_start, b.rental_end, b.total_amount,
                b.booking_status, b.payment_status, b.created_at
         FROM bookings b
         WHERE b.booking_status != 'canceled'
         ORDER BY b.created_at DESC
         LIMIT 8"
    );
} catch (\Throwable $e) {
    // Bookings table not yet installed — silently skip.
}

// ── Recent Work Orders ───────────────────────────────────────────────────────
$launch_checks = $launch_readiness['checks'];
$launch_ready_count = (int)$launch_readiness['ready_count'];
$launch_total_count = (int)$launch_readiness['total_count'];
$launch_score = (int)$launch_readiness['score'];
$launch_status = (string)$launch_readiness['status'];
$launch_status_class = (string)$launch_readiness['status_class'];
$launch_pending_checks = $launch_readiness['pending_checks'];

$recent_wo = db_fetchall(
    "SELECT wo.id, wo.wo_number, wo.cust_name, wo.status, wo.delivery_date, wo.amount,
            c.name AS customer_name
     FROM work_orders wo
     LEFT JOIN customers c ON c.id = wo.customer_id
     ORDER BY wo.created_at DESC
     LIMIT 8"
);

// ── Upcoming Deliveries (next 7 days) ────────────────────────────────────────
$upcoming_deliveries = db_fetchall(
    "SELECT wo.id, wo.wo_number, wo.cust_name, wo.service_address, wo.service_city,
            wo.delivery_date, wo.size, wo.status
     FROM work_orders wo
     WHERE wo.delivery_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
       AND wo.status NOT IN ('canceled','completed')
     ORDER BY wo.delivery_date ASC"
);

// ── Upcoming Pickups (next 7 days) ───────────────────────────────────────────
$upcoming_pickups = db_fetchall(
    "SELECT wo.id, wo.wo_number, wo.cust_name, wo.service_address, wo.service_city,
            wo.pickup_date, wo.size, wo.status
     FROM work_orders wo
     WHERE wo.pickup_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
       AND wo.status NOT IN ('canceled','completed')
     ORDER BY wo.pickup_date ASC"
);

// ── Overdue Pickups ───────────────────────────────────────────────────────────
$overdue_pickups = db_fetchall(
    "SELECT wo.id, wo.wo_number, wo.cust_name, wo.service_address, wo.service_city,
            wo.pickup_date, wo.size, wo.status
     FROM work_orders wo
     WHERE wo.pickup_date < CURDATE()
       AND wo.status NOT IN ('picked_up','completed','canceled')
     ORDER BY wo.pickup_date ASC"
);

// ── Stripe metrics (best-effort – silently skipped if not configured) ────────
$stripe_revenue_month  = null;
$stripe_revenue_today  = null;
$stripe_charges_count  = null;
$stripe_failed_count   = null;
$stripe_recent_charges = [];
$stripe_available      = false;
try {
    require_once INC_PATH . '/stripe.php';
    $stripe_client = stripe_client();
    $month_start   = (int)strtotime(date('Y-m-01 00:00:00'));
    $today_start   = (int)strtotime('today midnight');
    $now_ts        = time();

    $stripe_charges = $stripe_client->charges->all([
        'limit'   => 100,
        'created' => ['gte' => $month_start, 'lte' => $now_ts],
    ]);

    $s_rev_month   = 0.0;
    $s_rev_today   = 0.0;
    $s_count_month = 0;
    $s_failed      = 0;

    foreach ($stripe_charges->data as $ch) {
        if ($ch->status === 'failed') {
            $s_failed++;
            continue;
        }
        if ($ch->status !== 'succeeded' || $ch->refunded) {
            continue;
        }
        $amt = $ch->amount / 100;
        $s_rev_month += $amt;
        $s_count_month++;
        if ($ch->created >= $today_start) {
            $s_rev_today += $amt;
        }
        if (count($stripe_recent_charges) < 5) {
            $stripe_recent_charges[] = [
                'id'          => $ch->id,
                'amount'      => $amt,
                'customer'    => $ch->billing_details->name ?? ($ch->metadata['customer_name'] ?? null),
                'description' => $ch->description ?: ($ch->metadata['booking_number'] ?? null),
                'created'     => date('Y-m-d H:i:s', $ch->created),
            ];
        }
    }

    $stripe_revenue_month = round($s_rev_month, 2);
    $stripe_revenue_today = round($s_rev_today, 2);
    $stripe_charges_count = $s_count_month;
    $stripe_failed_count  = $s_failed;
    $stripe_available     = true;
} catch (\Throwable $e) {
    // Stripe not configured or unavailable — silently fall back to DB data.
}

// ── Layout ───────────────────────────────────────────────────────────────────
layout_start('Dashboard', 'dashboard');
?>

<!-- Page Header -->
<div class="tp-page-header d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="tp-page-title">Dashboard</h1>
        <p class="tp-page-sub mb-0"><?= e(date('l, F j, Y')) ?></p>
    </div>
    <div class="d-flex gap-2 align-items-center no-print">
        <span id="dash-last-updated" class="text-gy" style="font-size:.75rem;" title="Auto-refreshes every 60 seconds">
            <i class="fa-solid fa-rotate me-1 text-or"></i>Live
        </span>
        <a href="<?= e(APP_URL) ?>/modules/bookings/create.php" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-plus"></i> New Booking
        </a>
        <a href="<?= e(APP_URL) ?>/modules/work_orders/create.php" class="btn-tp-primary btn-tp-sm">
            <i class="fa-solid fa-plus"></i> New Work Order
        </a>
    </div>
</div>

<!-- ── KPI Cards ──────────────────────────────────────────────────────────── -->
<style>
.tp-launch-card {
    border: 1px solid rgba(249,115,22,.18);
    background: linear-gradient(135deg, rgba(15,23,42,.98), rgba(30,41,59,.94));
}
.tp-launch-score {
    width: 88px;
    height: 88px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    font-weight: 800;
    color: var(--wh);
    background: radial-gradient(circle at 30% 30%, rgba(249,115,22,.45), rgba(15,23,42,.95));
    border: 2px solid rgba(249,115,22,.35);
    flex-shrink: 0;
}
.tp-launch-meta h2 {
    margin: 0 0 .35rem;
    font-size: 1.1rem;
    color: var(--wh);
}
.tp-launch-meta p {
    margin: 0;
    color: var(--gl);
    font-size: .9rem;
}
.tp-launch-status {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .35rem .65rem;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
}
.tp-launch-status-good {
    background: rgba(34,197,94,.14);
    color: #86efac;
    border: 1px solid rgba(34,197,94,.28);
}
.tp-launch-status-mid {
    background: rgba(250,204,21,.14);
    color: #fde68a;
    border: 1px solid rgba(250,204,21,.26);
}
.tp-launch-status-warn {
    background: rgba(248,113,113,.14);
    color: #fca5a5;
    border: 1px solid rgba(248,113,113,.26);
}
.tp-launch-progress {
    width: 100%;
    height: 10px;
    border-radius: 999px;
    background: rgba(148,163,184,.16);
    overflow: hidden;
}
.tp-launch-progress-bar {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #f97316, #fb923c);
}
.tp-launch-pill {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .35rem .65rem;
    border-radius: 999px;
    background: rgba(15,23,42,.72);
    border: 1px solid rgba(148,163,184,.18);
    color: var(--gl);
    font-size: .8rem;
    text-decoration: none;
}
.tp-launch-pill:hover {
    border-color: rgba(249,115,22,.28);
    color: var(--wh);
    text-decoration: none;
}
</style>

<div class="tp-card tp-launch-card mb-4">
    <div class="tp-card-body">
        <div class="d-flex flex-column flex-lg-row gap-3 align-items-start align-items-lg-center justify-content-between">
            <div class="d-flex gap-3 align-items-center">
                <a href="<?= e(APP_URL) ?>/modules/help/launch.php" class="tp-launch-score text-decoration-none"><?= $launch_score ?>%</a>
                <div class="tp-launch-meta">
                    <div class="mb-2">
                        <span class="tp-launch-status <?= e($launch_status_class) ?>">
                            <i class="fa-solid fa-rocket"></i> <?= e($launch_status) ?>
                        </span>
                    </div>
                    <h2><a href="<?= e(APP_URL) ?>/modules/help/launch.php" class="text-reset text-decoration-none">Launch Readiness</a></h2>
                    <p><?= $launch_ready_count ?> of <?= $launch_total_count ?> setup checks are complete. Finish the remaining items, then run one full booking and payment test.</p>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="<?= e(APP_URL) ?>/modules/help/launch.php" class="btn-tp-primary btn-tp-sm">
                    <i class="fa-solid fa-rocket"></i> Open Launch Checklist
                </a>
                <a href="<?= e(APP_URL) ?>/modules/help/index.php" class="btn-tp-ghost btn-tp-sm">
                    <i class="fa-solid fa-list-check"></i> Testing Guide
                </a>
            </div>
        </div>

        <div class="mt-3">
            <div class="tp-launch-progress" aria-hidden="true">
                <div class="tp-launch-progress-bar" style="width: <?= $launch_score ?>%;"></div>
            </div>
        </div>

        <div class="mt-3 d-flex flex-wrap gap-2">
            <?php foreach ($launch_checks as $check): ?>
                <a href="<?= e($check['href']) ?>" class="tp-launch-pill">
                    <i class="fa-solid <?= $check['ready'] ? 'fa-circle-check text-gr' : 'fa-circle text-or' ?>"></i>
                    <?= e($check['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($launch_pending_checks)): ?>
            <div class="mt-3" style="font-size:.84rem;color:var(--gl);">
                Next up:
                <?php foreach (array_slice($launch_pending_checks, 0, 2) as $idx => $check): ?>
                    <?php if ($idx > 0): ?> and <?php endif; ?>
                    <a href="<?= e($check['href']) ?>" class="tp-link"><?= e($check['label']) ?></a>
                <?php endforeach; ?>.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">

    <!-- Upcoming Bookings -->
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(APP_URL) ?>/modules/bookings/index.php?filter=upcoming" class="tp-kpi-card tp-kpi-orange text-decoration-none d-block">
            <div class="kpi-icon"><i class="fa-solid fa-calendar-check"></i></div>
            <div class="kpi-value"><?= $bookings_upcoming ?></div>
            <div class="kpi-label">Upcoming Bookings</div>
        </a>
    </div>

    <!-- Active Work Orders -->
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(APP_URL) ?>/modules/work_orders/index.php" class="tp-kpi-card tp-kpi-blue text-decoration-none d-block">
            <div class="kpi-icon"><i class="fa-solid fa-clipboard-list"></i></div>
            <div class="kpi-value" data-metric="kpis.wo_active"><?= $wo_active ?></div>
            <div class="kpi-label">Active WOs</div>
        </a>
    </div>

    <!-- Today's Deliveries -->
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(APP_URL) ?>/modules/calendar/index.php" class="tp-kpi-card tp-kpi-green text-decoration-none d-block">
            <div class="kpi-icon"><i class="fa-solid fa-truck"></i></div>
            <div class="kpi-value" data-metric="kpis.wo_today_deliveries"><?= $wo_today_deliveries ?></div>
            <div class="kpi-label">Today Deliveries</div>
        </a>
    </div>

    <!-- Today's Pickups -->
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(APP_URL) ?>/modules/calendar/index.php" class="tp-kpi-card tp-kpi-amber text-decoration-none d-block">
            <div class="kpi-icon"><i class="fa-solid fa-truck-ramp-box"></i></div>
            <div class="kpi-value" data-metric="kpis.wo_today_pickups"><?= $wo_today_pickups ?></div>
            <div class="kpi-label">Today Pickups</div>
        </a>
    </div>

    <!-- Month Revenue — shows Stripe revenue when available, falls back to DB -->
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(APP_URL) ?>/modules/reports/index.php" class="tp-kpi-card tp-kpi-green text-decoration-none d-block">
            <div class="kpi-icon"><i class="fa-solid fa-dollar-sign"></i></div>
            <div class="kpi-value" data-metric="kpis.revenue_month" data-metric-format="money" style="font-size:1.1rem;">
                <?= $stripe_available ? fmt_money($stripe_revenue_month) : fmt_money($revenue_payments_month) ?>
            </div>
            <div class="kpi-label">Revenue This Month<?= $stripe_available ? ' <span style="font-size:.6rem;opacity:.6;">(Stripe)</span>' : '' ?></div>
        </a>
    </div>

    <!-- Available Dumpsters -->
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(APP_URL) ?>/modules/dumpsters/index.php" class="tp-kpi-card tp-kpi-gray text-decoration-none d-block">
            <div class="kpi-icon"><i class="fa-solid fa-dumpster"></i></div>
            <div class="kpi-value" data-metric="kpis.dumpsters_available"><?= $dumpsters_available ?></div>
            <div class="kpi-label">Available Dumpsters</div>
        </a>
    </div>

    <!-- Overdue Pickups -->
    <?php $overdue_count_num = count($overdue_pickups); ?>
    <div class="col-6 col-md-4 col-xl-2" id="kpi-overdue-wrap">
        <a href="<?= e(APP_URL) ?>/modules/work_orders/index.php?status=overdue"
           class="tp-kpi-card <?= $overdue_count_num > 0 ? 'tp-kpi-red' : 'tp-kpi-gray' ?> text-decoration-none d-block">
            <div class="kpi-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
            <div class="kpi-value <?= $overdue_count_num > 0 ? 'kpi-value-danger' : '' ?>" data-metric="kpis.overdue_pickups"><?= $overdue_count_num ?></div>
            <div class="kpi-label">Overdue Pickups</div>
        </a>
    </div>

</div><!-- /.row KPI cards -->

<!-- ── Stripe KPI Cards (only shown when Stripe is configured) ───────────── -->
<?php if ($stripe_available): ?>
<div class="row g-3 mb-4" id="stripe-kpi-row">

    <!-- Stripe Revenue Today -->
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card tp-kpi-stripe">
            <div class="kpi-icon"><i class="fa-brands fa-stripe" style="color:var(--or);"></i></div>
            <div class="kpi-value" data-metric="stripe.revenue_today" data-metric-format="money">
                <?= fmt_money($stripe_revenue_today) ?>
            </div>
            <div class="kpi-label">Stripe Revenue Today</div>
        </div>
    </div>

    <!-- Stripe Revenue This Month -->
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card tp-kpi-stripe">
            <div class="kpi-icon"><i class="fa-brands fa-stripe" style="color:var(--or);"></i></div>
            <div class="kpi-value" data-metric="stripe.revenue_month" data-metric-format="money">
                <?= fmt_money($stripe_revenue_month) ?>
            </div>
            <div class="kpi-label">Stripe Revenue (Month)</div>
        </div>
    </div>

    <!-- Stripe Payments Count -->
    <div class="col-6 col-md-3">
        <div class="tp-kpi-card tp-kpi-stripe">
            <div class="kpi-icon"><i class="fa-solid fa-credit-card" style="color:var(--or);"></i></div>
            <div class="kpi-value" data-metric="stripe.charges_month_count">
                <?= (int)$stripe_charges_count ?>
            </div>
            <div class="kpi-label">Payments This Month</div>
        </div>
    </div>

    <!-- Failed Payments -->
    <div class="col-6 col-md-3">
        <?php $is_failed = $stripe_failed_count > 0; ?>
        <div class="tp-kpi-card <?= $is_failed ? 'tp-kpi-red' : 'tp-kpi-stripe' ?>">
            <div class="kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="kpi-value <?= $is_failed ? 'kpi-value-danger' : '' ?>" data-metric="stripe.failed_month_count">
                <?= (int)$stripe_failed_count ?>
            </div>
            <div class="kpi-label">Failed Payments</div>
        </div>
    </div>

</div><!-- /.row stripe KPI cards -->
<?php endif; ?>

<!-- ── Booking KPI Cards ──────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

    <!-- Bookings This Month -->
    <div class="col-6 col-md-4 col-xl-4">
        <a href="<?= e(APP_URL) ?>/modules/bookings/index.php" class="tp-kpi-card tp-kpi-blue text-decoration-none d-block">
            <div class="kpi-icon"><i class="fa-solid fa-calendar-check"></i></div>
            <div class="kpi-value" data-metric="bookings.this_month"><?= $bookings_this_month ?></div>
            <div class="kpi-label">Bookings This Month</div>
        </a>
    </div>

    <!-- Upcoming Bookings -->
    <div class="col-6 col-md-4 col-xl-4">
        <a href="<?= e(APP_URL) ?>/modules/bookings/index.php?filter=upcoming" class="tp-kpi-card tp-kpi-green text-decoration-none d-block">
            <div class="kpi-icon"><i class="fa-solid fa-calendar-days"></i></div>
            <div class="kpi-value" data-metric="bookings.upcoming"><?= $bookings_upcoming ?></div>
            <div class="kpi-label">Upcoming Bookings</div>
        </a>
    </div>

    <!-- Unpaid Bookings -->
    <div class="col-6 col-md-4 col-xl-4">
        <a href="<?= e(APP_URL) ?>/modules/bookings/index.php?filter=pending" class="tp-kpi-card tp-kpi-amber text-decoration-none d-block">
            <div class="kpi-icon"><i class="fa-solid fa-clock"></i></div>
            <div class="kpi-value" data-metric="bookings.unpaid"><?= $bookings_unpaid ?></div>
            <div class="kpi-label">Awaiting Payment</div>
        </a>
    </div>

</div><!-- /.row booking KPI cards -->

<!-- ── Recent Bookings ──────────────────────────────────────────────────── -->
<?php if (!empty($recent_bookings)): ?>
<div class="tp-card mb-4">
    <div class="tp-card-header d-flex align-items-center justify-content-between">
        <span><i class="fa-solid fa-calendar-check me-2 text-muted"></i>Recent Bookings</span>
        <a href="<?= e(APP_URL) ?>/modules/bookings/index.php" class="btn-tp-ghost btn-tp-xs">
            View All <i class="fa-solid fa-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="tp-card-body p-0">
        <div class="table-responsive">
            <table class="tp-table mb-0">
                <thead>
                    <tr>
                        <th>Booking #</th>
                        <th>Customer</th>
                        <th>Size</th>
                        <th>Dates</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_bookings as $b): ?>
                    <tr>
                        <td>
                            <a href="<?= e(APP_URL) ?>/modules/bookings/view.php?id=<?= (int)$b['id'] ?>"
                               class="tp-link fw-semibold">
                                <?= e($b['booking_number']) ?>
                            </a>
                        </td>
                        <td><?= e($b['customer_name']) ?></td>
                        <td><?= e($b['unit_size'] ?: '—') ?></td>
                        <td style="white-space:nowrap;">
                            <?= e(fmt_date($b['rental_start'])) ?>
                            <span class="text-muted">→</span>
                            <?= e(fmt_date($b['rental_end'])) ?>
                        </td>
                                <td class="text-end text-gr fw-semibold">
                            <?= e(fmt_money($b['total_amount'])) ?>
                        </td>
                        <td><?= status_badge($b['booking_status']) ?></td>
                        <td><?= payment_badge($b['payment_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Two-column grid: Recent WOs + Quick Stats ─────────────────────────── -->
<div class="row g-4 mb-4">

    <!-- Recent Work Orders -->
    <div class="col-12 col-lg-7">
        <div class="tp-card h-100">
            <div class="tp-card-header d-flex align-items-center justify-content-between">
                <span><i class="fa-solid fa-clipboard-list me-2 text-muted"></i>Recent Work Orders</span>
                <a href="<?= e(APP_URL) ?>/modules/work_orders/index.php" class="btn-tp-ghost btn-tp-xs">
                    View All <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="tp-card-body p-0">
                <?php if (empty($recent_wo)): ?>
                    <p class="text-muted p-3 mb-0 text-center" style="font-size:.875rem;">
                        No work orders yet.
                    </p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="tp-table mb-0">
                        <thead>
                            <tr>
                                <th>WO #</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th>Delivery</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent_wo as $wo): ?>
                            <tr>
                                <td>
                                    <a href="<?= e(APP_URL) ?>/modules/work_orders/view.php?id=<?= (int)$wo['id'] ?>"
                                       class="tp-link fw-semibold">
                                        <?= e($wo['wo_number']) ?>
                                    </a>
                                </td>
                                <td><?= e($wo['customer_name'] ?: $wo['cust_name']) ?></td>
                                <td><?= status_badge($wo['status']) ?></td>
                                <td><?= $wo['delivery_date'] ? e(fmt_date($wo['delivery_date'])) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end"><?= e(fmt_money($wo['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="col-12 col-lg-5">
        <div class="tp-card h-100">
            <div class="tp-card-header">
                <i class="fa-solid fa-chart-simple me-2 text-muted"></i>Today's Schedule
            </div>
            <div class="tp-card-body">

                <div class="tp-stat-item mb-2">
                    <span class="tp-stat-icon" style="color:var(--gr);"><i class="fa-solid fa-truck"></i></span>
                    <div class="tp-stat-info">
                        <div class="tp-stat-label">Deliveries Today</div>
                        <div class="tp-stat-value" data-metric="kpis.wo_today_deliveries"><?= $wo_today_deliveries ?></div>
                    </div>
                    <a href="<?= e(APP_URL) ?>/modules/calendar/index.php" class="btn-tp-ghost btn-tp-xs">View</a>
                </div>

                <div class="tp-stat-item mb-3">
                    <span class="tp-stat-icon" style="color:var(--am);"><i class="fa-solid fa-truck-ramp-box"></i></span>
                    <div class="tp-stat-info">
                        <div class="tp-stat-label">Pickups Today</div>
                        <div class="tp-stat-value" data-metric="kpis.wo_today_pickups"><?= $wo_today_pickups ?></div>
                    </div>
                    <a href="<?= e(APP_URL) ?>/modules/calendar/index.php" class="btn-tp-ghost btn-tp-xs">View</a>
                </div>

                <hr style="border-color:var(--st);margin:1rem 0;">

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-gy" style="font-size:.85rem;">Upcoming Deliveries (7 days)</span>
                    <span class="tp-badge badge-delivered" data-metric="kpis.upcoming_deliveries_7d"><?= count($upcoming_deliveries) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-gy" style="font-size:.85rem;">Upcoming Pickups (7 days)</span>
                    <span class="tp-badge badge-pickup-requested" data-metric="kpis.upcoming_pickups_7d"><?= count($upcoming_pickups) ?></span>
                </div>
                <?php if (count($overdue_pickups) > 0): ?>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="tp-overdue-text" style="font-size:.85rem;">Overdue Pickups</span>
                    <span class="tp-badge badge-canceled" data-metric="kpis.overdue_pickups"><?= count($overdue_pickups) ?></span>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

</div><!-- /.row two-col -->

<!-- ── Upcoming Deliveries Table ─────────────────────────────────────────── -->
<div class="tp-card mb-4">
    <div class="tp-card-header d-flex align-items-center justify-content-between">
        <span class="tp-card-title"><i class="fa-solid fa-truck me-2 text-gr"></i>Upcoming Deliveries — Next 7 Days</span>
        <span class="tp-badge badge-delivered"><?= count($upcoming_deliveries) ?></span>
    </div>
    <div class="tp-card-body p-0">
        <?php if (empty($upcoming_deliveries)): ?>
            <p class="text-muted p-3 mb-0 text-center" style="font-size:.875rem;">
                No deliveries scheduled in the next 7 days.
            </p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="tp-table mb-0">
                <thead>
                    <tr>
                        <th>WO #</th>
                        <th>Customer</th>
                        <th>Service Address</th>
                        <th>Size</th>
                        <th>Delivery Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($upcoming_deliveries as $wo): ?>
                    <tr <?= $wo['delivery_date'] === date('Y-m-d') ? 'class="tp-row-today-delivery"' : '' ?>>
                        <td>
                            <a href="<?= e(APP_URL) ?>/modules/work_orders/view.php?id=<?= (int)$wo['id'] ?>"
                               class="tp-link fw-semibold">
                                <?= e($wo['wo_number']) ?>
                            </a>
                        </td>
                        <td><?= e($wo['cust_name']) ?></td>
                        <td>
                            <?= e($wo['service_address']) ?>
                            <?php if ($wo['service_city']): ?>
                                <span class="text-muted">, <?= e($wo['service_city']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($wo['size'] ?: '—') ?></td>
                        <td>
                            <?php if ($wo['delivery_date'] === date('Y-m-d')): ?>
                                <span class="tp-today-delivery">Today</span>
                            <?php else: ?>
                                <?= e(fmt_date($wo['delivery_date'])) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= status_badge($wo['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Upcoming Pickups Table ────────────────────────────────────────────── -->
<div class="tp-card mb-4">
    <div class="tp-card-header d-flex align-items-center justify-content-between">
        <span class="tp-card-title"><i class="fa-solid fa-truck-ramp-box me-2 text-am"></i>Upcoming Pickups — Next 7 Days</span>
        <span class="tp-badge badge-pickup-requested"><?= count($upcoming_pickups) ?></span>
    </div>
    <div class="tp-card-body p-0">
        <?php if (empty($upcoming_pickups)): ?>
            <p class="text-muted p-3 mb-0 text-center" style="font-size:.875rem;">
                No pickups scheduled in the next 7 days.
            </p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="tp-table mb-0">
                <thead>
                    <tr>
                        <th>WO #</th>
                        <th>Customer</th>
                        <th>Service Address</th>
                        <th>Size</th>
                        <th>Pickup Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($upcoming_pickups as $wo): ?>
                    <tr <?= $wo['pickup_date'] === date('Y-m-d') ? 'class="tp-row-today-pickup"' : '' ?>>
                        <td>
                            <a href="<?= e(APP_URL) ?>/modules/work_orders/view.php?id=<?= (int)$wo['id'] ?>"
                               class="tp-link fw-semibold">
                                <?= e($wo['wo_number']) ?>
                            </a>
                        </td>
                        <td><?= e($wo['cust_name']) ?></td>
                        <td>
                            <?= e($wo['service_address']) ?>
                            <?php if ($wo['service_city']): ?>
                                <span class="text-muted">, <?= e($wo['service_city']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($wo['size'] ?: '—') ?></td>
                        <td>
                            <?php if ($wo['pickup_date'] === date('Y-m-d')): ?>
                                <span class="tp-today-pickup">Today</span>
                            <?php else: ?>
                                <?= e(fmt_date($wo['pickup_date'])) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= status_badge($wo['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Overdue Pickups Table ─────────────────────────────────────────────── -->
<?php if (!empty($overdue_pickups)): ?>
<div class="tp-card mb-4 tp-card-danger">
    <div class="tp-card-header d-flex align-items-center justify-content-between tp-card-header-danger">
        <span class="tp-card-title">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>Overdue Pickups
        </span>
        <span class="tp-badge badge-canceled"><?= count($overdue_pickups) ?></span>
    </div>
    <div class="tp-card-body p-0">
        <div class="table-responsive">
            <table class="tp-table mb-0">
                <thead>
                    <tr>
                        <th>WO #</th>
                        <th>Customer</th>
                        <th>Service Address</th>
                        <th>Size</th>
                        <th>Pickup Date</th>
                        <th>Days Overdue</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($overdue_pickups as $wo): ?>
                    <?php
                        $overdue_days = (int)floor((time() - strtotime($wo['pickup_date'])) / 86400);
                    ?>
                    <tr class="tp-row-overdue">
                        <td>
                            <a href="<?= e(APP_URL) ?>/modules/work_orders/view.php?id=<?= (int)$wo['id'] ?>"
                               class="tp-link fw-semibold tp-overdue-link">
                                <?= e($wo['wo_number']) ?>
                            </a>
                        </td>
                        <td><?= e($wo['cust_name']) ?></td>
                        <td>
                            <?= e($wo['service_address']) ?>
                            <?php if ($wo['service_city']): ?>
                                <span class="text-muted">, <?= e($wo['service_city']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($wo['size'] ?: '—') ?></td>
                        <td class="tp-overdue-text"><?= e(fmt_date($wo['pickup_date'])) ?></td>
                        <td>
                            <span class="tp-overdue-days">
                                <?= $overdue_days ?> day<?= $overdue_days !== 1 ? 's' : '' ?>
                            </span>
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

<!-- ── Recent Activity Feed ──────────────────────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-12 col-lg-6">
        <div class="tp-card h-100">
            <div class="tp-card-header d-flex align-items-center justify-content-between">
                <span class="tp-card-title"><i class="fa-solid fa-bolt me-2 text-or"></i>Recent Activity</span>
                <span id="activity-refresh-badge" class="tp-badge tp-kpi-stripe" style="display:none;">
                    <i class="fa-solid fa-rotate fa-spin me-1"></i>Refreshing…
                </span>
            </div>
            <div class="tp-card-body p-0" id="activity-feed">
                <?php
                // Build a combined initial activity feed (same logic as API endpoint).
                $init_activity = [];
                try {
                    $init_bookings = db_fetchall(
                        "SELECT id, booking_number, customer_name, booking_status, payment_status, updated_at
                         FROM bookings ORDER BY updated_at DESC LIMIT 5"
                    );
                    foreach ($init_bookings as $b) {
                        $init_activity[] = [
                            'type'      => 'booking',
                            'icon'      => 'fa-calendar-check',
                            'color'     => '#22c55e',
                            'title'     => 'Booking ' . ($b['booking_number'] ?? '#' . $b['id']),
                            'detail'    => ($b['customer_name'] ?? 'Customer') . ' — ' . ucfirst(str_replace('_', ' ', $b['booking_status'])),
                            'sub'       => 'Payment: ' . ucfirst(str_replace('_', ' ', $b['payment_status'] ?? '')),
                            'timestamp' => $b['updated_at'],
                        ];
                    }
                } catch (\Throwable $e) { /* bookings table may not exist */ }

                $init_wo = db_fetchall(
                    "SELECT id, wo_number, cust_name, status, updated_at
                     FROM work_orders ORDER BY updated_at DESC LIMIT 5"
                );
                foreach ($init_wo as $wo) {
                    $init_activity[] = [
                        'type'      => 'work_order',
                        'icon'      => 'fa-clipboard-list',
                        'color'     => '#3b82f6',
                        'title'     => 'Work Order ' . ($wo['wo_number'] ?? '#' . $wo['id']),
                        'detail'    => ($wo['cust_name'] ?? 'Customer') . ' — ' . ucfirst(str_replace('_', ' ', $wo['status'])),
                        'sub'       => null,
                        'timestamp' => $wo['updated_at'],
                    ];
                }
                foreach ($stripe_recent_charges as $ch) {
                    $init_activity[] = [
                        'type'      => 'stripe',
                        'icon'      => 'fa-credit-card',
                        'color'     => '#f97316',
                        'title'     => 'Stripe Payment',
                        'detail'    => ($ch['customer'] ?? 'Customer') . ' — $' . number_format($ch['amount'], 2),
                        'sub'       => $ch['description'] ?: $ch['id'],
                        'timestamp' => $ch['created'],
                    ];
                }
                usort($init_activity, function ($a, $b) { return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''); });
                $init_activity = array_slice($init_activity, 0, 10);
                ?>
                <?php if (empty($init_activity)): ?>
                    <p class="text-muted p-3 mb-0 text-center" style="font-size:.875rem;">No recent activity.</p>
                <?php else: ?>
                <ul class="list-unstyled mb-0" style="max-height:360px;overflow-y:auto;">
                    <?php foreach ($init_activity as $ev): ?>
                    <li class="tp-activity-item">
                        <div class="tp-activity-icon">
                            <i class="fa-solid <?= e($ev['icon']) ?>" style="color:<?= e($ev['color']) ?>;"></i>
                        </div>
                        <div class="tp-activity-body">
                            <div class="tp-activity-title"><?= e($ev['title']) ?></div>
                            <div class="tp-activity-detail"><?= e($ev['detail']) ?></div>
                            <?php if (!empty($ev['sub'])): ?>
                            <div class="tp-activity-time"><?= e($ev['sub']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="tp-activity-time ms-auto"><?= e(fmt_datetime($ev['timestamp'])) ?></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($stripe_available && !empty($stripe_recent_charges)): ?>
    <!-- Recent Stripe Payments -->
    <div class="col-12 col-lg-6">
        <div class="tp-card h-100">
            <div class="tp-card-header">
                <i class="fa-brands fa-stripe me-2 text-or"></i>Recent Stripe Payments
            </div>
            <div class="tp-card-body p-0" id="stripe-recent-charges">
                <div class="table-responsive">
                    <table class="tp-table mb-0">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Description</th>
                                <th class="text-end">Amount</th>
                                <th>Date</th>
                                <th class="text-end">Stripe</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stripe_recent_charges as $ch): ?>
                            <?php $stripe_charge_url = stripe_dashboard_url($ch['id'] ?? ''); ?>
                            <tr>
                                <td><?= e($ch['customer'] ?? '—') ?></td>
                                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= e($ch['description'] ?: $ch['id']) ?>
                                </td>
                                <td class="text-end text-gr fw-semibold">
                                    <?= fmt_money($ch['amount']) ?>
                                </td>
                                <td style="white-space:nowrap;"><?= e(fmt_datetime($ch['created'])) ?></td>
                                <td class="text-end">
                                    <?php if ($stripe_charge_url): ?>
                                    <a href="<?= e($stripe_charge_url) ?>" target="_blank" rel="noopener noreferrer"
                                       class="btn-tp-ghost btn-tp-xs" title="Open payment in Stripe Dashboard">
                                        <i class="fa-brands fa-stripe"></i>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div><!-- /.row activity + stripe -->

<!-- ── Dashboard Auto-Refresh Script ────────────────────────────────────── -->
<script>
(function () {
    'use strict';

    var METRICS_URL  = '<?= e(APP_URL) ?>/api/dashboard-metrics.php';
    var INTERVAL_MS  = 60000; // refresh every 60 seconds
    var refreshTimer = null;

    /**
     * Resolve a dot-path (e.g. "kpis.leads_new") against the data object.
     */
    function resolvePath(obj, path) {
        return path.split('.').reduce(function (cur, key) {
            return cur && cur[key] !== undefined ? cur[key] : null;
        }, obj);
    }

    /**
     * Format a numeric value as "$1,234.56".
     */
    function formatMoney(val) {
        var num = parseFloat(val);
        if (isNaN(num)) return val;
        return '$' + num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /**
     * Update all [data-metric] elements from the fetched payload.
     */
    function applyMetrics(data) {
        document.querySelectorAll('[data-metric]').forEach(function (el) {
            var path  = el.getAttribute('data-metric');
            var value = resolvePath(data, path);
            if (value === null || value === undefined) return;

            var fmt = el.getAttribute('data-metric-format');
            var display = fmt === 'money' ? formatMoney(value) : String(value);

            if (el.textContent.trim() !== display) {
                el.textContent = display;
                // Brief highlight animation to signal the value changed
                el.style.transition = 'color .3s';
                var origColor = el.style.color || '';
                el.style.color = '#f97316';
                setTimeout(function () { el.style.color = origColor; }, 600);
            }
        });
    }

    /**
     * Rebuild the activity feed list from the latest data.
     */
    function refreshActivityFeed(events) {
        var feed = document.getElementById('activity-feed');
        if (!feed || !Array.isArray(events) || events.length === 0) return;

        var html = '<ul class="list-unstyled mb-0" style="max-height:360px;overflow-y:auto;">';
        events.forEach(function (ev) {
            var escapedTitle  = escHtml(ev.title  || '');
            var escapedDetail = escHtml(ev.detail || '');
            var escapedSub    = ev.sub ? escHtml(ev.sub) : '';
            var escapedTs     = escHtml(ev.timestamp || '');
            html += '<li class="tp-activity-item">';
            html += '<div class="tp-activity-icon">';
            html += '<i class="fa-solid ' + escHtml(ev.icon || 'fa-circle') + '" style="color:' + escHtml(ev.color || 'var(--gy)') + ';"></i>';
            html += '</div>';
            html += '<div class="tp-activity-body">';
            html += '<div class="tp-activity-title">' + escapedTitle + '</div>';
            html += '<div class="tp-activity-detail">' + escapedDetail + '</div>';
            if (escapedSub) {
                html += '<div class="tp-activity-time">' + escapedSub + '</div>';
            }
            html += '</div>';
            html += '<div class="tp-activity-time ms-auto">' + escapedTs + '</div>';
            html += '</li>';
        });
        html += '</ul>';
        feed.innerHTML = html;
    }

    /** Minimal HTML escaping for values injected via innerHTML. */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * Show/hide the "Refreshing…" badge.
     */
    function setBadge(visible) {
        var badge = document.getElementById('activity-refresh-badge');
        if (badge) badge.style.display = visible ? '' : 'none';
    }

    /**
     * Update the "last updated" indicator in the page header.
     */
    function setLastUpdated(ts) {
        var el = document.getElementById('dash-last-updated');
        if (!el) return;
        el.innerHTML = '<i class="fa-solid fa-rotate me-1 text-or"></i>Updated ' + escHtml(ts);
    }

    /**
     * Main refresh function — fetch metrics and apply to page.
     */
    function refresh() {
        setBadge(true);
        fetch(METRICS_URL, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                applyMetrics(data);
                if (data.activity) {
                    refreshActivityFeed(data.activity);
                }
                if (data.last_updated) {
                    setLastUpdated(data.last_updated);
                }
            })
            .catch(function () {
                // Silently fail — stale values remain visible.
            })
            .finally(function () {
                setBadge(false);
            });
    }

    // Start the auto-refresh loop once DOM is ready.
    function init() {
        refreshTimer = setInterval(refresh, INTERVAL_MS);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
</script>

<?php layout_end(); ?>
