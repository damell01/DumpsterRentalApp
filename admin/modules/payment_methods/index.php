<?php

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office');

require_once INC_PATH . '/stripe.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (trim($_POST['action'] ?? '') === 'detach') {
        try {
            billing_payment_method_service()->detach(
                (int)($_POST['customer_id'] ?? 0),
                (int)($_POST['id'] ?? 0)
            );
            flash_success('Payment method removed.');
        } catch (\Throwable $e) {
            flash_error('Could not remove payment method: ' . $e->getMessage());
        }
    }
    redirect('index.php');
}

$methods = db_fetchall(
    'SELECT pm.*, c.name AS customer_name, c.email AS customer_email
     FROM payment_methods pm
     INNER JOIN customers c ON c.id = pm.customer_id
     ORDER BY pm.is_default DESC, pm.updated_at DESC'
);

$duplicateKeys = [];
$potentialDupes = [];
foreach ($methods as $m) {
    if (!$m['is_active'] || !$m['last4']) {
        continue;
    }
    $key = $m['customer_id'] . '|' . $m['type'] . '|' . $m['brand'] . '|' . $m['last4'];
    $potentialDupes[$key][] = (int)$m['id'];
}
foreach ($potentialDupes as $key => $ids) {
    if (count($ids) > 1) {
        foreach ($ids as $dupId) {
            $duplicateKeys[$dupId] = true;
        }
    }
}

layout_start('Payment Methods', 'payment_methods');
?>

<?php if (!empty($_GET['setup_success'])): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-4" role="alert">
    <i class="fa-solid fa-circle-check"></i>
    Payment method saved successfully. It may take a moment to appear below.
</div>
<?php endif; ?>

<?php flash_display(); ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h3 mb-0">Payment Methods</h1>
    <a href="setup.php" class="btn-tp-primary btn-tp-sm">
        <i class="fa-solid fa-plus me-1"></i> Set Up Payment for Customer
    </a>
</div>

<div class="tp-card">
    <div class="tp-card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-wallet me-2 text-muted"></i>Saved Payment Methods</span>
        <span class="text-muted" style="font-size:.8rem;">Metadata only — no sensitive details are stored</span>
    </div>
    <div class="tp-card-body p-0">
        <?php if (!$methods): ?>
        <p class="text-muted p-3 mb-0">No saved payment methods have been synced yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table tp-table mb-0">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Method</th>
                        <th>Details</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($methods as $method): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= e($method['customer_name']) ?></div>
                            <div style="font-size:.78rem;color:var(--gl);"><?= e($method['customer_email']) ?></div>
                        </td>
                        <td>
                            <?php if ($method['type'] === 'us_bank_account'): ?>
                            <i class="fa-solid fa-building-columns me-1" style="color:#60a5fa;"></i> Bank / ACH
                            <?php else: ?>
                            <i class="fa-solid fa-credit-card me-1" style="color:var(--or);"></i>
                            <?= e(ucfirst($method['brand'] ?: 'Card')) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($method['last4']): ?>
                            <span style="letter-spacing:.05em;">•••• <?= e($method['last4']) ?></span>
                            <?php endif; ?>
                            <?php if ($method['type'] === 'card' && $method['exp_month']): ?>
                            <div style="font-size:.75rem;color:var(--gl);">
                                Exp <?= sprintf('%02d', $method['exp_month']) ?>/<?= e($method['exp_year']) ?>
                            </div>
                            <?php elseif ($method['bank_name']): ?>
                            <div style="font-size:.75rem;color:var(--gl);"><?= e($method['bank_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$method['is_default'] === 1): ?>
                            <span class="tp-badge badge-paid">Default</span>
                            <?php endif; ?>
                            <?php if ((int)$method['is_active'] === 1): ?>
                            <span class="tp-badge badge-active">Active</span>
                            <?php else: ?>
                            <span class="tp-badge badge-canceled">Detached</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if (!empty($duplicateKeys[(int)$method['id']])): ?>
                            <span class="tp-badge badge-warning me-1" title="Possible duplicate — same card on file">Duplicate?</span>
                            <?php endif; ?>
                            <a href="setup.php?customer_id=<?= (int)$method['customer_id'] ?>"
                               class="btn-tp-ghost btn-tp-xs me-1" title="Manage payment methods for this customer">
                                <i class="fa-solid fa-gear"></i>
                            </a>
                            <?php if ((int)$method['is_active'] === 1): ?>
                            <form method="POST" action="index.php" class="d-inline"
                                  onsubmit="return confirm('Remove this payment method? This cannot be undone.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="detach">
                                <input type="hidden" name="id" value="<?= (int)$method['id'] ?>">
                                <input type="hidden" name="customer_id" value="<?= (int)$method['customer_id'] ?>">
                                <button type="submit" class="btn-tp-danger btn-tp-xs" title="Remove payment method">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
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
<?php layout_end(); ?>
