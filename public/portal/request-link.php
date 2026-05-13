<?php

$_admin_root = dirname(__DIR__, 2) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/helpers.php';

$company_name = get_setting('company_name', 'Trash Panda Roll-Offs');
$prefill_email = trim((string)($_GET['email'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Portal - <?= e($company_name) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/shared.css">
</head>
<body style="background:#0f1117;color:#fff;">
<div class="container py-5" style="max-width:620px;">
    <div class="book-card">
        <h2>Billing Portal Link</h2>
        <p class="text-light-emphasis">Enter your billing email and we’ll send a secure magic link to manage invoices, payment methods, and subscriptions.</p>
        <div id="portal-msg" class="alert alert-info d-none"></div>
        <div class="mb-3">
            <label class="form-label">Email address</label>
            <input type="email" id="portal-email" class="form-control" placeholder="you@example.com" value="<?= e($prefill_email) ?>">
        </div>
        <button class="btn btn-warning" onclick="requestPortalLink()">Send secure link</button>
    </div>
</div>
<script>
function requestPortalLink() {
    var email = document.getElementById('portal-email').value.trim();
    var msg = document.getElementById('portal-msg');
    msg.className = 'alert alert-info';
    msg.classList.remove('d-none');
    msg.textContent = 'Sending...';
    fetch('/api/request-portal-link.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({email: email})
    }).then(function(r){ return r.json(); }).then(function(data){
        if (data.success) {
            msg.className = 'alert alert-success';
            msg.textContent = 'If that email address is on file, a secure portal link has been sent.';
        } else {
            msg.className = 'alert alert-danger';
            msg.textContent = data.error || 'Unable to send link.';
        }
    }).catch(function(){
        msg.className = 'alert alert-danger';
        msg.textContent = 'Network error. Please try again.';
    });
}
</script>
</body>
</html>
