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

// Bulk-load entity labels to avoid N+1 queries
$invIds = $bkIds = $subIds = [];
foreach ($logs as $log) {
    $id = (int)($log['related_entity_id'] ?? 0);
    if (!$id) continue;
    match ($log['related_entity_type'] ?? '') {
        'invoice'      => $invIds[$id] = $id,
        'booking'      => $bkIds[$id]  = $id,
        'subscription' => $subIds[$id] = $id,
        default        => null,
    };
}

$invLabels = $bkLabels = $subLabels = [];

if ($invIds) {
    $ph = implode(',', array_fill(0, count($invIds), '?'));
    foreach (db_fetchall(
        "SELECT i.id, i.invoice_number, c.name AS cust FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id WHERE i.id IN ($ph)",
        array_values($invIds)
    ) as $r) {
        $invLabels[(int)$r['id']] = ['n' => $r['invoice_number'] ?: ('INV-' . $r['id']), 'c' => $r['cust'] ?? ''];
    }
}

if ($bkIds) {
    $ph = implode(',', array_fill(0, count($bkIds), '?'));
    foreach (db_fetchall(
        "SELECT id, booking_number, customer_name FROM bookings WHERE id IN ($ph)",
        array_values($bkIds)
    ) as $r) {
        $bkLabels[(int)$r['id']] = ['n' => $r['booking_number'] ?: ('Booking ' . $r['id']), 'c' => $r['customer_name'] ?? ''];
    }
}

if ($subIds) {
    $ph = implode(',', array_fill(0, count($subIds), '?'));
    foreach (db_fetchall(
        "SELECT s.id, s.service_name, c.name AS cust FROM subscriptions s
         LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id IN ($ph)",
        array_values($subIds)
    ) as $r) {
        $subLabels[(int)$r['id']] = ['n' => $r['service_name'] ?: ('Subscription ' . $r['id']), 'c' => $r['cust'] ?? ''];
    }
}

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
                        <th>Event ID</th>
                        <th>Event Type</th>
                        <th>Status</th>
                        <th>Entity</th>
                        <th>Processed</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log):
                        $eType = $log['related_entity_type'] ?? '';
                        $eId   = (int)($log['related_entity_id'] ?? 0);
                        $evt   = $log['event_type'] ?? '';
                        // colour-code event type
                        if (str_contains($evt, '.paid') || str_contains($evt, '.succeeded') || str_contains($evt, 'completed')) {
                            $evtCss = 'color:#059669;font-weight:600;';
                        } elseif (str_contains($evt, 'fail') || str_contains($evt, 'deleted')) {
                            $evtCss = 'color:#dc2626;font-weight:600;';
                        } elseif (str_contains($evt, 'refund')) {
                            $evtCss = 'color:#d97706;font-weight:600;';
                        } else {
                            $evtCss = '';
                        }
                    ?>
                    <tr>
                        <td class="text-nowrap" style="font-size:.75rem;font-family:monospace;color:var(--gy);" title="<?= e($log['stripe_event_id']) ?>">
                            <?= e(substr($log['stripe_event_id'], 0, 20)) ?>…
                        </td>
                        <td style="<?= $evtCss ?>;font-size:.82rem;"><?= e($evt) ?></td>
                        <td><?= payment_badge($log['processing_status']) ?></td>
                        <td>
                            <?php if ($eType === 'invoice' && $eId && isset($invLabels[$eId])): ?>
                                <a href="<?= e(APP_URL . '/modules/invoices/view.php?id=' . $eId) ?>" class="fw-semibold"><?= e($invLabels[$eId]['n']) ?></a>
                                <?php if ($invLabels[$eId]['c']): ?><div style="font-size:.75rem;color:var(--gy);"><?= e($invLabels[$eId]['c']) ?></div><?php endif; ?>
                            <?php elseif ($eType === 'booking' && $eId && isset($bkLabels[$eId])): ?>
                                <a href="<?= e(APP_URL . '/modules/bookings/view.php?id=' . $eId) ?>" class="fw-semibold"><?= e($bkLabels[$eId]['n']) ?></a>
                                <?php if ($bkLabels[$eId]['c']): ?><div style="font-size:.75rem;color:var(--gy);"><?= e($bkLabels[$eId]['c']) ?></div><?php endif; ?>
                            <?php elseif ($eType === 'subscription' && $eId && isset($subLabels[$eId])): ?>
                                <a href="<?= e(APP_URL . '/modules/subscriptions/') ?>"><?= e($subLabels[$eId]['n']) ?></a>
                                <?php if ($subLabels[$eId]['c']): ?><div style="font-size:.75rem;color:var(--gy);"><?= e($subLabels[$eId]['c']) ?></div><?php endif; ?>
                            <?php elseif ($eType && $eId): ?>
                                <span><?= e(ucfirst($eType)) ?> #<?= $eId ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.82rem;"><?= e(fmt_datetime($log['last_processed_at'])) ?></td>
                        <td style="max-width:280px;white-space:normal;font-size:.8rem;"><?= e($log['error_message'] ?: '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
