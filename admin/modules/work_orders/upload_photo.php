<?php
/**
 * Work Order Photo Upload
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
csrf_check();

$wo_id = (int)($_POST['wo_id'] ?? 0);
if (!$wo_id) {
    redirect('index.php');
}

$wo = db_fetch('SELECT id, wo_number FROM work_orders WHERE id = ?', [$wo_id]);
if (!$wo) {
    flash_error('Work order not found.');
    redirect('index.php');
}

$upload_base = dirname(__DIR__, 3) . '/public/uploads/wo_photos/' . $wo_id . '/';
if (!is_dir($upload_base)) {
    mkdir($upload_base, 0755, true);
    // Prevent PHP execution in the upload directory
    file_put_contents($upload_base . '.htaccess', "php_flag engine off\nOptions -ExecCGI\n");
}

$allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$max_size      = 15 * 1024 * 1024; // 15 MB
$caption       = trim($_POST['caption'] ?? '');
$user_id       = (int)(current_user()['id'] ?? 0) ?: null;

$errors   = [];
$uploaded = 0;

$files = $_FILES['photos'] ?? [];
if (empty($files['name'])) {
    flash_error('No files were selected.');
    redirect('view.php?id=' . $wo_id . '#photos');
}

// Normalise to arrays for single-file submissions
$names    = (array)$files['name'];
$tmps     = (array)$files['tmp_name'];
$sizes    = (array)$files['size'];
$errs     = (array)$files['error'];

foreach ($names as $i => $original_name) {
    $err  = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
    $tmp  = $tmps[$i] ?? '';
    $size = (int)($sizes[$i] ?? 0);

    if ($err === UPLOAD_ERR_NO_FILE || $tmp === '') {
        continue;
    }
    if ($err !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload error for ' . htmlspecialchars(basename($original_name));
        continue;
    }
    if ($size > $max_size) {
        $errors[] = htmlspecialchars(basename($original_name)) . ' exceeds the 15 MB limit.';
        continue;
    }

    // Verify real MIME type from file content, not the browser header
    $real_type = mime_content_type($tmp) ?: '';
    if (!in_array($real_type, $allowed_types, true)) {
        $errors[] = htmlspecialchars(basename($original_name)) . ' is not a supported image type (JPEG, PNG, WebP, GIF).';
        continue;
    }

    $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $ext      = $ext_map[$real_type] ?? 'jpg';
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;

    if (!move_uploaded_file($tmp, $upload_base . $filename)) {
        $errors[] = 'Could not save ' . htmlspecialchars(basename($original_name)) . '. Check directory permissions.';
        continue;
    }

    db_insert('work_order_photos', [
        'wo_id'       => $wo_id,
        'filename'    => $filename,
        'caption'     => $caption !== '' ? $caption : null,
        'uploaded_by' => $user_id,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
    $uploaded++;
}

if (!empty($errors)) {
    flash_error(implode(' | ', $errors));
}
if ($uploaded > 0) {
    flash_success($uploaded . ' photo' . ($uploaded !== 1 ? 's' : '') . ' uploaded.');
} elseif (empty($errors)) {
    flash_error('No photos were uploaded.');
}

redirect('view.php?id=' . $wo_id . '#photos');
