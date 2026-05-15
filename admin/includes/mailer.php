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
function send_email(string $to, string $subject, string $html_body, string $from = '', array $attachments = []): bool
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
            foreach ($attachments as $attachment) {
                $name = (string)($attachment['name'] ?? 'attachment.bin');
                $type = (string)($attachment['type'] ?? 'application/octet-stream');
                $content = $attachment['content'] ?? null;
                if (!is_string($content) || $content === '') {
                    continue;
                }
                $mail->addStringAttachment($content, $name, 'base64', $type);
            }

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
    $headers .= 'From: ' . $from_name . ' <' . $from_email . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $from_email . "\r\n";
    $headers .= 'X-Mailer: PHP/' . PHP_VERSION . "\r\n";

    if (!empty($attachments)) {
        $boundary = 'tp_mail_' . bin2hex(random_bytes(12));
        $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";

        $body  = '--' . $boundary . "\r\n";
        $body .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
        $body .= chunk_split(base64_encode($html_body)) . "\r\n";

        foreach ($attachments as $attachment) {
            $name = (string)($attachment['name'] ?? 'attachment.bin');
            $type = (string)($attachment['type'] ?? 'application/octet-stream');
            $content = $attachment['content'] ?? null;
            if (!is_string($content) || $content === '') {
                continue;
            }

            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Type: ' . $type . '; name="' . addslashes($name) . '"' . "\r\n";
            $body .= 'Content-Disposition: attachment; filename="' . addslashes($name) . '"' . "\r\n";
            $body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
            $body .= chunk_split(base64_encode($content)) . "\r\n";
        }

        $body .= '--' . $boundary . '--';
        $result = @mail($to, $subject, $body, $headers);
    } else {
        $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $result = @mail($to, $subject, $html_body, $headers);
    }

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

function booking_group_rows(array $booking): array
{
    $bookingNumber = trim((string)($booking['booking_number'] ?? ''));
    if ($bookingNumber === '') {
        return [$booking];
    }

    $rows = db_fetchall('SELECT * FROM bookings WHERE booking_number = ? ORDER BY id', [$bookingNumber]);
    return !empty($rows) ? $rows : [$booking];
}

function booking_group_summary(array $booking): array
{
    $rows = booking_group_rows($booking);
    $items = [];
    $unitLabels = [];
    $total = 0.0;

    foreach ($rows as $row) {
        $unitCode = trim((string)($row['unit_code'] ?? ''));
        $unitSize = trim((string)($row['unit_size'] ?? ''));
        $label = trim($unitCode . ($unitSize !== '' ? ' - ' . $unitSize : ''));
        if ($label === '') {
            $label = 'Dumpster rental';
        }
        $unitLabels[] = $label;
        $items[] = '<div style="padding:6px 0;border-bottom:1px solid #e5e7eb;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</div>';
        $total += (float)($row['total_amount'] ?? 0);
    }

    return [
        'rows' => $rows,
        'count' => count($rows),
        'unit_text' => htmlspecialchars(implode(', ', $unitLabels), ENT_QUOTES, 'UTF-8'),
        'unit_html' => implode('', $items),
        'total' => $total,
    ];
}

function booking_terms_download_url(array $booking): string
{
    $rows = booking_group_rows($booking);
    $ids = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows);
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    $idsStr = implode(',', $ids);
    $token = hash_hmac('sha256', $idsStr, defined('PORTAL_SIGNING_KEY') ? PORTAL_SIGNING_KEY : 'booking-token-secret');
    return '/download-terms.php?ids=' . urlencode($idsStr) . '&token=' . urlencode($token);
}

function resolve_logo_file_path(): ?string
{
    $candidates = [];
    foreach ([get_setting('logo_path', ''), get_setting('logo_url', '')] as $value) {
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }

        $path = parse_url($value, PHP_URL_PATH);
        $path = is_string($path) ? $path : $value;
        $path = ltrim($path, '/');

        $roots = array_filter([
            defined('ROOT_PATH') ? ROOT_PATH : null,
            defined('ROOT_PATH') ? dirname(ROOT_PATH) : null,
            defined('ROOT_PATH') ? dirname(ROOT_PATH) . '/public' : null,
            $_SERVER['DOCUMENT_ROOT'] ?? null,
        ]);

        foreach ($roots as $root) {
            $candidates[] = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }
    }

    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function booking_terms_logo_jpeg(): ?array
{
    $logoPath = resolve_logo_file_path();
    if ($logoPath === null) {
        return null;
    }

    $info = @getimagesize($logoPath);
    if (!$info) {
        return null;
    }

    $type = (int)($info[2] ?? 0);
    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    if ($width <= 0 || $height <= 0) {
        return null;
    }

    if ($type === IMAGETYPE_JPEG) {
        $binary = @file_get_contents($logoPath);
        if (is_string($binary) && $binary !== '') {
            return ['binary' => $binary, 'width' => $width, 'height' => $height];
        }
    }

    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return null;
    }

    $raw = @file_get_contents($logoPath);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return null;
    }

    $canvas = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopy($canvas, $src, 0, 0, 0, 0, $width, $height);

    ob_start();
    imagejpeg($canvas, null, 88);
    $binary = (string)ob_get_clean();
    imagedestroy($canvas);
    imagedestroy($src);

    if ($binary === '') {
        return null;
    }

    return ['binary' => $binary, 'width' => $width, 'height' => $height];
}

function pdf_escape_text(string $text): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function pdf_wrap_lines(string $text, int $lineLength = 88): array
{
    $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
    $wrapped = [];
    foreach ($lines as $line) {
        $normalized = rtrim($line);
        if ($normalized === '') {
            $wrapped[] = ' ';
            continue;
        }

        $parts = explode("\n", wordwrap($normalized, $lineLength, "\n", false));
        foreach ($parts as $part) {
            $wrapped[] = $part === '' ? ' ' : $part;
        }
    }
    return !empty($wrapped) ? $wrapped : [' '];
}

function booking_terms_pdf_attachment(array $booking): ?array
{
    $termsText = trim((string)get_setting('booking_terms', ''));
    if ($termsText === '') {
        return null;
    }

    $companyName = (string)get_setting('company_name', 'Trash Panda Roll-Offs');
    $acceptedDate = !empty($booking['terms_accepted_at'])
        ? date('F j, Y', strtotime((string)$booking['terms_accepted_at']))
        : date('F j, Y');
    $customerName = (string)($booking['customer_name'] ?? 'Customer');
    $bookingNumber = (string)($booking['booking_number'] ?? '');

    $logo = booking_terms_logo_jpeg();
    $textLines = pdf_wrap_lines($termsText, 84);
    $firstPageLines = 29;
    $continuationPageLines = 43;
    $lineChunks = array_chunk($textLines, $firstPageLines);
    if (!empty($lineChunks)) {
        $firstChunk = array_shift($lineChunks);
    } else {
        $firstChunk = [' '];
    }

    $pageLineSets = [$firstChunk];
    foreach ($lineChunks as $chunk) {
        foreach (array_chunk($chunk, $continuationPageLines) as $continuedChunk) {
            $pageLineSets[] = $continuedChunk;
        }
    }

    $totalPages = count($pageLineSets);
    $pageStreams = [];

    foreach ($pageLineSets as $pageIndex => $pageLines) {
        $isFirstPage = $pageIndex === 0;
        $pageNumber = $pageIndex + 1;
        $content = [];
        $content[] = '0.08 0.1 0.16 rg 0 0 612 792 re f';
        $content[] = '0.97 0.45 0.09 rg 0 708 612 84 re f';
        $content[] = '1 1 1 rg';
        $content[] = 'BT /F2 24 Tf 48 752 Td (' . pdf_escape_text('Terms & Conditions') . ') Tj ET';
        $content[] = 'BT /F1 11 Tf 48 730 Td (' . pdf_escape_text($companyName) . ') Tj ET';

        if ($isFirstPage && $logo !== null) {
            $maxWidth = 132.0;
            $scale = min(1.0, $maxWidth / max(1, (float)$logo['width']));
            $displayW = round($logo['width'] * $scale, 2);
            $displayH = round($logo['height'] * $scale, 2);
            $x = round(612 - 48 - $displayW, 2);
            $y = round(720 + ((72 - $displayH) / 2), 2);
            $content[] = 'q ' . $displayW . ' 0 0 ' . $displayH . ' ' . $x . ' ' . $y . ' cm /Im1 Do Q';
        }

        if ($isFirstPage) {
            $content[] = '1 1 1 rg 36 54 540 624 re f';
            $content[] = '0.87 0.89 0.93 RG 1 w 36 54 540 624 re S';

            $content[] = '0.95 0.96 0.98 rg 54 592 504 64 re f';
            $content[] = '0.9 0.92 0.95 RG 1 w 54 592 504 64 re S';
            $content[] = '0.15 0.18 0.24 rg';
            $content[] = 'BT /F2 10 Tf 70 632 Td (' . pdf_escape_text('Accepted Date') . ') Tj ET';
            $content[] = 'BT /F1 11 Tf 70 615 Td (' . pdf_escape_text($acceptedDate) . ') Tj ET';
            $content[] = 'BT /F2 10 Tf 250 632 Td (' . pdf_escape_text('Customer') . ') Tj ET';
            $content[] = 'BT /F1 11 Tf 250 615 Td (' . pdf_escape_text($customerName) . ') Tj ET';
            $content[] = 'BT /F2 10 Tf 430 632 Td (' . pdf_escape_text('Booking #') . ') Tj ET';
            $content[] = 'BT /F1 11 Tf 430 615 Td (' . pdf_escape_text($bookingNumber !== '' ? $bookingNumber : 'N/A') . ') Tj ET';

            $content[] = '0.11 0.13 0.18 rg';
            $content[] = 'BT /F2 13 Tf 54 565 Td (' . pdf_escape_text('Agreement Summary') . ') Tj ET';
            $introLines = [
                'This document records the Terms and Conditions accepted for this dumpster rental booking.',
                'Please keep this copy with your booking records for future reference.',
            ];
            $introY = 545;
            foreach ($introLines as $line) {
                $content[] = 'BT /F1 10.5 Tf 54 ' . $introY . ' Td (' . pdf_escape_text($line) . ') Tj ET';
                $introY -= 15;
            }

            $content[] = '0.99 0.99 1 rg 54 92 504 420 re f';
            $content[] = '0.9 0.92 0.95 RG 1 w 54 92 504 420 re S';
            $content[] = '0.97 0.45 0.09 rg 54 482 504 30 re f';
            $content[] = '1 1 1 rg';
            $content[] = 'BT /F2 12 Tf 68 493 Td (' . pdf_escape_text('Accepted Terms') . ') Tj ET';

            $content[] = '0.16 0.18 0.24 rg';
            $textY = 463;
            foreach ($pageLines as $line) {
                $content[] = 'BT /F1 9.6 Tf 68 ' . $textY . ' Td (' . pdf_escape_text($line) . ') Tj ET';
                $textY -= 12.5;
            }
        } else {
            $content[] = '1 1 1 rg 36 54 540 624 re f';
            $content[] = '0.87 0.89 0.93 RG 1 w 36 54 540 624 re S';
            $content[] = '0.99 0.99 1 rg 54 92 504 564 re f';
            $content[] = '0.9 0.92 0.95 RG 1 w 54 92 504 564 re S';
            $content[] = '0.97 0.45 0.09 rg 54 626 504 30 re f';
            $content[] = '1 1 1 rg';
            $content[] = 'BT /F2 12 Tf 68 637 Td (' . pdf_escape_text('Accepted Terms (Continued)') . ') Tj ET';
            $content[] = '0.16 0.18 0.24 rg';

            $textY = 608;
            foreach ($pageLines as $line) {
                $content[] = 'BT /F1 9.6 Tf 68 ' . $textY . ' Td (' . pdf_escape_text($line) . ') Tj ET';
                $textY -= 12.5;
            }
        }

        $content[] = '0.45 0.49 0.56 rg';
        $content[] = 'BT /F1 8.5 Tf 54 70 Td (' . pdf_escape_text('Generated automatically by ' . $companyName . ' on ' . date('F j, Y')) . ') Tj ET';
        $content[] = 'BT /F1 8.5 Tf 500 70 Td (' . pdf_escape_text('Page ' . $pageNumber . ' of ' . $totalPages) . ') Tj ET';

        $pageStreams[] = implode("\n", $content) . "\n";
    }

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    $objects = [];
    $pageCount = count($pageStreams);
    $pageObjectStart = 3;
    $font1ObjectNumber = $pageObjectStart + $pageCount;
    $font2ObjectNumber = $font1ObjectNumber + 1;
    $nextObjectNumber = $font2ObjectNumber + 1;
    $imageObjectNumber = 0;
    $imageObject = '';

    if ($logo !== null) {
        $imageObjectNumber = $nextObjectNumber;
        $nextObjectNumber++;
        $imageBinary = $logo['binary'];
        $imageObject = $imageObjectNumber . " 0 obj\n<< /Type /XObject /Subtype /Image /Width {$logo['width']} /Height {$logo['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($imageBinary) . " >>\nstream\n" . $imageBinary . "\nendstream\nendobj\n";
    }

    $contentObjectNumbers = [];
    foreach ($pageStreams as $_stream) {
        $contentObjectNumbers[] = $nextObjectNumber;
        $nextObjectNumber++;
    }

    $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

    $pageKids = [];
    for ($i = 0; $i < $pageCount; $i++) {
        $pageKids[] = ($pageObjectStart + $i) . ' 0 R';
    }
    $objects[] = "2 0 obj\n<< /Type /Pages /Count {$pageCount} /Kids [" . implode(' ', $pageKids) . "] >>\nendobj\n";

    for ($i = 0; $i < $pageCount; $i++) {
        $resourceExtra = ($i === 0 && $imageObjectNumber > 0)
            ? ' /XObject << /Im1 ' . $imageObjectNumber . ' 0 R >>'
            : '';
        $objects[] = ($pageObjectStart + $i) . " 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 {$font1ObjectNumber} 0 R /F2 {$font2ObjectNumber} 0 R >>{$resourceExtra} >> /Contents {$contentObjectNumbers[$i]} 0 R >>\nendobj\n";
    }

    $objects[] = $font1ObjectNumber . " 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
    $objects[] = $font2ObjectNumber . " 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";

    if ($imageObject !== '') {
        $objects[] = $imageObject;
    }

    foreach ($pageStreams as $i => $stream) {
        $objects[] = $contentObjectNumbers[$i] . " 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream\nendobj\n";
    }

    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($offsets) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . (count($offsets) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

    return [
        'name' => 'terms-and-conditions-' . preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($bookingNumber !== '' ? $bookingNumber : 'booking')) . '.pdf',
        'type' => 'application/pdf',
        'content' => $pdf,
    ];
}

function send_customer_portal_link_email(array $customer): bool
{
    $email = trim((string)($customer['email'] ?? ''));
    $customerId = (int)($customer['id'] ?? 0);
    if ($email === '' || $customerId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $token = billing_portal_access_service()->issueTokenForCustomer($customerId, (int)get_setting('portal_link_ttl_minutes', '30'));
    $basePublicUrl = preg_replace('#/admin$#', '', APP_URL);
    $portalUrl = rtrim((string)$basePublicUrl, '/') . '/portal/index.php?customer_id=' . $customerId . '&token=' . urlencode($token);
    $html = email_template(
        'Customer Billing Portal',
        '<p>Your secure Trash Panda billing portal link is ready.</p><p>This link expires automatically.</p>',
        'Open Billing Portal',
        $portalUrl
    );

    $sent = send_email($email, 'Your Trash Panda Billing Portal Link', $html);
    if ($sent) {
        log_activity(
            'portal_link_emailed',
            'Sent customer portal access link to ' . $email . '.',
            'customer',
            $customerId
        );
    }

    return $sent;
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

        // Skip if already sent today for this work order
        $already = db_fetchone(
            "SELECT id FROM activity_log
             WHERE action = 'delivery_reminder_emailed'
               AND entity_type = 'work_order'
               AND entity_id = ?
               AND created_at >= CURDATE()
             LIMIT 1",
            [$wo['id']]
        );
        if ($already) {
            continue;
        }

        $html = email_template('Delivery Reminder', $body);
        $sent = send_email($email, $subject, $html);

        if ($sent) {
            log_activity('delivery_reminder_emailed', 'Delivery reminder sent to ' . $email . '.', 'work_order', $wo['id']);
        }

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

    // Only send once per day
    $already = db_fetchone(
        "SELECT id FROM activity_log
         WHERE action = 'overdue_pickup_emailed'
           AND created_at >= CURDATE()
         LIMIT 1",
        []
    );
    if ($already) {
        return;
    }

    $html = email_template('Overdue Pickup Alert', $body);
    $sent = send_email($company_email, 'Overdue Pickup Alert — ' . count($wos) . ' Work Orders', $html);

    if ($sent) {
        log_activity('overdue_pickup_emailed', 'Overdue pickup alert sent to ' . $company_email . ' (' . count($wos) . ' work orders).');
    }
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

    $summary = booking_group_summary($booking);
    $name   = htmlspecialchars($booking['customer_name'] ?? 'Customer',  ENT_QUOTES, 'UTF-8');
    $bk_num = htmlspecialchars($booking['booking_number'] ?? '',          ENT_QUOTES, 'UTF-8');
    $unit   = $summary['unit_text'];
    $unitHtml = $summary['count'] > 1 ? $summary['unit_html'] : $unit;
    $start  = !empty($booking['rental_start']) ? date('l, F j, Y', strtotime($booking['rental_start'])) : '—';
    $end    = !empty($booking['rental_end'])   ? date('l, F j, Y', strtotime($booking['rental_end']))   : '—';
    $days   = (int)($booking['rental_days'] ?? 0);
    $total  = fmt_money($summary['total']);
    $method = htmlspecialchars(ucfirst($booking['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8');

    $vars = [
        'customer_name'  => $name,
        'booking_number' => $bk_num,
        'unit'           => $unitHtml,
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

    $attachments = [];
    if (!empty($booking['terms_accepted_at'])) {
        $ta_time = date('F j, Y', strtotime($booking['terms_accepted_at']));
        $body .= '<p style="font-size:.75rem;color:#9ca3af;margin-top:1.5rem;padding-top:1rem;'
               . 'border-top:1px solid #e5e7eb;">'
               . 'Terms &amp; Conditions accepted on ' . $ta_time . '.'
               . '</p>';
        $termsAttachment = booking_terms_pdf_attachment($booking);
        if ($termsAttachment !== null) {
            $attachments[] = $termsAttachment;
        }
    }

    $html = email_template('Booking Confirmation — ' . $bk_num, $body);
    send_email($email, $subject, $html, '', $attachments);
    log_activity(
        'booking_confirmation_emailed',
        'Sent booking confirmation email for ' . ($booking['booking_number'] ?? '') . '.',
        'booking',
        (int)($booking['id'] ?? 0)
    );

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
    $summary = booking_group_summary($booking);
    $bk_num = htmlspecialchars($booking['booking_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $name   = htmlspecialchars($booking['customer_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8');
    $unit   = $summary['unit_text'];
    $unitHtml = $summary['count'] > 1 ? $summary['unit_html'] : $unit;
    $start  = !empty($booking['rental_start']) ? date('l, F j, Y', strtotime($booking['rental_start'])) : '—';
    $end    = !empty($booking['rental_end'])   ? date('l, F j, Y', strtotime($booking['rental_end']))   : '—';
    $total  = fmt_money($summary['total']);
    $method = htmlspecialchars(payment_method_label($booking['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8');

    $vars = [
        'customer_name'  => $name,
        'booking_number' => $bk_num,
        'unit'           => $unitHtml,
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
        $attachments = [];
        if (!empty($booking['terms_accepted_at'])) {
            $body .= '<p style="font-size:.75rem;color:#9ca3af;margin-top:1.5rem;padding-top:1rem;'
                   . 'border-top:1px solid #e5e7eb;">'
                   . 'Terms &amp; Conditions accepted on ' . date('F j, Y', strtotime($booking['terms_accepted_at'])) . '.'
                   . '</p>';
            $termsAttachment = booking_terms_pdf_attachment($booking);
            if ($termsAttachment !== null) {
                $attachments[] = $termsAttachment;
            }
        }
        $html = email_template('Booking Request Received — ' . $bk_num, $body);
        send_email($email, $subject, $html, '', $attachments);
        log_activity(
            'booking_request_emailed',
            'Sent booking request received email for ' . ($booking['booking_number'] ?? '') . '.',
            'booking',
            (int)($booking['id'] ?? 0)
        );
    }

    notify_admins(
        'New Booking Request — ' . $bk_num . ' (' . ($booking['customer_name'] ?? '') . ')',
        $body
    );
}

function notify_booking_approved(array $booking, array $invoice = []): bool
{
    $email = trim((string)($booking['customer_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $summary = booking_group_summary($booking);
    $bkNum = htmlspecialchars((string)($booking['booking_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars((string)($booking['customer_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8');
    $unitHtml = $summary['count'] > 1 ? $summary['unit_html'] : $summary['unit_text'];
    $start = !empty($booking['rental_start']) ? date('l, F j, Y', strtotime((string)$booking['rental_start'])) : '—';
    $end = !empty($booking['rental_end']) ? date('l, F j, Y', strtotime((string)$booking['rental_end'])) : '—';
    $days = (int)($booking['rental_days'] ?? 0);
    $total = fmt_money($summary['total']);
    $paymentMethod = payment_method_label((string)($booking['payment_method'] ?? ''));
    $paymentLink = trim((string)($invoice['stripe_payment_link'] ?? ''));
    $invoiceNumber = htmlspecialchars((string)($invoice['invoice_number'] ?? 'Pending'), ENT_QUOTES, 'UTF-8');
    $methodSlug = strtolower(trim((string)($booking['payment_method'] ?? '')));

    $paymentMessage = '<p>Your invoice is ready. Use the button below to review and pay online.</p>';
    if (in_array($methodSlug, ['cash', 'check'], true) && $paymentLink !== '') {
        $paymentMessage = '<p>Your invoice is ready. You can pay online using the button below, or you can still pay by '
            . htmlspecialchars($paymentMethod, ENT_QUOTES, 'UTF-8')
            . ' if that is your preference.</p>';
    } elseif (in_array($methodSlug, ['cash', 'check'], true)) {
        $paymentMessage = '<p>Your booking is approved. You can still pay by '
            . htmlspecialchars($paymentMethod, ENT_QUOTES, 'UTF-8')
            . ' as requested.</p>';
    }

    $vars = [
        'customer_name' => $name,
        'booking_number' => $bkNum,
        'invoice_number' => $invoiceNumber,
        'unit' => $unitHtml,
        'rental_start' => $start,
        'rental_end' => $end,
        'rental_days' => (string)$days,
        'rental_days_s' => $days !== 1 ? 's' : '',
        'total' => $total,
        'payment_method' => htmlspecialchars($paymentMethod, ENT_QUOTES, 'UTF-8'),
        'payment_message' => $paymentMessage,
    ];

    $tpl = get_email_template('booking_approved', [
        'subject' => 'Booking Approved — {{booking_number}}',
        'body_html' => '<p>Hello {{customer_name}},</p><p>Your dumpster rental request has been <strong>approved</strong>.</p><p>{{payment_message}}</p>',
    ]);

    $subject = render_email_vars($tpl['subject'], $vars);
    $body = render_email_vars($tpl['body_html'], $vars);

    if (!empty($booking['terms_accepted_at'])) {
        $body .= '<p style="font-size:.75rem;color:#9ca3af;margin-top:1.5rem;padding-top:1rem;border-top:1px solid #e5e7eb;">'
            . 'Terms &amp; Conditions accepted on ' . date('F j, Y', strtotime((string)$booking['terms_accepted_at'])) . '. '
            . '<a href="' . htmlspecialchars(booking_terms_download_url($booking), ENT_QUOTES, 'UTF-8') . '" style="color:#f97316;">Download terms PDF</a>.'
            . '</p>';
    }

    $attachments = [];
    $termsAttachment = booking_terms_pdf_attachment($booking);
    if ($termsAttachment !== null) {
        $attachments[] = $termsAttachment;
    }

    $html = email_template(
        'Booking Approved — ' . (string)($booking['booking_number'] ?? ''),
        $body,
        $paymentLink !== '' ? 'View Booking Invoice' : '',
        $paymentLink !== '' ? $paymentLink : ''
    );

    $sent = send_email($email, $subject, $html, '', $attachments);
    if ($sent) {
        log_activity(
            'booking_approved_emailed',
            'Sent booking approval email for ' . ($booking['booking_number'] ?? '') . '.',
            'booking',
            (int)($booking['id'] ?? 0)
        );
    }

    return $sent;
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
    $paymentMethod = strtolower(trim((string)($invoice['payment_method'] ?? '')));
    $isOfflineFriendly = in_array($paymentMethod, ['cash', 'check'], true);

    $notesBlock = $notes !== ''
        ? '<p><strong>Notes:</strong><br>' . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) . '</p>'
        : '';

    $paymentInstructions = '<p>You can review and pay this invoice using the link below.</p>';
    if ($isOfflineFriendly && $paymentLink !== '') {
        $paymentInstructions = '<p>You can pay this invoice online using the link below, or you can still pay by '
            . htmlspecialchars($paymentMethod, ENT_QUOTES, 'UTF-8')
            . ' if that is your preference.</p>';
    } elseif ($isOfflineFriendly) {
        $paymentInstructions = '<p>You can pay this invoice by '
            . htmlspecialchars($paymentMethod, ENT_QUOTES, 'UTF-8')
            . '. If you would prefer to pay online instead, please contact us and we can help.</p>';
    }

    $vars = [
        'customer_name'  => $customerName,
        'invoice_number' => $invoiceNumber,
        'amount'         => htmlspecialchars($amount, ENT_QUOTES, 'UTF-8'),
        'due_date'       => htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8'),
        'notes_block'    => $notesBlock,
        'payment_instructions' => $paymentInstructions,
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
  {{payment_instructions}}',
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

/**
 * Email the customer when a work order status changes to delivered or picked_up/completed.
 */
function notify_work_order_status_change(array $wo, string $new_status): void
{
    $email = trim($wo['cust_email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $name    = htmlspecialchars($wo['cust_name'] ?? 'Valued Customer', ENT_QUOTES, 'UTF-8');
    $address = htmlspecialchars(
        trim(($wo['service_address'] ?? '') . ($wo['service_city'] ? ', ' . $wo['service_city'] : '')),
        ENT_QUOTES, 'UTF-8'
    );
    $size   = htmlspecialchars($wo['size'] ?? '', ENT_QUOTES, 'UTF-8');
    $wo_num = htmlspecialchars($wo['wo_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $phone  = get_setting('company_phone', '');
    $phone_html = $phone
        ? ' at <strong>' . htmlspecialchars(fmt_phone($phone), ENT_QUOTES, 'UTF-8') . '</strong>'
        : '';

    if ($new_status === 'delivered') {
        $tpl = get_email_template('work_order_delivered', [
            'subject'   => 'Your Dumpster Has Been Delivered!',
            'body_html' => '<p>Hello {{customer_name}},</p>
<p>Your dumpster has been delivered to <strong>{{service_address}}</strong>. You are all set to start your project!</p>'
. ($size ? '<p><strong>Unit Size:</strong> {{unit_size}}</p>' : '')
. '<p>When you are ready for pickup, just give us a call' . $phone_html . ' and we will get it scheduled.</p>
<p>Thank you for choosing us!</p>',
        ]);
        $vars = [
            'customer_name'   => $name,
            'service_address' => $address,
            'unit_size'       => $size,
        ];
        $icon_title = 'Dumpster Delivered';
    } elseif (in_array($new_status, ['picked_up', 'completed'], true)) {
        $tpl = get_email_template('work_order_completed', [
            'subject'   => 'Pickup Complete — Thanks for Choosing Us!',
            'body_html' => '<p>Hello {{customer_name}},</p>
<p>Your dumpster has been picked up and your rental is now complete. Thank you for choosing us for your project!</p>
<p>We hope everything went smoothly. If you need a dumpster again in the future, we would love to help.</p>',
        ]);
        $vars = ['customer_name' => $name];
        $icon_title = 'Pickup Complete';
    } else {
        return;
    }

    $subject = render_email_vars($tpl['subject'], $vars);
    $body    = render_email_vars($tpl['body_html'], $vars);
    $html    = email_template($icon_title, $body);
    send_email($email, $subject, $html);
    log_activity(
        'wo_status_emailed',
        'Sent ' . $new_status . ' email for WO ' . ($wo['wo_number'] ?? $wo['id']) . ' to ' . $email,
        'work_order',
        (int)($wo['id'] ?? 0)
    );
}

/**
 * Send overdue invoice reminders to customers whose invoices are past due.
 * Skips invoices that already received a reminder today.
 */
function notify_invoice_overdue(): void
{
    $today = date('Y-m-d');

    $invoices = db_fetchall(
        "SELECT i.*, c.email AS cust_email_db
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         WHERE i.due_date < ?
           AND i.status IN ('open', 'sent')
           AND i.total > 0",
        [$today]
    );

    foreach ($invoices as $inv) {
        $email = trim($inv['cust_email_db'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        // Only one reminder per invoice per day
        $sentToday = db_value(
            "SELECT COUNT(*) FROM activity_log
             WHERE action = 'invoice_overdue_emailed' AND entity_id = ?
               AND DATE(created_at) = ?",
            [(int)$inv['id'], $today]
        );
        if ($sentToday > 0) {
            continue;
        }

        $name    = htmlspecialchars($inv['cust_name'] ?? 'Valued Customer', ENT_QUOTES, 'UTF-8');
        $inv_num = htmlspecialchars($inv['invoice_number'] ?? '', ENT_QUOTES, 'UTF-8');
        $amount  = htmlspecialchars(fmt_money((float)$inv['total']), ENT_QUOTES, 'UTF-8');
        $due     = !empty($inv['due_date']) ? htmlspecialchars(date('F j, Y', strtotime($inv['due_date'])), ENT_QUOTES, 'UTF-8') : '—';

        $vars = [
            'customer_name'  => $name,
            'invoice_number' => $inv_num,
            'amount'         => $amount,
            'due_date'       => $due,
        ];

        $tpl = get_email_template('invoice_overdue', [
            'subject'   => 'Invoice {{invoice_number}} — Payment Past Due',
            'body_html' => '<p>Hello {{customer_name}},</p>
<p>This is a reminder that invoice <strong>{{invoice_number}}</strong> for <strong>{{amount}}</strong> was due on <strong>{{due_date}}</strong> and remains outstanding.</p>
<p>Please remit payment at your earliest convenience. If you have already paid or have any questions, please contact us and we will sort it out right away.</p>
<p>Thank you for your prompt attention.</p>',
        ]);

        $subject = render_email_vars($tpl['subject'], $vars);
        $body    = render_email_vars($tpl['body_html'], $vars);
        $html    = email_template('Payment Past Due', $body);
        send_email($email, $subject, $html);
        log_activity('invoice_overdue_emailed', 'Sent overdue reminder for invoice ' . ($inv['invoice_number'] ?? $inv['id']), 'invoice', (int)$inv['id']);
    }
}

/**
 * Send an auto-response acknowledgment to a customer who submitted a contact/quote form.
 */
function notify_lead_received(string $to_email, string $to_name, string $company_name = ''): void
{
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $company = $company_name ?: get_setting('company_name', 'Trash Panda Roll-Offs');
    $phone   = get_setting('company_phone', '');
    $name    = htmlspecialchars($to_name ?: 'there', ENT_QUOTES, 'UTF-8');

    $phone_line = $phone
        ? '<p>If you need an immediate response, give us a call at <strong>'
          . htmlspecialchars(fmt_phone($phone), ENT_QUOTES, 'UTF-8')
          . '</strong>.</p>'
        : '';

    $body = '<p>Hi ' . $name . ',</p>
<p>Thanks for reaching out! We received your request and someone from our team will be in touch shortly.</p>'
    . $phone_line
    . '<p>We appreciate your interest in ' . htmlspecialchars($company, ENT_QUOTES, 'UTF-8') . '!</p>';

    $html = email_template('Request Received', $body);
    send_email($to_email, 'We received your request — ' . $company, $html);
}
