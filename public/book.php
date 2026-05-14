<?php
/**
 * Public Booking Page — Trash Panda Roll-Offs
 */

$_admin_root = dirname(__DIR__) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';

$units = db_fetchall(
    "SELECT id, unit_code, type, size, daily_rate, base_price, rental_days, extra_day_price, image, status
     FROM dumpsters
     WHERE active = 1 AND status != 'maintenance'
     ORDER BY COALESCE(base_price, daily_rate) ASC, size ASC, unit_code ASC"
);

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
            padding: 3rem 0 2rem;
            border-bottom: 1px solid var(--steel);
        }
        .book-hero h1 {
            font-family: var(--font-display);
            font-size: clamp(2rem, 5vw, 3rem);
            color: var(--white);
            margin: 0;
        }
        .book-hero h1 span { color: var(--orange); }

        .book-container { max-width: 900px; margin: 0 auto; padding: 2.5rem 1rem 4rem; }

        /* Step indicators */
        .step-nav {
            display: flex;
            gap: 0;
            margin-bottom: 2.5rem;
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

        /* Terms */
        .terms-box {
            background: var(--dark3);
            border: 1px solid var(--steel2);
            border-radius: 6px;
            padding: .9rem;
            font-size: .85rem;
            color: var(--gray-light);
            max-height: 120px;
            overflow-y: auto;
        }

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
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="rental_start">Start Date</label>
                    <input type="date" id="rental_start" class="form-control"
                           min="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="rental_end">End Date</label>
                    <input type="date" id="rental_end" class="form-control"
                           min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
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
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f_phone">Phone</label>
                    <input type="tel" id="f_phone" class="form-control" placeholder="(251) 555-1234">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f_email">Email</label>
                    <input type="email" id="f_email" class="form-control" placeholder="you@example.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f_city">City</label>
                    <input type="text" id="f_city" class="form-control" placeholder="Foley">
                </div>
                <div class="col-12">
                    <label class="form-label" for="f_address">Drop-off Address</label>
                    <input type="text" id="f_address" class="form-control" placeholder="123 Main St">
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
            </div>
        </div>

        <!-- Terms -->
        <div class="book-card">
            <h2><i class="fas fa-file-contract"></i> Terms &amp; Conditions</h2>
            <div class="terms-box mb-3">
                <?= htmlspecialchars($booking_terms, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;font-size:.9rem;color:var(--gray-light);">
                <input type="checkbox" id="f_terms" style="margin-top:.15rem;accent-color:var(--orange);">
                I have read and agree to the rental terms and conditions.
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

    </div><!-- /#step-2 -->

</div><!-- /.book-container -->

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
document.querySelectorAll('input[name="payment_method"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('label.unit-card input[name="payment_method"]').forEach(function(r) {
            r.closest('label').classList.remove('selected');
        });
        this.closest('label').classList.add('selected');
    });
    if (radio.checked) radio.closest('label').classList.add('selected');
});

// ─── Date change ─────────────────────────────────────────────────────────────
['rental_start', 'rental_end'].forEach(function(id) {
    document.getElementById(id).addEventListener('change', function() {
        computeTotal();
        triggerAvailCheck();
    });
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
    document.getElementById('sum-total').textContent = '$' + grandTotal.toFixed(2);

    setStep(2);
}

function goStep1() { setStep(1); }

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
</html>
