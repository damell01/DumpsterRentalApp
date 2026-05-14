<?php
/**
 * Payment Method Setup – Admin
 * Generate a Stripe-hosted setup link for a customer (email to them or open yourself).
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INC_PATH . '/stripe.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office');

$customerId = (int)($_GET['customer_id'] ?? 0);

$customers   = db_fetchall('SELECT id, name, email, phone FROM customers ORDER BY name ASC LIMIT 500');
$hasStripe   = trim(get_setting('stripe_secret_key', '')) !== '';

$selectedCustomer = null;
$savedMethods     = [];

if ($customerId > 0) {
    $selectedCustomer = db_fetch('SELECT * FROM customers WHERE id = ? LIMIT 1', [$customerId]);
    if ($selectedCustomer) {
        $savedMethods = db_fetchall(
            'SELECT * FROM payment_methods WHERE customer_id = ? AND is_active = 1 ORDER BY is_default DESC, updated_at DESC',
            [$customerId]
        );
    }
}

layout_start('Payment Method Setup', 'payment_methods');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">Payment Method Setup</h1>
        <small style="color:var(--gl);">Collect a card or bank account for a customer</small>
    </div>
    <a href="index.php" class="btn-tp-ghost btn-tp-sm">
        <i class="fa-solid fa-arrow-left"></i> Back to Payment Methods
    </a>
</div>

<?php flash_display(); ?>

<div class="row g-4">

    <!-- Left: customer selector + saved methods -->
    <div class="col-lg-5">
        <div class="tp-card mb-4">
            <div class="tp-card-header">
                <h5 class="mb-0"><i class="fa-solid fa-user me-2"></i>Select Customer</h5>
            </div>
            <form method="GET" action="setup.php" class="mt-3">
                <div class="d-flex gap-2">
                    <select name="customer_id" class="form-select">
                        <option value="">Choose a customer…</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"
                            <?= $customerId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['name']) ?><?= $c['email'] ? ' — ' . e($c['email']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-tp-primary btn-tp-sm" style="white-space:nowrap;">
                        Select
                    </button>
                </div>
            </form>
        </div>

        <?php if ($selectedCustomer): ?>
        <div class="tp-card">
            <div class="tp-card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fa-solid fa-wallet me-2"></i>Saved Methods</h5>
                <span style="font-size:.78rem;color:var(--gl);"><?= count($savedMethods) ?> on file</span>
            </div>
            <?php if (!$savedMethods): ?>
            <div class="mt-3" style="color:var(--gl);font-size:.9rem;">
                <i class="fa-solid fa-circle-info me-1" style="color:#f59e0b;"></i>
                No saved payment methods yet. Use the setup flow on the right to collect one.
            </div>
            <?php else: ?>
            <div class="mt-3 d-flex flex-column gap-2">
                <?php foreach ($savedMethods as $pm): ?>
                <div style="background:var(--dk3);border:1px solid var(--st2);border-radius:8px;padding:.75rem 1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;">
                    <div style="display:flex;align-items:center;gap:.75rem;">
                        <?php if ($pm['type'] === 'us_bank_account'): ?>
                        <i class="fa-solid fa-building-columns" style="color:#60a5fa;font-size:1.1rem;"></i>
                        <?php else: ?>
                        <i class="fa-solid fa-credit-card" style="color:var(--or);font-size:1.1rem;"></i>
                        <?php endif; ?>
                        <div>
                            <div style="font-weight:600;font-size:.9rem;">
                                <?= e(ucfirst($pm['brand'] ?: $pm['bank_name'] ?: $pm['type'])) ?>
                                <?= $pm['last4'] ? ' •••• ' . e($pm['last4']) : '' ?>
                            </div>
                            <div style="font-size:.75rem;color:var(--gl);">
                                <?= $pm['type'] === 'card' && $pm['exp_month'] ? 'Exp ' . sprintf('%02d', $pm['exp_month']) . '/' . $pm['exp_year'] : ucfirst($pm['type']) ?>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <?php if ($pm['is_default']): ?>
                        <span class="tp-badge badge-paid">Default</span>
                        <?php endif; ?>
                        <span class="tp-badge badge-active">Active</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: setup actions -->
    <div class="col-lg-7">
        <?php if (!$hasStripe): ?>
        <div class="tp-card">
            <div style="text-align:center;padding:2rem;color:var(--gl);">
                <i class="fa-brands fa-stripe" style="font-size:2.5rem;color:#635bff;margin-bottom:1rem;display:block;"></i>
                <strong>Stripe is not configured.</strong><br>
                Go to <a href="../settings/index.php" style="color:var(--or);">Settings</a> and add your Stripe keys first.
            </div>
        </div>
        <?php elseif (!$selectedCustomer): ?>
        <div class="tp-card">
            <div style="text-align:center;padding:2.5rem;color:var(--gl);">
                <i class="fa-solid fa-arrow-left" style="font-size:1.5rem;margin-bottom:.75rem;display:block;opacity:.4;"></i>
                Select a customer on the left to get started.
            </div>
        </div>
        <?php else: ?>

        <!-- Option A: Admin opens Stripe setup page (phone order) -->
        <div class="tp-card mb-4" style="border-left:3px solid var(--or);">
            <div class="tp-card-header">
                <h5 class="mb-0">
                    <i class="fa-solid fa-phone me-2" style="color:var(--or);"></i>
                    Admin Enters Card (Phone Order)
                </h5>
            </div>
            <p style="color:var(--gl);font-size:.9rem;margin-top:.75rem;">
                Opens Stripe's secure setup page in a new tab. Read the card details from the customer over the phone and enter them yourself. The method saves to <strong><?= e($selectedCustomer['name']) ?></strong>'s account automatically.
            </p>
            <form method="POST" action="send_setup_link.php">
                <?= csrf_field() ?>
                <input type="hidden" name="customer_id" value="<?= $customerId ?>">
                <input type="hidden" name="action" value="open">
                <button type="submit" class="btn-tp-primary btn-tp-sm">
                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Open Setup Page Now
                </button>
            </form>
        </div>

        <!-- Option B: Email setup link to customer -->
        <div class="tp-card" style="border-left:3px solid #60a5fa;">
            <div class="tp-card-header">
                <h5 class="mb-0">
                    <i class="fa-solid fa-paper-plane me-2" style="color:#60a5fa;"></i>
                    Email Setup Link to Customer
                </h5>
            </div>
            <p style="color:var(--gl);font-size:.9rem;margin-top:.75rem;">
                Sends <?= e($selectedCustomer['name']) ?> a secure link to add their own card or bank account. They click it, enter their details on Stripe's page, and it automatically appears here.
            </p>
            <form method="POST" action="send_setup_link.php">
                <?= csrf_field() ?>
                <input type="hidden" name="customer_id" value="<?= $customerId ?>">
                <input type="hidden" name="action" value="email">
                <div class="mb-3">
                    <label class="form-label" style="font-size:.85rem;">Send To</label>
                    <input type="email" name="email_to" class="form-control form-control-sm"
                           value="<?= e($selectedCustomer['email'] ?? '') ?>"
                           placeholder="customer@email.com" required>
                    <?php if (!empty($selectedCustomer['email'])): ?>
                    <div style="font-size:.75rem;color:var(--gl);margin-top:.25rem;">
                        Email on file: <?= e($selectedCustomer['email']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn-tp-primary btn-tp-sm">
                    <i class="fa-solid fa-paper-plane me-1"></i> Send Setup Link
                </button>
            </form>
        </div>

        <?php endif; ?>
    </div>

</div>

<?php layout_end(); ?>
