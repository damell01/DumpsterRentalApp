<?php
/**
 * Leads - Website quote/contact requests
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';

require_login();
require_role('admin', 'office');

$status_filter = trim($_GET['status'] ?? '');
$q = trim($_GET['q'] ?? '');
$page_num = max(1, (int)($_GET['page'] ?? 1));

$valid_statuses = ['new', 'contacted', 'quoted', 'won', 'lost'];

$where = ['archived = 0'];
$params = [];

if ($status_filter !== '' && in_array($status_filter, $valid_statuses, true)) {
    $where[] = 'status = ?';
    $params[] = $status_filter;
}

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ? OR address LIKE ? OR project_type LIKE ? OR message LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$count_row = db_fetch("SELECT COUNT(*) AS cnt FROM leads $where_sql", $params);
$total = (int)($count_row['cnt'] ?? 0);
$pag = paginate($total, $page_num, 25);

$leads = db_fetchall(
    "SELECT *
     FROM leads
     $where_sql
     ORDER BY created_at DESC
     LIMIT {$pag['per_page']} OFFSET {$pag['offset']}",
    $params
);

$status_counts = [];
$count_rows = db_fetchall("SELECT status, COUNT(*) AS cnt FROM leads WHERE archived = 0 GROUP BY status");
foreach ($count_rows as $row) {
    $status_counts[strtolower((string)$row['status'])] = (int)$row['cnt'];
}

$tabs = [
    ''          => ['label' => 'All',       'count' => array_sum($status_counts)],
    'new'       => ['label' => 'New',       'count' => $status_counts['new'] ?? 0],
    'contacted' => ['label' => 'Contacted', 'count' => $status_counts['contacted'] ?? 0],
    'quoted'    => ['label' => 'Quoted',    'count' => $status_counts['quoted'] ?? 0],
    'won'       => ['label' => 'Won',       'count' => $status_counts['won'] ?? 0],
    'lost'      => ['label' => 'Lost',      'count' => $status_counts['lost'] ?? 0],
];

layout_start('Leads', 'leads');
?>

<div class="tp-page-header d-flex align-items-center justify-content-between mb-3">
    <div>
        <h2 class="tp-page-title mb-0">Leads</h2>
        <p class="tp-page-sub mb-0">Website quote and contact requests coming into the system.</p>
    </div>
    <span class="text-muted" style="font-size:.85rem;"><?= (int)$total ?> total</span>
</div>

<div class="tp-filter-bar tp-filter-bar-compact mb-3">
    <div class="filter-tabs">
        <?php foreach ($tabs as $key => $tab):
            $is_active = ($status_filter === $key);
            $args = array_filter(['status' => $key, 'q' => $q]);
            $href = APP_URL . '/modules/leads/index.php' . ($args ? '?' . http_build_query($args) : '');
        ?>
        <a href="<?= e($href) ?>" class="filter-tab <?= $is_active ? 'active' : '' ?>">
            <?= e($tab['label']) ?>
            <span class="tab-count"><?= (int)$tab['count'] ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="tp-filter-search">
        <form method="get" action="<?= APP_URL ?>/modules/leads/index.php" class="tp-filter-search">
            <?php if ($status_filter !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status_filter) ?>">
            <?php endif; ?>

            <input type="text"
                   name="q"
                   value="<?= e($q) ?>"
                   placeholder="Search name, phone, email, project, message..."
                   class="tp-search form-control form-control-sm">

            <button type="submit" class="btn-tp-primary btn-tp-sm">
                <i class="fa-solid fa-magnifying-glass"></i> Search
            </button>
            <?php if ($q !== ''): ?>
            <a href="<?= APP_URL ?>/modules/leads/index.php<?= $status_filter ? '?status=' . urlencode($status_filter) : '' ?>"
               class="btn-tp-ghost btn-tp-sm">
                <i class="fa-solid fa-xmark"></i> Clear
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="tp-card">
    <?php if (!$leads): ?>
    <div class="tp-empty-state py-5 text-center">
        <i class="fa-solid fa-user-plus fa-2x mb-3" style="color:var(--gy);"></i>
        <p class="mb-0">No leads found<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="tp-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Project</th>
                    <th>Status</th>
                    <th>Source</th>
                    <th>Message</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $lead): ?>
                <tr>
                    <td>
                        <strong><?= e($lead['name'] ?? '—') ?></strong>
                        <?php if (!empty($lead['address'])): ?>
                        <br><small class="text-muted"><?= e($lead['address']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($lead['phone'])): ?>
                        <?= e(fmt_phone($lead['phone'])) ?>
                        <?php endif; ?>
                        <?php if (!empty($lead['email'])): ?>
                        <br><small class="text-muted"><?= e($lead['email']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e($lead['size_needed'] ?: '—') ?>
                        <?php if (!empty($lead['project_type'])): ?>
                        <br><small class="text-muted"><?= e($lead['project_type']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string)($lead['status'] ?? 'new')) ?></td>
                    <td><?= e($lead['source'] ?: 'Website') ?></td>
                    <td style="max-width:320px;">
                        <?php if (!empty($lead['message'])): ?>
                        <span class="tp-text-tooltip" title="<?= e($lead['message']) ?>">
                            <?= e(mb_strimwidth((string)$lead['message'], 0, 90, '…')) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span title="<?= e(fmt_datetime($lead['created_at'])) ?>">
                            <?= e(fmt_date($lead['created_at'])) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                            <?php if (!empty($lead['phone'])): ?>
                            <a href="tel:<?= e($lead['phone']) ?>" class="btn-tp-ghost btn-tp-sm" title="Call">
                                <i class="fa-solid fa-phone"></i> Call
                            </a>
                            <?php endif; ?>
                            <?php if (!empty($lead['email'])): ?>
                            <a href="mailto:<?= e($lead['email']) ?>" class="btn-tp-ghost btn-tp-sm" title="Email">
                                <i class="fa-solid fa-envelope"></i> Email
                            </a>
                            <?php endif; ?>
                            <a href="<?= APP_URL ?>/modules/customers/create.php?lead_id=<?= (int)$lead['id'] ?>"
                               class="btn-tp-primary btn-tp-sm" title="Convert to customer">
                                <i class="fa-solid fa-user-check"></i> Convert
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pag['pages'] > 1): ?>
    <div class="tp-pagination d-flex align-items-center justify-content-between px-3 py-2 border-top">
        <small class="text-muted">
            Showing <?= ($pag['offset'] + 1) ?>–<?= min($pag['offset'] + $pag['per_page'], $total) ?>
            of <?= $total ?> leads
        </small>
        <div class="d-flex gap-1">
            <?php for ($p = 1; $p <= $pag['pages']; $p++):
                $p_args = array_filter(['status' => $status_filter, 'q' => $q]);
                $p_args['page'] = $p;
                $href = APP_URL . '/modules/leads/index.php?' . http_build_query($p_args);
            ?>
            <a href="<?= e($href) ?>" class="btn-tp-ghost btn-tp-sm <?= $p === $pag['page'] ? 'active' : '' ?>">
                <?= $p ?>
            </a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php layout_end(); ?>
