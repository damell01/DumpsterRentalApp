<?php
/**
 * Customers - Index / Listing
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';

require_login();

$type_filter = trim($_GET['type'] ?? '');
$q           = trim($_GET['q'] ?? '');
$page_num    = max(1, (int)($_GET['page'] ?? 1));

$valid_types = ['residential', 'commercial', 'contractor'];

$where  = ['1=1'];
$params = [];

if ($type_filter !== '' && in_array(strtolower($type_filter), $valid_types, true)) {
    $where[]  = 'c.type = ?';
    $params[] = strtolower($type_filter);
}

if ($q !== '') {
    $like     = '%' . $q . '%';
    $where[]  = '(c.name LIKE ? OR c.company LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$count_sql = "SELECT COUNT(*) AS cnt FROM customers c $where_sql";
$count_row = db_try_fetch($count_sql, $params, ['cnt' => 0]);
$total     = (int)($count_row['cnt'] ?? 0);
$pag       = paginate($total, $page_num, 25);

$customers = [];
$can_count_work_orders = db_table_exists('work_orders') && db_column_exists('work_orders', 'customer_id');

if ($can_count_work_orders) {
    $customers = db_try_fetchall(
        "SELECT c.*, COUNT(wo.id) AS wo_count
         FROM customers c
         LEFT JOIN work_orders wo ON wo.customer_id = c.id
         $where_sql
         GROUP BY c.id
         ORDER BY c.created_at DESC
         LIMIT {$pag['per_page']} OFFSET {$pag['offset']}",
        $params
    );
}

if (empty($customers) && $total > 0) {
    $customers = db_try_fetchall(
        "SELECT c.*, 0 AS wo_count
         FROM customers c
         $where_sql
         ORDER BY c.created_at DESC
         LIMIT {$pag['per_page']} OFFSET {$pag['offset']}",
        $params
    );
}

$customers = array_values(array_filter($customers, static function ($cust): bool {
    return is_array($cust) && !empty($cust['id']);
}));

$type_counts = [];
foreach (db_try_fetchall("SELECT type, COUNT(*) AS cnt FROM customers GROUP BY type") as $tr) {
    $type_key = strtolower(trim((string)($tr['type'] ?? '')));
    if ($type_key === '') {
        $type_key = 'residential';
    }
    $type_counts[$type_key] = (int)($tr['cnt'] ?? 0);
}
$total_all = array_sum($type_counts);

$tabs = [
    ''            => ['label' => 'All',         'count' => $total_all],
    'residential' => ['label' => 'Residential', 'count' => $type_counts['residential'] ?? 0],
    'commercial'  => ['label' => 'Commercial',  'count' => $type_counts['commercial'] ?? 0],
    'contractor'  => ['label' => 'Contractor',  'count' => $type_counts['contractor'] ?? 0],
];

function customer_type_badge(mixed $type): string
{
    $type = strtolower(trim((string)$type));
    if ($type === '') {
        $type = 'residential';
    }

    $map = [
        'residential' => 'badge-scheduled',
        'commercial'  => 'badge-quoted',
        'contractor'  => 'badge-active',
    ];

    $css   = $map[$type] ?? 'badge-new';
    $label = ucfirst($type);

    return '<span class="tp-badge ' . $css . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

layout_start('Customers', 'customers');
?>

<div class="tp-page-header d-flex align-items-center justify-content-between mb-3">
    <h2 class="tp-page-title mb-0">Customers</h2>
    <?php if (has_role('admin', 'office')): ?>
    <a href="<?= APP_URL ?>/modules/customers/create.php" class="btn-tp-primary btn-tp-sm">
        <i class="fa-solid fa-plus"></i> New Customer
    </a>
    <?php endif; ?>
</div>

<div class="tp-filter-bar tp-filter-bar-compact mb-3">
    <div class="filter-tabs">
        <?php foreach ($tabs as $key => $tab):
            $is_active = ($type_filter === $key);
            $args      = array_filter(['type' => $key, 'q' => $q]);
            $href      = APP_URL . '/modules/customers/index.php' . ($args ? '?' . http_build_query($args) : '');
        ?>
            <a href="<?= e($href) ?>" class="filter-tab <?= $is_active ? 'active' : '' ?>">
                <?= e($tab['label']) ?>
                <span class="tab-count"><?= (int)$tab['count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="tp-filter-search">
        <form method="get" action="<?= APP_URL ?>/modules/customers/index.php" class="tp-filter-search">
            <?php if ($type_filter !== ''): ?>
                <input type="hidden" name="type" value="<?= e($type_filter) ?>">
            <?php endif; ?>

            <input
                type="text"
                name="q"
                value="<?= e($q) ?>"
                placeholder="Search name, company, phone, email..."
                class="tp-search form-control form-control-sm"
            >

            <button type="submit" class="btn-tp-primary btn-tp-sm">
                <i class="fa-solid fa-magnifying-glass"></i> Search
            </button>

            <?php if ($q !== ''): ?>
                <a href="<?= APP_URL ?>/modules/customers/index.php<?= $type_filter ? '?type=' . e($type_filter) : '' ?>" class="btn-tp-ghost btn-tp-sm">
                    <i class="fa-solid fa-xmark"></i> Clear
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="tp-card">
    <?php if (empty($customers)): ?>
        <div class="tp-empty-state py-5 text-center">
            <i class="fa-solid fa-users fa-2x mb-3" style="color:var(--gy);"></i>
            <p class="mb-0">No customers found<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.</p>
            <?php if (has_role('admin', 'office')): ?>
            <a href="<?= APP_URL ?>/modules/customers/create.php" class="btn-tp-primary btn-tp-sm mt-3">
                <i class="fa-solid fa-plus"></i> Add First Customer
            </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="tp-table">
            <thead>
                <tr>
                    <th>Name / Company</th>
                    <th>Phone / Email</th>
                    <th>Type</th>
                    <th class="d-none d-md-table-cell">City</th>
                    <th class="d-none d-lg-table-cell text-center">Work Orders</th>
                    <th class="d-none d-lg-table-cell">Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $cust): ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/modules/customers/view.php?id=<?= (int)$cust['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= e($cust['name'] ?? 'Unnamed Customer') ?>
                        </a>
                        <?php if (!empty($cust['company'])): ?>
                            <br><small class="text-muted"><?= e($cust['company']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e(fmt_phone($cust['phone'] ?? '')) ?>
                        <?php if (!empty($cust['email'])): ?>
                            <br><small class="text-muted"><?= e($cust['email']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= customer_type_badge($cust['type'] ?? 'residential') ?></td>
                    <td class="d-none d-md-table-cell"><?= e($cust['city'] ?? '-') ?></td>
                    <td class="d-none d-lg-table-cell text-center">
                        <span class="tp-badge badge-active"><?= (int)($cust['wo_count'] ?? 0) ?></span>
                    </td>
                    <td class="d-none d-lg-table-cell">
                        <span title="<?= e(fmt_datetime($cust['created_at'] ?? null)) ?>">
                            <?= e(fmt_date($cust['created_at'] ?? null)) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end tp-table-actions">
                            <a href="<?= APP_URL ?>/modules/customers/view.php?id=<?= (int)$cust['id'] ?>" class="btn-tp-ghost btn-tp-sm" title="View">
                                <i class="fa-solid fa-eye"></i> View
                            </a>
                            <?php if (has_role('admin', 'office')): ?>
                            <a href="<?= APP_URL ?>/modules/bookings/create.php?customer_id=<?= (int)$cust['id'] ?>" class="btn-tp-primary btn-tp-sm" title="New Booking">
                                <i class="fa-solid fa-calendar-plus"></i> Book
                            </a>
                            <a href="<?= APP_URL ?>/modules/customers/edit.php?id=<?= (int)$cust['id'] ?>" class="btn-tp-ghost btn-tp-sm" title="Edit">
                                <i class="fa-solid fa-pencil"></i> Edit
                            </a>
                            <a href="<?= APP_URL ?>/modules/customers/delete.php?id=<?= (int)$cust['id'] ?>" class="btn-tp-ghost btn-tp-sm text-danger" title="Delete" data-confirm="Delete customer <?= e($cust['name'] ?? 'this customer') ?>? This cannot be undone.">
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

    <?php if ($pag['pages'] > 1): ?>
    <div class="tp-pagination d-flex align-items-center justify-content-between px-3 py-2 border-top">
        <small class="text-muted">
            Showing <?= ($pag['offset'] + 1) ?>-<?= min($pag['offset'] + $pag['per_page'], $total) ?>
            of <?= $total ?> customers
        </small>
        <div class="d-flex gap-1">
            <?php for ($p = 1; $p <= $pag['pages']; $p++):
                $p_args = array_filter(['type' => $type_filter, 'q' => $q]);
                $p_args['page'] = $p;
                $href = APP_URL . '/modules/customers/index.php?' . http_build_query($p_args);
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
