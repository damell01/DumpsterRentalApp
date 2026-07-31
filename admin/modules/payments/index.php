<?php
/**
 * Payments – Overview
 * All-time revenue totals, filtered payment records, Stripe live data.
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_once INC_PATH . '/stripe.php';
require_login();
require_role('admin', 'office');

// ── Filters ───────────────────────────────────────────────────────────────────
$pay_method = trim($_GET['pay_method'] ?? 'all');   // all|stripe|cash|check
$pay_status = trim($_GET['pay_status'] ?? 'all');   // all|paid|pending|refunded
$date_from  = trim($_GET['date_from']  ?? '');
$date_to    = trim($_GET['date_to']    ?? '');
$source     = trim($_GET['source']     ?? 'all');   // all|booking|invoice

$valid_methods  = ['all', 'stripe', 'ach', 'cash', 'check'];
$valid_statuses = ['all', 'paid', 'pending', 'processing', 'failed', 'refunded'];
$valid_sources  = ['all', 'booking', 'invoice'];

if (!in_array($pay_method, $valid_methods, true))  $pay_method = 'all';
if (!in_array($pay_status, $valid_statuses, true))  $pay_status = 'all';
if (!in_array($source,     $valid_sources, true))   $source     = 'all';

// ── All-time totals by method ─────────────────────────────────────────────────
$db_error = null;
$alltime_stripe = ['total' => 0, 'cnt' => 0];
$alltime_ach    = ['total' => 0, 'cnt' => 0];
$alltime_cash   = ['total' => 0, 'cnt' => 0];
$alltime_check  = ['total' => 0, 'cnt' => 0];
$alltime_inv_stripe = ['total' => 0, 'cnt' => 0];
$alltime_inv_cash   = ['total' => 0, 'cnt' => 0];
$alltime_inv_check  = ['total' => 0, 'cnt' => 0];
$mtd_booking_rev = ['total' => 0, 'cnt' => 0];
$mtd_invoice_rev = ['total' => 0, 'cnt' => 0];
$booking_records = [];
$inv_records     = [];
$month_start     = date('Y-m-01');

try {
$alltime_stripe = db_fetch(
    "SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
     FROM bookings
     WHERE payment_method = 'stripe'
       AND payment_status = 'paid'
       AND booking_status != 'canceled'"
) ?: ['total' => 0, 'cnt' => 0];

$alltime_ach = db_fetch(
    "SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
     FROM bookings
     WHERE payment_method = 'ach'
       AND payment_status IN ('paid','processing')
       AND booking_status != 'canceled'"
) ?: ['total' => 0, 'cnt' => 0];

$alltime_cash = db_fetch(
    "SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
     FROM bookings
     WHERE payment_status = 'paid_cash'
       AND booking_status != 'canceled'"
) ?: ['total' => 0, 'cnt' => 0];

$alltime_check = db_fetch(
    "SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
     FROM bookings
     WHERE payment_status = 'paid_check'
       AND booking_status != 'canceled'"
) ?: ['total' => 0, 'cnt' => 0];

$alltime_inv_stripe = db_fetch(
    "SELECT COALESCE(SUM(total),0) AS total, COUNT(*) AS cnt
     FROM invoices
     WHERE payment_method = 'stripe' AND status = 'paid'"
) ?: ['total' => 0, 'cnt' => 0];

$alltime_inv_cash = db_fetch(
    "SELECT COALESCE(SUM(total),0) AS total, COUNT(*) AS cnt
     FROM invoices
     WHERE payment_method = 'cash' AND status = 'paid'"
) ?: ['total' => 0, 'cnt' => 0];

$alltime_inv_check = db_fetch(
    "SELECT COALESCE(SUM(total),0) AS total, COUNT(*) AS cnt
     FROM invoices
     WHERE payment_method = 'check' AND status = 'paid'"
) ?: ['total' => 0, 'cnt' => 0];

$alltime_total_stripe = (float)$alltime_stripe['total'] + (float)$alltime_inv_stripe['total'];
$alltime_total_ach    = (float)$alltime_ach['total'];
$alltime_total_cash   = (float)$alltime_cash['total']   + (float)$alltime_inv_cash['total'];
$alltime_total_check  = (float)$alltime_check['total']  + (float)$alltime_inv_check['total'];
$alltime_grand_total  = $alltime_total_stripe + $alltime_total_ach + $alltime_total_cash + $alltime_total_check;

// ── Month-to-date totals ──────────────────────────────────────────────────────
$mtd_booking_rev = db_fetch(
    "SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
     FROM bookings
     WHERE payment_status IN ('paid','paid_cash','paid_check')
       AND booking_status != 'canceled'
       AND updated_at >= ?",
    [$month_start . ' 00:00:00']
) ?: ['total' => 0, 'cnt' => 0];

$mtd_invoice_rev = db_fetch(
    "SELECT COALESCE(SUM(total),0) AS total, COUNT(*) AS cnt
     FROM invoices
     WHERE status = 'paid'
       AND updated_at >= ?",
    [$month_start . ' 00:00:00']
) ?: ['total' => 0, 'cnt' => 0];

$mtd_total = (float)$mtd_booking_rev['total'] + (float)$mtd_invoice_rev['total'];

// ── Build filtered payment records ───────────────────────────────────────────
// Booking payments
$b_where   = ['b.booking_status != ?'];
$b_params  = ['canceled'];

    if ($pay_method !== 'all') {
    if ($pay_method === 'cash') {
        $b_where[] = "b.payment_status IN ('pending_cash','paid_cash')";
    } elseif ($pay_method === 'check') {
        $b_where[] = "b.payment_status IN ('pending_check','paid_check')";
    } elseif ($pay_method === 'ach') {
        $b_where[] = "b.payment_method = 'ach'";
    } else {
        $b_where[] = "b.payment_method = ?";
        $b_params[] = $pay_method;
    }
}

if ($pay_status !== 'all') {
    if ($pay_status === 'paid') {
        $b_where[] = "b.payment_status IN ('paid','paid_cash','paid_check')";
    } elseif ($pay_status === 'pending') {
        $b_where[] = "b.payment_status IN ('pending','pending_cash','pending_check','unpaid')";
    } elseif ($pay_status === 'processing') {
        $b_where[] = "b.payment_status = 'processing'";
    } elseif ($pay_status === 'failed') {
        $b_where[] = "b.payment_status = 'failed'";
    } elseif ($pay_status === 'refunded') {
        $b_where[] = "b.payment_status = 'refunded'";
    }
}

if ($date_from !== '') {
    $b_where[] = "b.updated_at >= ?";
    $b_params[] = $date_from . ' 00:00:00';
}
if ($date_to !== '') {
    $b_where[] = "b.updated_at <= ?";
    $b_params[] = $date_to . ' 23:59:59';
}

if ($source === 'all' || $source === 'booking') {
    $b_where_sql = $b_where ? ('WHERE ' . implode(' AND ', $b_where)) : '';
    $booking_records = db_fetchall(
        "SELECT b.id, b.booking_number AS ref_number, b.customer_name, b.total_amount AS amount,
                b.payment_method, b.payment_status, b.stripe_payment_id, b.stripe_session_id,
                COALESCE(b.payment_notes,'') AS payment_notes, b.updated_at, 'booking' AS source_type
         FROM bookings b
         $b_where_sql
         ORDER BY b.updated_at DESC
         LIMIT 200",
        $b_params
    ) ?: [];
}

// Invoice payments
if ($source === 'all' || $source === 'invoice') {
    $inv_where  = ['1=1'];
    $inv_params = [];

    if ($pay_method !== 'all') {
        if ($pay_method === 'stripe') {
            $inv_where[] = "i.payment_method = 'stripe'";
        } elseif ($pay_method === 'cash') {
            $inv_where[] = "i.payment_method = 'cash'";
        } elseif ($pay_method === 'check') {
            $inv_where[] = "i.payment_method = 'check'";
        }
    }
    if ($pay_status !== 'all') {
        if ($pay_status === 'paid') {
            $inv_where[] = "i.status = 'paid'";
        } elseif ($pay_status === 'pending') {
            // Only show sent invoices that have an active Stripe payment link/session as "pending"
            // Draft invoices with no payment attempt should NOT appear in payment records
            $inv_where[] = "i.status = 'sent' AND (i.stripe_session_id IS NOT NULL OR i.stripe_payment_link IS NOT NULL)";
        } elseif ($pay_status === 'refunded') {
            $inv_where[] = "i.status = 'void'";
        }
    } else {
        // Default "all" view: only show invoices with actual payment activity
        // (paid, or sent with a stripe session/link, or voided)
        $inv_where[] = "(i.status = 'paid' OR i.status = 'void' OR (i.status = 'sent' AND (i.stripe_session_id IS NOT NULL OR i.stripe_payment_link IS NOT NULL)))";
    }
    if ($date_from !== '') {
        $inv_where[] = "i.updated_at >= ?";
        $inv_params[] = $date_from . ' 00:00:00';
    }
    if ($date_to !== '') {
        $inv_where[] = "i.updated_at <= ?";
        $inv_params[] = $date_to . ' 23:59:59';
    }

    $inv_where_sql = 'WHERE ' . implode(' AND ', $inv_where);
    $inv_records = db_fetchall(
        "SELECT i.id, i.invoice_number AS ref_number, i.cust_name AS customer_name, i.total AS amount,
                COALESCE(i.payment_method,'stripe') AS payment_method,
                i.status AS payment_status, NULL AS stripe_payment_id,
                COALESCE(i.stripe_session_id,'') AS stripe_session_id,
                COALESCE(i.payment_notes,'') AS payment_notes, i.updated_at, 'invoice' AS source_type
         FROM invoices i
         $inv_where_sql
         ORDER BY i.updated_at DESC
         LIMIT 200",
        $inv_params
    ) ?: [];
}

} catch (\Throwable $dbErr) {
    $db_error = 'Database error: ' . $dbErr->getMessage() . '. Some data may be missing. Run the upgrade script to ensure all columns exist.';
    error_log('[Payments] DB error: ' . $dbErr->getMessage());
}

// Merge and sort
$all_records = array_merge($booking_records, $inv_records);
usort($all_records, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
$all_records = array_slice($all_records, 0, 100);

// ── Stripe live data ──────────────────────────────────────────────────────────
$stripe_balance    = null;
$stripe_payouts    = [];
$stripe_charges    = [];
$stripe_error      = null;
$stripe_configured = trim(get_setting('stripe_secret_key', '')) !== '';
$stripe_sdk_ready  = stripe_sdk_available();

if ($stripe_configured && $stripe_sdk_ready) {
    try {
        $stripe_balance = stripe_get_balance();

        $payout_list    = stripe_list_payouts(20);
        $stripe_payouts = $payout_list->data ?? [];

        $charge_list    = stripe_list_charges(50, strtotime($month_start));
        $stripe_charges = $charge_list->data ?? [];
    } catch (\Throwable $e) {
        $stripe_error = $e->getMessage();
    }
} elseif ($stripe_configured && !$stripe_sdk_ready) {
    $stripe_error = 'Stripe PHP SDK not installed on this server.';
}

$stripe_month_revenue = 0.0;
$stripe_failed_count  = 0;
foreach ($stripe_charges as $ch) {
    if ($ch->status === 'succeeded' && !$ch->refunded) {
        $stripe_month_revenue += $ch->amount / 100;
    } elseif ($ch->status === 'failed') {
        $stripe_failed_count++;
    }
}

layout_start('Payments', 'payments');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Payments &amp; Revenue</h5>
        <small class="text-muted">Month to date: <?= e(date('F Y')) ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($stripe_configured): ?>
        <a href="<?= e('https://dashboard.stripe.com' . (stripe_is_test_mode() ? '/test' : '') . '/payments') ?>"
           target="_blank" rel="noopener noreferrer" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-brands fa-stripe"></i> Stripe Payments
        </a>
        <a href="<?= e('https://dashboard.stripe.com' . (stripe_is_test_mode() ? '/test' : '') . '/payouts') ?>"
           target="_blank" rel="noopener noreferrer" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-brands fa-stripe"></i> Stripe Payouts
        </a>
        <?php endif; ?>
    </div>
</div>

<?php render_flash(); ?>

<?php if (!empty($db_error)): ?>
<div class="alert alert-danger" style="font-size:.88rem;">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    <strong>Database error:</strong> <?= e($db_error) ?>
</div>
<?php endif; ?>

<?php if ($stripe_error): ?>
<div class="alert alert-warning" style="font-size:.88rem;">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    <strong>Stripe data unavailable</strong> — Showing local database totals only.<br>
    <span style="opacity:.85;"><?= e($stripe_error) ?></span>
    <?php if (!$stripe_sdk_ready): ?>
    <br><small>To enable live Stripe data, run <code>composer install</code> inside the <code>/admin</code> directory on your server.</small>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── All-time totals ── -->
<div class="tp-card mb-4">
    <div class="tp-card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-chart-line me-2 text-muted"></i> All-Time Revenue Totals</span>
    </div>
    <div class="tp-card-body">
        <div class="kpi-row mb-0">
            <div class="kpi-card">
                <div class="kpi-label">Grand Total</div>
                <div class="kpi-value" style="color:var(--or);"><?= e(fmt_money($alltime_grand_total)) ?></div>
                <div class="kpi-sub">All methods</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label"><i class="fa-brands fa-stripe me-1" style="color:#635bff;"></i> Stripe</div>
                <div class="kpi-value"><?= e(fmt_money($alltime_total_stripe)) ?></div>
                <div class="kpi-sub"><?= (int)$alltime_stripe['cnt'] + (int)$alltime_inv_stripe['cnt'] ?> records</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label"><i class="fa-solid fa-building-columns me-1" style="color:#14b8a6;"></i> ACH</div>
                <div class="kpi-value"><?= e(fmt_money($alltime_total_ach)) ?></div>
                <div class="kpi-sub"><?= (int)$alltime_ach['cnt'] ?> records</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label"><i class="fa-solid fa-money-bill me-1" style="color:#28a745;"></i> Cash</div>
                <div class="kpi-value"><?= e(fmt_money($alltime_total_cash)) ?></div>
                <div class="kpi-sub"><?= (int)$alltime_cash['cnt'] + (int)$alltime_inv_cash['cnt'] ?> records</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label"><i class="fa-solid fa-check me-1" style="color:#0d6efd;"></i> Check</div>
                <div class="kpi-value"><?= e(fmt_money($alltime_total_check)) ?></div>
                <div class="kpi-sub"><?= (int)$alltime_check['cnt'] + (int)$alltime_inv_check['cnt'] ?> records</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">This Month</div>
                <div class="kpi-value"><?= e(fmt_money($mtd_total)) ?></div>
                <div class="kpi-sub"><?= e(date('M Y')) ?> — bookings + invoices</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Stripe Balance ── -->
<?php if ($stripe_configured && $stripe_balance && !$stripe_error): ?>
<div class="tp-card mb-4">
    <div class="tp-card-header">
        <i class="fa-brands fa-stripe me-2 text-muted"></i> Stripe Account Balance
        <?php if (stripe_is_test_mode()): ?>
        <span class="tp-badge badge-scheduled ms-2" style="font-size:.7rem;">TEST MODE</span>
        <?php endif; ?>
    </div>
    <div class="tp-card-body">
        <div class="row g-3">
            <?php
            $balance_map = [];
            foreach ($stripe_balance->available as $entry) {
                $cur = strtoupper($entry->currency);
                $balance_map[$cur]['available'] = ($balance_map[$cur]['available'] ?? 0) + $entry->amount;
            }
            foreach ($stripe_balance->pending as $entry) {
                $cur = strtoupper($entry->currency);
                $balance_map[$cur]['pending'] = ($balance_map[$cur]['pending'] ?? 0) + $entry->amount;
            }
            foreach ($balance_map as $cur => $bal):
                $avail   = ($bal['available'] ?? 0) / 100;
                $pending = ($bal['pending'] ?? 0) / 100;
            ?>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:.8rem;">Available (<?= e($cur) ?>)</div>
                <div class="fw-bold" style="font-size:1.2rem;color:#28a745;"><?= e(fmt_money($avail)) ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:.8rem;">Pending (<?= e($cur) ?>)</div>
                <div class="fw-bold" style="font-size:1.2rem;color:#fd7e14;"><?= e(fmt_money($pending)) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Filters ── -->
<div class="filter-bar tp-filter-form-compact">
    <!-- Date quick-selectors -->
    <div class="tp-date-qs">
        <span style="font-size:.72rem;color:var(--gy);font-family:var(--font-cond);letter-spacing:.06em;text-transform:uppercase;margin-right:.25rem;">Quick:</span>
        <?php
        $today_str      = date('Y-m-d');
        $week_start     = date('Y-m-d', strtotime('monday this week'));
        $month_start_s  = date('Y-m-01');
        $month_end_s    = date('Y-m-t');

        $is_today  = ($date_from === $today_str && $date_to === $today_str);
        $is_week   = ($date_from === $week_start && $date_to === $today_str);
        $is_month  = ($date_from === $month_start_s && $date_to === $month_end_s);
        $is_all    = ($date_from === '' && $date_to === '');

        // Preserve method/status/source filters in quick-date links
        $qs_base = array_filter([
            'pay_method' => $pay_method !== 'all' ? $pay_method : '',
            'pay_status' => $pay_status !== 'all' ? $pay_status : '',
            'source'     => $source !== 'all' ? $source : '',
        ]);
        $all_href   = 'index.php' . ($qs_base ? '?' . http_build_query($qs_base) : '');
        $today_href = 'index.php?' . http_build_query(array_merge($qs_base, ['date_from' => $today_str,    'date_to' => $today_str]));
        $week_href  = 'index.php?' . http_build_query(array_merge($qs_base, ['date_from' => $week_start,   'date_to' => $today_str]));
        $month_href = 'index.php?' . http_build_query(array_merge($qs_base, ['date_from' => $month_start_s,'date_to' => $month_end_s]));
        ?>
        <a href="<?= e($all_href) ?>"   class="<?= $is_all   ? 'active' : '' ?>">All Time</a>
        <a href="<?= e($today_href) ?>" class="<?= $is_today ? 'active' : '' ?>">Today</a>
        <a href="<?= e($week_href) ?>"  class="<?= $is_week  ? 'active' : '' ?>">This Week</a>
        <a href="<?= e($month_href) ?>" class="<?= $is_month ? 'active' : '' ?>">This Month</a>
    </div>

    <form method="GET" action="index.php" class="tp-filter-grid-compact">
        <div>
            <label class="form-label" style="font-size:.8rem;">Method</label>
            <select name="pay_method" class="form-select form-select-sm">
                <?php foreach (['all' => 'All Methods', 'stripe' => 'Card', 'ach' => 'ACH', 'cash' => 'Cash', 'check' => 'Check'] as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= $pay_method === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" style="font-size:.8rem;">Status</label>
            <select name="pay_status" class="form-select form-select-sm">
                <?php foreach (['all' => 'All Statuses', 'paid' => 'Paid', 'pending' => 'Pending', 'processing' => 'Processing', 'failed' => 'Failed', 'refunded' => 'Refunded/Void'] as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= $pay_status === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" style="font-size:.8rem;">Source</label>
            <select name="source" class="form-select form-select-sm">
                <?php foreach (['all' => 'All Sources', 'booking' => 'Bookings', 'invoice' => 'Invoices'] as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= $source === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" style="font-size:.8rem;">From</label>
            <input type="date" name="date_from" class="form-control form-control-sm"
                   value="<?= e($date_from) ?>">
        </div>
        <div>
            <label class="form-label" style="font-size:.8rem;">To</label>
            <input type="date" name="date_to" class="form-control form-control-sm"
                   value="<?= e($date_to) ?>">
        </div>
        <div class="tp-filter-actions-compact">
            <button type="submit" class="btn-tp-primary btn-tp-sm flex-fill">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <a href="index.php" class="btn-tp-ghost btn-tp-sm" title="Clear filters">
                <i class="fa-solid fa-xmark"></i> Clear
            </a>
        </div>
    </form>
</div>

<!-- ── Payment Records Table ── -->
<div class="tp-card mb-4">
    <div class="tp-card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-list me-2 text-muted"></i> Payment Records</span>
        <small class="text-muted"><?= count($all_records) ?> records shown (most recent 100 combined)</small>
    </div>
    <div class="tp-card-body p-0">
        <?php if (empty($all_records)): ?>
        <p class="text-muted p-3 mb-0">No payment records match the current filters.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table tp-table mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Source</th>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th class="text-end">Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Note</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all_records as $row):
                    $is_booking = $row['source_type'] === 'booking';
                    $view_url   = $is_booking
                        ? '../bookings/view.php?id=' . (int)$row['id']
                        : '../invoices/view.php?id='  . (int)$row['id'];
                    $stripe_id  = $row['stripe_payment_id'] ?: $row['stripe_session_id'];
                    $stripe_url = stripe_dashboard_url($stripe_id);
                    $method_icon = match($row['payment_method']) {
                        'ach'   => '<i class="fa-solid fa-building-columns" title="ACH" style="color:#14b8a6;"></i>',
                        'cash'  => '<i class="fa-solid fa-money-bill" title="Cash" style="color:#28a745;"></i>',
                        'check' => '<i class="fa-solid fa-check-square" title="Check" style="color:#0d6efd;"></i>',
                        default => '<i class="fa-brands fa-stripe" title="Stripe" style="color:#635bff;"></i>',
                    };
                    $pay_status_display = $row['payment_status'];
                    // normalize invoice statuses for display
                    if ($row['source_type'] === 'invoice') {
                        $pay_status_display = match($row['payment_status']) {
                            'paid'  => 'paid',
                            'void'  => 'refunded',
                            'draft','sent' => 'pending',
                            default => $row['payment_status'],
                        };
                    }
                ?>
                <tr>
                    <td class="text-nowrap"><?= e(fmt_date($row['updated_at'])) ?></td>
                    <td>
                        <span class="tp-badge" style="font-size:.7rem;text-transform:capitalize;">
                            <?= e($row['source_type']) ?>
                        </span>
                    </td>
                    <td>
                        <a href="<?= e($view_url) ?>"><?= e($row['ref_number']) ?></a>
                    </td>
                    <td><?= e($row['customer_name']) ?></td>
                    <td class="text-end fw-semibold"><?= e(fmt_money($row['amount'])) ?></td>
                    <td><?= $method_icon ?> <?= e(payment_method_label($row['payment_method'])) ?></td>
                    <td><?= payment_badge($pay_status_display) ?></td>
                    <td class="text-muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= e($row['payment_notes'] ?? '') ?>">
                        <?= e(mb_strimwidth($row['payment_notes'] ?? '', 0, 30, '…')) ?>
                    </td>
                    <td class="text-nowrap">
                        <?php if ($stripe_url): ?>
                        <a href="<?= e($stripe_url) ?>" target="_blank" rel="noopener noreferrer"
                           class="text-muted" title="View in Stripe" style="font-size:.8rem;">
                            <i class="fa-brands fa-stripe"></i>
                        </a>
                        <?php endif; ?>
                        <?php if ($is_booking && $row['payment_status'] === 'paid' && $row['payment_method'] === 'stripe'): ?>
                        <a href="../bookings/refund.php?id=<?= (int)$row['id'] ?>"
                           class="ms-1 text-danger" title="Refund" style="font-size:.8rem;">
                            <i class="fa-solid fa-rotate-left"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Stripe Charges this month ── -->
<?php if ($stripe_configured && !$stripe_error && !empty($stripe_charges)): ?>
<div class="row g-4">
    <div class="col-12 col-xl-7">
        <div class="tp-card h-100">
            <div class="tp-card-header">
                <i class="fa-solid fa-credit-card me-2 text-muted"></i>
                Recent Stripe Charges <small class="text-muted">(this month)</small>
                <small class="ms-2 text-muted"><?= e(fmt_money($stripe_month_revenue)) ?> total</small>
            </div>
            <div class="tp-card-body p-0">
                <div class="table-responsive">
                    <table class="table tp-table mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Description</th>
                                <th class="text-end">Amount</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stripe_charges as $ch):
                            $ch_date   = date('M j g:i A', $ch->created);
                            $ch_name   = $ch->billing_details->name ?? $ch->metadata['customer_name'] ?? '—';
                            $ch_desc   = $ch->description ?? $ch->metadata['booking_number'] ?? '';
                            $ch_amount = $ch->amount / 100;
                            $ch_status = $ch->refunded ? 'refunded' : $ch->status;
                            $ch_url    = stripe_dashboard_url($ch->id);
                        ?>
                        <tr>
                            <td class="text-nowrap"><?= e($ch_date) ?></td>
                            <td><?= e($ch_name) ?></td>
                            <td class="text-muted"><?= e($ch_desc) ?></td>
                            <td class="text-end fw-semibold"><?= e(fmt_money($ch_amount)) ?></td>
                            <td>
                                <span class="charge-status-<?= e($ch_status) ?> fw-semibold">
                                    <?= e(ucfirst($ch_status)) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($ch_url): ?>
                                <a href="<?= e($ch_url) ?>" target="_blank" rel="noopener noreferrer"
                                   class="text-muted" title="View in Stripe" style="font-size:.75rem;">
                                    <i class="fa-brands fa-stripe"></i>
                                </a>
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

    <!-- Payouts -->
    <div class="col-12 col-xl-5">
        <div class="tp-card h-100">
            <div class="tp-card-header">
                <i class="fa-solid fa-building-columns me-2 text-muted"></i> Recent Payouts to Bank
            </div>
            <div class="tp-card-body p-0">
                <?php if (empty($stripe_payouts)): ?>
                <p class="text-muted p-3 mb-0">No payouts found.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table tp-table mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th class="text-end">Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stripe_payouts as $payout):
                            $p_date   = date('M j, Y', $payout->arrival_date ?? $payout->created);
                            $p_desc   = $payout->description ?: 'Automatic payout';
                            $p_amount = $payout->amount / 100;
                            $p_status = $payout->status;
                            $p_colors = ['paid'=>'#28a745','in_transit'=>'#fd7e14','pending'=>'#6c757d','canceled'=>'#dc3545','failed'=>'#dc3545'];
                            $p_color  = $p_colors[$p_status] ?? '#222';
                        ?>
                        <tr>
                            <td class="text-nowrap"><?= e($p_date) ?></td>
                            <td class="text-muted"><?= e($p_desc) ?></td>
                            <td class="text-end fw-semibold"><?= e(fmt_money($p_amount)) ?></td>
                            <td><span style="color:<?= e($p_color) ?>;font-weight:600;"><?= e(ucfirst(str_replace('_',' ',$p_status))) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$stripe_configured): ?>
<div class="alert alert-info mt-4" style="font-size:.88rem;">
    <i class="fa-solid fa-circle-info me-1"></i>
    <strong>Stripe not configured.</strong>
    To enable live balance, payout, and charge data, add your
    <a href="../settings/index.php">Stripe API keys in Settings</a>.
</div>
<?php endif; ?>

<?php
layout_end();
