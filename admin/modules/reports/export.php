<?php
/**
 * Reports – CSV Export
 * Trash Panda Roll-Offs
 *
 * Accepts the same GET filters as index.php and streams a CSV download.
 * ?type=bookings|invoices|work_orders (default: bookings)
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();

// ── Filters (same as index.php) ───────────────────────────────────────────────
$type       = in_array(trim($_GET['type'] ?? 'bookings'), ['bookings', 'invoices', 'work_orders'], true)
              ? trim($_GET['type'] ?? 'bookings') : 'bookings';
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

// ── Booking status filter (copied from index.php) ─────────────────────────────
function export_booking_status_filter(string $method, string $status): array
{
    $paid_all    = ['paid', 'paid_cash', 'paid_check'];
    $pending_all = ['pending', 'pending_cash', 'pending_check', 'unpaid'];

    if ($method === 'stripe') {
        if ($status === 'paid')    return ['paid'];
        if ($status === 'pending') return ['pending', 'unpaid'];
        return ['paid', 'pending', 'unpaid'];
    }
    if ($method === 'ach') {
        if ($status === 'paid')    return ['paid'];
        if ($status === 'pending') return ['pending', 'processing', 'failed'];
        return ['paid', 'pending', 'processing', 'failed', 'refunded'];
    }
    if ($method === 'cash') {
        if ($status === 'paid')    return ['paid_cash'];
        if ($status === 'pending') return ['pending_cash'];
        return ['paid_cash', 'pending_cash'];
    }
    if ($method === 'check') {
        if ($status === 'paid')    return ['paid_check'];
        if ($status === 'pending') return ['pending_check'];
        return ['paid_check', 'pending_check'];
    }
    if ($status === 'paid')    return $paid_all;
    if ($status === 'pending') return $pending_all;
    return array_merge($paid_all, $pending_all);
}

// ── Helper: write a CSV row ───────────────────────────────────────────────────
function csv_row(array $cols): string
{
    $escaped = array_map(static function (string $v): string {
        $v = str_replace('"', '""', $v);
        return '"' . $v . '"';
    }, array_map('strval', $cols));
    return implode(',', $escaped) . "\r\n";
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$rows    = [];
$headers = [];
$filename = '';

if ($type === 'bookings') {
    $bk_statuses = export_booking_status_filter($pay_method, $pay_status);
    $bk_ph       = implode(',', array_fill(0, count($bk_statuses), '?'));
    $bk_params   = array_merge([$dt_from, $dt_to], $bk_statuses);

    $rows = db_fetchall(
        "SELECT b.booking_number, b.customer_name, b.customer_email, b.customer_phone,
                b.rental_start, b.rental_end, b.rental_days,
                b.total_amount, b.payment_method, b.payment_status, b.booking_status,
                b.service_address, b.updated_at
         FROM bookings b
         WHERE b.booking_status != 'canceled'
           AND b.updated_at BETWEEN ? AND ?
           AND b.payment_status IN ($bk_ph)
         ORDER BY b.updated_at DESC
         LIMIT 5000",
        $bk_params
    );

    $headers  = ['Booking #', 'Customer Name', 'Email', 'Phone', 'Rental Start', 'Rental End',
                 'Days', 'Amount', 'Payment Method', 'Payment Status', 'Booking Status',
                 'Service Address', 'Last Updated'];
    $filename = 'bookings-report-' . $date_from . '-to-' . $date_to . '.csv';

} elseif ($type === 'invoices') {
    $rows = db_fetchall(
        "SELECT i.invoice_number, c.name AS customer_name, c.email AS customer_email,
                i.status, i.total, i.due_date, i.paid_at, i.payment_method,
                i.created_at, i.updated_at
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         WHERE i.updated_at BETWEEN ? AND ?
         ORDER BY i.updated_at DESC
         LIMIT 5000",
        [$dt_from, $dt_to]
    );

    $headers  = ['Invoice #', 'Customer Name', 'Email', 'Status', 'Total', 'Due Date',
                 'Paid At', 'Payment Method', 'Created', 'Last Updated'];
    $filename = 'invoices-report-' . $date_from . '-to-' . $date_to . '.csv';

} elseif ($type === 'work_orders') {
    $rows = db_fetchall(
        "SELECT wo.wo_number, wo.cust_name, wo.cust_email, wo.cust_phone,
                wo.service_address, wo.size, wo.status, wo.priority,
                wo.delivery_date, wo.pickup_date, wo.amount,
                wo.assigned_driver, wo.created_at, wo.updated_at
         FROM work_orders wo
         WHERE wo.created_at BETWEEN ? AND ?
         ORDER BY wo.created_at DESC
         LIMIT 5000",
        [$dt_from, $dt_to]
    );

    $headers  = ['WO #', 'Customer', 'Email', 'Phone', 'Service Address', 'Size',
                 'Status', 'Priority', 'Delivery Date', 'Pickup Date', 'Amount',
                 'Driver', 'Created', 'Last Updated'];
    $filename = 'work-orders-report-' . $date_from . '-to-' . $date_to . '.csv';
}

// ── Stream CSV ────────────────────────────────────────────────────────────────
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM so Excel opens it correctly
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, $headers);

foreach ($rows as $row) {
    $cols = array_values($row);
    fputcsv($out, array_map('strval', $cols));
}

// Summary footer row for bookings
if ($type === 'bookings' && !empty($rows)) {
    $paid_total = array_sum(array_map(static function (array $r): float {
        return in_array($r['payment_status'], ['paid', 'paid_cash', 'paid_check'], true)
            ? (float)$r['total_amount'] : 0.0;
    }, $rows));
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', '', '', '', 'PAID TOTAL:', number_format($paid_total, 2), '', '', '']);
    fputcsv($out, ['', '', '', '', '', '', '', 'ROWS EXPORTED:', (string)count($rows), '', '', '']);
    fputcsv($out, ['Report generated:', date('Y-m-d H:i:s'), 'Period:', $date_from . ' to ' . $date_to]);
}

if ($type === 'invoices' && !empty($rows)) {
    $paid_total = array_sum(array_map(static fn(array $r): float => $r['status'] === 'paid' ? (float)$r['total'] : 0.0, $rows));
    fputcsv($out, []);
    fputcsv($out, ['', '', '', 'PAID TOTAL:', number_format($paid_total, 2)]);
    fputcsv($out, ['Report generated:', date('Y-m-d H:i:s'), 'Period:', $date_from . ' to ' . $date_to]);
}

fclose($out);
exit;
