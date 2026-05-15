<?php
/**
 * Work Order Photo Delete
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
csrf_check();

$photo_id = (int)($_POST['photo_id'] ?? 0);
$wo_id    = (int)($_POST['wo_id']    ?? 0);

$photo = db_fetch('SELECT * FROM work_order_photos WHERE id = ? AND wo_id = ?', [$photo_id, $wo_id]);
if (!$photo) {
    flash_error('Photo not found.');
    redirect('view.php?id=' . $wo_id . '#photos');
}

$path = dirname(__DIR__, 3) . '/public/uploads/wo_photos/' . $wo_id . '/' . $photo['filename'];
if (is_file($path)) {
    unlink($path);
}

db_execute('DELETE FROM work_order_photos WHERE id = ?', [$photo_id]);
flash_success('Photo deleted.');

$back = trim($_POST['back'] ?? '');
$dest = ($back === 'edit') ? 'edit.php' : 'view.php';
redirect($dest . '?id=' . $wo_id . '#photos');
