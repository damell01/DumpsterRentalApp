<?php

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin');

$status = trim((string)($_GET['status'] ?? ''));
$params = [];
$where = '';
if ($status !== '') {
    $where = 'WHERE processing_status = ?';
    $params[] = $status;
}

$logs = db_fetchall(
    'SELECT * FROM webhook_logs ' . $where . ' ORDER BY id DESC LIMIT 250',
    $params
);

layout_start('Webhook Logs', 'webhooks');
?>
<div class="tp-card">
    <div class="tp-card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-plug-circle-bolt me-2 text-muted"></i>Stripe Webhook Logs</span>
        <form method="GET" action="index.php" class="d-flex gap-2 align-items-center">
            <select name="status" class="form-select form-select-sm">
                <option value="">All statuses</option>
                <?php foreach (['received', 'processed', 'duplicate', 'failed'] as $value): ?>
                <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e(ucfirst($value)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-tp-ghost btn-tp-xs">Filter</button>
        </form>
    </div>
    <div class="tp-card-body p-0">
        <?php if (!$logs): ?>
        <p class="text-muted p-3 mb-0">No webhook events logged yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table tp-table mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Entity</th>
                        <th>Last Processed</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-nowrap"><?= e($log['stripe_event_id']) ?></td>
                        <td><?= e($log['event_type']) ?></td>
                        <td><?= payment_badge($log['processing_status']) ?></td>
                        <td><?= (int)$log['attempt_count'] ?></td>
                        <td><?= e(($log['related_entity_type'] ?: '—') . ($log['related_entity_id'] ? ' #' . $log['related_entity_id'] : '')) ?></td>
                        <td><?= e(fmt_datetime($log['last_processed_at'])) ?></td>
                        <td style="max-width:260px;white-space:normal;"><?= e($log['error_message'] ?: '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
