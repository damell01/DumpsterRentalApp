<?php

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office');

$methods = db_fetchall(
    'SELECT pm.*, c.name AS customer_name, c.email AS customer_email
     FROM payment_methods pm
     INNER JOIN customers c ON c.id = pm.customer_id
     ORDER BY pm.is_default DESC, pm.updated_at DESC'
);

layout_start('Payment Methods', 'payment_methods');
?>
<div class="tp-card">
    <div class="tp-card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-wallet me-2 text-muted"></i>Saved Payment Methods</span>
        <span class="text-muted" style="font-size:.8rem;">Metadata only - no sensitive details are stored</span>
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
                        <th>Type</th>
                        <th>Brand / Bank</th>
                        <th>Last4</th>
                        <th>Verification</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($methods as $method): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= e($method['customer_name']) ?></div>
                            <div class="text-muted" style="font-size:.78rem;"><?= e($method['customer_email']) ?></div>
                        </td>
                        <td><?= e($method['type']) ?></td>
                        <td><?= e($method['brand'] ?: $method['bank_name'] ?: '—') ?></td>
                        <td><?= e($method['last4'] ?: '—') ?></td>
                        <td><?= e($method['verification_status'] ?: '—') ?></td>
                        <td>
                            <?php if ((int)$method['is_default'] === 1): ?><span class="tp-badge badge-paid">Default</span><?php endif; ?>
                            <?php if ((int)$method['is_active'] === 1): ?><span class="tp-badge badge-active">Active</span><?php else: ?><span class="tp-badge badge-canceled">Detached</span><?php endif; ?>
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
