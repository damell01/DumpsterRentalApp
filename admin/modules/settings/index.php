<?php
/**
 * Settings – Company & System Settings
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office');

$settings_copy_visible = current_user_is_super_admin();
$settings_tab_names = ['company', 'branding', 'email', 'stripe', 'database'];
if ($settings_copy_visible) {
    array_splice($settings_tab_names, 1, 0, ['copy']);
}

function normalize_retry_days_input(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '[1,3,5]';
    }

    $decoded = json_decode($input, true);
    if (is_array($decoded)) {
        $days = [];
        foreach ($decoded as $value) {
            $day = max(1, (int)$value);
            if ($day > 0) {
                $days[] = $day;
            }
        }
        $days = array_values(array_unique($days));
        sort($days);
        return json_encode($days, JSON_UNESCAPED_SLASHES);
    }

    $parts = preg_split('/[^0-9]+/', $input) ?: [];
    $days = [];
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $day = max(1, (int)$part);
        if ($day > 0) {
            $days[] = $day;
        }
    }
    $days = array_values(array_unique($days));
    sort($days);

    return json_encode($days ?: [1, 3, 5], JSON_UNESCAPED_SLASHES);
}

function retry_days_display_value(): string
{
    $raw = get_setting('billing_retry_days', '[1,3,5]');
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return '1, 3, 5';
    }

    $days = [];
    foreach ($decoded as $value) {
        $day = (int)$value;
        if ($day > 0) {
            $days[] = $day;
        }
    }

    return $days ? implode(', ', array_values(array_unique($days))) : '1, 3, 5';
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = trim($_POST['action'] ?? 'save_company');

    if ($action === 'save_copy' && !$settings_copy_visible) {
        flash_error('You do not have permission to edit customer-facing copy settings.');
        redirect('index.php#settings-company');
    }

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
            'hide_launch_checklist',
        ],
        'save_copy' => [
            'payment_note_cash', 'payment_note_check',
            'booking_success_pending_intro', 'booking_success_confirmed_intro',
            'booking_success_terms_text', 'booking_success_keep_title',
            'booking_success_keep_body', 'booking_success_contact_prompt',
            'invoice_paid_intro_named', 'invoice_paid_intro_generic',
            'invoice_paid_contact_prompt', 'invoice_canceled_intro',
            'invoice_canceled_contact_prompt', 'portal_request_lead',
            'portal_request_sub', 'portal_request_success',
            'portal_request_security_note', 'portal_link_email_subject',
            'portal_link_email_intro', 'portal_link_email_button',
            'invoice_paid_email_subject', 'invoice_paid_email_body',
        ],
        'save_email' => [
            'email_from_name', 'email_from_email', 'notification_emails',
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption',
        ],
        'save_branding' => [
            'company_tagline', 'company_website', 'company_facebook', 'company_instagram',
        ],
        'save_stripe' => [
            'stripe_mode', 'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret',
            'portal_signing_key', 'portal_link_ttl_minutes', 'stripe_statement_descriptor',
            'billing_retry_days', 'booking_flow_mode', 'ach_enabled', 'subscription_enabled',
            'approval_auto_send_invoice', 'tax_rate',
        ],
    ];

    // ── Regenerate cron key ──────────────────────────────────────────────────
    if ($action === 'regenerate_cron_key') {
        $new_key = bin2hex(random_bytes(24));
        set_setting('cron_key', $new_key);
        log_activity('update', 'Regenerated cron key.', 'settings', 0);
        flash_success('Cron key regenerated.');
        redirect('index.php#settings-email');
    }

    // Fall back to saving all fields if unknown action
    $fields = $section_fields[$action] ?? array_merge(...array_values($section_fields));

    $sensitive_blanks = ['smtp_password', 'stripe_secret_key', 'stripe_webhook_secret', 'portal_signing_key'];

    foreach ($fields as $key) {
        $raw = trim($_POST[$key] ?? '');
        if ($key === 'billing_retry_days') {
            $raw = normalize_retry_days_input($raw);
        }
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

<style>
.settings-shell { max-width: 980px; }
.settings-intro {
    background: rgba(249,115,22,.06);
    border: 1px solid rgba(249,115,22,.25);
    border-radius: 14px;
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
}
.settings-intro p { margin: 0; color: var(--gy,#6b7280); }
.settings-checklist {
    margin-top: .9rem;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: .75rem;
}
.settings-checkitem {
    display: block;
    background: var(--dk1,#fff);
    border: 1px solid var(--st,#e5e7eb);
    border-radius: 12px;
    padding: .85rem .95rem;
    text-decoration: none;
    transition: transform .15s ease, border-color .15s ease, background .15s ease;
}
.settings-checkitem:hover,
.settings-checkitem:focus {
    transform: translateY(-1px);
    border-color: rgba(249,115,22,.5);
    background: var(--dk2,#f3f4f6);
    text-decoration: none;
}
.settings-checkitem:focus-visible {
    outline: 2px solid rgba(249,115,22,.55);
    outline-offset: 2px;
}
.settings-checkitem strong {
    display: block;
    color: #111827;
    font-size: .9rem;
    margin-bottom: .3rem;
}
.settings-checkitem span {
    color: var(--gy,#6b7280);
    font-size: .83rem;
    line-height: 1.45;
}
.settings-tabs {
    display: flex;
    align-items: center;
    gap: .5rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}
.settings-tabs .settings-tab {
    min-height: 42px;
    padding-inline: 1rem;
    border-radius: 12px;
}
.settings-tabs .settings-tab.active {
    box-shadow: 0 8px 18px rgba(249,115,22,.12);
}
.settings-panel {
    display: none;
    border-radius: 18px;
    border: 1px solid var(--st,#e5e7eb);
    box-shadow: 0 10px 28px rgba(15,23,42,.06);
    overflow: hidden;
}
.settings-panel.active { display: block; }
.settings-panel > form,
.settings-panel > div,
.settings-panel > .row {
    padding: 1.15rem 1.2rem;
}
.settings-panel h6 {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: .9rem !important;
}
.settings-panel .row.g-3 {
    --bs-gutter-x: 1rem;
    --bs-gutter-y: .95rem;
}
.settings-panel .d-flex.gap-2 {
    margin-top: .5rem;
}
.settings-copy-group {
    border: 1px solid var(--st,#e5e7eb);
    border-radius: 14px;
    padding: 1rem 1.05rem;
    background: linear-gradient(180deg, rgba(249,115,22,.03), rgba(255,255,255,0));
    margin-bottom: 1rem;
}
.settings-copy-group h6 {
    margin: 0 0 .35rem;
    font-weight: 700;
}
.settings-copy-group p {
    margin: 0 0 .9rem;
    color: var(--gy,#6b7280);
    font-size: .85rem;
}
.settings-copy-editor textarea {
    min-height: 84px;
    resize: vertical;
}
.settings-copy-editor textarea.rows-lg {
    min-height: 120px;
}
.settings-token-note {
    font-size: .8rem;
    color: var(--gy,#6b7280);
}
@media (max-width: 767px) {
    .settings-panel > form,
    .settings-panel > div,
    .settings-panel > .row {
        padding: 1rem;
    }
    .settings-tabs .settings-tab {
        flex: 1 1 calc(50% - .5rem);
        justify-content: center;
    }
}
</style>

<div class="settings-shell">
    <div class="settings-intro">
        <p>Move through setup in order: company details, branding, email delivery, Stripe and booking controls, then database maintenance.</p>
        <div class="settings-checklist">
            <a href="#settings-company" class="settings-checkitem">
                <strong>1. Company Basics</strong>
                <span>Set the company name, phone, email, address, invoice terms, and footer text.</span>
            </a>
            <a href="#settings-branding" class="settings-checkitem">
                <strong>2. Branding</strong>
                <span>Upload the real logo so public pages, invoices, and office screens match the client brand.</span>
            </a>
            <a href="#settings-email" class="settings-checkitem">
                <strong>3. Email Delivery</strong>
                <span>Configure SMTP or confirm mail sending, then use the test email button before launch.</span>
            </a>
            <a href="#settings-stripe" class="settings-checkitem">
                <strong>4. Stripe in Test Mode</strong>
                <span>Enter test keys first, confirm booking and invoice payments work, then switch to live later.</span>
            </a>
            <?php if ($settings_copy_visible): ?>
            <a href="#settings-copy" class="settings-checkitem">
                <strong>5. Customer Copy</strong>
                <span>Adjust booking, billing, portal, and payment wording without touching code.</span>
            </a>
            <?php endif; ?>
            <a href="#settings-stripe" class="settings-checkitem">
                <strong>6. Portal & Booking Rules</strong>
                <span>Review booking flow mode, portal link TTL, ACH, subscriptions, and customer-facing booking terms.</span>
            </a>
            <a href="#settings-database" class="settings-checkitem">
                <strong>7. Final Launch Pass</strong>
                <span>Run the database upgrade if needed, verify live inventory on the public site, and complete one full end-to-end test.</span>
            </a>
        </div>
    </div>

    <div class="settings-tabs" role="tablist" aria-label="Settings Sections">
        <a href="#settings-company" class="filter-tab settings-tab active" data-settings-tab="company" role="tab" aria-selected="true"><i class="fa-solid fa-building"></i> Company</a>
        <?php if ($settings_copy_visible): ?>
        <a href="#settings-copy" class="filter-tab settings-tab" data-settings-tab="copy" role="tab" aria-selected="false"><i class="fa-solid fa-language"></i> Copy</a>
        <?php endif; ?>
        <a href="#settings-branding" class="filter-tab settings-tab" data-settings-tab="branding" role="tab" aria-selected="false"><i class="fa-solid fa-image"></i> Branding</a>
        <a href="#settings-email" class="filter-tab settings-tab" data-settings-tab="email" role="tab" aria-selected="false"><i class="fa-solid fa-envelope"></i> Email</a>
        <a href="#settings-stripe" class="filter-tab settings-tab" data-settings-tab="stripe" role="tab" aria-selected="false"><i class="fa-brands fa-stripe"></i> Stripe</a>
        <a href="#settings-database" class="filter-tab settings-tab" data-settings-tab="database" role="tab" aria-selected="false"><i class="fa-solid fa-database"></i> Database</a>
    </div>

<!-- ── Company Information ──────────────────────────────────────────────── -->
<div id="settings-company" class="tp-card settings-panel active" style="max-width:780px;">
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

        <div class="col-12 mb-4">
            <label class="form-label" for="review_url">
                <i class="fa-solid fa-star" style="color:#f97316;"></i> Google Review Link
            </label>
            <input type="url" id="review_url" name="review_url" class="form-control"
                   placeholder="https://g.page/r/XXXXXX/review"
                   value="<?= e(get_setting('review_url', '')) ?>">
            <div class="form-text" style="color:var(--gy);">
                Customers are automatically emailed this link after their job is completed. Leave blank to disable review requests.
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
            <div class="col-12">
                <label class="form-label" for="booking_terms">Online Booking Terms</label>
                <textarea id="booking_terms" name="booking_terms" class="form-control" rows="4"
                          placeholder="Terms &amp; conditions shown to customers on the public booking page…"><?= e(get_setting('booking_terms', '')) ?></textarea>
                <div class="form-text" style="color:var(--gy);">Displayed to customers on the booking page. They must scroll and agree before submitting.</div>
            </div>
            <div class="col-12">
                <label class="form-label" for="payment_note_cash">Cash Payment Note</label>
                <textarea id="payment_note_cash" name="payment_note_cash" class="form-control" rows="2"
                          placeholder="Shown to customers on the booking success page when they choose cash…"><?= e(get_setting('payment_note_cash', 'Please have cash payment ready at time of delivery.')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="payment_note_check">Check Payment Note</label>
                <textarea id="payment_note_check" name="payment_note_check" class="form-control" rows="2"
                          placeholder="Shown to customers on the booking success page when they choose check…"><?= e(get_setting('payment_note_check', 'Please have your check made out to {company_name} ready at time of delivery.')) ?></textarea>
                <div class="form-text" style="color:var(--gy);">You can use <code>{company_name}</code> and it will be replaced automatically.</div>
            </div>
        </div>

        <h6 class="mb-3" style="font-weight:600;border-bottom:1px solid #e5e7eb;padding-bottom:.5rem;">
            <i class="fa-solid fa-language" style="color:#f97316;"></i> Customer-Facing Copy
        </h6>

        <div class="row g-3 mb-4">
            <div class="col-12">
                <label class="form-label" for="booking_success_pending_intro">Booking Success: Pending Review Intro</label>
                <textarea id="booking_success_pending_intro" name="booking_success_pending_intro" class="form-control" rows="3"
                          placeholder="Shown when the booking is waiting for approval…"><?= e(get_setting('booking_success_pending_intro', 'Thank you, {customer_name}. {subject_phrase} been submitted for review. We will follow up with approval details and payment instructions if needed.')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="booking_success_confirmed_intro">Booking Success: Confirmed Intro</label>
                <textarea id="booking_success_confirmed_intro" name="booking_success_confirmed_intro" class="form-control" rows="3"
                          placeholder="Shown after the booking is confirmed…"><?= e(get_setting('booking_success_confirmed_intro', 'Thank you, {customer_name}! {subject_phrase} been booked.')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="booking_success_terms_text">Booking Success: Terms Card Text</label>
                <textarea id="booking_success_terms_text" name="booking_success_terms_text" class="form-control" rows="2"
                          placeholder="Shown above the terms PDF button…"><?= e(get_setting('booking_success_terms_text', 'Keep a copy of the signed rental terms for your records.')) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="booking_success_keep_title">Booking Success: Reminder Title</label>
                <input type="text" id="booking_success_keep_title" name="booking_success_keep_title" class="form-control"
                       value="<?= e(get_setting('booking_success_keep_title', 'Keep this page handy.')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="booking_success_contact_prompt">Booking Success: Contact Prompt</label>
                <input type="text" id="booking_success_contact_prompt" name="booking_success_contact_prompt" class="form-control"
                       value="<?= e(get_setting('booking_success_contact_prompt', 'Questions? Call us at')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="booking_success_keep_body">Booking Success: Reminder Body</label>
                <textarea id="booking_success_keep_body" name="booking_success_keep_body" class="form-control" rows="2"
                          placeholder="Shown below the booking details…"><?= e(get_setting('booking_success_keep_body', 'Use these booking numbers when you call, email, or check your order in the customer portal.')) ?></textarea>
                <div class="form-text" style="color:var(--gy);">Supported placeholders: <code>{company_name}</code>, <code>{customer_name}</code>, <code>{subject_phrase}</code>.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="invoice_paid_intro_named">Invoice Paid: Thank-you Message</label>
                <textarea id="invoice_paid_intro_named" name="invoice_paid_intro_named" class="form-control" rows="2"
                          placeholder="Shown when the customer name is known…"><?= e(get_setting('invoice_paid_intro_named', 'Thank you, {customer_name}! Your invoice has been paid.')) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="invoice_paid_intro_generic">Invoice Paid: Generic Message</label>
                <textarea id="invoice_paid_intro_generic" name="invoice_paid_intro_generic" class="form-control" rows="2"
                          placeholder="Shown when no customer name is available…"><?= e(get_setting('invoice_paid_intro_generic', 'Your invoice payment has been received. Thank you!')) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="invoice_paid_contact_prompt">Invoice Paid: Contact Prompt</label>
                <input type="text" id="invoice_paid_contact_prompt" name="invoice_paid_contact_prompt" class="form-control"
                       value="<?= e(get_setting('invoice_paid_contact_prompt', 'Questions? Call us at')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="invoice_canceled_contact_prompt">Payment Not Completed: Contact Prompt</label>
                <input type="text" id="invoice_canceled_contact_prompt" name="invoice_canceled_contact_prompt" class="form-control"
                       value="<?= e(get_setting('invoice_canceled_contact_prompt', 'Need help? Call us at')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="invoice_canceled_intro">Payment Not Completed: Message</label>
                <textarea id="invoice_canceled_intro" name="invoice_canceled_intro" class="form-control" rows="2"
                          placeholder="Shown when checkout is canceled or abandoned…"><?= e(get_setting('invoice_canceled_intro', 'Your payment was not completed and the invoice has not been charged. No amount has been collected.')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="portal_request_lead">Portal Request: Hero Text</label>
                <textarea id="portal_request_lead" name="portal_request_lead" class="form-control" rows="2"
                          placeholder="Shown on the billing portal request page…"><?= e(get_setting('portal_request_lead', 'Get a secure one-time link to review invoices, payment history, saved billing methods, and subscription activity without needing a password.')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="portal_request_sub">Portal Request: Form Intro</label>
                <textarea id="portal_request_sub" name="portal_request_sub" class="form-control" rows="2"
                          placeholder="Shown above the email field…"><?= e(get_setting('portal_request_sub', 'Enter your billing email and we’ll send a secure one-time link to access your invoices, subscriptions, and saved payment methods.')) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="portal_request_success">Portal Request: Success Message</label>
                <textarea id="portal_request_success" name="portal_request_success" class="form-control" rows="2"
                          placeholder="Shown after a portal link request is submitted…"><?= e(get_setting('portal_request_success', 'If that email address is on file, a secure portal link has been sent. Check your inbox.')) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="portal_request_security_note">Portal Request: Security Note</label>
                <textarea id="portal_request_security_note" name="portal_request_security_note" class="form-control" rows="2"
                          placeholder="Shown under the send button…"><?= e(get_setting('portal_request_security_note', 'Links are single-use and expire automatically. No password needed.')) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="portal_link_email_subject">Portal Link Email Subject</label>
                <input type="text" id="portal_link_email_subject" name="portal_link_email_subject" class="form-control"
                       value="<?= e(get_setting('portal_link_email_subject', 'Your {company_name} Billing Portal Link')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="portal_link_email_button">Portal Link Email Button Label</label>
                <input type="text" id="portal_link_email_button" name="portal_link_email_button" class="form-control"
                       value="<?= e(get_setting('portal_link_email_button', 'Open Billing Portal')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="portal_link_email_intro">Portal Link Email Body</label>
                <textarea id="portal_link_email_intro" name="portal_link_email_intro" class="form-control" rows="2"
                          placeholder="Shown inside the portal access email…"><?= e(get_setting('portal_link_email_intro', 'Your secure {company_name} billing portal link is ready. This link expires automatically.')) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="invoice_paid_email_subject">Invoice Paid Email Subject</label>
                <input type="text" id="invoice_paid_email_subject" name="invoice_paid_email_subject" class="form-control"
                       value="<?= e(get_setting('invoice_paid_email_subject', 'Invoice Paid — {invoice_number}')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="invoice_paid_email_body">Invoice Paid Email Body</label>
                <textarea id="invoice_paid_email_body" name="invoice_paid_email_body" class="form-control" rows="3"
                          placeholder="Shown in the payment receipt email…"><?= e(get_setting('invoice_paid_email_body', 'Hi {customer_name}, Your payment of {amount} for invoice {invoice_number} has been received. Thank you!')) ?></textarea>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label" for="hide_launch_checklist">Dashboard Launch Checklist</label>
                <select id="hide_launch_checklist" name="hide_launch_checklist" class="form-select">
                    <?php $hide_cl = get_setting('hide_launch_checklist', '0'); ?>
                    <option value="0" <?= $hide_cl === '0' ? 'selected' : '' ?>>Visible on dashboard</option>
                    <option value="1" <?= $hide_cl === '1' ? 'selected' : '' ?>>Hidden</option>
                </select>
                <div class="form-text" style="color:var(--gy);">Auto-hides at 100%. You can also dismiss it from the dashboard.</div>
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
<?php if ($settings_copy_visible): ?>
<div id="settings-copy" class="tp-card mt-4 settings-panel" style="max-width:980px;">
    <form method="POST" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_copy">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h6 class="mb-1" style="font-weight:700;"><i class="fa-solid fa-language" style="color:#f97316;"></i> Customer-Facing Copy</h6>
                <div class="settings-token-note">Group copy by where it appears so updates are easier to review. Supported placeholders vary by screen, including <code>{company_name}</code>, <code>{customer_name}</code>, <code>{invoice_number}</code>, <code>{amount}</code>, and <code>{subject_phrase}</code>.</div>
            </div>
            <button type="submit" class="btn-tp-primary btn-tp-sm"><i class="fa-solid fa-floppy-disk"></i> Save Copy Settings</button>
        </div>

        <div class="settings-copy-group settings-copy-editor">
            <h6>Booking Success</h6>
            <p>Shown after a customer submits or completes a booking.</p>
            <div class="row g-3">
                <div class="col-lg-6">
                    <label class="form-label" for="copy_booking_success_pending_intro">Pending Review Intro</label>
                    <textarea id="copy_booking_success_pending_intro" name="booking_success_pending_intro" class="form-control rows-lg"><?= e(get_setting('booking_success_pending_intro', 'Thank you, {customer_name}. {subject_phrase} been submitted for review. We will follow up with approval details and payment instructions if needed.')) ?></textarea>
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="copy_booking_success_confirmed_intro">Confirmed Intro</label>
                    <textarea id="copy_booking_success_confirmed_intro" name="booking_success_confirmed_intro" class="form-control rows-lg"><?= e(get_setting('booking_success_confirmed_intro', 'Thank you, {customer_name}! {subject_phrase} been booked.')) ?></textarea>
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="copy_booking_success_terms_text">Terms Card Text</label>
                    <textarea id="copy_booking_success_terms_text" name="booking_success_terms_text" class="form-control"><?= e(get_setting('booking_success_terms_text', 'Keep a copy of the signed rental terms for your records.')) ?></textarea>
                </div>
                <div class="col-lg-3">
                    <label class="form-label" for="copy_booking_success_keep_title">Reminder Title</label>
                    <input type="text" id="copy_booking_success_keep_title" name="booking_success_keep_title" class="form-control" value="<?= e(get_setting('booking_success_keep_title', 'Keep this page handy.')) ?>">
                </div>
                <div class="col-lg-3">
                    <label class="form-label" for="copy_booking_success_contact_prompt">Contact Prompt</label>
                    <input type="text" id="copy_booking_success_contact_prompt" name="booking_success_contact_prompt" class="form-control" value="<?= e(get_setting('booking_success_contact_prompt', 'Questions? Call us at')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="copy_booking_success_keep_body">Reminder Body</label>
                    <textarea id="copy_booking_success_keep_body" name="booking_success_keep_body" class="form-control"><?= e(get_setting('booking_success_keep_body', 'Use these booking numbers when you call, email, or check your order in the customer portal.')) ?></textarea>
                </div>
            </div>
        </div>

        <div class="settings-copy-group settings-copy-editor">
            <h6>Payment Notes</h6>
            <p>Offline payment instructions for cash and check flows.</p>
            <div class="row g-3">
                <div class="col-lg-6">
                    <label class="form-label" for="copy_payment_note_cash">Cash Payment Note</label>
                    <textarea id="copy_payment_note_cash" name="payment_note_cash" class="form-control"><?= e(get_setting('payment_note_cash', 'Please have cash payment ready at time of delivery.')) ?></textarea>
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="copy_payment_note_check">Check Payment Note</label>
                    <textarea id="copy_payment_note_check" name="payment_note_check" class="form-control"><?= e(get_setting('payment_note_check', 'Please have your check made out to {company_name} ready at time of delivery.')) ?></textarea>
                </div>
            </div>
        </div>

        <div class="settings-copy-group settings-copy-editor">
            <h6>Invoice Pages</h6>
            <p>Messages shown after successful payment and after canceled or abandoned payment attempts.</p>
            <div class="row g-3">
                <div class="col-lg-6">
                    <label class="form-label" for="copy_invoice_paid_intro_named">Paid Page: Named Message</label>
                    <textarea id="copy_invoice_paid_intro_named" name="invoice_paid_intro_named" class="form-control"><?= e(get_setting('invoice_paid_intro_named', 'Thank you, {customer_name}! Your invoice has been paid.')) ?></textarea>
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="copy_invoice_paid_intro_generic">Paid Page: Generic Message</label>
                    <textarea id="copy_invoice_paid_intro_generic" name="invoice_paid_intro_generic" class="form-control"><?= e(get_setting('invoice_paid_intro_generic', 'Your invoice payment has been received. Thank you!')) ?></textarea>
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="copy_invoice_paid_contact_prompt">Paid Page: Contact Prompt</label>
                    <input type="text" id="copy_invoice_paid_contact_prompt" name="invoice_paid_contact_prompt" class="form-control" value="<?= e(get_setting('invoice_paid_contact_prompt', 'Questions? Call us at')) ?>">
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="copy_invoice_canceled_contact_prompt">Canceled Page: Contact Prompt</label>
                    <input type="text" id="copy_invoice_canceled_contact_prompt" name="invoice_canceled_contact_prompt" class="form-control" value="<?= e(get_setting('invoice_canceled_contact_prompt', 'Need help? Call us at')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="copy_invoice_canceled_intro">Canceled / Abandoned Message</label>
                    <textarea id="copy_invoice_canceled_intro" name="invoice_canceled_intro" class="form-control"><?= e(get_setting('invoice_canceled_intro', 'Your payment was not completed and the invoice has not been charged. No amount has been collected.')) ?></textarea>
                </div>
            </div>
        </div>

        <div class="settings-copy-group settings-copy-editor">
            <h6>Billing Portal</h6>
            <p>Copy shown on the portal request page and inside portal access emails.</p>
            <div class="row g-3">
                <div class="col-lg-6">
                    <label class="form-label" for="copy_portal_request_lead">Hero Text</label>
                    <textarea id="copy_portal_request_lead" name="portal_request_lead" class="form-control rows-lg"><?= e(get_setting('portal_request_lead', 'Get a secure one-time link to review invoices, payment history, saved billing methods, and subscription activity without needing a password.')) ?></textarea>
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="copy_portal_request_sub">Form Intro</label>
                    <textarea id="copy_portal_request_sub" name="portal_request_sub" class="form-control rows-lg"><?= e(get_setting('portal_request_sub', 'Enter your billing email and we’ll send a secure one-time link to access your invoices, subscriptions, and saved payment methods.')) ?></textarea>
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="copy_portal_request_success">Success Message</label>
                    <textarea id="copy_portal_request_success" name="portal_request_success" class="form-control"><?= e(get_setting('portal_request_success', 'If that email address is on file, a secure portal link has been sent. Check your inbox.')) ?></textarea>
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="copy_portal_request_security_note">Security Note</label>
                    <textarea id="copy_portal_request_security_note" name="portal_request_security_note" class="form-control"><?= e(get_setting('portal_request_security_note', 'Links are single-use and expire automatically. No password needed.')) ?></textarea>
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="copy_portal_link_email_subject">Portal Email Subject</label>
                    <input type="text" id="copy_portal_link_email_subject" name="portal_link_email_subject" class="form-control" value="<?= e(get_setting('portal_link_email_subject', 'Your {company_name} Billing Portal Link')) ?>">
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="copy_portal_link_email_button">Portal Button Label</label>
                    <input type="text" id="copy_portal_link_email_button" name="portal_link_email_button" class="form-control" value="<?= e(get_setting('portal_link_email_button', 'Open Billing Portal')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="copy_portal_link_email_intro">Portal Email Body</label>
                    <textarea id="copy_portal_link_email_intro" name="portal_link_email_intro" class="form-control"><?= e(get_setting('portal_link_email_intro', 'Your secure {company_name} billing portal link is ready. This link expires automatically.')) ?></textarea>
                </div>
            </div>
        </div>

        <div class="settings-copy-group settings-copy-editor">
            <h6>Receipt Emails</h6>
            <p>Copy for payment receipt emails after an invoice is paid.</p>
            <div class="row g-3">
                <div class="col-lg-6">
                    <label class="form-label" for="copy_invoice_paid_email_subject">Invoice Paid Subject</label>
                    <input type="text" id="copy_invoice_paid_email_subject" name="invoice_paid_email_subject" class="form-control" value="<?= e(get_setting('invoice_paid_email_subject', 'Invoice Paid — {invoice_number}')) ?>">
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="copy_invoice_paid_email_body">Invoice Paid Body</label>
                    <textarea id="copy_invoice_paid_email_body" name="invoice_paid_email_body" class="form-control rows-lg"><?= e(get_setting('invoice_paid_email_body', 'Hi {customer_name}, Your payment of {amount} for invoice {invoice_number} has been received. Thank you!')) ?></textarea>
                </div>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<div id="settings-branding" class="tp-card mt-4 settings-panel" style="max-width:780px;">
    <h6 class="mb-3" style="font-weight:600;border-bottom:1px solid var(--st);padding-bottom:.5rem;">
        <i class="fa-solid fa-image" style="color:#f97316;"></i> Logo
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
    <form method="POST" action="index.php" class="mb-5">
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

    <h6 class="mb-3" style="font-weight:600;border-bottom:1px solid var(--st);padding-bottom:.5rem;">
        <i class="fa-solid fa-bullhorn" style="color:#f97316;"></i> Website &amp; Social
        <span style="font-weight:400;font-size:.8rem;color:var(--gy);"> — controls nav, footer, invoices, and email templates</span>
    </h6>

    <form method="POST" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_branding">

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label" for="company_tagline">Tagline</label>
                <input type="text" id="company_tagline" name="company_tagline" class="form-control"
                       value="<?= e(get_setting('company_tagline', '')) ?>"
                       placeholder="Baldwin County &amp; Mobile's most trusted dumpster rental crew.">
                <div class="form-text" style="color:var(--gy);">Shown in the website footer below the company name.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="company_website">Website URL</label>
                <input type="url" id="company_website" name="company_website" class="form-control"
                       value="<?= e(get_setting('company_website', '')) ?>"
                       placeholder="https://trashpandarolloffs.com">
                <div class="form-text" style="color:var(--gy);">Used in customer emails and invoices.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="company_facebook">
                    <i class="fa-brands fa-facebook me-1" style="color:#60a5fa;"></i> Facebook URL
                </label>
                <input type="url" id="company_facebook" name="company_facebook" class="form-control"
                       value="<?= e(get_setting('company_facebook', '')) ?>"
                       placeholder="https://facebook.com/yourpage">
                <div class="form-text" style="color:var(--gy);">Leave blank to hide the Facebook icon in the footer.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="company_instagram">
                    <i class="fa-brands fa-instagram me-1" style="color:#f472b6;"></i> Instagram URL
                </label>
                <input type="url" id="company_instagram" name="company_instagram" class="form-control"
                       value="<?= e(get_setting('company_instagram', '')) ?>"
                       placeholder="https://instagram.com/yourhandle">
                <div class="form-text" style="color:var(--gy);">Leave blank to hide the Instagram icon in the footer.</div>
            </div>
        </div>

        <div class="alert alert-info d-flex gap-2 align-items-start mb-3" style="font-size:.85rem;">
            <i class="fa-solid fa-circle-info mt-1 flex-shrink-0"></i>
            <span>
                <strong>Company name, phone, email, and address</strong> are set on the <a href="#settings-company" class="alert-link">Company tab</a>.
                Changes there are reflected on all PHP-rendered pages (invoices, emails, portal, booking success)
                and on the public website nav &amp; footer automatically via the live config API.
            </span>
        </div>

        <button type="submit" class="btn-tp-primary">
            <i class="fa-solid fa-floppy-disk"></i> Save Branding Settings
        </button>
    </form>
</div>

<!-- ── Email Configuration ──────────────────────────────────────────────── -->
<div id="settings-email" class="tp-card mt-4 settings-panel" style="max-width:780px;">
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
                <div class="form-text" style="color:var(--gy);">Comma-separated. These addresses receive alerts for new bookings, invoice payments, contact form submissions, and overdue pickups.</div>
            </div>

            <div class="col-12">
                <label class="form-label">
                    Cron Key
                    <span style="font-weight:400;color:var(--gy);font-size:.8rem;"> — used to trigger the daily cron via URL</span>
                </label>
                <?php $cron_key = get_setting('cron_key', ''); ?>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" readonly
                           value="<?= e($cron_key) ?>"
                           id="cronKeyField"
                           style="font-size:.82rem;color:var(--gy);"
                           placeholder="Click Regenerate to create a key">
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            onclick="navigator.clipboard.writeText(document.getElementById('cronKeyField').value).then(()=>{this.textContent='Copied!';setTimeout(()=>{this.textContent='Copy'},1500)})"
                            <?= $cron_key === '' ? 'disabled' : '' ?>>
                        Copy
                    </button>
                    <button type="submit" form="regenCronForm" class="btn btn-outline-warning btn-sm"
                            onclick="return <?= $cron_key !== '' ? "confirm('Regenerate the cron key? Any existing cron job URL using the old key will stop working until updated.')" : 'true' ?>">
                        <i class="fa-solid fa-rotate"></i> <?= $cron_key === '' ? 'Generate Key' : 'Regenerate' ?>
                    </button>
                </div>
                <div class="form-text" style="color:var(--gy);">
                    Daily cron URL:
                    <code style="font-size:.78rem;"><?= e(rtrim(str_replace('/admin','',APP_URL),'/')) ?>/admin/cron/run.php?key=<?= e($cron_key) ?></code><br>
                    In Hostinger hPanel → Advanced → Cron Jobs, run daily at 8 AM:<br>
                    <code style="font-size:.78rem;">0 8 * * * /usr/bin/php <?= e($_SERVER['DOCUMENT_ROOT'] ?? '/home/user/public_html') ?>/admin/cron/run.php</code>
                </div>
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

    <form id="regenCronForm" method="POST" action="index.php" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="regenerate_cron_key">
    </form>
</div>

<!-- ── Stripe Configuration ──────────────────────────────────────────────── -->
<div id="settings-stripe" class="tp-card mt-4 settings-panel" style="max-width:780px;">
    <h6 class="mb-3" style="font-weight:600;border-bottom:1px solid #e5e7eb;padding-bottom:.5rem;">
        <i class="fa-brands fa-stripe" style="color:#6772e5;"></i> Stripe &amp; Booking Configuration
    </h6>

    <div class="alert alert-warning d-flex gap-3 align-items-start" style="background:#fff7ed;border:1px solid #fdba74;color:#9a3412;">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <div style="font-weight:700;">Production setup note</div>
            <div style="font-size:.92rem;">
                Stripe keys are managed on this page and stored in the app settings table. Your application environment is separate:
                <code>APP_ENV</code> comes from the server or <code>.env</code> file and is currently
                <strong><?= e(APP_ENV) ?></strong>.
            </div>
            <div style="font-size:.92rem;margin-top:.35rem;">
                Recommended workflow: finish QA in <strong>Test</strong> mode first, then switch to
                <strong>Live</strong> here and paste your live Stripe keys.
            </div>
        </div>
    </div>

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
                <div class="form-text" style="color:var(--gy);">
                    This controls whether billing uses your test or live Stripe account.
                </div>
            </div>
            <div class="col-md-8">
                <label class="form-label" for="stripe_publishable_key">Publishable Key</label>
                <input type="text" id="stripe_publishable_key" name="stripe_publishable_key"
                       class="form-control" placeholder="pk_test_…"
                       value="<?= e(get_setting('stripe_publishable_key', '')) ?>">
                <div class="form-text" style="color:var(--gy);">
                    Use <code>pk_test_...</code> in Test mode and <code>pk_live_...</code> in Live mode.
                </div>
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
                <div class="form-text" style="color:var(--gy);">Saved in app settings. Leave blank to keep the existing key.</div>
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
                <div class="form-text" style="color:var(--gy);">Saved in app settings.</div>
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
                <label class="form-label" for="booking_flow_mode">Website Booking Flow</label>
                <select id="booking_flow_mode" name="booking_flow_mode" class="form-select">
                    <?php $booking_flow_mode = get_setting('booking_flow_mode', 'instant'); ?>
                    <option value="instant" <?= $booking_flow_mode === 'instant' ? 'selected' : '' ?>>Instant checkout after booking</option>
                    <option value="request" <?= $booking_flow_mode === 'request' ? 'selected' : '' ?>>Request first, approve and invoice later</option>
                </select>
                <div class="form-text" style="color:var(--gy);">
                    Choose whether customers pay right away online, or submit a request for your team to review before sending an invoice or payment link.
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="approval_auto_send_invoice">Invoice Auto-Send on Approval</label>
                <select id="approval_auto_send_invoice" name="approval_auto_send_invoice" class="form-select">
                    <?php $auto_send = get_setting('approval_auto_send_invoice', '1'); ?>
                    <option value="1" <?= $auto_send === '1' ? 'selected' : '' ?>>Auto-send invoice email to customer</option>
                    <option value="0" <?= $auto_send === '0' ? 'selected' : '' ?>>Create invoice as draft (send manually)</option>
                </select>
                <div class="form-text" style="color:var(--gy);">
                    When a booking request is approved, the system always creates a work order and an invoice. This controls whether the invoice email goes out immediately.
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="billing_retry_days">Billing Retry Schedule</label>
                <input type="text" id="billing_retry_days" name="billing_retry_days" class="form-control"
                       value="<?= e(retry_days_display_value()) ?>" placeholder="1, 3, 5">
                <div class="form-text" style="color:var(--gy);">
                    Enter the days after a failed payment when Stripe retries should happen, separated by commas. Example: <code>1, 3, 5</code>.
                </div>
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
        </div>

        <!-- Tax Rate -->
        <div class="row g-3 mb-4 mt-0" style="border-top:1px solid var(--st,#2a2f3e);padding-top:1.25rem;">
            <div class="col-12">
                <h6 class="mb-0" style="font-weight:600;color:var(--or,#f97316);">
                    <i class="fa-solid fa-percent me-1"></i> Tax Rate
                </h6>
                <div class="form-text mt-0 mb-2" style="color:var(--gy);">
                    Applied to printed work order invoices. Stripe handles tax automatically for online payments.
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="tax_rate">Tax Rate (%)</label>
                <div class="input-group">
                    <input type="number" id="tax_rate" name="tax_rate" class="form-control"
                           step="0.001" min="0" max="100"
                           value="<?= e(get_setting('tax_rate', '0')) ?>"
                           placeholder="0.000">
                    <button type="button" class="btn btn-outline-secondary" id="syncTaxRateBtn"
                            onclick="syncStripeRate()" title="Pull rate from your Stripe Tax rates">
                        <i class="fa-solid fa-rotate me-1"></i> Sync from Stripe
                    </button>
                </div>
                <div id="stripeRateList" class="mt-2" style="display:none;"></div>
                <div class="form-text" style="color:var(--gy);">
                    Set manually or click <strong>Sync from Stripe</strong> to pull your Stripe Tax rates.
                </div>
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

<script>
function syncStripeRate() {
    var btn  = document.getElementById('syncTaxRateBtn');
    var list = document.getElementById('stripeRateList');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    list.style.display = 'none';
    fetch('<?= e(APP_URL) ?>/api/stripe-tax-rates.php', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                list.innerHTML = '<small class="text-danger"><i class="fa-solid fa-circle-xmark me-1"></i>' + data.error + '</small>';
                list.style.display = 'block';
                return;
            }
            if (!data.rates || !data.rates.length) {
                list.innerHTML = '<small class="text-warning">No active tax rates found in Stripe. '
                    + '<a href="https://dashboard.stripe.com/tax-rates" target="_blank" rel="noopener">Create one in Stripe &rarr;</a></small>';
                list.style.display = 'block';
                return;
            }
            var html = '<div class="d-flex flex-wrap gap-1">';
            data.rates.forEach(function(r) {
                html += '<button type="button" class="btn btn-sm btn-outline-warning" onclick="applyTaxRate(' + r.percentage + ',\'' + r.display_name.replace(/'/g,'') + '\')">'
                      + r.display_name + ' &mdash; ' + r.percentage + '%</button>';
            });
            html += '</div>';
            list.innerHTML = html;
            list.style.display = 'block';
        })
        .catch(function(e) {
            list.innerHTML = '<small class="text-danger">Request failed: ' + e.message + '</small>';
            list.style.display = 'block';
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-rotate me-1"></i> Sync from Stripe';
        });
}
function applyTaxRate(pct, name) {
    document.getElementById('tax_rate').value = pct;
    document.getElementById('stripeRateList').style.display = 'none';
}
</script>
</div>

<!-- ── Database Maintenance ──────────────────────────────────────────────── -->
<div id="settings-database" class="tp-card mt-4 settings-panel" style="max-width:780px;">
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

</div>

<script>
function activateSettingsTab(tabName) {
    var tabs = document.querySelectorAll('.settings-tab');
    var panels = document.querySelectorAll('.settings-panel');

    tabs.forEach(function(tab) {
        var isActive = tab.getAttribute('data-settings-tab') === tabName;
        tab.classList.toggle('active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    panels.forEach(function(panel) {
        var isActive = panel.id === 'settings-' + tabName;
        panel.classList.toggle('active', isActive);
    });
}

function currentSettingsTabFromHash() {
    var hash = window.location.hash || '#settings-company';
    var normalized = hash.replace('#settings-', '');
    return <?= json_encode(array_values($settings_tab_names)) ?>.indexOf(normalized) >= 0
        ? normalized
        : 'company';
}

document.querySelectorAll('.settings-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        activateSettingsTab(tab.getAttribute('data-settings-tab'));
    });
});

window.addEventListener('hashchange', function() {
    activateSettingsTab(currentSettingsTabFromHash());
});

activateSettingsTab(currentSettingsTabFromHash());

[
    'payment_note_cash',
    'payment_note_check',
    'booking_success_pending_intro',
    'booking_success_confirmed_intro',
    'booking_success_terms_text',
    'booking_success_keep_title',
    'booking_success_contact_prompt',
    'booking_success_keep_body',
    'invoice_paid_intro_named',
    'invoice_paid_intro_generic',
    'invoice_paid_contact_prompt',
    'invoice_canceled_contact_prompt',
    'invoice_canceled_intro',
    'portal_request_lead',
    'portal_request_sub',
    'portal_request_success',
    'portal_request_security_note',
    'portal_link_email_subject',
    'portal_link_email_button',
    'portal_link_email_intro',
    'invoice_paid_email_subject',
    'invoice_paid_email_body'
].forEach(function(id) {
    var node = document.getElementById(id);
    if (!node) return;
    var col = node.closest('[class*="col-"]');
    if (col) col.style.display = 'none';
});

Array.from(document.querySelectorAll('#settings-company h6')).forEach(function(h) {
    if (h.textContent.indexOf('Customer-Facing Copy') !== -1) {
        h.style.display = 'none';
        var next = h.nextElementSibling;
        if (next && next.classList.contains('row')) {
            next.style.display = 'none';
        }
    }
});

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
