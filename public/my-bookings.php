<?php
/**
 * My Bookings — Trash Panda Roll-Offs
 * Customers look up their bookings by email or phone number.
 */

$_admin_root = dirname(__DIR__) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';

$company_name = get_setting('company_name', 'Trash Panda Roll-Offs');

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self';");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings — <?= htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') ?></title>
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

        /* ── Page hero ─────────────────────────────────────────────────────── */
        .page-hero {
            background: var(--dark2);
            padding: 3rem 0 2rem;
            border-bottom: 1px solid var(--steel);
        }
        .page-hero h1 {
            font-family: var(--font-display);
            font-size: clamp(1.8rem, 5vw, 2.75rem);
            color: var(--white);
            margin: 0;
        }
        .page-hero h1 span { color: var(--orange); }

        .page-container { max-width: 900px; margin: 0 auto; padding: 3rem 1.1rem 4.75rem; }

        /* ── Card ──────────────────────────────────────────────────────────── */
        .book-card {
            background: var(--dark2);
            border: 1px solid var(--steel);
            border-radius: 14px;
            padding: 1.95rem;
            margin-bottom: 1.7rem;
        }
        .book-card h2 {
            font-family: var(--font-cond);
            font-weight: 700;
            font-size: 1.3rem;
            color: var(--white);
            margin-bottom: 1.25rem;
            padding-bottom: .75rem;
            border-bottom: 1px solid var(--steel);
        }
        .book-card h2 i { color: var(--orange); margin-right: .4rem; }

        /* ── Form ──────────────────────────────────────────────────────────── */
        .form-label { color: var(--gray-light); font-size: .9rem; margin-bottom: .35rem; }
        .form-control {
            background: var(--dark3);
            border: 1px solid var(--steel2);
            color: var(--white);
            border-radius: 6px;
        }
        .form-control:focus {
            background: var(--dark3);
            border-color: var(--orange);
            color: var(--white);
            box-shadow: 0 0 0 3px rgba(249,115,22,.2);
        }
        .form-control::placeholder { color: var(--gray); }

        /* ── Alerts ─────────────────────────────────────────────────────────── */
        .alert-err  { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.4);   color: #fca5a5; border-radius: 8px; padding: .9rem 1rem; font-size: .9rem; }
        .alert-info { background: rgba(249,115,22,.12); border: 1px solid rgba(249,115,22,.35); color: #fdba74; border-radius: 8px; padding: .9rem 1rem; font-size: .9rem; }
        .alert-ok   { background: rgba(34,197,94,.12); border: 1px solid rgba(34,197,94,.35);   color: #86efac; border-radius: 8px; padding: .9rem 1rem; font-size: .9rem; }

        /* ── Booking list ───────────────────────────────────────────────────── */
        #bookings-list { margin-top: 0; }

        .bk-card {
            background: var(--dark3);
            border: 1px solid var(--steel);
            border-radius: 14px;
            padding: 1.45rem 1.65rem;
            margin-bottom: 1.1rem;
            position: relative;
            overflow: hidden;
        }
        .bk-card + .bk-card { margin-top: 0; }
        .bk-card::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: linear-gradient(180deg, rgba(249,115,22,.9), rgba(249,115,22,.18));
        }
        .bk-card.is-awaiting::before { background: linear-gradient(180deg, rgba(245,158,11,.9), rgba(245,158,11,.18)); }
        .bk-card.is-paid::before { background: linear-gradient(180deg, rgba(34,197,94,.9), rgba(34,197,94,.18)); }
        .bk-card.is-canceled::before { background: linear-gradient(180deg, rgba(239,68,68,.9), rgba(239,68,68,.18)); }

        .bk-number {
            font-family: var(--font-cond);
            font-weight: 800;
            font-size: 1rem;
            color: var(--orange);
            letter-spacing: .04em;
        }
        .bk-unit {
            font-family: var(--font-cond);
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--white);
        }
        .bk-unit-stack {
            display: grid;
            gap: .65rem;
            margin-top: .55rem;
        }
        .bk-unit-item {
            background:
                linear-gradient(135deg, rgba(255,255,255,.05), rgba(255,255,255,.02)),
                rgba(255,255,255,.025);
            border: 1px solid rgba(249,115,22,.12);
            border-radius: 12px;
            padding: .9rem 1rem;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.03);
            position: relative;
            overflow: hidden;
        }
        .bk-unit-item::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 3px;
            background: linear-gradient(180deg, rgba(249,115,22,.95), rgba(249,115,22,.15));
        }
        .bk-unit-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            flex-wrap: wrap;
        }
        .bk-unit-code {
            font-family: var(--font-cond);
            font-size: 1.03rem;
            font-weight: 700;
            color: var(--white);
            letter-spacing: .02em;
        }
        .bk-unit-size {
            color: var(--gray-light);
            font-size: .82rem;
            margin-top: .24rem;
        }
        .bk-unit-dates {
            color: var(--gray);
            font-size: .77rem;
            margin-top: .55rem;
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
        }
        .bk-dates { font-size: .85rem; color: var(--gray-light); }
        .bk-meta  { font-size: .8rem;  color: var(--gray); margin-top: .15rem; }
        .bk-actions {
            display: flex;
            gap: .65rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        .bk-actions .btn-panda,
        .bk-actions .btn-ghost {
            padding: 10px 18px;
            font-size: .85rem;
        }
        .invoice-chip {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(249,115,22,.12);
            border: 1px solid rgba(249,115,22,.28);
            color: #fdba74;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            margin-top: .7rem;
        }
        .invoice-meta {
            margin-top: .45rem;
            color: var(--gray-light);
            font-size: .78rem;
        }
        .bk-highlight-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .7rem;
            margin-top: .95rem;
        }
        .bk-highlight {
            background: rgba(255,255,255,.03);
            border: 1px solid rgba(255,255,255,.05);
            border-radius: 9px;
            padding: .75rem .8rem;
        }
        .bk-highlight-label {
            font-size: .68rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: .08em;
            font-family: var(--font-cond);
        }
        .bk-highlight-value {
            margin-top: .32rem;
            color: var(--white);
            font-weight: 700;
            font-size: .9rem;
        }
        .results-intro {
            background: linear-gradient(135deg, rgba(249,115,22,.12), rgba(249,115,22,.04));
            border: 1px solid rgba(249,115,22,.2);
            border-radius: 10px;
            padding: 1rem 1.1rem;
            margin-bottom: 1rem;
            color: var(--gray-light);
            font-size: .9rem;
        }
        .results-intro strong { color: var(--white); }
        @media (max-width: 767px) {
            .bk-highlight-row { grid-template-columns: 1fr; }
            .page-container { padding: 2.15rem .9rem 3.75rem; }
            .book-card { padding: 1.35rem; }
            .bk-card { padding: 1.15rem 1.2rem 1.2rem 1.3rem; }
            .bk-unit-item { padding: .8rem .82rem; }
        }

        .status-badge {
            display: inline-block;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            border-radius: 4px;
            padding: 2px 8px;
        }
        .status-confirmed, .status-paid    { background: rgba(34,197,94,.15);  color: #86efac; border: 1px solid rgba(34,197,94,.3); }
        .status-pending                    { background: rgba(249,115,22,.12); color: #fdba74; border: 1px solid rgba(249,115,22,.3); }
        .status-canceled                   { background: rgba(239,68,68,.12);  color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
        .status-completed                  { background: rgba(99,102,241,.12); color: #a5b4fc; border: 1px solid rgba(99,102,241,.3); }
        .pay-paid                          { background: rgba(34,197,94,.15);  color: #86efac; border: 1px solid rgba(34,197,94,.3); }
        .pay-pending, .pay-pending_cash,
        .pay-pending_check                 { background: rgba(249,115,22,.12); color: #fdba74; border: 1px solid rgba(249,115,22,.3); }
        .pay-unpaid                        { background: rgba(239,68,68,.12);  color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }

        /* ── Push banner ─────────────────────────────────────────────────────── */
        #push-banner {
            background: rgba(249,115,22,.1);
            border: 1px solid rgba(249,115,22,.35);
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        #push-banner i { font-size: 1.5rem; color: var(--orange); flex-shrink: 0; }
        #push-banner .push-text { flex: 1; min-width: 200px; font-size: .9rem; color: var(--gray-light); }
        #push-banner .push-text strong { color: var(--white); }

        /* ── Loading spinner ─────────────────────────────────────────────────── */
        .spinner-inline {
            width: 1rem; height: 1rem;
            border: 2px solid rgba(255,255,255,.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            display: inline-block;
            vertical-align: middle;
            margin-right: .4rem;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Nav ─────────────────────────────────────────────────────────────── */
        .book-nav {
            background: var(--dark);
            border-bottom: 1px solid var(--steel);
            padding: .8rem 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .book-nav-brand {
            font-family: var(--font-display);
            font-size: 1.1rem;
            color: var(--white);
            text-decoration: none;
        }
        .book-nav-brand span { color: var(--orange); }
    </style>
</head>
<body>

<!-- Nav -->
<nav class="book-nav">
    <a href="/" class="book-nav-brand">TRASH PANDA <span>ROLL-OFFS</span></a>
    <a href="/" style="color:var(--gray);font-size:.85rem;margin-left:auto;">
        <i class="fas fa-arrow-left"></i> Back to Home
    </a>
</nav>

<!-- Hero -->
<div class="page-hero">
    <div class="page-container" style="padding-top:0;padding-bottom:0;">
        <h1>MY <span>BOOKINGS</span></h1>
        <p style="color:var(--gray-light);margin-top:.5rem;font-size:1rem;">
            Enter the email address or phone number you used when booking to view your rentals.
        </p>
    </div>
</div>

<!-- Main -->
<div class="page-container">

    <!-- Lookup form -->
    <div class="book-card" id="lookup-card">
        <h2><i class="fas fa-search"></i> Look Up Your Bookings</h2>

        <div id="lookup-error" class="alert-err mb-3" style="display:none;"></div>

        <div class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label" for="identifier">Email Address or Phone Number</label>
                <input type="text" id="identifier" class="form-control"
                       placeholder="you@example.com  or  (251) 555-1234"
                       autocomplete="email" inputmode="text">
            </div>
            <div class="col-md-4">
                <button id="btnLookup" class="btn-panda w-100" onclick="lookupBookings()">
                    <i class="fas fa-search"></i> Find Bookings
                </button>
            </div>
        </div>
        <p class="mb-0 mt-3" style="font-size:.8rem;color:var(--gray);">
            <i class="fas fa-lock" style="color:var(--orange);"></i>
            Your information is only used to retrieve your bookings and is never shared.
        </p>
    </div>

    <!-- Push notification banner (shown after results load) -->
    <div id="push-banner" style="display:none;">
        <i class="fas fa-bell"></i>
        <div class="push-text">
            <strong>Stay updated on your rental.</strong><br>
            Enable push notifications to get alerts when your dumpster is on the way, payment is confirmed, or your rental is ending.
        </div>
        <button class="btn-panda" style="flex-shrink:0;" onclick="requestPushPermission()">
            <i class="fas fa-bell"></i> Enable Alerts
        </button>
    </div>

    <!-- Results -->
    <div id="results-wrap" style="display:none;">
        <div class="book-card">
            <h2><i class="fas fa-calendar-alt"></i> Your Bookings <span id="booking-count-badge" style="font-size:.85rem;color:var(--gray);font-weight:400;"></span></h2>
            <div class="results-intro" id="results-intro-copy" style="display:none;"></div>
            <div id="bookings-list"></div>
        </div>
        <div class="text-center mt-2">
            <button class="btn-ghost" onclick="resetLookup()">
                <i class="fas fa-arrow-left"></i> Look Up a Different Number
            </button>
        </div>
    </div>

    <!-- No results -->
    <div id="no-results-wrap" style="display:none;" class="text-center" style="padding:2rem 0;">
        <div class="book-card" style="text-align:center;">
            <i class="fas fa-inbox" style="font-size:3rem;color:var(--steel2);display:block;margin-bottom:1rem;"></i>
            <h3 style="font-family:var(--font-cond);color:var(--white);">No bookings found</h3>
            <p style="color:var(--gray-light);font-size:.9rem;">
                We couldn't find any bookings linked to that email or phone number.<br>
                Double-check for typos, or <a href="/contact.html" style="color:var(--orange);">contact us</a> for help.
            </p>
            <div class="mt-3 d-flex justify-content-center gap-3 flex-wrap">
                <button class="btn-ghost" onclick="resetLookup()">
                    <i class="fas fa-search"></i> Try Again
                </button>
                <a href="/book.php" class="btn-panda">
                    <i class="fas fa-calendar-plus"></i> Book Now
                </a>
            </div>
        </div>
    </div>

</div><!-- /.page-container -->

<script>
var _pushIdentifier = '';

// ── Lookup ─────────────────────────────────────────────────────────────────────
function lookupBookings() {
    var id     = document.getElementById('identifier').value.trim();
    var errEl  = document.getElementById('lookup-error');
    var btnEl  = document.getElementById('btnLookup');
    errEl.style.display = 'none';

    if (!id) {
        errEl.textContent = 'Please enter your email or phone number.';
        errEl.style.display = 'block';
        return;
    }

    btnEl.disabled = true;
    btnEl.innerHTML = '<span class="spinner-inline"></span> Searching…';

    fetch('/api/lookup-bookings.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ identifier: id })
    })
    .then(function(r) {
        return r.text().then(function(text) {
            var data = {};
            try {
                data = text ? JSON.parse(text) : {};
            } catch (e) {
                data = {};
            }

            if (!r.ok) {
                var msg = data.error || ('Lookup failed with HTTP ' + r.status + '.');
                throw new Error(msg);
            }

            return data;
        });
    })
    .then(function(data) {
        btnEl.disabled = false;
        btnEl.innerHTML = '<i class="fas fa-search"></i> Find Bookings';

        if (!data.success) {
            errEl.innerHTML = '<i class="fas fa-times-circle"></i> ' + (data.error || 'An error occurred.');
            errEl.style.display = 'block';
            return;
        }

        _pushIdentifier = id;

        if (data.count === 0) {
            document.getElementById('no-results-wrap').style.display = 'block';
            document.getElementById('results-wrap').style.display    = 'none';
            document.getElementById('push-banner').style.display     = 'none';
        } else {
            renderBookings(data.bookings);
            document.getElementById('results-wrap').style.display    = 'block';
            document.getElementById('no-results-wrap').style.display = 'none';
            // Show push banner if notifications are not yet granted
            if ('Notification' in window && Notification.permission !== 'granted') {
                document.getElementById('push-banner').style.display = 'flex';
            }
        }

        // Scroll to the visible result section
        var scrollTarget = data.count === 0 ? 'no-results-wrap' : 'results-wrap';
        document.getElementById(scrollTarget).scrollIntoView({ behavior: 'smooth', block: 'start' });
    })
    .catch(function(err) {
        btnEl.disabled = false;
        btnEl.innerHTML = '<i class="fas fa-search"></i> Find Bookings';
        errEl.textContent = err && err.message ? err.message : 'Network error. Please try again.';
        errEl.style.display = 'block';
    });
}

function renderBookings(bookings) {
    var list = document.getElementById('bookings-list');
    var countBadge = document.getElementById('booking-count-badge');
    var intro = document.getElementById('results-intro-copy');
    var groupedBookings = groupBookings(bookings);
    countBadge.textContent = '(' + groupedBookings.length + ')';
    intro.style.display = 'block';
    intro.innerHTML = '<strong>' + groupedBookings.length + ' booking' + (groupedBookings.length === 1 ? '' : 's') + ' found.</strong> '
        + 'Units booked together are grouped into a single card so each order is easier to review.';
    list.innerHTML = '';

    groupedBookings.forEach(function(b) {
        var bkStatus  = statusLabel(b.booking_status);
        var payStatus = payLabel(b.payment_status);
        var invoice = b.invoice || null;

        var start  = formatDate(b.rental_start);
        var end    = formatDate(b.rental_end);
        var total  = '$' + parseFloat(b.total_amount).toFixed(2);
        var addr   = b.address ? '<i class="fas fa-map-marker-alt" style="color:var(--orange);width:12px;"></i> ' + escHtml(b.address) + ' &nbsp;·&nbsp; ' : '';
        var days   = parseInt(b.rental_days, 10) > 1 ? parseInt(b.rental_days, 10) + ' days' : '1 day';
        var portalUrl = b.customer_email ? '/portal/request-link.php?email=' + encodeURIComponent(b.customer_email) : '/portal/request-link.php';

        // Build unit line — show sibling units if this is part of a group
        var unitSummary = b.units.length === 1
            ? b.units[0].label
            : b.units.length + ' units booked together';
        var unitHtml = '<div class="bk-unit">' + escHtml(unitSummary) + '</div>';
        unitHtml += '<div class="bk-unit-stack">';
        b.units.forEach(function(unit) {
            unitHtml += '<div class="bk-unit-item">'
                + '<div class="bk-unit-top">'
                +   '<div>'
                +     '<div class="bk-unit-code">' + escHtml(unit.code || 'Booked Unit') + '</div>'
                +     '<div class="bk-unit-size">' + escHtml(unit.size || 'Dumpster rental') + '</div>'
                +   '</div>'
                +   '<span class="status-badge status-' + escHtml(unit.booking_status) + '">' + escHtml(statusLabel(unit.booking_status)) + '</span>'
                + '</div>'
                + '<div class="bk-unit-dates">'
                +   '<span><i class="fas fa-calendar-alt" style="color:var(--orange);margin-right:.3rem;"></i>' + escHtml(formatDate(unit.rental_start)) + ' → ' + escHtml(formatDate(unit.rental_end)) + '</span>'
                +   '<span><i class="fas fa-arrows-left-right-to-line" style="color:var(--orange);margin-right:.3rem;"></i>' + escHtml(unit.days_label) + '</span>'
                +   '<span><i class="fas fa-dollar-sign" style="color:var(--orange);margin-right:.3rem;"></i>$' + parseFloat(unit.total_amount).toFixed(2) + '</span>'
                + '</div>'
                + '</div>';
        });
        unitHtml += '</div>';

        var invoiceHtml = '';
        if (invoice && invoice.number) {
            invoiceHtml += '<div class="invoice-chip"><i class="fas fa-file-invoice-dollar"></i> Invoice ' + escHtml(invoice.number) + '</div>';
            invoiceHtml += '<div class="invoice-meta">'
                + 'Status: ' + escHtml(invoiceLabel(invoice.status))
                + (invoice.total > 0 ? ' &nbsp;·&nbsp; Total: $' + parseFloat(invoice.total).toFixed(2) : '')
                + '</div>';
        }

        var actionsHtml = '';
        if (invoice && invoice.payment_link && invoice.status !== 'paid') {
            actionsHtml += '<a class="btn-panda" href="' + escHtml(invoice.payment_link) + '" target="_blank" rel="noopener">'
                + '<i class="fas fa-credit-card"></i> Pay Invoice</a>';
        }
        if (invoice && invoice.number) {
            actionsHtml += '<a class="btn-ghost" href="' + escHtml(portalUrl) + '">'
                + '<i class="fas fa-file-lines"></i> View Billing Portal</a>';
        }

        var invoicePaid = invoice && invoice.status === 'paid';
        var cardClass = 'bk-card';
        if (b.booking_status === 'canceled') {
            cardClass += ' is-canceled';
        } else if (b.payment_status === 'paid' || invoicePaid || b.booking_status === 'confirmed' || b.booking_status === 'completed') {
            cardClass += ' is-paid';
        } else {
            cardClass += ' is-awaiting';
        }

        var invoiceValue = invoice && invoice.number ? escHtml(invoice.number) : 'Pending';
        var nextStep = 'Awaiting next update';
        if (b.booking_status === 'canceled') {
            nextStep = 'Order canceled';
        } else if (b.payment_status === 'paid' || invoicePaid) {
            nextStep = 'Paid and scheduled';
        } else if (b.booking_status === 'pending') {
            nextStep = 'Waiting for review';
        } else if (invoice && invoice.payment_link && invoice.status !== 'paid') {
            nextStep = 'Invoice ready to pay';
        }

        var html = '<div class="' + cardClass + '">'
            + '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">'
            +   '<div>'
            +     '<div class="bk-number">' + escHtml(b.booking_number) + '</div>'
            +     unitHtml
            +   '</div>'
            +   '<div class="d-flex gap-2 flex-wrap">'
            +     '<span class="status-badge status-' + escHtml(b.booking_status) + '">' + bkStatus + '</span>'
            +     '<span class="status-badge pay-' + escHtml(b.payment_status) + '">' + payStatus + '</span>'
            +   '</div>'
            + '</div>'
            + '<div class="bk-dates"><i class="fas fa-calendar-alt" style="color:var(--orange);width:14px;"></i> ' + escHtml(start) + ' → ' + escHtml(end) + ' &nbsp;·&nbsp; ' + escHtml(days) + '</div>'
            + '<div class="bk-meta">' + addr + '<i class="fas fa-dollar-sign" style="color:var(--orange);width:12px;"></i> ' + total + ' &nbsp;·&nbsp; ' + escHtml(pmLabel(b.payment_method)) + '</div>'
            + '<div class="bk-highlight-row">'
            +   '<div class="bk-highlight"><div class="bk-highlight-label">Invoice</div><div class="bk-highlight-value">' + invoiceValue + '</div></div>'
            +   '<div class="bk-highlight"><div class="bk-highlight-label">Payment</div><div class="bk-highlight-value">' + escHtml(invoicePaid ? 'Paid' : payStatus) + '</div></div>'
            +   '<div class="bk-highlight"><div class="bk-highlight-label">Next Step</div><div class="bk-highlight-value">' + escHtml(nextStep) + '</div></div>'
            + '</div>'
            + invoiceHtml
            + (actionsHtml ? '<div class="bk-actions">' + actionsHtml + '</div>' : '')
            + '</div>';
        list.innerHTML += html;
    });
}

function resetLookup() {
    document.getElementById('results-wrap').style.display    = 'none';
    document.getElementById('no-results-wrap').style.display = 'none';
    document.getElementById('push-banner').style.display     = 'none';
    document.getElementById('lookup-error').style.display    = 'none';
    document.getElementById('results-intro-copy').style.display = 'none';
    document.getElementById('identifier').value              = '';
    document.getElementById('lookup-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Formatters ─────────────────────────────────────────────────────────────────
function groupBookings(bookings) {
    var grouped = {};

    bookings.forEach(function(booking) {
        var key = booking.booking_number || ('row-' + (booking.id || Math.random()));
        if (!grouped[key]) {
            grouped[key] = {
                id: booking.id,
                booking_number: booking.booking_number,
                booking_status: booking.booking_status,
                payment_status: booking.payment_status,
                payment_method: booking.payment_method,
                rental_start: booking.rental_start,
                rental_end: booking.rental_end,
                rental_days: booking.rental_days,
                total_amount: parseFloat(booking.total_amount || 0),
                address: booking.address,
                customer_email: booking.customer_email,
                invoice: booking.invoice || null,
                units: []
            };
        } else {
            grouped[key].total_amount += parseFloat(booking.total_amount || 0);
            grouped[key].rental_start = minDate(grouped[key].rental_start, booking.rental_start);
            grouped[key].rental_end = maxDate(grouped[key].rental_end, booking.rental_end);
            grouped[key].rental_days = calcRentalDays(grouped[key].rental_start, grouped[key].rental_end);

            if ((!grouped[key].invoice || !grouped[key].invoice.number) && booking.invoice) {
                grouped[key].invoice = booking.invoice;
            }
            if (grouped[key].payment_status !== 'paid' && booking.payment_status === 'paid') {
                grouped[key].payment_status = booking.payment_status;
            }
            if (grouped[key].booking_status === 'pending' && booking.booking_status !== 'pending') {
                grouped[key].booking_status = booking.booking_status;
            }
        }

        grouped[key].units.push({
            code: booking.unit_code || '',
            size: booking.unit_size || booking.unit || '',
            rental_start: booking.rental_start,
            rental_end: booking.rental_end,
            booking_status: booking.booking_status,
            total_amount: parseFloat(booking.total_amount || 0),
            days_label: unitDaysLabel(booking.rental_days, booking.rental_start, booking.rental_end),
            label: booking.unit || ((booking.unit_code || '') + ((booking.unit_size || '') ? ' - ' + booking.unit_size : ''))
        });
    });

    return Object.keys(grouped).map(function(key) {
        grouped[key].total_amount = grouped[key].total_amount.toFixed(2);
        grouped[key].units.sort(function(a, b) {
            return String(a.code || '').localeCompare(String(b.code || ''));
        });
        return grouped[key];
    });
}

function minDate(a, b) {
    if (!a) return b || '';
    if (!b) return a;
    return a < b ? a : b;
}

function maxDate(a, b) {
    if (!a) return b || '';
    if (!b) return a;
    return a > b ? a : b;
}

function calcRentalDays(start, end) {
    if (!start || !end) return 1;
    var startDate = new Date(start + 'T00:00:00');
    var endDate = new Date(end + 'T00:00:00');
    var diff = Math.round((endDate - startDate) / 86400000) + 1;
    return diff > 0 ? diff : 1;
}

function unitDaysLabel(rentalDays, start, end) {
    var days = parseInt(rentalDays, 10);
    if (!days || days < 1) {
        days = calcRentalDays(start, end);
    }
    return days === 1 ? '1 day' : days + ' days';
}

function statusLabel(s) {
    var map = { confirmed: 'Confirmed', pending: 'Pending', canceled: 'Canceled', completed: 'Completed', paid: 'Paid' };
    return map[s] || s;
}
function payLabel(s) {
    var map = { paid: 'Paid', pending: 'Awaiting Payment', pending_cash: 'Pay by Cash', pending_check: 'Pay by Check', unpaid: 'Unpaid' };
    return map[s] || s;
}
function invoiceLabel(s) {
    var map = { draft: 'Draft', sent: 'Sent', paid: 'Paid', void: 'Void', overdue: 'Overdue' };
    return map[s] || s;
}
function pmLabel(s) {
    var map = { stripe: 'Card (Online)', cash: 'Cash', check: 'Check' };
    return map[s] || s;
}
function formatDate(d) {
    if (!d) return '';
    var parts = d.split('-');
    if (parts.length !== 3) return d;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[parseInt(parts[1], 10) - 1] + ' ' + parseInt(parts[2], 10) + ', ' + parts[0];
}
function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Enter key support ──────────────────────────────────────────────────────────
document.getElementById('identifier').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') lookupBookings();
});

// ── Push notifications ─────────────────────────────────────────────────────────
function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw     = window.atob(base64);
    var arr     = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; ++i) arr[i] = raw.charCodeAt(i);
    return arr;
}

function subscribeToPush(identifier) {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    navigator.serviceWorker.ready.then(function(reg) {
        return fetch('/api/push-subscribe.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'getVapidKey' })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.vapidPublicKey) return;
            return reg.pushManager.subscribe({
                userVisibleOnly:      true,
                applicationServerKey: urlBase64ToUint8Array(d.vapidPublicKey)
            });
        })
        .then(function(sub) {
            if (!sub) return;
            document.getElementById('push-banner').style.display = 'none';
            return fetch('/api/push-subscribe.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'subscribe', subscription: sub.toJSON(), identifier: identifier })
            });
        })
        .catch(function() {});
    });
}

function requestPushPermission() {
    if (!('Notification' in window)) return;
    var id = _pushIdentifier || document.getElementById('identifier').value.trim();
    if (!id) return;
    if (Notification.permission === 'granted') {
        subscribeToPush(id);
        return;
    }
    if (Notification.permission !== 'denied') {
        Notification.requestPermission().then(function(perm) {
            if (perm === 'granted') subscribeToPush(id);
        });
    }
}
</script>

<script>if('serviceWorker'in navigator)navigator.serviceWorker.register('/sw.js').catch(()=>{});</script>
</body>
</html>
