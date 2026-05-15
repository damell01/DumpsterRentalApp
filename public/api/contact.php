<?php
/**
 * Public Contact / Quote Request API
 * Trash Panda Roll-Offs
 *
 * Accepts a JSON POST from the public website contact form,
 * saves it as a Lead in the admin database, and emails the
 * admin notification addresses configured in Settings.
 *
 * Returns JSON: { "success": true } or { "success": false, "error": "..." }
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
// Load admin config — ROOT_PATH, INC_PATH, etc. are defined inside config.php
// (relative to config.php's own __DIR__, so the path is always correct).
$_admin_root = dirname(__DIR__, 2) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';
require_once INC_PATH . '/mailer.php';
unset($_admin_root);

// ── CORS headers (same origin only; tighten if needed) ───────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// Allow the public site to call this endpoint regardless of sub-path
$allowed_origin = defined('APP_URL')
    ? rtrim(preg_replace('#/admin.*$#', '', APP_URL), '/')
    : '';

if (!empty($allowed_origin)) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Only accept POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Rate-limit: 5 submissions per IP per hour (uses existing rate_limit_locks table) ──
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ip = trim(explode(',', $ip)[0]);
// Prefix key to distinguish from login attempts
$rl_ip = 'cf_' . substr(md5($ip), 0, 38);   // still fits in varchar(45)

try {
    $rl_row = db_fetch(
        "SELECT attempts, locked_until FROM rate_limit_locks WHERE ip_address = ?",
        [$rl_ip]
    );
    $now = time();
    if ($rl_row) {
        if (!empty($rl_row['locked_until']) && strtotime($rl_row['locked_until']) > $now) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again later or call us directly.']);
            exit;
        }
        $new_attempts = ((int)$rl_row['attempts']) + 1;
        $locked_until = $new_attempts >= 5 ? date('Y-m-d H:i:s', $now + 3600) : null;
        db_query(
            "UPDATE rate_limit_locks SET attempts = ?, locked_until = ?, updated_at = NOW() WHERE ip_address = ?",
            [$new_attempts, $locked_until, $rl_ip]
        );
        if ($locked_until) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again in an hour or call us directly.']);
            exit;
        }
    } else {
        db_query(
            "INSERT INTO rate_limit_locks (ip_address, attempts, locked_until, updated_at) VALUES (?, 1, NULL, NOW())",
            [$rl_ip]
        );
    }
} catch (\Throwable $e) {
    // Rate-limit table missing — continue without blocking
}

// ── Parse input (JSON body or classic form POST) ──────────────────────────────
$raw = file_get_contents('php://input');
$body = !empty($raw) ? json_decode($raw, true) : $_POST;
if (!is_array($body)) {
    $body = [];
}

// ── Collect & sanitize fields ─────────────────────────────────────────────────
$first_name   = substr(strip_tags(trim($body['first_name']   ?? '')), 0, 60);
$last_name    = substr(strip_tags(trim($body['last_name']    ?? '')), 0, 60);
$phone        = substr(strip_tags(trim($body['phone']        ?? '')), 0, 25);
$email        = substr(strip_tags(trim($body['email']        ?? '')), 0, 150);
$address      = substr(strip_tags(trim($body['address']      ?? '')), 0, 200);
$size_needed  = substr(strip_tags(trim($body['size']         ?? '')), 0, 50);
$project_type = substr(strip_tags(trim($body['project_type'] ?? '')), 0, 100);
$delivery_date = substr(strip_tags(trim($body['delivery_date'] ?? '')), 0, 20);
$message      = substr(strip_tags(trim($body['message']      ?? '')), 0, 2000);

$name = trim("$first_name $last_name");

// ── Validation ────────────────────────────────────────────────────────────────
$errors = [];
if (empty($name)) {
    $errors[] = 'Name is required.';
}
if (empty($phone) && empty($email)) {
    $errors[] = 'Please provide at least a phone number or email address.';
}
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}
// Honeypot check (the form has a hidden field "website" that bots fill in)
if (!empty($body['website'])) {
    // Silently succeed — it's a bot
    echo json_encode(['success' => true]);
    exit;
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// ── Save contact inquiry ──────────────────────────────────────────────────────
$lead_id = 0;
try {
    // Parse city/state/zip from address if possible (basic heuristic)
    $city  = '';
    $state = '';
    $zip   = '';
    if (!empty($address) && preg_match('/,\s*([^,]+),\s*([A-Z]{2})\s*(\d{5})?/i', $address, $am)) {
        $city  = trim($am[1]);
        $state = strtoupper(trim($am[2]));
        $zip   = trim($am[3] ?? '');
    }

    $lead_id = db_insert('leads', [
        'name'         => $name,
        'email'        => $email,
        'phone'        => $phone,
        'address'      => $address,
        'city'         => $city,
        'state'        => $state,
        'zip'          => $zip,
        'size_needed'  => $size_needed,
        'project_type' => $project_type,
        'source'       => 'Website Contact Form',
        'message'      => ($delivery_date ? "Preferred delivery: $delivery_date\n" : '') . $message,
        'status'       => 'new',
        'archived'     => 0,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
} catch (\Throwable $e) {
    // If leads table is unavailable, continue — the email notification below
    // is the primary delivery mechanism for contact form submissions.
    $lead_id = 0;
}

// ── Send admin notification email ─────────────────────────────────────────────
try {
    $admin_url  = defined('APP_URL') ? APP_URL : '';
    $detail_rows = '';
    $details = [
        'Name'            => htmlspecialchars($name,         ENT_QUOTES, 'UTF-8'),
        'Phone'           => htmlspecialchars($phone,        ENT_QUOTES, 'UTF-8'),
        'Email'           => htmlspecialchars($email,        ENT_QUOTES, 'UTF-8'),
        'Address'         => htmlspecialchars($address,      ENT_QUOTES, 'UTF-8'),
        'Size Needed'     => htmlspecialchars($size_needed,  ENT_QUOTES, 'UTF-8'),
        'Project Type'    => htmlspecialchars($project_type, ENT_QUOTES, 'UTF-8'),
        'Delivery Date'   => htmlspecialchars($delivery_date,ENT_QUOTES, 'UTF-8'),
        'Message'         => nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')),
    ];
    foreach ($details as $label => $value) {
        if (empty($value)) {
            continue;
        }
        $detail_rows .= '<tr>
          <td style="padding:8px 12px;border:1px solid #e5e7eb;font-weight:600;background:#f9fafb;white-space:nowrap;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>
          <td style="padding:8px 12px;border:1px solid #e5e7eb;">' . $value . '</td>
        </tr>';
    }

    $body_html = '<p style="font-size:1rem;color:#111;">A new quote request has been submitted through the website contact form.</p>
<table style="border-collapse:collapse;width:100%;font-size:.9rem;margin:1rem 0;">'
        . $detail_rows . '</table>';

    $html = email_template(
        'New Contact Form Submission',
        $body_html,
        $admin_url ? 'View Customers in Admin' : '',
        $admin_url ? $admin_url . '/modules/customers/index.php' : ''
    );

    notify_admins('🗑️ New Quote Request: ' . $name, $html);
} catch (\Throwable $e) {
    // Email failure should not fail the API response
}

// Auto-response to the customer
if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    try {
        notify_lead_received($email, $name);
    } catch (\Throwable $e) {
        // Non-fatal — admin notification already sent above
    }
}

// Success
echo json_encode(['success' => true]);
