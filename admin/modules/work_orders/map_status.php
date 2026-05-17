<?php
/**
 * AJAX – Update a work order status from the dispatch map.
 * POST: _csrf, wo_id, new_status  →  JSON
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INC_PATH . '/mailer.php';
require_login();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

if (!csrf_verify()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$wo_id      = (int)($_POST['wo_id']     ?? 0);
$new_status = trim($_POST['new_status'] ?? '');

$valid = ['scheduled', 'delivered', 'active', 'pickup_requested', 'picked_up', 'completed', 'canceled'];
if (!$wo_id || !in_array($new_status, $valid, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$pdo = get_db();
$wo  = db_fetch('SELECT * FROM work_orders WHERE id = ? LIMIT 1', [$wo_id]);

if (!$wo) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Work order not found']);
    exit;
}

$old_status = $wo['status'];
if ($old_status === $new_status) {
    echo json_encode(['success' => true, 'status' => $new_status]);
    exit;
}

$labels = [
    'scheduled'        => 'Scheduled',
    'delivered'        => 'Delivered',
    'active'           => 'Active',
    'pickup_requested' => 'Pickup Requested',
    'picked_up'        => 'Picked Up',
    'completed'        => 'Completed',
    'canceled'         => 'Canceled',
];

try {
    if ($new_status === 'picked_up') {
        db_execute(
            'UPDATE work_orders SET status = ?, actual_pickup = CURDATE(), updated_at = NOW() WHERE id = ?',
            [$new_status, $wo_id]
        );
    } else {
        db_execute(
            'UPDATE work_orders SET status = ?, updated_at = NOW() WHERE id = ?',
            [$new_status, $wo_id]
        );
    }

    // Sync dumpster status
    if (!empty($wo['dumpster_id'])) {
        $ds = match(true) {
            in_array($new_status, ['delivered', 'active'], true)           => 'in_use',
            in_array($new_status, ['picked_up', 'completed', 'canceled'], true) => 'available',
            $new_status === 'scheduled'                                    => 'reserved',
            default                                                        => null,
        };
        if ($ds !== null) {
            db_execute('UPDATE dumpsters SET status = ?, updated_at = NOW() WHERE id = ?',
                [$ds, (int)$wo['dumpster_id']]);
        }
    }

    // Note
    $note = 'Status changed from "' . ($labels[$old_status] ?? $old_status)
          . '" to "' . ($labels[$new_status] ?? $new_status) . '" via dispatch map';
    db_execute(
        'INSERT INTO work_order_notes (wo_id, user_id, note, note_type, created_at) VALUES (?, ?, ?, ?, NOW())',
        [$wo_id, (int)($_SESSION['user_id'] ?? 0), $note, 'status_change']
    );

    log_activity('update_wo_status', 'WO ' . $wo['wo_number'] . ': ' . $note, 'work_order', $wo_id);

    // Customer notification on key transitions
    if (in_array($new_status, ['delivered', 'picked_up', 'completed'], true)) {
        try { notify_work_order_status_change($wo, $new_status); } catch (\Throwable $ignored) {}
    }

    echo json_encode(['success' => true, 'status' => $new_status, 'label' => $labels[$new_status]]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
