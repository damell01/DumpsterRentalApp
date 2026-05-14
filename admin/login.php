<?php
/**
 * Login page – Trash Panda Roll-Offs
 *
 * Does NOT call require_login() (the user is not authenticated yet).
 * Manually bootstraps config, db, helpers, auth, and starts the session.
 */

// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self';");

require_once __DIR__ . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/rate_limit.php';
session_init();

// Already logged in? Send them home.
if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/dashboard.php');
}

$error            = '';
$attempts_warning = '';

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $email = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    // Rate limit check before processing. Older installs may not have these tables yet.
    try {
        check_rate_limit($ip);
    } catch (\Throwable $e) {
        error_log('[TP Login] Rate limit check skipped: ' . $e->getMessage());
    }

    $userSql = 'SELECT * FROM users WHERE email = ?';
    if (db_column_exists('users', 'active')) {
        $userSql .= ' AND active = 1';
    }
    $userSql .= ' LIMIT 1';
    $user = db_fetch($userSql, [$email]);

    if ($user && password_verify($password, $user['password'])) {
        // Successful password verification — clear failed attempts
        try {
            clear_attempts($ip);
        } catch (\Throwable $e) {
            error_log('[TP Login] Clear attempts skipped: ' . $e->getMessage());
        }

        // Check if 2FA is enabled for this user
        $tfs = null;
        if (db_table_exists('two_factor_secrets')) {
            $twoFactorSql = 'SELECT * FROM two_factor_secrets WHERE user_id = ?';
            if (db_column_exists('two_factor_secrets', 'enabled')) {
                $twoFactorSql .= ' AND enabled = 1';
            }
            $twoFactorSql .= ' LIMIT 1';
            $tfs = db_fetch($twoFactorSql, [$user['id']]);
        }

        if ($tfs) {
            // Store pending user id, redirect to 2FA verify
            session_regenerate_id(true);
            $_SESSION['2fa_pending_user_id'] = $user['id'];
            redirect(APP_URL . '/modules/two_factor/verify.php');
        }

        // No 2FA — log in normally
        login_user($user);

        if (!empty($user['must_change_pw'])) {
            redirect(APP_URL . '/modules/settings/change_password.php');
        }

        redirect(APP_URL . '/dashboard.php');
    }

    // Failed login
    sleep(1);
    try {
        record_failed_attempt($ip, $email);
    } catch (\Throwable $e) {
        error_log('[TP Login] Failed attempt logging skipped: ' . $e->getMessage());
    }

    try {
        $recent_count = count_recent_attempts($ip);
    } catch (\Throwable $e) {
        $recent_count = 0;
    }
    if ($recent_count >= 5 && $recent_count < RATE_LIMIT_MAX_ATTEMPTS) {
        $remaining = RATE_LIMIT_MAX_ATTEMPTS - $recent_count;
        flash_error('Invalid credentials. Warning: ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining before lockout.');
    } else {
        flash_error('Invalid credentials. Please check your email and password.');
    }
    redirect(APP_URL . '/login.php');
}

// Pull any queued flash messages so they display on the login page
$flash_messages = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

$app_name   = defined('APP_NAME')   ? APP_NAME   : 'Trash Panda Roll-Offs';
$asset_path = defined('ASSET_PATH') ? ASSET_PATH : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?= e($app_name) ?></title>

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

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700&family=Barlow:wght@400;500&display=swap">

    <!-- App styles (cache-busted) -->
    <?php if ($asset_path): ?>
    <?php $css_ver = file_exists(ROOT_PATH . '/assets/css/app.css') ? filemtime(ROOT_PATH . '/assets/css/app.css') : APP_VERSION; ?>
    <link rel="stylesheet" href="<?= e($asset_path) ?>/css/app.css?v=<?= $css_ver ?>">
    <?php endif; ?>

    <!-- PWA -->
    <link rel="manifest" href="<?= e(APP_URL) ?>/manifest.json">
    <meta name="theme-color" content="#f97316">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="TP Admin">
    <?php if ($asset_path): ?>
    <link rel="apple-touch-icon" href="<?= e($asset_path) ?>/img/icon-192.png">
    <?php endif; ?>

    <style>
        body {
            background: #F7F7F7;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Barlow', sans-serif;
        }

        .login-card {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 14px;
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
        }

        .login-logo {
            font-family: 'Barlow Condensed', sans-serif;
            font-weight: 700;
            font-size: 1.6rem;
            color: #f97316;
            letter-spacing: .04em;
            text-align: center;
            margin-bottom: .25rem;
        }

        .login-sub {
            text-align: center;
            color: #6b7280;
            font-size: .85rem;
            margin-bottom: 1.75rem;
        }

        .tp-label {
            display: block;
            font-size: .8rem;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: .35rem;
        }

        .tp-input {
            width: 100%;
            background: #FFFFFF;
            border: 1px solid #D1D5DB;
            border-radius: 6px;
            color: #111827;
            padding: .55rem .75rem;
            font-size: .95rem;
            font-family: 'Barlow', sans-serif;
            transition: border-color .15s, box-shadow .15s;
            box-sizing: border-box;
        }

        .tp-input:focus {
            outline: none;
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249,115,22,.15);
        }

        .tp-input::placeholder {
            color: #9CA3AF;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .btn-login {
            width: 100%;
            background: #f97316;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: .65rem 1rem;
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Barlow Condensed', sans-serif;
            letter-spacing: .05em;
            cursor: pointer;
            transition: background .15s, transform .1s, box-shadow .15s;
            margin-top: .5rem;
        }

        .btn-login:hover {
            background: #ea6c0e;
            box-shadow: 0 4px 16px rgba(249,115,22,.3);
        }

        .btn-login:active {
            transform: scale(.98);
        }

        .alert-danger-dark {
            background: rgba(239,68,68,.08);
            border: 1px solid rgba(239,68,68,.25);
            color: #991b1b;
            border-radius: 6px;
            padding: .65rem .85rem;
            font-size: .875rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .input-icon-wrap {
            position: relative;
        }

        .input-icon-wrap .tp-input {
            padding-left: 2.4rem;
        }

        .input-icon {
            position: absolute;
            left: .75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9CA3AF;
            font-size: .9rem;
            pointer-events: none;
        }
    </style>
</head>
<body>

<div class="login-card">

    <!-- Brand -->
    <div class="login-logo">
        <i class="fa-solid fa-dumpster" style="color:#f97316;"></i>
        <?= e($app_name) ?>
    </div>
    <div class="login-sub">Admin Portal — Sign in to continue</div>

    <!-- Flash messages -->
    <?php foreach ($flash_messages as $flash): ?>
        <?php if ($flash['type'] === 'error'): ?>
        <div class="alert-danger-dark">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= e($flash['msg']) ?>
        </div>
        <?php elseif ($flash['type'] === 'success'): ?>
        <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);color:#065f46;border-radius:6px;padding:.65rem .85rem;font-size:.875rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;">
            <i class="fa-solid fa-circle-check"></i>
            <?= e($flash['msg']) ?>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- Login form -->
    <form method="POST" action="<?= e(APP_URL) ?>/login.php" autocomplete="on" novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label class="tp-label" for="email">Email Address</label>
            <div class="input-icon-wrap">
                <i class="fa-solid fa-envelope input-icon"></i>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="tp-input"
                    placeholder="you@example.com"
                    value="<?= e($_POST['email'] ?? '') ?>"
                    autocomplete="email"
                    required
                    autofocus
                >
            </div>
        </div>

        <div class="form-group">
            <label class="tp-label" for="password">Password</label>
            <div class="input-icon-wrap">
                <i class="fa-solid fa-lock input-icon"></i>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="tp-input"
                    placeholder="••••••••"
                    autocomplete="current-password"
                    required
                >
            </div>
        </div>

        <button type="submit" class="btn-login">
            <i class="fa-solid fa-right-to-bracket"></i>
            Sign In
        </button>
    </form>

</div><!-- /.login-card -->

<!-- Bootstrap JS (for any future use) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>

<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= e(APP_URL) ?>/sw.js', {
        scope: '<?= e(APP_URL) ?>/'
    }).catch(function(){});
}
</script>

</body>
</html>
