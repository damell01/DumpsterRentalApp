<?php
/**
 * Reset Password — Trash Panda Roll-Offs
 *
 * Validates a one-time token from forgot_password.php and allows the user
 * to set a new password.  Token expires after 1 hour and is single-use.
 */

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/auth.php';
session_init();

if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/dashboard.php');
}

$token = trim($_GET['token'] ?? '');
$error = '';
$done  = false;

// ── Validate token ────────────────────────────────────────────────────────────
$reset = null;
if ($token !== '') {
    try {
        $reset = db_fetch(
            "SELECT * FROM password_resets
             WHERE token = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1",
            [$token]
        );
    } catch (\Throwable $e) {
        error_log('[TP ResetPw] token lookup: ' . $e->getMessage());
    }
}

if ($token === '' || !$reset) {
    // Invalid or expired — show a gentle error, link back to forgot page
    $app_name   = defined('APP_NAME') ? APP_NAME : 'Trash Panda Roll-Offs';
    $asset_path = defined('ASSET_PATH') ? ASSET_PATH : '';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Link Expired | <?= e($app_name) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700&family=Barlow:wght@400;500&display=swap">
    <?php if ($asset_path): ?>
    <?php $css_ver = file_exists(ROOT_PATH . '/assets/css/app.css') ? filemtime(ROOT_PATH . '/assets/css/app.css') : APP_VERSION; ?>
    <link rel="stylesheet" href="<?= e($asset_path) ?>/css/app.css?v=<?= $css_ver ?>">
    <?php endif; ?>
    <style>
        body { background:#F7F7F7; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:'Barlow',sans-serif; }
        .card-wrap { background:#fff; border:1px solid #E5E7EB; border-radius:14px; padding:2.5rem 2rem; width:100%; max-width:420px; box-shadow:0 4px 24px rgba(0,0,0,.08); text-align:center; }
        .login-logo { font-family:'Barlow Condensed',sans-serif; font-weight:700; font-size:1.6rem; color:#f97316; letter-spacing:.04em; margin-bottom:.25rem; }
    </style>
</head>
<body>
<div class="card-wrap">
    <div class="login-logo"><i class="fa-solid fa-dumpster" style="color:#f97316;"></i> <?= e($app_name) ?></div>
    <div style="color:#6b7280;font-size:.85rem;margin-bottom:1.5rem;">Reset your password</div>
    <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:#991b1b;border-radius:6px;padding:.85rem 1rem;font-size:.9rem;margin-bottom:1.5rem;">
        <i class="fa-solid fa-triangle-exclamation" style="margin-right:.4rem;"></i>
        This password reset link is invalid or has expired. Reset links are valid for 1 hour.
    </div>
    <a href="<?= e(APP_URL) ?>/forgot_password.php"
       style="display:inline-block;background:#f97316;color:#fff;border-radius:6px;padding:.6rem 1.25rem;font-family:'Barlow Condensed',sans-serif;font-weight:600;font-size:1rem;text-decoration:none;letter-spacing:.05em;">
        <i class="fa-solid fa-paper-plane" style="margin-right:.3rem;"></i> Request a new link
    </a>
    <div style="margin-top:1rem;">
        <a href="<?= e(APP_URL) ?>/login.php" style="color:#6b7280;font-size:.85rem;text-decoration:none;">
            <i class="fa-solid fa-arrow-left" style="margin-right:.3rem;"></i> Back to sign in
        </a>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Re-validate token in case it expired between GET and POST
        $still_valid = db_fetch(
            "SELECT id, email FROM password_resets
             WHERE token = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1",
            [$token]
        );

        if (!$still_valid) {
            flash_error('Your reset link has expired. Please request a new one.');
            redirect(APP_URL . '/forgot_password.php');
        }

        $user = db_fetch('SELECT id FROM users WHERE email = ? LIMIT 1', [$still_valid['email']]);
        if ($user) {
            db_execute(
                'UPDATE users SET password = ? WHERE id = ?',
                [password_hash($password, PASSWORD_BCRYPT), $user['id']]
            );
        }

        // Mark token used
        db_execute(
            "UPDATE password_resets SET used_at = NOW() WHERE token = ?",
            [$token]
        );

        flash_success('Password updated successfully. You can now sign in.');
        redirect(APP_URL . '/login.php');
    }
}

$app_name   = defined('APP_NAME')   ? APP_NAME   : 'Trash Panda Roll-Offs';
$asset_path = defined('ASSET_PATH') ? ASSET_PATH : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | <?= e($app_name) ?></title>

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
        .btn-login:hover  { background:#ea6c0e; box-shadow:0 4px 16px rgba(249,115,22,.3); }
        .btn-login:active { transform:scale(.98); }
        .input-icon-wrap  { position:relative; }
        .input-icon-wrap .tp-input { padding-left:2.4rem; }
        .input-icon { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:#9CA3AF; font-size:.9rem; pointer-events:none; }
        .pw-strength { height:4px; border-radius:2px; margin-top:6px; background:#e5e7eb; overflow:hidden; }
        .pw-strength-bar { height:100%; width:0; border-radius:2px; transition:width .3s,background .3s; }
        .pw-hint { font-size:.75rem; color:#9ca3af; margin-top:4px; }
        .toggle-pw { position:absolute; right:.75rem; top:50%; transform:translateY(-50%); background:none; border:none; color:#9ca3af; cursor:pointer; padding:0; font-size:.9rem; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-logo">
        <i class="fa-solid fa-dumpster" style="color:#f97316;"></i>
        <?= e($app_name) ?>
    </div>
    <div class="login-sub">Choose a new password</div>

    <?php if ($error): ?>
    <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:#991b1b;border-radius:6px;padding:.65rem .85rem;font-size:.875rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;">
        <i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST"
          action="<?= e(APP_URL) ?>/reset_password.php?token=<?= urlencode($token) ?>"
          novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label class="tp-label" for="password">New Password</label>
            <div class="input-icon-wrap" style="position:relative;">
                <i class="fa-solid fa-lock input-icon"></i>
                <input type="password" id="password" name="password" class="tp-input"
                       placeholder="Min. 8 characters"
                       autocomplete="new-password"
                       oninput="checkStrength(this.value)"
                       required style="padding-right:2.5rem;">
                <button type="button" class="toggle-pw" onclick="togglePw('password',this)"
                        tabindex="-1" aria-label="Show/hide password">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </div>
            <div class="pw-strength"><div class="pw-strength-bar" id="pwBar"></div></div>
            <div class="pw-hint" id="pwHint">Use 8+ characters for a stronger password.</div>
        </div>

        <div class="form-group">
            <label class="tp-label" for="password_confirm">Confirm Password</label>
            <div class="input-icon-wrap" style="position:relative;">
                <i class="fa-solid fa-lock input-icon"></i>
                <input type="password" id="password_confirm" name="password_confirm" class="tp-input"
                       placeholder="Repeat password"
                       autocomplete="new-password"
                       required style="padding-right:2.5rem;">
                <button type="button" class="toggle-pw" onclick="togglePw('password_confirm',this)"
                        tabindex="-1" aria-label="Show/hide password">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-login">
            <i class="fa-solid fa-key"></i>
            Set New Password
        </button>
    </form>

    <div style="margin-top:1.25rem;text-align:center;">
        <a href="<?= e(APP_URL) ?>/login.php"
           style="color:#6b7280;font-size:.85rem;text-decoration:none;">
            <i class="fa-solid fa-arrow-left" style="margin-right:.3rem;"></i> Back to sign in
        </a>
    </div>
</div>

<script>
function togglePw(fieldId, btn) {
    var f = document.getElementById(fieldId);
    var icon = btn.querySelector('i');
    if (f.type === 'password') {
        f.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        f.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

function checkStrength(val) {
    var bar  = document.getElementById('pwBar');
    var hint = document.getElementById('pwHint');
    var score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    var colors  = ['#ef4444','#f97316','#eab308','#22c55e','#16a34a'];
    var labels  = ['Too short','Weak','Fair','Good','Strong'];
    var widths  = ['20%','40%','60%','80%','100%'];
    var idx = Math.max(0, Math.min(score - 1, 4));

    if (val.length === 0) {
        bar.style.width = '0';
        hint.textContent = 'Use 8+ characters for a stronger password.';
        hint.style.color = '#9ca3af';
    } else {
        bar.style.width     = widths[idx];
        bar.style.background = colors[idx];
        hint.textContent    = labels[idx];
        hint.style.color    = colors[idx];
    }
}
</script>
</body>
</html>
