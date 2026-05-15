<?php
/**
 * Email Templates – Admin Editor
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_once INC_PATH . '/mailer.php';
require_login();
require_role('admin');

// ── Template registry ─────────────────────────────────────────────────────────
$TEMPLATE_DEFS = [
    'booking_confirmed' => [
        'name'    => 'Booking Confirmed',
        'desc'    => 'Sent to customer when their booking is approved/confirmed.',
        'vars'    => ['customer_name','booking_number','unit','rental_start','rental_end','rental_days','rental_days_s','total','payment_method'],
        'default_subject'  => 'Booking Confirmed — {{booking_number}}',
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>Your dumpster rental booking has been <strong>confirmed</strong>. Here are your details:</p>
<table width="100%" style="border-collapse:collapse;font-size:.95rem;margin:16px 0;">
  <tr style="background:#f9fafb;"><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;width:40%;">Booking #</td><td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{booking_number}}</td></tr>
  <tr><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Unit</td><td style="padding:10px 14px;border:1px solid #e5e7eb;">{{unit}}</td></tr>
  <tr style="background:#f9fafb;"><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Rental Period</td><td style="padding:10px 14px;border:1px solid #e5e7eb;">{{rental_start}} → {{rental_end}} ({{rental_days}} day{{rental_days_s}})</td></tr>
  <tr><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Total</td><td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{total}}</td></tr>
  <tr style="background:#f9fafb;"><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Payment Method</td><td style="padding:10px 14px;border:1px solid #e5e7eb;">{{payment_method}}</td></tr>
</table>
<p>If you have any questions or need to make changes, please contact us.</p>
<p>Thank you for choosing us!</p>',
    ],
    'booking_request_received' => [
        'name'    => 'Booking Request Received',
        'desc'    => 'Sent to customer when their booking request is submitted (request/approval flow).',
        'vars'    => ['customer_name','booking_number','unit','rental_start','rental_end','total','payment_method'],
        'default_subject'  => 'We received your booking request — {{booking_number}}',
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>We received your dumpster rental request and it is now <strong>awaiting review</strong>.</p>
<table width="100%" style="border-collapse:collapse;font-size:.95rem;margin:16px 0;">
  <tr style="background:#f9fafb;"><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;width:40%;">Request #</td><td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{booking_number}}</td></tr>
  <tr><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Unit</td><td style="padding:10px 14px;border:1px solid #e5e7eb;">{{unit}}</td></tr>
  <tr style="background:#f9fafb;"><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Rental Period</td><td style="padding:10px 14px;border:1px solid #e5e7eb;">{{rental_start}} → {{rental_end}}</td></tr>
  <tr><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Estimated Total</td><td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{total}}</td></tr>
  <tr style="background:#f9fafb;"><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Preferred Payment</td><td style="padding:10px 14px;border:1px solid #e5e7eb;">{{payment_method}}</td></tr>
</table>
<p>Our team will review availability and follow up with approval details and payment instructions if needed.</p>
<p>We appreciate the opportunity to earn your business.</p>',
    ],
    'booking_approved' => [
        'name'    => 'Booking Approved',
        'desc'    => 'Sent to customer when a pending booking request is approved by staff.',
        'vars'    => ['customer_name','booking_number','unit','rental_start','rental_end','rental_days','rental_days_s','total','payment_method','payment_message','invoice_number'],
        'default_subject'  => 'Your booking is approved — {{booking_number}}',
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>Your dumpster rental request has been <strong>approved</strong>, and your order is now reserved.</p>
<table width="100%" style="border-collapse:collapse;font-size:.95rem;margin:16px 0;">
  <tr style="background:#f9fafb;"><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;width:40%;">Booking #</td><td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{booking_number}}</td></tr>
  <tr><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Invoice #</td><td style="padding:10px 14px;border:1px solid #e5e7eb;">{{invoice_number}}</td></tr>
  <tr style="background:#f9fafb;"><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Unit</td><td style="padding:10px 14px;border:1px solid #e5e7eb;">{{unit}}</td></tr>
  <tr><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Rental Period</td><td style="padding:10px 14px;border:1px solid #e5e7eb;">{{rental_start}} → {{rental_end}} ({{rental_days}} day{{rental_days_s}})</td></tr>
  <tr style="background:#f9fafb;"><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Total</td><td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{total}}</td></tr>
  <tr><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Payment Preference</td><td style="padding:10px 14px;border:1px solid #e5e7eb;">{{payment_method}}</td></tr>
</table>
{{payment_message}}
<p>Your signed terms PDF is attached for your records.</p>
<p>If you need to make any changes before delivery, just reply to this email or contact us.</p>',
    ],
    'invoice_ready' => [
        'name'    => 'Invoice Ready',
        'desc'    => 'Sent to customer when an invoice is emailed to them.',
        'vars'    => ['customer_name','invoice_number','amount','due_date','notes_block'],
        'default_subject'  => 'Your invoice is ready — {{invoice_number}}',
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>Your invoice <strong>{{invoice_number}}</strong> is ready.</p>
<table width="100%" style="border-collapse:collapse;font-size:.95rem;margin:16px 0;">
  <tr style="background:#f9fafb;"><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;width:40%;">Invoice #</td><td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{invoice_number}}</td></tr>
  <tr><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Amount Due</td><td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{amount}}</td></tr>
  <tr style="background:#f9fafb;"><td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Due Date</td><td style="padding:10px 14px;border:1px solid #e5e7eb;">{{due_date}}</td></tr>
</table>
{{notes_block}}
<p>You can review and pay this invoice using the link below.</p>
<p>If you have any questions before paying, reply to this email and we’ll be happy to help.</p>',
    ],
    'delivery_tomorrow' => [
        'name'    => 'Delivery Tomorrow Reminder',
        'desc'    => 'Sent to customer the day before their scheduled dumpster delivery.',
        'vars'    => ['customer_name','delivery_date','delivery_address','unit_size'],
        'default_subject'  => 'Your Dumpster Delivery Is Tomorrow!',
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>This is a reminder that your dumpster delivery is scheduled for <strong>tomorrow, {{delivery_date}}</strong>.</p>
<p><strong>Delivery Address:</strong> {{delivery_address}}</p>
<p><strong>Dumpster Size:</strong> {{unit_size}}</p>
<p>Please ensure the area is accessible for our driver. If you need to reschedule, contact us as soon as possible.</p>
<p>Thank you for choosing us!</p>',
    ],
    'rental_ending_soon' => [
        'name'    => 'Rental Ending Soon',
        'desc'    => 'Sent to customer a few days before their rental end date.',
        'vars'    => ['customer_name','booking_number','unit','rental_end','phone_block'],
        'default_subject'  => 'Your Dumpster Rental Ends on {{rental_end}} — {{booking_number}}',
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>This is a friendly reminder that your dumpster rental (<strong>{{booking_number}}</strong>) is scheduled to end on <strong>{{rental_end}}</strong>.</p>
<p><strong>Unit:</strong> {{unit}}</p>
{{phone_block}}
<p>If you do not need to extend, please ensure the dumpster is ready for pickup by the end date.</p>
<p>Thank you!</p>',
    ],
    'booking_cancelled' => [
        'name'    => 'Booking Cancelled',
        'desc'    => 'Sent to customer when their booking is cancelled.',
        'vars'    => ['customer_name','booking_number'],
        'default_subject'  => 'Booking Cancelled — {{booking_number}}',
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>Your booking <strong>{{booking_number}}</strong> has been <strong>cancelled</strong>.</p>
<p>If you believe this was done in error or have questions, please contact us.</p>
<p>Thank you for your business.</p>',
    ],
    'work_order_delivered' => [
        'name'    => 'Dumpster Delivered',
        'desc'    => 'Sent to customer when their work order is marked Delivered.',
        'vars'    => ['customer_name','service_address','unit_size'],
        'default_subject'  => 'Your Dumpster Has Been Delivered!',
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>Your dumpster has been delivered to <strong>{{service_address}}</strong>. You are all set to start your project!</p>
<p><strong>Unit Size:</strong> {{unit_size}}</p>
<p>When you are ready for pickup, just give us a call and we will get it scheduled.</p>
<p>Thank you for choosing us!</p>',
    ],
    'work_order_completed' => [
        'name'    => 'Pickup Complete',
        'desc'    => 'Sent to customer when their work order is marked Picked Up or Completed.',
        'vars'    => ['customer_name'],
        'default_subject'  => 'Pickup Complete — Thanks for Choosing Us!',
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>Your dumpster has been picked up and your rental is now complete. Thank you for choosing us for your project!</p>
<p>We hope everything went smoothly. If you need a dumpster again in the future, we would love to help.</p>',
    ],
    'invoice_overdue' => [
        'name'    => 'Invoice Overdue Reminder',
        'desc'    => 'Sent to customer when an invoice is past its due date (daily cron).',
        'vars'    => ['customer_name','invoice_number','amount','due_date'],
        'default_subject'  => 'Invoice {{invoice_number}} — Payment Past Due',
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>This is a reminder that invoice <strong>{{invoice_number}}</strong> for <strong>{{amount}}</strong> was due on <strong>{{due_date}}</strong> and remains outstanding.</p>
<p>Please remit payment at your earliest convenience. If you have already paid or have any questions, please contact us and we will sort it out right away.</p>
<p>Thank you for your prompt attention.</p>',
    ],
    'lead_received' => [
        'name'    => 'Contact Form Auto-Response',
        'desc'    => 'Sent to the customer automatically when they submit the public contact/quote form.',
        'vars'    => ['customer_name','company_name'],
        'default_subject'  => 'We received your request — {{company_name}}',
        'default_body_html'=> '<p>Hi {{customer_name}},</p>
<p>Thanks for reaching out! We received your request and someone from our team will be in touch shortly.</p>
<p>We appreciate your interest in {{company_name}}!</p>',
    ],
];

// ── POST handler ──────────────────────────────────────────────────────────────
$flash_ok  = '';
$flash_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_template') {
        $slug      = trim($_POST['slug'] ?? '');
        $subject   = trim($_POST['subject'] ?? '');
        $body_html = trim($_POST['body_html'] ?? '');

        if (!isset($TEMPLATE_DEFS[$slug])) {
            $flash_err = 'Unknown template slug.';
        } elseif ($subject === '' || $body_html === '') {
            $flash_err = 'Subject and body cannot be empty.';
        } else {
            $existing = db_fetch('SELECT id FROM email_templates WHERE slug = ?', [$slug]);
            if ($existing) {
                db_update('email_templates', [
                    'subject'    => $subject,
                    'body_html'  => $body_html,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id', (int)$existing['id']);
            } else {
                db_insert('email_templates', [
                    'slug'       => $slug,
                    'name'       => $TEMPLATE_DEFS[$slug]['name'],
                    'subject'    => $subject,
                    'body_html'  => $body_html,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $flash_ok = 'Template saved.';
        }
    } elseif ($action === 'reset_template') {
        $slug = trim($_POST['slug'] ?? '');
        if (isset($TEMPLATE_DEFS[$slug])) {
            db_execute('DELETE FROM email_templates WHERE slug = ?', [$slug]);
            $flash_ok = 'Template reset to default.';
        }
    }
}

// ── Load saved templates from DB ──────────────────────────────────────────────
$saved = [];
try {
    $rows = db_fetchall('SELECT slug, subject, body_html, updated_at FROM email_templates');
    foreach ($rows as $r) {
        $saved[$r['slug']] = $r;
    }
} catch (\Throwable $e) {
    $flash_err = 'DB error: ' . $e->getMessage();
}

$editing = isset($_GET['edit']) && isset($TEMPLATE_DEFS[$_GET['edit']]) ? $_GET['edit'] : null;

// Preview company info (used in JS email wrapper)
$preview_company = get_setting('company_name',    'Trash Panda Roll-Offs');
$preview_phone   = get_setting('company_phone',   '');
$preview_email_s = get_setting('company_email',   '');
$preview_address = get_setting('company_address', '');

layout_start('Email Templates', 'email_templates');
?>

<?php if ($flash_ok): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= e($flash_ok) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($flash_err): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= e($flash_err) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="tp-page-title mb-1">Email Templates</h1>
        <p class="text-muted mb-0" style="font-size:.9rem;">Customize the emails sent to customers. Use <code>{{variable}}</code> placeholders — they get replaced with real data when the email sends.</p>
    </div>
</div>

<?php if ($editing): ?>
<?php
    $def         = $TEMPLATE_DEFS[$editing];
    $curr        = $saved[$editing] ?? null;
    $cur_subject = $curr ? $curr['subject']  : $def['default_subject'];
    $cur_body    = $curr ? $curr['body_html'] : $def['default_body_html'];
?>

<!-- TinyMCE via jsDelivr (no API key required) -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header d-flex align-items-center gap-2 flex-wrap" style="background:#1a1d27;border-radius:8px 8px 0 0;">
        <a href="<?= APP_URL ?>/modules/email_templates/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left"></i>
        </a>
        <span style="color:#f97316;font-weight:700;"><?= e($def['name']) ?></span>
        <?php if ($curr): ?>
        <span class="badge bg-success">Custom</span>
        <?php else: ?>
        <span class="badge bg-secondary">Default</span>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-info ms-auto" onclick="openPreview()">
            <i class="fas fa-eye me-1"></i> Preview Email
        </button>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3" style="font-size:.9rem;"><?= e($def['desc']) ?></p>

        <!-- Variables -->
        <div class="mb-4">
            <label class="form-label fw-semibold mb-2">Available Variables</label>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($def['vars'] as $v): ?>
                <button type="button"
                        class="btn btn-sm"
                        style="background:#1e2030;color:#f97316;border:1px solid #f97316;font-family:monospace;font-size:.8rem;padding:2px 10px;"
                        onclick="insertVar('{{<?= e($v) ?>}}')"
                        title="Click to insert into editor">{{<?= e($v) ?>}}</button>
                <?php endforeach; ?>
            </div>
            <small class="text-muted mt-1 d-block">Click any variable to insert it at the cursor position in the editor.</small>
        </div>

        <form method="POST" action="" id="tpl-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_template">
            <input type="hidden" name="slug" value="<?= e($editing) ?>">

            <!-- Subject -->
            <div class="mb-4">
                <label class="form-label fw-semibold" for="tpl_subject">Subject Line</label>
                <input type="text" id="tpl_subject" name="subject" class="form-control"
                       value="<?= e($cur_subject) ?>" required>
                <small class="text-muted">Supports <code>{{variables}}</code> too.</small>
            </div>

            <!-- Body editor (TinyMCE) -->
            <div class="mb-4">
                <label class="form-label fw-semibold">Email Body</label>
                <textarea id="tpl_body" name="body_html"><?= e($cur_body) ?></textarea>
                <small class="text-muted mt-1 d-block">
                    This is the inner body — the header/footer and button are added automatically.
                    Use the <strong>&lt;/&gt;</strong> toolbar button to switch to HTML source view.
                </small>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary" id="save-btn">
                    <i class="fas fa-save me-1"></i> Save Template
                </button>
                <?php if ($curr): ?>
                <button type="submit" form="reset-form" class="btn btn-outline-danger">
                    <i class="fas fa-rotate-left me-1"></i> Reset to Default
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-info" onclick="openPreview()">
                    <i class="fas fa-eye me-1"></i> Preview
                </button>
                <a href="<?= APP_URL ?>/modules/email_templates/index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>

        <?php if ($curr): ?>
        <form id="reset-form" method="POST" action=""
              onsubmit="return confirm('Reset this template to the built-in default?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_template">
            <input type="hidden" name="slug" value="<?= e($editing) ?>">
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="background:#1a1d27;">
            <div class="modal-header" style="border-color:#2d3148;">
                <h5 class="modal-title" style="color:#f97316;" id="previewModalLabel">
                    <i class="fas fa-eye me-2"></i>Email Preview
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="previewFrame"
                        style="width:100%;height:600px;border:0;display:block;"
                        sandbox="allow-same-origin"></iframe>
            </div>
            <div class="modal-footer" style="border-color:#2d3148;">
                <small class="text-muted">Preview uses sample values for <code>{{variables}}</code>. The actual email wraps the body with your company header and footer.</small>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// ── Company info for preview wrapper ──────────────────────────────────────────
const _co = {
    name:    <?= json_encode($preview_company) ?>,
    phone:   <?= json_encode($preview_phone) ?>,
    email:   <?= json_encode($preview_email_s) ?>,
    address: <?= json_encode($preview_address) ?>,
};

// ── Sample values for {{variable}} replacement in preview ─────────────────────
const _samples = {
    customer_name:   'John Smith',
    booking_number:  'BK-0001',
    invoice_number:  'INV-0001',
    unit:            'TP-01 — 10 Yard',
    unit_size:       '10 Yard',
    rental_start:    'Monday, June 2, 2025',
    rental_end:      'Monday, June 9, 2025',
    rental_days:     '7',
    rental_days_s:   's',
    total:           '$350.00',
    amount:          '$350.00',
    due_date:        'June 9, 2025',
    payment_method:  'Card',
    delivery_date:   'Wednesday, June 4, 2025',
    delivery_address:'123 Main St, Mobile, AL 36602',
    notes_block:     '',
    phone_block:     _co.phone ? '<p>To schedule a pickup or extend your rental, call us at <strong>' + _co.phone + '</strong>.</p>' : '<p>Please contact us to schedule a pickup.</p>',
};

function _replaceSamples(html) {
    return html.replace(/\{\{(\w+)\}\}/g, (m, key) => _samples[key] !== undefined ? _samples[key] : m);
}

function _buildEmailWrapper(title, bodyHtml) {
    const footerParts = [_co.name, _co.phone, _co.email, _co.address].filter(Boolean);
    const footerText  = footerParts.join(' &bull; ');

    return `<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:30px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
        <tr><td style="background:#1a1d27;padding:24px 32px;border-radius:8px 8px 0 0;">
          ${_co.logo
            ? `<img src="${_escHtml(_co.logo)}" alt="${_escHtml(_co.name)}" style="max-height:55px;max-width:200px;object-fit:contain;display:block;margin-bottom:8px;">`
            : `<h1 style="margin:0 0 6px;color:#f97316;font-size:1.5rem;font-weight:700;">${_escHtml(_co.name)}</h1>`}
          <p style="margin:0;color:#9ca3af;font-size:.85rem;">${_escHtml(title)}</p>
        </td></tr>
        <tr><td style="background:#ffffff;padding:32px;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;">
          ${bodyHtml}
        </td></tr>
        <tr><td style="background:#f9fafb;padding:20px 32px;border:1px solid #e5e7eb;border-radius:0 0 8px 8px;color:#9ca3af;font-size:.75rem;text-align:center;">
          ${footerText}<br><span style="color:#d1d5db;">Powered by Trash Panda Roll-Offs Manager</span>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>`;
}

function _escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function openPreview() {
    const subj   = document.getElementById('tpl_subject').value || 'Email Preview';
    const editor = tinymce.get('tpl_body');
    const body   = editor ? editor.getContent() : document.getElementById('tpl_body').value;
    const filled = _replaceSamples(body);
    const html   = _buildEmailWrapper(subj, filled);

    const frame  = document.getElementById('previewFrame');
    frame.srcdoc = html;

    const modal  = new bootstrap.Modal(document.getElementById('previewModal'));
    modal.show();
}

function insertVar(v) {
    const editor = tinymce.get('tpl_body');
    if (editor) {
        editor.insertContent(v);
        editor.focus();
    }
}

// ── TinyMCE init ──────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    tinymce.init({
        selector: '#tpl_body',
        height: 480,
        menubar: false,
        promotion: false,
        branding: false,
        plugins: 'lists link table code',
        toolbar: 'undo redo | blocks | bold italic underline | forecolor | '
               + 'bullist numlist | table | link | removeformat | code',
        toolbar_mode: 'wrap',
        skin: 'oxide-dark',
        content_css: 'dark',
        content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; color: #111827; background: #fff; }',
        valid_elements: '*[*]',
        extended_valid_elements: '*[*]',
        verify_html: false,
        cleanup: false,
        setup: function (editor) {
            editor.on('change', function () { editor.save(); });
        },
    });

    // Sync TinyMCE content to textarea before form submit
    document.getElementById('tpl-form').addEventListener('submit', function () {
        const editor = tinymce.get('tpl_body');
        if (editor) editor.save();
    });
});
</script>

<?php else: ?>
<!-- ── Template list ── -->
<script>
var _tplData = {};
<?php foreach ($TEMPLATE_DEFS as $slug => $def):
    $curr = $saved[$slug] ?? null; ?>
_tplData[<?= json_encode($slug) ?>] = {
    subject: <?= json_encode($curr ? $curr['subject'] : $def['default_subject']) ?>,
    body: <?= json_encode($curr ? $curr['body_html'] : $def['default_body_html']) ?>
};
<?php endforeach; ?>
</script>
<div class="row g-3">
    <?php foreach ($TEMPLATE_DEFS as $slug => $def): ?>
    <?php $curr = $saved[$slug] ?? null; ?>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div>
                        <h6 class="mb-1 fw-bold"><?= e($def['name']) ?></h6>
                        <p class="text-muted mb-2" style="font-size:.85rem;"><?= e($def['desc']) ?></p>
                    </div>
                    <?php if ($curr): ?>
                    <span class="badge bg-success flex-shrink-0 ms-2">Custom</span>
                    <?php else: ?>
                    <span class="badge bg-secondary flex-shrink-0 ms-2">Default</span>
                    <?php endif; ?>
                </div>

                <?php if ($curr): ?>
                <div class="mb-1" style="font-size:.82rem;">
                    <span class="text-muted">Subject:</span>
                    <span class="ms-1"><?= e($curr['subject']) ?></span>
                </div>
                <div class="mb-2" style="font-size:.75rem;color:#9ca3af;">
                    Last saved: <?= e($curr['updated_at'] ?? '—') ?>
                </div>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-1 mb-3">
                    <?php foreach ($def['vars'] as $v): ?>
                    <code class="bg-light px-1 rounded" style="font-size:.72rem;">{{<?= e($v) ?>}}</code>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex gap-2 mt-auto flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-info"
                            onclick="openListPreview('<?= e($slug) ?>')">
                        <i class="fas fa-eye me-1"></i> Preview
                    </button>
                    <a href="?edit=<?= urlencode($slug) ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-pen me-1"></i> <?= $curr ? 'Edit' : 'Customize' ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- List preview modal -->
<div class="modal fade" id="listPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="background:#1a1d27;">
            <div class="modal-header" style="border-color:#2d3148;">
                <h5 class="modal-title" style="color:#f97316;">
                    <i class="fas fa-eye me-2"></i>Email Preview
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="listPreviewFrame"
                        style="width:100%;height:600px;border:0;display:block;"
                        sandbox="allow-same-origin"></iframe>
            </div>
            <div class="modal-footer" style="border-color:#2d3148;">
                <small class="text-muted">Preview uses sample values for <code>{{variables}}</code>.</small>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
$preview_logo = get_setting('logo_url', '') ?: get_setting('logo_path', '');
?>
<script>
const _co = {
    name:    <?= json_encode($preview_company) ?>,
    phone:   <?= json_encode($preview_phone) ?>,
    email:   <?= json_encode($preview_email_s) ?>,
    address: <?= json_encode($preview_address) ?>,
    logo:    <?= json_encode($preview_logo) ?>,
};

const _samples = {
    customer_name:'John Smith', booking_number:'BK-0001', invoice_number:'INV-0001',
    unit:'TP-01 — 10 Yard', unit_size:'10 Yard',
    rental_start:'Monday, June 2, 2025', rental_end:'Monday, June 9, 2025',
    rental_days:'7', rental_days_s:'s', total:'$350.00', amount:'$350.00',
    due_date:'June 9, 2025', payment_method:'Card',
    delivery_date:'Wednesday, June 4, 2025', delivery_address:'123 Main St, Mobile, AL',
    notes_block:'',
    phone_block: _co.phone ? '<p>Call us at <strong>' + _co.phone + '</strong>.</p>' : '<p>Please contact us.</p>',
};

function _escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function _replaceSamples(html) {
    return html.replace(/\{\{(\w+)\}\}/g, (m, key) => _samples[key] !== undefined ? _samples[key] : m);
}
function _buildEmailWrapper(title, bodyHtml) {
    const footerParts = [_co.name, _co.phone, _co.email, _co.address].filter(Boolean);
    const footerText  = footerParts.join(' &bull; ');
    return `<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:30px 0;">
<tr><td align="center"><table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
<tr><td style="background:#1a1d27;padding:24px 32px;border-radius:8px 8px 0 0;">
  ${_co.logo
    ? `<img src="${_escHtml(_co.logo)}" alt="${_escHtml(_co.name)}" style="max-height:55px;max-width:200px;object-fit:contain;display:block;margin-bottom:8px;">`
    : `<h1 style="margin:0 0 6px;color:#f97316;font-size:1.5rem;font-weight:700;">${_escHtml(_co.name)}</h1>`}
  <p style="margin:0;color:#9ca3af;font-size:.85rem;">${_escHtml(title)}</p>
</td></tr>
<tr><td style="background:#fff;padding:32px;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;">
  ${bodyHtml}
</td></tr>
<tr><td style="background:#f9fafb;padding:20px 32px;border:1px solid #e5e7eb;border-radius:0 0 8px 8px;color:#9ca3af;font-size:.75rem;text-align:center;">
  ${footerText}<br><span style="color:#d1d5db;">Powered by Trash Panda Roll-Offs Manager</span>
</td></tr>
</table></td></tr></table></body></html>`;
}

function openListPreview(slug) {
    var tpl    = _tplData[slug] || { subject: 'Preview', body: '' };
    var filled = _replaceSamples(tpl.body);
    var html   = _buildEmailWrapper(tpl.subject, filled);
    document.getElementById('listPreviewFrame').srcdoc = html;
    new bootstrap.Modal(document.getElementById('listPreviewModal')).show();
}
</script>

<?php endif; ?>

<?php layout_end(); ?>
