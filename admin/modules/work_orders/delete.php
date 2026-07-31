<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_role('admin', 'office');
$pdo = get_db();

$id   = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM work_orders WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$wo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$wo) {
    flash_error('Work order not found.');
    redirect('index.php');
}

$wo_number   = $wo['wo_number'];
$dumpster_id = !empty($wo['dumpster_id']) ? (int)$wo['dumpster_id'] : null;

// ── Delete work order (notes cascade via FK or manual) ────────────────────────
// Delete associated notes first in case there is no ON DELETE CASCADE
$pdo->prepare('DELETE FROM work_order_notes WHERE wo_id = ?')->execute([$id]);

// Delete the work order
$pdo->prepare('DELETE FROM work_orders WHERE id = ?')->execute([$id]);

// ── Release dumpster back to available, if nothing else still holds it ────────
if ($dumpster_id) {
    release_dumpster_if_free($dumpster_id);
}

// ── Log & notify ──────────────────────────────────────────────────────────────
log_activity('delete_work_order', 'Deleted work order ' . $wo_number, 'work_order', $id);
flash_info('Work Order ' . htmlspecialchars($wo_number) . ' has been deleted.');
redirect('index.php');
