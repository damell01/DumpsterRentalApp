<?php
/**
 * Invoices – View / Print
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { flash_error('Invalid invoice ID.'); redirect('index.php'); }

$inv = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$id]);
if (!$inv) { flash_error('Invoice not found.'); redirect('index.php'); }

$items = db_fetchall('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC', [$id]);

// Settings
$company_name    = get_setting('company_name',    'Trash Panda Roll-Offs');
$company_phone   = get_setting('company_phone',   '');
$company_email   = get_setting('company_email',   '');
$company_address = get_setting('company_address', '');
$invoice_footer  = get_setting('invoice_footer',  '');
$logo_url        = get_setting('logo_url', '') ?: get_setting('logo_path', '');

$print_mode = !empty($_GET['print']);

if ($print_mode):
// ── PRINT MODE ────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice <?= e($inv['invoice_number']) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<style>
  body { background:#fff; color:#111; font-family:Arial,Helvetica,sans-serif; font-size:14px; }
  .inv-header { border-bottom:2px solid #f97316; padding-bottom:1rem; margin-bottom:1.5rem; }
  .inv-logo-img { max-height:60px; max-width:200px; object-fit:contain; }
  .inv-logo-text { font-size:1.6rem; font-weight:900; letter-spacing:.04em; color:#111; }
  .inv-logo-text span { color:#f97316; }
  .badge-status { display:inline-block; padding:.25rem .75rem; border-radius:4px; font-size:.8rem; font-weight:700; text-transform:uppercase; }
  .badge-draft { background:#e5e7eb; color:#374151; }
  .badge-sent  { background:#dbeafe; color:#1d4ed8; }
  .badge-paid  { background:#d1fae5; color:#065f46; }
  .badge-void  { background:#fee2e2; color:#991b1b; }
  .items-table th { background:#f9fafb; font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; }
  .totals-table td { padding:.35rem .5rem; }
  .subtotal-row td { border-top:2px solid #e5e7eb; font-weight:600; background:#f9fafb; }
  .total-row td { border-top:2px solid #f97316; font-weight:700; font-size:1.1rem; }
  .payment-box { background:#fffbeb; border:1px solid #f59e0b; border-radius:6px; padding:1rem 1.25rem; margin-top:1.5rem; }
  .inv-footer { border-top:1px solid #e5e7eb; margin-top:2rem; padding-top:.75rem; font-size:.8rem; color:#6b7280; text-align:center; }
  @media print {
    .no-print { display:none !important; }
    body { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
</style>
</head>
<body>
<div class="container py-4">
  <div class="inv-header d-flex justify-content-between align-items-start">
    <div>
      <?php if (!empty($logo_url)): ?>
      <img src="<?= e($logo_url) ?>" alt="<?= e($company_name) ?>" class="inv-logo-img mb-1"
           onerror="this.style.display='none';">
      <br>
      <?php else: ?>
      <div class="inv-logo-text">🗑 <span><?= e($company_name) ?></span></div>
      <?php endif; ?>
      <div style="font-size:.95rem;font-weight:600;"><?= e($company_name) ?></div>
      <?php if ($company_phone):   ?><div style="font-size:.85rem;"><?= e($company_phone) ?></div><?php endif; ?>
      <?php if ($company_email):   ?><div style="font-size:.85rem;"><?= e($company_email) ?></div><?php endif; ?>
      <?php if ($company_address): ?><div style="font-size:.85rem;"><?= e($company_address) ?></div><?php endif; ?>
    </div>
    <div class="text-end">
      <h2 class="mb-1">INVOICE</h2>
      <div class="fw-bold fs-5"><?= e($inv['invoice_number']) ?></div>
      <div class="text-muted" style="font-size:.85rem;">
        Date: <?= e(fmt_date($inv['created_at'])) ?>
        <?php if ($inv['due_date']): ?>
        <br>Due: <?= e(fmt_date($inv['due_date'])) ?>
        <?php endif; ?>
      </div>
      <div class="mt-1">
        <span class="badge-status badge-<?= e($inv['status']) ?>"><?= e(ucfirst($inv['status'])) ?></span>
      </div>
    </div>
  </div>

  <!-- Bill To -->
  <div class="row mb-4">
    <div class="col-md-6">
      <strong>Bill To:</strong>
      <div><?= e($inv['cust_name']) ?></div>
      <?php if ($inv['cust_phone']):   ?><div><?= e($inv['cust_phone']) ?></div><?php endif; ?>
      <?php if ($inv['cust_email']):   ?><div><?= e($inv['cust_email']) ?></div><?php endif; ?>
      <?php if ($inv['cust_address']): ?><div><?= e($inv['cust_address']) ?></div><?php endif; ?>
    </div>
  </div>

  <!-- Line Items -->
  <table class="table items-table mb-0">
    <thead>
      <tr>
        <th style="width:50%">Description</th>
        <th>Rate Type</th>
        <th class="text-end">Qty</th>
        <th class="text-end">Unit Price</th>
        <th class="text-end">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td><?= e($it['description']) ?></td>
        <td><?= e(ucfirst($it['rate_type'])) ?></td>
        <td class="text-end"><?= e(rtrim(rtrim(number_format((float)$it['quantity'], 2, '.', ''), '0'), '.')) ?></td>
        <td class="text-end"><?= e(fmt_money($it['unit_price'])) ?></td>
        <td class="text-end"><?= e(fmt_money($it['amount'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="subtotal-row">
        <td colspan="4" class="text-end">Subtotal</td>
        <td class="text-end"><?= e(fmt_money($inv['subtotal'])) ?></td>
      </tr>
      <?php if ((float)$inv['tax_rate'] > 0): ?>
      <tr>
        <td colspan="4" class="text-end">Tax (<?= number_format((float)$inv['tax_rate'], 2) ?>%)</td>
        <td class="text-end"><?= e(fmt_money($inv['tax_amount'])) ?></td>
      </tr>
      <?php endif; ?>
      <tr class="total-row">
        <td colspan="4" class="text-end">Total Due</td>
        <td class="text-end"><?= e(fmt_money($inv['total'])) ?></td>
      </tr>
    </tfoot>
  </table>

  <?php if (!empty($inv['stripe_payment_link'])): ?>
  <div class="payment-box">
    <strong>Pay Online:</strong><br>
    <a href="<?= e($inv['stripe_payment_link']) ?>"
       style="display:inline-block;margin-top:.5rem;padding:.5rem 1.5rem;background:#f97316;color:#fff;
              border-radius:6px;font-weight:700;text-decoration:none;font-size:1rem;">
      💳 Pay Now
    </a>
    <div style="margin-top:.5rem;font-size:.8rem;color:#6b7280;word-break:break-all;">
      <?= e($inv['stripe_payment_link']) ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($inv['notes'])): ?>
  <div class="mt-3"><strong>Notes:</strong><br><?= nl2br(e($inv['notes'])) ?></div>
  <?php endif; ?>
  <?php if (!empty($inv['terms'])): ?>
  <div class="mt-2" style="font-size:.85rem;color:#6b7280;"><strong>Terms:</strong><br><?= nl2br(e($inv['terms'])) ?></div>
  <?php endif; ?>

  <?php if (!empty($invoice_footer)): ?>
  <div class="inv-footer"><?= nl2br(e($invoice_footer)) ?></div>
  <?php endif; ?>

  <div class="no-print mt-4 text-center">
    <button class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print / Save PDF</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary ms-2"><i class="fa-solid fa-arrow-left me-1"></i> Back</a>
  </div>
</div>
</body>
</html>
<?php
else:
// ── SCREEN MODE ───────────────────────────────────────────────────────────────
layout_start('Invoice ' . $inv['invoice_number'], 'invoices');
?>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">Invoice <?= e($inv['invoice_number']) ?></h1>
        <small class="text-muted">Created <?= e(fmt_datetime($inv['created_at'])) ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="view.php?id=<?= $id ?>&print=1" class="btn-tp-ghost btn-tp-sm" target="_blank">
            <i class="fa-solid fa-print"></i> Print / PDF
        </a>
        <?php if (has_role('admin', 'office')): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-pencil"></i> Edit
        </a>
        <?php endif; ?>
        <a href="index.php" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<?php flash_display(); ?>

<div class="row g-4">
    <div class="col-lg-8">

        <!-- Invoice card -->
        <div class="tp-card mb-4">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
                <div>
                    <?php if (!empty($logo_url)): ?>
                    <img src="<?= e($logo_url) ?>" alt="<?= e($company_name) ?>"
                         style="max-height:55px;max-width:180px;object-fit:contain;margin-bottom:.4rem;display:block;"
                         onerror="this.style.display='none';">
                    <?php endif; ?>
                    <div style="font-family:var(--font-display);font-size:1.1rem;">
                        🗑 <?= e($company_name) ?>
                    </div>
                    <?php if ($company_phone):   ?><div style="color:var(--gl);font-size:.88rem;"><?= e($company_phone) ?></div><?php endif; ?>
                    <?php if ($company_email):   ?><div style="color:var(--gl);font-size:.88rem;"><?= e($company_email) ?></div><?php endif; ?>
                    <?php if ($company_address): ?><div style="color:var(--gl);font-size:.88rem;"><?= e($company_address) ?></div><?php endif; ?>
                </div>
                <div class="text-end">
                    <div style="font-size:1.5rem;font-weight:700;color:var(--or);"><?= e($inv['invoice_number']) ?></div>
                    <?= status_badge($inv['status']) ?>
                    <div style="color:var(--gl);font-size:.85rem;margin-top:.25rem;">
                        Date: <?= e(fmt_date($inv['created_at'])) ?>
                        <?php if ($inv['due_date']): ?>
                        <br>Due: <?= e(fmt_date($inv['due_date'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Bill To -->
            <div class="mb-4">
                <div style="color:var(--gl);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.35rem;">Bill To</div>
                <div style="font-weight:600;"><?= e($inv['cust_name']) ?></div>
                <?php if ($inv['cust_phone']):   ?><div style="color:var(--gl);"><?= e($inv['cust_phone']) ?></div><?php endif; ?>
                <?php if ($inv['cust_email']):   ?><div style="color:var(--gl);"><?= e($inv['cust_email']) ?></div><?php endif; ?>
                <?php if ($inv['cust_address']): ?><div style="color:var(--gl);"><?= e($inv['cust_address']) ?></div><?php endif; ?>
            </div>

            <!-- Line items table -->
            <div class="table-responsive">
                <table class="table tp-table mb-0">
                    <thead>
                        <tr>
                            <th style="width:45%">Description</th>
                            <th>Rate Type</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <td><?= e($it['description']) ?></td>
                            <td><span class="tp-badge" style="text-transform:capitalize;"><?= e($it['rate_type']) ?></span></td>
                            <td class="text-end"><?= e(rtrim(rtrim(number_format((float)$it['quantity'], 2, '.', ''), '0'), '.')) ?></td>
                            <td class="text-end"><?= e(fmt_money($it['unit_price'])) ?></td>
                            <td class="text-end" style="font-weight:600;"><?= e(fmt_money($it['amount'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-end" style="color:var(--gl);">Subtotal</td>
                            <td class="text-end fw-semibold"><?= e(fmt_money($inv['subtotal'])) ?></td>
                        </tr>
                        <?php if ((float)$inv['tax_rate'] > 0): ?>
                        <tr>
                            <td colspan="4" class="text-end" style="color:var(--gl);">
                                Tax (<?= number_format((float)$inv['tax_rate'], 2) ?>%)
                            </td>
                            <td class="text-end fw-semibold"><?= e(fmt_money($inv['tax_amount'])) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td colspan="4" class="text-end fw-bold"
                                style="border-top:2px solid var(--or);font-size:1.05rem;">
                                Total Due
                            </td>
                            <td class="text-end fw-bold"
                                style="border-top:2px solid var(--or);font-size:1.15rem;color:var(--or);">
                                <?= e(fmt_money($inv['total'])) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if (!empty($inv['notes'])): ?>
            <div class="mt-3 pt-3" style="border-top:1px solid var(--st2);">
                <div style="color:var(--gl);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.35rem;">Notes</div>
                <div style="color:var(--gl);"><?= nl2br(e($inv['notes'])) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($inv['terms'])): ?>
            <div class="mt-3 pt-3" style="border-top:1px solid var(--st2);">
                <div style="color:var(--gl);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.35rem;">Terms</div>
                <div style="color:var(--gl);font-size:.85rem;"><?= nl2br(e($inv['terms'])) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($invoice_footer)): ?>
            <div class="mt-3 pt-3" style="border-top:1px solid var(--st2);text-align:center;">
                <div style="color:var(--gl);font-size:.82rem;"><?= nl2br(e($invoice_footer)) ?></div>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <div class="col-lg-4">

        <!-- Payment Link card -->
        <div class="tp-card mb-4" id="stripe-payment-card">
            <div class="tp-card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fab fa-stripe me-2" style="color:#635bff;"></i>Stripe Payment Link</h5>
                <?php
                $stripe_session_url_inv = stripe_dashboard_url($inv['stripe_session_id'] ?? '');
                if ($stripe_session_url_inv): ?>
                <a href="<?= e($stripe_session_url_inv) ?>" target="_blank" rel="noopener noreferrer"
                   class="btn-tp-ghost btn-tp-xs" title="Open in Stripe">
                    <i class="fa-brands fa-stripe"></i> Open in Stripe
                </a>
                <?php endif; ?>
            </div>
            <?php if (!empty($inv['stripe_payment_link'])): ?>
            <div class="mt-3">
                <a href="<?= e($inv['stripe_payment_link']) ?>" target="_blank"
                   class="btn-tp-primary d-block text-center mb-2">
                    <i class="fa-solid fa-credit-card me-1"></i> Open Payment Link
                </a>
                <div class="mt-2" style="background:var(--dk3);border:1px solid var(--st2);border-radius:6px;padding:.6rem .9rem;word-break:break-all;font-size:.8rem;color:var(--gl);">
                    <?= e($inv['stripe_payment_link']) ?>
                </div>
                <button class="btn-tp-ghost btn-tp-xs w-100 mt-2"
                        data-copy-url="<?= e($inv['stripe_payment_link']) ?>"
                        onclick="copyPayLink(this)" type="button">
                    <i class="fa-solid fa-copy me-1"></i> Copy Link
                </button>
<script>
function copyPayLink(btn) {
    var url = btn.getAttribute('data-copy-url');
    navigator.clipboard.writeText(url).then(function() {
        btn.textContent = '✓ Copied!';
        setTimeout(function() { btn.innerHTML = '<i class="fa-solid fa-copy" style="margin-right:.25rem;"></i> Copy Link'; }, 2000);
    });
}
</script>
                <?php if (has_role('admin', 'office') && trim(get_setting('stripe_secret_key','')) !== ''): ?>
                <form method="POST" action="generate_payment_link.php" class="mt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="payment_method" value="<?= e(in_array($inv['payment_method'] ?? '', ['card','ach'], true) ? $inv['payment_method'] : 'stripe') ?>">
                    <button type="submit" class="btn-tp-ghost btn-tp-xs w-100"
                            onclick="return confirm('Regenerate Stripe payment link? The old link may still work.')">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> Regenerate Link
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <?php if (has_role('admin', 'office') && trim(get_setting('stripe_secret_key','')) !== '' && !in_array($inv['status'], ['void','canceled'], true)): ?>
            <div class="mt-3">
                <p class="text-muted mb-2" style="font-size:.9rem;">No payment link generated yet.</p>
                <form method="POST" action="generate_payment_link.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <div class="mb-2">
                        <label class="form-label" style="font-size:.82rem;">Online Payment Method</label>
                        <select name="payment_method" class="form-select form-select-sm">
                            <option value="stripe" <?= !in_array($inv['payment_method'] ?? '', ['card','ach'], true) ? 'selected' : '' ?>>Card or ACH (Customer Chooses)</option>
                            <option value="card" <?= ($inv['payment_method'] ?? '') === 'card' ? 'selected' : '' ?>>Card Only</option>
                            <option value="ach" <?= ($inv['payment_method'] ?? '') === 'ach' ? 'selected' : '' ?>>ACH Bank Transfer Only</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-tp-primary btn-tp-sm w-100">
                        <i class="fa-brands fa-stripe me-1"></i> Generate Online Payment Link
                    </button>
                </form>
            </div>
            <?php else: ?>
            <p class="text-muted mt-3 mb-2" style="font-size:.9rem;">No payment link. Configure Stripe in Settings to enable.</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Send Invoice Email -->
        <?php if (has_role('admin', 'office')): ?>
        <div class="tp-card mb-4">
            <div class="tp-card-header">
                <h5 class="mb-0"><i class="fa-solid fa-paper-plane me-2"></i>Send Invoice to Customer</h5>
            </div>
            <div class="mt-3">
                <form method="POST" action="send_invoice.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <div class="mb-2">
                        <label class="form-label" style="font-size:.82rem;">Send To</label>
                        <input type="email" name="send_to" class="form-control form-control-sm"
                               value="<?= e($inv['cust_email'] ?? '') ?>"
                               placeholder="customer@email.com" required>
                        <?php if (!empty($inv['cust_email'])): ?>
                        <div style="font-size:.78rem;color:var(--gl);margin-top:.3rem;">
                            Email on file: <?= e($inv['cust_email']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($inv['stripe_payment_link'])): ?>
                    <div class="mb-2 mt-1">
                        <div class="form-check" style="font-size:.88rem;">
                            <input class="form-check-input" type="checkbox" name="include_payment_link"
                                   id="include_payment_link" value="1" checked>
                            <label class="form-check-label" for="include_payment_link" style="color:var(--gl);">
                                Include online payment link
                            </label>
                        </div>
                    </div>
                    <?php else: ?>
                    <p style="font-size:.78rem;color:#f59e0b;margin:0 0 .6rem;text-align:center;">
                        No payment link — invoice only will be sent.
                        <a href="#stripe-payment-card" style="color:#f97316;">Generate one?</a>
                    </p>
                    <?php endif; ?>
                    <button type="submit" class="btn-tp-primary btn-tp-sm w-100">
                        <i class="fa-solid fa-paper-plane me-1"></i> Send Invoice Email
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick status update / cash-check actions -->
        <?php if (has_role('admin', 'office') && !in_array($inv['status'], ['void','canceled'], true)): ?>
        <div class="tp-card mb-4">
            <div class="tp-card-header">
                <h5 class="mb-0"><i class="fa-solid fa-bolt me-2"></i>Quick Actions</h5>
            </div>
            <div class="mt-3">
                <form method="post" action="quick_pay.php" id="inv-quick-pay-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" id="inv-quick-action" value="">
                    <div class="mb-2">
                        <input type="text" name="payment_notes" class="form-control form-control-sm"
                               placeholder="Payment note (e.g. check #1042)…"
                               value="<?= e($inv['payment_notes'] ?? '') ?>">
                    </div>
                    <div class="d-flex flex-column gap-2">
                        <?php if ($inv['status'] !== 'paid'): ?>
                        <button type="button" class="btn-tp-primary btn-tp-sm"
                                onclick="invQuickPay('mark_paid_cash')">
                            <i class="fa-solid fa-money-bill"></i> Mark Paid (Cash)
                        </button>
                        <button type="button" class="btn-tp-primary btn-tp-sm"
                                onclick="invQuickPay('mark_paid_check')">
                            <i class="fa-solid fa-check-square"></i> Mark Paid (Check)
                        </button>
                        <?php endif; ?>
                        <?php if ($inv['status'] !== 'sent'): ?>
                        <button type="button" class="btn-tp-ghost btn-tp-sm"
                                onclick="invQuickPay('mark_sent')">
                            <i class="fa-solid fa-envelope"></i> Mark as Sent
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn-tp-ghost btn-tp-sm"
                                style="color:#dc3545;border-color:#dc3545;"
                                onclick="if(confirm('Cancel this invoice?')) invQuickPay('mark_canceled')">
                            <i class="fa-solid fa-ban"></i> Cancel Invoice
                        </button>
                    </div>
                </form>
                <script>
                function invQuickPay(action) {
                    document.getElementById('inv-quick-action').value = action;
                    document.getElementById('inv-quick-pay-form').submit();
                }
                </script>
                <?php if (!empty($inv['payment_notes'])): ?>
                <div class="mt-2 pt-2" style="border-top:1px solid var(--st2);font-size:.82rem;">
                    <span class="text-muted">Note:</span> <?= e($inv['payment_notes']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary -->
        <div class="tp-card">
            <div class="tp-card-header">
                <h5 class="mb-0"><i class="fa-solid fa-info-circle me-2"></i>Invoice Summary</h5>
            </div>
            <table class="table tp-table table-sm mt-3 mb-0">
                <tr><td style="color:var(--gl);">Invoice #</td><td class="fw-semibold"><?= e($inv['invoice_number']) ?></td></tr>
                <tr><td style="color:var(--gl);">Status</td><td><?= status_badge($inv['status']) ?></td></tr>
                <?php if (!empty($inv['payment_method'])): ?>
                <tr><td style="color:var(--gl);">Paid Via</td><td><?= e(payment_method_label($inv['payment_method'])) ?></td></tr>
                <?php endif; ?>
                <tr><td style="color:var(--gl);">Items</td><td><?= count($items) ?></td></tr>
                <?php if ((float)($inv['tax_rate'] ?? 0) > 0): ?>
                <tr><td style="color:var(--gl);">Subtotal</td><td><?= e(fmt_money($inv['subtotal'])) ?></td></tr>
                <tr><td style="color:var(--gl);">Tax</td><td><?= e(fmt_money($inv['tax_amount'])) ?></td></tr>
                <?php endif; ?>
                <tr><td style="color:var(--gl);font-weight:700;">Total</td><td style="color:var(--or);font-weight:700;font-size:1.1rem;"><?= e(fmt_money($inv['total'])) ?></td></tr>
                <?php if ($inv['due_date']): ?>
                <tr><td style="color:var(--gl);">Due Date</td><td><?= e(fmt_date($inv['due_date'])) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>

    </div>
</div>

<?php
layout_end();
endif; // print mode
?>
