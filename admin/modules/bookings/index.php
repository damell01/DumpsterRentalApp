<?php
/**
 * Bookings – List
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();

// ── Filters ───────────────────────────────────────────────────────────────────
$filter    = trim($_GET['filter']    ?? 'all');
$q         = trim($_GET['q']         ?? '');
$date_qs   = trim($_GET['date_qs']   ?? '');  // today|week|month
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 25;

$allowed_filters = ['all', 'pending', 'confirmed', 'paid', 'canceled', 'upcoming'];
if (!in_array($filter, $allowed_filters, true)) {
    $filter = 'all';
}

$allowed_date_qs = ['', 'today', 'week', 'month'];
if (!in_array($date_qs, $allowed_date_qs, true)) {
    $date_qs = '';
}

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

switch ($filter) {
    case 'pending':
        $where[]  = 'b.booking_status = ?';
        $params[] = 'pending';
        break;
    case 'confirmed':
        $where[]  = 'b.booking_status = ?';
        $params[] = 'confirmed';
        break;
    case 'paid':
        $where[] = "(b.booking_status = 'paid' OR b.payment_status IN ('paid','paid_cash','paid_check'))";
        break;
    case 'canceled':
        $where[]  = 'b.booking_status = ?';
        $params[] = 'canceled';
        break;
    case 'upcoming':
        $where[] = "b.rental_start >= CURDATE() AND b.booking_status NOT IN ('canceled','completed')";
        break;
}

// Date quick-select
if ($date_qs === 'today') {
    $where[] = 'DATE(b.created_at) = CURDATE()';
} elseif ($date_qs === 'week') {
    $where[] = 'b.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
} elseif ($date_qs === 'month') {
    $where[] = "MONTH(b.created_at) = MONTH(NOW()) AND YEAR(b.created_at) = YEAR(NOW())";
}

// Search
if ($q !== '') {
    $like     = '%' . $q . '%';
    $where[]  = '(b.booking_number LIKE ? OR b.customer_name LIKE ? OR b.customer_email LIKE ? OR b.unit_code LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = implode(' AND ', $where);

// ── Count (distinct booking_number = one order per line) ─────────────────────
$total_row = db_fetch(
    "SELECT COUNT(DISTINCT b.booking_number) AS cnt FROM bookings b WHERE $where_sql",
    $params
);
$total = (int)($total_row['cnt'] ?? 0);
$pager = paginate($total, $page, $per_page);

// ── Fetch rows (grouped by booking_number so multi-unit orders appear once) ──
// MIN(b.id) links to the primary row; unit_codes/sizes are comma-joined.
$bookings = db_fetchall(
    "SELECT MIN(b.id) AS id,
            b.booking_number,
            b.customer_name, b.customer_email,
            GROUP_CONCAT(b.unit_code ORDER BY b.id SEPARATOR ', ')  AS unit_code,
            GROUP_CONCAT(b.unit_size ORDER BY b.id SEPARATOR ', ')  AS unit_size,
            b.unit_type,
            b.rental_start, b.rental_end, b.rental_days,
            SUM(b.total_amount) AS total_amount,
            b.payment_method, b.payment_status, b.booking_status,
            b.stripe_payment_id, b.stripe_session_id,
            MIN(b.created_at) AS created_at,
            COUNT(*) AS unit_count
     FROM bookings b
     WHERE $where_sql
     GROUP BY b.booking_number, b.customer_name, b.customer_email,
              b.unit_type, b.rental_start, b.rental_end, b.rental_days,
              b.payment_method, b.payment_status, b.booking_status,
              b.stripe_payment_id, b.stripe_session_id
     ORDER BY MIN(b.created_at) DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$pager['per_page'], $pager['offset']])
);

// Helper to build URL preserving current filters
function bk_url(array $overrides = []): string {
    global $filter, $q, $date_qs;
    $base = array_filter([
        'filter'  => $filter,
        'q'       => $q,
        'date_qs' => $date_qs,
    ], fn($v) => $v !== '');
    $merged = array_merge($base, $overrides);
    return '?' . http_build_query($merged);
}

layout_start('Bookings', 'bookings');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Bookings
        <?php if ($total > 0): ?>
        <small class="text-muted fw-normal ms-2" style="font-size:.75rem;"><?= number_format($total) ?> total</small>
        <?php endif; ?>
    </h5>
    <a href="create.php" class="btn-tp-primary btn-tp-sm">
        <i class="fa-solid fa-plus"></i> New Booking
    </a>
</div>

<!-- Search + Date Quick Filters -->
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <form method="get" action="" class="d-flex gap-2 flex-wrap align-items-center">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <?php if ($date_qs): ?><input type="hidden" name="date_qs" value="<?= e($date_qs) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= e($q) ?>"
               placeholder="Search booking #, customer, email, unit…"
               class="tp-search form-control form-control-sm"
               style="min-width:220px;max-width:320px;">
        <button type="submit" class="btn-tp-primary btn-tp-sm">
            <i class="fa-solid fa-magnifying-glass"></i> Search
        </button>
        <?php if ($q !== ''): ?>
        <a href="<?= e(bk_url(['q' => '', 'page' => 1])) ?>" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
    </form>

    <div class="tp-date-qs ms-auto">
        <a href="<?= e(bk_url(['date_qs' => '', 'page' => 1])) ?>"
           class="<?= $date_qs === '' ? 'active' : '' ?>">All Time</a>
        <a href="<?= e(bk_url(['date_qs' => 'today', 'page' => 1])) ?>"
           class="<?= $date_qs === 'today' ? 'active' : '' ?>">Today</a>
        <a href="<?= e(bk_url(['date_qs' => 'week', 'page' => 1])) ?>"
           class="<?= $date_qs === 'week' ? 'active' : '' ?>">This Week</a>
        <a href="<?= e(bk_url(['date_qs' => 'month', 'page' => 1])) ?>"
           class="<?= $date_qs === 'month' ? 'active' : '' ?>">This Month</a>
    </div>
</div>

<!-- Filter Tabs -->
<div class="tp-filter-tabs mb-3">
    <?php
    $tabs = [
        'all'       => 'All',
        'pending'   => 'Pending',
        'confirmed' => 'Confirmed',
        'paid'      => 'Paid',
        'upcoming'  => 'Upcoming',
        'canceled'  => 'Canceled',
    ];
    foreach ($tabs as $key => $label):
        $active_class = $filter === $key ? ' active' : '';
    ?>
    <a href="<?= e(bk_url(['filter' => $key, 'page' => 1])) ?>"
       class="tp-filter-tab<?= $active_class ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="tp-card p-0">
    <?php if (empty($bookings)): ?>
        <p class="text-muted p-4 mb-0 text-center">No bookings found.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="tp-table">
            <thead>
                <tr>
                    <th>Booking #</th>
                    <th>Customer</th>
                    <th class="d-none d-md-table-cell">Unit</th>
                    <th>Dates</th>
                    <th class="d-none d-lg-table-cell text-center">Days</th>
                    <th class="text-end">Total</th>
                    <th class="d-none d-lg-table-cell">Method</th>
                    <th class="d-none d-md-table-cell">Payment</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bookings as $b): ?>
            <?php
                $stripeLink = stripe_dashboard_url($b['stripe_payment_id'] ?? '');
                if ($stripeLink === null) {
                    $stripeLink = stripe_dashboard_url($b['stripe_session_id'] ?? '');
                }
                $is_unpaid = in_array($b['payment_status'], ['unpaid','pending','pending_cash','pending_check'], true);
            ?>
            <tr data-href="view.php?id=<?= (int)$b['id'] ?>">
                <td>
                    <a href="view.php?id=<?= (int)$b['id'] ?>" class="fw-semibold text-decoration-none">
                        <?= e($b['booking_number']) ?>
                    </a>
                </td>
                <td>
                    <div><?= e($b['customer_name']) ?></div>
                    <?php if ($b['customer_email']): ?>
                    <div style="font-size:.8rem;color:var(--gy);"><?= e($b['customer_email']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="d-none d-md-table-cell">
                    <?php if ($b['unit_code']): ?>
                    <div class="fw-semibold">
                        <?= e($b['unit_code']) ?>
                        <?php if ((int)($b['unit_count'] ?? 1) > 1): ?>
                        <span class="badge" style="background:var(--orange);font-size:.65rem;vertical-align:middle;"><?= (int)$b['unit_count'] ?> units</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:.8rem;color:var(--gy);">
                        <?= e($b['unit_size'] ?? '') ?>
                        <?php if ($b['unit_type'] && (int)($b['unit_count'] ?? 1) === 1): ?> · <?= e(ucfirst($b['unit_type'])) ?><?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div><?= e(fmt_date($b['rental_start'])) ?></div>
                    <div style="font-size:.8rem;color:var(--gy);">→ <?= e(fmt_date($b['rental_end'])) ?></div>
                </td>
                <td class="d-none d-lg-table-cell text-center"><?= (int)$b['rental_days'] ?></td>
                <td class="text-end fw-semibold"><?= e(fmt_money($b['total_amount'])) ?></td>
                <td class="d-none d-lg-table-cell"><?= e(ucfirst($b['payment_method'])) ?></td>
                <td class="d-none d-md-table-cell">
                    <?= payment_badge($b['payment_status']) ?>
                    <?php if (($b['payment_method'] ?? '') === 'stripe' && !empty($stripeLink)): ?>
                    <div style="margin-top:.35rem;">
                        <a href="<?= e($stripeLink) ?>" target="_blank" rel="noopener noreferrer"
                           class="btn-tp-ghost btn-tp-xs" title="Open this payment in Stripe Dashboard">
                            <i class="fa-brands fa-stripe"></i> Stripe
                        </a>
                    </div>
                    <?php endif; ?>
                </td>
                <td><?= status_badge($b['booking_status']) ?></td>
                <td class="text-end">
                    <div class="d-flex gap-1 flex-wrap justify-content-end">
                        <a href="view.php?id=<?= (int)$b['id'] ?>" class="btn-tp-ghost btn-tp-xs">
                            <i class="fa-solid fa-eye"></i> View
                        </a>
                        <a href="edit.php?id=<?= (int)$b['id'] ?>" class="btn-tp-ghost btn-tp-xs">
                            <i class="fa-solid fa-pencil"></i> Edit
                        </a>
                        <?php if (($b['booking_status'] ?? '') === 'pending' && has_role('admin', 'office')): ?>
                        <form method="post" action="approve_request.php" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                            <button type="submit" class="btn-tp-primary btn-tp-xs"
                                    data-confirm="Approve booking <?= e($b['booking_number']) ?>? A work order and invoice will be created.">
                                <i class="fa-solid fa-circle-check"></i> Approve
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if ($is_unpaid && has_role('admin', 'office')): ?>
                        <form method="post" action="quick_pay.php" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                            <input type="hidden" name="action" value="mark_paid_cash">
                            <input type="hidden" name="redirect_to" value="index.php<?= e('?' . http_build_query(array_filter(['filter' => $filter, 'q' => $q, 'date_qs' => $date_qs, 'page' => $page]))) ?>">
                            <button type="submit" class="btn-tp-ghost btn-tp-xs" title="Mark paid — cash"
                                    data-confirm="Mark booking <?= e($b['booking_number']) ?> as paid (cash)?">
                                <i class="fa-solid fa-money-bill"></i> Cash
                            </button>
                        </form>
                        <form method="post" action="quick_pay.php" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                            <input type="hidden" name="action" value="mark_paid_check">
                            <input type="hidden" name="redirect_to" value="index.php<?= e('?' . http_build_query(array_filter(['filter' => $filter, 'q' => $q, 'date_qs' => $date_qs, 'page' => $page]))) ?>">
                            <button type="submit" class="btn-tp-ghost btn-tp-xs" title="Mark paid — check"
                                    data-confirm="Mark booking <?= e($b['booking_number']) ?> as paid (check)?">
                                <i class="fa-solid fa-money-check"></i> Check
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if (has_role('admin')): ?>
                        <a href="delete.php?id=<?= (int)$b['id'] ?>" class="btn-tp-ghost btn-tp-xs text-danger"
                           data-confirm="Permanently delete booking <?= e($b['booking_number']) ?>? This cannot be undone.">
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

    <?php if ($pager['pages'] > 1): ?>
    <div class="d-flex justify-content-between align-items-center p-3" style="border-top:1px solid var(--st);">
        <small class="text-muted">
            Showing <?= $pager['offset'] + 1 ?>–<?= min($pager['offset'] + $pager['per_page'], $total) ?> of <?= $total ?>
        </small>
        <div class="d-flex gap-1">
            <?php if ($pager['page'] > 1): ?>
            <a href="<?= e(bk_url(['page' => $pager['page'] - 1])) ?>" class="btn-tp-ghost btn-tp-xs">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            <?php endif; ?>
            <?php for ($p = max(1, $pager['page'] - 2); $p <= min($pager['pages'], $pager['page'] + 2); $p++): ?>
            <a href="<?= e(bk_url(['page' => $p])) ?>"
               class="btn-tp-ghost btn-tp-xs<?= $p === $pager['page'] ? ' active' : '' ?>">
                <?= $p ?>
            </a>
            <?php endfor; ?>
            <?php if ($pager['page'] < $pager['pages']): ?>
            <a href="<?= e(bk_url(['page' => $pager['page'] + 1])) ?>" class="btn-tp-ghost btn-tp-xs">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php
layout_end();
