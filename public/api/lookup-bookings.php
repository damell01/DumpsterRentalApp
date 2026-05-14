<?php
/**
 * Public API: Look up bookings by email or phone number
 * POST /api/lookup-bookings.php
 */
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$_admin_root = dirname(__DIR__, 2) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) { $data = $_POST; }

$identifier = trim($data['identifier'] ?? '');

if (empty($identifier)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter your email or phone number.']);
    exit;
}

// Simple rate-limit: max 10 lookups per IP per hour via activity_log
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
try {
    $recent = db_fetch(
        "SELECT COUNT(*) AS cnt FROM activity_log
          WHERE action = 'booking_lookup' AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        [$ip]
    );
    if ((int)($recent['cnt'] ?? 0) >= 10) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many lookups. Please try again later.']);
        exit;
    }
    db_execute(
        "INSERT INTO activity_log (user_id, action, description, entity_type, ip_address, created_at)
         VALUES (0, 'booking_lookup', ?, 'booking', ?, NOW())",
        [substr($identifier, 0, 50), $ip]
    );
} catch (\Throwable $e) {
    // Non-fatal; continue
}

// Determine if identifier is email or phone
$is_email = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
$digits   = preg_replace('/\D/', '', $identifier);

$invoiceColumnsAvailable = db_table_exists('invoices');
$invoiceSelect = '';
if ($invoiceColumnsAvailable) {
    $invoiceNumberColumn = db_column_exists('invoices', 'invoice_number') ? 'i.invoice_number' : 'CAST(i.id AS CHAR)';
    $invoiceStatusColumn = db_column_exists('invoices', 'status') ? 'i.status' : "'sent'";
    $invoiceTotalColumn = db_column_exists('invoices', 'total') ? 'i.total' : '0';
    $invoicePaymentLinkColumn = db_column_exists('invoices', 'stripe_payment_link') ? 'i.stripe_payment_link' : "''";
    $invoiceNotesCondition = db_column_exists('invoices', 'notes')
        ? "i.notes LIKE CONCAT('%Booking ', b.booking_number, '%')"
        : "1 = 0";

    $invoiceSelect = ",
                    (
                        SELECT i.id
                        FROM invoices i
                        WHERE {$invoiceNotesCondition}
                        ORDER BY i.id DESC
                        LIMIT 1
                    ) AS invoice_id,
                    (
                        SELECT {$invoiceNumberColumn}
                        FROM invoices i
                        WHERE {$invoiceNotesCondition}
                        ORDER BY i.id DESC
                        LIMIT 1
                    ) AS invoice_number,
                    (
                        SELECT {$invoiceStatusColumn}
                        FROM invoices i
                        WHERE {$invoiceNotesCondition}
                        ORDER BY i.id DESC
                        LIMIT 1
                    ) AS invoice_status,
                    (
                        SELECT {$invoiceTotalColumn}
                        FROM invoices i
                        WHERE {$invoiceNotesCondition}
                        ORDER BY i.id DESC
                        LIMIT 1
                    ) AS invoice_total,
                    (
                        SELECT {$invoicePaymentLinkColumn}
                        FROM invoices i
                        WHERE {$invoiceNotesCondition}
                        ORDER BY i.id DESC
                        LIMIT 1
                    ) AS invoice_payment_link";
}

if (!$is_email && strlen($digits) < 7) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address or phone number.']);
    exit;
}

try {
    if ($is_email) {
        $bookings = db_fetchall(
            "SELECT b.id, b.booking_number, b.customer_name, b.customer_email, b.customer_phone,
                    b.unit_code, b.unit_size, b.rental_start, b.rental_end, b.rental_days,
                    b.total_amount, b.payment_method, b.payment_status, b.booking_status,
                    b.customer_address, b.customer_city, b.notes, b.created_at,
                    b.booking_group_id
                    {$invoiceSelect}
               FROM bookings b
              WHERE LOWER(TRIM(b.customer_email)) = ?
              ORDER BY b.rental_start DESC
              LIMIT 50",
            [strtolower($identifier)]
        );
    } else {
        // Match on digits-only phone
        $bookings = db_fetchall(
            "SELECT b.id, b.booking_number, b.customer_name, b.customer_email, b.customer_phone,
                    b.unit_code, b.unit_size, b.rental_start, b.rental_end, b.rental_days,
                    b.total_amount, b.payment_method, b.payment_status, b.booking_status,
                    b.customer_address, b.customer_city, b.notes, b.created_at,
                    b.booking_group_id
                    {$invoiceSelect}
               FROM bookings b
              WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(b.customer_phone, '-', ''), '(', ''), ')', ''), ' ', ''), '.', ''), '+', ''), '/', '') LIKE ?
              ORDER BY b.rental_start DESC
              LIMIT 50",
            ['%' . $digits . '%']
        );
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
    exit;
}

// Group bookings that share a booking_group_id so multi-unit sessions appear together
$groups      = [];   // group_id => [bookings]
$standalone  = [];   // bookings with no group

foreach ($bookings as $b) {
    if (!empty($b['booking_group_id'])) {
        $groups[$b['booking_group_id']][] = $b;
    } else {
        $standalone[] = $b;
    }
}

// Format for output — produce one entry per booking, adding group metadata
$result = [];

// Helper to format a single booking row for the API response
$format_row = function(array $b, array $group_siblings = []): array {
    $sibling_units = [];
    foreach ($group_siblings as $s) {
        if ($s['booking_number'] !== $b['booking_number']) {
            $sibling_units[] = trim(($s['unit_code'] ?? '') . ' — ' . ($s['unit_size'] ?? ''), ' —');
        }
    }
    return [
        'booking_number'   => $b['booking_number'],
        'unit'             => trim(($b['unit_code'] ?? '') . ' — ' . ($b['unit_size'] ?? ''), ' —'),
        'rental_start'     => $b['rental_start'],
        'rental_end'       => $b['rental_end'],
        'rental_days'      => (int)$b['rental_days'],
        'total_amount'     => (float)$b['total_amount'],
        'payment_method'   => $b['payment_method'],
        'payment_status'   => $b['payment_status'],
        'booking_status'   => $b['booking_status'],
        'address'          => trim(($b['customer_address'] ?? '') . ($b['customer_city'] ? ', ' . $b['customer_city'] : ''), ', '),
        'customer_name'    => $b['customer_name'],
        'customer_email'   => $b['customer_email'],
        'created_at'       => $b['created_at'],
        'booking_group_id' => $b['booking_group_id'] ?? null,
        'group_units'      => $sibling_units,
        'invoice'          => !empty($b['invoice_id']) ? [
            'id'           => (int)$b['invoice_id'],
            'number'       => (string)($b['invoice_number'] ?? ''),
            'status'       => (string)($b['invoice_status'] ?? ''),
            'total'        => (float)($b['invoice_total'] ?? 0),
            'payment_link' => (string)($b['invoice_payment_link'] ?? ''),
        ] : null,
    ];
};

// Add grouped bookings as separate entries but carry group context
foreach ($groups as $gid => $group) {
    foreach ($group as $b) {
        $result[] = $format_row($b, $group);
    }
}

// Add standalone bookings
foreach ($standalone as $b) {
    $result[] = $format_row($b);
}

// Sort all results by rental_start descending
usort($result, fn($a, $b) => strcmp($b['rental_start'] ?? '', $a['rental_start'] ?? ''));

echo json_encode(['success' => true, 'bookings' => $result, 'count' => count($result)]);
