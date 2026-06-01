<?php
/**
 * Public API: Batch Availability Check
 * GET /api/batch-availability.php?start=YYYY-MM-DD&end=YYYY-MM-DD
 * Returns availability status for all active, non-maintenance dumpsters.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$_admin_root = dirname(__DIR__, 2) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/api_rate_limit.php';

// 60 batch checks per IP per hour
api_rate_limit('batch_availability', 60, 3600);

$start = trim($_GET['start'] ?? '');
$end   = trim($_GET['end']   ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !strtotime($start) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)   || !strtotime($end)   ||
    $end <= $start) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing dates.']);
    exit;
}

// Fetch all active, non-maintenance units
$units = db_fetchall(
    "SELECT id FROM dumpsters WHERE active = 1 AND status != 'maintenance' ORDER BY id ASC"
);

// For each unit check overlap in bookings and inventory_blocks
$result = [];
foreach ($units as $u) {
    $uid = (int)$u['id'];

    $overlap = db_fetch(
        "SELECT COUNT(*) AS cnt FROM bookings
         WHERE dumpster_id = ?
           AND booking_status != 'canceled'
           AND rental_start <= ? AND rental_end >= ?",
        [$uid, $end, $start]
    );

    $blocked = ($overlap && (int)$overlap['cnt'] > 0) ? true : false;

    if (!$blocked) {
        $block = db_fetch(
            "SELECT COUNT(*) AS cnt FROM inventory_blocks
             WHERE dumpster_id = ?
               AND block_start <= ? AND block_end >= ?",
            [$uid, $end, $start]
        );
        $blocked = ($block && (int)$block['cnt'] > 0);
    }

    $result[$uid] = !$blocked;
}

echo json_encode(['available' => $result]);
