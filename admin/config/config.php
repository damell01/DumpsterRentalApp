<?php
$manual_config = [
    'DB_HOST' => '',
    'DB_NAME' => '',
    'DB_USER' => '',
    'DB_PASS' => '',
    'DB_CHARSET' => 'utf8mb4',
    'APP_NAME' => 'Trash Panda Roll-Offs',
    'APP_VERSION' => '1.0.0',
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'APP_INSTALLED' => 'false',
    'SESSION_LIFETIME' => '7200',
    'CRON_KEY' => '',
    'PORTAL_SIGNING_KEY' => '',
    'APP_LOG_DIR' => '',
];
// ── .env loader ───────────────────────────────────────────────────────────────
// Loads key=value pairs from a .env file in the project root (one level above
// the /admin/ directory). Lines starting with # are treated as comments.
// Values are stored in $_ENV and made available via getenv().
// Hardcoded defaults below are used as fallbacks when a .env file is absent.
(static function (): void {
    $env_file = dirname(__DIR__, 2) . '/.env';  // project root/.env
    if (!is_file($env_file)) {
        return;
    }
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key   = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        // Strip optional surrounding quotes (single or double)
        if (strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
})();

// Helper: read from .env (via $_ENV) with a hardcoded default fallback.
// Only used in this file; not exposed globally.
$_env = static function (string $key, string $default) use ($manual_config): string {
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }

    if (array_key_exists($key, $manual_config) && (string)$manual_config[$key] !== '') {
        return (string)$manual_config[$key];
    }

    return $default;
};

// ── Database ─────────────────────────────────────────────────────────────────
define('DB_HOST',    $_env('DB_HOST',    'localhost'));
define('DB_NAME',    $_env('DB_NAME',    ''));
define('DB_USER',    $_env('DB_USER',    ''));
define('DB_PASS',    $_env('DB_PASS',    ''));
define('DB_CHARSET', $_env('DB_CHARSET', 'utf8mb4'));

// ── Application ───────────────────────────────────────────────────────────────
define('APP_NAME',    $_env('APP_NAME',    'Trash Panda Roll-Offs'));
define('APP_VERSION', $_env('APP_VERSION', '1.0.0'));
define('APP_ENV',     $_env('APP_ENV',     'production'));

// Dynamically build APP_URL from the current request so the admin panel
// works on any domain without manual configuration.
// HTTP_HOST is sanitised to prevent host-header injection.
$_app_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_raw_host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Allow only valid hostname characters (letters, digits, hyphens, dots) and an optional :port
$_app_host   = preg_replace('/[^a-zA-Z0-9\-\.:]/', '', $_raw_host);
define('APP_URL', $_app_scheme . '://' . $_app_host . '/admin');  // no trailing slash
unset($_app_scheme, $_raw_host, $_app_host);

// ── Debug Mode ────────────────────────────────────────────────────────────────
// Set to true to display detailed errors and exception traces in the browser.
// NEVER leave true in production — it exposes sensitive information.
$_debug_raw = strtolower($_env('APP_DEBUG', 'false'));
define('APP_DEBUG', APP_ENV !== 'production' && in_array($_debug_raw, ['true', '1', 'yes'], true));
unset($_debug_raw);

define('SESSION_NAME',      'tp_session');
define('CSRF_TOKEN_NAME',   'tp_csrf');
define('SESSION_LIFETIME',  (int)$_env('SESSION_LIFETIME', '7200'));
define('ROOT_PATH',  dirname(__DIR__));  // points to /admin/
define('INC_PATH',   ROOT_PATH . '/includes');
define('TMPL_PATH',  ROOT_PATH . '/templates');
define('ASSET_PATH', APP_URL  . '/assets');

// set APP_INSTALLED=true in .env (or here) after running the installer.
$_installed_raw = strtolower($_env('APP_INSTALLED', 'false'));
define('APP_INSTALLED', in_array($_installed_raw, ['true', '1', 'yes'], true));
unset($_installed_raw);

// ── Cron ──────────────────────────────────────────────────────────────────────
define('CRON_KEY', $_env('CRON_KEY', 'change-this-to-a-random-secret'));
define('PORTAL_SIGNING_KEY', $_env('PORTAL_SIGNING_KEY', 'change-this-portal-signing-key'));
define('APP_LOG_DIR', $_env('APP_LOG_DIR', ROOT_PATH . '/logs'));

unset($_env);
unset($manual_config);
