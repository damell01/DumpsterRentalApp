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

layout_start('Launch Checklist', 'help');
?>

<style>
.launch-hero {
    background: linear-gradient(135deg, rgba(15,23,42,.98), rgba(30,41,59,.92));
    border: 1px solid rgba(249,115,22,.18);
    border-radius: 16px;
    padding: 1.25rem;
    margin-bottom: 1rem;
}
.launch-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) minmax(280px, .9fr);
    gap: 1rem;
}
.launch-score-ring {
    width: 110px;
    height: 110px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--wh);
    background: radial-gradient(circle at 30% 30%, rgba(249,115,22,.45), rgba(15,23,42,.98));
    border: 2px solid rgba(249,115,22,.35);
    flex-shrink: 0;
}
.launch-status-pill {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .35rem .65rem;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
}
.tp-launch-status-good {
    background: rgba(34,197,94,.14);
    color: #86efac;
    border: 1px solid rgba(34,197,94,.28);
}
.tp-launch-status-mid {
    background: rgba(250,204,21,.14);
    color: #fde68a;
    border: 1px solid rgba(250,204,21,.26);
}
.tp-launch-status-warn {
    background: rgba(248,113,113,.14);
    color: #fca5a5;
    border: 1px solid rgba(248,113,113,.26);
}
.launch-progress {
    width: 100%;
    height: 12px;
    border-radius: 999px;
    background: rgba(148,163,184,.16);
    overflow: hidden;
}
.launch-progress-bar {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #f97316, #fb923c);
}
.launch-checklist {
    display: grid;
    gap: .85rem;
}
.launch-check {
    background: var(--dk2);
    border: 1px solid var(--st2);
    border-radius: 14px;
    padding: 1rem;
}
.launch-check-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}
.launch-check h6 {
    margin: 0 0 .25rem;
}
.launch-check p {
    margin: 0;
    color: var(--gl);
    font-size: .9rem;
}
.launch-check-state {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    font-size: .8rem;
    font-weight: 700;
    white-space: nowrap;
}
.launch-next {
    background: rgba(15,23,42,.8);
    border: 1px solid rgba(148,163,184,.16);
    border-radius: 14px;
    padding: 1rem;
}
@media (max-width: 991px) {
    .launch-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h5 class="mb-0"><i class="fa-solid fa-rocket me-2" style="color:#f97316;"></i>Launch Checklist</h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(APP_URL) ?>/dashboard.php" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
        </a>
        <a href="<?= e(APP_URL) ?>/../docs/TESTING_AND_ONBOARDING_GUIDE.md" target="_blank" rel="noopener" class="btn-tp-primary btn-tp-sm">
            <i class="fa-solid fa-book"></i> Open Full Testing Guide
        </a>
    </div>
</div>

<div class="launch-hero">
    <div class="launch-grid align-items-start">
        <div>
            <div class="d-flex gap-3 align-items-center mb-3 flex-wrap">
                <div class="launch-score-ring"><?= $launch_score ?>%</div>
                <div>
                    <div class="mb-2">
                        <span class="launch-status-pill <?= e($launch_status_class) ?>">
                            <i class="fa-solid fa-flag-checkered"></i> <?= e($launch_status) ?>
                        </span>
                    </div>
                    <h4 class="mb-2">Production Readiness Snapshot</h4>
                    <p class="mb-0" style="color:var(--gl);"><?= $launch_ready_count ?> of <?= $launch_total_count ?> core launch checks are complete. Use this page to close the remaining gaps before go-live.</p>
                </div>
            </div>
            <div class="launch-progress">
                <div class="launch-progress-bar" style="width: <?= $launch_score ?>%;"></div>
            </div>
            <div class="mt-3" style="font-size:.88rem;color:var(--gl);">
                Current server environment: <strong style="color:var(--wh);"><?= e($app_env) ?></strong>
            </div>
        </div>

        <div class="launch-next">
            <h6 class="mb-3">Recommended Finish Order</h6>
            <ol class="mb-0" style="font-size:.92rem;line-height:1.9;">
                <li>Complete every incomplete setup check below.</li>
                <li>Verify public inventory, booking flow, and portal access.</li>
                <li>Run one full payment flow in Stripe test mode.</li>
                <li>Switch to live Stripe keys only after QA passes.</li>
                <li>Do one live smoke test after launch.</li>
            </ol>
        </div>
    </div>
</div>

<?php if (!empty($launch_pending_checks)): ?>
<div class="alert alert-warning mb-4" style="font-size:.9rem;">
    <i class="fa-solid fa-triangle-exclamation me-2"></i>
    <strong>Still to finish:</strong>
    <?= e(implode(', ', array_map(static fn(array $check): string => $check['label'], $launch_pending_checks))) ?>.
</div>
<?php endif; ?>

<div class="launch-checklist mb-4">
    <?php foreach ($launch_checks as $check): ?>
        <div class="launch-check">
            <div class="launch-check-head">
                <div>
                    <h6><?= e($check['label']) ?></h6>
                    <p><?= e($check['description']) ?></p>
                </div>
                <div class="launch-check-state" style="color:<?= $check['ready'] ? '#86efac' : '#fdba74' ?>;">
                    <i class="fa-solid <?= $check['ready'] ? 'fa-circle-check' : 'fa-circle-dot' ?>"></i>
                    <?= $check['ready'] ? 'Complete' : 'Needs Attention' ?>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2 flex-wrap">
                <a href="<?= e($check['href']) ?>" class="btn-tp-primary btn-tp-sm">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Related Area
                </a>
                <?php if (!$check['ready']): ?>
                    <span class="btn-tp-ghost btn-tp-sm" style="pointer-events:none;opacity:.9;">
                        <i class="fa-solid fa-hourglass-half"></i> Action Needed
                    </span>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="tp-card h-100">
            <div class="tp-card-header">
                <i class="fa-solid fa-vial-circle-check me-2 text-or"></i>Developer QA Pass
            </div>
            <div class="tp-card-body">
                <ul class="mb-0" style="line-height:1.9;">
                    <li>Create a test booking from the public site.</li>
                    <li>Confirm inventory and size cards match real stock.</li>
                    <li>Open the customer portal and verify lookup plus invoice actions.</li>
                    <li>Send a test email and confirm branded content looks correct.</li>
                    <li>Process a Stripe test payment and confirm webhook updates land.</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="tp-card h-100">
            <div class="tp-card-header">
                <i class="fa-solid fa-person-chalkboard me-2 text-or"></i>Client Handoff
            </div>
            <div class="tp-card-body">
                <ul class="mb-0" style="line-height:1.9;">
                    <li>Walk the owner through Dashboard, Bookings, Work Orders, and Payments.</li>
                    <li>Show office staff how to update booking status and mark manual payments.</li>
                    <li>Review Settings tabs so they know where Stripe, email, and branding live.</li>
                    <li>Share the testing guide and this launch page as their reference points.</li>
                    <li>Have them complete one supervised booking-to-payment run.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php layout_end(); ?>
