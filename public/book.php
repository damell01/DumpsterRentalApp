<?php
/**
 * Public Booking Page — Trash Panda Roll-Offs
 */

$_admin_root = dirname(__DIR__) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';

$public_sizes = public_dumpster_sizes();

$units = db_fetchall(
    "SELECT id, unit_code, type, size, daily_rate, base_price, rental_days, extra_day_price, image, status
     FROM dumpsters
     WHERE active = 1 AND status != 'maintenance'
     ORDER BY COALESCE(base_price, daily_rate) ASC, size ASC, unit_code ASC"
);

if (db_table_exists('dumpster_size_options') || !empty($public_sizes)) {
    $units = array_values(array_filter($units, static function (array $unit) use ($public_sizes): bool {
        return in_array((string)($unit['size'] ?? ''), $public_sizes, true);
    }));
}

// Pre-select a unit from URL param (?unit_id=5 or ?size=20)
$preselect_unit_id = (int)($_GET['unit_id'] ?? 0);
$preselect_size    = trim($_GET['size'] ?? '');
if ($preselect_unit_id <= 0 && $preselect_size !== '') {
    foreach ($units as $u) {
        if ((string)$u['size'] === $preselect_size) {
            $preselect_unit_id = (int)$u['id'];
            break;
        }
    }
}

$company_name = get_setting('company_name', 'Trash Panda Roll-Offs');
$booking_terms = get_setting('booking_terms', 'By completing this booking, you agree to our rental terms and conditions.');
$stripe_pub_key = get_setting('stripe_publishable_key', '');
$ach_enabled = get_setting('ach_enabled', '1') === '1';
$booking_flow_mode = get_setting('booking_flow_mode', 'instant');
$request_mode = $booking_flow_mode === 'request';
$card_fee_percent = max(0, (float)get_setting('card_fee_percent', '0'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Online — <?= htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') ?></title>
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

        .book-hero {
            background: var(--dark2);
            padding: 1.75rem 0 1.25rem;
            border-bottom: 1px solid var(--steel);
        }
        .book-hero h1 {
            font-family: var(--font-display);
            font-size: clamp(2rem, 5vw, 3rem);
            color: var(--white);
            margin: 0;
        }
        .book-hero h1 span { color: var(--orange); }

        .book-container { max-width: 900px; margin: 0 auto; padding: 1.75rem 1rem 2.5rem; }

        /* Step indicators */
        .step-nav {
            display: flex;
            gap: 0;
            margin-bottom: 1.75rem;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--steel);
        }
        .step-nav-item {
            flex: 1;
            padding: .75rem 1rem;
            text-align: center;
            background: var(--dark2);
            color: var(--gray);
            font-family: var(--font-cond);
            font-weight: 600;
            font-size: 1rem;
            letter-spacing: .03em;
            transition: background .2s;
            cursor: default;
            border-right: 1px solid var(--steel);
        }
        .step-nav-item:last-child { border-right: none; }
        .step-nav-item.active { background: var(--orange); color: #fff; }
        .step-nav-item.done { background: var(--steel); color: var(--gray-light); }

        /* Cards */
        .book-card {
            background: var(--dark2);
            border: 1px solid var(--steel);
            border-radius: 10px;
            padding: 1.75rem;
            margin-bottom: 1.5rem;
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

        /* Unit cards */
        .unit-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; }
        .unit-card {
            background: var(--dark3);
            border: 2px solid var(--steel);
            border-radius: 8px;
            padding: 1.25rem;
            cursor: pointer;
            transition: border-color .2s, background .2s;
        }
        .unit-card:hover { border-color: var(--orange); }
        .unit-card.selected { border-color: var(--orange); background: rgba(249,115,22,.1); }
        .unit-card input[type="checkbox"].unit-checkbox { display: none; }
        .unit-size-label {
            font-family: var(--font-display);
            font-size: 1.5rem;
            color: var(--orange);
            line-height: 1;
        }
        .unit-code { font-size: .8rem; color: var(--gray); margin-top: .25rem; }
        .unit-rate {
            font-family: var(--font-cond);
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--white);
            margin-top: .5rem;
        }
        .unit-type-badge {
            display: inline-block;
            font-size: .7rem;
            background: var(--steel2);
            color: var(--gray-light);
            border-radius: 4px;
            padding: 1px 6px;
            margin-top: .3rem;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        /* Form elements */
        .form-label { color: var(--gray-light); font-size: .9rem; margin-bottom: .35rem; }
        .form-control, .form-select {
            background: var(--dark3);
            border: 1px solid var(--steel2);
            color: var(--white);
            border-radius: 6px;
        }
        .form-control:focus, .form-select:focus {
            background: var(--dark3);
            border-color: var(--orange);
            color: var(--white);
            box-shadow: 0 0 0 3px rgba(249,115,22,.2);
        }
        .form-control::placeholder { color: var(--gray); }
        .form-select option { background: var(--dark3); }

        /* Total display */
        .total-display {
            background: var(--dark3);
            border: 1px solid var(--steel2);
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-top: 1rem;
        }
        .total-display .total-label { color: var(--gray); font-size: .85rem; }
        .total-display .total-amount {
            font-family: var(--font-display);
            font-size: 2rem;
            color: var(--orange);
            line-height: 1;
        }
        .total-display .total-breakdown { color: var(--gray-light); font-size: .85rem; margin-top: .25rem; }

        /* Step panel visibility */
        .step-panel { display: none; }
        .step-panel.active { display: block; }

        /* Alerts */
        .book-alert {
            border-radius: 8px;
            padding: .9rem 1rem;
            margin-bottom: 1.25rem;
            font-size: .9rem;
        }
        .book-alert-error { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.4); color: #fca5a5; }
        .book-alert-info  { background: rgba(249,115,22,.12); border: 1px solid rgba(249,115,22,.35); color: #fdba74; }

        /* Inline field errors */
        .field-err { font-size:.78rem; color:#fca5a5; margin-top:.3rem; display:none; }
        .field-err.show { display:block; }
        input.input-err, textarea.input-err { border-color:#ef4444 !important; }

        /* Sticky summary bar (step 2) */
        #sticky-summary {
            display: none;
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: var(--dark);
            border-top: 2px solid var(--orange);
            padding: .6rem 1.25rem;
            z-index: 200;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
        }
        #sticky-summary.show { display: flex; }
        .ss-detail { color: var(--gray-light); font-size: .8rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ss-total  { color: var(--orange); font-weight: 700; font-family: var(--font-cond); font-size: 1.15rem; white-space: nowrap; }

        /* Duration preset pills */
        .preset-row { margin-bottom: 1.1rem; }
        .preset-row .form-label { margin-bottom: .45rem; }
        .preset-pills { display: flex; flex-wrap: wrap; gap: .45rem; }
        .preset-pill {
            background: var(--dark3);
            border: 1px solid var(--steel2);
            color: var(--gray-light);
            border-radius: 20px;
            padding: .32rem .85rem;
            font-size: .82rem;
            cursor: pointer;
            transition: border-color .15s, background .15s, color .15s;
            font-family: var(--font-body);
            line-height: 1.5;
        }
        .preset-pill:hover { border-color: var(--orange); color: #fff; }
        .preset-pill.active {
            background: rgba(249,115,22,.15);
            border-color: var(--orange);
            color: var(--orange);
            font-weight: 600;
        }

        /* Terms modal */
        #termsModal { display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1050;align-items:center;justify-content:center;padding:1rem; }
        .terms-modal-box { background:var(--dark2);border:1px solid var(--steel);border-radius:12px;max-width:600px;width:100%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.5); }
        .terms-modal-header { padding:1.25rem 1.5rem;border-bottom:1px solid var(--steel);display:flex;justify-content:space-between;align-items:center; }
        .terms-modal-body { padding:1.25rem 1.5rem;overflow-y:auto;flex:1;font-size:.88rem;color:var(--gray-light);line-height:1.7;white-space:pre-wrap; }
        .terms-modal-footer { padding:1rem 1.5rem;border-top:1px solid var(--steel);display:flex;justify-content:flex-end;gap:.75rem; }

        /* Nav bar minimal */
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

        /* Unit unavailable overlay */
        .unit-card.unavailable {
            opacity: .5;
            cursor: not-allowed;
            pointer-events: none;
        }
        .unit-card.date-unavailable {
            opacity: .55;
            cursor: not-allowed;
            pointer-events: none;
            border-color: rgba(239,68,68,.5) !important;
            background: rgba(239,68,68,.05) !important;
        }
        .unit-status-badge {
            display: inline-block;
            font-size: .65rem;
            font-weight: 700;
            border-radius: 4px;
            padding: 2px 7px;
            margin-top: .3rem;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .unit-status-available  { background: rgba(34,197,94,.15); color: #86efac; border: 1px solid rgba(34,197,94,.3); }
        .unit-status-reserved   { background: rgba(239,68,68,.15); color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
        .unit-status-in_use     { background: rgba(239,68,68,.15); color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
        .unit-status-checking   { background: rgba(249,115,22,.12); color: #fdba74; border: 1px solid rgba(249,115,22,.3); }

        /* Loading spinner */
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
<div class="book-hero">
    <div class="book-container" style="padding-top:0;padding-bottom:0;">
        <h1>BOOK YOUR <span>DUMPSTER</span> ONLINE</h1>
        <p style="color:var(--gray-light);margin-top:.5rem;font-size:1rem;">
            Fast, simple, and secure. Choose your unit, pick your dates, and confirm.
        </p>
    </div>
</div>

<!-- Main content -->
    <div class="book-container">
        <?php if ($request_mode): ?>
        <div class="book-alert book-alert-info">
            <i class="fas fa-circle-info"></i>
            Your booking will be submitted as a request for approval. We will review it first, then send an invoice or payment link if needed.
        </div>
        <?php endif; ?>

        <!-- Step indicators -->
    <div class="step-nav">
        <div class="step-nav-item active" id="step-nav-1">
            <i class="fas fa-dumpster"></i> 1. Unit &amp; Dates
        </div>
        <div class="step-nav-item" id="step-nav-2">
            <i class="fas fa-user"></i> 2. Your Info
        </div>
    </div>

    <!-- Step 1: Unit + Dates -->
    <div class="step-panel active" id="step-1">

        <div id="step1-error" class="book-alert book-alert-error" style="display:none;"></div>

        <!-- Unit selection -->
        <div class="book-card">
            <h2><i class="fas fa-dumpster"></i> Select Unit(s) <small style="font-size:.8rem;font-weight:400;color:var(--gray);font-family:var(--font-body);">— select one or more</small></h2>
            <?php if (empty($units)): ?>
                <p style="color:var(--gray);">No units are currently available for online booking. Please call us to check availability!</p>
            <?php else: ?>
            <div class="unit-grid">
                <?php
                $statusLabels = ['reserved' => 'Reserved', 'in_use' => 'In Use'];
                foreach ($units as $u):
                    $isUnavailable = in_array($u['status'], ['reserved', 'in_use'], true);
                    $statusLabel   = $statusLabels[$u['status']] ?? 'Available';
                    $statusClass   = 'unit-status-' . ($u['status'] === 'available' ? 'available' : $u['status']);
                ?>
                <label class="unit-card<?= ((int)$u['id'] === $preselect_unit_id) ? ' selected' : '' ?><?= $isUnavailable ? ' unavailable' : '' ?>"
                       data-unit-id="<?= (int)$u['id'] ?>">
                    <input type="checkbox" name="unit_id[]" id="unit_<?= (int)$u['id'] ?>"
                           class="unit-checkbox"
                           value="<?= (int)$u['id'] ?>"
                           data-rate="<?= htmlspecialchars($u['daily_rate'], ENT_QUOTES, 'UTF-8') ?>"
                           data-base-price="<?= htmlspecialchars((string)(float)($u['base_price'] ?? 0), ENT_QUOTES, 'UTF-8') ?>"
                           data-rental-days="<?= (int)($u['rental_days'] ?? 0) ?>"
                           data-extra-day-price="<?= htmlspecialchars((string)(float)($u['extra_day_price'] ?? 0), ENT_QUOTES, 'UTF-8') ?>"
                           data-code="<?= htmlspecialchars($u['unit_code'], ENT_QUOTES, 'UTF-8') ?>"
                           data-size="<?= htmlspecialchars($u['size'], ENT_QUOTES, 'UTF-8') ?>"
                           data-type="<?= htmlspecialchars($u['type'], ENT_QUOTES, 'UTF-8') ?>"
                           data-status="<?= htmlspecialchars($u['status'], ENT_QUOTES, 'UTF-8') ?>"
                           <?= ((int)$u['id'] === $preselect_unit_id) ? 'checked' : '' ?>
                           <?= $isUnavailable ? 'disabled' : '' ?>>
                    <?php if (!empty($u['image'])): ?>
                    <img src="<?= htmlspecialchars($u['image'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($u['unit_code'], ENT_QUOTES, 'UTF-8') ?>"
                         style="width:100%;border-radius:4px;margin-bottom:.5rem;object-fit:cover;max-height:80px;">
                    <?php endif; ?>
                    <div class="unit-size-label"><?= htmlspecialchars($u['size'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="unit-code"><?= htmlspecialchars($u['unit_code'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php
                    $bp = (float)($u['base_price'] ?? 0);
                    $rd = (int)($u['rental_days'] ?? 0);
                    $ep = (float)($u['extra_day_price'] ?? 0);
                    if ($bp > 0 && $rd > 0):
                    ?>
                    <div class="unit-rate">$<?= number_format($bp, 2) ?> / <?= $rd ?>d</div>
                    <?php if ($ep > 0): ?>
                    <div style="font-size:.72rem;color:var(--gray);margin-top:.15rem;">+$<?= number_format($ep, 2) ?>/extra day</div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="unit-rate">$<?= number_format((float)$u['daily_rate'], 2) ?>/day</div>
                    <?php endif; ?>
                    <div class="unit-type-badge"><?= htmlspecialchars(ucfirst($u['type']), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="unit-status-badge <?= $statusClass ?>" data-status-badge="<?= (int)$u['id'] ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></div>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Date selection -->
        <div class="book-card">
            <h2><i class="fas fa-calendar-alt"></i> Select Dates</h2>

            <!-- Quick duration presets -->
            <div class="preset-row">
                <div class="form-label">Quick Duration</div>
                <div class="preset-pills">
                    <button type="button" class="preset-pill" data-weeks="1">1 Week</button>
                    <button type="button" class="preset-pill" data-weeks="2">2 Weeks</button>
                    <button type="button" class="preset-pill" data-months="1">1 Month</button>
                    <button type="button" class="preset-pill" data-months="2">2 Months</button>
                    <button type="button" class="preset-pill" data-months="3">3 Months</button>
                    <button type="button" class="preset-pill" data-months="6">6 Months</button>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="rental_start">Start Date</label>
                    <input type="date" id="rental_start" class="form-control"
                           min="<?= date('Y-m-d') ?>"
                           value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="rental_end">
                        End Date
                        <span id="preset-active-label" style="font-size:.75rem;color:var(--orange);margin-left:.4rem;display:none;"></span>
                    </label>
                    <input type="date" id="rental_end" class="form-control"
                           min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                    <div id="rental-days-hint" style="font-size:.78rem;color:var(--gray);margin-top:.3rem;display:none;"></div>
                </div>
            </div>

            <div class="total-display" id="totalDisplay" style="display:none;">
                <div class="total-label">Estimated Total</div>
                <div class="total-amount" id="totalAmount">$0.00</div>
                <div class="total-breakdown" id="totalBreakdown"></div>
            </div>

            <div id="avail-status" class="book-alert book-alert-info" style="display:none;margin-top:1rem;"></div>
        </div>

        <div class="d-flex justify-content-end">
            <button type="button" id="btnStep1Next" class="btn-panda" onclick="goStep2()">
                Continue <i class="fas fa-arrow-right"></i>
            </button>
        </div>
        <div style="height:64px;"></div><!-- mobile bottom chrome spacer -->

    </div><!-- /#step-1 -->

    <!-- Step 2: Customer info -->
    <div class="step-panel" id="step-2">

        <div id="step2-error" class="book-alert book-alert-error" style="display:none;"></div>

        <!-- Summary -->
        <div class="book-card" id="step2-summary" style="background:rgba(249,115,22,.07);border-color:rgba(249,115,22,.3);">
            <h2 style="border-bottom-color:rgba(249,115,22,.3);"><i class="fas fa-receipt"></i> Booking Summary</h2>
            <div id="sum-units-list" style="margin-bottom:.75rem;font-size:.9rem;"></div>
            <div class="row g-2" style="font-size:.9rem;">
                <div class="col-6 col-md-4">
                    <div style="color:var(--gray);font-size:.8rem;">Dates</div>
                    <div id="sum-dates"></div>
                </div>
                <div class="col-6 col-md-4">
                    <div style="color:var(--gray);font-size:.8rem;">Grand Total</div>
                    <div id="sum-total" style="color:var(--orange);font-weight:700;font-size:1.1rem;"></div>
                </div>
            </div>
        </div>

        <!-- Customer form -->
        <div class="book-card">
            <h2><i class="fas fa-user"></i> Your Information</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="f_name">Full Name <span style="color:#f97316;">*</span></label>
                    <input type="text" id="f_name" class="form-control" placeholder="Jane Smith" required>
                    <div class="field-err" id="err-f_name"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f_phone">Phone</label>
                    <input type="tel" id="f_phone" class="form-control" placeholder="(251) 555-1234" inputmode="numeric">
                    <div class="field-err" id="err-f_phone"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f_email">Email</label>
                    <input type="email" id="f_email" class="form-control" placeholder="you@example.com">
                    <div class="field-err" id="err-f_email"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f_city">City</label>
                    <input type="text" id="f_city" class="form-control" placeholder="Foley" autocomplete="address-level2">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="f_state">State</label>
                    <input type="text" id="f_state" class="form-control" placeholder="AL" maxlength="2" autocomplete="address-level1">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="f_zip">ZIP</label>
                    <input type="text" id="f_zip" class="form-control" placeholder="36551" maxlength="10" autocomplete="postal-code">
                </div>
                <div class="col-12">
                    <label class="form-label" for="f_address">Drop-off Address</label>
                    <input type="text" id="f_address" class="form-control" placeholder="123 Main St" autocomplete="street-address">
                    <div id="f_address_suggest" class="book-address-suggest" hidden></div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="f_notes">Special Instructions</label>
                    <textarea id="f_notes" class="form-control" rows="2"
                              placeholder="Gate codes, access notes, placement instructions…"></textarea>
                </div>
            </div>
        </div>

        <!-- Payment method -->
        <div class="book-card">
            <h2><i class="fas fa-credit-card"></i> Payment Method</h2>
            <div class="row g-3">
                <div class="col-12">
                    <div class="d-flex gap-3 flex-wrap">
                        <label class="unit-card d-flex align-items-center gap-2" style="flex:0 0 auto;padding:.85rem 1.25rem;" for="pm_stripe">
                            <input type="radio" id="pm_stripe" name="payment_method" value="stripe" checked>
                            <i class="fab fa-stripe" style="font-size:1.5rem;color:#6772e5;"></i>
                            <span>Pay Online (Card)</span>
                        </label>
                        <?php if ($ach_enabled): ?>
                        <label class="unit-card d-flex align-items-center gap-2" style="flex:0 0 auto;padding:.85rem 1.25rem;" for="pm_ach">
                            <input type="radio" id="pm_ach" name="payment_method" value="ach">
                            <i class="fas fa-building-columns" style="font-size:1.25rem;color:#14b8a6;"></i>
                            <span>Pay by ACH</span>
                        </label>
                        <?php endif; ?>
                        <label class="unit-card d-flex align-items-center gap-2" style="flex:0 0 auto;padding:.85rem 1.25rem;" for="pm_cash">
                            <input type="radio" id="pm_cash" name="payment_method" value="cash">
                            <i class="fas fa-money-bill-wave" style="font-size:1.3rem;color:#22c55e;"></i>
                            <span>Pay by Cash</span>
                        </label>
                        <label class="unit-card d-flex align-items-center gap-2" style="flex:0 0 auto;padding:.85rem 1.25rem;" for="pm_check">
                            <input type="radio" id="pm_check" name="payment_method" value="check">
                            <i class="fas fa-money-check" style="font-size:1.3rem;color:#3b82f6;"></i>
                            <span>Pay by Check</span>
                        </label>
                    </div>
                </div>
                <?php if ($card_fee_percent > 0): ?>
                <div class="col-12" id="card-fee-notice" style="display:none;">
                    <div style="background:rgba(249,115,22,.08);border:1px solid rgba(249,115,22,.25);border-radius:.5rem;padding:.65rem 1rem;font-size:.875rem;color:var(--gray);">
                        <i class="fas fa-circle-info me-1" style="color:var(--orange);"></i>
                        A <strong><?= htmlspecialchars(number_format($card_fee_percent, 2), ENT_QUOTES, 'UTF-8') ?>%</strong> card processing fee will be added to your total.
                        Fee: <strong id="card-fee-display" style="color:var(--orange);">$0.00</strong>
                        &mdash; Grand Total: <strong id="card-fee-grand-total" style="color:var(--orange);">$0.00</strong>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Terms -->
        <div class="book-card">
            <h2><i class="fas fa-file-contract"></i> Terms &amp; Conditions</h2>
            <p style="font-size:.88rem;color:var(--gray-light);margin-bottom:1rem;">
                Please review our rental terms and conditions before completing your booking.
            </p>
            <button type="button" class="btn-ghost" style="margin-bottom:1.1rem;" onclick="openTermsModal()">
                <i class="fas fa-scroll" style="margin-right:.4rem;"></i> View Terms &amp; Conditions
            </button>
            <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;font-size:.9rem;color:var(--gray-light);">
                <input type="checkbox" id="f_terms" style="margin-top:.15rem;accent-color:var(--orange);">
                I have read and agree to the
                <button type="button" onclick="openTermsModal()" style="background:none;border:none;padding:0;color:var(--orange);cursor:pointer;font-size:.9rem;text-decoration:underline;line-height:inherit;">Terms &amp; Conditions</button>.
            </label>
        </div>

        <div class="d-flex justify-content-between flex-wrap gap-2">
            <button type="button" class="btn-ghost" onclick="goStep1()">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <button type="button" id="btnSubmit" class="btn-panda" onclick="submitBooking()">
                <i class="fas fa-calendar-check"></i> <?= $request_mode ? 'Submit Booking Request' : 'Continue to Payment' ?>
            </button>
        </div>

        <div style="height:64px;"></div><!-- sticky bar spacer -->
    </div><!-- /#step-2 -->

</div><!-- /.book-container -->

<!-- Sticky booking summary (step 2) -->
<div id="sticky-summary">
    <div class="ss-detail" id="ss-units"></div>
    <div style="display:flex;align-items:center;gap:1rem;flex-shrink:0;">
        <div class="ss-detail" id="ss-dates"></div>
        <div class="ss-total"  id="ss-total"></div>
    </div>
</div>

<style>
.book-address-suggest {
    margin-top: .5rem;
    background: rgba(15,23,42,.98);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 14px;
    overflow: hidden;
}
.book-address-option {
    width: 100%;
    border: 0;
    background: transparent;
    color: #fff;
    text-align: left;
    padding: .8rem .95rem;
    display: flex;
    flex-direction: column;
    gap: .15rem;
}
.book-address-option + .book-address-option {
    border-top: 1px solid rgba(255,255,255,.08);
}
.book-address-option:hover {
    background: rgba(249,115,22,.12);
}
.book-address-option span {
    color: rgba(255,255,255,.62);
    font-size: .82rem;
}
</style>

<script>
// ─── State ───────────────────────────────────────────────────────────────────
var selectedUnits  = [];   // array of { id, rate, basePrice, rentalDays, extraDayPrice, code, size, type }
var availCheckTimer = null;

// ─── Unit card selection (multi-select checkboxes) ────────────────────────────
document.querySelectorAll('input.unit-checkbox').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var card = this.closest('.unit-card');
        var unit = {
            id:            this.value,
            rate:          parseFloat(this.dataset.rate) || 0,
            basePrice:     parseFloat(this.dataset.basePrice) || 0,
            rentalDays:    parseInt(this.dataset.rentalDays, 10) || 0,
            extraDayPrice: parseFloat(this.dataset.extraDayPrice) || 0,
            code:          this.dataset.code,
            size:          this.dataset.size,
            type:          this.dataset.type
        };
        if (this.checked) {
            card.classList.add('selected');
            // Add to selectedUnits if not already present
            var found = false;
            for (var i = 0; i < selectedUnits.length; i++) {
                if (selectedUnits[i].id === unit.id) { found = true; break; }
            }
            if (!found) selectedUnits.push(unit);
        } else {
            card.classList.remove('selected');
            selectedUnits = selectedUnits.filter(function(u) { return u.id !== unit.id; });
        }
        computeTotal();
        triggerAvailCheck();
    });
    // Auto-initialise for any pre-checked box (URL pre-select)
    if (cb.checked) {
        cb.closest('.unit-card').classList.add('selected');
        selectedUnits.push({
            id:   cb.value,
            rate: parseFloat(cb.dataset.rate) || 0,
            basePrice: parseFloat(cb.dataset.basePrice) || 0,
            rentalDays: parseInt(cb.dataset.rentalDays, 10) || 0,
            extraDayPrice: parseFloat(cb.dataset.extraDayPrice) || 0,
            code: cb.dataset.code,
            size: cb.dataset.size,
            type: cb.dataset.type
        });
    }
});

// ─── Payment method card selection ───────────────────────────────────────────
var CARD_FEE_PCT = <?= (float)$card_fee_percent ?>;

function _isCardPayment() {
    var pm = document.querySelector('input[name="payment_method"]:checked');
    return pm && (pm.value === 'stripe' || pm.value === 'ach');
}

function _updateCardFeeNotice(subtotal) {
    var notice = document.getElementById('card-fee-notice');
    if (!notice) return;
    if (CARD_FEE_PCT <= 0) return;
    var fee = Math.round(subtotal * CARD_FEE_PCT / 100 * 100) / 100;
    var grand = Math.round((subtotal + fee) * 100) / 100;
    if (_isCardPayment() && subtotal > 0) {
        document.getElementById('card-fee-display').textContent = '$' + fee.toFixed(2);
        document.getElementById('card-fee-grand-total').textContent = '$' + grand.toFixed(2);
        notice.style.display = '';
    } else {
        notice.style.display = 'none';
    }
}

document.querySelectorAll('input[name="payment_method"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('label.unit-card input[name="payment_method"]').forEach(function(r) {
            r.closest('label').classList.remove('selected');
        });
        this.closest('label').classList.add('selected');
        // Recompute fee notice whenever payment method changes
        var start = document.getElementById('rental_start').value;
        var end   = document.getElementById('rental_end').value;
        if (selectedUnits.length > 0 && start && end) {
            var startUTC = Date.UTC.apply(null, start.split('-').map(function(v,i){ return i===1?parseInt(v,10)-1:parseInt(v,10); }));
            var endUTC   = Date.UTC.apply(null, end.split('-').map(function(v,i){ return i===1?parseInt(v,10)-1:parseInt(v,10); }));
            var days = Math.round((endUTC - startUTC) / 86400000);
            if (days > 0) {
                var sub = 0;
                selectedUnits.forEach(function(u) { sub += calcUnitTotal(u, days); });
                _updateCardFeeNotice(sub);
                _updateStep2Summary(sub, days, start, end);
            }
        }
    });
    if (radio.checked) radio.closest('label').classList.add('selected');
});

// ─── Duration presets ────────────────────────────────────────────────────────
var _activePill = null;

document.querySelectorAll('.preset-pill').forEach(function(pill) {
    pill.addEventListener('click', function() {
        document.querySelectorAll('.preset-pill').forEach(function(p) { p.classList.remove('active'); });
        pill.classList.add('active');
        _activePill = pill;
        _applyPreset();
    });
});

function _applyPreset() {
    if (!_activePill) return;
    var startVal = document.getElementById('rental_start').value;
    if (!startVal) return;

    var start = new Date(startVal + 'T00:00:00');
    var end   = new Date(start);

    if (_activePill.dataset.weeks) {
        end.setDate(end.getDate() + parseInt(_activePill.dataset.weeks, 10) * 7);
    } else if (_activePill.dataset.months) {
        end.setDate(end.getDate() + parseInt(_activePill.dataset.months, 10) * 30);
    }

    var yyyy = end.getFullYear();
    var mm   = String(end.getMonth() + 1).padStart(2, '0');
    var dd   = String(end.getDate()).padStart(2, '0');
    document.getElementById('rental_end').value = yyyy + '-' + mm + '-' + dd;

    _updatePresetLabel();
    _updateDaysHint();
    computeTotal();
    triggerAvailCheck();
}

function _clearPreset() {
    _activePill = null;
    document.querySelectorAll('.preset-pill').forEach(function(p) { p.classList.remove('active'); });
    document.getElementById('preset-active-label').style.display = 'none';
    _updateDaysHint();
}

function _updatePresetLabel() {
    var lbl = document.getElementById('preset-active-label');
    if (_activePill) {
        lbl.textContent = '(' + _activePill.textContent.trim() + ')';
        lbl.style.display = 'inline';
    } else {
        lbl.style.display = 'none';
    }
}

function _updateDaysHint() {
    var hint  = document.getElementById('rental-days-hint');
    var start = document.getElementById('rental_start').value;
    var end   = document.getElementById('rental_end').value;
    if (!start || !end) { hint.style.display = 'none'; return; }
    var s = new Date(start + 'T00:00:00');
    var e = new Date(end   + 'T00:00:00');
    var days = Math.round((e - s) / 86400000);
    if (days <= 0) { hint.style.display = 'none'; return; }
    hint.textContent = days + ' day' + (days !== 1 ? 's' : '');
    hint.style.display = 'block';
}

// ─── Date change ─────────────────────────────────────────────────────────────
document.getElementById('rental_start').addEventListener('change', function() {
    _applyPreset();   // recalculates end if a preset is active
    _updateDaysHint();
    computeTotal();
    triggerAvailCheck();
});

document.getElementById('rental_end').addEventListener('change', function() {
    _clearPreset();   // user manually picked end — deactivate preset
    _updateDaysHint();
    computeTotal();
    triggerAvailCheck();
});

function calcUnitTotal(u, days) {
    if (u.basePrice > 0 && u.rentalDays > 0) {
        var extra = Math.max(0, days - u.rentalDays);
        return u.basePrice + extra * u.extraDayPrice;
    }
    return u.rate * days;
}

function unitBreakdown(u, days) {
    if (u.basePrice > 0 && u.rentalDays > 0) {
        var extra = Math.max(0, days - u.rentalDays);
        var t = u.basePrice + extra * u.extraDayPrice;
        var s = u.size + ' · $' + u.basePrice.toFixed(2) + ' / ' + u.rentalDays + ' day' + (u.rentalDays !== 1 ? 's' : '');
        if (extra > 0) s += ' + ' + extra + ' extra @ $' + u.extraDayPrice.toFixed(2) + '/day';
        s += ' = $' + t.toFixed(2);
        return s;
    }
    return u.size + ' · ' + days + ' day' + (days !== 1 ? 's' : '') + ' @ $' + u.rate.toFixed(2) + '/day';
}

function computeTotal() {
    var start = document.getElementById('rental_start').value;
    var end   = document.getElementById('rental_end').value;
    var disp  = document.getElementById('totalDisplay');
    var amtEl = document.getElementById('totalAmount');
    var brkEl = document.getElementById('totalBreakdown');

    if (selectedUnits.length === 0 || !start || !end) {
        disp.style.display = 'none';
        return;
    }

    var startUTC = Date.UTC.apply(null, start.split('-').map(function(v,i){ return i===1?parseInt(v,10)-1:parseInt(v,10); }));
    var endUTC   = Date.UTC.apply(null, end.split('-').map(function(v,i){ return i===1?parseInt(v,10)-1:parseInt(v,10); }));
    var days = Math.round((endUTC - startUTC) / 86400000);
    if (days <= 0) { disp.style.display = 'none'; return; }

    var total = 0;
    selectedUnits.forEach(function(u) { total += calcUnitTotal(u, days); });
    amtEl.textContent = '$' + total.toFixed(2);
    if (selectedUnits.length === 1) {
        brkEl.textContent = unitBreakdown(selectedUnits[0], days);
    } else {
        brkEl.textContent = selectedUnits.length + ' units · ' + days + ' day' + (days !== 1 ? 's' : '') + ' · $' + total.toFixed(2) + ' total';
    }
    disp.style.display = 'block';
}

function triggerAvailCheck() {
    clearTimeout(availCheckTimer);
    availCheckTimer = setTimeout(checkAvailability, 500);
}

function checkAvailability() {
    var start    = document.getElementById('rental_start').value;
    var end      = document.getElementById('rental_end').value;
    var statusEl = document.getElementById('avail-status');

    var startDate = new Date(start);
    var endDate   = new Date(end);
    if (!start || !end || endDate <= startDate) {
        // Reset all date-based unavailability when dates are cleared/invalid
        document.querySelectorAll('.unit-card.date-unavailable').forEach(function(c) {
            c.classList.remove('date-unavailable');
            var cb = c.querySelector('input.unit-checkbox');
            if (cb && cb.dataset.status === 'available') {
                cb.disabled = false;
                c.style.pointerEvents = '';
                var badge = document.querySelector('[data-status-badge="' + cb.value + '"]');
                if (badge) { badge.textContent = 'Available'; badge.className = 'unit-status-badge unit-status-available'; }
            }
        });
        statusEl.style.display = 'none';
        return;
    }

    // Show "checking" badge on all available-status units
    document.querySelectorAll('input.unit-checkbox').forEach(function(cb) {
        if (cb.dataset.status === 'available') {
            var badge = document.querySelector('[data-status-badge="' + cb.value + '"]');
            if (badge) { badge.textContent = 'Checking…'; badge.className = 'unit-status-badge unit-status-checking'; }
        }
    });

    // Batch check all units for selected dates
    fetch('/api/batch-availability.php?start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.available) return;
            document.querySelectorAll('input.unit-checkbox').forEach(function(cb) {
                if (cb.dataset.status !== 'available') return; // don't touch already-marked units
                var uid   = cb.value;
                var card  = cb.closest('.unit-card');
                var badge = document.querySelector('[data-status-badge="' + uid + '"]');
                var isAvail = data.available[uid] === true;
                if (isAvail) {
                    card.classList.remove('date-unavailable');
                    cb.disabled = false;
                    if (badge) { badge.textContent = 'Available'; badge.className = 'unit-status-badge unit-status-available'; }
                } else {
                    card.classList.add('date-unavailable');
                    card.classList.remove('selected');
                    cb.disabled = true;
                    cb.checked  = false;
                    selectedUnits = selectedUnits.filter(function(u) { return u.id !== uid; });
                    if (badge) { badge.textContent = 'Booked'; badge.className = 'unit-status-badge unit-status-reserved'; }
                }
            });
            computeTotal();
        })
        .catch(function() { /* silently fail */ });

    // Show a status message if any units are selected
    if (selectedUnits.length === 0) { statusEl.style.display = 'none'; return; }

    statusEl.style.display = 'block';
    statusEl.textContent   = 'Checking availability…';
    statusEl.className     = 'book-alert book-alert-info';

    // Check first selected unit as a quick confirmation
    fetch('/api/availability.php?unit_id=' + encodeURIComponent(selectedUnits[0].id) +
          '&start=' + encodeURIComponent(start) +
          '&end='   + encodeURIComponent(end))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.available) {
                statusEl.className = 'book-alert book-alert-info';
                statusEl.innerHTML = '<i class="fas fa-check-circle" style="color:#22c55e;"></i> Selected unit(s) available for those dates!';
            } else {
                statusEl.className = 'book-alert book-alert-error';
                statusEl.innerHTML = '<i class="fas fa-times-circle"></i> ' + (data.message || 'Not available for selected dates.');
            }
        })
        .catch(function() {
            statusEl.style.display = 'none';
        });
}

// ─── Phone auto-format ───────────────────────────────────────────────────────
document.getElementById('f_phone').addEventListener('input', function() {
    var d = this.value.replace(/\D/g, '').substring(0, 10);
    var out = '';
    if (d.length > 0) out = '(' + d.substring(0, 3);
    if (d.length >= 4) out += ') ' + d.substring(3, 6);
    if (d.length >= 7) out += '-' + d.substring(6, 10);
    this.value = out;
});

// ─── Inline field validation ──────────────────────────────────────────────────
function _fieldErr(id, msg) {
    var inp = document.getElementById(id);
    var box = document.getElementById('err-' + id);
    if (!box) return;
    if (msg) {
        box.textContent = msg;
        box.classList.add('show');
        inp.classList.add('input-err');
    } else {
        box.classList.remove('show');
        inp.classList.remove('input-err');
    }
}

document.getElementById('f_name').addEventListener('blur', function() {
    _fieldErr('f_name', this.value.trim() === '' ? 'Name is required.' : '');
});
document.getElementById('f_name').addEventListener('input', function() {
    if (this.value.trim()) _fieldErr('f_name', '');
});

document.getElementById('f_email').addEventListener('blur', function() {
    var v = this.value.trim();
    _fieldErr('f_email', v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) ? 'Enter a valid email address.' : '');
});
document.getElementById('f_email').addEventListener('input', function() {
    if (!this.value.trim()) _fieldErr('f_email', '');
});

document.getElementById('f_phone').addEventListener('blur', function() {
    var d = this.value.replace(/\D/g, '');
    _fieldErr('f_phone', d.length > 0 && d.length < 10 ? 'Enter a 10-digit phone number.' : '');
});
document.getElementById('f_phone').addEventListener('input', function() {
    var d = this.value.replace(/\D/g, '');
    if (d.length === 0 || d.length === 10) _fieldErr('f_phone', '');
});

// ─── Sticky summary bar ───────────────────────────────────────────────────────
function _updateStickyBar(units, start, end, total) {
    document.getElementById('ss-units').textContent = units.map(function(u) { return u.code; }).join(', ');
    document.getElementById('ss-dates').textContent = start + ' – ' + end;
    document.getElementById('ss-total').textContent = '$' + total.toFixed(2);
    document.getElementById('sticky-summary').classList.add('show');
}
function _hideStickyBar() {
    document.getElementById('sticky-summary').classList.remove('show');
}

// ─── Step navigation ─────────────────────────────────────────────────────────
function goStep2() {
    var errEl = document.getElementById('step1-error');
    errEl.style.display = 'none';

    if (selectedUnits.length === 0) {
        errEl.textContent = 'Please select at least one unit.';
        errEl.style.display = 'block';
        return;
    }
    var start = document.getElementById('rental_start').value;
    var end   = document.getElementById('rental_end').value;
    if (!start) { errEl.textContent = 'Please select a start date.'; errEl.style.display = 'block'; return; }
    if (!end)   { errEl.textContent = 'Please select an end date.';  errEl.style.display = 'block'; return; }
    if (new Date(end) <= new Date(start)) {
        errEl.textContent = 'End date must be after start date.';
        errEl.style.display = 'block';
        return;
    }

    // Populate summary
    var startUTC = Date.UTC.apply(null, start.split('-').map(function(v,i){ return i===1?parseInt(v,10)-1:parseInt(v,10); }));
    var endUTC   = Date.UTC.apply(null, end.split('-').map(function(v,i){ return i===1?parseInt(v,10)-1:parseInt(v,10); }));
    var days = Math.round((endUTC - startUTC) / 86400000);

    var listHtml = '';
    var grandTotal = 0;
    selectedUnits.forEach(function(u) {
        var sub = calcUnitTotal(u, days);
        grandTotal += sub;
        listHtml += '<div style="display:flex;justify-content:space-between;padding:.25rem 0;border-bottom:1px solid rgba(255,255,255,.06);">'
            + '<span><strong>' + escHtml(u.code) + '</strong> <span style="color:var(--gray);font-size:.85rem;">' + escHtml(u.size) + '</span></span>'
            + '<span style="color:var(--orange);">$' + sub.toFixed(2) + '</span>'
            + '</div>';
    });
    document.getElementById('sum-units-list').innerHTML = listHtml;
    document.getElementById('sum-dates').textContent = start + ' – ' + end;
    _updateStep2Summary(grandTotal, days, start, end);
    _updateStickyBar(selectedUnits, start, end, grandTotal);
    setStep(2);
}

function _updateStep2Summary(subtotal, days, start, end) {
    var feeAmt = (_isCardPayment() && CARD_FEE_PCT > 0)
        ? Math.round(subtotal * CARD_FEE_PCT / 100 * 100) / 100 : 0;
    var grandTotal = Math.round((subtotal + feeAmt) * 100) / 100;
    var totalEl = document.getElementById('sum-total');
    if (feeAmt > 0) {
        totalEl.innerHTML = '$' + subtotal.toFixed(2)
            + ' <span style="font-size:.8rem;color:var(--gray);">+ $' + feeAmt.toFixed(2)
            + ' card fee</span><br><span style="font-size:1.1rem;">$' + grandTotal.toFixed(2) + ' total</span>';
    } else {
        totalEl.textContent = '$' + grandTotal.toFixed(2);
    }
    _updateCardFeeNotice(subtotal);
}

function goStep1() { _hideStickyBar(); setStep(1); }

function setStep(n) {
    document.querySelectorAll('.step-panel').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.step-nav-item').forEach(function(i, idx) {
        i.classList.remove('active', 'done');
        if (idx + 1 < n)  i.classList.add('done');
        if (idx + 1 === n) i.classList.add('active');
    });
    document.getElementById('step-' + n).classList.add('active');
    window.scrollTo(0, 0);
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ─── Submit ───────────────────────────────────────────────────────────────────
function setupAddressAutocomplete(opts) {
    var address = document.getElementById(opts.addressId);
    var city = document.getElementById(opts.cityId);
    var state = document.getElementById(opts.stateId);
    var zip = document.getElementById(opts.zipId);
    var box = document.getElementById(opts.boxId);
    if (!address || !city || !state || !zip || !box) return;

    var timer = null;
    var controller = null;

    function hideBox() {
        box.hidden = true;
        box.innerHTML = '';
    }

    function partsFor(item) {
        var a = item.address || {};
        var street = [a.house_number || '', a.road || a.pedestrian || a.footway || a.cycleway || a.path || ''].join(' ').trim();
        if (!street) street = String(item.display_name || '').split(',')[0].trim();
        return {
            street: street,
            city: a.city || a.town || a.village || a.hamlet || a.county || '',
            state: (a.state_code || a.state || '').toString().trim(),
            zip: (a.postcode || '').toString().trim()
        };
    }

    function choose(item) {
        var parts = partsFor(item);
        address.value = parts.street;
        if (parts.city) city.value = parts.city;
        if (parts.state) state.value = parts.state.length === 2 ? parts.state.toUpperCase() : parts.state;
        if (parts.zip) zip.value = parts.zip;
        hideBox();
    }

    address.addEventListener('input', function() {
        var q = address.value.trim();
        if (controller) controller.abort();
        clearTimeout(timer);
        if (q.length < 5) { hideBox(); return; }
        timer = setTimeout(function() {
            controller = new AbortController();
            fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&countrycodes=us&limit=5&viewbox=-88.5,31.2,-86.5,30.0&bounded=0&q=' + encodeURIComponent(q), {
                signal: controller.signal,
                headers: { 'Accept': 'application/json' }
            })
            .then(function(r) { return r.ok ? r.json() : []; })
            .then(function(items) {
                if (!Array.isArray(items) || items.length === 0) { hideBox(); return; }
                box.innerHTML = items.map(function(item, idx) {
                    var parts = partsFor(item);
                    var subtitle = [parts.city, parts.state, parts.zip].filter(Boolean).join(', ');
                    return '<button type="button" class="book-address-option" data-idx="' + idx + '"><strong>'
                        + escHtml(parts.street || item.display_name || 'Address')
                        + '</strong>'
                        + (subtitle ? '<span>' + escHtml(subtitle) + '</span>' : '')
                        + '</button>';
                }).join('');
                box.hidden = false;
                box.querySelectorAll('.book-address-option').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        choose(items[parseInt(btn.dataset.idx, 10)]);
                    });
                });
            })
            .catch(function() { hideBox(); });
        }, 250);
    });

    address.addEventListener('blur', function() {
        setTimeout(hideBox, 150);
    });
}

function submitBooking() {
    var errEl  = document.getElementById('step2-error');
    var btnEl  = document.getElementById('btnSubmit');
    errEl.style.display = 'none';

    // Safety guard: if units were lost (e.g. page reload), send user back to step 1
    if (selectedUnits.length === 0) {
        goStep1();
        var e1 = document.getElementById('step1-error');
        e1.textContent = 'Please select at least one unit to continue.';
        e1.style.display = 'block';
        return;
    }

    var name   = document.getElementById('f_name').value.trim();
    var phone  = document.getElementById('f_phone').value.trim();
    var email  = document.getElementById('f_email').value.trim();
    var addr   = document.getElementById('f_address').value.trim();
    var city   = document.getElementById('f_city').value.trim();
    var state  = document.getElementById('f_state').value.trim();
    var zip    = document.getElementById('f_zip').value.trim();
    var notes  = document.getElementById('f_notes').value.trim();
    var terms  = document.getElementById('f_terms').checked;
    var pm     = document.querySelector('input[name="payment_method"]:checked');

    if (!name)  { errEl.textContent = 'Please enter your name.';                   errEl.style.display = 'block'; return; }
    if (!terms) { errEl.textContent = 'Please agree to the terms and conditions.'; errEl.style.display = 'block'; return; }

    var payload = {
        unit_ids:         selectedUnits.map(function(u) { return u.id; }),
        rental_start:     document.getElementById('rental_start').value,
        rental_end:       document.getElementById('rental_end').value,
        customer_name:    name,
        customer_phone:   phone,
        customer_email:   email,
        customer_address: addr,
        customer_city:    city,
        customer_state:   state,
        customer_zip:     zip,
        payment_method:   pm ? pm.value : 'stripe',
        notes:            notes,
        terms_accepted:   '1'
    };

    btnEl.disabled = true;
    btnEl.innerHTML = '<span class="spinner-inline"></span> Processing...';

    fetch('/api/create-booking.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            // Offer push notifications using the customer's email or phone as identifier
            var pushId = email || phone;
            if (pushId) {
                requestPushPermission(pushId);
            }
            if (data.checkout_url) {
                window.location.href = data.checkout_url;
            } else {
                window.location.href = data.redirect;
            }
        } else {
            errEl.innerHTML     = '<i class="fas fa-times-circle"></i> ' + (data.error || 'An error occurred. Please try again.');
            errEl.style.display = 'block';
            btnEl.disabled      = false;
            btnEl.innerHTML     = '<i class="fas fa-calendar-check"></i> <?= $request_mode ? 'Submit Booking Request' : 'Continue to Payment' ?>';
            window.scrollTo(0, errEl.getBoundingClientRect().top + window.scrollY - 80);
        }
    })
    .catch(function() {
        errEl.textContent   = 'Network error. Please try again.';
        errEl.style.display = 'block';
        btnEl.disabled      = false;
        btnEl.innerHTML     = '<i class="fas fa-calendar-check"></i> <?= $request_mode ? 'Submit Booking Request' : 'Continue to Payment' ?>';
    });
}
</script>

    <script>
// ── Push Notification Helper ───────────────────────────────────────────────────
var _pushIdentifier = '';

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
        fetch('/api/push-subscribe.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'getVapidKey' }) })
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
                return fetch('/api/push-subscribe.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ action: 'subscribe', subscription: sub.toJSON(), identifier: identifier })
                });
            })
            .catch(function() {});
    });
}

function requestPushPermission(identifier) {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'granted') {
        subscribeToPush(identifier);
        return;
    }
    if (Notification.permission !== 'denied') {
        Notification.requestPermission().then(function(perm) {
            if (perm === 'granted') subscribeToPush(identifier);
        });
    }
}
</script>

    <script>if('serviceWorker'in navigator)navigator.serviceWorker.register('/sw.js').catch(()=>{});</script>
</body>

<!-- Terms & Conditions Modal -->
<div id="termsModal" onclick="if(event.target===this)closeTermsModal()">
    <div class="terms-modal-box">
        <div class="terms-modal-header">
            <h5 style="margin:0;color:#fff;font-size:1rem;font-weight:600;">
                <i class="fas fa-file-contract" style="color:var(--orange);margin-right:.5rem;"></i>
                Terms &amp; Conditions
            </h5>
            <button type="button" onclick="closeTermsModal()" style="background:none;border:none;color:var(--gray-light);font-size:1.5rem;cursor:pointer;padding:0;line-height:1;">&times;</button>
        </div>
        <div class="terms-modal-body" id="termsModalBody"><?= htmlspecialchars($booking_terms, ENT_QUOTES, 'UTF-8') ?></div>
        <div id="termsScrollHint" style="text-align:center;font-size:.75rem;color:var(--gray);padding:.4rem;border-top:1px solid var(--steel);">
            <i class="fas fa-arrow-down" style="margin-right:.3rem;"></i> Scroll to read all terms
        </div>
        <div class="terms-modal-footer">
            <button type="button" onclick="printTerms()"
                    style="background:none;border:1px solid var(--steel2);color:var(--gray-light);border-radius:6px;padding:.45rem .9rem;font-size:.85rem;cursor:pointer;margin-right:auto;">
                <i class="fas fa-print" style="margin-right:.35rem;"></i> Print
            </button>
            <button type="button" class="btn-ghost" onclick="closeTermsModal()">Close</button>
            <button type="button" id="termsAgreeBtn" class="btn-panda" onclick="agreeTerms()" disabled
                    style="opacity:.5;cursor:not-allowed;">
                <i class="fas fa-check" style="margin-right:.35rem;"></i> I Agree
            </button>
        </div>
    </div>
</div>

<script>
function openTermsModal() {
    var body = document.getElementById('termsModalBody');
    var btn  = document.getElementById('termsAgreeBtn');
    var hint = document.getElementById('termsScrollHint');

    // Reset scroll lock
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.style.cursor = 'not-allowed';
    if (hint) hint.style.display = 'block';

    document.getElementById('termsModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';

    // Scroll back to top on re-open
    body.scrollTop = 0;

    // Unlock immediately if content is short enough to not scroll
    setTimeout(function() { _checkTermsScroll(body, btn, hint); }, 60);

    body.onscroll = function() { _checkTermsScroll(body, btn, hint); };
}

function _checkTermsScroll(body, btn, hint) {
    if (body.scrollHeight - body.scrollTop - body.clientHeight < 30) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
        if (hint) hint.style.display = 'none';
        body.onscroll = null;
    }
}

function closeTermsModal() {
    document.getElementById('termsModal').style.display = 'none';
    document.body.style.overflow = '';
}

function agreeTerms() {
    document.getElementById('f_terms').checked = true;
    closeTermsModal();
}

function printTerms() {
    var text = document.getElementById('termsModalBody').textContent || '';
    var esc  = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    var w = window.open('', '_blank', 'width=720,height=800');
    w.document.write('<!DOCTYPE html><html><head><title>Terms & Conditions</title>'
        + '<style>body{font-family:Arial,sans-serif;padding:2rem;font-size:14px;line-height:1.7;color:#111;}'
        + 'h1{font-size:1.1rem;margin-bottom:1.5rem;border-bottom:1px solid #ccc;padding-bottom:.5rem;}'
        + '@media print{button{display:none;}}</style></head><body>'
        + '<h1>Terms &amp; Conditions</h1>'
        + '<pre style="white-space:pre-wrap;font-family:inherit;">' + esc + '</pre>'
        + '</body></html>');
    w.document.close();
    w.print();
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeTermsModal();
});

// ─── On load: start date already filled — compute total + check availability ─
setupAddressAutocomplete({
    addressId: 'f_address',
    cityId: 'f_city',
    stateId: 'f_state',
    zipId: 'f_zip',
    boxId: 'f_address_suggest'
});
computeTotal();
triggerAvailCheck();
</script>
</html>
