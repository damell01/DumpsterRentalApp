<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INC_PATH . '/mailer.php';
require_login();
$pdo = get_db();

// POST-only endpoint
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

csrf_check();

$wo_id      = (int)($_POST['wo_id']      ?? 0);
$new_status = trim($_POST['new_status']  ?? '');

// ── Validate status ───────────────────────────────────────────────────────────
$valid_statuses = ['scheduled', 'delivered', 'active', 'pickup_requested', 'picked_up', 'completed', 'canceled'];

if (!in_array($new_status, $valid_statuses)) {
    flash_error('Invalid status value.');
    redirect('view.php?id=' . $wo_id);
}

// ── Fetch current work order ──────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM work_orders WHERE id = ? LIMIT 1');
$stmt->execute([$wo_id]);
$wo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$wo) {
    flash_error('Work order not found.');
    redirect('index.php');
}

// Check dispatcher role restriction (dispatchers can update status but not delete)
// Role check already handled by require_login; dispatcher access is permitted here
$old_status = $wo['status'];

if ($old_status === $new_status) {
    flash_info('Status is already set to ' . ucfirst(str_replace('_', ' ', $new_status)) . '.');
    redirect('view.php?id=' . $wo_id);
}

// ── Build status labels for the note ─────────────────────────────────────────
$status_labels = [
    'scheduled'        => 'Scheduled',
    'delivered'        => 'Delivered',
    'active'           => 'Active',
    'pickup_requested' => 'Pickup Requested',
    'picked_up'        => 'Picked Up',
    'completed'        => 'Completed',
    'canceled'         => 'Canceled',
];
$old_label = $status_labels[$old_status] ?? ucfirst(str_replace('_', ' ', $old_status));
$new_label = $status_labels[$new_status] ?? ucfirst(str_replace('_', ' ', $new_status));

// ── Update work order status ──────────────────────────────────────────────────
if ($new_status === 'picked_up') {
    $upd = $pdo->prepare(
        'UPDATE work_orders SET status = ?, actual_pickup = CURDATE(), updated_at = NOW() WHERE id = ?'
    );
    $upd->execute([$new_status, $wo_id]);
} else {
    $upd = $pdo->prepare(
        'UPDATE work_orders SET status = ?, updated_at = NOW() WHERE id = ?'
    );
    $upd->execute([$new_status, $wo_id]);
}

// ── Update dumpster status if relevant ────────────────────────────────────────
if (!empty($wo['dumpster_id'])) {
    $dumpster_id = (int)$wo['dumpster_id'];

    if ($new_status === 'delivered' || $new_status === 'active') {
        // Dumpster is out on site
        $pdo->prepare('UPDATE dumpsters SET status = ? WHERE id = ?')
            ->execute(['in_use', $dumpster_id]);
    } elseif (in_array($new_status, ['picked_up', 'completed', 'canceled'])) {
        // Dumpster returned or job ended — only release it if no other
        // active booking/work order still has it committed.
        release_dumpster_if_free($dumpster_id);
    } elseif ($new_status === 'scheduled') {
        // Reverted to scheduled — mark as reserved
        $pdo->prepare('UPDATE dumpsters SET status = ? WHERE id = ?')
            ->execute(['reserved', $dumpster_id]);
    }
}

// ── Insert status change note ─────────────────────────────────────────────────
$note_text = 'Status changed from "' . $old_label . '" to "' . $new_label . '"';
$ins_note = $pdo->prepare(
    'INSERT INTO work_order_notes (wo_id, user_id, note, note_type, created_at)
     VALUES (?, ?, ?, ?, NOW())'
);
$ins_note->execute([$wo_id, $_SESSION['user_id'], $note_text, 'status_change']);

// ── Log activity ──────────────────────────────────────────────────────────────
log_activity(
    'update_wo_status',
    'Work order ' . $wo['wo_number'] . ': ' . $note_text,
    'work_order',
    $wo_id
);

// Email customer on key status transitions
if (in_array($new_status, ['delivered', 'picked_up', 'completed'], true)) {
    try {
        notify_work_order_status_change($wo, $new_status);
    } catch (\Throwable $e) {
        error_log('[WO status] notify_work_order_status_change failed: ' . $e->getMessage());
    }
}

flash_success('Status updated to ' . $new_label . '.');
redirect('view.php?id=' . $wo_id);
