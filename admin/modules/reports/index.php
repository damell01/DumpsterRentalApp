<?php
/**
 * Reports – Revenue & Payments
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();

// ── Filters ───────────────────────────────────────────────────────────────────
$date_from  = trim($_GET['date_from']  ?? '');
$date_to    = trim($_GET['date_to']    ?? '');
$pay_method = trim($_GET['pay_method'] ?? 'all');
$pay_status = trim($_GET['pay_status'] ?? 'all');
$all_time   = isset($_GET['all_time']) && $_GET['all_time'] === '1';

$valid_methods  = ['all', 'stripe', 'ach', 'cash', 'check'];
$valid_statuses = ['all', 'paid', 'pending'];
if (!in_array($pay_method, $valid_methods, true))  $pay_method = 'all';
if (!in_array($pay_status, $valid_statuses, true)) $pay_status = 'all';

if ($all_time) {
    $date_from = '2000-01-01';
    $date_to   = date('Y-m-d');
} else {
    if ($date_from === '' || !strtotime($date_from)) $date_from = date('Y-m-01');
    if ($date_to   === '' || !strtotime($date_to))   $date_to   = date('Y-m-d');
    $date_from = date('Y-m-d', strtotime($date_from));
    $date_to   = date('Y-m-d', strtotime($date_to));
}
$dt_from = $date_from . ' 00:00:00';
$dt_to   = $date_to   . ' 23:59:59';

// ── Resolve booking payment_status array from filters ─────────────────────────
function booking_status_filter(string $method, string $status): array
{
    $paid_all    = ['paid','paid_cash','paid_check'];
    $pending_all = ['pending','pending_cash','pending_check','unpaid'];

    if ($method === 'stripe') {
        if ($status === 'paid')    return ['paid'];
        if ($status === 'pending') return ['pending','unpaid'];
        return array_merge(['paid'], ['pending','unpaid']);
    }
    if ($method === 'ach') {
        if ($status === 'paid')    return ['paid'];
        if ($status === 'pending') return ['pending','processing','failed'];
        return ['paid','pending','processing','failed','refunded'];
    }
    if ($method === 'cash') {
        if ($status === 'paid')    return ['paid_cash'];
        if ($status === 'pending') return ['pending_cash'];
        return ['paid_cash','pending_cash'];
    }
    if ($method === 'check') {
        if ($status === 'paid')    return ['paid_check'];
        if ($status === 'pending') return ['pending_check'];
        return ['paid_check','pending_check'];
    }
    // all methods
    if ($status === 'paid')    return $paid_all;
    if ($status === 'pending') return $pending_all;
    return array_merge($paid_all, $pending_all);
}

$bk_statuses = booking_status_filter($pay_method, $pay_status);
$bk_ph       = implode(',', array_fill(0, count($bk_statuses), '?'));

// ── All-time booking totals by payment method ─────────────────────────────────
$all_time_stripe  = 0.0;
$all_time_ach     = 0.0;
$all_time_cash    = 0.0;
$all_time_check   = 0.0;
$all_time_pending = 0.0;
try {
    $r = db_fetch(
        "SELECT
            COALESCE(SUM(CASE WHEN payment_status = 'paid'       THEN total_amount ELSE 0 END),0) AS stripe_total,
            COALESCE(SUM(CASE WHEN payment_method = 'ach' AND payment_status IN ('paid','processing','refunded') THEN total_amount ELSE 0 END),0) AS ach_total,
            COALESCE(SUM(CASE WHEN payment_status = 'paid_cash'  THEN total_amount ELSE 0 END),0) AS cash_total,
            COALESCE(SUM(CASE WHEN payment_status = 'paid_check' THEN total_amount ELSE 0 END),0) AS check_total,
            COALESCE(SUM(CASE WHEN payment_status IN ('pending','pending_cash','pending_check','unpaid') THEN total_amount ELSE 0 END),0) AS pending_total
         FROM bookings WHERE booking_status != 'canceled'"
    );
    $all_time_stripe  = (float)($r['stripe_total']  ?? 0);
    $all_time_ach     = (float)($r['ach_total']     ?? 0);
    $all_time_cash    = (float)($r['cash_total']     ?? 0);
    $all_time_check   = (float)($r['check_total']    ?? 0);
    $all_time_pending = (float)($r['pending_total']  ?? 0);
} catch (\Throwable $e) {}

// ── Period booking revenue by method ─────────────────────────────────────────
$period_stripe = 0.0;
$period_ach    = 0.0;
$period_cash   = 0.0;
$period_check  = 0.0;
try {
    $pr = db_fetch(
        "SELECT
            COALESCE(SUM(CASE WHEN payment_status = 'paid'       THEN total_amount ELSE 0 END),0) AS stripe_total,
            COALESCE(SUM(CASE WHEN payment_method = 'ach' AND payment_status IN ('paid','processing') THEN total_amount ELSE 0 END),0) AS ach_total,
            COALESCE(SUM(CASE WHEN payment_status = 'paid_cash'  THEN total_amount ELSE 0 END),0) AS cash_total,
            COALESCE(SUM(CASE WHEN payment_status = 'paid_check' THEN total_amount ELSE 0 END),0) AS check_total
         FROM bookings
         WHERE booking_status != 'canceled' AND updated_at BETWEEN ? AND ?",
        [$dt_from, $dt_to]
    );
    $period_stripe = (float)($pr['stripe_total'] ?? 0);
    $period_ach    = (float)($pr['ach_total']    ?? 0);
    $period_cash   = (float)($pr['cash_total']   ?? 0);
    $period_check  = (float)($pr['check_total']  ?? 0);
} catch (\Throwable $e) {}
$period_booking = $period_stripe + $period_ach + $period_cash + $period_check;

$inv_period = 0.0;
try {
    $ir = db_fetch(
        "SELECT COALESCE(SUM(total),0) AS total FROM invoices WHERE status='paid' AND updated_at BETWEEN ? AND ?",
        [$dt_from, $dt_to]
    );
    $inv_period = (float)($ir['total'] ?? 0);
} catch (\Throwable $e) {}

$wo_period = (float)(db_fetch(
    "SELECT COALESCE(SUM(amount),0) AS total FROM work_orders WHERE status='completed' AND updated_at BETWEEN ? AND ?",
    [$dt_from, $dt_to]
)['total'] ?? 0);

$grand_total = $period_booking + $inv_period + $wo_period;

// ── Filtered booking rows ─────────────────────────────────────────────────────
$filtered_bookings = [];
try {
    $bk_params = [$dt_from, $dt_to];
    $bk_where  = ["b.booking_status != 'canceled'", "b.updated_at BETWEEN ? AND ?",
                  "b.payment_status IN ($bk_ph)"];
    $bk_params = array_merge($bk_params, $bk_statuses);
    $filtered_bookings = db_fetchall(
        "SELECT b.id, b.booking_number, b.customer_name, b.customer_email,
                b.rental_start, b.rental_end, b.total_amount,
                b.payment_method, b.payment_status, b.booking_status, b.updated_at
         FROM bookings b
         WHERE " . implode(' AND ', $bk_where) . "
         ORDER BY b.updated_at DESC LIMIT 200",
        $bk_params
    );
} catch (\Throwable $e) {}

// ── Work Orders by Status ─────────────────────────────────────────────────────
$wo_status_rows = db_fetchall(
    "SELECT status, COUNT(*) AS cnt FROM work_orders WHERE created_at BETWEEN ? AND ?
     GROUP BY status ORDER BY FIELD(status,'scheduled','delivered','active','pickup_requested','picked_up','completed','canceled')",
    [$dt_from, $dt_to]
);

// ── Monthly revenue bar chart (last 6 months) ─────────────────────────────────
$monthly_revenue = [];
try {
    $monthly_revenue = db_fetchall(
        "SELECT DATE_FORMAT(updated_at,'%Y-%m') AS month,
                COALESCE(SUM(CASE WHEN payment_status='paid'       THEN total_amount ELSE 0 END),0) AS stripe,
                COALESCE(SUM(CASE WHEN payment_status='paid_cash'  THEN total_amount ELSE 0 END),0) AS cash,
                COALESCE(SUM(CASE WHEN payment_status='paid_check' THEN total_amount ELSE 0 END),0) AS chk
         FROM bookings WHERE booking_status!='canceled' AND updated_at >= DATE_SUB(NOW(),INTERVAL 6 MONTH)
         GROUP BY month ORDER BY month ASC"
    );
} catch (\Throwable $e) {}
$max_bar = 0;
foreach ($monthly_revenue as $mr) {
    $t = (float)$mr['stripe'] + (float)$mr['cash'] + (float)$mr['chk'];
    if ($t > $max_bar) $max_bar = $t;
}

layout_start('Reports', 'reports');
?>

<!-- Filter Bar -->
<div class="tp-card mb-4">
    <form method="GET" action="index.php" class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label mb-1" for="date_from">From</label>
            <input type="date" id="date_from" name="date_from" class="form-control form-control-sm"
                   value="<?= e($all_time ? '' : $date_from) ?>" <?= $all_time ? 'disabled' : '' ?>>
        </div>
        <div class="col-auto">
            <label class="form-label mb-1" for="date_to">To</label>
            <input type="date" id="date_to" name="date_to" class="form-control form-control-sm"
                   value="<?= e($all_time ? '' : $date_to) ?>" <?= $all_time ? 'disabled' : '' ?>>
        </div>
        <div class="col-auto">
            <label class="form-label mb-1" for="pay_method">Payment Method</label>
            <select id="pay_method" name="pay_method" class="form-select form-select-sm">
                <option value="all"    <?= $pay_method==='all'    ? 'selected':'' ?>>All Methods</option>
                <option value="stripe" <?= $pay_method==='stripe' ? 'selected':'' ?>>Card</option>
                <option value="ach"    <?= $pay_method==='ach' ? 'selected':'' ?>>ACH</option>
                <option value="cash"   <?= $pay_method==='cash'   ? 'selected':'' ?>>Cash</option>
                <option value="check"  <?= $pay_method==='check'  ? 'selected':'' ?>>Check</option>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label mb-1" for="pay_status">Status</label>
            <select id="pay_status" name="pay_status" class="form-select form-select-sm">
                <option value="all"     <?= $pay_status==='all'     ? 'selected':'' ?>>All Statuses</option>
                <option value="paid"    <?= $pay_status==='paid'    ? 'selected':'' ?>>Paid</option>
                <option value="pending" <?= $pay_status==='pending' ? 'selected':'' ?>>Pending</option>
            </select>
        </div>
        <div class="col-auto">
            <div class="form-check mt-3 mb-1">
                <input type="checkbox" id="all_time" name="all_time" value="1" class="form-check-input"
                       <?= $all_time ? 'checked' : '' ?> onchange="this.form.submit()">
                <label for="all_time" class="form-check-label" style="font-size:.85rem;">All Time</label>
            </div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn-tp-primary btn-tp-sm">
                <i class="fa-solid fa-magnifying-glass"></i> Filter
            </button>
            <a href="index.php" class="btn-tp-ghost btn-tp-sm ms-1">Reset</a>
        </div>
    </form>
</div>

<!-- All-Time Totals -->
<h6 class="section-heading mb-2">
    <i class="fa-solid fa-infinity me-1"></i> All-Time Revenue (Bookings)
</h6>
<div class="kpi-row mb-4">
    <div class="kpi-card" style="border-left:4px solid #16a34a;">
        <div class="kpi-label">Total All-Time Paid</div>
        <div class="kpi-value"><?= e(fmt_money($all_time_stripe + $all_time_ach + $all_time_cash + $all_time_check)) ?></div>
        <div class="kpi-sub">All paid bookings</div>
    </div>
    <div class="kpi-card" style="border-left:4px solid #6366f1;">
        <div class="kpi-label">Stripe — All Time</div>
        <div class="kpi-value"><?= e(fmt_money($all_time_stripe)) ?></div>
        <div class="kpi-sub">Card payments</div>
    </div>
    <div class="kpi-card" style="border-left:4px solid #14b8a6;">
        <div class="kpi-label">ACH — All Time</div>
        <div class="kpi-value"><?= e(fmt_money($all_time_ach)) ?></div>
        <div class="kpi-sub">Bank payments</div>
    </div>
    <div class="kpi-card" style="border-left:4px solid #16a34a;">
        <div class="kpi-label">Cash — All Time</div>
        <div class="kpi-value"><?= e(fmt_money($all_time_cash)) ?></div>
        <div class="kpi-sub">Cash payments</div>
    </div>
    <div class="kpi-card" style="border-left:4px solid #d97706;">
        <div class="kpi-label">Check — All Time</div>
        <div class="kpi-value"><?= e(fmt_money($all_time_check)) ?></div>
        <div class="kpi-sub">Check payments</div>
    </div>
    <div class="kpi-card" style="border-left:4px solid #ef4444;">
        <div class="kpi-label">Pending — All Time</div>
        <div class="kpi-value"><?= e(fmt_money($all_time_pending)) ?></div>
        <div class="kpi-sub">Awaiting payment</div>
    </div>
</div>

<!-- Period Revenue Summary -->
<h6 class="section-heading mb-2">
    <i class="fa-solid fa-dollar-sign me-1"></i> Revenue Summary
    <small class="text-muted ms-1">(<?= $all_time ? 'All Time' : e(fmt_date($date_from)).' – '.e(fmt_date($date_to)) ?>)</small>
</h6>
<div class="kpi-row mb-4">
    <div class="kpi-card" style="border-left:4px solid #16a34a;">
        <div class="kpi-label">Grand Total</div>
        <div class="kpi-value" style="color:var(--or,#f60);"><?= e(fmt_money($grand_total)) ?></div>
        <div class="kpi-sub">Bookings + Invoices + WOs</div>
    </div>
    <div class="kpi-card" style="border-left:4px solid #6366f1;">
        <div class="kpi-label">Stripe Revenue</div>
        <div class="kpi-value"><?= e(fmt_money($period_stripe)) ?></div>
    </div>
    <div class="kpi-card" style="border-left:4px solid #14b8a6;">
        <div class="kpi-label">ACH Revenue</div>
        <div class="kpi-value"><?= e(fmt_money($period_ach)) ?></div>
    </div>
    <div class="kpi-card" style="border-left:4px solid #16a34a;">
        <div class="kpi-label">Cash Revenue</div>
        <div class="kpi-value"><?= e(fmt_money($period_cash)) ?></div>
    </div>
    <div class="kpi-card" style="border-left:4px solid #d97706;">
        <div class="kpi-label">Check Revenue</div>
        <div class="kpi-value"><?= e(fmt_money($period_check)) ?></div>
    </div>
    <div class="kpi-card" style="border-left:4px solid #7c3aed;">
        <div class="kpi-label">Invoice Revenue</div>
        <div class="kpi-value"><?= e(fmt_money($inv_period)) ?></div>
    </div>
    <div class="kpi-card" style="border-left:4px solid #2563eb;">
        <div class="kpi-label">Work Order Revenue</div>
        <div class="kpi-value"><?= e(fmt_money($wo_period)) ?></div>
    </div>
</div>

<!-- Monthly Bar Chart -->
<?php if (!empty($monthly_revenue)): ?>
<h6 class="section-heading mb-2">
    <i class="fa-solid fa-chart-bar me-1"></i> Monthly Booking Revenue (last 6 months)
</h6>
<div class="tp-card mb-4">
    <div class="bar-chart">
        <?php foreach ($monthly_revenue as $mr):
            $st = (float)$mr['stripe']; $ca = (float)$mr['cash']; $ch = (float)$mr['chk'];
            $rt = $st + $ca + $ch;
            $total_px  = $max_bar > 0 ? max(4, round(($rt / $max_bar) * 120)) : 4;
            $stripe_px = $rt > 0 ? round(($st / $rt) * $total_px) : 0;
            $cash_px   = $rt > 0 ? round(($ca / $rt) * $total_px) : 0;
            $check_px  = $total_px - $stripe_px - $cash_px;
            $label     = date("M 'y", strtotime($mr['month'].'-01'));
        ?>
        <div class="bar-col">
            <div class="bar-count">$<?= number_format($rt,0) ?></div>
            <div style="display:flex;flex-direction:column-reverse;align-items:center;width:100%;height:120px;justify-content:flex-start;">
                <?php if ($stripe_px>0): ?><div class="bar-seg" style="height:<?=$stripe_px?>px;background:#6366f1;" title="Stripe: $<?=number_format($st,2)?>"></div><?php endif;?>
                <?php if ($cash_px>0):   ?><div class="bar-seg" style="height:<?=$cash_px?>px;background:#16a34a;" title="Cash: $<?=number_format($ca,2)?>"></div><?php endif;?>
                <?php if ($check_px>0):  ?><div class="bar-seg" style="height:<?=$check_px?>px;background:#d97706;" title="Check: $<?=number_format($ch,2)?>"></div><?php endif;?>
            </div>
            <div class="bar-label"><?= e($label) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="d-flex gap-3 mt-2" style="font-size:.75rem;">
        <span><span style="display:inline-block;width:10px;height:10px;background:#6366f1;margin-right:4px;border-radius:2px;"></span>Stripe</span>
        <span><span style="display:inline-block;width:10px;height:10px;background:#16a34a;margin-right:4px;border-radius:2px;"></span>Cash</span>
        <span><span style="display:inline-block;width:10px;height:10px;background:#d97706;margin-right:4px;border-radius:2px;"></span>Check</span>
    </div>
</div>
<?php endif; ?>

<!-- Work Orders by Status -->
<?php if (!empty($wo_status_rows)): ?>
<h6 class="section-heading mb-2">
    <i class="fa-solid fa-clipboard-list me-1"></i> Work Orders by Status
    <small class="text-muted ms-1">(<?= $all_time ? 'All Time' : e(fmt_date($date_from)).' – '.e(fmt_date($date_to)) ?>)</small>
</h6>
<div class="kpi-row mb-4">
    <?php foreach ($wo_status_rows as $row): ?>
    <div class="kpi-card">
        <div class="kpi-value"><?= (int)$row['cnt'] ?></div>
        <div class="kpi-label"><?= status_badge($row['status']) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Quick Filter Shortcuts -->
<h6 class="section-heading mb-2">
    <i class="fa-solid fa-list me-1"></i> Payment Transactions
</h6>
<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="?all_time=1"
       class="btn-tp-ghost btn-tp-sm <?= ($all_time && $pay_method==='all' && $pay_status==='all') ? 'filter-active' : '' ?>">
        All Time
    </a>
    <a href="?all_time=1&pay_method=cash&pay_status=paid"
       class="btn-tp-ghost btn-tp-sm <?= ($all_time && $pay_method==='cash' && $pay_status==='paid') ? 'filter-active' : '' ?>">
        <i class="fa-solid fa-money-bill-wave me-1" style="color:#16a34a;"></i>All Cash Payments
    </a>
    <a href="?all_time=1&pay_method=check&pay_status=paid"
       class="btn-tp-ghost btn-tp-sm <?= ($all_time && $pay_method==='check' && $pay_status==='paid') ? 'filter-active' : '' ?>">
        <i class="fa-solid fa-money-check me-1" style="color:#d97706;"></i>All Check Payments
    </a>
    <a href="?all_time=1&pay_method=stripe&pay_status=paid"
       class="btn-tp-ghost btn-tp-sm <?= ($all_time && $pay_method==='stripe' && $pay_status==='paid') ? 'filter-active' : '' ?>">
        <i class="fa-brands fa-stripe me-1" style="color:#6366f1;"></i>All Stripe Payments
    </a>
    <a href="?all_time=1&pay_method=ach&pay_status=paid"
       class="btn-tp-ghost btn-tp-sm <?= ($all_time && $pay_method==='ach' && $pay_status==='paid') ? 'filter-active' : '' ?>">
        <i class="fa-solid fa-building-columns me-1" style="color:#14b8a6;"></i>All ACH Payments
    </a>
    <a href="?pay_status=pending"
       class="btn-tp-ghost btn-tp-sm <?= (!$all_time && $pay_status==='pending' && $pay_method==='all') ? 'filter-active' : '' ?>">
        Pending Payments
    </a>
</div>

<div class="tp-card p-0 mb-4">
    <?php if (empty($filtered_bookings)): ?>
    <p class="text-muted p-4 mb-0 text-center">No transactions found for these filters.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table tp-table mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Booking #</th>
                    <th>Customer</th>
                    <th>Rental Period</th>
                    <th class="text-end">Amount</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $filtered_total = 0.0;
            foreach ($filtered_bookings as $row):
                if (in_array($row['payment_status'], ['paid','paid_cash','paid_check'], true)) {
                    $filtered_total += (float)$row['total_amount'];
                }
                $m_label = match ($row['payment_status']) {
                    'paid'                    => ($row['payment_method'] ?? '') === 'ach' ? 'ACH' : 'Stripe',
                    'paid_cash','pending_cash' => 'Cash',
                    'paid_check','pending_check' => 'Check',
                    default => ucfirst($row['payment_method'] ?? 'Unknown'),
                };
                $m_color = match ($row['payment_status']) {
                    'paid'                      => ($row['payment_method'] ?? '') === 'ach' ? '#14b8a6' : '#6366f1',
                    'processing','failed'       => '#14b8a6',
                    'paid_cash','pending_cash'   => '#16a34a',
                    'paid_check','pending_check' => '#d97706',
                    default                      => '#6b7280',
                };
            ?>
            <tr>
                <td class="text-nowrap"><?= e(fmt_date($row['updated_at'])) ?></td>
                <td>
                    <a href="<?= e(APP_URL) ?>/modules/bookings/view.php?id=<?= (int)$row['id'] ?>" class="fw-semibold">
                        <?= e($row['booking_number']) ?>
                    </a>
                </td>
                <td>
                    <div><?= e($row['customer_name']) ?></div>
                    <?php if ($row['customer_email']): ?>
                    <div style="font-size:.78rem;color:#6b7280;"><?= e($row['customer_email']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="font-size:.82rem;">
                    <?= e(fmt_date($row['rental_start'])) ?> → <?= e(fmt_date($row['rental_end'])) ?>
                </td>
                <td class="text-end fw-semibold"><?= e(fmt_money($row['total_amount'])) ?></td>
                <td>
                    <span style="color:<?= $m_color ?>;font-weight:600;font-size:.82rem;"><?= e($m_label) ?></span>
                </td>
                <td><?= payment_badge($row['payment_status']) ?></td>
                <td>
                    <a href="<?= e(APP_URL) ?>/modules/bookings/view.php?id=<?= (int)$row['id'] ?>"
                       class="btn-tp-ghost btn-tp-xs">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="border-top:2px solid var(--steel);">
                    <td colspan="4" class="fw-semibold text-end pe-3" style="font-size:.85rem;">Filtered Paid Total:</td>
                    <td class="text-end fw-bold pe-3" style="font-size:1rem;color:#4ade80;"><?= e(fmt_money($filtered_total)) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
layout_end();
