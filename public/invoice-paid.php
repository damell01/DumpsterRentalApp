<?php
/**
 * Invoice Payment Success — Trash Panda Roll-Offs
 * Customer lands here after paying an invoice via Stripe.
 */

$_admin_root = dirname(__DIR__) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';

$id    = (int)($_GET['id']    ?? 0);
$token = trim($_GET['token']  ?? '');

if ($id <= 0 || $token === '') {
    header('Location: /');
    exit;
}

$expected = hash_hmac('sha256', 'inv_' . $id, defined('PORTAL_SIGNING_KEY') ? PORTAL_SIGNING_KEY : 'invoice-token-secret');
if (!hash_equals($expected, $token)) {
    header('Location: /');
    exit;
}

$inv = db_fetch(
    'SELECT i.*, c.email AS customer_email FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     WHERE i.id = ? LIMIT 1',
    [$id]
);

if (!$inv) {
    header('Location: /');
    exit;
}

// Best-effort: finalize via Stripe session if webhook hasn't fired yet
$sessionId = trim($_GET['session_id'] ?? '');
if ($sessionId !== '' && ($inv['status'] ?? '') !== 'paid') {
    $autoload = $_admin_root . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        try {
            require_once $autoload;
            require_once INC_PATH . '/stripe.php';
            require_once INC_PATH . '/mailer.php';
            $session = stripe_client()->checkout->sessions->retrieve($sessionId);
            $isPaid  = in_array($session->payment_status ?? '', ['paid', 'complete'], true)
                    || ($session->status ?? '') === 'complete';
            if ($isPaid) {
                db_update('invoices', [
                    'status'     => 'paid',
                    'paid_at'    => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id', $id);
                $inv['status']  = 'paid';
                $inv['paid_at'] = date('Y-m-d H:i:s');

                // Send confirmation email (only if this page is finalizing — webhook sends if it fires first)
                $alreadyEmailed = (bool)db_value(
                    "SELECT COUNT(*) FROM activity_log WHERE action = 'invoice_paid_emailed' AND entity_id = ?",
                    [$id]
                );
                if (!$alreadyEmailed) {
                    $custEmail = trim($inv['customer_email'] ?? '');
                    $custName  = $inv['cust_name'] ?? '';
                    if ($custEmail !== '' && filter_var($custEmail, FILTER_VALIDATE_EMAIL)) {
                        $body = '<p>' . ($custName ? 'Hi ' . htmlspecialchars($custName, ENT_QUOTES, 'UTF-8') . ',' : 'Hello,') . '</p>'
                            . '<p>Your payment of <strong>' . htmlspecialchars(fmt_money((float)$inv['total']), ENT_QUOTES, 'UTF-8')
                            . '</strong> for invoice <strong>' . htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8')
                            . '</strong> has been received. Thank you!</p>';
                        $html = email_template('Invoice Paid', $body);
                        send_email($custEmail, 'Invoice Paid — ' . $inv['invoice_number'], $html);
                    }
                    $adminBody = '<p>Invoice <strong>' . htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8')
                        . '</strong> has been paid.'
                        . ($inv['cust_name'] ? ' Customer: <strong>' . htmlspecialchars($inv['cust_name'], ENT_QUOTES, 'UTF-8') . '</strong>.' : '')
                        . ' Amount: <strong>' . htmlspecialchars(fmt_money((float)$inv['total']), ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
                    notify_admins(
                        'Invoice Paid — ' . $inv['invoice_number'] . ($inv['cust_name'] ? ' (' . $inv['cust_name'] . ')' : ''),
                        $adminBody
                    );
                    log_activity('invoice_paid_emailed', 'Sent invoice paid notification for ' . $inv['invoice_number'], 'invoice', $id);
                }
            }
        } catch (\Throwable $e) {
            error_log('[invoice-paid.php] Stripe/email error: ' . $e->getMessage());
        }
    }
}

$company_name  = get_setting('company_name', 'Trash Panda Roll-Offs');
$company_phone = get_setting('company_phone', '');
$inv_num   = $inv['invoice_number'] ?? '—';
$cust_name = $inv['cust_name'] ?? '';
$total     = (float)($inv['total'] ?? 0);
$paid_at   = !empty($inv['paid_at']) ? date('F j, Y', strtotime($inv['paid_at'])) : date('F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Paid — <?= htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Black+Han+Sans&family=Barlow+Condensed:wght@400;600;700;800&family=Barlow:wght@300;400;500;600&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/shared.css">
    <meta name="theme-color" content="#f97316"/>
    <style>
        body { background: var(--black); color: var(--white); font-family: var(--font-body); }
        .book-nav { background: var(--dark); border-bottom: 1px solid var(--steel); padding: .8rem 1rem; display: flex; align-items: center; }
        .book-nav-brand { font-family: var(--font-display); font-size: 1.1rem; color: var(--white); text-decoration: none; }
        .book-nav-brand span { color: var(--orange); }
        .page-wrap { max-width: 560px; margin: 3rem auto; padding: 0 1rem; text-align: center; }
        .success-icon {
            width: 90px; height: 90px;
            background: rgba(34,197,94,.15); border: 2px solid rgba(34,197,94,.4);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.5rem; font-size: 2.5rem; color: #22c55e;
        }
        .page-title { font-family: var(--font-display); font-size: 2.2rem; color: var(--white); margin-bottom: .5rem; }
        .page-title span { color: var(--orange); }
        .detail-card {
            background: var(--dark2); border: 1px solid var(--steel);
            border-radius: 12px; padding: 1.5rem; margin-top: 1.5rem; text-align: left;
        }
        .detail-card h3 { font-family: var(--font-cond); font-size: 1rem; font-weight: 700; color: var(--white); border-bottom: 1px solid var(--steel); padding-bottom: .6rem; margin-bottom: 1rem; }
        .detail-row { display: flex; justify-content: space-between; padding: .4rem 0; font-size: .9rem; border-bottom: 1px solid var(--steel2); }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: var(--gray); }
        .detail-value { color: var(--white); font-weight: 500; }
        .total-row .detail-value { color: var(--orange); font-weight: 700; font-size: 1.1rem; }
        .inv-badge {
            display: inline-block; background: rgba(249,115,22,.15); border: 1px solid rgba(249,115,22,.35);
            border-radius: 6px; padding: .4rem 1.1rem; font-family: var(--font-cond);
            font-size: 1.4rem; font-weight: 700; color: var(--orange); letter-spacing: .05em; margin: .5rem 0 1rem;
        }
    </style>
</head>
<body>
<nav class="book-nav">
    <a href="/" class="book-nav-brand">TRASH PANDA <span>ROLL-OFFS</span></a>
</nav>

<div class="page-wrap">
    <div class="success-icon"><i class="fas fa-check"></i></div>
    <div class="page-title">PAYMENT <span>RECEIVED!</span></div>

    <?php if ($cust_name): ?>
    <p style="color:var(--gray-light);">Thank you, <?= htmlspecialchars($cust_name, ENT_QUOTES, 'UTF-8') ?>! Your invoice has been paid.</p>
    <?php else: ?>
    <p style="color:var(--gray-light);">Your invoice payment has been received. Thank you!</p>
    <?php endif; ?>

    <div class="inv-badge"><?= htmlspecialchars($inv_num, ENT_QUOTES, 'UTF-8') ?></div>

    <div class="detail-card">
        <h3><i class="fas fa-receipt" style="color:var(--orange);margin-right:.4rem;"></i> Payment Summary</h3>
        <div class="detail-row">
            <span class="detail-label">Invoice</span>
            <span class="detail-value"><?= htmlspecialchars($inv_num, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Date Paid</span>
            <span class="detail-value"><?= htmlspecialchars($paid_at, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="detail-row total-row">
            <span class="detail-label" style="font-weight:600;">Amount Paid</span>
            <span class="detail-value"><?= htmlspecialchars(fmt_money($total), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <?php if ($company_phone): ?>
    <p style="color:var(--gray);font-size:.85rem;margin-top:1.5rem;">
        Questions? Call us at
        <a href="tel:<?= htmlspecialchars(preg_replace('/\D/', '', $company_phone), ENT_QUOTES, 'UTF-8') ?>"
           style="color:var(--orange);"><?= htmlspecialchars(fmt_phone($company_phone), ENT_QUOTES, 'UTF-8') ?></a>
    </p>
    <?php endif; ?>

    <div class="d-flex gap-3 justify-content-center mt-4">
        <a href="/" class="btn-panda-outline"><i class="fas fa-home"></i> Back to Home</a>
    </div>
</div>
<script>if('serviceWorker'in navigator)navigator.serviceWorker.register('/sw.js').catch(()=>{});</script>
</body>
</html>
