<?php
/**
 * Daily Cron Runner — Trash Panda Roll-Offs
 *
 * Runs all scheduled notification tasks once per day.
 *
 * ── Hostinger setup ──────────────────────────────────────────────────────────
 * In Hostinger hPanel → Advanced → Cron Jobs, add a new job:
 *
 *   Command : /usr/bin/php /home/YOUR_USERNAME/public_html/admin/cron/run.php
 *   Schedule: Once daily, e.g. 0 8 * * *  (8:00 AM every day)
 *
 * Replace YOUR_USERNAME with your Hostinger account username.
 * You can find the full path in hPanel → Files → File Manager (top address bar).
 *
 * Alternatively, call via HTTP with the cron key from Settings:
 *   curl -s "https://yourdomain.com/admin/cron/run.php?key=YOUR_CRON_KEY"
 * ─────────────────────────────────────────────────────────────────────────────
 */

$_admin_root = dirname(__DIR__);
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/mailer.php';

// ── Auth: CLI runs freely; HTTP requires the cron_key setting ────────────────
$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    $stored_key = trim(get_setting('cron_key', ''));
    $given_key  = trim($_GET['key'] ?? '');

    if ($stored_key === '' || $given_key === '' || !hash_equals($stored_key, $given_key)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    header('Content-Type: application/json');
}

// ── Prevent overlapping runs (lock file approach) ────────────────────────────
$lock_file = sys_get_temp_dir() . '/tp_cron.lock';
$lock_fp   = fopen($lock_file, 'c');
if (!$lock_fp || !flock($lock_fp, LOCK_EX | LOCK_NB)) {
    $msg = 'Cron already running — skipped.';
    echo $is_cli ? $msg . PHP_EOL : json_encode(['skipped' => true, 'reason' => $msg]);
    exit;
}

// ── Run tasks ────────────────────────────────────────────────────────────────
$results = [];

$tasks = [
    'delivery_reminders' => function () {
        notify_delivery_tomorrow();
        return 'ok';
    },
    'overdue_pickups' => function () {
        notify_pickup_overdue();
        return 'ok';
    },
    'overdue_invoices' => function () {
        notify_invoice_overdue();
        return 'ok';
    },
];

foreach ($tasks as $name => $fn) {
    try {
        $results[$name] = $fn();
    } catch (\Throwable $e) {
        $results[$name] = 'error: ' . $e->getMessage();
        error_log('[cron] ' . $name . ' failed: ' . $e->getMessage());
    }
}

// ── Release lock ─────────────────────────────────────────────────────────────
flock($lock_fp, LOCK_UN);
fclose($lock_fp);

// ── Output ───────────────────────────────────────────────────────────────────
$results['ran_at'] = date('Y-m-d H:i:s');

if ($is_cli) {
    foreach ($results as $k => $v) {
        echo $k . ': ' . $v . PHP_EOL;
    }
} else {
    echo json_encode($results);
}
