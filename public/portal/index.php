<?php

$_admin_root = dirname(__DIR__, 2) . '/admin';

spl_autoload_register(static function (string $class): void {
    $prefix = 'TrashPanda\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/billing.php';
require_once INC_PATH . '/stripe.php';

session_init();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self';");

$error = '';
$customer = null;
$customerId = (int)($_GET['customer_id'] ?? 0);
$token = trim((string)($_GET['token'] ?? ''));
$company_name = get_setting('company_name', 'Trash Panda Roll-Offs');

try {
    $customer = billing_portal_access_service()->validateToken($customerId, $token);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $customer) {
    csrf_check();
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

$active_subscription_count = 0;
foreach ($subscriptions as $subscription) {
    if (in_array((string)($subscription['status'] ?? ''), ['trialing', 'active', 'paused', 'past_due', 'incomplete'], true)) {
        $active_subscription_count++;
    }
}

$open_invoice_count = 0;
foreach ($invoices as $invoice) {
    if (!in_array((string)($invoice['status'] ?? ''), ['paid', 'void'], true)) {
        $open_invoice_count++;
    }
}

$paid_total = 0.0;
foreach ($payments as $payment) {
    if (in_array((string)($payment['payment_status'] ?? ''), ['paid', 'processed', 'received'], true)) {
        $paid_total += (float)($payment['amount'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Billing Portal - <?= e($company_name) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/shared.css">
    <style>
        body {
            background: var(--black);
            color: var(--white);
            font-family: var(--font-body);
        }
        .portal-nav {
            background: var(--dark);
            border-bottom: 1px solid var(--steel);
            padding: .85rem 1rem;
        }
        .portal-nav-inner {
            max-width: 1180px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .portal-brand {
            font-family: var(--font-display);
            font-size: 1.05rem;
            color: var(--white);
            text-decoration: none;
        }
        .portal-brand span { color: var(--orange); }
        .portal-nav-link {
            margin-left: auto;
            color: var(--gray-light);
            font-size: .85rem;
            text-decoration: none;
        }
        .portal-nav-link:hover { color: var(--white); }
        .portal-shell {
            max-width: 1180px;
            margin: 0 auto;
            padding: 2.9rem 1.2rem 5rem;
        }
        .portal-hero {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 2rem;
            background:
                radial-gradient(circle at top right, rgba(249,115,22,.14), transparent 30%),
                linear-gradient(135deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
            border: 1px solid var(--steel);
            border-radius: 18px;
            padding: 1.65rem 1.7rem;
        }
        .portal-overline {
            color: var(--gray-light);
            font-size: .82rem;
            letter-spacing: .12em;
            text-transform: uppercase;
            font-family: var(--font-cond);
        }
        .portal-title {
            font-family: var(--font-display);
            font-size: clamp(2rem, 5vw, 3rem);
            margin: .25rem 0 0;
        }
        .portal-title span { color: var(--orange); }
        .portal-subtitle {
            margin-top: .75rem;
            color: var(--gray-light);
            font-size: 1rem;
            line-height: 1.7;
            max-width: 720px;
        }
        .portal-chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.1);
            color: var(--gray-light);
            border-radius: 999px;
            padding: .45rem .8rem;
            font-size: .75rem;
            letter-spacing: .08em;
            text-transform: uppercase;
            font-family: var(--font-cond);
        }
        .portal-kpis {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1.1rem;
            margin-bottom: 1.45rem;
        }
        .portal-kpi {
            background: var(--dark2);
            border: 1px solid var(--steel);
            border-radius: 18px;
            padding: 1.15rem 1.1rem 1.05rem;
        }
        .portal-kpi-label {
            color: var(--gray-light);
            font-size: .74rem;
            letter-spacing: .08em;
            text-transform: uppercase;
            font-family: var(--font-cond);
        }
        .portal-kpi-value {
            margin-top: .35rem;
            font-family: var(--font-cond);
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--white);
        }
        .portal-kpi-note {
            margin-top: .2rem;
            color: var(--gray);
            font-size: .78rem;
        }
        .portal-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 1.35rem;
        }
        .portal-span-12 { grid-column: span 12; }
        .portal-span-8 { grid-column: span 8; }
        .portal-span-6 { grid-column: span 6; }
        .portal-span-4 { grid-column: span 4; }
        .portal-panel {
            background: var(--dark2);
            border: 1px solid var(--steel);
            border-radius: 18px;
            padding: 1.55rem;
        }
        .portal-panel-head {
            margin-bottom: 1.15rem;
        }
        .portal-panel-head h2 {
            margin: 0;
            font-family: var(--font-cond);
            font-size: 1.08rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .portal-panel-head p {
            margin: .42rem 0 0;
            color: var(--gray-light);
            font-size: .88rem;
            line-height: 1.65;
        }
        .portal-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .portal-item {
            background: var(--dark3);
            border: 1px solid var(--steel);
            border-radius: 16px;
            padding: 1.1rem;
        }
        .portal-item-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .portal-item-title {
            font-family: var(--font-cond);
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--white);
            margin: 0;
        }
        .portal-item-meta {
            margin-top: .2rem;
            color: var(--gray-light);
            font-size: .86rem;
        }
        .portal-item-submeta {
            margin-top: .2rem;
            color: var(--gray);
            font-size: .8rem;
        }
        .portal-actions {
            display: flex;
            gap: .6rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        .portal-actions form { margin: 0; }
        .portal-empty {
            margin: 0;
            background: var(--dark3);
            border: 1px dashed var(--steel2);
            border-radius: 14px;
            padding: 1rem;
            color: var(--gray-light);
        }
        .portal-note-box {
            background: rgba(249,115,22,.1);
            border: 1px solid rgba(249,115,22,.25);
            border-radius: 16px;
            padding: 1.15rem;
            color: var(--gray-light);
            font-size: .92rem;
            line-height: 1.65;
        }
        .portal-note-box strong { color: var(--white); }
        .portal-footer-links {
            display: flex;
            gap: .8rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        .portal-footer-links a {
            color: var(--orange);
            text-decoration: none;
            font-weight: 600;
        }
        .portal-footer-links a:hover { color: var(--orange-light); }
        @media (max-width: 991px) {
            .portal-kpis {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .portal-span-8,
            .portal-span-6,
            .portal-span-4 {
                grid-column: span 12;
            }
        }
        @media (max-width: 575px) {
            .portal-kpis {
                grid-template-columns: 1fr;
            }
            .portal-shell {
                padding: 2rem .9rem 4rem;
            }
            .portal-hero,
            .portal-panel,
            .portal-kpi {
                padding-left: 1.15rem;
                padding-right: 1.15rem;
            }
        }
    </style>
</head>
<body>
<nav class="portal-nav">
    <div class="portal-nav-inner">
        <a href="/" class="portal-brand">TRASH PANDA <span>ROLL-OFFS</span></a>
        <a href="/portal/request-link.php" class="portal-nav-link">Request a new secure link</a>
    </div>
</nav>

<div class="portal-shell">
    <div class="portal-hero">
        <div>
            <div class="portal-overline">Customer billing access</div>
            <h1 class="portal-title">BILLING <span>PORTAL</span></h1>
            <p class="portal-subtitle">Review invoices, pay open balances, manage subscriptions, and check payment activity in one place.</p>
        </div>
        <?php if ($customer): ?>
        <div class="portal-chip">
            <i class="fa-solid fa-user"></i>
            <?= e($customer['name'] ?? 'Customer') ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($customer): ?>
    <div class="portal-kpis">
        <div class="portal-kpi">
            <div class="portal-kpi-label">Active Services</div>
            <div class="portal-kpi-value"><?= (int)$active_subscription_count ?></div>
            <div class="portal-kpi-note">Subscriptions currently on your account</div>
        </div>
        <div class="portal-kpi">
            <div class="portal-kpi-label">Open Invoices</div>
            <div class="portal-kpi-value"><?= (int)$open_invoice_count ?></div>
            <div class="portal-kpi-note">Invoices that still need attention</div>
        </div>
        <div class="portal-kpi">
            <div class="portal-kpi-label">Saved Methods</div>
            <div class="portal-kpi-value"><?= count($paymentMethods) ?></div>
            <div class="portal-kpi-note">Cards and bank accounts on file</div>
        </div>
        <div class="portal-kpi">
            <div class="portal-kpi-label">Paid To Date</div>
            <div class="portal-kpi-value"><?= e(fmt_money($paid_total)) ?></div>
            <div class="portal-kpi-note">Completed payment activity</div>
        </div>
    </div>

    <div class="portal-grid">
        <section class="portal-panel portal-span-8">
            <div class="portal-panel-head">
                <h2>Invoices</h2>
                <p>Pay open balances and review your recent invoice activity.</p>
            </div>
            <?php if (!$invoices): ?>
            <p class="portal-empty">No invoices found for this account yet.</p>
            <?php else: ?>
            <div class="portal-list">
                <?php foreach ($invoices as $invoice): ?>
                <article class="portal-item">
                    <div class="portal-item-head">
                        <div>
                            <h3 class="portal-item-title"><?= e($invoice['invoice_number']) ?></h3>
                            <div class="portal-item-meta"><?= e(fmt_money($invoice['total'])) ?> - Due <?= e(fmt_date($invoice['due_date'] ?? '')) ?></div>
                            <?php if (!empty($invoice['notes'])): ?>
                            <div class="portal-item-submeta"><?= e(mb_strimwidth((string)$invoice['notes'], 0, 130, '...')) ?></div>
                            <?php endif; ?>
                        </div>
                        <div><?= payment_badge((string)$invoice['status']) ?></div>
                    </div>
                    <div class="portal-actions">
                        <?php if (!empty($invoice['stripe_payment_link'])): ?>
                        <a class="btn-panda" href="<?= e($invoice['stripe_payment_link']) ?>" target="_blank" rel="noopener">Pay Invoice</a>
                        <?php else: ?>
                        <span class="portal-chip">Payment handled offline</span>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <aside class="portal-panel portal-span-4">
            <div class="portal-panel-head">
                <h2>Quick Actions</h2>
                <p>Common account actions you can handle from here.</p>
            </div>
            <div class="portal-note-box">
                <strong>Need a dumpster pickup?</strong><br>
                Send a pickup request and the office will follow up on scheduling.
            </div>
            <div class="portal-actions">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="request_pickup">
                    <button class="btn-panda" type="submit">Request Pickup</button>
                </form>
                <a href="/contact.html" class="btn-ghost">Contact Support</a>
            </div>
            <div class="portal-footer-links">
                <a href="/my-bookings.php">My Bookings</a>
                <a href="/portal/request-link.php">Get New Portal Link</a>
            </div>
        </aside>

        <section class="portal-panel portal-span-6">
            <div class="portal-panel-head">
                <h2>Subscriptions</h2>
                <p>Pause, resume, or cancel recurring services on your account.</p>
            </div>
            <?php if (!$subscriptions): ?>
            <p class="portal-empty">No subscriptions found.</p>
            <?php else: ?>
            <div class="portal-list">
                <?php foreach ($subscriptions as $subscription): ?>
                <article class="portal-item">
                    <div class="portal-item-head">
                        <div>
                            <h3 class="portal-item-title"><?= e($subscription['service_name']) ?></h3>
                            <div class="portal-item-meta"><?= e(fmt_money($subscription['amount'])) ?> every <?= (int)$subscription['interval_count'] . ' ' . e($subscription['interval_unit']) ?></div>
                            <div class="portal-item-submeta">Next billing: <?= e(fmt_date($subscription['next_billing_date'])) ?></div>
                        </div>
                        <div><?= subscription_badge((string)$subscription['status']) ?></div>
                    </div>
                    <div class="portal-actions">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="subscription_id" value="<?= (int)$subscription['id'] ?>">
                            <input type="hidden" name="action" value="<?= $subscription['status'] === 'paused' ? 'resume_subscription' : 'pause_subscription' ?>">
                            <button class="btn-ghost" type="submit"><?= $subscription['status'] === 'paused' ? 'Resume Service' : 'Pause Service' ?></button>
                        </form>
                        <?php if ($subscription['status'] !== 'canceled'): ?>
                        <form method="POST" onsubmit="return confirm('Cancel this subscription?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="subscription_id" value="<?= (int)$subscription['id'] ?>">
                            <input type="hidden" name="action" value="cancel_subscription">
                            <button class="btn-ghost" type="submit">Cancel Subscription</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <section class="portal-panel portal-span-6">
            <div class="portal-panel-head">
                <h2>Payment History</h2>
                <p>Your recent payment activity and saved billing methods.</p>
            </div>
            <?php if (!$payments): ?>
            <p class="portal-empty" style="margin-bottom:1rem;">No payments found.</p>
            <?php else: ?>
            <div class="portal-list" style="margin-bottom:1rem;">
                <?php foreach ($payments as $payment): ?>
                <article class="portal-item">
                    <div class="portal-item-head">
                        <div>
                            <h3 class="portal-item-title"><?= e(fmt_money($payment['amount'])) ?></h3>
                            <div class="portal-item-meta"><?= e(payment_method_label($payment['payment_method'])) ?> - <?= e(fmt_datetime($payment['created_at'])) ?></div>
                            <?php if (!empty($payment['notes'])): ?>
                            <div class="portal-item-submeta"><?= e(mb_strimwidth((string)$payment['notes'], 0, 110, '...')) ?></div>
                            <?php endif; ?>
                        </div>
                        <div><?= payment_badge((string)$payment['payment_status']) ?></div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="portal-panel-head" style="margin-top:.25rem;">
                <h2>Saved Payment Methods</h2>
                <p>Remove old cards or bank accounts you no longer use.</p>
            </div>
            <?php if (!$paymentMethods): ?>
            <p class="portal-empty">No saved payment methods.</p>
            <?php else: ?>
            <div class="portal-list">
                <?php foreach ($paymentMethods as $paymentMethod): ?>
                <article class="portal-item">
                    <div class="portal-item-head">
                        <div>
                            <h3 class="portal-item-title"><?= e(payment_method_label($paymentMethod['type'])) ?></h3>
                            <div class="portal-item-meta">
                                <?= e($paymentMethod['brand'] ?: $paymentMethod['bank_name'] ?: 'Stored billing method') ?>
                                <?php if (!empty($paymentMethod['last4'])): ?>
                                 - Ending in <?= e($paymentMethod['last4']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($paymentMethod['is_default'])): ?>
                            <div class="portal-item-submeta">Default payment method</div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($paymentMethod['is_default'])): ?>
                        <div class="portal-chip">Default</div>
                        <?php endif; ?>
                    </div>
                    <div class="portal-actions">
                        <form method="POST" onsubmit="return confirm('Remove this payment method?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="payment_method_id" value="<?= (int)$paymentMethod['id'] ?>">
                            <input type="hidden" name="action" value="detach_payment_method">
                            <button class="btn-ghost" type="submit">Remove Method</button>
                        </form>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
