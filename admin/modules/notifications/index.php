<?php
/**
 * Notifications – Log List
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();

// Mark all unseen notifications as seen when this page is visited
try {
    db_execute("UPDATE notifications SET seen_at = ? WHERE seen_at IS NULL", [date('Y-m-d H:i:s')]);
} catch (\Throwable $e) {
    // Non-fatal — badge is cosmetic
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_type   = trim($_GET['type']   ?? '');
$filter_status = trim($_GET['status'] ?? '');

$where  = ['1=1'];
$params = [];

if ($filter_type !== '') {
    $where[]  = 'type = ?';
    $params[] = $filter_type;
}
if ($filter_status !== '') {
    $where[]  = 'status = ?';
    $params[] = $filter_status;
}

$where_sql = implode(' AND ', $where);

$total_row = db_fetch("SELECT COUNT(*) AS cnt FROM notifications WHERE $where_sql", $params);
$total     = (int)($total_row['cnt'] ?? 0);
$pager     = paginate($total, (int)($_GET['page'] ?? 1), 30);

$notifications = db_fetchall(
    "SELECT * FROM notifications WHERE $where_sql
     ORDER BY created_at DESC
     LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
    $params
);

layout_start('Notifications', 'notifications');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Notification Log</h5>
    <a href="send.php" class="btn-tp-ghost btn-tp-sm">
        <i class="fa-solid fa-paper-plane"></i> Manual Send
    </a>
</div>

<!-- Filters -->
<form method="GET" class="tp-card mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label form-label-sm">Type</label>
            <select name="type" class="form-select form-select-sm">
                <option value="">All Types</option>
                <option value="email" <?= $filter_type === 'email' ? 'selected' : '' ?>>Email</option>
                <option value="sms"   <?= $filter_type === 'sms'   ? 'selected' : '' ?>>SMS</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label form-label-sm">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <?php foreach (['queued','sent','failed'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $filter_status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn-tp-primary btn-tp-sm">Filter</button>
            <a href="index.php" class="btn-tp-ghost btn-tp-sm">Reset</a>
        </div>
    </div>
</form>

<!-- Table -->
<div class="tp-card p-0">
    <div class="table-responsive">
        <table class="table tp-table mb-0">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Recipient</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Related</th>
                    <th>Sent At</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($notifications)): ?>
                <tr><td colspan="6" class="text-center py-4" style="color:var(--gy);">No notifications found.</td></tr>
            <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
            <?php
                $type_icon  = $notif['type'] === 'email' ? 'fa-envelope' : 'fa-comment-sms';
                $status_map = ['queued' => 'warning', 'sent' => 'success', 'failed' => 'danger'];
                $status_col = $status_map[$notif['status']] ?? 'secondary';
            ?>
                <tr>
                    <td>
                        <i class="fa-solid <?= e($type_icon) ?>" style="color:var(--or);"></i>
                        <?= e(ucfirst($notif['type'])) ?>
                    </td>
                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= e($notif['recipient']) ?>
                    </td>
                    <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= e($notif['subject']) ?>
                    </td>
                    <td><span class="badge bg-<?= $status_col ?>"><?= e(ucfirst($notif['status'])) ?></span></td>
                    <td>
                        <?php if ($notif['related_type'] && $notif['related_id']): ?>
                        <?php
                            $link_url = '#';
                            if ($notif['related_type'] === 'work_order') {
                                $link_url = APP_URL . '/modules/work_orders/view.php?id=' . (int)$notif['related_id'];
                            }
                        ?>
                        <a href="<?= e($link_url) ?>"><?= e(ucfirst(str_replace('_',' ',$notif['related_type']))) ?> #<?= (int)$notif['related_id'] ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><?= fmt_datetime($notif['sent_at'] ?: $notif['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($pager['pages'] > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php for ($p = 1; $p <= $pager['pages']; $p++): ?>
        <li class="page-item <?= $p === $pager['page'] ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $p ?>&type=<?= urlencode($filter_type) ?>&status=<?= urlencode($filter_status) ?>">
                <?= $p ?>
            </a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php layout_end(); ?>
