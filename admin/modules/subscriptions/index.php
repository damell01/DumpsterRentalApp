<?php

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INC_PATH . '/stripe.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office');

try {
    billing_subscription_service()->repairMissingHistory();
} catch (Throwable $e) {
    error_log('[subscriptions/index] repairMissingHistory failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim((string)($_POST['action'] ?? 'create'));

    try {
        if ($action === 'create') {
            $subscriptionId = stripe_create_subscription([
                'customer_id' => (int)($_POST['customer_id'] ?? 0),
                'service_name' => trim((string)($_POST['service_name'] ?? 'Recurring Dumpster Service')),
                'service_address' => trim((string)($_POST['service_address'] ?? '')),
                'amount' => (float)($_POST['amount'] ?? 0),
                'interval_unit' => trim((string)($_POST['interval_unit'] ?? 'month')),
                'interval_count' => (int)($_POST['interval_count'] ?? 1),
                'billing_anchor' => trim((string)($_POST['billing_anchor'] ?? '')),
                'stripe_payment_method_id' => trim((string)($_POST['stripe_payment_method_id'] ?? '')),
                'autopay_enabled' => !empty($_POST['autopay_enabled']),
            ]);
            flash_success('Subscription #' . $subscriptionId . ' created successfully.');
        } elseif ($action === 'pause') {
            stripe_pause_subscription((int)($_POST['id'] ?? 0));
            flash_success('Subscription paused.');
        } elseif ($action === 'resume') {
            stripe_resume_subscription((int)($_POST['id'] ?? 0));
            flash_success('Subscription resumed.');
        } elseif ($action === 'cancel') {
            stripe_cancel_subscription((int)($_POST['id'] ?? 0));
            flash_success('Subscription canceled.');
        } elseif ($action === 'retry') {
            stripe_retry_subscription_invoice((int)($_POST['id'] ?? 0));
            flash_success('Latest subscription invoice retry triggered.');
        } elseif ($action === 'update') {
            stripe_update_subscription((int)($_POST['id'] ?? 0), [
                'service_name'    => trim((string)($_POST['service_name'] ?? '')),
                'service_address' => trim((string)($_POST['service_address'] ?? '')),
                'amount'          => (float)($_POST['amount'] ?? 0),
                'interval_unit'   => trim((string)($_POST['interval_unit'] ?? 'month')),
                'interval_count'  => (int)($_POST['interval_count'] ?? 1),
                'autopay_enabled' => !empty($_POST['autopay_enabled']),
            ]);
            flash_success('Subscription updated.');
        }
    } catch (Throwable $e) {
        flash_error('Subscription error: ' . $e->getMessage());
    }

    redirect('index.php');
}

$subscriptions = db_fetchall(
    'SELECT s.*, c.name AS customer_name, c.email AS customer_email,
            (SELECT COUNT(*) FROM payment_methods pm WHERE pm.customer_id = s.customer_id AND pm.is_active = 1) AS saved_method_count
     FROM subscriptions s
     INNER JOIN customers c ON c.id = s.customer_id
     ORDER BY s.updated_at DESC, s.id DESC'
);
$customers = db_fetchall('SELECT id, name, email FROM customers ORDER BY name ASC LIMIT 200');
$paymentMethods = db_fetchall(
    'SELECT pm.*, c.name AS customer_name
     FROM payment_methods pm
     INNER JOIN customers c ON c.id = pm.customer_id
     WHERE pm.is_active = 1
     ORDER BY pm.is_default DESC, pm.updated_at DESC'
);

// Comprehensive payment history per subscription:
// - recurring_invoice_logs (populated by Stripe webhooks)
// - invoices linked to the subscription that aren't already in recurring_invoice_logs
$allLogs = db_fetchall(
    "SELECT ril.subscription_id, ril.amount, ril.status, ril.billing_date,
            ril.failure_message, ril.invoice_id, i.invoice_number
     FROM recurring_invoice_logs ril
     LEFT JOIN invoices i ON i.id = ril.invoice_id

     UNION ALL

     SELECT i2.subscription_id,
            i2.total            AS amount,
            CASE WHEN i2.status = 'paid' THEN 'paid'
                 WHEN i2.status = 'void' THEN 'void'
                 ELSE 'failed' END AS status,
            COALESCE(i2.paid_at, i2.created_at) AS billing_date,
            NULL               AS failure_message,
            i2.id              AS invoice_id,
            i2.invoice_number
     FROM invoices i2
     WHERE i2.subscription_id IS NOT NULL
       AND (i2.stripe_invoice_id IS NULL
            OR NOT EXISTS (
                SELECT 1 FROM recurring_invoice_logs r
                WHERE r.stripe_invoice_id = i2.stripe_invoice_id
            ))

     ORDER BY billing_date DESC
     LIMIT 300"
);

// Build both per-subscription history (for modal) and last-payment (for column)
// from the same data set — SQL is DESC so first entry per sub = most recent
$logsBySubId = [];
$lastPayment  = [];
foreach ($allLogs as $l) {
    $sid   = (int)$l['subscription_id'];
    $invId = (int)($l['invoice_id'] ?? 0);
    $logsBySubId[$sid][] = [
        'd' => $l['billing_date'] ? date('M j, Y g:i a', strtotime($l['billing_date'])) : '',
        'a' => fmt_money((float)$l['amount']),
        's' => $l['status'],
        'm' => $l['failure_message'] ?? '',
        'i' => $invId,
        'n' => $l['invoice_number'] ?? '',
    ];
    if (!isset($lastPayment[$sid])) {
        $lastPayment[$sid] = $l;   // first = most recent
    }
}

// Recent activity feed (last 30, with customer / service name)
$recentLogs = db_fetchall(
    "SELECT p.subscription_id, p.amount, p.status, p.billing_date,
            p.failure_message, s.service_name, c.name AS customer_name
     FROM (
         SELECT ril.subscription_id, ril.amount, ril.status, ril.billing_date, ril.failure_message
         FROM recurring_invoice_logs ril

         UNION ALL

         SELECT i.subscription_id, i.total,
                CASE WHEN i.status = 'paid' THEN 'paid'
                     WHEN i.status = 'void' THEN 'void'
                     ELSE 'failed' END,
                COALESCE(i.paid_at, i.created_at), NULL
         FROM invoices i
         WHERE i.subscription_id IS NOT NULL
           AND (i.stripe_invoice_id IS NULL
                OR NOT EXISTS (
                    SELECT 1 FROM recurring_invoice_logs r
                    WHERE r.stripe_invoice_id = i.stripe_invoice_id
                ))
     ) p
     JOIN subscriptions s ON s.id = p.subscription_id
     JOIN customers c ON c.id = s.customer_id
     ORDER BY p.billing_date DESC
     LIMIT 30"
);

layout_start('Subscriptions', 'subscriptions');
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">
        <i class="fa-solid fa-arrows-rotate me-2 text-muted"></i>Recurring Subscriptions
        <?php if ($subscriptions): ?>
        <small class="text-muted fw-normal ms-2" style="font-size:.75rem;"><?= count($subscriptions) ?> total</small>
        <?php endif; ?>
    </h5>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <a href="../payment_methods/setup.php" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-credit-card me-1"></i> Set Up Payment Method
        </a>
        <?php if (trim(get_setting('stripe_secret_key', '')) !== ''): ?>
        <form method="POST" action="../dumpsters/sync_all_stripe.php" class="d-inline">
            <?= csrf_field() ?>
            <button type="submit" class="btn-tp-ghost btn-tp-sm"
                    onclick="return confirm('Sync inventory catalog to Stripe? This updates dumpster products plus recurring prices used for subscription billing.')">
                <i class="fa-brands fa-stripe me-1"></i> Sync Pricing Catalog
            </button>
        </form>
        <?php endif; ?>
        <button type="button" class="btn-tp-primary btn-tp-sm" data-bs-toggle="modal" data-bs-target="#createSubModal">
            <i class="fa-solid fa-plus me-1"></i> New Subscription
        </button>
    </div>
</div>

<!-- Subscriptions Table -->
<div class="tp-card p-0 mb-4">
    <?php if (!$subscriptions): ?>
    <div class="text-center text-muted py-5">
        <i class="fa-solid fa-arrows-rotate fa-2x mb-3 d-block" style="color:var(--gy);"></i>
        <p class="mb-2">No subscriptions yet.</p>
        <button type="button" class="btn-tp-primary btn-tp-sm" data-bs-toggle="modal" data-bs-target="#createSubModal">
            <i class="fa-solid fa-plus me-1"></i> Create First Subscription
        </button>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="tp-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Service</th>
                    <th>Amount</th>
                    <th>Cycle</th>
                    <th>Next Bill</th>
                    <th>Last Payment</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subscriptions as $subscription): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= e($subscription['customer_name']) ?></div>
                        <div class="text-muted" style="font-size:.78rem;"><?= e($subscription['customer_email']) ?></div>
                    </td>
                    <td>
                        <div><?= e($subscription['service_name']) ?></div>
                        <?php if (!empty($subscription['service_address'])): ?>
                        <div class="text-muted" style="font-size:.78rem;"><?= e($subscription['service_address']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold"><?= e(fmt_money($subscription['amount'])) ?></td>
                    <td class="text-nowrap">Every <?= (int)$subscription['interval_count'] . ' ' . e($subscription['interval_unit']) . ((int)$subscription['interval_count'] === 1 ? '' : 's') ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($subscription['next_billing_date'])) ?></td>
                    <td>
                        <?php $lp = $lastPayment[(int)$subscription['id']] ?? null; ?>
                        <?php if ($lp): ?>
                            <?php if ($lp['status'] === 'paid'): ?>
                            <span class="tp-badge badge-paid"><i class="fa-solid fa-check me-1"></i>Paid</span>
                            <?php elseif ($lp['status'] === 'failed'): ?>
                            <span class="tp-badge badge-canceled"><i class="fa-solid fa-xmark me-1"></i>Failed</span>
                            <?php else: ?>
                            <span class="tp-badge badge-void"><?= e(ucfirst($lp['status'])) ?></span>
                            <?php endif; ?>
                            <div style="font-size:.75rem;color:var(--gy);margin-top:.15rem;"><?= e(fmt_date($lp['billing_date'])) ?></div>
                            <?php if (!empty($lp['invoice_number'])): ?>
                            <div style="font-size:.72rem;color:var(--gy);">Invoice <?= e($lp['invoice_number']) ?></div>
                            <?php endif; ?>
                            <?php if ($lp['status'] === 'failed' && !empty($lp['failure_message'])): ?>
                            <div style="font-size:.72rem;color:#ef4444;max-width:180px;white-space:normal;"><?= e(mb_strimwidth($lp['failure_message'], 0, 55, '…')) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem;">No history</span>
                        <?php endif; ?>
                    </td>
                    <td><?= subscription_badge($subscription['status']) ?></td>
                    <td class="text-end text-nowrap">
                        <div class="d-flex gap-1 justify-content-end align-items-center flex-wrap">
                            <?php if ((int)$subscription['saved_method_count'] === 0 && $subscription['status'] !== 'canceled'): ?>
                            <a href="../payment_methods/setup.php?customer_id=<?= (int)$subscription['customer_id'] ?>"
                               class="btn-tp-danger btn-tp-xs" title="No payment method on file">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i>Add Payment
                            </a>
                            <?php endif; ?>
                            <?php if ($subscription['status'] !== 'canceled'): ?>
                            <button type="button" class="btn-tp-ghost btn-tp-xs"
                                    onclick="openEditSub(<?= (int)$subscription['id'] ?>,<?= e(json_encode($subscription['service_name'])) ?>,<?= e(json_encode($subscription['service_address'] ?? '')) ?>,<?= (float)$subscription['amount'] ?>,<?= e(json_encode($subscription['interval_unit'])) ?>,<?= (int)$subscription['interval_count'] ?>,<?= (int)$subscription['autopay_enabled'] ?>)">
                                <i class="fa-solid fa-pencil"></i> Edit
                            </button>
                            <?php endif; ?>
                            <form method="POST" action="index.php" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$subscription['id'] ?>">
                                <?php if ($subscription['status'] === 'paused'): ?>
                                <input type="hidden" name="action" value="resume">
                                <button type="submit" class="btn-tp-ghost btn-tp-xs"><i class="fa-solid fa-play"></i> Resume</button>
                                <?php elseif ($subscription['status'] !== 'canceled'): ?>
                                <input type="hidden" name="action" value="pause">
                                <button type="submit" class="btn-tp-ghost btn-tp-xs"><i class="fa-solid fa-pause"></i> Pause</button>
                                <?php endif; ?>
                            </form>
                            <?php if ($subscription['status'] !== 'canceled'): ?>
                            <form method="POST" action="index.php" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$subscription['id'] ?>">
                                <input type="hidden" name="action" value="retry">
                                <button type="submit" class="btn-tp-ghost btn-tp-xs"><i class="fa-solid fa-rotate-right"></i> Retry</button>
                            </form>
                            <form method="POST" action="index.php" class="d-inline" onsubmit="return confirm('Cancel this subscription?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$subscription['id'] ?>">
                                <input type="hidden" name="action" value="cancel">
                                <button type="submit" class="btn-tp-ghost btn-tp-xs text-danger"><i class="fa-solid fa-xmark"></i> Cancel</button>
                            </form>
                            <?php endif; ?>
                            <button type="button" class="btn-tp-ghost btn-tp-xs"
                                    onclick="openSubHistory(<?= (int)$subscription['id'] ?>, <?= e(json_encode($subscription['service_name'] . ' — ' . $subscription['customer_name'])) ?>)">
                                <i class="fa-solid fa-clock-rotate-left"></i> History
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Recent Payment Activity -->
<?php if ($recentLogs): ?>
<div class="tp-card p-0">
    <div class="tp-card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-clock-rotate-left me-2 text-muted"></i>Recent Payment Activity</span>
        <span class="text-muted" style="font-size:.8rem;"><?= count($recentLogs) ?> most recent</span>
    </div>
    <div class="table-responsive">
        <table class="tp-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Service</th>
                    <th>Invoice</th>
                    <th>Amount</th>
                    <th>Result</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td class="text-nowrap" style="font-size:.83rem;"><?= e(fmt_datetime($log['billing_date'])) ?></td>
                    <td><?= e($log['customer_name']) ?></td>
                    <td><?= e($log['service_name']) ?></td>
                    <td class="text-nowrap"><?= !empty($log['invoice_number']) ? e($log['invoice_number']) : '—' ?></td>
                    <td class="fw-semibold"><?= e(fmt_money($log['amount'])) ?></td>
                    <td>
                        <?php if ($log['status'] === 'paid'): ?>
                        <span class="tp-badge badge-paid"><i class="fa-solid fa-check me-1"></i>Paid</span>
                        <?php elseif ($log['status'] === 'failed'): ?>
                        <span class="tp-badge badge-canceled"><i class="fa-solid fa-xmark me-1"></i>Failed</span>
                        <?php else: ?>
                        <span class="tp-badge badge-void"><?= e(ucfirst($log['status'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8rem;color:var(--gy);"><?= !empty($log['failure_message']) ? e($log['failure_message']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Create Subscription Modal -->
<div class="modal fade" id="createSubModal" tabindex="-1" aria-labelledby="createSubModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title" id="createSubModalLabel"><i class="fa-solid fa-arrows-rotate me-2"></i>New Subscription</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info py-2 px-3 mb-3" style="font-size:.85rem;">
              <i class="fa-solid fa-circle-info me-1"></i>
              The customer must have a saved payment method before Stripe can auto-charge on a recurring basis.
              <a href="../payment_methods/setup.php" class="alert-link ms-1">Set up a payment method</a> first if needed.
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Customer <span class="text-danger">*</span></label>
              <select name="customer_id" class="form-select" required>
                <option value="">Select customer…</option>
                <?php foreach ($customers as $customer): ?>
                <option value="<?= (int)$customer['id'] ?>"><?= e($customer['name']) ?><?= $customer['email'] ? ' — ' . e($customer['email']) : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Service Name <span class="text-danger">*</span></label>
              <input type="text" name="service_name" class="form-control" value="Recurring Dumpster Service" required>
            </div>
            <div class="col-12">
              <label class="form-label">Service Address <span class="text-muted fw-normal">(optional)</span></label>
              <input type="text" name="service_address" class="form-control" placeholder="Optional service location">
            </div>
            <div class="col-md-4">
              <label class="form-label">Amount ($) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" min="1" name="amount" class="form-control" placeholder="0.00" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Billing Interval</label>
              <div class="input-group">
                <input type="number" min="1" name="interval_count" class="form-control" value="1" style="max-width:70px;">
                <select name="interval_unit" class="form-select">
                  <option value="week">Week</option>
                  <option value="month" selected>Month</option>
                  <option value="year">Year</option>
                  <option value="day">Day</option>
                </select>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Billing Anchor Date <span class="text-muted fw-normal">(optional)</span></label>
              <input type="date" name="billing_anchor" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Default Saved Method</label>
              <select name="stripe_payment_method_id" class="form-select">
                <option value="">Use Stripe customer default</option>
                <?php foreach ($paymentMethods as $paymentMethod): ?>
                <option value="<?= e($paymentMethod['stripe_payment_method_id']) ?>">
                  <?= e($paymentMethod['customer_name']) ?> — <?= e(strtoupper($paymentMethod['type'])) ?> <?= e($paymentMethod['last4'] ? '•••• ' . $paymentMethod['last4'] : '') ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="autopay_enabled" id="autopay_enabled" checked>
                <label class="form-check-label" for="autopay_enabled">Enable autopay</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-tp-ghost btn-tp-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-tp-primary btn-tp-sm">
            <i class="fa-solid fa-plus me-1"></i> Create Subscription
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Subscription Modal -->
<div class="modal fade" id="editSubModal" tabindex="-1" aria-labelledby="editSubModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="editSubId">
        <div class="modal-header">
          <h5 class="modal-title" id="editSubModalLabel">Edit Subscription</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Service Name</label>
            <input type="text" name="service_name" id="editSubServiceName" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Service Address</label>
            <input type="text" name="service_address" id="editSubServiceAddress" class="form-control" placeholder="Optional">
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label">Amount ($)</label>
              <input type="number" step="0.01" min="1" name="amount" id="editSubAmount" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Interval</label>
              <div class="input-group">
                <input type="number" min="1" name="interval_count" id="editSubIntervalCount" class="form-control" required>
                <select name="interval_unit" id="editSubIntervalUnit" class="form-select">
                  <option value="day">Day</option>
                  <option value="week">Week</option>
                  <option value="month">Month</option>
                  <option value="year">Year</option>
                </select>
              </div>
            </div>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="autopay_enabled" id="editSubAutopay" value="1">
            <label class="form-check-label" for="editSubAutopay">Autopay enabled</label>
          </div>
          <p class="text-muted mb-0" style="font-size:.8rem;">Changing amount or interval creates a new Stripe price and updates the subscription immediately with no proration.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-tp-ghost btn-tp-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-tp-primary btn-tp-sm">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Subscription History Modal -->
<div class="modal fade" id="subHistoryModal" tabindex="-1" aria-labelledby="subHistoryTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="subHistoryTitle">Payment History</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0" id="subHistoryBody"></div>
    </div>
  </div>
</div>

<script>
var APP_URL = <?= json_encode(APP_URL) ?>;
var subLogs = <?= json_encode($logsBySubId, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function openEditSub(id, name, address, amount, intervalUnit, intervalCount, autopay) {
    document.getElementById('editSubId').value = id;
    document.getElementById('editSubServiceName').value = name;
    document.getElementById('editSubServiceAddress').value = address;
    document.getElementById('editSubAmount').value = amount;
    document.getElementById('editSubIntervalUnit').value = intervalUnit;
    document.getElementById('editSubIntervalCount').value = intervalCount;
    document.getElementById('editSubAutopay').checked = autopay == 1;
    new bootstrap.Modal(document.getElementById('editSubModal')).show();
}

function openSubHistory(subId, label) {
    var logs = subLogs[subId] || [];
    document.getElementById('subHistoryTitle').textContent = 'Payment History — ' + label;
    var body = document.getElementById('subHistoryBody');
    if (!logs.length) {
        body.innerHTML = '<p class="text-muted p-3 mb-0">No payment history recorded yet.</p>';
    } else {
        var rows = logs.map(function(l) {
            var badge = l.s === 'paid'
                ? '<span class="tp-badge badge-paid"><i class="fa-solid fa-check me-1"></i>Paid</span>'
                : (l.s === 'failed'
                    ? '<span class="tp-badge badge-canceled"><i class="fa-solid fa-xmark me-1"></i>Failed</span>'
                    : '<span class="tp-badge badge-void">' + escH(l.s) + '</span>');
            var note = l.m ? '<div style="font-size:.75rem;color:#ef4444;margin-top:.15rem;">' + escH(l.m) + '</div>' : '';
            var invLabel = l.n ? l.n : 'View Invoice';
            var invLink = l.i ? '<a href="' + APP_URL + '/modules/invoices/view.php?id=' + l.i
                + '" style="font-size:.75rem;display:block;margin-top:.2rem;" target="_blank">'
                + '<i class="fa-solid fa-file-invoice me-1"></i>' + escH(invLabel) + '</a>' : '';
            return '<tr>'
                + '<td style="font-size:.83rem;white-space:nowrap;">' + escH(l.d) + '</td>'
                + '<td style="white-space:nowrap;">' + (l.i ? escH(l.i) : '—') + '</td>'
                + '<td class="fw-semibold">' + escH(l.a) + '</td>'
                + '<td>' + badge + note + invLink + '</td>'
                + '</tr>';
        }).join('');
        body.innerHTML = '<div class="table-responsive"><table class="tp-table mb-0">'
            + '<thead><tr><th>Date</th><th>Invoice</th><th>Amount</th><th>Result</th></tr></thead>'
            + '<tbody>' + rows + '</tbody></table></div>';
    }
    new bootstrap.Modal(document.getElementById('subHistoryModal')).show();
}

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
<?php layout_end(); ?>
