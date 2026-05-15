<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();

// ── Filters ───────────────────────────────────────────────────────────────────
$valid_statuses = ['scheduled', 'delivered', 'active', 'pickup_requested', 'picked_up', 'completed', 'canceled'];
$status_filter  = (isset($_GET['status']) && in_array($_GET['status'], $valid_statuses))
                  ? $_GET['status'] : '';
$search            = trim($_GET['q'] ?? '');
$delivery_date_from = trim($_GET['delivery_date_from'] ?? '');
$delivery_date_to   = trim($_GET['delivery_date_to']   ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset   = ($page - 1) * $per_page;

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($status_filter !== '') {
    $where[]  = 'wo.status = ?';
    $params[] = $status_filter;
}

if ($search !== '') {
    $where[]  = '(wo.wo_number LIKE ? OR wo.cust_name LIKE ? OR wo.service_address LIKE ?)';
    $sl       = '%' . $search . '%';
    $params[] = $sl;
    $params[] = $sl;
    $params[] = $sl;
}

if ($delivery_date_from !== '') {
    $where[]  = 'wo.delivery_date >= ?';
    $params[] = $delivery_date_from;
}

if ($delivery_date_to !== '') {
    $where[]  = 'wo.delivery_date <= ?';
    $params[] = $delivery_date_to;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Database connection ───────────────────────────────────────────────────────
$pdo = get_db();

// ── Count ─────────────────────────────────────────────────────────────────────
$count_stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM work_orders wo
     LEFT JOIN dumpsters d ON wo.dumpster_id = d.id
     LEFT JOIN users u ON wo.assigned_driver = u.id
     $where_sql"
);
$count_stmt->execute($params);
$total       = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

// ── Fetch rows ────────────────────────────────────────────────────────────────
$sql = "SELECT wo.*,
               d.unit_code AS dumpster_code,
               d.size      AS dumpster_size,
               u.name      AS driver_name
        FROM work_orders wo
        LEFT JOIN dumpsters d ON wo.dumpster_id = d.id
        LEFT JOIN users u ON wo.assigned_driver = u.id
        $where_sql
        ORDER BY wo.created_at DESC
        LIMIT $per_page OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$work_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Helpers ───────────────────────────────────────────────────────────────────
function wo_status_badge(string $status): string
{
    return status_badge($status);
}

function wo_priority_badge(string $priority): string
{
    $map = [
        'normal' => ['Normal', 'badge-draft'],
        'high'   => ['High',   'badge-pickup-requested'],
        'urgent' => ['Urgent', 'badge-canceled'],
    ];
    [$label, $cls] = $map[$priority] ?? ['Normal', 'badge-draft'];
    return '<span class="tp-badge ' . $cls . '">' . htmlspecialchars($label) . '</span>';
}

function wo_qs(array $overrides = []): string
{
    global $status_filter, $search, $delivery_date_from, $delivery_date_to;
    $base = array_filter([
        'status'             => $status_filter,
        'q'                  => $search,
        'delivery_date_from' => $delivery_date_from,
        'delivery_date_to'   => $delivery_date_to,
    ]);
    return http_build_query(array_merge($base, $overrides));
}

$status_labels = [
    ''                 => 'All',
    'scheduled'        => 'Scheduled',
    'delivered'        => 'Delivered',
    'active'           => 'Active',
    'pickup_requested' => 'Pickup Requested',
    'picked_up'        => 'Picked Up',
    'completed'        => 'Completed',
    'canceled'         => 'Canceled',
];

layout_start('Work Orders', 'work_orders');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Work Orders
        <?php if ($total > 0): ?>
        <small class="text-muted fw-normal ms-2" style="font-size:.75rem;"><?= number_format($total) ?> total</small>
        <?php endif; ?>
    </h5>
    <a href="create.php" class="btn-tp-primary btn-tp-sm">
        <i class="fa-solid fa-plus"></i> New Work Order
    </a>
</div>

<!-- Status Tabs -->
<div class="tp-filter-tabs mb-3">
    <?php foreach ($status_labels as $sv => $sl): ?>
    <a class="tp-filter-tab <?= $status_filter === $sv ? 'active' : '' ?>"
       href="?<?= wo_qs(['status' => $sv, 'page' => 1]) ?>">
        <?= htmlspecialchars($sl) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Search & Date Filters -->
<form method="get" class="mb-3" action="">
    <?php if ($status_filter): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
    <?php endif; ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <div class="d-flex gap-1">
                <input type="text" name="q" class="tp-search form-control form-control-sm"
                       placeholder="WO#, customer, address…"
                       value="<?= htmlspecialchars($search) ?>">
                <?php if ($search): ?>
                <a href="?<?= wo_qs(['q' => '', 'page' => 1]) ?>" class="btn-tp-ghost btn-tp-sm" title="Clear">
                    <i class="fa-solid fa-xmark"></i> Clear
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-3">
            <input type="date" name="delivery_date_from" class="form-control form-control-sm"
                   placeholder="Delivery From"
                   value="<?= htmlspecialchars($delivery_date_from) ?>">
        </div>
        <div class="col-md-3">
            <input type="date" name="delivery_date_to" class="form-control form-control-sm"
                   placeholder="Delivery To"
                   value="<?= htmlspecialchars($delivery_date_to) ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn-tp-primary btn-tp-sm w-100">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
        </div>
    </div>
</form>

<!-- Work Orders Table -->
<div class="tp-card p-0">
    <div class="table-responsive">
        <table class="tp-table">
            <thead>
                <tr>
                    <th>WO #</th>
                    <th>Customer</th>
                    <th class="d-none d-md-table-cell">Service Address</th>
                    <th class="d-none d-lg-table-cell">Size</th>
                    <th class="d-none d-lg-table-cell">Dumpster</th>
                    <th>Delivery</th>
                    <th class="d-none d-md-table-cell">Pickup</th>
                    <th>Status</th>
                    <th class="d-none d-lg-table-cell">Priority</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($work_orders)): ?>
                <tr>
                    <td colspan="10" class="text-center text-muted py-5">
                        <i class="fa-solid fa-truck fa-2x mb-2 d-block" style="color:var(--gy);"></i>
                        No work orders found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($work_orders as $wo): ?>
                <tr data-href="view.php?id=<?= (int)$wo['id'] ?>">
                    <td>
                        <a href="view.php?id=<?= (int)$wo['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= htmlspecialchars($wo['wo_number']) ?>
                        </a>
                    </td>
                    <td>
                        <?= htmlspecialchars($wo['cust_name']) ?>
                        <?php if (!empty($wo['cust_phone'])): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($wo['cust_phone']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <?= htmlspecialchars($wo['service_address'] ?? '—') ?>
                        <?php if (!empty($wo['service_city'])): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($wo['service_city']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-lg-table-cell"><?= htmlspecialchars($wo['size'] ?? '—') ?></td>
                    <td class="d-none d-lg-table-cell">
                        <?php if (!empty($wo['dumpster_code'])): ?>
                            <span class="tp-badge badge-available"><?= htmlspecialchars($wo['dumpster_code']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= !empty($wo['delivery_date'])
                            ? htmlspecialchars(date('m/d/Y', strtotime($wo['delivery_date'])))
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <?= !empty($wo['pickup_date'])
                            ? htmlspecialchars(date('m/d/Y', strtotime($wo['pickup_date'])))
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td><?= wo_status_badge($wo['status']) ?></td>
                    <td class="d-none d-lg-table-cell"><?= wo_priority_badge($wo['priority'] ?? 'normal') ?></td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                            <a href="view.php?id=<?= (int)$wo['id'] ?>"
                               class="btn-tp-ghost btn-tp-xs" title="View">
                                <i class="fa-solid fa-eye"></i> View
                            </a>
                            <a href="edit.php?id=<?= (int)$wo['id'] ?>"
                               class="btn-tp-ghost btn-tp-xs" title="Edit">
                                <i class="fa-solid fa-pencil"></i> Edit
                            </a>
                            <a href="edit.php?id=<?= (int)$wo['id'] ?>#photos"
                               class="btn-tp-ghost btn-tp-xs" title="Add Photo">
                                <i class="fa-solid fa-camera"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="d-flex justify-content-between align-items-center mt-3">
    <small class="text-muted">
        Showing <?= number_format(min($offset + 1, $total)) ?>–<?= number_format(min($offset + $per_page, $total)) ?>
        of <?= number_format($total) ?> work orders
    </small>
    <div class="d-flex gap-1">
        <?php if ($page > 1): ?>
        <a href="?<?= wo_qs(['page' => $page - 1]) ?>" class="btn-tp-ghost btn-tp-xs">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 2);
        $end   = min($total_pages, $page + 2);
        for ($p = $start; $p <= $end; $p++): ?>
        <a href="?<?= wo_qs(['page' => $p]) ?>"
           class="btn-tp-ghost btn-tp-xs<?= $p === $page ? ' active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
        <a href="?<?= wo_qs(['page' => $page + 1]) ?>" class="btn-tp-ghost btn-tp-xs">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($total > 0): ?>
    <p class="text-muted small mt-2">Showing all <?= number_format($total) ?> work order<?= $total !== 1 ? 's' : '' ?>.</p>
<?php endif; ?>

<?php layout_end(); ?>
