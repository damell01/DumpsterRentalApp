<?php

$_admin_root = dirname(__DIR__, 2) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/billing.php';
require_once INC_PATH . '/stripe.php';

$error = '';
$customer = null;
$customerId = (int)($_GET['customer_id'] ?? 0);
$token = trim((string)($_GET['token'] ?? ''));

try {
    $customer = billing_portal_access_service()->validateToken($customerId, $token);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $customer) {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'pause_subscription') {
            stripe_pause_subscription((int)$_POST['subscription_id']);
        } elseif ($action === 'resume_subscription') {
            stripe_resume_subscription((int)$_POST['subscription_id']);
        } elseif ($action === 'cancel_subscription') {
            stripe_cancel_subscription((int)$_POST['subscription_id']);
        } elseif ($action === 'detach_payment_method') {
            billing_payment_method_service()->detach((int)$customer['id'], (int)$_POST['payment_method_id']);
        } elseif ($action === 'request_pickup') {
            db_insert('notifications', [
                'type' => 'email',
                'recipient' => get_setting('company_email', ''),
                'subject' => 'Pickup request from ' . ($customer['name'] ?? 'Customer'),
                'template_key' => 'pickup_request',
                'body' => 'Customer requested pickup from billing portal.',
                'status' => 'queued',
                'related_type' => 'customer',
                'related_id' => (int)$customer['id'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        header('Location: index.php?customer_id=' . $customerId . '&token=' . urlencode($token));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$subscriptions = $customer ? db_fetchall('SELECT * FROM subscriptions WHERE customer_id = ? ORDER BY updated_at DESC', [(int)$customer['id']]) : [];
$invoices = $customer ? db_fetchall('SELECT * FROM invoices WHERE customer_id = ? ORDER BY created_at DESC LIMIT 50', [(int)$customer['id']]) : [];
$payments = $customer ? db_fetchall('SELECT * FROM payments WHERE customer_id = ? ORDER BY created_at DESC LIMIT 50', [(int)$customer['id']]) : [];
$paymentMethods = $customer ? db_fetchall('SELECT * FROM payment_methods WHERE customer_id = ? ORDER BY is_default DESC, updated_at DESC', [(int)$customer['id']]) : [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Billing Portal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/shared.css">
</head>
<body style="background:#0f1117;color:#fff;">
<div class="container py-4">
    <h1 class="mb-4">Customer Billing Portal</h1>
    <?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($customer): ?>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card bg-dark border-secondary">
                <div class="card-header">Subscriptions</div>
                <div class="card-body">
                    <?php if (!$subscriptions): ?><p class="mb-0 text-secondary">No subscriptions found.</p><?php endif; ?>
                    <?php foreach ($subscriptions as $subscription): ?>
                    <div class="border-bottom border-secondary py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong><?= e($subscription['service_name']) ?></strong>
                            <?= subscription_badge($subscription['status']) ?>
                        </div>
                        <div class="small text-secondary"><?= e(fmt_money($subscription['amount'])) ?> every <?= (int)$subscription['interval_count'] . ' ' . e($subscription['interval_unit']) ?></div>
                        <div class="small text-secondary">Next billing: <?= e(fmt_date($subscription['next_billing_date'])) ?></div>
                        <div class="d-flex gap-2 mt-2 flex-wrap">
                            <form method="POST">
                                <input type="hidden" name="subscription_id" value="<?= (int)$subscription['id'] ?>">
                                <input type="hidden" name="action" value="<?= $subscription['status'] === 'paused' ? 'resume_subscription' : 'pause_subscription' ?>">
                                <button class="btn btn-outline-light btn-sm"><?= $subscription['status'] === 'paused' ? 'Resume' : 'Pause' ?></button>
                            </form>
                            <?php if ($subscription['status'] !== 'canceled'): ?>
                            <form method="POST" onsubmit="return confirm('Cancel this subscription?');">
                                <input type="hidden" name="subscription_id" value="<?= (int)$subscription['id'] ?>">
                                <input type="hidden" name="action" value="cancel_subscription">
                                <button class="btn btn-outline-danger btn-sm">Cancel</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card bg-dark border-secondary">
                <div class="card-header">Saved Payment Methods</div>
                <div class="card-body">
                    <?php if (!$paymentMethods): ?><p class="mb-0 text-secondary">No saved payment methods.</p><?php endif; ?>
                    <?php foreach ($paymentMethods as $paymentMethod): ?>
                    <div class="border-bottom border-secondary py-3 d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= e(payment_method_label($paymentMethod['type'])) ?></strong>
                            <div class="small text-secondary"><?= e($paymentMethod['brand'] ?: $paymentMethod['bank_name'] ?: '') ?> <?= e($paymentMethod['last4'] ? '•••• ' . $paymentMethod['last4'] : '') ?></div>
                        </div>
                        <form method="POST" onsubmit="return confirm('Remove this payment method?');">
                            <input type="hidden" name="payment_method_id" value="<?= (int)$paymentMethod['id'] ?>">
                            <input type="hidden" name="action" value="detach_payment_method">
                            <button class="btn btn-outline-danger btn-sm">Remove</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card bg-dark border-secondary">
                <div class="card-header">Invoices</div>
                <div class="card-body">
                    <?php if (!$invoices): ?><p class="mb-0 text-secondary">No invoices found.</p><?php endif; ?>
                    <?php foreach ($invoices as $invoice): ?>
                    <div class="border-bottom border-secondary py-3">
                        <div class="d-flex justify-content-between">
                            <strong><?= e($invoice['invoice_number']) ?></strong>
                            <?= payment_badge($invoice['status']) ?>
                        </div>
                        <div class="small text-secondary"><?= e(fmt_money($invoice['total'])) ?></div>
                        <?php if (!empty($invoice['stripe_payment_link'])): ?>
                        <a class="btn btn-warning btn-sm mt-2" href="<?= e($invoice['stripe_payment_link']) ?>">Pay invoice</a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card bg-dark border-secondary">
                <div class="card-header">Payment History</div>
                <div class="card-body">
                    <?php if (!$payments): ?><p class="mb-0 text-secondary">No payments found.</p><?php endif; ?>
                    <?php foreach ($payments as $payment): ?>
                    <div class="border-bottom border-secondary py-3">
                        <div class="d-flex justify-content-between">
                            <strong><?= e(fmt_money($payment['amount'])) ?></strong>
                            <?= payment_badge($payment['payment_status']) ?>
                        </div>
                        <div class="small text-secondary"><?= e(payment_method_label($payment['payment_method'])) ?> · <?= e(fmt_datetime($payment['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                    <form method="POST" class="mt-3">
                        <input type="hidden" name="action" value="request_pickup">
                        <button class="btn btn-outline-light btn-sm">Request Pickup</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
