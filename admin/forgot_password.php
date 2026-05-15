<?php
/**
 * Forgot Password — Trash Panda Roll-Offs
 *
 * Issues a one-time, time-limited reset link and emails it to the user.
 * Always shows the same success message regardless of whether the email
 * exists, so account enumeration is not possible.
 */

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/mailer.php';
session_init();

if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/dashboard.php');
}

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email = trim(strtolower($_POST['email'] ?? ''));

    // Always sleep to slow down enumeration / brute-force
    sleep(1);

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $user = db_fetch('SELECT id, email, name FROM users WHERE email = ? LIMIT 1', [$email]);

        if ($user) {
            // Expire any existing unused tokens for this email
            try {
                db_execute(
                    "DELETE FROM password_resets WHERE email = ? AND used_at IS NULL",
                    [$email]
                );
            } catch (\Throwable $e) { /* table may not exist yet */ }

            $token      = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            try {
                db_execute(
                    "INSERT INTO password_resets (email, token, expires_at, created_at)
                     VALUES (?, ?, ?, NOW())",
                    [$email, $token, $expires_at]
                );

                $reset_url = APP_URL . '/reset_password.php?token=' . urlencode($token);
                send_password_reset_email($user['email'], $user['name'] ?? 'User', $reset_url);
            } catch (\Throwable $e) {
                error_log('[TP ForgotPw] ' . $e->getMessage());
            }
        }
    }

    // Always show success — never reveal whether the email exists
    $sent = true;
}

$app_name   = defined('APP_NAME')   ? APP_NAME   : 'Trash Panda Roll-Offs';
$asset_path = defined('ASSET_PATH') ? ASSET_PATH : '';

$flash_messages = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | <?= e($app_name) ?></title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLzsA=="
          crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700&family=Barlow:wght@400;500&display=swap">
    <?php if ($asset_path): ?>
    <?php $css_ver = file_exists(ROOT_PATH . '/assets/css/app.css') ? filemtime(ROOT_PATH . '/assets/css/app.css') : APP_VERSION; ?>
    <link rel="stylesheet" href="<?= e($asset_path) ?>/css/app.css?v=<?= $css_ver ?>">
    <?php endif; ?>

    <style>
        body { background:#F7F7F7; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:'Barlow',sans-serif; }
        .login-card { background:#fff; border:1px solid #E5E7EB; border-radius:14px; padding:2.5rem 2rem; width:100%; max-width:420px; box-shadow:0 4px 24px rgba(0,0,0,.08); }
        .login-logo { font-family:'Barlow Condensed',sans-serif; font-weight:700; font-size:1.6rem; color:#f97316; letter-spacing:.04em; text-align:center; margin-bottom:.25rem; }
        .login-sub  { text-align:center; color:#6b7280; font-size:.85rem; margin-bottom:1.75rem; }
        .tp-label   { display:block; font-size:.8rem; font-weight:700; color:#111827; text-transform:uppercase; letter-spacing:.06em; margin-bottom:.35rem; }
        .tp-input   { width:100%; background:#fff; border:1px solid #D1D5DB; border-radius:6px; color:#111827; padding:.55rem .75rem; font-size:.95rem; font-family:'Barlow',sans-serif; transition:border-color .15s,box-shadow .15s; box-sizing:border-box; }
        .tp-input:focus { outline:none; border-color:#f97316; box-shadow:0 0 0 3px rgba(249,115,22,.15); }
        .tp-input::placeholder { color:#9CA3AF; }
        .form-group { margin-bottom:1.25rem; }
        .btn-login  { width:100%; background:#f97316; color:#fff; border:none; border-radius:6px; padding:.65rem 1rem; font-size:1rem; font-weight:600; font-family:'Barlow Condensed',sans-serif; letter-spacing:.05em; cursor:pointer; transition:background .15s,transform .1s,box-shadow .15s; margin-top:.5rem; }
        .btn-login:hover { background:#ea6c0e; box-shadow:0 4px 16px rgba(249,115,22,.3); }
        .btn-login:active { transform:scale(.98); }
        .input-icon-wrap { position:relative; }
        .input-icon-wrap .tp-input { padding-left:2.4rem; }
        .input-icon { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:#9CA3AF; font-size:.9rem; pointer-events:none; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-logo">
        <i class="fa-solid fa-dumpster" style="color:#f97316;"></i>
        <?= e($app_name) ?>
    </div>
    <div class="login-sub">Reset your password</div>

    <?php if ($sent): ?>
    <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);color:#065f46;border-radius:6px;padding:.85rem 1rem;font-size:.9rem;margin-bottom:1.5rem;text-align:center;">
        <i class="fa-solid fa-circle-check" style="margin-right:.4rem;"></i>
        If that email is registered, a reset link is on its way. Check your inbox (and spam folder).
    </div>
    <a href="<?= e(APP_URL) ?>/login.php"
       style="display:block;text-align:center;color:#f97316;font-size:.9rem;text-decoration:none;">
        <i class="fa-solid fa-arrow-left" style="margin-right:.3rem;"></i> Back to sign in
    </a>

    <?php else: ?>

    <?php foreach ($flash_messages as $flash): ?>
        <?php if ($flash['type'] === 'error'): ?>
        <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:#991b1b;border-radius:6px;padding:.65rem .85rem;font-size:.875rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;">
            <i class="fa-solid fa-circle-exclamation"></i> <?= e($flash['msg']) ?>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <p style="font-size:.9rem;color:#6b7280;margin-bottom:1.5rem;">
        Enter the email address for your admin account and we'll send you a link to reset your password.
    </p>

    <form method="POST" action="<?= e(APP_URL) ?>/forgot_password.php" novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label class="tp-label" for="email">Email Address</label>
            <div class="input-icon-wrap">
                <i class="fa-solid fa-envelope input-icon"></i>
                <input type="email" id="email" name="email" class="tp-input"
                       placeholder="you@example.com"
                       value="<?= e($_POST['email'] ?? '') ?>"
                       autocomplete="email" required autofocus>
            </div>
        </div>

        <button type="submit" class="btn-login">
            <i class="fa-solid fa-paper-plane"></i>
            Send Reset Link
        </button>
    </form>

    <div style="margin-top:1.25rem;text-align:center;">
        <a href="<?= e(APP_URL) ?>/login.php"
           style="color:#6b7280;font-size:.85rem;text-decoration:none;">
            <i class="fa-solid fa-arrow-left" style="margin-right:.3rem;"></i> Back to sign in
        </a>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>
</body>
</html>
