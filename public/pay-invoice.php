<?php
/**
 * Invoice Payment Redirect — Trash Panda Roll-Offs
 * Stable link customers receive in email/portal. Creates a fresh Stripe
 * Checkout session on each visit and redirects, so the session's 24h
 * expiry window starts when the customer actually clicks, not when the
 * invoice was generated or emailed.
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

$expected = hash_hmac('sha256', 'inv_' . $id, PORTAL_SIGNING_KEY);
if (!hash_equals($expected, $token)) {
    header('Location: /');
    exit;
}

$inv = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$id]);
if (!$inv) {
    header('Location: /');
    exit;
}

if (($inv['status'] ?? '') === 'paid') {
    header('Location: /invoice-paid.php?id=' . $id . '&token=' . urlencode($token));
    exit;
}

$autoload = $_admin_root . '/vendor/autoload.php';
if ((float)($inv['total'] ?? 0) <= 0 || !file_exists($autoload)) {
    header('Location: /invoice-canceled.php?id=' . $id . '&token=' . urlencode($token));
    exit;
}

require_once $autoload;
require_once INC_PATH . '/stripe.php';

try {
    $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $publicBase = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? parse_url(APP_URL, PHP_URL_HOST));
    $successUrl = $publicBase . '/invoice-paid.php?id=' . $id . '&token=' . urlencode($token) . '&session_id={CHECKOUT_SESSION_ID}';
    $cancelUrl  = $publicBase . '/invoice-canceled.php?id=' . $id . '&token=' . urlencode($token);

    $paymentMethod = trim((string)($inv['payment_method'] ?? 'card'));
    if (!in_array($paymentMethod, ['card', 'ach'], true)) {
        $paymentMethod = 'card';
    }

    $session = stripe_create_invoice_checkout($inv, $successUrl, $cancelUrl, $paymentMethod);

    db_update('invoices', [
        'stripe_session_id' => $session->id,
        'updated_at'        => date('Y-m-d H:i:s'),
    ], 'id', $id);

    header('Location: ' . $session->url);
    exit;
} catch (\Throwable $e) {
    error_log('[pay-invoice.php] Checkout session creation failed for invoice ' . $id . ': ' . $e->getMessage());
    header('Location: /invoice-canceled.php?id=' . $id . '&token=' . urlencode($token));
    exit;
}
