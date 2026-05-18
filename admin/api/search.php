<?php
/**
 * Admin Quick-Search API
 * GET /api/search.php?q=...
 * Returns up to 4 matches each from customers, bookings, work orders, and invoices.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$app_url = defined('APP_URL') ? APP_URL : '';
$like    = '%' . $q . '%';
$results = [];

try {
    $rows = db_fetchall(
        "SELECT id, name, email, phone FROM customers
         WHERE name LIKE ? OR email LIKE ? OR phone LIKE ?
         ORDER BY name ASC LIMIT 4",
        [$like, $like, $like]
    );
    foreach ($rows as $r) {
        $sub = array_filter([$r['email'], !empty($r['phone']) ? fmt_phone($r['phone']) : '']);
        $results[] = [
            'type'  => 'customer',
            'icon'  => 'fa-user',
            'label' => $r['name'],
            'sub'   => implode(' - ', $sub),
            'url'   => $app_url . '/modules/customers/view.php?id=' . (int)$r['id'],
        ];
    }
} catch (\Throwable $e) {
}

try {
    $rows = db_fetchall(
        "SELECT id, booking_number, customer_name, booking_status, total_amount
         FROM bookings
         WHERE booking_number LIKE ? OR customer_name LIKE ? OR customer_email LIKE ?
         ORDER BY id DESC LIMIT 4",
        [$like, $like, $like]
    );
    foreach ($rows as $r) {
        $parts = [
            $r['customer_name'] ?? '',
            ucfirst(str_replace('_', ' ', $r['booking_status'] ?? '')),
        ];
        if (!empty($r['total_amount'])) {
            $parts[] = fmt_money((float)$r['total_amount']);
        }
        $results[] = [
            'type'  => 'booking',
            'icon'  => 'fa-box',
            'label' => $r['booking_number'] ?: ('Booking #' . $r['id']),
            'sub'   => implode(' - ', array_filter($parts)),
            'url'   => $app_url . '/modules/bookings/view.php?id=' . (int)$r['id'],
        ];
    }
} catch (\Throwable $e) {
}

try {
    $rows = db_fetchall(
        "SELECT id, wo_number, cust_name, service_address, status
         FROM work_orders
         WHERE wo_number LIKE ? OR cust_name LIKE ? OR service_address LIKE ?
         ORDER BY id DESC LIMIT 4",
        [$like, $like, $like]
    );
    foreach ($rows as $r) {
        $parts = [$r['cust_name'] ?? ''];
        if (!empty($r['service_address'])) {
            $parts[] = $r['service_address'];
        }
        $results[] = [
            'type'  => 'work_order',
            'icon'  => 'fa-truck',
            'label' => $r['wo_number'] ?: ('WO #' . $r['id']),
            'sub'   => implode(' - ', array_filter($parts)),
            'url'   => $app_url . '/modules/work_orders/view.php?id=' . (int)$r['id'],
        ];
    }
} catch (\Throwable $e) {
}

try {
    $rows = db_fetchall(
        "SELECT id, invoice_number, cust_name, total, status
         FROM invoices
         WHERE invoice_number LIKE ? OR cust_name LIKE ?
         ORDER BY id DESC LIMIT 4",
        [$like, $like]
    );
    foreach ($rows as $r) {
        $results[] = [
            'type'  => 'invoice',
            'icon'  => 'fa-file-invoice-dollar',
            'label' => $r['invoice_number'] ?: ('INV #' . $r['id']),
            'sub'   => implode(' - ', array_filter([
                $r['cust_name'] ?? '',
                fmt_money((float)($r['total'] ?? 0)),
                ucfirst($r['status'] ?? ''),
            ])),
            'url'   => $app_url . '/modules/invoices/view.php?id=' . (int)$r['id'],
        ];
    }
} catch (\Throwable $e) {
}

echo json_encode(['results' => $results]);
