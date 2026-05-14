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

// ── Template registry (slug → metadata + defaults) ────────────────────────────
$TEMPLATE_DEFS = [
    'booking_confirmed' => [
        'name'    => 'Booking Confirmed',
        'desc'    => 'Sent to customer when their booking is approved/confirmed.',
        'vars'    => ['customer_name','booking_number','unit','rental_start','rental_end','rental_days','rental_days_s','total','payment_method'],
        'default_subject'  => 'Booking Confirmed — {{booking_number}}',
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>Your dumpster rental booking has been <strong>confirmed</strong>. Here are your details:</p>
<table width="100%" style="border-collapse:collapse;font-size:.95rem;margin:16px 0;">
  <tr style="background:#f9fafb;">
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;width:40%;">Booking #</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{booking_number}}</td>
  </tr>
  <tr>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Unit</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;">{{unit}}</td>
  </tr>
  <tr style="background:#f9fafb;">
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Rental Period</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;">{{rental_start}} → {{rental_end}} ({{rental_days}} day{{rental_days_s}})</td>
  </tr>
  <tr>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Total</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{total}}</td>
  </tr>
  <tr style="background:#f9fafb;">
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Payment Method</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;">{{payment_method}}</td>
  </tr>
</table>
<p>If you have any questions or need to make changes, please contact us.</p>
<p>Thank you for choosing us!</p>',
    ],
    'booking_request_received' => [
        'name'    => 'Booking Request Received',
        'desc'    => 'Sent to customer when their booking request is submitted (request/approval flow).',
        'vars'    => ['customer_name','booking_number','unit','rental_start','rental_end','total','payment_method'],
        'default_subject'  => 'Booking Request Received — {{booking_number}}',
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>We received your dumpster rental request and it is now <strong>awaiting review</strong>.</p>
<table width="100%" style="border-collapse:collapse;font-size:.95rem;margin:16px 0;">
  <tr style="background:#f9fafb;">
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;width:40%;">Request #</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{booking_number}}</td>
  </tr>
  <tr>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Unit</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;">{{unit}}</td>
  </tr>
  <tr style="background:#f9fafb;">
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Rental Period</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;">{{rental_start}} → {{rental_end}}</td>
  </tr>
  <tr>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Estimated Total</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{total}}</td>
  </tr>
  <tr style="background:#f9fafb;">
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Preferred Payment</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;">{{payment_method}}</td>
  </tr>
</table>
<p>Our team will review availability and follow up with approval details and payment instructions if needed.</p>',
    ],
    'invoice_ready' => [
        'name'    => 'Invoice Ready',
        'desc'    => 'Sent to customer when an invoice is emailed to them.',
        'vars'    => ['customer_name','invoice_number','amount','due_date','notes_block'],
        'default_subject'  => 'Invoice {{invoice_number}} from ' . get_setting('company_name', 'Trash Panda Roll-Offs'),
        'default_body_html'=> '<p>Hello {{customer_name}},</p>
<p>Your invoice <strong>{{invoice_number}}</strong> is ready.</p>
<table width="100%" style="border-collapse:collapse;font-size:.95rem;margin:16px 0;">
  <tr style="background:#f9fafb;">
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;width:40%;">Invoice #</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{invoice_number}}</td>
  </tr>
  <tr>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Amount Due</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;color:#f97316;font-weight:700;">{{amount}}</td>
  </tr>
  <tr style="background:#f9fafb;">
    <td style="padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;">Due Date</td>
    <td style="padding:10px 14px;border:1px solid #e5e7eb;">{{due_date}}</td>
  </tr>
</table>
{{notes_block}}
<p>You can review and pay this invoice using the link below.</p>',
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
];

// ── POST handler ──────────────────────────────────────────────────────────────
$flash_ok  = '';
$flash_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_template') {
        $slug     = trim($_POST['slug'] ?? '');
        $subject  = trim($_POST['subject'] ?? '');
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
        <p class="text-muted mb-0" style="font-size:.9rem;">Customize the emails sent to customers. Use <code>{{variable}}</code> placeholders shown for each template.</p>
    </div>
</div>

<?php if ($editing): ?>
<?php
    $def  = $TEMPLATE_DEFS[$editing];
    $curr = $saved[$editing] ?? null;
    $cur_subject  = $curr ? $curr['subject']   : $def['default_subject'];
    $cur_body     = $curr ? $curr['body_html']  : $def['default_body_html'];
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header d-flex align-items-center gap-2" style="background:#1a1d27;border-radius:8px 8px 0 0;">
        <a href="<?= APP_URL ?>/modules/email_templates/index.php" class="btn btn-sm btn-outline-secondary me-2">
            <i class="fas fa-arrow-left"></i>
        </a>
        <span style="color:#f97316;font-weight:700;"><?= e($def['name']) ?></span>
        <?php if ($curr): ?>
        <span class="badge bg-success ms-2">Custom</span>
        <?php else: ?>
        <span class="badge bg-secondary ms-2">Default</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3" style="font-size:.9rem;"><?= e($def['desc']) ?></p>

        <div class="mb-3">
            <label class="form-label fw-semibold">Available Variables</label>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($def['vars'] as $v): ?>
                <code class="bg-light px-2 py-1 rounded" style="font-size:.8rem;cursor:pointer;"
                      onclick="insertVar(this.textContent)"
                      title="Click to insert into body">{{<?= e($v) ?>}}</code>
                <?php endforeach; ?>
            </div>
            <small class="text-muted">Click a variable to insert it at the cursor in the body editor.</small>
        </div>

        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_template">
            <input type="hidden" name="slug" value="<?= e($editing) ?>">

            <div class="mb-3">
                <label class="form-label fw-semibold" for="tpl_subject">Subject Line</label>
                <input type="text" id="tpl_subject" name="subject" class="form-control"
                       value="<?= e($cur_subject) ?>" required>
                <small class="text-muted">You can use <code>{{variables}}</code> in the subject too.</small>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold" for="tpl_body">Body HTML</label>
                <textarea id="tpl_body" name="body_html" class="form-control font-monospace"
                          rows="20" style="font-size:.82rem;resize:vertical;" required><?= e($cur_body) ?></textarea>
                <small class="text-muted">This is the inner body of the email (inside the white box). HTML is supported. The header, footer, and CTA button are added automatically.</small>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Template
                </button>
                <?php if ($curr): ?>
                <button type="submit" form="reset-form-<?= e($editing) ?>" class="btn btn-outline-danger">
                    <i class="fas fa-rotate-left me-1"></i> Reset to Default
                </button>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/modules/email_templates/index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>

        <?php if ($curr): ?>
        <form id="reset-form-<?= e($editing) ?>" method="POST" action=""
              onsubmit="return confirm('Reset this template to the built-in default?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_template">
            <input type="hidden" name="slug" value="<?= e($editing) ?>">
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
function insertVar(v) {
    const ta = document.getElementById('tpl_body');
    if (!ta) return;
    const s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.slice(0, s) + v + ta.value.slice(e);
    ta.selectionStart = ta.selectionEnd = s + v.length;
    ta.focus();
}
</script>

<?php else: ?>

<div class="row g-3">
    <?php foreach ($TEMPLATE_DEFS as $slug => $def): ?>
    <?php $curr = $saved[$slug] ?? null; ?>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
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
                <div class="mb-2" style="font-size:.82rem;">
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

                <a href="?edit=<?= urlencode($slug) ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-pen me-1"></i> <?= $curr ? 'Edit' : 'Customize' ?>
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php layout_end(); ?>
