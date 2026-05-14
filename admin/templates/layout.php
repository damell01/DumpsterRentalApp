<?php
/**
 * Layout Template — Trash Panda Roll-Offs Admin
 *
 * Provides layout_start($page_title, $active_nav) and layout_end().
 */

/**
 * Render the top of the page: <html>, <head>, sidebar, topbar, and opens .tp-content-inner.
 *
 * @param string $page_title  The page-specific title shown in <title> and the topbar.
 * @param string $active_nav  Key string matching one of the sidebar nav items (e.g. 'dashboard').
 */
function layout_start(string $page_title, string $active_nav = ''): void
{
    $page_guides = [
        'dashboard' => [
            'title' => 'Dashboard Guide',
            'summary' => 'Use the dashboard as your daily command center for what needs attention first.',
            'tips' => [
                'Start with anything overdue, pending, or unpaid.',
                'Use the quick actions when you need to create a booking or work order fast.',
                'Review recent activity to confirm office actions and payment updates.',
            ],
        ],
        'leads' => [
            'title' => 'Leads Guide',
            'summary' => 'Leads are website quote or contact requests that still need follow-up.',
            'tips' => [
                'Review new leads first and call or email them quickly.',
                'Convert good leads into customers once they are ready to book.',
                'Use status updates to keep the pipeline honest for the office.',
            ],
        ],
        'bookings' => [
            'title' => 'Bookings Guide',
            'summary' => 'Bookings track the rental request, scheduling, and payment state for a dumpster job.',
            'tips' => [
                'Pending requests should usually be approved, invoiced, or turned into work orders quickly.',
                'Watch booking status and payment status separately because they solve different questions.',
                'Create a work order once the job is confirmed for operations.',
            ],
        ],
        'work_orders' => [
            'title' => 'Work Orders Guide',
            'summary' => 'Work orders are the operations record for delivery, pickup, notes, and field activity.',
            'tips' => [
                'Keep notes updated so office and driver context stays in one place.',
                'Use work orders for schedule execution, not just customer communication.',
                'Create the invoice from the work order when the job is ready for billing.',
            ],
        ],
        'invoices' => [
            'title' => 'Invoices Guide',
            'summary' => 'Invoices are your billing records for bookings, work orders, and custom charges.',
            'tips' => [
                'Check invoice status before chasing payment so you know if it is draft, sent, or paid.',
                'Use Stripe payment links when the customer should pay online.',
                'Mark cash and check payments manually so reports stay accurate.',
            ],
        ],
        'customers' => [
            'title' => 'Customers Guide',
            'summary' => 'Customers are the long-term record of contact details, jobs, and billing history.',
            'tips' => [
                'Use the customer record before creating duplicates.',
                'Review booking and invoice history when resolving support questions.',
                'Convert leads into customers once they become real accounts.',
            ],
        ],
        'inventory' => [
            'title' => 'Inventory Guide',
            'summary' => 'Inventory controls your dumpsters, pricing, and availability.',
            'tips' => [
                'Keep status accurate so the booking flow reflects real availability.',
                'Update pricing here first, then sync to Stripe when needed.',
                'Use maintenance and reserved states to prevent bad assignments.',
            ],
        ],
        'payments' => [
            'title' => 'Payments Guide',
            'summary' => 'Payments show what has actually been collected across Stripe, cash, and check.',
            'tips' => [
                'Use this page to reconcile what was really paid, not what was only invoiced.',
                'Filter by method and date when closing out a day or week.',
                'Stripe activity and manual office entries should both land here.',
            ],
        ],
        'subscriptions' => [
            'title' => 'Subscriptions Guide',
            'summary' => 'Subscriptions handle recurring billing services and stored payment relationships.',
            'tips' => [
                'Pause or cancel carefully because those changes affect future billing automatically.',
                'Verify the customer and service before changing recurring status.',
                'Use the billing portal for customer self-service when possible.',
            ],
        ],
        'calendar' => [
            'title' => 'Calendar Guide',
            'summary' => 'The calendar helps the office see deliveries and pickups in one visual schedule.',
            'tips' => [
                'Use it to spot overloaded days before they become dispatch problems.',
                'Click through events to review booking or work order details.',
                'Cross-check calendar density against available dumpsters.',
            ],
        ],
        'settings' => [
            'title' => 'Settings Guide',
            'summary' => 'Settings control branding, billing, Stripe, email, and system behavior.',
            'tips' => [
                'Change one section at a time and verify the result before moving on.',
                'Treat Stripe, email, and branding as launch-critical areas.',
                'Use the Help page and testing guide before making production changes.',
            ],
        ],
        'help' => [
            'title' => 'Help Guide',
            'summary' => 'This area is the built-in training and reference center for admins and office staff.',
            'tips' => [
                'Use the Testing section before launch or after major changes.',
                'Use Client Onboarding when training office staff or owners.',
                'Keep the written guide aligned with real process changes.',
            ],
        ],
    ];

    // Sidebar nav item definitions
    // [key, label, icon-class, href, role-gate (null = everyone, array = has_role check)]
    $nav_items = [
        [
            'key'   => 'dashboard',
            'label' => 'Dashboard',
            'icon'  => 'fa-gauge',
            'href'  => APP_URL . '/dashboard.php',
            'roles' => null,
            'badge' => null,
        ],
        // ── Operations ──────────────────────────────────────────────────────
        ['group' => 'Operations'],
        [
            'key'   => 'bookings',
            'label' => 'Bookings',
            'icon'  => 'fa-calendar-check',
            'href'  => APP_URL . '/modules/bookings/index.php',
            'roles' => null,
            'badge' => 'bookings',
        ],
        [
            'key'   => 'work_orders',
            'label' => 'Work Orders',
            'icon'  => 'fa-clipboard-list',
            'href'  => APP_URL . '/modules/work_orders/index.php',
            'roles' => null,
            'badge' => 'work_orders',
        ],
        [
            'key'   => 'calendar',
            'label' => 'Calendar',
            'icon'  => 'fa-calendar-days',
            'href'  => APP_URL . '/modules/calendar/index.php',
            'roles' => null,
            'badge' => null,
        ],
        [
            'key'   => 'customers',
            'label' => 'Customers',
            'icon'  => 'fa-users',
            'href'  => APP_URL . '/modules/customers/index.php',
            'roles' => null,
            'badge' => null,
        ],
        [
            'key'   => 'leads',
            'label' => 'Leads',
            'icon'  => 'fa-user-plus',
            'href'  => APP_URL . '/modules/leads/index.php',
            'roles' => ['admin', 'office'],
            'badge' => 'leads',
        ],
        // ── Finance ─────────────────────────────────────────────────────────
        ['group' => 'Finance'],
        [
            'key'   => 'invoices',
            'label' => 'Invoices',
            'icon'  => 'fa-file-invoice-dollar',
            'href'  => APP_URL . '/modules/invoices/index.php',
            'roles' => null,
            'badge' => null,
        ],
        [
            'key'   => 'subscriptions',
            'label' => 'Subscriptions',
            'icon'  => 'fa-arrows-rotate',
            'href'  => APP_URL . '/modules/subscriptions/index.php',
            'roles' => ['admin', 'office'],
            'badge' => null,
        ],
        [
            'key'   => 'payment_methods',
            'label' => 'Payment Methods',
            'icon'  => 'fa-wallet',
            'href'  => APP_URL . '/modules/payment_methods/index.php',
            'roles' => ['admin', 'office'],
            'badge' => null,
        ],
        [
            'key'   => 'payments',
            'label' => 'Payments',
            'icon'  => 'fa-money-bill-wave',
            'href'  => APP_URL . '/modules/payments/index.php',
            'roles' => ['admin', 'office'],
            'badge' => null,
        ],
        [
            'key'   => 'reports',
            'label' => 'Reports',
            'icon'  => 'fa-chart-bar',
            'href'  => APP_URL . '/modules/reports/index.php',
            'roles' => null,
            'badge' => null,
        ],
        // ── Tools ────────────────────────────────────────────────────────────
        ['group' => 'Tools'],
        [
            'key'   => 'webhooks',
            'label' => 'Webhook Logs',
            'icon'  => 'fa-plug-circle-bolt',
            'href'  => APP_URL . '/modules/webhooks/index.php',
            'roles' => ['admin'],
            'badge' => null,
        ],
        [
            'key'   => 'inventory',
            'label' => 'Inventory',
            'icon'  => 'fa-dumpster',
            'href'  => APP_URL . '/modules/dumpsters/index.php',
            'roles' => null,
            'badge' => null,
        ],
        [
            'key'   => 'notifications',
            'label' => 'Notifications',
            'icon'  => 'fa-bell',
            'href'  => APP_URL . '/modules/notifications/index.php',
            'roles' => ['admin', 'office'],
            'badge' => null,
        ],
        [
            'key'   => 'settings',
            'label' => 'Settings',
            'icon'  => 'fa-gear',
            'href'  => APP_URL . '/modules/settings/index.php',
            'roles' => ['admin', 'office'],
            'badge' => null,
        ],
        [
            'key'   => 'users',
            'label' => 'Users',
            'icon'  => 'fa-user-group',
            'href'  => APP_URL . '/modules/settings/users.php',
            'roles' => ['admin'],
            'badge' => null,
        ],
        [
            'key'   => 'help',
            'label' => 'Help & Guide',
            'icon'  => 'fa-circle-question',
            'href'  => APP_URL . '/modules/help/index.php',
            'roles' => null,
            'badge' => null,
        ],
    ];

    // Resolve badge counts lazily so a missing DB connection does not fatal-error the layout.
    $get_badge = function (string $type): int {
        if (!function_exists('db_query')) {
            return 0;
        }
        try {
            if ($type === 'work_orders') {
                $row = db_fetch(
                    "SELECT COUNT(*) AS cnt FROM work_orders WHERE status IN ('scheduled','pickup_requested')"
                );
                return (int) ($row['cnt'] ?? 0);
            }
            if ($type === 'bookings') {
                $row = db_fetch(
                    "SELECT COUNT(*) AS cnt FROM bookings WHERE booking_status IN ('pending','confirmed') AND payment_status IN ('unpaid','pending','pending_cash','pending_check')"
                );
                return (int) ($row['cnt'] ?? 0);
            }
            if ($type === 'leads') {
                $row = db_fetch(
                    "SELECT COUNT(*) AS cnt FROM leads WHERE archived = 0 AND status IN ('new','contacted','quoted')"
                );
                return (int) ($row['cnt'] ?? 0);
            }
        } catch (\Throwable $e) {
            // Swallow DB errors — badge is cosmetic.
        }
        return 0;
    };

    // Current user info
    $current_user_name = '';
    $current_user_role = '';
    if (function_exists('current_user')) {
        $u = current_user();
        $current_user_name = htmlspecialchars($u['name'] ?? $u['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
        $current_user_role = htmlspecialchars(ucfirst($u['role'] ?? ''), ENT_QUOTES, 'UTF-8');
    }

    $escaped_title = htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8');
    $app_name      = defined('APP_NAME') ? htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') : 'Trash Panda Roll-Offs';
    $asset_path    = defined('ASSET_PATH') ? ASSET_PATH : '';
    $app_url       = defined('APP_URL')    ? APP_URL    : '';
    $page_guide    = $page_guides[$active_nav] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escaped_title ?> | <?= $app_name ?></title>

    <!-- Bootstrap 5.3 -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- Font Awesome 6 -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLzsA=="
          crossorigin="anonymous"
          referrerpolicy="no-referrer">

    <!-- Google Fonts: Barlow Condensed, Barlow, Black Han Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Black+Han+Sans&family=Barlow+Condensed:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700&family=Barlow:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap">

    <!-- App styles (cache-busted by file mtime) -->
    <?php
    $css_file = defined('ROOT_PATH') ? ROOT_PATH . '/assets/css/app.css' : '';
    $css_ver  = ($css_file && file_exists($css_file)) ? filemtime($css_file) : (defined('APP_VERSION') ? APP_VERSION : '1');
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($asset_path, ENT_QUOTES, 'UTF-8') ?>/css/app.css?v=<?= $css_ver ?>">

    <!-- PWA -->
    <link rel="manifest" href="<?= htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') ?>/manifest.json">
    <meta name="theme-color" content="#f97316">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="TP Admin">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($asset_path, ENT_QUOTES, 'UTF-8') ?>/img/icon-192.png">
</head>
<body>

<!-- =====================================================================
     SIDEBAR
     ===================================================================== -->
<div class="tp-sidebar" id="tpSidebar">

    <!-- Brand -->
    <div class="sb-brand">
        <?php
        // Try uploaded logo first, then custom path, then bundled logo
        $custom_logo_url  = get_setting('logo_url', '');
        $custom_logo_path = get_setting('logo_path', '');
        $logo_url         = '';
        if (!empty($custom_logo_url)) {
            $logo_url = htmlspecialchars($custom_logo_url, ENT_QUOTES, 'UTF-8');
        } elseif (!empty($custom_logo_path)) {
            $logo_url = htmlspecialchars($custom_logo_path, ENT_QUOTES, 'UTF-8');
        } elseif (!empty($asset_path)) {
            $logo_url = htmlspecialchars($asset_path, ENT_QUOTES, 'UTF-8') . '/img/logo.png';
        }
        ?>
        <?php if (!empty($logo_url)): ?>
        <img src="<?= $logo_url ?>"
             alt="<?= $app_name ?>"
             id="sb-logo"
             onerror="this.style.display='none';">
        <?php endif; ?>
        <div class="sb-brand-text">TRASH PANDA<br><em>ROLL-OFFS</em></div>
    </div>

    <!-- Navigation -->
    <nav class="sb-nav">
        <ul class="list-unstyled mb-0">
        <?php foreach ($nav_items as $item):
            // Group label separator
            if (isset($item['group'])):
        ?>
            <li><div class="sb-group-label"><?= htmlspecialchars($item['group'], ENT_QUOTES, 'UTF-8') ?></div></li>
            <?php continue; endif; ?>
            <?php
            // Role-gate: skip if user does not meet requirements
            if (!empty($item['roles'])) {
                if (!function_exists('has_role') || !has_role(...$item['roles'])) {
                    continue;
                }
            }

            $is_active  = ($active_nav === $item['key']);
            $item_class = 'sb-item' . ($is_active ? ' active' : '');
            $badge_count = 0;
            if (!empty($item['badge'])) {
                $badge_count = $get_badge($item['badge']);
            }
        ?>
            <li>
                <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
                   class="<?= $item_class ?>">
                    <span class="sb-icon">
                        <i class="fa-solid <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                    </span>
                    <span class="sb-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($badge_count > 0): ?>
                    <span class="sb-count ms-auto"><?= $badge_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
        </ul>
    </nav>

    <!-- Footer / user info -->
    <div class="sb-footer">
        <?php if ($current_user_name): ?>
        <div class="sb-user d-flex align-items-center gap-2 mb-2">
            <div class="flex-grow-1 overflow-hidden">
                <div class="text-truncate sb-user-name">
                    <?= $current_user_name ?>
                </div>
                <?php if ($current_user_role): ?>
                <div class="sb-user-role"><?= $current_user_role ?></div>
                <?php endif; ?>
            </div>
            <?php if ($current_user_role): ?>
            <span class="tp-badge badge-scheduled" style="font-size:.65rem;">
                <?= $current_user_role ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') ?>/logout.php"
           class="btn-tp-ghost btn-tp-sm w-100 justify-content-center">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            Logout
        </a>
    </div>

</div><!-- /.tp-sidebar -->


<!-- =====================================================================
     MAIN WRAPPER
     ===================================================================== -->
<div class="tp-main">

    <!-- Top bar -->
    <div class="tp-topbar">
        <!-- Hamburger (mobile) -->
        <button class="hamburger-btn me-1" id="hamburgerBtn"
                aria-label="Toggle navigation" aria-expanded="false" aria-controls="tpSidebar">
            <span class="hamburger-lines" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </span>
        </button>

        <!-- Page title -->
        <h4 class="mb-0 me-auto tp-topbar-title">
            <?= $escaped_title ?>
        </h4>

        <!-- Right-side quick actions -->
        <div class="d-flex gap-2 align-items-center">
            <a href="<?= htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') ?>/modules/bookings/create.php"
               class="btn-tp-ghost btn-tp-sm no-print">
                <i class="fa-solid fa-plus"></i>
                New Booking
            </a>
            <a href="<?= htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') ?>/modules/work_orders/create.php"
               class="btn-tp-primary btn-tp-sm no-print">
                <i class="fa-solid fa-plus"></i>
                New Work Order
            </a>
        </div>
    </div><!-- /.tp-topbar -->

    <!-- Content area -->
    <div class="tp-content">

        <!-- Flash messages -->
        <?php if (function_exists('render_flash')) { render_flash(); } ?>

        <!-- Page body (opened here, closed by layout_end) -->
        <div class="tp-content-inner">
        <?php if ($page_guide): ?>
        <button type="button"
                class="tp-guide-trigger no-print"
                id="tpGuideTrigger"
                aria-expanded="false"
                aria-controls="tpPageGuide">
            <i class="fa-solid fa-compass-drafting"></i>
            <span>Page Guide</span>
        </button>

        <aside class="tp-page-guide no-print" id="tpPageGuide" aria-hidden="true">
            <div class="tp-page-guide-head">
                <div>
                    <div class="tp-page-guide-kicker">On this page</div>
                    <h3><?= htmlspecialchars($page_guide['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                </div>
                <button type="button" class="tp-page-guide-close" id="tpGuideClose" aria-label="Close page guide">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <p class="tp-page-guide-summary"><?= htmlspecialchars($page_guide['summary'], ENT_QUOTES, 'UTF-8') ?></p>
            <ul class="tp-page-guide-list">
                <?php foreach ($page_guide['tips'] as $tip): ?>
                <li><?= htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="tp-page-guide-actions">
                <a href="<?= htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') ?>/modules/help/index.php" class="btn-tp-ghost btn-tp-sm">
                    <i class="fa-solid fa-circle-question"></i>
                    Open Help
                </a>
                <a href="<?= htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') ?>/../docs/TESTING_AND_ONBOARDING_GUIDE.md" target="_blank" rel="noopener" class="btn-tp-primary btn-tp-sm">
                    <i class="fa-solid fa-book"></i>
                    Testing Guide
                </a>
            </div>
        </aside>
        <?php endif; ?>
<?php
} // end layout_start()


/**
 * Close the page: ends .tp-content-inner, .tp-content, .tp-main, loads JS, closes </body></html>.
 */
function layout_end(): void
{
    $asset_path = defined('ASSET_PATH') ? ASSET_PATH : '';
    $app_url    = defined('APP_URL')    ? APP_URL    : '';
?>
        </div><!-- /.tp-content-inner -->
    </div><!-- /.tp-content -->
</div><!-- /.tp-main -->

<!-- Bootstrap 5.3 JS bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>

<!-- App scripts (cache-busted by file mtime) -->
<?php
$js_file = defined('ROOT_PATH') ? ROOT_PATH . '/assets/js/app.js' : '';
$js_ver  = ($js_file && file_exists($js_file)) ? filemtime($js_file) : (defined('APP_VERSION') ? APP_VERSION : '1');
?>
<script src="<?= htmlspecialchars($asset_path, ENT_QUOTES, 'UTF-8') ?>/js/app.js?v=<?= $js_ver ?>"></script>

<!-- Service Worker registration -->
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') ?>/sw.js', {
        scope: '<?= htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') ?>/'
    }).catch(function(){});
}
</script>

<!-- Push Notification Registration (Admin) -->
<script>
(function() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;

    function urlBase64ToUint8Array(b64) {
        var pad = '='.repeat((4 - b64.length % 4) % 4);
        var s   = (b64 + pad).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(s);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function subscribeAdmin() {
        navigator.serviceWorker.ready.then(function(reg) {
            // First get the VAPID public key (action=getVapidKey triggers key generation server-side)
            fetch('<?= htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') ?>/api/push-subscribe.php', {
                method:      'POST',
                headers:     { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body:        JSON.stringify({ action: 'getVapidKey' })
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
                return fetch('<?= htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') ?>/api/push-subscribe.php', {
                    method:      'POST',
                    headers:     { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body:        JSON.stringify({ action: 'subscribe', subscription: sub.toJSON() })
                });
            })
            .catch(function() {});
        });
    }

    if (Notification.permission === 'granted') {
        subscribeAdmin();
    } else if (Notification.permission !== 'denied') {
        // Show a subtle one-time prompt the first time
        var _prompted = sessionStorage.getItem('tp_push_prompted');
        if (!_prompted) {
            sessionStorage.setItem('tp_push_prompted', '1');
            Notification.requestPermission().then(function(perm) {
                if (perm === 'granted') subscribeAdmin();
            });
        }
    }
})();
</script>

</body>
</html>
<?php
} // end layout_end()
