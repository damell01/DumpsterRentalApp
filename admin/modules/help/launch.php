<?php
/**
 * Launch Checklist
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INC_PATH . '/launch_readiness.php';
require_once TMPL_PATH . '/layout.php';

require_login();

$launch = launch_readiness_data();
$launch_score = (int)$launch['score'];
$launch_status = (string)$launch['status'];
$launch_status_class = (string)$launch['status_class'];
$launch_ready_count = (int)$launch['ready_count'];
$launch_total_count = (int)$launch['total_count'];
$launch_checks = $launch['checks'];
$launch_pending_checks = $launch['pending_checks'];
$app_env = strtoupper((string)$launch['app_env']);

// SVG ring params
$r = 46; $cx = 56; $cy = 56;
$circ = round(2 * M_PI * $r, 2);
$dash = round($circ * $launch_score / 100, 2);

layout_start('Launch Checklist', 'help');
?>

<style>
/* ── Page header ─────────────────────────────────────── */
.lc-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .75rem;
    margin-bottom: 1.5rem;
}
.lc-page-title {
    font-family: var(--font-cond);
    font-weight: 800;
    font-size: 1.45rem;
    letter-spacing: .03em;
    color: var(--wh);
    display: flex;
    align-items: center;
    gap: .5rem;
    margin: 0;
}
.lc-page-title i { color: var(--or); }

/* ── Hero card ───────────────────────────────────────── */
.lc-hero {
    background: linear-gradient(135deg, #0c1e35 0%, #0f2a44 55%, #1a3050 100%);
    border-radius: var(--radius-lg);
    padding: 2rem;
    margin-bottom: 1.25rem;
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(249,115,22,.2);
    box-shadow: 0 4px 24px rgba(0,0,0,.12);
}
.lc-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse 70% 80% at 95% 50%, rgba(249,115,22,.08), transparent 65%);
    pointer-events: none;
}
.lc-hero-inner {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 2rem;
    align-items: center;
    position: relative;
}
@media(max-width:767px){ .lc-hero-inner { grid-template-columns: 1fr; } }

/* ring */
.lc-ring-wrap { display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap; }
.lc-ring svg { display: block; overflow: visible; }
.lc-ring-bg  { fill: none; stroke: rgba(255,255,255,.08); }
.lc-ring-fill {
    fill: none;
    stroke: #f97316;
    stroke-linecap: round;
    transition: stroke-dasharray .6s ease;
    filter: drop-shadow(0 0 6px rgba(249,115,22,.5));
}
.lc-ring-text {
    font-family: var(--font-display);
    font-size: 1.05rem;
    fill: #fff;
    text-anchor: middle;
    dominant-baseline: middle;
}
.lc-ring-sub {
    font-size: .48rem;
    fill: rgba(255,255,255,.5);
    font-family: 'Barlow Condensed', sans-serif;
    letter-spacing: .1em;
    text-transform: uppercase;
}

/* status pill */
.lc-pill {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .3rem .75rem;
    border-radius: 999px;
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}
.tp-launch-status-good  { background: rgba(16,185,129,.18); color: #6ee7b7; border: 1px solid rgba(16,185,129,.3); }
.tp-launch-status-mid   { background: rgba(245,158,11,.18); color: #fcd34d; border: 1px solid rgba(245,158,11,.3); }
.tp-launch-status-warn  { background: rgba(239,68,68,.18);  color: #fca5a5; border: 1px solid rgba(239,68,68,.3);  }

.lc-hero-title {
    font-family: var(--font-cond);
    font-weight: 800;
    font-size: 1.35rem;
    color: #fff;
    margin: .5rem 0 .35rem;
    letter-spacing: .02em;
}
.lc-hero-sub { color: rgba(255,255,255,.55); font-size: .9rem; line-height: 1.55; margin: 0; }

/* progress bar */
.lc-bar-wrap {
    margin-top: 1.25rem;
    display: flex;
    align-items: center;
    gap: .75rem;
}
.lc-bar {
    flex: 1;
    height: 8px;
    border-radius: 999px;
    background: rgba(255,255,255,.1);
    overflow: hidden;
}
.lc-bar-fill {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #f97316, #fb923c);
    box-shadow: 0 0 8px rgba(249,115,22,.4);
}
.lc-bar-label { font-size: .8rem; color: rgba(255,255,255,.5); white-space: nowrap; }

/* env badge */
.lc-env {
    margin-top: .85rem;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    font-size: .78rem;
    color: rgba(255,255,255,.45);
}
.lc-env strong { color: rgba(255,255,255,.8); }

/* guide box inside hero */
.lc-guide {
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: var(--radius);
    padding: 1.25rem 1.5rem;
    min-width: 240px;
}
.lc-guide-title {
    font-family: var(--font-cond);
    font-weight: 700;
    font-size: .78rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: #f97316;
    margin-bottom: .75rem;
    display: flex;
    align-items: center;
    gap: .4rem;
}
.lc-guide ol {
    padding-left: 1.1rem;
    margin: 0;
    color: rgba(255,255,255,.6);
    font-size: .85rem;
    line-height: 2;
}
.lc-guide ol li { padding-left: .2rem; }

/* ── Pending banner ──────────────────────────────────── */
.lc-pending {
    background: rgba(245,158,11,.07);
    border: 1px solid rgba(245,158,11,.22);
    border-radius: var(--radius);
    padding: .85rem 1.1rem;
    font-size: .875rem;
    color: #78350f;
    display: flex;
    align-items: flex-start;
    gap: .6rem;
    margin-bottom: 1.25rem;
}
.lc-pending i { color: #f59e0b; margin-top: 2px; flex-shrink: 0; }
.lc-pending-items { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .4rem; }
.lc-pending-tag {
    background: rgba(245,158,11,.15);
    border: 1px solid rgba(245,158,11,.3);
    border-radius: 6px;
    padding: .15rem .55rem;
    font-size: .78rem;
    font-weight: 700;
    color: #92400e;
}

/* ── Checklist items ─────────────────────────────────── */
.lc-list { display: grid; gap: .65rem; margin-bottom: 1.5rem; }

.lc-item {
    background: var(--dk1);
    border: 1px solid var(--st);
    border-left: 4px solid var(--st2);
    border-radius: var(--radius);
    padding: 1rem 1.1rem 1rem 1.25rem;
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: center;
    gap: 1rem;
    transition: box-shadow .15s, border-color .15s;
}
.lc-item:hover { box-shadow: var(--shadow-card-hover); }
.lc-item.is-done  { border-left-color: var(--gr); }
.lc-item.is-todo  { border-left-color: var(--or); }

.lc-item-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .9rem;
    flex-shrink: 0;
}
.is-done .lc-item-icon { background: rgba(16,185,129,.1); color: var(--gr); }
.is-todo .lc-item-icon { background: rgba(249,115,22,.1); color: var(--or); }

.lc-item-body { flex: 1; min-width: 0; }
.lc-item-label {
    font-family: var(--font-cond);
    font-weight: 700;
    font-size: 1rem;
    color: var(--wh);
    margin: 0 0 .15rem;
    letter-spacing: .01em;
}
.lc-item-desc { color: var(--gy); font-size: .86rem; margin: 0; }

.lc-item-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: .55rem;
    flex-shrink: 0;
}

.lc-badge {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    padding: .25rem .65rem;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 700;
    white-space: nowrap;
}
.lc-badge-done { background: rgba(16,185,129,.1); color: #059669; border: 1px solid rgba(16,185,129,.25); }
.lc-badge-todo { background: rgba(249,115,22,.1); color: #ea580c; border: 1px solid rgba(249,115,22,.25); }

@media(max-width:576px) {
    .lc-item { grid-template-columns: 1fr; }
    .lc-item-right { flex-direction: row; align-items: center; }
}

/* ── Bottom cards ────────────────────────────────────── */
.lc-bottom-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media(max-width:767px){ .lc-bottom-cards { grid-template-columns: 1fr; } }

.lc-info-card {
    background: var(--dk1);
    border: 1px solid var(--st);
    border-radius: var(--radius-lg);
    overflow: hidden;
}
.lc-info-header {
    padding: .9rem 1.25rem;
    border-bottom: 1px solid var(--st);
    display: flex;
    align-items: center;
    gap: .6rem;
    font-family: var(--font-cond);
    font-weight: 700;
    font-size: .95rem;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--navy);
    background: var(--dk2);
}
.lc-info-header i { color: var(--or); }
.lc-info-body { padding: 1.25rem; }
.lc-step-list {
    list-style: none;
    padding: 0;
    margin: 0;
    counter-reset: steps;
}
.lc-step-list li {
    counter-increment: steps;
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    padding: .55rem 0;
    font-size: .88rem;
    color: var(--gy);
    border-bottom: 1px solid var(--st);
}
.lc-step-list li:last-child { border-bottom: none; }
.lc-step-list li::before {
    content: counter(steps);
    background: var(--navy);
    color: #fff;
    font-family: var(--font-cond);
    font-weight: 800;
    font-size: .72rem;
    min-width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: 1px;
}
</style>

<!-- Page header -->
<div class="lc-page-header">
    <h5 class="lc-page-title">
        <i class="fa-solid fa-rocket"></i> Launch Checklist
    </h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(APP_URL) ?>/dashboard.php" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
        </a>
        <a href="<?= e(APP_URL) ?>/../docs/TESTING_AND_ONBOARDING_GUIDE.md" target="_blank" rel="noopener" class="btn-tp-primary btn-tp-sm">
            <i class="fa-solid fa-book"></i> Open Full Testing Guide
        </a>
    </div>
</div>

<!-- Hero -->
<div class="lc-hero">
    <div class="lc-hero-inner">
        <div>
            <div class="lc-ring-wrap">
                <div class="lc-ring">
                    <svg width="112" height="112" viewBox="0 0 112 112">
                        <circle class="lc-ring-bg"  cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>" stroke-width="7"/>
                        <circle class="lc-ring-fill" cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>" stroke-width="7"
                            stroke-dasharray="<?= $dash ?> <?= $circ ?>"
                            stroke-dashoffset="<?= round($circ / 4, 2) ?>"
                            transform="rotate(-90 <?= $cx ?> <?= $cy ?>)"/>
                        <text class="lc-ring-text" x="<?= $cx ?>" y="<?= $cy - 6 ?>"><?= $launch_score ?>%</text>
                        <text class="lc-ring-text lc-ring-sub" x="<?= $cx ?>" y="<?= $cy + 10 ?>">READY</text>
                    </svg>
                </div>
                <div>
                    <div class="mb-2">
                        <span class="lc-pill <?= e($launch_status_class) ?>">
                            <i class="fa-solid fa-flag-checkered"></i> <?= e($launch_status) ?>
                        </span>
                    </div>
                    <div class="lc-hero-title">Production Readiness</div>
                    <p class="lc-hero-sub">
                        <?= $launch_ready_count ?> of <?= $launch_total_count ?> checks complete.
                        <?php if (!empty($launch_pending_checks)): ?>
                            Close the remaining items before go-live.
                        <?php else: ?>
                            All checks passed — you're cleared for launch.
                        <?php endif; ?>
                    </p>
                    <div class="lc-bar-wrap">
                        <div class="lc-bar">
                            <div class="lc-bar-fill" style="width:<?= $launch_score ?>%;"></div>
                        </div>
                        <div class="lc-bar-label"><?= $launch_ready_count ?> / <?= $launch_total_count ?></div>
                    </div>
                    <div class="lc-env">
                        <i class="fa-solid fa-server" style="font-size:.7rem;"></i>
                        Environment: <strong><?= e($app_env) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="lc-guide">
            <div class="lc-guide-title">
                <i class="fa-solid fa-list-check"></i> Finish Order
            </div>
            <ol class="lc-guide">
                <li>Complete every incomplete check below.</li>
                <li>Verify inventory, booking flow, and portal access.</li>
                <li>Run a full payment in Stripe test mode.</li>
                <li>Switch to live Stripe keys after QA passes.</li>
                <li>Do a live smoke test after launch.</li>
            </ol>
        </div>
    </div>
</div>

<!-- Pending banner -->
<?php if (!empty($launch_pending_checks)): ?>
<div class="lc-pending">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div>
        <strong>Still to finish:</strong>
        <div class="lc-pending-items">
            <?php foreach ($launch_pending_checks as $pc): ?>
                <span class="lc-pending-tag"><?= e($pc['label']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Checklist -->
<div class="lc-list">
    <?php foreach ($launch_checks as $check): ?>
        <?php $done = (bool)$check['ready']; ?>
        <div class="lc-item <?= $done ? 'is-done' : 'is-todo' ?>">
            <div class="d-flex align-items-center gap-3">
                <div class="lc-item-icon">
                    <i class="fa-solid <?= $done ? 'fa-circle-check' : 'fa-circle-dot' ?>"></i>
                </div>
                <div class="lc-item-body">
                    <div class="lc-item-label"><?= e($check['label']) ?></div>
                    <p class="lc-item-desc"><?= e($check['description']) ?></p>
                </div>
            </div>
            <div class="lc-item-right">
                <span class="lc-badge <?= $done ? 'lc-badge-done' : 'lc-badge-todo' ?>">
                    <i class="fa-solid <?= $done ? 'fa-check' : 'fa-hourglass-half' ?>"></i>
                    <?= $done ? 'Complete' : 'Needs Attention' ?>
                </span>
                <a href="<?= e($check['href']) ?>" class="btn-tp-<?= $done ? 'ghost' : 'primary' ?> btn-tp-sm">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    <?= $done ? 'View Area' : 'Open &amp; Fix' ?>
                </a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Bottom cards -->
<div class="lc-bottom-cards">
    <div class="lc-info-card">
        <div class="lc-info-header">
            <i class="fa-solid fa-vial-circle-check"></i> Developer QA Pass
        </div>
        <div class="lc-info-body">
            <ul class="lc-step-list">
                <li>Create a test booking from the public site.</li>
                <li>Confirm inventory and size cards match real stock.</li>
                <li>Open the customer portal and verify lookup and invoice actions.</li>
                <li>Send a test email and confirm branded content looks correct.</li>
                <li>Process a Stripe test payment and confirm webhook updates land.</li>
            </ul>
        </div>
    </div>
    <div class="lc-info-card">
        <div class="lc-info-header">
            <i class="fa-solid fa-person-chalkboard"></i> Client Handoff
        </div>
        <div class="lc-info-body">
            <ul class="lc-step-list">
                <li>Walk the owner through Dashboard, Bookings, Work Orders, and Payments.</li>
                <li>Show staff how to update booking status and mark manual payments.</li>
                <li>Review Settings so they know where Stripe, email, and branding live.</li>
                <li>Share the testing guide and this launch page as reference points.</li>
                <li>Have them complete one supervised booking-to-payment run.</li>
            </ul>
        </div>
    </div>
</div>

<?php layout_end(); ?>
