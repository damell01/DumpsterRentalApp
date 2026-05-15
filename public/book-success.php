<?php
/**
 * Booking Success Page — Trash Panda Roll-Offs
 * Supports single booking (?id=N&token=X) and multi-booking (?ids=1,2,3&token=X)
 */

$_admin_root = dirname(__DIR__) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';

/**
 * Fallback payment finalization for Stripe returns.
 * Webhook should normally do this, but this catches delayed/failed webhooks.
 */
function maybe_finalize_stripe_success(array $bookings, string $sessionId, string $adminRoot): void
{
    if ($sessionId === '') {
        return;
    }

    $needsFinalizing = false;
    foreach ($bookings as $bk) {
        if (($bk['payment_method'] ?? '') === 'stripe' && ($bk['payment_status'] ?? '') !== 'paid') {
            $needsFinalizing = true;
            break;
        }
    }
    if (!$needsFinalizing) {
        return;
    }

    $autoload = $adminRoot . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        return;
    }

    require_once $autoload;
    require_once INC_PATH . '/stripe.php';

    try {
        $session = stripe_client()->checkout->sessions->retrieve($sessionId);
    } catch (\Throwable $e) {
        return;
    }

    $isPaid = (($session->payment_status ?? '') === 'paid') || (($session->status ?? '') === 'complete');
    if (!$isPaid) {
        return;
    }

    $paymentId = $session->payment_intent ?? null;
    $now = date('Y-m-d H:i:s');

    foreach ($bookings as $bk) {
        if (($bk['payment_method'] ?? '') !== 'stripe') {
            continue;
        }
        db_update('bookings', [
            'payment_status'    => 'paid',
            'booking_status'    => 'confirmed',
            'stripe_session_id' => $sessionId,
            'stripe_payment_id' => $paymentId,
            'updated_at'        => $now,
        ], 'id', (int)$bk['id']);
    }
}

$token = trim($_GET['token'] ?? '');

// Support both ?ids=1,2,3 (new multi-booking) and ?id=N (legacy single)
$ids_str = '';
if (!empty($_GET['ids'])) {
    $ids_str = trim($_GET['ids']);
} elseif (!empty($_GET['id'])) {
    $ids_str = trim((string)(int)$_GET['id']);
}

if ($ids_str === '' || $token === '') {
    header('Location: /');
    exit;
}

// Verify token (HMAC of the IDs string)
$expected = hash_hmac('sha256', $ids_str, defined('PORTAL_SIGNING_KEY') ? PORTAL_SIGNING_KEY : 'booking-token-secret');
if (!hash_equals($expected, $token)) {
    // Legacy fallback: old single-booking token was HMAC of just the integer ID
    $single_id = (int)$ids_str;
    $legacy_expected = hash_hmac('sha256', (string)$single_id, defined('PORTAL_SIGNING_KEY') ? PORTAL_SIGNING_KEY : 'booking-token-secret');
    if ($single_id <= 0 || !hash_equals($legacy_expected, $token)) {
        header('Location: /');
        exit;
    }
}

// Parse IDs and fetch bookings
$id_parts = array_filter(array_map('intval', explode(',', $ids_str)), fn($v) => $v > 0);
if (empty($id_parts)) {
    header('Location: /');
    exit;
}

$bookings = [];
foreach ($id_parts as $bid) {
    $row = db_fetch('SELECT * FROM bookings WHERE id = ? LIMIT 1', [$bid]);
    if ($row) $bookings[] = $row;
}

if (empty($bookings)) {
    header('Location: /');
    exit;
}

// If Stripe sent us back with a session id, confirm payment now as a fallback.
$returnedSessionId = trim($_GET['session_id'] ?? '');
maybe_finalize_stripe_success($bookings, $returnedSessionId, $_admin_root);

// Refresh rows so UI always reflects the latest paid/confirmed state.
$bookings = [];
foreach ($id_parts as $bid) {
    $row = db_fetch('SELECT * FROM bookings WHERE id = ? LIMIT 1', [$bid]);
    if ($row) $bookings[] = $row;
}

if (empty($bookings)) {
    header('Location: /');
    exit;
}

// Use first booking for customer name / contact info
$first_booking = $bookings[0];
$grand_total   = array_sum(array_column($bookings, 'total_amount'));
$all_pending_review = true;
foreach ($bookings as $booking_row) {
    $pending_payment_states = ['unpaid', 'pending', 'pending_cash', 'pending_check'];
    if (($booking_row['booking_status'] ?? '') !== 'pending' || !in_array((string)($booking_row['payment_status'] ?? ''), $pending_payment_states, true)) {
        $all_pending_review = false;
        break;
    }
}

$company_name  = get_setting('company_name', 'Trash Panda Roll-Offs');
$company_phone = get_setting('company_phone', '');
$multi         = count($bookings) > 1;
$page_title = $all_pending_review ? 'Booking Request Received' : 'Booking Confirmed';
$terms_download_url = '/download-terms.php?ids=' . urlencode($ids_str) . '&token=' . urlencode($token);
$has_terms_document = trim((string)get_setting('booking_terms', '')) !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Black+Han+Sans&family=Barlow+Condensed:wght@400;600;700;800&family=Barlow:wght@300;400;500;600&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/shared.css">
    <!-- PWA -->
    <link rel="manifest" href="/manifest.json"/>
    <meta name="theme-color" content="#f97316"/>
    <meta name="mobile-web-app-capable" content="yes"/>
    <meta name="apple-mobile-web-app-capable" content="yes"/>
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
    <meta name="apple-mobile-web-app-title" content="Trash Panda"/>
    <link rel="apple-touch-icon" href="/assets/icon-192.png"/>
    <style>
        body { background: var(--black); color: var(--white); font-family: var(--font-body); }
        .book-nav {
            background: var(--dark);
            border-bottom: 1px solid var(--steel);
            padding: .8rem 1rem;
            display: flex;
            align-items: center;
        }
        .book-nav-brand { font-family: var(--font-display); font-size: 1.1rem; color: var(--white); text-decoration: none; }
        .book-nav-brand span { color: var(--orange); }
        .success-container { max-width: 660px; margin: 2.5rem auto; padding: 0 1rem; text-align: center; }
        .success-icon {
            width: 90px; height: 90px;
            background: rgba(34,197,94,.15);
            border: 2px solid rgba(34,197,94,.4);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            color: #22c55e;
        }
        .success-title {
            font-family: var(--font-display);
            font-size: 2.2rem;
            color: var(--white);
            margin-bottom: .5rem;
        }
        .success-title span { color: var(--orange); }
        .booking-card {
            background: var(--dark2);
            border: 1px solid var(--steel);
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            text-align: left;
        }
        .booking-card h3 {
            font-family: var(--font-cond);
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--white);
            border-bottom: 1px solid var(--steel);
            padding-bottom: .6rem;
            margin-bottom: 1rem;
        }
        .detail-row { display: flex; justify-content: space-between; padding: .4rem 0; font-size: .9rem; border-bottom: 1px solid var(--steel2); }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: var(--gray); }
        .detail-value { color: var(--white); font-weight: 500; text-align: right; }
        .total-row .detail-value { color: var(--orange); font-weight: 700; font-size: 1.1rem; }
        .booking-number-display {
            display: inline-block;
            background: rgba(249,115,22,.15);
            border: 1px solid rgba(249,115,22,.35);
            border-radius: 6px;
            padding: .4rem 1rem;
            font-family: var(--font-cond);
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--orange);
            letter-spacing: .05em;
            margin: .25rem;
        }
        .unit-row {
            background: var(--dark3);
            border: 1px solid var(--steel2);
            border-radius: 8px;
            padding: .9rem 1.1rem;
            margin-bottom: .75rem;
        }
        .unit-row:last-child { margin-bottom: 0; }
        .next-steps {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .9rem;
            margin-top: 1.5rem;
            text-align: left;
        }
        .step-card {
            background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
            border: 1px solid var(--steel);
            border-radius: 12px;
            padding: 1rem;
        }
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: rgba(249,115,22,.15);
            color: var(--orange);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-cond);
            font-weight: 800;
            margin-bottom: .75rem;
        }
        .step-card h4 {
            font-family: var(--font-cond);
            font-size: 1rem;
            color: var(--white);
            margin: 0 0 .35rem;
        }
        .step-card p {
            margin: 0;
            color: var(--gray-light);
            font-size: .86rem;
            line-height: 1.55;
        }
        .step-card.is-active {
            border-color: rgba(249,115,22,.35);
            background: linear-gradient(180deg, rgba(249,115,22,.12), rgba(249,115,22,.04));
        }
        .success-help-card {
            background: rgba(249,115,22,.08);
            border: 1px solid rgba(249,115,22,.25);
            border-radius: 12px;
            padding: 1rem 1.15rem;
            margin-top: 1.35rem;
            text-align: left;
        }
        .success-help-card strong { color: var(--white); }
        @media (max-width: 767px) {
            .next-steps { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<nav class="book-nav">
    <a href="/" class="book-nav-brand">TRASH PANDA <span>ROLL-OFFS</span></a>
</nav>

<div class="success-container">
    <div class="success-icon">
        <i class="fas fa-check"></i>
    </div>

    <?php if ($all_pending_review): ?>
    <div class="success-title">REQUEST <span>RECEIVED!</span></div>
    <p style="color:var(--gray-light);margin-bottom:1rem;">
        Thank you, <?= htmlspecialchars($first_booking['customer_name'], ENT_QUOTES, 'UTF-8') ?>.
        <?= $multi ? 'Your booking requests have' : 'Your booking request has' ?> been submitted for review. We will follow up with approval details and payment instructions if needed.
    </p>
    <?php else: ?>
    <div class="success-title">BOOKING<?= $multi ? 'S' : '' ?> <span>CONFIRMED!</span></div>
    <p style="color:var(--gray-light);margin-bottom:1rem;">
        Thank you, <?= htmlspecialchars($first_booking['customer_name'], ENT_QUOTES, 'UTF-8') ?>!
        <?= $multi ? count($bookings) . ' dumpster rentals have' : 'Your dumpster rental has' ?> been booked.
    </p>
    <?php endif; ?>

    <!-- Booking number(s) -->
    <div>
        <?php foreach ($bookings as $bk): ?>
        <span class="booking-number-display"><?= htmlspecialchars($bk['booking_number'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endforeach; ?>
    </div>

    <div class="next-steps">
        <div class="step-card is-active">
            <div class="step-number">1</div>
            <h4><?= $all_pending_review ? 'Request Received' : 'Booking Confirmed' ?></h4>
            <p>
                <?= $all_pending_review
                    ? 'Your request is safely in our queue and tied to the booking number above.'
                    : 'Your rental is on the schedule and tied to the booking number above.' ?>
            </p>
        </div>
        <div class="step-card">
            <div class="step-number">2</div>
            <h4><?= $all_pending_review ? 'We Review Availability' : 'Watch for Updates' ?></h4>
            <p>
                <?= $all_pending_review
                    ? 'Our team reviews inventory, timing, and routing before approving the order.'
                    : 'We may still send reminders, invoice updates, or portal access details before delivery.' ?>
            </p>
        </div>
        <div class="step-card">
            <div class="step-number">3</div>
            <h4><?= $all_pending_review ? 'Approval & Payment' : 'Delivery Day Prep' ?></h4>
            <p>
                <?= $all_pending_review
                    ? 'Once approved, you will receive a branded confirmation, terms PDF, and payment instructions.'
                    : 'Make sure the drop-off area is clear and accessible before your scheduled delivery date.' ?>
            </p>
        </div>
    </div>

    <?php if ($multi): ?>
    <!-- Multi-unit summary -->
    <div class="booking-card">
        <h3><i class="fas fa-dumpster" style="color:var(--orange);margin-right:.4rem;"></i> Booked Units</h3>
        <?php foreach ($bookings as $bk): ?>
        <div class="unit-row">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <span style="font-family:var(--font-cond);font-weight:700;font-size:1rem;color:var(--white);">
                        <?= htmlspecialchars($bk['unit_code'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php if ($bk['unit_size']): ?>
                    <span style="color:var(--gray);font-size:.85rem;margin-left:.4rem;">
                        <?= htmlspecialchars($bk['unit_size'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php endif; ?>
                    <div style="color:var(--gray);font-size:.8rem;margin-top:.15rem;">
                        <?= htmlspecialchars($bk['booking_number'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
                <span style="color:var(--orange);font-weight:700;">
                    <?= htmlspecialchars(fmt_money($bk['total_amount']), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Common booking details -->
    <div class="booking-card">
        <h3><i class="fas fa-receipt" style="color:var(--orange);margin-right:.4rem;"></i>
            <?= $multi ? 'Booking Details' : 'Booking Details' ?></h3>

        <?php if (!$multi): ?>
        <div class="detail-row">
            <span class="detail-label">Unit</span>
            <span class="detail-value">
                <?= htmlspecialchars($first_booking['unit_code'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                <?php if ($first_booking['unit_size']): ?>
                — <?= htmlspecialchars($first_booking['unit_size'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <div class="detail-row">
            <span class="detail-label">Start Date</span>
            <span class="detail-value"><?= htmlspecialchars(fmt_date($first_booking['rental_start']), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">End Date</span>
            <span class="detail-value"><?= htmlspecialchars(fmt_date($first_booking['rental_end']), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Duration</span>
            <span class="detail-value"><?= (int)$first_booking['rental_days'] ?> day<?= (int)$first_booking['rental_days'] !== 1 ? 's' : '' ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Payment Method</span>
            <span class="detail-value"><?= htmlspecialchars(ucfirst($first_booking['payment_method']), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php if ($first_booking['customer_address'] || $first_booking['customer_city']): ?>
        <div class="detail-row">
            <span class="detail-label">Drop-off Address</span>
            <span class="detail-value">
                <?= htmlspecialchars(trim(($first_booking['customer_address'] ?? '') . ', ' . ($first_booking['customer_city'] ?? ''), ', '), ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
        <?php endif; ?>
        <div class="detail-row total-row">
            <span class="detail-label" style="font-weight:600;">Total<?= $multi ? ' (All Units)' : '' ?></span>
            <span class="detail-value"><?= htmlspecialchars(fmt_money($grand_total), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <?php if ($has_terms_document): ?>
    <div class="booking-card" style="text-align:center;">
        <h3><i class="fas fa-file-contract" style="color:var(--orange);margin-right:.4rem;"></i> Terms & Agreement</h3>
        <p style="color:var(--gray-light);font-size:.92rem;margin-bottom:1rem;">
            Keep a copy of the signed rental terms for your records.
        </p>
        <a href="<?= htmlspecialchars($terms_download_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-panda-outline">
            <i class="fas fa-file-pdf"></i> Download Terms PDF
        </a>
    </div>
    <?php endif; ?>

    <div class="success-help-card">
        <strong>Keep this page handy.</strong><br>
        Use these booking numbers when you call, email, or check your order in the customer portal.
    </div>

    <?php if ($first_booking['payment_method'] !== 'stripe'): ?>
    <div style="background:rgba(249,115,22,.1);border:1px solid rgba(249,115,22,.3);border-radius:8px;padding:1rem;margin-top:1.25rem;font-size:.9rem;color:var(--gray-light);text-align:left;">
        <i class="fas fa-info-circle" style="color:var(--orange);"></i>
        <strong style="color:var(--white);">Payment Note:</strong>
        <?php if ($first_booking['payment_method'] === 'cash'): ?>
            Please have cash payment ready at time of delivery.
        <?php else: ?>
            Please have your check made out to
            <strong style="color:var(--white);"><?= htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') ?></strong>
            ready at time of delivery.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($company_phone): ?>
    <p style="color:var(--gray);font-size:.85rem;margin-top:1.5rem;">
        Questions? Call us at
        <a href="tel:<?= htmlspecialchars(preg_replace('/\D/', '', $company_phone), ENT_QUOTES, 'UTF-8') ?>"
           style="color:var(--orange);">
            <?= htmlspecialchars(fmt_phone($company_phone), ENT_QUOTES, 'UTF-8') ?>
        </a>
    </p>
    <?php endif; ?>

    <div class="d-flex gap-3 justify-content-center mt-4">
        <a href="/" class="btn-panda-outline"><i class="fas fa-home"></i> Back to Home</a>
        <a href="/book.php" class="btn-ghost">Book Another</a>
    </div>
</div>

    <script>if('serviceWorker'in navigator)navigator.serviceWorker.register('/sw.js').catch(()=>{});</script>
</body>
</html>
