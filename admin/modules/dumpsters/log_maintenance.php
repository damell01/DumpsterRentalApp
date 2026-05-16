<?php
/**
 * Dumpsters – Log Maintenance Entry
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_role('admin', 'office');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/modules/dumpsters/index.php');
}
csrf_check();

$dumpster_id  = (int)($_POST['dumpster_id'] ?? 0);
$maint_date   = trim($_POST['maintenance_date'] ?? '');
$description  = trim($_POST['description'] ?? '');
$performed_by = trim($_POST['performed_by'] ?? '');
$cost_raw     = trim($_POST['cost'] ?? '');
$cost         = $cost_raw !== '' ? (float)$cost_raw : null;

if ($dumpster_id <= 0) {
    flash_error('Invalid dumpster.');
    redirect(APP_URL . '/modules/dumpsters/index.php');
}

$dumpster = db_fetch('SELECT id, unit_code FROM dumpsters WHERE id = ? LIMIT 1', [$dumpster_id]);
if (!$dumpster) {
    flash_error('Dumpster not found.');
    redirect(APP_URL . '/modules/dumpsters/index.php');
}

if ($maint_date === '' || !strtotime($maint_date)) {
    flash_error('A valid maintenance date is required.');
    redirect(APP_URL . '/modules/dumpsters/edit.php?id=' . $dumpster_id . '#maintenance');
}
if ($description === '') {
    flash_error('Description is required.');
    redirect(APP_URL . '/modules/dumpsters/edit.php?id=' . $dumpster_id . '#maintenance');
}

db_insert('dumpster_maintenance_logs', [
    'dumpster_id'      => $dumpster_id,
    'maintenance_date' => date('Y-m-d', strtotime($maint_date)),
    'description'      => $description,
    'performed_by'     => $performed_by ?: null,
    'cost'             => $cost,
    'created_by'       => current_user()['id'] ?? null,
    'created_at'       => date('Y-m-d H:i:s'),
]);

// Keep last_maintenance_date in sync with the most recent log entry
db_execute(
    "UPDATE dumpsters
     SET last_maintenance_date = (
         SELECT MAX(maintenance_date) FROM dumpster_maintenance_logs WHERE dumpster_id = ?
     ), updated_at = NOW()
     WHERE id = ?",
    [$dumpster_id, $dumpster_id]
);

log_activity('maintenance_logged', 'Logged maintenance for ' . $dumpster['unit_code'], 'dumpster', $dumpster_id);
flash_success('Maintenance entry logged.');
redirect(APP_URL . '/modules/dumpsters/edit.php?id=' . $dumpster_id . '#maintenance');
