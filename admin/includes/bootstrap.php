<?php
/**
 * Bootstrap – load all core dependencies and initialise the session.
 * Include this file at the top of every entry-point script.
 *
 * Trash Panda Roll-Offs
 */

// ── Security Headers ─────────────────────────────────────────────────────────
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com https://unpkg.com 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://*.tile.openstreetmap.org https://tile.openstreetmap.org; connect-src 'self' https://nominatim.openstreetmap.org https://router.project-osrm.org;");

// ── 1. Configuration ────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/config.php';

// ── 2. Error handling (after config so APP_DEBUG / APP_LOG_DIR are defined) ─
// Debug mode: ?debug=1 on non-production env, or APP_DEBUG=true in .env
$debugMode = (
    defined('APP_ENV') && APP_ENV !== 'production' && isset($_GET['debug']) && $_GET['debug'] === '1'
) || (defined('APP_DEBUG') && APP_DEBUG === true);

require_once INC_PATH . '/error_handler.php';
tp_install_error_handler(false);

// ── 3. Composer autoloader (installed via `composer install` in /admin/) ────
$_composer_autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($_composer_autoload)) {
    require_once $_composer_autoload;
}
unset($_composer_autoload);

// ── 3b. TrashPanda namespace autoloader ──────────────────────────────────────
spl_autoload_register(static function (string $class): void {
    $prefix = 'TrashPanda\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// ── 4. Core includes (order matters: db → auth → helpers → mailer → billing) ─
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/mailer.php';
require_once INC_PATH . '/billing.php';

// ── 3. Start / resume the session ───────────────────────────────────────────
session_init();

// ── 4. Force password-change flow ───────────────────────────────────────────
// If the logged-in user has must_change_pw set, redirect them to the change-
// password page unless they are already on it (avoids an infinite loop).
if (!empty($_SESSION['user_id'])) {
    $current_script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $change_pw_file = ROOT_PATH . '/modules/settings/change_password.php';

    if (realpath($current_script) !== realpath($change_pw_file)) {
        $user = current_user();
        if ($user && !empty($user['must_change_pw'])) {
            redirect(APP_URL . '/modules/settings/change_password.php?force=1');
        }
    }
}
