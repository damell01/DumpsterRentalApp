<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment Method Saved | Trash Panda Roll-Offs</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    body { background:#0a0a0a; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:Arial,sans-serif; }
    .box { background:#111; border:1px solid #1e1e1e; border-radius:16px; padding:3rem 2.5rem; max-width:480px; width:100%; text-align:center; }
    .check-ring { width:72px; height:72px; border-radius:50%; background:rgba(34,197,94,.12); border:2px solid rgba(34,197,94,.3); display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem; }
    .check-ring i { color:#22c55e; font-size:1.8rem; }
    h1 { color:#f5f5f5; font-size:1.5rem; font-weight:700; margin-bottom:.5rem; }
    p { color:#9ca3af; font-size:.95rem; line-height:1.6; margin-bottom:0; }
    .company { color:#f97316; font-weight:700; }
    .divider { border-top:1px solid #1e1e1e; margin:1.5rem 0; }
    .footer-note { font-size:.8rem; color:#4b5563; }
    a { color:#f97316; text-decoration:none; }
  </style>
</head>
<body>
<?php
// Minimal PHP to get company name from config if available
$companyName = 'Trash Panda Roll-Offs';
$companyPhone = '';
$configFile = dirname(__DIR__) . '/admin/config/config.php';
if (file_exists($configFile)) {
    try {
        require_once $configFile;
        if (function_exists('get_setting')) {
            $companyName  = get_setting('company_name',  $companyName);
            $companyPhone = get_setting('company_phone', '');
        }
    } catch (\Throwable $e) {}
}
?>
<div class="box">
  <div class="check-ring">
    <i class="fa-solid fa-check"></i>
  </div>
  <h1>Payment Method Saved!</h1>
  <p>Your payment method has been securely saved to your account with <span class="company"><?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></span>. You're all set.</p>
  <div class="divider"></div>
  <?php if ($companyPhone): ?>
  <p>Questions? Call us at <a href="tel:<?= preg_replace('/\D/', '', $companyPhone) ?>"><?= htmlspecialchars($companyPhone, ENT_QUOTES, 'UTF-8') ?></a></p>
  <?php else: ?>
  <p>If you have any questions, contact us and we'll be happy to help.</p>
  <?php endif; ?>
  <div class="divider"></div>
  <p class="footer-note">Your payment details are stored securely by Stripe. <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?> never sees your full card or account number.</p>
</div>
</body>
</html>
