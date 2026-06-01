<?php
/**
 * Daily Cron Job
 * Trash Panda Roll-Offs
 *
 * Run via cron: php /path/to/admin/cron/daily.php
 * Or via secure web endpoint: GET /admin/cron/daily.php?key=CRON_SECRET_KEY
 */

// Bootstrap without session
define('RUNNING_FROM_CRON', true);

require_once dirname(__DIR__) . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/mailer.php';
require_once INC_PATH . '/billing.php';

// Load push support when the library is available
$_push_autoload = ROOT_PATH . '/vendor/autoload.php';
if (file_exists($_push_autoload)) {
    require_once $_push_autoload;
}
require_once INC_PATH . '/push.php';
unset($_push_autoload);

// ── Auth: accept cron key or CLI ─────────────────────────────────────────────
$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    $provided_key = $_GET['key'] ?? '';
    if (!defined('CRON_KEY')
        || CRON_KEY === ''
        || CRON_KEY === 'change-this-to-a-random-secret'
        || !hash_equals(CRON_KEY, $provided_key)
    ) {
        http_response_code(403);
        echo "Forbidden: invalid or unconfigured cron key.\n";
        exit;
    }
    header('Content-Type: text/plain');
}

$log   = [];
$start = microtime(true);

$log[] = '=== Trash Panda Roll-Offs Daily Cron ===';
$log[] = 'Started: ' . date('Y-m-d H:i:s');
$log[] = '';

// ── Task 1: Notify deliveries tomorrow ────────────────────────────────────────
$log[] = '[Task 1] Delivery Tomorrow Notifications';
try {
    notify_delivery_tomorrow();
    $log[] = '  → Done';
} catch (\Throwable $e) {
    $log[] = '  → ERROR: ' . $e->getMessage();
}

// ── Task 2: Notify overdue pickups ────────────────────────────────────────────
$log[] = '';
$log[] = '[Task 2] Overdue Pickup Alerts';
try {
    notify_pickup_overdue();
    $log[] = '  → Done';
} catch (\Throwable $e) {
    $log[] = '  → ERROR: ' . $e->getMessage();
}

// ── Task 3: Auto-activate scheduled WOs with delivery_date <= today ───────────
$log[] = '';
$log[] = '[Task 3] Auto-activate scheduled work orders';
try {
    $today = date('Y-m-d');
    $wos_to_activate = db_fetchall(
        "SELECT id, wo_number FROM work_orders
         WHERE status = 'scheduled' AND delivery_date <= ?",
        [$today]
    );

    $count = 0;
    foreach ($wos_to_activate as $wo) {
        db_execute(
            "UPDATE work_orders SET status = 'active', updated_at = NOW() WHERE id = ?",
            [$wo['id']]
        );
        db_execute(
            "INSERT INTO work_order_notes (wo_id, user_id, note, note_type, created_at)
             VALUES (?, 0, 'Status auto-updated to Active by daily cron.', 'system', NOW())",
            [$wo['id']]
        );
        $count++;
    }

    $log[] = '  → Activated ' . $count . ' work order(s)';
} catch (\Throwable $e) {
    $log[] = '  → ERROR: ' . $e->getMessage();
}

// ── Task 4: Booking expiry reminders (3 days out) ────────────────────────────
$log[] = '';
$log[] = '[Task 4] Booking Expiry Reminders (3 days)';
try {
    $remind_date = date('Y-m-d', strtotime('+3 days'));
    $expiring    = db_fetchall(
        "SELECT * FROM bookings
          WHERE rental_end = ?
            AND booking_status IN ('confirmed','paid')
          ORDER BY id ASC",
        [$remind_date]
    );
    $count = 0;
    foreach ($expiring as $bk) {
        notify_booking_expiry_reminder($bk);

        // Push to admins once per batch (only first iteration)
        if ($count === 0) {
            $admin_url = defined('APP_URL') ? APP_URL . '/modules/bookings/index.php' : '/admin/modules/bookings/index.php';
            push_notify_admins(
                '⏳ Rentals Ending in 3 Days',
                count($expiring) . ' booking(s) end on ' . date('M j, Y', strtotime($remind_date)),
                $admin_url
            );
        }

        // Push to the customer for this booking
        foreach (array_unique(array_filter([
            !empty($bk['customer_email']) ? strtolower(trim($bk['customer_email'])) : '',
            !empty($bk['customer_phone']) ? preg_replace('/\D/', '', $bk['customer_phone']) : '',
        ])) as $cid) {
            push_notify_customer(
                $cid,
                '⏳ Rental Ending in 3 Days — ' . ($bk['booking_number'] ?? ''),
                'Your ' . ($bk['unit_size'] ?? 'dumpster') . ' rental ends on ' . date('M j, Y', strtotime($remind_date)) . '.'
            );
        }

        $count++;
    }
    $log[] = '  → Sent reminders for ' . $count . ' booking(s)';
} catch (\Throwable $e) {
    $log[] = '  → ERROR: ' . $e->getMessage();
}

// ── Task 5: Push alert for overdue bookings ───────────────────────────────────
$log[] = '';
$log[] = '[Task 5] Push Alert: Overdue Bookings';
try {
    $today    = date('Y-m-d');
    $overdue  = db_fetchall(
        "SELECT COUNT(*) AS cnt FROM bookings
          WHERE rental_end < ? AND booking_status IN ('confirmed','paid')",
        [$today]
    );
    $cnt = (int)($overdue[0]['cnt'] ?? 0);
    if ($cnt > 0) {
        $admin_url = defined('APP_URL') ? APP_URL . '/modules/bookings/index.php' : '/admin/modules/bookings/index.php';
        push_notify_admins(
            '🚨 Overdue Rentals',
            $cnt . ' booking(s) are past their rental end date.',
            $admin_url
        );
    }
    $log[] = '  → ' . $cnt . ' overdue booking(s) notified';
} catch (\Throwable $e) {
    $log[] = '  → ERROR: ' . $e->getMessage();
}

// ── Task 6: Cancel stale pending-Stripe bookings (missed webhook safety-net) ──
$log[] = '';
$log[] = '[Task 6] Cancel Stale Pending-Stripe Bookings';
try {
    // A Stripe Checkout Session is valid for 24 hours by default.
    // Any booking still in 'pending' / payment_method='stripe' state more than
    // 26 hours after creation almost certainly had its session expire without
    // a webhook being delivered.  Cancel the booking and release the dumpster.
    $cutoff = date('Y-m-d H:i:s', strtotime('-26 hours'));
    $stale  = db_fetchall(
        "SELECT id, booking_number, dumpster_id
           FROM bookings
          WHERE payment_method  = 'stripe'
            AND booking_status  = 'pending'
            AND payment_status  = 'pending'
            AND created_at     <= ?",
        [$cutoff]
    );

    $count = 0;
    foreach ($stale as $bk) {
        db_update('bookings', [
            'booking_status' => 'canceled',
            'payment_status' => 'canceled',
            'updated_at'     => date('Y-m-d H:i:s'),
        ], 'id', (int)$bk['id']);

        if (!empty($bk['dumpster_id'])) {
            db_execute(
                "UPDATE dumpsters SET status = 'available', updated_at = ? WHERE id = ? AND status = 'reserved'",
                [date('Y-m-d H:i:s'), (int)$bk['dumpster_id']]
            );
        }
        $count++;
    }

    $log[] = '  → Canceled ' . $count . ' stale pending-Stripe booking(s)';
} catch (\Throwable $e) {
    $log[] = '  → ERROR: ' . $e->getMessage();
}

// ── Task 7: Subscription reminders, portal cleanup, ACH follow-up ────────────
$log[] = '';
$log[] = '[Task 7] Subscription reminders, portal cleanup, ACH follow-up';
try {
    $threeDays = date('Y-m-d', strtotime('+3 days'));
    $upcomingSubscriptions = db_fetchall(
        "SELECT s.*, c.email
         FROM subscriptions s
         INNER JOIN customers c ON c.id = s.customer_id
         WHERE s.status IN ('active','trialing') AND s.next_billing_date = ?",
        [$threeDays]
    );
    foreach ($upcomingSubscriptions as $subscription) {
        billing_notification_service()->sendUpcomingRenewalReminder(
            ['email' => $subscription['email'] ?? ''],
            $subscription['service_name'] ?? ('Subscription ' . $subscription['id']),
            $subscription['next_billing_date']
        );
    }

    db_execute(
        "UPDATE customers
         SET portal_access_token_hash = NULL, portal_access_expires_at = NULL, updated_at = NOW()
         WHERE portal_access_expires_at IS NOT NULL AND portal_access_expires_at < NOW()"
    );

    $staleAchPayments = db_fetchall(
        "SELECT p.*, c.email, b.booking_number, i.invoice_number
         FROM payments p
         LEFT JOIN customers c ON c.id = p.customer_id
         LEFT JOIN bookings b ON b.id = p.booking_id
         LEFT JOIN invoices i ON i.id = p.invoice_id
         WHERE p.payment_method = 'ach'
           AND p.payment_status IN ('pending','processing')
           AND p.created_at <= DATE_SUB(NOW(), INTERVAL 5 DAY)"
    );
    foreach ($staleAchPayments as $payment) {
        billing_notification_service()->sendAchFailed(
            ['email' => $payment['email'] ?? ''],
            $payment['booking_number'] ?: ($payment['invoice_number'] ?: ('Payment ' . $payment['id']))
        );
    }

    $failedWebhooks = (int)(db_value("SELECT COUNT(*) FROM webhook_logs WHERE processing_status = 'failed'") ?? 0);
    if ($failedWebhooks > 0) {
        notify_admins(
            'Stripe webhook health alert',
            email_template(
                'Stripe webhook health alert',
                '<p>There are currently <strong>' . $failedWebhooks . '</strong> failed webhook events waiting for review in the admin panel.</p>'
            )
        );
    }

    $log[] = '  → Completed billing maintenance tasks';
} catch (\Throwable $e) {
    $log[] = '  → ERROR: ' . $e->getMessage();
}

// ── Task 8: Post-job review requests ─────────────────────────────────────────
$log[] = '';
$log[] = '[Task 8] Post-job review requests';
try {
    $review_url = get_setting('review_url', '');
    if ($review_url === '') {
        $log[] = '  → Skipped (no review_url configured in Settings)';
    } else {
        $completed_wos = db_fetchall(
            "SELECT wo.id, wo.wo_number, wo.cust_name, wo.cust_email, wo.service_address
             FROM work_orders wo
             WHERE wo.status IN ('completed', 'picked_up')
               AND wo.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
               AND wo.cust_email IS NOT NULL AND wo.cust_email != ''
               AND NOT EXISTS (
                   SELECT 1 FROM activity_log al
                   WHERE al.action = 'review_request_sent'
                     AND al.entity_type = 'work_order'
                     AND al.entity_id = wo.id
               )"
        );
        $review_count = 0;
        $company_name = get_setting('company_name', 'Trash Panda Roll-Offs');
        foreach ($completed_wos as $wo) {
            $email = trim($wo['cust_email'] ?? '');
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $name    = $wo['cust_name'] ? 'Hi ' . e($wo['cust_name']) . ',' : 'Hi there,';
            $body    = '<p>' . $name . '</p>'
                     . '<p>Thank you for choosing <strong>' . e($company_name) . '</strong>!'
                     . ' We hope everything went smoothly with your dumpster rental.</p>'
                     . '<p>If you have a moment, we\'d really appreciate a quick review. It helps us a lot and only takes 30 seconds.</p>';
            $html    = email_template('Leave Us a Review', $body, 'Leave a Review', $review_url);
            try {
                send_email($email, 'How did we do? — ' . $company_name, $html);
                log_activity(
                    'review_request_sent',
                    'Sent review request to ' . $email . ' for WO ' . $wo['wo_number'],
                    'work_order',
                    (int)$wo['id']
                );
                $review_count++;
            } catch (\Throwable $e) {
                $log[] = '  → Failed to send review request for WO ' . $wo['wo_number'] . ': ' . $e->getMessage();
            }
        }
        $log[] = '  → Sent ' . $review_count . ' review request(s)';
    }
} catch (\Throwable $e) {
    $log[] = '  → ERROR: ' . $e->getMessage();
}

// ── Task 9: Log completion ────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $start, 2);
$log[]   = '';
$log[]   = '[Task 9] Logging to activity_log';
try {
    db_execute(
        "INSERT INTO activity_log (user_id, action, description, entity_type, entity_id, ip_address, created_at)
         VALUES (0, 'cron', ?, 'system', 0, 'cron', NOW())",
        ['Daily cron completed in ' . $elapsed . 's']
    );
    $log[] = '  → Done';
} catch (\Throwable $e) {
    $log[] = '  → ERROR: ' . $e->getMessage();
}

// ── Output ─────────────────────────────────────────────────────────────────────
$log[] = '';
$log[] = 'Completed: ' . date('Y-m-d H:i:s') . ' (Elapsed: ' . $elapsed . 's)';
$log[] = '=========================================';

echo implode("\n", $log) . "\n";
