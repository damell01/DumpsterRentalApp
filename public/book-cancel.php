<?php
/**
 * Booking Canceled/Abandoned Page — Trash Panda Roll-Offs
 */

$_admin_root = dirname(__DIR__) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';

$company_name  = get_setting('company_name', 'Trash Panda Roll-Offs');
$company_phone = get_setting('company_phone', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Canceled — <?= htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') ?></title>
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
        .cancel-container { max-width: 520px; margin: 3rem auto; padding: 0 1rem; text-align: center; }
        .cancel-icon {
            width: 90px; height: 90px;
            background: rgba(249,115,22,.12);
            border: 2px solid rgba(249,115,22,.35);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            color: var(--orange);
        }
        .cancel-title {
            font-family: var(--font-display);
            font-size: 2rem;
            color: var(--white);
            margin-bottom: .5rem;
        }
    </style>
</head>
<body>

<nav class="book-nav">
    <a href="/" class="book-nav-brand">TRASH PANDA <span>ROLL-OFFS</span></a>
</nav>

<div class="cancel-container">
    <div class="cancel-icon">
        <i class="fas fa-times"></i>
    </div>

    <div class="cancel-title">PAYMENT CANCELED</div>
    <p style="color:var(--gray-light);margin-bottom:2rem;">
        Your payment was not completed and no booking was confirmed.
        No charge has been made to your card.
    </p>

    <?php if ($company_phone): ?>
    <p style="color:var(--gray);font-size:.9rem;margin-bottom:1.5rem;">
        Need help? Call us at
        <a href="tel:<?= htmlspecialchars(preg_replace('/\D/', '', $company_phone), ENT_QUOTES, 'UTF-8') ?>"
           style="color:var(--orange);">
            <?= htmlspecialchars(fmt_phone($company_phone), ENT_QUOTES, 'UTF-8') ?>
        </a>
    </p>
    <?php endif; ?>

    <div class="d-flex gap-3 justify-content-center flex-wrap">
        <a href="/book.php" class="btn-panda">
            <i class="fas fa-redo"></i> Try Again
        </a>
        <a href="/" class="btn-ghost">
            <i class="fas fa-home"></i> Back to Home
        </a>
    </div>
</div>

    <script>if('serviceWorker'in navigator)navigator.serviceWorker.register('/sw.js').catch(()=>{});</script>
</body>
</html>
