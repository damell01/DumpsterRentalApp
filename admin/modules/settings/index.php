<?php
/**
 * Settings – Company & System Settings
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office');

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = trim($_POST['action'] ?? 'save_company');

    // ── Test email ───────────────────────────────────────────────────────────
    if ($action === 'test_email') {
        require_once INC_PATH . '/mailer.php';
        $to = get_setting('company_email', '');
        if (empty($to)) {
            flash_error('No company email set. Please save settings first.');
        } else {
            $html = email_template(
                'Test Email',
                '<p>This is a test email from your Trash Panda Roll-Offs Manager.</p><p>If you received this, email sending is working correctly.</p>'
            );
            $result = send_email($to, 'Test Email from ' . get_setting('company_name', 'Trash Panda Roll-Offs'), $html);
            if ($result) {
                flash_success('Test email sent to ' . $to . '.');
            } else {
                flash_error('Failed to send test email. Check your PHP mail() configuration.');
            }
        }
        redirect('index.php');
    }

    // ── Logo upload ──────────────────────────────────────────────────────────
    if ($action === 'upload_logo') {
        $upload_dir = ROOT_PATH . '/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
            // Protect uploads directory
            file_put_contents($upload_dir . '.htaccess',
                "Options -Indexes\n<FilesMatch \"^(?!.*\.(png|jpg|jpeg|gif|webp)$).*$\">\n  Require all denied\n</FilesMatch>\n"
            );
        }
        if (!empty($_FILES['logo_file']['tmp_name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            // SVG excluded — can contain embedded JS (XSS risk)
            $allowed_types = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['logo_file']['tmp_name']);
            if (!in_array($mime, $allowed_types, true)) {
                flash_error('Invalid file type. Please upload a PNG, JPG, GIF, or WebP image.');
            } else {
                $ext_map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                $ext      = $ext_map[$mime] ?? 'png';
                $filename = 'logo_' . uniqid('', true) . '.' . $ext;
                $dest     = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $dest)) {
                    // Store as a URL path relative to APP_URL
                    set_setting('logo_url', APP_URL . '/uploads/' . $filename);
                    // Clear manual path if we now have an uploaded logo
                    set_setting('logo_path', '');
                    log_activity('update', 'Uploaded company logo: ' . $filename, 'settings', 0);
                    flash_success('Logo uploaded successfully.');
                } else {
                    flash_error('Failed to save logo file. Check directory permissions.');
                }
            }
        } else {
            flash_error('No file selected or upload error.');
        }
        redirect('index.php');
    }

    // ── Save logo path only ──────────────────────────────────────────────────
    if ($action === 'save_logo_path') {
        $path = trim($_POST['logo_path'] ?? '');
        set_setting('logo_path', $path);
        if ($path !== '') {
            // When a manual path is set, clear the uploaded logo URL preference
            set_setting('logo_url', '');
        }
        log_activity('update', 'Updated logo path setting', 'settings', 0);
        flash_success('Logo path saved.');
        redirect('index.php');
    }

    // ── Section-specific saves (prevent cross-section field clobbering) ──────
    $section_fields = [
        'save_company' => [
            'company_name', 'company_phone', 'company_email', 'company_address',
            'quote_terms', 'wo_footer', 'invoice_footer', 'booking_terms', 'currency',
        ],
        'save_email' => [
            'email_from_name', 'email_from_email', 'notification_emails',
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption',
        ],
        'save_stripe' => [
            'stripe_mode', 'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret',
            'portal_signing_key', 'portal_link_ttl_minutes', 'stripe_statement_descriptor',
            'billing_retry_days', 'ach_enabled', 'subscription_enabled',
        ],
    ];

    // Fall back to saving all fields if unknown action
    $fields = $section_fields[$action] ?? array_merge(...array_values($section_fields));

    $sensitive_blanks = ['smtp_password', 'stripe_secret_key', 'stripe_webhook_secret', 'portal_signing_key'];

    foreach ($fields as $key) {
        $raw = trim($_POST[$key] ?? '');
        // Never overwrite existing sensitive fields with blank
        if (in_array($key, $sensitive_blanks, true) && $raw === '') {
            if (!empty(get_setting($key))) {
                continue;
            }
        }
        set_setting($key, $raw);
    }

    log_activity('update', 'Updated system settings (' . $action . ')', 'settings', 0);
    flash_success('Settings saved successfully.');
    redirect('index.php');
}

layout_start('Settings', 'settings');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">System Settings</h5>
    <a href="<?= e(APP_URL) ?>/modules/help/index.php" class="btn-tp-ghost btn-tp-sm">
        <i class="fa-solid fa-circle-question"></i> Help &amp; Guide
    </a>
</div>

<!-- ── Company Information ──────────────────────────────────────────────── -->
<div class="tp-card" style="max-width:780px;">
    <form method="POST" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_company">

        <h6 class="mb-3" style="font-weight:600;border-bottom:1px solid #e5e7eb;padding-bottom:.5rem;">
            <i class="fa-solid fa-building" style="color:#f97316;"></i> Company Information
        </h6>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label" for="company_name">Company Name</label>
                <input type="text" id="company_name" name="company_name" class="form-control"
                       value="<?= e(get_setting('company_name', 'Trash Panda Roll-Offs')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="company_phone">Company Phone</label>
                <input type="text" id="company_phone" name="company_phone" class="form-control"
                       value="<?= e(get_setting('company_phone')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="company_email">Company Email</label>
                <input type="email" id="company_email" name="company_email" class="form-control"
                       value="<?= e(get_setting('company_email')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="company_address">Company Address</label>
                <input type="text" id="company_address" name="company_address" class="form-control"
                       value="<?= e(get_setting('company_address')) ?>">
            </div>
        </div>

        <h6 class="mb-3" style="font-weight:600;border-bottom:1px solid #e5e7eb;padding-bottom:.5rem;">
            <i class="fa-solid fa-file-lines" style="color:#f97316;"></i> Documents &amp; Templates
        </h6>

        <div class="row g-3 mb-4">
            <div class="col-12">
                <label class="form-label" for="quote_terms">Invoice / Work Order Terms &amp; Conditions</label>
                <textarea id="quote_terms" name="quote_terms" class="form-control" rows="3"
                          placeholder="Terms and conditions to display on invoices and work orders…"><?= e(get_setting('quote_terms')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="wo_footer">Work Order Footer Text</label>
                <textarea id="wo_footer" name="wo_footer" class="form-control" rows="2"
                          placeholder="Footer text to appear on printed work orders (e.g. contact info, thank you message)…"><?= e(get_setting('wo_footer')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="invoice_footer">Invoice Footer Text</label>
                <textarea id="invoice_footer" name="invoice_footer" class="form-control" rows="2"
                          placeholder="Footer text to appear on printed invoices (e.g. payment instructions, thank you message)…"><?= e(get_setting('invoice_footer')) ?></textarea>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn-tp-primary">
                <i class="fa-solid fa-floppy-disk"></i> Save Company Settings
            </button>
        </div>

    </form>
</div>

<!-- ── Branding / Logo ──────────────────────────────────────────────────── -->
<div class="tp-card mt-4" style="max-width:780px;">
    <h6 class="mb-3" style="font-weight:600;border-bottom:1px solid #e5e7eb;padding-bottom:.5rem;">
        <i class="fa-solid fa-image" style="color:#f97316;"></i> Branding &amp; Logo
    </h6>

    <?php
    $current_logo = get_setting('logo_url', '') ?: get_setting('logo_path', '');
    if ($current_logo): ?>
    <div class="mb-3">
        <div style="font-size:.8rem;color:var(--gy);margin-bottom:.4rem;">Current Logo</div>
        <img src="<?= e($current_logo) ?>" alt="Current logo"
             style="max-height:80px;max-width:300px;border:1px solid var(--st2);border-radius:6px;padding:4px;background:#fff;"
             onerror="this.style.display='none'">
    </div>
    <?php endif; ?>

    <!-- Upload logo file -->
    <form method="POST" action="index.php" enctype="multipart/form-data" class="mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_logo">
        <label class="form-label" for="logo_file">Upload Logo Image</label>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <input type="file" id="logo_file" name="logo_file" class="form-control"
                   accept="image/png,image/jpeg,image/gif,image/webp"
                   style="max-width:360px;">
            <button type="submit" class="btn-tp-primary btn-tp-sm">
                <i class="fa-solid fa-upload"></i> Upload Logo
            </button>
        </div>
        <div class="form-text" style="color:var(--gy);">
            Accepted formats: PNG, JPG, GIF, WebP. Recommended size: 300×80 px or similar landscape shape.
        </div>
    </form>

    <!-- Or enter a URL/path manually — uses save_logo_path action to avoid touching other fields -->
    <form method="POST" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_logo_path">
        <label class="form-label" for="logo_path">
            Or enter a Logo URL / Path manually
            <span style="font-weight:400;font-size:.8rem;color:var(--gy);">(overrides uploaded logo)</span>
        </label>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <input type="text" id="logo_path" name="logo_path" class="form-control"
                   value="<?= e(get_setting('logo_path')) ?>"
                   placeholder="https://yourdomain.com/logo.png"
                   style="max-width:400px;">
            <button type="submit" class="btn-tp-ghost btn-tp-sm">
                <i class="fa-solid fa-floppy-disk"></i> Save Path
            </button>
        </div>
    </form>
</div>

<!-- ── Email Configuration ──────────────────────────────────────────────── -->
<div class="tp-card mt-4" style="max-width:780px;">
    <h6 class="mb-3" style="font-weight:600;border-bottom:1px solid #e5e7eb;padding-bottom:.5rem;">
        <i class="fa-solid fa-envelope" style="color:#f97316;"></i> Email Configuration
    </h6>

    <form method="POST" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_email">

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label" for="email_from_name">From Name</label>
                <input type="text" id="email_from_name" name="email_from_name" class="form-control"
                       value="<?= e(get_setting('email_from_name', get_setting('company_name', 'Trash Panda Roll-Offs'))) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="email_from_email">From Email</label>
                <input type="email" id="email_from_email" name="email_from_email" class="form-control"
                       value="<?= e(get_setting('email_from_email', get_setting('company_email', ''))) ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="notification_emails">
                    Notification Email(s)
                    <span style="font-weight:400;color:var(--gy);font-size:.8rem;"> — receive alerts for contact form submissions</span>
                </label>
                <input type="text" id="notification_emails" name="notification_emails" class="form-control"
                       placeholder="admin@example.com, manager@example.com"
                       value="<?= e(get_setting('notification_emails', get_setting('company_email', ''))) ?>">
                <div class="form-text" style="color:var(--gy);">Comma-separated. These addresses get an email whenever the public contact form is submitted.</div>
            </div>
        </div>

        <h6 class="mb-3 mt-2" style="font-weight:600;border-bottom:1px solid var(--st);padding-bottom:.5rem;">
            <i class="fa-solid fa-server" style="color:var(--or);"></i> SMTP Settings
            <span style="font-weight:400;font-size:.8rem;color:var(--gy);"> — leave Host blank to use PHP mail()</span>
        </h6>

        <div class="row g-3 mb-4">
            <div class="col-md-8">
                <label class="form-label" for="smtp_host">SMTP Host</label>
                <input type="text" id="smtp_host" name="smtp_host" class="form-control"
                       placeholder="smtp.mailgun.org / smtp.sendgrid.net / smtp.gmail.com"
                       value="<?= e(get_setting('smtp_host', '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="smtp_port">SMTP Port</label>
                <input type="number" id="smtp_port" name="smtp_port" class="form-control"
                       placeholder="587"
                       value="<?= e(get_setting('smtp_port', '587')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="smtp_username">SMTP Username</label>
                <input type="text" id="smtp_username" name="smtp_username" class="form-control"
                       value="<?= e(get_setting('smtp_username', '')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="smtp_password">SMTP Password</label>
                <input type="password" id="smtp_password" name="smtp_password" class="form-control"
                       placeholder="<?= get_setting('smtp_password') ? '••••••••' : 'Enter password' ?>"
                       value="">
                <div class="form-text" style="color:var(--gy);">Leave blank to keep existing password.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="smtp_encryption">Encryption</label>
                <select id="smtp_encryption" name="smtp_encryption" class="form-select">
                    <?php
                    $enc_cur = get_setting('smtp_encryption', 'tls');
                    foreach (['tls' => 'TLS (STARTTLS — port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None (not recommended)'] as $val => $lbl): ?>
                    <option value="<?= e($val) ?>" <?= $enc_cur === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <div class="alert alert-info mb-0" style="font-size:.85rem;">
                    <i class="fa-solid fa-circle-info me-2"></i>
                    <strong>PHPMailer:</strong> Install PHPMailer via Composer for reliable SMTP delivery.
                    Run <code>composer install</code> inside the <code>admin/</code> folder.
                    Without it, the system falls back to PHP <code>mail()</code>.
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn-tp-primary">
                <i class="fa-solid fa-floppy-disk"></i> Save Email Settings
            </button>
            <button type="submit" form="testEmailForm" class="btn-tp-ghost btn-tp-sm">
                <i class="fa-solid fa-paper-plane"></i> Send Test Email
            </button>
        </div>

    </form>

    <form id="testEmailForm" method="POST" action="index.php" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test_email">
    </form>
</div>

<!-- ── Stripe Configuration ──────────────────────────────────────────────── -->
<div class="tp-card mt-4" style="max-width:780px;">
    <h6 class="mb-3" style="font-weight:600;border-bottom:1px solid #e5e7eb;padding-bottom:.5rem;">
        <i class="fa-brands fa-stripe" style="color:#6772e5;"></i> Stripe &amp; Booking Configuration
    </h6>

    <form method="POST" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_stripe">

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label" for="stripe_mode">Stripe Mode</label>
                <select id="stripe_mode" name="stripe_mode" class="form-select">
                    <?php $mode = get_setting('stripe_mode', 'test'); ?>
                    <option value="test" <?= $mode === 'test' ? 'selected' : '' ?>>Test (Sandbox)</option>
                    <option value="live" <?= $mode === 'live' ? 'selected' : '' ?>>Live (Production)</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label" for="stripe_publishable_key">Publishable Key</label>
                <input type="text" id="stripe_publishable_key" name="stripe_publishable_key"
                       class="form-control" placeholder="pk_test_…"
                       value="<?= e(get_setting('stripe_publishable_key', '')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="stripe_secret_key">
                    Secret Key
                    <button type="button" class="btn btn-sm btn-link p-0 ms-1" onclick="toggleField('stripe_secret_key')" style="color:var(--gy);font-size:.8rem;" title="Show/Hide">
                        <i class="fa-solid fa-eye" id="stripe_secret_key-icon"></i>
                    </button>
                </label>
                <input type="password" id="stripe_secret_key" name="stripe_secret_key"
                       class="form-control"
                       placeholder="<?= get_setting('stripe_secret_key') ? '••••••••' : 'sk_test_…' ?>"
                       value="">
                <div class="form-text" style="color:var(--gy);">Leave blank to keep existing key.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="stripe_webhook_secret">
                    Webhook Secret
                    <button type="button" class="btn btn-sm btn-link p-0 ms-1" onclick="toggleField('stripe_webhook_secret')" style="color:var(--gy);font-size:.8rem;" title="Show/Hide">
                        <i class="fa-solid fa-eye" id="stripe_webhook_secret-icon"></i>
                    </button>
                </label>
                <input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret"
                       class="form-control"
                       placeholder="<?= get_setting('stripe_webhook_secret') ? '••••••••' : 'whsec_…' ?>"
                       value="">
                <div class="form-text" style="color:var(--gy);">
                    Webhook endpoint: <code><?= e(rtrim(preg_replace('#/admin$#', '', APP_URL), '/')) ?>/public/api/stripe-webhook.php</code>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="portal_signing_key">Portal Signing Key</label>
                <input type="password" id="portal_signing_key" name="portal_signing_key"
                       class="form-control"
                       placeholder="<?= get_setting('portal_signing_key') ? '••••••••' : 'long-random-secret' ?>"
                       value="">
                <div class="form-text" style="color:var(--gy);">Used to sign passwordless customer portal links. Leave blank to keep existing value.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="currency">Currency</label>
                <select id="currency" name="currency" class="form-select">
                    <?php
                    $cur = get_setting('currency', 'usd') ?: 'usd';
                    foreach (['usd' => 'USD ($)', 'cad' => 'CAD (CA$)', 'eur' => 'EUR (€)', 'gbp' => 'GBP (£)', 'aud' => 'AUD (A$)'] as $val => $lbl):
                    ?>
                    <option value="<?= e($val) ?>" <?= $cur === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="portal_link_ttl_minutes">Portal Link TTL (minutes)</label>
                <input type="number" id="portal_link_ttl_minutes" name="portal_link_ttl_minutes" class="form-control"
                       value="<?= e(get_setting('portal_link_ttl_minutes', '30')) ?>" min="5" step="5">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="stripe_statement_descriptor">Statement Descriptor</label>
                <input type="text" id="stripe_statement_descriptor" name="stripe_statement_descriptor" class="form-control"
                       value="<?= e(get_setting('stripe_statement_descriptor', '')) ?>" maxlength="22">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="billing_retry_days">Billing Retry Days (JSON)</label>
                <input type="text" id="billing_retry_days" name="billing_retry_days" class="form-control"
                       value="<?= e(get_setting('billing_retry_days', '[1,3,5]')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="ach_enabled">ACH Payments</label>
                <select id="ach_enabled" name="ach_enabled" class="form-select">
                    <option value="1" <?= get_setting('ach_enabled', '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                    <option value="0" <?= get_setting('ach_enabled', '1') === '0' ? 'selected' : '' ?>>Disabled</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="subscription_enabled">Subscriptions</label>
                <select id="subscription_enabled" name="subscription_enabled" class="form-select">
                    <option value="1" <?= get_setting('subscription_enabled', '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                    <option value="0" <?= get_setting('subscription_enabled', '1') === '0' ? 'selected' : '' ?>>Disabled</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="booking_terms">Online Booking Terms</label>
                <textarea id="booking_terms" name="booking_terms" class="form-control" rows="3"
                          placeholder="Terms shown to customers during online booking…"><?= e(get_setting('booking_terms', '')) ?></textarea>
            </div>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <button type="submit" class="btn-tp-primary">
                <i class="fa-solid fa-floppy-disk"></i> Save Stripe Settings
            </button>
            <a href="<?= e(APP_URL) ?>/modules/help/index.php#stripe" class="btn-tp-ghost btn-tp-sm">
                <i class="fa-solid fa-circle-question"></i> Stripe Setup Guide
            </a>
        </div>

    </form>
</div>

<!-- ── Database Maintenance ──────────────────────────────────────────────── -->
<div class="tp-card mt-4" style="max-width:780px;">
    <h6 class="mb-2" style="font-weight:600;">
        <i class="fa-solid fa-database" style="color:#f97316;"></i> Database Maintenance
    </h6>
    <p style="font-size:.9rem;color:var(--gy);">
        Apply any pending database schema upgrades. All steps are idempotent — it is
        safe to run more than once. Run this after pulling new code that adds tables
        or columns.
    </p>
    <button type="button" id="btnRunUpgrade" class="btn-tp-ghost btn-tp-sm" onclick="runUpgrade()">
        <i class="fa-solid fa-rotate" id="upgradeIcon"></i> Run Database Upgrade
    </button>
    <div id="upgradeOutput" style="display:none;margin-top:1rem;">
        <pre id="upgradeOutputText"
             style="background:#111827;color:#d1fae5;padding:1rem;border-radius:6px;
                    font-size:.78rem;line-height:1.5;max-height:420px;overflow-y:auto;
                    white-space:pre-wrap;word-break:break-word;border:1px solid #1f2937;"></pre>
    </div>
</div>

<script>
function toggleField(id) {
    var inp  = document.getElementById(id);
    var icon = document.getElementById(id + '-icon');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        inp.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

function runUpgrade() {
    var btn    = document.getElementById('btnRunUpgrade');
    var icon   = document.getElementById('upgradeIcon');
    var output = document.getElementById('upgradeOutput');
    var text   = document.getElementById('upgradeOutputText');

    btn.disabled = true;
    icon.classList.add('fa-spin');
    text.textContent = 'Running upgrade…';
    output.style.display = 'block';

    // Grab the CSRF token from any of the forms on this page.
    var csrfInput = document.querySelector('input[name="<?= CSRF_TOKEN_NAME ?>"]');
    if (!csrfInput) {
        text.textContent = 'Security token not found. Please refresh the page and try again.';
        text.style.color = '#fca5a5';
        btn.disabled = false;
        icon.classList.remove('fa-spin');
        return;
    }
    var csrfToken = csrfInput.value;

    var body = new URLSearchParams();
    body.append('<?= CSRF_TOKEN_NAME ?>', csrfToken);

    fetch('run_upgrade.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    body.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        icon.classList.remove('fa-spin');

        var out = data.output || '';
        if (data.errors && data.errors.length > 0) {
            out += '\n\nFailed steps:\n' + data.errors.join('\n');
        }
        text.textContent = out || 'No output returned.';

        if (data.success) {
            text.style.color = '#d1fae5'; // green
        } else {
            text.style.color = '#fca5a5'; // red
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        icon.classList.remove('fa-spin');
        text.textContent = 'Unable to run database upgrade. Please try again or contact support.';
        text.style.color = '#fca5a5';
        console.error('Upgrade request failed:', err);
    });
}
</script>

<?php
layout_end();
