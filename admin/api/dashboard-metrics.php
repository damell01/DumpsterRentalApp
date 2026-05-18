<?php
/**
 * Dashboard Metrics API – Trash Panda Roll-Offs
 *
 * Returns real-time KPI data as JSON for the live-refresh dashboard.
 * Requires an active admin session.
 *
 * GET /admin/api/dashboard-metrics.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INC_PATH . '/stripe.php';

require_login();

header('Content-Type: application/json; charset=utf-8');
// Never cache this endpoint — data must always be fresh.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// ── Helper: safe integer from DB row ─────────────────────────────────────────
function api_int(array|null $row, string $col, int $default = 0): int
{
    return (int)(($row[$col] ?? null) ?? $default);
}

$payload = [
    'kpis'         => [],
    'bookings'     => [],
    'stripe'       => [],
    'activity'     => [],
    'last_updated' => date('Y-m-d H:i:s'),
];

// ── Core KPIs ────────────────────────────────────────────────────────────────
$payload['kpis'] = [
    'wo_active' => api_int(
        db_try_fetch("SELECT COUNT(*) AS cnt FROM work_orders WHERE status IN ('scheduled','delivered','active','pickup_requested')", [], ['cnt' => 0]),
        'cnt'
    ),
    'wo_today_deliveries' => api_int(
        db_try_fetch("SELECT COUNT(*) AS cnt FROM work_orders WHERE delivery_date = CURDATE() AND status NOT IN ('canceled','completed')", [], ['cnt' => 0]),
        'cnt'
    ),
    'wo_today_pickups' => api_int(
        db_try_fetch("SELECT COUNT(*) AS cnt FROM work_orders WHERE pickup_date = CURDATE() AND status NOT IN ('canceled','completed')", [], ['cnt' => 0]),
        'cnt'
    ),
    'revenue_month' => (float)((db_try_fetch(
        "SELECT COALESCE(SUM(amount), 0) AS total FROM work_orders
         WHERE status = 'completed'
           AND MONTH(updated_at) = MONTH(NOW())
           AND YEAR(updated_at)  = YEAR(NOW())",
        [],
        ['total' => 0]
    )['total'] ?? 0)),
    'dumpsters_available' => api_int(
        db_try_fetch("SELECT COUNT(*) AS cnt FROM dumpsters WHERE status = 'available'", [], ['cnt' => 0]),
        'cnt'
    ),
    'overdue_pickups' => api_int(
        db_try_fetch("SELECT COUNT(*) AS cnt FROM work_orders WHERE pickup_date < CURDATE() AND status NOT IN ('picked_up','completed','canceled')", [], ['cnt' => 0]),
        'cnt'
    ),
    'upcoming_deliveries_7d' => api_int(
        db_try_fetch("SELECT COUNT(*) AS cnt FROM work_orders WHERE delivery_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) AND status NOT IN ('canceled','completed')", [], ['cnt' => 0]),
        'cnt'
    ),
    'upcoming_pickups_7d' => api_int(
        db_try_fetch("SELECT COUNT(*) AS cnt FROM work_orders WHERE pickup_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) AND status NOT IN ('canceled','completed')", [], ['cnt' => 0]),
        'cnt'
    ),
];

// ── Booking KPIs ─────────────────────────────────────────────────────────────
try {
    $payload['bookings'] = [
        'total'      => api_int(db_fetch("SELECT COUNT(*) AS cnt FROM bookings WHERE booking_status != 'canceled'"), 'cnt'),
        'this_month' => api_int(db_fetch(
            "SELECT COUNT(*) AS cnt FROM bookings
             WHERE booking_status != 'canceled'
               AND MONTH(created_at) = MONTH(NOW())
               AND YEAR(created_at)  = YEAR(NOW())"
        ), 'cnt'),
        'upcoming'   => api_int(db_fetch("SELECT COUNT(*) AS cnt FROM bookings WHERE rental_start >= CURDATE() AND booking_status NOT IN ('canceled','completed')"), 'cnt'),
        'unpaid'     => api_int(db_fetch("SELECT COUNT(*) AS cnt FROM bookings WHERE payment_status IN ('unpaid','pending','pending_cash','pending_check') AND booking_status != 'canceled'"), 'cnt'),
        'past'       => api_int(db_fetch("SELECT COUNT(*) AS cnt FROM bookings WHERE rental_end < CURDATE() AND booking_status = 'completed'"), 'cnt'),
    ];
} catch (\Throwable $e) {
    $payload['bookings'] = ['total' => 0, 'this_month' => 0, 'upcoming' => 0, 'unpaid' => 0, 'past' => 0];
}

// ── Stripe Revenue Metrics ────────────────────────────────────────────────────
$stripe_data = [
    'revenue_month'        => 0.0,
    'revenue_today'        => 0.0,
    'charges_month_count'  => 0,
    'failed_month_count'   => 0,
    'recent_charges'       => [],
    'available'            => false,
    'error'                => null,
];

try {
    $stripe = stripe_client();

    // Current month range (Unix timestamps)
    $month_start = (int)strtotime(date('Y-m-01 00:00:00'));
    $month_end   = (int)strtotime('tomorrow midnight') - 1;
    $today_start = (int)strtotime('today midnight');

    // Fetch up to 100 charges for this month (succeeded)
    $succeeded = $stripe->charges->all([
        'limit'   => 100,
        'created' => ['gte' => $month_start, 'lte' => $month_end],
    ]);

    $revenue_month       = 0.0;
    $revenue_today       = 0.0;
    $charges_month_count = 0;
    $recent_charges      = [];

    foreach ($succeeded->data as $charge) {
        if ($charge->status !== 'succeeded' || $charge->refunded) {
            continue;
        }
        $amount_dollars = $charge->amount / 100;
        $revenue_month += $amount_dollars;
        $charges_month_count++;

        if ($charge->created >= $today_start) {
            $revenue_today += $amount_dollars;
        }

        if (count($recent_charges) < 5) {
            $recent_charges[] = [
                'id'          => $charge->id,
                'amount'      => $amount_dollars,
                'description' => $charge->description ?: ($charge->metadata['booking_number'] ?? null),
                'customer'    => $charge->billing_details->name ?? ($charge->metadata['customer_name'] ?? null),
                'created'     => date('Y-m-d H:i:s', $charge->created),
                'status'      => $charge->status,
            ];
        }
    }

    // Count failed charges this month
    $failed = $stripe->charges->all([
        'limit'   => 100,
        'created' => ['gte' => $month_start, 'lte' => $month_end],
    ]);
    $failed_month_count = 0;
    foreach ($failed->data as $charge) {
        if ($charge->status === 'failed') {
            $failed_month_count++;
        }
    }

    $stripe_data = [
        'revenue_month'       => round($revenue_month, 2),
        'revenue_today'       => round($revenue_today, 2),
        'charges_month_count' => $charges_month_count,
        'failed_month_count'  => $failed_month_count,
        'recent_charges'      => $recent_charges,
        'available'           => true,
        'error'               => null,
    ];
} catch (\Throwable $e) {
    $stripe_data['error']     = $e->getMessage();
    $stripe_data['available'] = false;
}

$payload['stripe'] = $stripe_data;

// ── Override kpis.revenue_month with Stripe data when available ───────────
if ($stripe_data['available']) {
    $payload['kpis']['revenue_month'] = $stripe_data['revenue_month'];
}

// ── Activity Feed ─────────────────────────────────────────────────────────────
// Merge recent events from multiple sources into a unified feed (latest 10).
$activity = [];

// Recent booking changes
try {
    $recent_bookings = db_fetchall(
        "SELECT b.id, b.booking_number, b.customer_name, b.booking_status,
                b.payment_status, b.rental_start, b.updated_at
         FROM bookings b
         ORDER BY b.updated_at DESC
         LIMIT 5"
    );
    foreach ($recent_bookings as $b) {
        $activity[] = [
            'type'       => 'booking',
            'icon'       => 'fa-calendar-check',
            'color'      => '#22c55e',
            'title'      => 'Booking ' . ($b['booking_number'] ?? '#' . $b['id']),
            'detail'     => ($b['customer_name'] ?? 'Customer') . ' — ' . ucfirst(str_replace('_', ' ', $b['booking_status'])),
            'sub'        => 'Payment: ' . ucfirst(str_replace('_', ' ', $b['payment_status'] ?? '')),
            'timestamp'  => $b['updated_at'],
        ];
    }
} catch (\Throwable $e) {
    // Bookings table may not exist yet.
}

// Recent work order updates
$recent_wo = db_try_fetchall(
    "SELECT id, wo_number, cust_name, status, updated_at
     FROM work_orders
     ORDER BY updated_at DESC
     LIMIT 5"
);
foreach ($recent_wo as $wo) {
    $activity[] = [
        'type'      => 'work_order',
        'icon'      => 'fa-clipboard-list',
        'color'     => '#3b82f6',
        'title'     => 'Work Order ' . ($wo['wo_number'] ?? '#' . $wo['id']),
        'detail'    => ($wo['cust_name'] ?? 'Customer') . ' — ' . ucfirst(str_replace('_', ' ', $wo['status'])),
        'sub'       => null,
        'timestamp' => $wo['updated_at'],
    ];
}

// Add recent Stripe charges to the feed
if ($stripe_data['available']) {
    foreach ($stripe_data['recent_charges'] as $charge) {
        $activity[] = [
            'type'      => 'stripe',
            'icon'      => 'fa-credit-card',
            'color'     => '#f97316',
            'title'     => 'Stripe Payment',
            'detail'    => ($charge['customer'] ?? 'Customer') . ' — $' . number_format($charge['amount'], 2),
            'sub'       => $charge['description'] ?: $charge['id'],
            'timestamp' => $charge['created'],
        ];
    }
}

// Sort all events by timestamp descending
usort($activity, function (array $a, array $b): int {
    return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
});

$payload['activity'] = array_slice($activity, 0, 10);

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
