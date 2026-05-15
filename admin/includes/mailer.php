<?php
/**
 * Mailer – PHPMailer (preferred) or PHP mail() fallback
 * Trash Panda Roll-Offs
 *
 * When Composer dependencies are installed (composer install run inside admin/),
 * PHPMailer is used automatically for reliable SMTP delivery.
 * Otherwise the system falls back to PHP's built-in mail().
 *
 * SMTP settings are read from the admin settings table:
 *   smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption (tls|ssl|none)
 * Leave smtp_host blank to use PHP mail() even if PHPMailer is installed.
 */

// Load PHPMailer autoloader when available
$_tp_phpmailer_autoload = ROOT_PATH . '/vendor/autoload.php';
if (file_exists($_tp_phpmailer_autoload)) {
    require_once $_tp_phpmailer_autoload;
}
unset($_tp_phpmailer_autoload);

/**
 * Send an HTML email, using PHPMailer+SMTP when configured, otherwise mail().
 *
 * @param string $to
 * @param string $subject
 * @param string $html_body
 * @param string $from  Override From; defaults to company email from settings
 * @return bool
 */
function send_email(string $to, string $subject, string $html_body, string $from = ''): bool
{
    $from_name  = get_setting('email_from_name',  get_setting('company_name', 'Trash Panda Roll-Offs'));
    $from_email = get_setting('email_from_email', get_setting('company_email', 'noreply@example.com'));

    if (!empty($from)) {
        // Parse "Name <email>" if provided, otherwise use as-is for the email address
    if (preg_match('/^(.+?)\s*<(.+)>$/', $from, $m)) {
            $from_name  = trim($m[1]);
            $from_email = trim($m[2]);
        } else {
            $from_email = $from;
        }
    }

    $smtp_host = get_setting('smtp_host', '');

    // ── PHPMailer + SMTP ─────────────────────────────────────────────────────
    if (!empty($smtp_host) && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $smtp_host;
            $mail->Port       = (int) get_setting('smtp_port', 587);
            $mail->SMTPAuth   = true;
            $mail->Username   = get_setting('smtp_username', '');
            $mail->Password   = get_setting('smtp_password', '');

            $enc = strtolower(get_setting('smtp_encryption', 'tls'));
            if ($enc === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'tls') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($from_email, $from_name);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body    = $html_body;
            $mail->AltBody = strip_tags($html_body);

            $mail->send();
            _log_notification('email', $to, $subject, $html_body, 'sent');
            return true;
        } catch (\Throwable $e) {
            _log_notification('email', $to, $subject, $html_body, 'failed');
            return false;
        }
    }

    // ── PHP mail() fallback ──────────────────────────────────────────────────
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . $from_name . ' <' . $from_email . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $from_email . "\r\n";
    $headers .= 'X-Mailer: PHP/' . PHP_VERSION . "\r\n";

    $result = @mail($to, $subject, $html_body, $headers);

    _log_notification('email', $to, $subject, $html_body, $result ? 'sent' : 'failed');

    return (bool)$result;
}

/**
 * Send an email to every address listed in the admin notification_emails setting.
 * Comma-separated list. Silently skips invalid addresses.
 *
 * @param string $subject
 * @param string $html_body
 * @return int  Number of successfully sent messages
 */
function notify_admins(string $subject, string $html_body): int
{
    $raw     = get_setting('notification_emails', get_setting('company_email', ''));
    $emails  = array_filter(array_map('trim', explode(',', $raw)));
    $sent    = 0;
    foreach ($emails as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (send_email($email, $subject, $html_body)) {
                $sent++;
            }
        }
    }
    return $sent;
}

/**
 * Log a notification record to the notifications table.
 *
 * @param string $type    'email' or 'sms'
 * @param string $recipient
 * @param string $subject
 * @param string $body
 * @param string $status  'queued', 'sent', or 'failed'
 * @param string $related_type
 * @param int    $related_id
 */
function _log_notification(
    string $type,
    string $recipient,
    string $subject,
    string $body,
    string $status = 'sent',
    string $related_type = '',
    int $related_id = 0
): void {
    try {
        db_insert('notifications', [
            'type'         => $type,
            'recipient'    => $recipient,
            'subject'      => $subject,
            'body'         => $body,
            'status'       => $status,
            'related_type' => $related_type,
            'related_id'   => $related_id,
            'sent_at'      => $status === 'sent' ? date('Y-m-d H:i:s') : null,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        // Swallow DB errors so mailer does not break page rendering
    }
}

/**
 * Load an email template from DB; fall back to $defaults if not found.
 *
 * @param string $slug
 * @param array{subject:string,body_html:string} $defaults
 * @return array{subject:string,body_html:string}
 */
function get_email_template(string $slug, array $defaults): array
{
    try {
        $row = db_fetch('SELECT subject, body_html FROM email_templates WHERE slug = ?', [$slug]);
        if ($row && !empty($row['subject']) && !empty($row['body_html'])) {
            return ['subject' => (string)$row['subject'], 'body_html' => (string)$row['body_html']];
        }
    } catch (\Throwable $e) {
        // fall through to defaults
    }
    return $defaults;
}

/**
 * Replace {{variable}} placeholders in a template string.
 *
 * @param string $template
 * @param array<string,string> $vars
 * @return string
 */
function render_email_vars(string $template, array $vars): string
{
    foreach ($vars as $key => $val) {
        $template = str_replace('{{' . $key . '}}', (string)$val, $template);
    }
    return $template;
}

/**
 * Send a password reset email to an admin user.
 *
 * @param string $to        Recipient email address
 * @param string $name      Recipient display name
 * @param string $reset_url Full URL with token
 * @return bool
 */
function send_password_reset_email(string $to, string $name, string $reset_url): bool
{
    $body = '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>
<p>We received a request to reset the password for your admin account. Click the button below to choose a new password.</p>
<p style="text-align:center;padding:20px 0;">
  <a href="' . htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8') . '"
     style="background:#f97316;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:700;font-size:1rem;display:inline-block;">
    Reset My Password
  </a>
</p>
<p style="font-size:.85rem;color:#6b7280;">
  This link expires in <strong>1 hour</strong>. If you did not request a password reset, you can safely ignore this email — your password will not change.
</p>
<p style="font-size:.85rem;color:#6b7280;">
  If the button above does not work, copy and paste this URL into your browser:<br>
  <a href="' . htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8') . '" style="color:#f97316;word-break:break-all;">'
    . htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8') . '</a>
</p>';

    $html = email_template('Password Reset Request', $body);
    return send_email($to, 'Reset Your Password', $html);
}

/**
 * Generate a full HTML email template.
 *
 * @param string $title
 * @param string $body_html
 * @param string $cta_text   Optional call-to-action button text
 * @param string $cta_url    Optional call-to-action button URL
 * @return string
 */
function email_template(string $title, string $body_html, string $cta_text = '', string $cta_url = ''): string
{
    $company_name    = get_setting('company_name',    'Trash Panda Roll-Offs');
    $company_phone   = get_setting('company_phone',   '');
    $company_email   = get_setting('company_email',   '');
    $company_address = get_setting('company_address', '');

    $cta_block = '';
    if ($cta_text && $cta_url) {
        $cta_block = '
        <tr>
          <td align="center" style="padding:20px 0 10px;">
            <a href="' . htmlspecialchars($cta_url, ENT_QUOTES, 'UTF-8') . '"
               style="background:#f97316;color:#ffffff;text-decoration:none;padding:12px 28px;
                      border-radius:6px;font-weight:700;font-size:1rem;display:inline-block;">
              ' . htmlspecialchars($cta_text, ENT_QUOTES, 'UTF-8') . '
            </a>
          </td>
        </tr>';
    }

    $footer_parts = array_filter([$company_name, $company_phone, $company_email, $company_address]);
    $footer_text  = implode(' &bull; ', array_map('htmlspecialchars', $footer_parts));

    return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:30px 0;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

          <!-- Header -->
          <tr>
            <td style="background:#1a1d27;padding:24px 32px;border-radius:8px 8px 0 0;">' .
              (($logo = get_setting('logo_url', '') ?: get_setting('logo_path', ''))
                ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '"
                        alt="' . htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') . '"
                        style="max-height:55px;max-width:200px;object-fit:contain;display:block;margin-bottom:10px;">'
                : '<h1 style="margin:0 0 6px;color:#f97316;font-size:1.5rem;font-weight:700;letter-spacing:.04em;">'
                  . htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') . '</h1>') . '
              <p style="margin:0;color:#9ca3af;font-size:.85rem;">'
                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="background:#ffffff;padding:32px;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;">
              ' . $body_html . '
            </td>
          </tr>

          <!-- CTA -->
          ' . ($cta_block ? '<tr><td style="background:#ffffff;padding:0 32px;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;"><table width="100%">' . $cta_block . '</table></td></tr>' : '') . '

          <!-- Footer -->
          <tr>
            <td style="background:#f9fafb;padding:20px 32px;border:1px solid #e5e7eb;border-radius:0 0 8px 8px;
                       color:#9ca3af;font-size:.75rem;text-align:center;">
              ' . $footer_text . '<br>
              <span style="color:#d1d5db;">Powered by Trash Panda Roll-Offs Manager</span>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

/**
 * Send delivery reminder emails for work orders scheduled for tomorrow.
 */
function notify_delivery_tomorrow(): void
{
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    $wos = db_fetchall(
        "SELECT wo.*, c.email AS cust_email_db, c.name AS cust_name_db
         FROM work_orders wo
         LEFT JOIN customers c ON wo.customer_id = c.id
         WHERE wo.delivery_date = ? AND wo.status = 'scheduled'",
        [$tomorrow]
    );

    foreach ($wos as $wo) {
        $email = $wo['cust_email'] ?: ($wo['cust_email_db'] ?? '');
        if (empty($email)) {
            continue;
        }

        $name    = $wo['cust_name'] ?: ($wo['cust_name_db'] ?? 'Valued Customer');
        $address = $wo['service_address'] . ($wo['service_city'] ? ', ' . $wo['service_city'] : '');

        $vars = [
            'customer_name'    => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
            'delivery_date'    => date('l, F j, Y', strtotime($tomorrow)),
            'delivery_address' => htmlspecialchars($address, ENT_QUOTES, 'UTF-8'),
            'unit_size'        => htmlspecialchars($wo['size'] ?? 'N/A', ENT_QUOTES, 'UTF-8'),
        ];

        $tpl = get_email_template('delivery_tomorrow', [
            'subject'  => 'Your Dumpster Delivery Is Tomorrow!',
            'body_html' => '<p>Hello {{customer_name}},</p>
<p>This is a reminder that your dumpster delivery is scheduled for <strong>tomorrow, {{delivery_date}}</strong>.</p>
<p><strong>Delivery Address:</strong> {{delivery_address}}</p>
<p><strong>Dumpster Size:</strong> {{unit_size}}</p>
<p>Please ensure the area is accessible for our driver. If you need to reschedule, contact us as soon as possible.</p>
<p>Thank you for choosing us!</p>',
        ]);

        $subject = render_email_vars($tpl['subject'], $vars);
        $body    = render_email_vars($tpl['body_html'], $vars);

        $html = email_template('Delivery Reminder', $body);
        send_email($email, $subject, $html);

        // Push notification to customer
        if (function_exists('push_notify_customer')) {
            $wo_num = $wo['wo_number'] ?? '';
            foreach (array_filter([$email, !empty($wo['cust_phone']) ? preg_replace('/\D/', '', $wo['cust_phone']) : '']) as $push_id) {
                push_notify_customer(
                    $push_id,
                    '🚚 Delivery Tomorrow' . ($wo_num ? ' — ' . $wo_num : ''),
                    'Your dumpster delivery is scheduled for tomorrow at ' . htmlspecialchars($address, ENT_QUOTES, 'UTF-8') . '.'
                );
            }
        }
    }
}

/**
 * Send overdue pickup alerts to the internal company email.
 */
function notify_pickup_overdue(): void
{
    $today = date('Y-m-d');

    $wos = db_fetchall(
        "SELECT wo.*, c.email AS cust_email_db
         FROM work_orders wo
         LEFT JOIN customers c ON wo.customer_id = c.id
         WHERE wo.pickup_date < ? AND wo.status NOT IN ('picked_up','completed','canceled')
         ORDER BY wo.pickup_date ASC",
        [$today]
    );

    if (empty($wos)) {
        return;
    }

    $company_email = get_setting('company_email', '');
    if (empty($company_email)) {
        return;
    }

    $rows = '';
    foreach ($wos as $wo) {
        $rows .= '<tr>
          <td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($wo['wo_number'], ENT_QUOTES, 'UTF-8') . '</td>
          <td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($wo['cust_name'], ENT_QUOTES, 'UTF-8') . '</td>
          <td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($wo['service_address'], ENT_QUOTES, 'UTF-8') . '</td>
          <td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($wo['pickup_date'], ENT_QUOTES, 'UTF-8') . '</td>
          <td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($wo['status'], ENT_QUOTES, 'UTF-8') . '</td>
        </tr>';
    }

    $body = '<p>The following work orders have passed their scheduled pickup date and are still active:</p>
<table width="100%" style="border-collapse:collapse;font-size:.9rem;">
  <thead>
    <tr style="background:#f3f4f6;">
      <th style="padding:8px;border:1px solid #e5e7eb;text-align:left;">WO#</th>
      <th style="padding:8px;border:1px solid #e5e7eb;text-align:left;">Customer</th>
      <th style="padding:8px;border:1px solid #e5e7eb;text-align:left;">Address</th>
      <th style="padding:8px;border:1px solid #e5e7eb;text-align:left;">Pickup Date</th>
      <th style="padding:8px;border:1px solid #e5e7eb;text-align:left;">Status</th>
    </tr>
  </thead>
  <tbody>' . $rows . '</tbody>
</table>';

    $html = email_template('Overdue Pickup Alert', $body);
    send_email($company_email, 'Overdue Pickup Alert — ' . count($wos) . ' Work Orders', $html);
}

/**
 * ── Booking-specific Notification Helpers ────────────────────────────────────
 */

/**
 * Send a booking confirmation email to the customer.
 */
function notify_booking_confirmed(array $booking): void
{
    $email = trim($booking['customer_email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $name   = htmlspecialchars($booking['customer_name'] ?? 'Customer',  ENT_QUOTES, 'UTF-8');
    $bk_num = htmlspecialchars($booking['booking_number'] ?? '',          ENT_QUOTES, 'UTF-8');
    $unit   = htmlspecialchars(($booking['unit_code'] ?? '') . ' — ' . ($booking['unit_size'] ?? ''), ENT_QUOTES, 'UTF-8');
    $start  = !empty($booking['rental_start']) ? date('l, F j, Y', strtotime($booking['rental_start'])) : '—';
    $end    = !empty($booking['rental_end'])   ? date('l, F j, Y', strtotime($booking['rental_end']))   : '—';
    $days   = (int)($booking['rental_days'] ?? 0);
    $total  = fmt_money($booking['total_amount'] ?? 0);
    $method = htmlspecialchars(ucfirst($booking['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8');

    $vars = [
        'customer_name'  => $name,
        'booking_number' => $bk_num,
        'unit'           => $unit,
        'rental_start'   => $start,
        'rental_end'     => $end,
        'rental_days'    => (string)$days,
        'rental_days_s'  => $days !== 1 ? 's' : '',
        'total'          => $total,
        'payment_method' => $method,
    ];

    $tpl = get_email_template('booking_confirmed', [
        'subject'  => 'Booking Confirmed — {{booking_number}}',
        'body_html' => '<p>Hello {{customer_name}},</p>
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
    ]);

    $subject = render_email_vars($tpl['subject'], $vars);
    $body    = render_email_vars($tpl['body_html'], $vars);

    $html = email_template('Booking Confirmation — ' . $bk_num, $body);
    send_email($email, $subject, $html);

    // Also notify the company
    notify_admins(
        'New Booking — ' . $bk_num . ' (' . ($booking['customer_name'] ?? '') . ')',
        $body
    );

    // ── Push notifications ────────────────────────────────────────────────────
    if (function_exists('push_notify_admins')) {
        $admin_url = defined('APP_URL') ? APP_URL . '/modules/bookings/index.php' : '/admin/modules/bookings/index.php';
        push_notify_admins(
            '🗑️ New Booking — ' . $bk_num,
            ($booking['customer_name'] ?? 'Customer') . ' · ' . $total,
            $admin_url
        );
    }
    if (function_exists('push_notify_customer')) {
        $identifiers = array_filter([
            !empty($booking['customer_email']) ? strtolower(trim($booking['customer_email'])) : '',
            !empty($booking['customer_phone']) ? preg_replace('/\D/', '', $booking['customer_phone']) : '',
        ]);
        foreach ($identifiers as $id) {
            push_notify_customer($id, 'Booking Confirmed — ' . $bk_num, 'Your rental starts ' . $start);
        }
    }
}

/**
 * Send a booking request received email to the customer and admins.
 */
function notify_booking_request_received(array $booking): void
{
    $email  = trim($booking['customer_email'] ?? '');
    $bk_num = htmlspecialchars($booking['booking_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $name   = htmlspecialchars($booking['customer_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8');
    $unit   = htmlspecialchars(($booking['unit_code'] ?? '') . ' — ' . ($booking['unit_size'] ?? ''), ENT_QUOTES, 'UTF-8');
    $start  = !empty($booking['rental_start']) ? date('l, F j, Y', strtotime($booking['rental_start'])) : '—';
    $end    = !empty($booking['rental_end'])   ? date('l, F j, Y', strtotime($booking['rental_end']))   : '—';
    $total  = fmt_money($booking['total_amount'] ?? 0);
    $method = htmlspecialchars(payment_method_label($booking['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8');

    $vars = [
        'customer_name'  => $name,
        'booking_number' => $bk_num,
        'unit'           => $unit,
        'rental_start'   => $start,
        'rental_end'     => $end,
        'total'          => $total,
        'payment_method' => $method,
    ];

    $tpl = get_email_template('booking_request_received', [
        'subject'  => 'Booking Request Received — {{booking_number}}',
        'body_html' => '<p>Hello {{customer_name}},</p>
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
    ]);

    $subject = render_email_vars($tpl['subject'], $vars);
    $body    = render_email_vars($tpl['body_html'], $vars);

    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $html = email_template('Booking Request Received — ' . $bk_num, $body);
        send_email($email, $subject, $html);
    }

    notify_admins(
        'New Booking Request — ' . $bk_num . ' (' . ($booking['customer_name'] ?? '') . ')',
        $body
    );
}

/**
 * Send an invoice email to the customer.
 */
function send_invoice_email_to_customer(array $invoice): bool
{
    $email = trim((string)($invoice['cust_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $invoiceNumber = htmlspecialchars((string)($invoice['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $customerName = htmlspecialchars((string)($invoice['cust_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8');
    $amount = fmt_money($invoice['total'] ?? 0);
    $dueDate = !empty($invoice['due_date']) ? fmt_date((string)$invoice['due_date']) : 'upon receipt';
    $paymentLink = trim((string)($invoice['stripe_payment_link'] ?? ''));
    $notes = trim((string)($invoice['notes'] ?? ''));

    $notesBlock = $notes !== ''
        ? '<p><strong>Notes:</strong><br>' . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) . '</p>'
        : '';

    $vars = [
        'customer_name'  => $customerName,
        'invoice_number' => $invoiceNumber,
        'amount'         => htmlspecialchars($amount, ENT_QUOTES, 'UTF-8'),
        'due_date'       => htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8'),
        'notes_block'    => $notesBlock,
    ];

    $tpl = get_email_template('invoice_ready', [
        'subject'  => 'Invoice {{invoice_number}} from ' . get_setting('company_name', 'Trash Panda Roll-Offs'),
        'body_html' => '<p>Hello {{customer_name}},</p>
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
    ]);

    $subject = render_email_vars($tpl['subject'], $vars);
    $body    = render_email_vars($tpl['body_html'], $vars);

    $html = email_template(
        'Invoice ' . (string)($invoice['invoice_number'] ?? ''),
        $body,
        $paymentLink !== '' ? 'View & Pay Invoice' : '',
        $paymentLink !== '' ? $paymentLink : ''
    );

    $sent = send_email($email, $subject, $html);

    if ($sent) {
        notify_admins(
            'Invoice Sent - ' . (string)($invoice['invoice_number'] ?? ''),
            '<p>Invoice <strong>' . $invoiceNumber . '</strong> was sent to ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.</p>'
        );
    }

    return $sent;
}

/**
 * Send a rental expiry reminder to the customer (typically 3 days before end).
 */
function notify_booking_expiry_reminder(array $booking): void
{
    $email = trim($booking['customer_email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $name   = htmlspecialchars($booking['customer_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8');
    $bk_num = htmlspecialchars($booking['booking_number'] ?? '',         ENT_QUOTES, 'UTF-8');
    $unit   = htmlspecialchars(($booking['unit_code'] ?? '') . ' — ' . ($booking['unit_size'] ?? ''), ENT_QUOTES, 'UTF-8');
    $end    = !empty($booking['rental_end']) ? date('l, F j, Y', strtotime($booking['rental_end'])) : '—';

    $company_phone = get_setting('company_phone', '');
    $phone_html = $company_phone !== ''
        ? '<p>To schedule a pickup or extend your rental, please call us at <strong>' . htmlspecialchars($company_phone, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
        : '<p>Please contact us to schedule a pickup or extend your rental.</p>';

    $vars = [
        'customer_name'  => $name,
        'booking_number' => $bk_num,
        'unit'           => $unit,
        'rental_end'     => $end,
        'phone_block'    => $phone_html,
    ];

    $tpl = get_email_template('rental_ending_soon', [
        'subject'  => 'Your Dumpster Rental Ends on {{rental_end}} — {{booking_number}}',
        'body_html' => '<p>Hello {{customer_name}},</p>
<p>This is a friendly reminder that your dumpster rental (<strong>{{booking_number}}</strong>) is scheduled to end on <strong>{{rental_end}}</strong>.</p>
<p><strong>Unit:</strong> {{unit}}</p>
{{phone_block}}
<p>If you do not need to extend, please ensure the dumpster is ready for pickup by the end date.</p>
<p>Thank you!</p>',
    ]);

    $subject = render_email_vars($tpl['subject'], $vars);
    $body    = render_email_vars($tpl['body_html'], $vars);

    $html = email_template('Rental Ending Soon — ' . $bk_num, $body);
    send_email($email, $subject, $html);

    // ── Push notifications ────────────────────────────────────────────────────
    if (function_exists('push_notify_customer')) {
        $identifiers = array_filter([
            filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : '',
            !empty($booking['customer_phone']) ? preg_replace('/\D/', '', $booking['customer_phone']) : '',
        ]);
        foreach ($identifiers as $id) {
            push_notify_customer($id, '⏰ Rental Ending Soon — ' . $bk_num, 'Your rental ends on ' . $end . '. Contact us to extend or schedule pickup.');
        }
    }
}

/**
 * Send a booking cancellation notice to the customer.
 */
function notify_booking_cancelled(array $booking): void
{
    $email = trim($booking['customer_email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $name   = htmlspecialchars($booking['customer_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8');
    $bk_num = htmlspecialchars($booking['booking_number'] ?? '',         ENT_QUOTES, 'UTF-8');

    $vars = [
        'customer_name'  => $name,
        'booking_number' => $bk_num,
    ];

    $tpl = get_email_template('booking_cancelled', [
        'subject'  => 'Booking Cancelled — {{booking_number}}',
        'body_html' => '<p>Hello {{customer_name}},</p>
<p>Your booking <strong>{{booking_number}}</strong> has been <strong>cancelled</strong>.</p>
<p>If you believe this was done in error or have questions, please contact us.</p>
<p>Thank you for your business.</p>',
    ]);

    $subject = render_email_vars($tpl['subject'], $vars);
    $body    = render_email_vars($tpl['body_html'], $vars);

    $html = email_template('Booking Cancelled — ' . $bk_num, $body);
    send_email($email, $subject, $html);

    // ── Push notifications ────────────────────────────────────────────────────
    if (function_exists('push_notify_customer')) {
        $identifiers = array_filter([
            filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : '',
            !empty($booking['customer_phone']) ? preg_replace('/\D/', '', $booking['customer_phone']) : '',
        ]);
        foreach ($identifiers as $id) {
            push_notify_customer($id, 'Booking Cancelled — ' . $bk_num, 'Your booking has been cancelled. Contact us if this was an error.');
        }
    }
}
