<?php
/**
 * Installer – Trash Panda Roll-Offs
 *
 * Runs all setup steps: pre-flight checks, database creation, schema import,
 * default settings, admin user, and dumpster seed data.
 *
 * Usage: navigate to /install/install.php in a browser.
 * After a successful run, set APP_INSTALLED = true in config/config.php.
 */

// ── Bootstrap config (without the full app bootstrap) ────────────────────────
$config_path = dirname(__DIR__) . '/config/config.php';
if (!is_readable($config_path)) {
    die('ERROR: Cannot read admin/config/config.php. Verify the file exists and is readable.');
}
require_once $config_path;

// ── Guard: block re-runs unless ?force=1 ─────────────────────────────────────
if (defined('APP_INSTALLED') && APP_INSTALLED === true && ($_GET['force'] ?? '') !== '1') {
    die(
        '<p style="font-family:sans-serif;color:#ef4444;padding:2rem;">'
        . '<strong>Already installed.</strong> '
        . 'If you need to re-run the installer, set <code>APP_INSTALLED = false</code> in '
        . '<code>config/config.php</code> or append <code>?force=1</code> to the URL.'
        . '</p>'
    );
}

// ── Collected results for display ────────────────────────────────────────────
$steps  = [];   // [ ['label'=>'...', 'ok'=>bool, 'detail'=>'...'] ]
$fatal  = false;

// Normalise DB config values to prevent hidden leading/trailing whitespace
// from causing authentication failures (common after copy/paste).
$dbHost = preg_replace('/^\s+|\s+$/u', '', (string) DB_HOST) ?? (string) DB_HOST;
$dbName = preg_replace('/^\s+|\s+$/u', '', (string) DB_NAME) ?? (string) DB_NAME;
$dbUser = preg_replace('/\s+/u', '', (string) DB_USER) ?? (string) DB_USER;
$dbPass = (string) DB_PASS;

/**
 * Record a step result and optionally mark the run as failed.
 */
function step(string $label, bool $ok, string $detail = ''): void
{
    global $steps, $fatal;
    $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $fatal = true;
    }
}

// =============================================================================
// PRE-FLIGHT CHECKS
// =============================================================================

// PHP version
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
step('PHP >= 8.0', $phpOk, 'Detected: ' . PHP_VERSION);

// PDO extension
$pdoOk = extension_loaded('pdo');
step('PDO extension loaded', $pdoOk);

// PDO_MySQL driver
$pdoMysqlOk = extension_loaded('pdo_mysql');
step('PDO_MySQL driver loaded', $pdoMysqlOk);

// config.php readable (already loaded, so this is a sanity display)
step('config/config.php readable', true);

// DB credentials look customised
$dbNameOk = defined('DB_NAME') && $dbName !== '' && $dbName !== 'your_db';
step('DB_NAME is configured (not placeholder)', $dbNameOk,
    $dbNameOk ? 'DB_NAME = ' . $dbName : 'DB_NAME is empty or still using a placeholder — please edit config/config.php');

$dbUserOk = defined('DB_USER') && $dbUser !== '' && $dbUser !== 'your_user';
step('DB_USER is configured (not placeholder)', $dbUserOk,
    $dbUserOk ? 'DB_USER = ' . $dbUser : 'DB_USER is empty or still using a placeholder — please edit config/config.php');

if ($dbHost !== (string) DB_HOST || $dbName !== (string) DB_NAME || $dbUser !== (string) DB_USER) {
    step(
        'Credential whitespace detected',
        true,
        'Leading/trailing whitespace was removed from DB_HOST/DB_NAME/DB_USER for this run.'
    );
}

// Stop here if any pre-flight failed
if ($fatal) {
    render_page($steps, false);
    exit;
}

// =============================================================================
// DATABASE CONNECTION
// =============================================================================

try {
    // Connect WITHOUT specifying a database so we can CREATE it if needed
    $dsn = sprintf('mysql:host=%s;charset=%s', $dbHost, DB_CHARSET);
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    step('Connected to MySQL server', true, 'Host: ' . $dbHost);
} catch (PDOException $e) {
    step('Connected to MySQL server', false, $e->getMessage());
    render_page($steps, false);
    exit;
}

// =============================================================================
// CREATE DATABASE
// =============================================================================

try {
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    step('Created/verified database', true, 'Database: ' . $dbName);
} catch (PDOException $e) {
    step('Created/verified database', false, $e->getMessage());
    render_page($steps, false);
    exit;
}

// Select the database
try {
    $pdo->exec('USE `' . $dbName . '`');
    step('Selected database', true);
} catch (PDOException $e) {
    step('Selected database', false, $e->getMessage());
    render_page($steps, false);
    exit;
}

// =============================================================================
// IMPORT SCHEMA
// =============================================================================

$schema_file = __DIR__ . '/schema.sql';

if (!is_readable($schema_file)) {
    step('Read schema.sql', false, 'File not found: ' . $schema_file);
    render_page($steps, false);
    exit;
}

$sql_raw = file_get_contents($schema_file);

// Split on semicolons, strip comments, execute each statement
$statements = explode(';', $sql_raw);
$exec_count = 0;
$schema_ok  = true;
$schema_err = '';

foreach ($statements as $stmt) {
    // Remove single-line comments (-- ...) and block comments (/* ... */)
    $stmt = preg_replace('/--[^\n]*/', '', $stmt);
    $stmt = preg_replace('/\/\*.*?\*\//s', '', $stmt);
    $stmt = trim($stmt);

    if ($stmt === '') {
        continue;
    }

    try {
        $pdo->exec($stmt);
        $exec_count++;
    } catch (PDOException $e) {
        $schema_ok  = false;
        $schema_err = $e->getMessage() . ' | Statement: ' . substr($stmt, 0, 120) . '…';
        break;
    }
}

step(
    'Imported schema (' . $exec_count . ' statement' . ($exec_count !== 1 ? 's' : '') . ')',
    $schema_ok,
    $schema_ok ? '' : $schema_err
);

if (!$schema_ok) {
    render_page($steps, false);
    exit;
}

$billing_upgrade_file = __DIR__ . '/billing_upgrade.sql';
if (!is_readable($billing_upgrade_file)) {
    step('Read billing_upgrade.sql', false, 'File not found: ' . $billing_upgrade_file);
    render_page($steps, false);
    exit;
}

$billing_sql_raw = file_get_contents($billing_upgrade_file);
$billing_statements = preg_split('/;\s*(?:\r?\n|$)/', (string)$billing_sql_raw);
$billing_exec_count = 0;
$billing_ok = true;
$billing_err = '';

foreach ($billing_statements as $stmt) {
    $stmt = preg_replace('/--[^\n]*/', '', $stmt);
    $stmt = preg_replace('/\/\*.*?\*\//s', '', $stmt);
    $stmt = trim($stmt);

    if ($stmt === '') {
        continue;
    }

    try {
        $pdo->exec($stmt);
        $billing_exec_count++;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (
            stripos($msg, 'Duplicate column') !== false ||
            stripos($msg, 'already exists') !== false ||
            stripos($msg, 'Duplicate key') !== false ||
            stripos($msg, 'Duplicate foreign key') !== false
        ) {
            continue;
        }
        $billing_ok = false;
        $billing_err = $msg . ' | Statement: ' . substr($stmt, 0, 120) . '...';
        break;
    }
}

step(
    'Imported billing upgrade (' . $billing_exec_count . ' statement' . ($billing_exec_count !== 1 ? 's' : '') . ')',
    $billing_ok,
    $billing_ok ? '' : $billing_err
);

if (!$billing_ok) {
    render_page($steps, false);
    exit;
}

// =============================================================================
// DEFAULT SETTINGS
// =============================================================================

$default_settings = [
    'company_name'    => 'Trash Panda Roll-Offs',
    'company_phone'   => '(251) 555-0100',
    'company_email'   => 'info@trashpandarolloffs.com',
    'company_address' => 'Baldwin County, AL',
    'tax_rate'        => '8.00',
    'quote_terms'     => 'Payment due upon completion. Rental period begins on delivery date.',
    'wo_footer'       => 'Thank you for choosing Trash Panda Roll-Offs!',
];

$settings_ok  = true;
$settings_err = '';

foreach ($default_settings as $key => $value) {
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO settings (`key`, `value`, `updated_at`) VALUES (?, ?, NOW())'
        )->execute([$key, $value]);
    } catch (PDOException $e) {
        $settings_ok  = false;
        $settings_err = $e->getMessage();
        break;
    }
}

step('Seeded default settings', $settings_ok, $settings_err);

// =============================================================================
// ADMIN USER
// =============================================================================

$admin_email    = 'admin@example.com';
$admin_password = password_hash('ChangeMe123!', PASSWORD_BCRYPT);

try {
    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$admin_email]);

    if (!$exists->fetch()) {
        $pdo->prepare(
            'INSERT INTO users (name, email, password, role, active, must_change_pw, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, 1, NOW(), NOW())'
        )->execute(['Admin', $admin_email, $admin_password, 'admin']);
        step('Created admin user', true, $admin_email . ' / ChangeMe123! (must change on first login)');
    } else {
        step('Admin user already exists', true, 'Skipped — ' . $admin_email . ' already present');
    }
} catch (PDOException $e) {
    step('Created admin user', false, $e->getMessage());
}

// =============================================================================
// SEED DUMPSTERS  (TP-001 … TP-008)
// =============================================================================

$dumpster_seed = [
    ['TP-001', '10 Yard'],
    ['TP-002', '10 Yard'],
    ['TP-003', '15 Yard'],
    ['TP-004', '15 Yard'],
    ['TP-005', '20 Yard'],
    ['TP-006', '20 Yard'],
    ['TP-007', '30 Yard'],
    ['TP-008', '40 Yard'],
];

$dumpsters_ok  = true;
$dumpsters_err = '';
$inserted      = 0;
$skipped       = 0;

foreach ($dumpster_seed as [$code, $size]) {
    try {
        $check = $pdo->prepare('SELECT id FROM dumpsters WHERE unit_code = ? LIMIT 1');
        $check->execute([$code]);

        if (!$check->fetch()) {
            $pdo->prepare(
                'INSERT INTO dumpsters (unit_code, size, status, `condition`, created_at, updated_at)
                 VALUES (?, ?, \'available\', \'good\', NOW(), NOW())'
            )->execute([$code, $size]);
            $inserted++;
        } else {
            $skipped++;
        }
    } catch (PDOException $e) {
        $dumpsters_ok  = false;
        $dumpsters_err = $e->getMessage();
        break;
    }
}

step(
    'Seeded dumpsters',
    $dumpsters_ok,
    $dumpsters_ok
        ? $inserted . ' inserted, ' . $skipped . ' already existed'
        : $dumpsters_err
);

// =============================================================================
// RENDER PAGE
// =============================================================================

render_page($steps, !$fatal);
exit;

// ── Helper: render the installer results page ─────────────────────────────────

function render_page(array $steps, bool $success): void
{
    $any_fail = !$success || array_filter($steps, fn($s) => !$s['ok']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installer – Trash Panda Roll-Offs</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: #0f1117;
            color: #e5e7eb;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            font-size: 15px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 3rem 1rem;
        }

        .installer-wrap {
            width: 100%;
            max-width: 680px;
        }

        .installer-brand {
            text-align: center;
            margin-bottom: 2rem;
        }

        .installer-brand h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #f97316;
            letter-spacing: .03em;
        }

        .installer-brand p {
            color: #6b7280;
            margin-top: .4rem;
            font-size: .9rem;
        }

        .installer-card {
            background: #1a1d27;
            border: 1px solid #2a2d3e;
            border-radius: 12px;
            overflow: hidden;
        }

        .installer-card-header {
            background: #12151f;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #2a2d3e;
            font-weight: 600;
            font-size: .95rem;
            color: #9ca3af;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .step-list {
            list-style: none;
            padding: 0;
        }

        .step-list li {
            display: flex;
            align-items: flex-start;
            gap: .85rem;
            padding: .85rem 1.5rem;
            border-bottom: 1px solid #1f2335;
        }

        .step-list li:last-child {
            border-bottom: none;
        }

        .step-icon {
            flex-shrink: 0;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
            font-weight: 700;
            margin-top: .05rem;
        }

        .step-icon.ok  { background: rgba(34,197,94,.15); color: #22c55e; }
        .step-icon.err { background: rgba(239,68,68,.15);  color: #ef4444; }

        .step-label {
            flex: 1;
        }

        .step-label strong {
            display: block;
            color: #e5e7eb;
            font-size: .9rem;
        }

        .step-detail {
            font-size: .78rem;
            color: #6b7280;
            margin-top: .2rem;
        }

        .step-detail.error-detail {
            color: #f87171;
        }

        .result-banner {
            margin: 1.5rem 0 0;
            padding: 1.25rem 1.5rem;
            border-radius: 10px;
            font-size: .95rem;
            line-height: 1.6;
        }

        .result-banner.success {
            background: rgba(34,197,94,.1);
            border: 1px solid rgba(34,197,94,.25);
            color: #86efac;
        }

        .result-banner.failure {
            background: rgba(239,68,68,.1);
            border: 1px solid rgba(239,68,68,.25);
            color: #fca5a5;
        }

        .result-banner strong {
            display: block;
            font-size: 1.05rem;
            margin-bottom: .4rem;
        }

        .result-banner code {
            background: rgba(255,255,255,.08);
            padding: .15em .4em;
            border-radius: 4px;
            font-size: .88em;
            font-family: 'Consolas', 'Menlo', monospace;
        }

        .creds-box {
            margin-top: .75rem;
            background: rgba(0,0,0,.25);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 8px;
            padding: .75rem 1rem;
            font-family: 'Consolas', 'Menlo', monospace;
            font-size: .85rem;
            line-height: 1.8;
            color: #d1d5db;
        }

        .installer-footer {
            text-align: center;
            color: #4b5563;
            font-size: .78rem;
            margin-top: 1.5rem;
        }
    </style>
</head>
<body>

<div class="installer-wrap">

    <!-- Brand -->
    <div class="installer-brand">
        <h1>🦝 Trash Panda Roll-Offs</h1>
        <p>Auto-Installer — Work Order Management System</p>
    </div>

    <!-- Steps card -->
    <div class="installer-card">
        <div class="installer-card-header">Installation Steps</div>

        <ul class="step-list">
        <?php foreach ($steps as $s): ?>
            <li>
                <span class="step-icon <?= $s['ok'] ? 'ok' : 'err' ?>">
                    <?= $s['ok'] ? '✓' : '✗' ?>
                </span>
                <div class="step-label">
                    <strong><?= htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php if (!empty($s['detail'])): ?>
                        <div class="step-detail <?= !$s['ok'] ? 'error-detail' : '' ?>">
                            <?= htmlspecialchars($s['detail'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>
    </div><!-- /.installer-card -->

    <!-- Result banner -->
    <?php if ($success): ?>
    <div class="result-banner success">
        <strong>✓ Installation complete!</strong>
        Next steps:
        <ol style="margin:.5rem 0 0 1.2rem;line-height:1.9;">
            <li>Open <code>admin/config/config.php</code> and set <code>APP_INSTALLED = true</code>.</li>
            <li>Delete or protect the <code>install/</code> directory so it cannot be re-run.</li>
            <li>Log in with the credentials below, then change your password immediately.</li>
        </ol>
        <div class="creds-box">
            URL:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?= htmlspecialchars(defined('APP_URL') ? APP_URL . '/login.php' : '/admin/login.php', ENT_QUOTES, 'UTF-8') ?><br>
            Email:&nbsp;&nbsp;&nbsp;admin@example.com<br>
            Password: ChangeMe123!
        </div>
    </div>
    <?php else: ?>
    <div class="result-banner failure">
        <strong>✗ Installation did not complete.</strong>
        One or more steps above failed. Resolve the errors shown, then reload this page to try again.
    </div>
    <?php endif; ?>

    <div class="installer-footer">
        Trash Panda Roll-Offs &mdash; v<?= defined('APP_VERSION') ? htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') : '1.0.0' ?>
    </div>

</div><!-- /.installer-wrap -->

</body>
</html>
<?php
}
