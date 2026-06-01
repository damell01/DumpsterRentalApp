<?php
/**
 * Public API: Availability Check
 * GET /api/availability.php?unit_id=X&start=YYYY-MM-DD&end=YYYY-MM-DD
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$_admin_root = dirname(__DIR__, 2) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/api_rate_limit.php';

// 120 checks per IP per hour — generous for the booking UI's date picker
api_rate_limit('availability', 120, 3600);

function json_response(bool $available, string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['available' => $available, 'message' => $message]);
    exit;
}

$unit_id = (int)($_GET['unit_id'] ?? 0);
$start   = trim($_GET['start']   ?? '');
$end     = trim($_GET['end']     ?? '');

// Input validation
if ($unit_id <= 0) {
    json_response(false, 'Invalid unit.', 400);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !strtotime($start)) {
    json_response(false, 'Invalid start date.', 400);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || !strtotime($end)) {
    json_response(false, 'Invalid end date.', 400);
}
if ($end <= $start) {
    json_response(false, 'End date must be after start date.', 400);
}

// 1. Check unit exists and is active/not in maintenance
$unit = db_fetch(
    "SELECT id, unit_code, active, status FROM dumpsters WHERE id = ? LIMIT 1",
    [$unit_id]
);
if (!$unit) {
    json_response(false, 'Unit not found.');
}
if (!$unit['active']) {
    json_response(false, 'This unit is not available for booking.');
}
if ($unit['status'] === 'maintenance') {
    json_response(false, 'This unit is currently under maintenance.');
}

// 2. Check overlapping bookings
$overlap = db_fetch(
    "SELECT COUNT(*) AS cnt FROM bookings
     WHERE dumpster_id = ?
       AND booking_status != 'canceled'
       AND rental_start <= ? AND rental_end >= ?",
    [$unit_id, $end, $start]
);
if ((int)($overlap['cnt'] ?? 0) > 0) {
    json_response(false, 'This unit is already booked for the selected dates.');
}

// 3. Check inventory blocks
$block = db_fetch(
    "SELECT COUNT(*) AS cnt FROM inventory_blocks
     WHERE dumpster_id = ?
       AND block_start <= ? AND block_end >= ?",
    [$unit_id, $end, $start]
);
if ((int)($block['cnt'] ?? 0) > 0) {
    json_response(false, 'This unit is blocked for the selected dates.');
}

json_response(true, 'Unit is available for the selected dates.');
