<?php

$_admin_root = dirname(__DIR__, 2) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/helpers.php';

$company_name = get_setting('company_name', 'Trash Panda Roll-Offs');
$prefill_email = trim((string)($_GET['email'] ?? ''));

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self';");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Portal Access - <?= e($company_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Black+Han+Sans&family=Barlow+Condensed:wght@400;600;700;800&family=Barlow:wght@300;400;500;600&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/shared.css">
    <style>
        body {
            background: var(--black);
            color: var(--white);
            font-family: var(--font-body);
            min-height: 100vh;
        }
        .portal-nav {
            background: var(--dark);
            border-bottom: 1px solid var(--steel);
            padding: .9rem 1rem;
        }
        .portal-nav-inner {
            max-width: 1180px;
            margin: 0 auto;
            display: flex;
            align-items: center;
        }
        .portal-brand {
            font-family: var(--font-display);
            font-size: 1.05rem;
            color: var(--white);
            text-decoration: none;
        }
        .portal-brand span { color: var(--orange); }
        .request-shell {
            max-width: 1180px;
            margin: 0 auto;
            padding: 3rem 1rem 4.5rem;
        }
        .request-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.08fr) minmax(360px, .92fr);
            gap: 1.4rem;
            align-items: stretch;
        }
        .request-story,
        .request-card {
            border-radius: 18px;
            border: 1px solid var(--steel);
        }
        .request-story {
            background:
                radial-gradient(circle at top right, rgba(249,115,22,.16), transparent 34%),
                linear-gradient(135deg, #11141b 0%, #181d27 55%, #121417 100%);
            padding: 2.3rem 2.15rem;
            position: relative;
            overflow: hidden;
        }
        .request-overline {
            color: var(--orange-light);
            font-size: .78rem;
            letter-spacing: .18em;
            text-transform: uppercase;
            font-family: var(--font-cond);
        }
        .request-hero-title {
            font-family: var(--font-display);
            font-size: clamp(2rem, 5vw, 3.15rem);
            line-height: .98;
            margin: .6rem 0 .85rem;
        }
        .request-hero-title span { color: var(--orange); }
        .request-lead {
            color: var(--gray-light);
            font-size: 1rem;
            line-height: 1.7;
            max-width: 640px;
        }
        .request-points {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .8rem;
            margin-top: 1.65rem;
        }
        .request-point {
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 14px;
            padding: 1rem;
        }
        .request-point i {
            color: var(--orange);
            font-size: 1rem;
            margin-bottom: .6rem;
        }
        .request-point h4 {
            font-family: var(--font-cond);
            font-size: .95rem;
            margin: 0 0 .3rem;
            color: var(--white);
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .request-point p {
            margin: 0;
            color: var(--gray-light);
            font-size: .84rem;
            line-height: 1.55;
        }
        .request-card {
            background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01));
            padding: 2.35rem 2.1rem;
        }
        .request-icon {
            width: 60px;
            height: 60px;
            background: rgba(249,115,22,.12);
            border: 2px solid rgba(249,115,22,.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 1.5rem;
            color: var(--orange);
        }
        .request-title {
            font-family: var(--font-display);
            font-size: 1.9rem;
            text-align: center;
            margin-bottom: .5rem;
        }
        .request-title span { color: var(--orange); }
        .request-sub {
            text-align: center;
            color: var(--gray-light);
            font-size: .94rem;
            line-height: 1.7;
            margin-bottom: 1.9rem;
        }
        .form-label {
            color: var(--gray-light);
            font-size: .88rem;
            margin-bottom: .35rem;
        }
        .form-control {
            background: var(--dark3);
            border: 1px solid var(--steel2);
            color: var(--white);
            border-radius: 10px;
            padding: .88rem 1rem;
        }
        .form-control:focus {
            background: var(--dark3);
            border-color: var(--orange);
            color: var(--white);
            box-shadow: 0 0 0 3px rgba(249,115,22,.2);
        }
        .form-control::placeholder { color: var(--gray); }
        .msg-success,
        .msg-error {
            border-radius: 10px;
            padding: 1rem 1.05rem;
            font-size: .9rem;
            display: none;
        }
        .msg-success {
            background: rgba(34,197,94,.12);
            border: 1px solid rgba(34,197,94,.3);
            color: #86efac;
        }
        .msg-error {
            background: rgba(239,68,68,.12);
            border: 1px solid rgba(239,68,68,.3);
            color: #fca5a5;
        }
        .security-note {
            margin-top: 1.4rem;
            text-align: center;
            color: var(--gray);
            font-size: .78rem;
            line-height: 1.6;
        }
        .security-note i { color: var(--orange); margin-right: .25rem; }
        .spinner-inline {
            width: 1rem;
            height: 1rem;
            border: 2px solid rgba(255,255,255,.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            display: inline-block;
            vertical-align: middle;
            margin-right: .4rem;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (max-width: 991px) {
            .request-hero {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 767px) {
            .request-shell {
                padding: 2.25rem .9rem 3.75rem;
            }
            .request-story,
            .request-card {
                padding: 1.55rem 1.3rem;
            }
            .request-points {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<nav class="portal-nav">
    <div class="portal-nav-inner">
        <a href="/" class="portal-brand">TRASH PANDA <span>ROLL-OFFS</span></a>
    </div>
</nav>

<div class="request-shell">
    <div class="request-hero">
        <section class="request-story">
            <div class="request-overline">Secure customer access</div>
            <h1 class="request-hero-title">YOUR <span>TRASH PANDA</span><br>BILLING HUB</h1>
            <p class="request-lead">
                Get a secure one-time link to review invoices, payment history, saved billing methods, and subscription activity without needing a password.
            </p>
            <div class="request-points">
                <div class="request-point">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                    <h4>Invoices</h4>
                    <p>Review open balances and jump straight to online payment links when available.</p>
                </div>
                <div class="request-point">
                    <i class="fa-solid fa-wallet"></i>
                    <h4>Payment Methods</h4>
                    <p>Check the cards or bank methods on file and remove anything you no longer use.</p>
                </div>
                <div class="request-point">
                    <i class="fa-solid fa-arrows-rotate"></i>
                    <h4>Recurring Services</h4>
                    <p>View subscription activity, request changes, and manage billing with less back-and-forth.</p>
                </div>
            </div>
        </section>

        <section class="request-card">
            <div class="request-icon">
                <i class="fa-solid fa-lock"></i>
            </div>
            <div class="request-title">BILLING <span>PORTAL</span></div>
            <p class="request-sub">Enter your billing email and we’ll send a secure one-time link to access your invoices, subscriptions, and saved payment methods.</p>

            <div id="msg-success" class="msg-success mb-3">
                <i class="fa-solid fa-circle-check me-2"></i>
                If that email address is on file, a secure portal link has been sent. Check your inbox.
            </div>
            <div id="msg-error" class="msg-error mb-3"></div>

            <div class="mb-3">
                <label class="form-label" for="portal-email">Email address</label>
                <input type="email" id="portal-email" class="form-control"
                       placeholder="you@example.com"
                       value="<?= e($prefill_email) ?>"
                       autocomplete="email">
            </div>
            <button id="btnSend" class="btn-panda w-100" onclick="requestPortalLink()">
                <i class="fa-solid fa-paper-plane me-1"></i> Send Secure Link
            </button>

            <p class="security-note">
                <i class="fa-solid fa-lock"></i>
                Links are single-use and expire automatically. No password needed.
            </p>
        </section>
    </div>
</div>

<script>
function requestPortalLink() {
    var email  = document.getElementById('portal-email').value.trim();
    var btn    = document.getElementById('btnSend');
    var ok     = document.getElementById('msg-success');
    var err    = document.getElementById('msg-error');
    ok.style.display  = 'none';
    err.style.display = 'none';

    if (!email) {
        err.textContent  = 'Please enter your email address.';
        err.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-inline"></span> Sending...';

    fetch('/api/request-portal-link.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Send Secure Link';
        if (data.success) {
            ok.style.display = 'block';
            document.getElementById('portal-email').value = '';
        } else {
            err.textContent = data.error || 'Unable to send link. Please try again.';
            err.style.display = 'block';
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Send Secure Link';
        err.textContent = 'Network error. Please try again.';
        err.style.display = 'block';
    });
}

document.getElementById('portal-email').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') requestPortalLink();
});
</script>
</body>
</html>
