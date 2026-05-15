<?php
/**
 * Invoices – List
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();

$pdo = get_db();

// ── Filter ────────────────────────────────────────────────────────────────────
$filter = trim($_GET['filter'] ?? '');
$valid_filters = ['draft', 'sent', 'paid', 'void'];

$where  = ['1=1'];
$params = [];

if (in_array($filter, $valid_filters, true)) {
    $where[]  = 'i.status = ?';
    $params[] = $filter;
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $where[]  = '(i.invoice_number LIKE ? OR i.cust_name LIKE ? OR i.cust_email LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page   = 25;
$page       = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($page - 1) * $per_page;

$total_count = (int)db_fetch("SELECT COUNT(*) AS cnt FROM invoices i $where_sql", $params)['cnt'];
$pages       = max(1, (int)ceil($total_count / $per_page));

$invoices = db_fetchall(
    "SELECT i.*,
            c.name AS customer_name_linked
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     $where_sql
     ORDER BY i.created_at DESC
     LIMIT $per_page OFFSET $offset",
    $params
);

// ── Tab counts ────────────────────────────────────────────────────────────────
$counts = [];
foreach (db_fetchall("SELECT status, COUNT(*) AS cnt FROM invoices GROUP BY status") as $r) {
    $counts[$r['status']] = (int)$r['cnt'];
}
$counts[''] = array_sum($counts);

layout_start('Invoices', 'invoices');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Invoices</h5>
    <?php if (has_role('admin', 'office')): ?>
    <a href="create.php" class="btn-tp-primary btn-tp-sm">
        <i class="fa-solid fa-plus"></i> New Invoice
    </a>
    <?php endif; ?>
</div>

<!-- Filter tabs -->
<div class="filter-bar mb-3">
    <?php
    $tab_defs = [
        ''      => 'All',
        'draft' => 'Draft',
        'sent'  => 'Sent',
        'paid'  => 'Paid',
        'void'  => 'Void',
    ];
    foreach ($tab_defs as $key => $label):
        $active = $filter === $key ? ' active' : '';
        $cnt = $counts[$key] ?? 0;
    ?>
    <a href="?filter=<?= urlencode($key) ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
       class="filter-tab<?= $active ?>">
        <?= e($label) ?>
        <span class="tp-badge"><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>

    <!-- Search -->
    <form method="get" action="" class="ms-auto d-flex gap-2" style="max-width:280px;">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <input type="text" name="q" value="<?= e($search) ?>"
               class="form-control form-control-sm"
               placeholder="Search invoice # or customer…">
        <button type="submit" class="btn-tp-primary btn-tp-sm">
            <i class="fa-solid fa-magnifying-glass"></i> Search
        </button>
        <?php if ($search): ?>
        <a href="?filter=<?= e($filter) ?>" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
    </form>
</div>

<div class="tp-card">
    <?php if (empty($invoices)): ?>
        <p class="text-muted p-3 mb-0">No invoices found.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table tp-table mb-0">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Customer</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th class="d-none d-lg-table-cell">Due Date</th>
                    <th class="d-none d-md-table-cell">Payment Link</th>
                    <th class="d-none d-md-table-cell">Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($invoices as $inv): ?>
                <tr data-href="view.php?id=<?= (int)$inv['id'] ?>">
                    <td>
                        <a href="view.php?id=<?= (int)$inv['id'] ?>" style="font-weight:600;">
                            <?= e($inv['invoice_number']) ?>
                        </a>
                    </td>
                    <td><?= e($inv['cust_name']) ?></td>
                    <td><?= e(fmt_money($inv['total'] ?? 0)) ?></td>
                    <td><?= status_badge($inv['status'] ?? 'draft') ?></td>
                    <td class="d-none d-lg-table-cell"><?= $inv['due_date'] ? e(fmt_date($inv['due_date'])) : '<span class="text-muted">—</span>' ?></td>
                    <td class="d-none d-md-table-cell">
                        <?php if (!empty($inv['stripe_payment_link'])): ?>
                            <a href="<?= e($inv['stripe_payment_link']) ?>" target="_blank"
                               class="btn-tp-ghost btn-tp-xs" title="Open payment link">
                                <i class="fa-solid fa-link"></i> Pay Link
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell"><?= e(fmt_date($inv['created_at'])) ?></td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <a href="view.php?id=<?= (int)$inv['id'] ?>"
                               class="btn-tp-ghost btn-tp-sm" title="View">
                                <i class="fa-solid fa-eye"></i> View
                            </a>
                            <?php if (has_role('admin', 'office')): ?>
                            <a href="edit.php?id=<?= (int)$inv['id'] ?>"
                               class="btn-tp-ghost btn-tp-sm" title="Edit">
                                <i class="fa-solid fa-pencil"></i> Edit
                            </a>
                            <a href="delete.php?id=<?= (int)$inv['id'] ?>"
                               class="btn-tp-ghost btn-tp-sm text-danger" title="Delete"
                               data-confirm="Delete invoice <?= e($inv['invoice_number']) ?>? This cannot be undone.">
                                <i class="fa-solid fa-trash"></i> Delete
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div class="tp-pagination d-flex align-items-center justify-content-between px-3 py-2 border-top">
        <small class="text-muted">
            Showing <?= number_format(($page - 1) * $per_page + 1) ?>–<?= number_format(min($page * $per_page, $total_count)) ?>
            of <?= number_format($total_count) ?>
        </small>
        <div class="d-flex gap-1">
            <?php if ($page > 1): ?>
            <a href="?filter=<?= e($filter) ?>&q=<?= e($search) ?>&page=<?= $page - 1 ?>"
               class="btn-tp-ghost btn-tp-xs">‹ Prev</a>
            <?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
            <a href="?filter=<?= e($filter) ?>&q=<?= e($search) ?>&page=<?= $p ?>"
               class="btn-tp-ghost btn-tp-xs<?= $p === $page ? ' active' : '' ?>">
                <?= $p ?>
            </a>
            <?php endfor; ?>
            <?php if ($page < $pages): ?>
            <a href="?filter=<?= e($filter) ?>&q=<?= e($search) ?>&page=<?= $page + 1 ?>"
               class="btn-tp-ghost btn-tp-xs">Next ›</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php layout_end(); ?>
