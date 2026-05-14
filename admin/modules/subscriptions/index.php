<?php

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INC_PATH . '/stripe.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim((string)($_POST['action'] ?? 'create'));

    try {
        if ($action === 'create') {
            $subscriptionId = stripe_create_subscription([
                'customer_id' => (int)($_POST['customer_id'] ?? 0),
                'service_name' => trim((string)($_POST['service_name'] ?? 'Recurring Dumpster Service')),
                'service_address' => trim((string)($_POST['service_address'] ?? '')),
                'amount' => (float)($_POST['amount'] ?? 0),
                'interval_unit' => trim((string)($_POST['interval_unit'] ?? 'month')),
                'interval_count' => (int)($_POST['interval_count'] ?? 1),
                'billing_anchor' => trim((string)($_POST['billing_anchor'] ?? '')),
                'stripe_payment_method_id' => trim((string)($_POST['stripe_payment_method_id'] ?? '')),
                'autopay_enabled' => !empty($_POST['autopay_enabled']),
            ]);
            flash_success('Subscription #' . $subscriptionId . ' created successfully.');
        } elseif ($action === 'pause') {
            stripe_pause_subscription((int)($_POST['id'] ?? 0));
            flash_success('Subscription paused.');
        } elseif ($action === 'resume') {
            stripe_resume_subscription((int)($_POST['id'] ?? 0));
            flash_success('Subscription resumed.');
        } elseif ($action === 'cancel') {
            stripe_cancel_subscription((int)($_POST['id'] ?? 0));
            flash_success('Subscription canceled.');
        } elseif ($action === 'retry') {
            stripe_retry_subscription_invoice((int)($_POST['id'] ?? 0));
            flash_success('Latest subscription invoice retry triggered.');
        }
    } catch (Throwable $e) {
        flash_error('Subscription error: ' . $e->getMessage());
    }

    redirect('index.php');
}

$subscriptions = db_fetchall(
    'SELECT s.*, c.name AS customer_name, c.email AS customer_email,
            (SELECT COUNT(*) FROM payment_methods pm WHERE pm.customer_id = s.customer_id AND pm.is_active = 1) AS saved_method_count
     FROM subscriptions s
     INNER JOIN customers c ON c.id = s.customer_id
     ORDER BY s.updated_at DESC, s.id DESC'
);
$customers = db_fetchall('SELECT id, name, email FROM customers ORDER BY name ASC LIMIT 200');
$paymentMethods = db_fetchall(
    'SELECT pm.*, c.name AS customer_name
     FROM payment_methods pm
     INNER JOIN customers c ON c.id = pm.customer_id
     WHERE pm.is_active = 1
     ORDER BY pm.is_default DESC, pm.updated_at DESC'
);

layout_start('Subscriptions', 'subscriptions');
?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="tp-card">
            <div class="tp-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="fa-solid fa-arrows-rotate me-2 text-muted"></i>Recurring Subscriptions</span>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?php if (trim(get_setting('stripe_secret_key', '')) !== ''): ?>
                    <form method="POST" action="../dumpsters/sync_all_stripe.php" class="d-inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-tp-ghost btn-tp-xs"
                                onclick="return confirm('Sync inventory catalog to Stripe? This updates dumpster products plus recurring prices used for subscription billing.')">
                            <i class="fa-brands fa-stripe me-1"></i> Sync Pricing Catalog
                        </button>
                    </form>
                    <?php endif; ?>
                    <span class="text-muted" style="font-size:.8rem;"><?= count($subscriptions) ?> total</span>
                </div>
            </div>
            <div class="tp-card-body p-0">
                <div class="px-3 pt-3">
                    <div class="alert alert-info py-2 px-3 mb-3" role="alert">
                        Subscription pricing comes from your in-app catalog. Use <strong>Sync Pricing Catalog</strong> after changing dumpster pricing so Stripe stays aligned.
                    </div>
                </div>
                <?php if (!$subscriptions): ?>
                <p class="text-muted p-3 mb-0">No subscriptions created yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table tp-table mb-0">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Service</th>
                                <th>Amount</th>
                                <th>Cycle</th>
                                <th>Next Bill</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subscriptions as $subscription): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($subscription['customer_name']) ?></div>
                                    <div class="text-muted" style="font-size:.78rem;"><?= e($subscription['customer_email']) ?></div>
                                </td>
                                <td>
                                    <div><?= e($subscription['service_name']) ?></div>
                                    <?php if (!empty($subscription['service_address'])): ?>
                                    <div class="text-muted" style="font-size:.78rem;"><?= e($subscription['service_address']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold"><?= e(fmt_money($subscription['amount'])) ?></td>
                                <td>Every <?= (int)$subscription['interval_count'] . ' ' . e($subscription['interval_unit']) . ((int)$subscription['interval_count'] === 1 ? '' : 's') ?></td>
                                <td><?= e(fmt_date($subscription['next_billing_date'])) ?></td>
                                <td><?= subscription_badge($subscription['status']) ?></td>
                                <td class="text-nowrap">
                                    <?php if ((int)$subscription['saved_method_count'] === 0 && $subscription['status'] !== 'canceled'): ?>
                                    <a href="../payment_methods/setup.php?customer_id=<?= (int)$subscription['customer_id'] ?>"
                                       class="btn-tp-danger btn-tp-xs me-1" title="No payment method on file">
                                        <i class="fa-solid fa-triangle-exclamation me-1"></i>Set Up Payment
                                    </a>
                                    <?php endif; ?>
                                    <form method="POST" action="index.php" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$subscription['id'] ?>">
                                        <?php if ($subscription['status'] === 'paused'): ?>
                                        <input type="hidden" name="action" value="resume">
                                        <button type="submit" class="btn-tp-ghost btn-tp-xs">Resume</button>
                                        <?php elseif ($subscription['status'] !== 'canceled'): ?>
                                        <input type="hidden" name="action" value="pause">
                                        <button type="submit" class="btn-tp-ghost btn-tp-xs">Pause</button>
                                        <?php endif; ?>
                                    </form>
                                    <?php if ($subscription['status'] !== 'canceled'): ?>
                                    <form method="POST" action="index.php" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$subscription['id'] ?>">
                                        <input type="hidden" name="action" value="retry">
                                        <button type="submit" class="btn-tp-ghost btn-tp-xs">Retry</button>
                                    </form>
                                    <form method="POST" action="index.php" class="d-inline" onsubmit="return confirm('Cancel this subscription?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$subscription['id'] ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <button type="submit" class="btn-tp-danger btn-tp-xs">Cancel</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="tp-card mb-4" style="border-left:3px solid #60a5fa;">
            <div class="tp-card-header">
                <h5 class="mb-0"><i class="fa-solid fa-wallet me-2" style="color:#60a5fa;"></i>Step 1 — Payment Method</h5>
            </div>
            <p style="color:var(--gl);font-size:.88rem;margin-top:.75rem;">
                The customer needs a saved card or bank account before Stripe can auto-charge them on a recurring basis.
            </p>
            <a href="../payment_methods/setup.php" class="btn-tp-primary btn-tp-sm w-100">
                <i class="fa-solid fa-credit-card me-1"></i> Set Up Payment for Customer
            </a>
        </div>
        <div class="tp-card">
            <div class="tp-card-header"><i class="fa-solid fa-plus me-2 text-muted"></i>Step 2 — Create Subscription</div>
            <div class="tp-card-body">
                <form method="POST" action="index.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" class="form-select" required>
                            <option value="">Select customer…</option>
                            <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int)$customer['id'] ?>"><?= e($customer['name']) ?><?= $customer['email'] ? ' - ' . e($customer['email']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Service Name</label>
                        <input type="text" name="service_name" class="form-control" value="Recurring Dumpster Service" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Service Address</label>
                        <input type="text" name="service_address" class="form-control" placeholder="Optional service location">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Amount</label>
                            <input type="number" step="0.01" min="1" name="amount" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Anchor Date</label>
                            <input type="date" name="billing_anchor" class="form-control">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Interval</label>
                            <select name="interval_unit" class="form-select">
                                <option value="week">Week</option>
                                <option value="month" selected>Month</option>
                                <option value="year">Year</option>
                                <option value="day">Day</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Count</label>
                            <input type="number" min="1" name="interval_count" class="form-control" value="1">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Default Saved Method</label>
                        <select name="stripe_payment_method_id" class="form-select">
                            <option value="">Use Stripe customer default</option>
                            <?php foreach ($paymentMethods as $paymentMethod): ?>
                            <option value="<?= e($paymentMethod['stripe_payment_method_id']) ?>">
                                <?= e($paymentMethod['customer_name']) ?> - <?= e(strtoupper($paymentMethod['type'])) ?> <?= e($paymentMethod['last4'] ? '•••• ' . $paymentMethod['last4'] : '') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="autopay_enabled" id="autopay_enabled" checked>
                        <label class="form-check-label" for="autopay_enabled">Enable autopay</label>
                    </div>
                    <button type="submit" class="btn-tp-primary w-100">
                        <i class="fa-solid fa-plus"></i> Create Subscription
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php layout_end(); ?>
