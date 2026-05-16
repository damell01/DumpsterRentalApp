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
                    <td style="max-width:280px;">
                        <div class="d-flex align-items-center gap-2">
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0;">
                                <?= e($notif['subject']) ?>
                            </span>
                            <?php if (!empty($notif['body'])): ?>
                            <button type="button"
                                    class="btn-tp-ghost btn-tp-xs preview-btn flex-shrink-0"
                                    data-id="<?= (int)$notif['id'] ?>"
                                    title="Preview message">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                            <?php endif; ?>
                        </div>
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

<!-- ── Message Preview Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="background:var(--dark2,#161b27);border:1px solid var(--steel,#374151);">
            <div class="modal-header" style="border-bottom:1px solid var(--steel,#374151);">
                <div style="min-width:0;flex:1;">
                    <h6 class="modal-title mb-0 text-truncate" id="previewModalLabel">Loading…</h6>
                    <div id="previewMeta" style="font-size:.78rem;color:var(--gy,#9ca3af);margin-top:.2rem;"></div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-3 flex-shrink-0" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="min-height:300px;">
                <div id="previewLoading" class="d-flex align-items-center justify-content-center py-5">
                    <i class="fa-solid fa-spinner fa-spin me-2" style="color:var(--or);"></i>
                    <span style="color:var(--gy);">Loading…</span>
                </div>
                <iframe id="previewFrame"
                        srcdoc=""
                        style="width:100%;height:600px;border:0;display:none;background:#f1f3f4;"
                        sandbox="allow-same-origin"
                        title="Email preview"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var modal     = null;
    var currentId = null;

    function esc(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function buildDoc(data) {
        var raw     = data.body || '';
        // Extract body content from a full HTML doc if present
        var match   = raw.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
        var content = match ? match[1] : raw;

        var company = esc(data.company_name || 'Trash Panda Roll-Offs');
        var initial = esc((data.company_name || 'T').charAt(0).toUpperCase());

        return [
            '<!DOCTYPE html><html lang="en"><head>',
            '<meta charset="UTF-8">',
            '<meta name="viewport" content="width=device-width,initial-scale=1">',
            '<style>',
            '*{box-sizing:border-box;}',
            'body{margin:0;padding:0;background:#f1f3f4;font-family:Roboto,Arial,sans-serif;}',
            '.gc{background:#fff;padding:16px 24px 0;}',
            '.gs{font-size:22px;font-weight:400;color:#202124;margin:0 0 14px;line-height:1.3;word-break:break-word;}',
            '.gr{display:flex;align-items:flex-start;gap:12px;padding-bottom:14px;}',
            '.ga{width:40px;height:40px;min-width:40px;border-radius:50%;background:#f97316;',
            '    display:flex;align-items:center;justify-content:center;',
            '    color:#fff;font-size:18px;font-weight:500;}',
            '.gf{font-size:14px;font-weight:600;color:#202124;}',
            '.gt{font-size:12px;color:#5f6368;margin-top:2px;}',
            '.gd{margin-left:auto;font-size:12px;color:#5f6368;white-space:nowrap;padding-top:2px;}',
            'hr{border:none;border-top:1px solid #e0e0e0;margin:0;}',
            '.gb{padding:20px 24px 32px;background:#fff;}',
            '</style></head><body>',
            '<div class="gc">',
            '  <h1 class="gs">' + esc(data.subject || '(no subject)') + '</h1>',
            '  <div class="gr">',
            '    <div class="ga">' + initial + '</div>',
            '    <div>',
            '      <div class="gf">' + company + '</div>',
            '      <div class="gt">to ' + esc(data.recipient || '') + '</div>',
            '    </div>',
            '    <div class="gd">' + esc(data.sent_at || '') + '</div>',
            '  </div>',
            '</div>',
            '<hr>',
            '<div class="gb">' + content + '</div>',
            '</body></html>'
        ].join('\n');
    }

    function showPreview(id) {
        if (!modal) {
            modal = new bootstrap.Modal(document.getElementById('previewModal'));
        }
        currentId = id;
        document.getElementById('previewModalLabel').textContent = 'Loading…';
        document.getElementById('previewMeta').textContent       = '';
        document.getElementById('previewLoading').style.display  = '';
        document.getElementById('previewFrame').style.display    = 'none';
        document.getElementById('previewFrame').srcdoc           = '';
        modal.show();

        fetch('preview.php?id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (id !== currentId) return;
                document.getElementById('previewModalLabel').textContent = data.subject || '(no subject)';
                var parts = [data.recipient, data.sent_at];
                if (data.status) parts.push(data.status.charAt(0).toUpperCase() + data.status.slice(1));
                document.getElementById('previewMeta').textContent = parts.filter(Boolean).join('  ·  ');
                document.getElementById('previewLoading').style.display = 'none';
                document.getElementById('previewFrame').srcdoc          = buildDoc(data);
                document.getElementById('previewFrame').style.display   = '';
            })
            .catch(function () {
                if (id !== currentId) return;
                document.getElementById('previewLoading').style.display = 'none';
                document.getElementById('previewFrame').srcdoc = buildDoc({
                    subject: 'Error', body: '<p style="color:red;font-family:sans-serif;">Failed to load preview.</p>',
                    company_name: '', recipient: '', sent_at: '',
                });
                document.getElementById('previewFrame').style.display = '';
            });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.preview-btn');
        if (btn) showPreview(btn.dataset.id);
    });
}());
</script>

<?php layout_end(); ?>
