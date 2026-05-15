<?php
/**
 * General-purpose helper functions
 * Trash Panda Roll-Offs
 */

/**
 * HTML-encode a value for safe output.
 *
 * @param mixed $val
 * @return string
 */
function e(mixed $val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

/**
 * Format a date string using the given format.
 * Returns an empty string for null / empty input.
 *
 * @param string|null $date
 * @param string      $format
 * @return string
 */
function fmt_date(?string $date, string $format = 'M j, Y'): string
{
    if ($date === null || $date === '') {
        return '';
    }
    $ts = strtotime($date);
    return $ts !== false ? date($format, $ts) : '';
}

/**
 * Format a datetime string as "M j, Y g:i A".
 *
 * @param string|null $dt
 * @return string
 */
function fmt_datetime(?string $dt): string
{
    return fmt_date($dt, 'M j, Y g:i A');
}

/**
 * Format a numeric amount as a dollar string.
 *
 * @param mixed $amount
 * @return string  e.g. "$1,234.56"
 */
function fmt_money(mixed $amount): string
{
    return '$' . number_format((float)$amount, 2);
}

/**
 * Format a 10-digit phone number as (251) 555-1234.
 * Returns the original string if it cannot be normalised.
 *
 * @param string|null $phone
 * @return string
 */
function fmt_phone(?string $phone): string
{
    if ($phone === null) {
        return '';
    }
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s',
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6, 4)
        );
    }
    return $phone;
}

/**
 * Return a styled badge <span> for a status value.
 *
 * @param string $status
 * @return string  HTML
 */
function status_badge(string $status): string
{
    $map = [
        'new'              => 'New',
        'contacted'        => 'Contacted',
        'quoted'           => 'Quoted',
        'won'              => 'Won',
        'lost'             => 'Lost',
        'draft'            => 'Draft',
        'sent'             => 'Sent',
        'open'             => 'Open',
        'approved'         => 'Approved',
        'rejected'         => 'Rejected',
        'uncollectible'    => 'Uncollectible',
        'scheduled'        => 'Scheduled',
        'delivered'        => 'Delivered',
        'active'           => 'Active',
        'pickup_requested' => 'Pickup Req.',
        'picked_up'        => 'Picked Up',
        'completed'        => 'Completed',
        'canceled'         => 'Canceled',
        'failed'           => 'Failed',
        'processing'       => 'Processing',
        'past_due'         => 'Past Due',
        'paused'           => 'Paused',
        'trialing'         => 'Trialing',
        'incomplete'       => 'Incomplete',
        'available'        => 'Available',
        'reserved'         => 'Reserved',
        'in_use'           => 'In Use',
        'maintenance'      => 'Maintenance',
    ];

    $label     = $map[$status] ?? ucfirst($status);
    $cssSlug   = str_replace('_', '-', strtolower($status));

    return '<span class="tp-badge badge-' . e($cssSlug) . '">' . e($label) . '</span>';
}

/**
 * Return a styled badge <span> for a payment status value.
 */
function payment_badge(string $status): string
{
    $map = [
        'unpaid'        => 'Unpaid',
        'pending'       => 'Pending',
        'processing'    => 'Processing',
        'paid'          => 'Paid',
        'failed'        => 'Failed',
        'refunded'      => 'Refunded',
        'received'      => 'Received',
        'processed'     => 'Processed',
        'duplicate'     => 'Duplicate',
        'pending_cash'  => 'Cash (Pending)',
        'paid_cash'     => 'Cash (Paid)',
        'pending_check' => 'Check (Pending)',
        'paid_check'    => 'Check (Paid)',
        'canceled'      => 'Canceled',
    ];
    $label   = $map[$status] ?? ucfirst($status);
    $cssSlug = str_replace('_', '-', strtolower($status));
    return '<span class="tp-badge badge-' . e($cssSlug) . '">' . e($label) . '</span>';
}

/**
 * Return a styled badge for subscription status values.
 */
function subscription_badge(string $status): string
{
    $label = match ($status) {
        'trialing'  => 'Trialing',
        'active'    => 'Active',
        'past_due'  => 'Past Due',
        'paused'    => 'Paused',
        'canceled'  => 'Canceled',
        'incomplete' => 'Incomplete',
        default     => ucwords(str_replace('_', ' ', $status)),
    };
    $cssSlug = str_replace('_', '-', strtolower($status));
    return '<span class="tp-badge badge-' . e($cssSlug) . '">' . e($label) . '</span>';
}

/**
 * Alias retained for older views.
 */
function flash_display(): void
{
    render_flash();
}

/**
 * Determine whether Stripe is configured in test mode.
 */
function stripe_is_test_mode(): bool
{
    $secret = trim(get_setting('stripe_secret_key', ''));
    if ($secret === '') {
        return false;
    }

    return str_starts_with($secret, 'sk_test_') || str_starts_with($secret, 'rk_test_');
}

/**
 * Build a Stripe Dashboard URL for common object IDs (pi_, ch_, cs_).
 */
function stripe_dashboard_url(?string $object_id): ?string
{
    $id = trim((string)$object_id);
    if ($id === '') {
        return null;
    }

    $base = 'https://dashboard.stripe.com' . (stripe_is_test_mode() ? '/test' : '');

    if (str_starts_with($id, 'pi_') || str_starts_with($id, 'ch_')) {
        return $base . '/payments/' . rawurlencode($id);
    }
    if (str_starts_with($id, 'cs_')) {
        return $base . '/payments/checkout/sessions/' . rawurlencode($id);
    }
    if (str_starts_with($id, 'prod_')) {
        return $base . '/products/' . rawurlencode($id);
    }
    if (str_starts_with($id, 'price_')) {
        return $base . '/prices/' . rawurlencode($id);
    }

    return null;
}

/**
 * Generate the next sequential number for a given prefix / table / column.
 * Example output: "Q-0001", "WO-0042"
 *
 * $table and $col are validated against an explicit whitelist to prevent SQL
 * injection, since PDO cannot parameterise identifier names.
 *
 * @param string $prefix  e.g. "Q" or "WO"
 * @param string $table   database table name (must be whitelisted)
 * @param string $col     column that holds the number string (must be whitelisted)
 * @return string
 * @throws InvalidArgumentException if $table or $col are not in the whitelist
 */
function next_number(string $prefix, string $table, string $col): string
{
    // Whitelist of tables and columns permitted for sequence generation.
    static $allowed_tables = [
        'quotes', 'work_orders', 'leads', 'estimates', 'bookings', 'invoices',
    ];
    static $allowed_cols = [
        'quote_number', 'wo_number', 'lead_number', 'estimate_number', 'booking_number', 'invoice_number',
    ];

    if (!in_array($table, $allowed_tables, true)) {
        throw new InvalidArgumentException("next_number: table '$table' is not whitelisted.");
    }
    if (!in_array($col, $allowed_cols, true)) {
        throw new InvalidArgumentException("next_number: column '$col' is not whitelisted.");
    }

    // Identifiers are now safe to interpolate (validated against static whitelist).
    $row = db_fetch(
        'SELECT MAX(CAST(SUBSTRING_INDEX(`' . $col . '`, \'-\', -1) AS UNSIGNED)) AS max_num FROM `' . $table . '`'
    );

    $next = ($row && $row['max_num'] !== null) ? (int)$row['max_num'] + 1 : 1;

    return $prefix . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Queue a success flash message.
 *
 * @param string $msg
 */
function flash_success(string $msg): void
{
    $_SESSION['flash'][] = ['type' => 'success', 'msg' => $msg];
}

/**
 * Queue an error flash message.
 *
 * @param string $msg
 */
function flash_error(string $msg): void
{
    $_SESSION['flash'][] = ['type' => 'error', 'msg' => $msg];
}

/**
 * Queue an info flash message.
 *
 * @param string $msg
 */
function flash_info(string $msg): void
{
    $_SESSION['flash'][] = ['type' => 'info', 'msg' => $msg];
}

/**
 * Queue a warning flash message.
 *
 * @param string $msg
 */
function flash_warning(string $msg): void
{
    $_SESSION['flash'][] = ['type' => 'warning', 'msg' => $msg];
}

/**
 * Output Bootstrap toasts for queued flash messages, then clear them.
 */
function render_flash(): void
{
    if (empty($_SESSION['flash'])) {
        return;
    }

    $typeMap = [
        'success' => ['#16a34a', 'fa-circle-check'],
        'error'   => ['#dc2626', 'fa-circle-xmark'],
        'info'    => ['#2563eb', 'fa-circle-info'],
        'warning' => ['#d97706', 'fa-triangle-exclamation'],
    ];

    echo '<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1060;">';
    foreach ($_SESSION['flash'] as $flash) {
        [$bg, $icon] = $typeMap[$flash['type']] ?? $typeMap['info'];
        $uid = 'tp-toast-' . uniqid();
        echo '<div id="' . $uid . '" class="toast align-items-center text-white border-0 mb-2"
                   role="alert" aria-live="assertive" aria-atomic="true"
                   data-bs-autohide="true" data-bs-delay="4500"
                   style="background:' . $bg . ';min-width:280px;box-shadow:0 4px 16px rgba(0,0,0,.4);">
                <div class="d-flex">
                    <div class="toast-body d-flex align-items-center gap-2">
                        <i class="fa-solid ' . $icon . '"></i>
                        ' . e($flash['msg']) . '
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto"
                            data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>';
    }
    echo '</div>';

    unset($_SESSION['flash']);
}

/**
 * Redirect to a URL and terminate execution.
 *
 * @param string $url
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Build a pagination metadata array.
 *
 * @param int $total     total number of records
 * @param int $page      current page (1-based)
 * @param int $per_page  records per page
 * @return array{page:int,pages:int,per_page:int,offset:int}
 */
function paginate(int $total, int $page, int $per_page = 25): array
{
    $page   = max(1, $page);
    $pages  = max(1, (int)ceil($total / $per_page));
    $page   = min($page, $pages);

    return [
        'page'     => $page,
        'pages'    => $pages,
        'per_page' => $per_page,
        'offset'   => ($page - 1) * $per_page,
    ];
}

/**
 * Retrieve a setting value from the database, with a static in-memory cache.
 *
 * @param string $key
 * @param string $default
 * @return string
 */
function get_setting(string $key, string $default = ''): string
{
    static $cache = [];

    if (!array_key_exists($key, $cache)) {
        $row = db_fetch('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1', [$key]);
        $cache[$key] = ($row !== false) ? $row['value'] : null;
    }

    return $cache[$key] ?? $default;
}

/**
 * Persist a setting value using INSERT … ON DUPLICATE KEY UPDATE.
 *
 * @param string $key
 * @param string $value
 */
function set_setting(string $key, string $value): void
{
    db_execute(
        'INSERT INTO settings (`key`, `value`, `updated_at`) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW()',
        [$key, $value]
    );
}

/**
 * Retrieve a JSON setting value, returning the provided default when unset or invalid.
 */
function get_setting_json(string $key, array $default = []): array
{
    $raw = trim(get_setting($key, ''));
    if ($raw === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

/**
 * Persist a setting as JSON.
 */
function set_setting_json(string $key, array $value): void
{
    set_setting($key, json_encode($value, JSON_UNESCAPED_SLASHES));
}

/**
 * Validate that required fields are present and non-empty in $data.
 *
 * @param string[] $fields  list of required field names
 * @param array    $data    data array to check (e.g. $_POST)
 * @return string[]         error messages for any missing fields
 */
function validate_required(array $fields, array $data): array
{
    $errors = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
            $label    = ucwords(str_replace(['_', '-'], ' ', $field));
            $errors[] = $label . ' is required.';
        }
    }
    return $errors;
}

/**
 * Return the list of available dumpster sizes.
 *
 * @return string[]
 */
function dumpster_sizes(): array
{
    static $sizes = null;

    if ($sizes !== null) {
        return $sizes;
    }

    $fallback = ['10 Yard', '15 Yard', '20 Yard', '30 Yard', '40 Yard'];

    try {
        $rows = db_fetchall(
            "SELECT DISTINCT size
             FROM dumpsters
             WHERE active = 1
               AND type = 'dumpster'
               AND size IS NOT NULL
               AND TRIM(size) <> ''"
        );
    } catch (Throwable $e) {
        $sizes = $fallback;
        return $sizes;
    }

    $found = [];
    foreach ($rows as $row) {
        $size = trim((string)($row['size'] ?? ''));
        if ($size !== '') {
            $found[] = $size;
        }
    }

    $found = array_values(array_unique($found));
    if (!$found) {
        $sizes = $fallback;
        return $sizes;
    }

    usort($found, static function (string $a, string $b): int {
        preg_match('/\d+/', $a, $aMatch);
        preg_match('/\d+/', $b, $bMatch);
        $aNum = isset($aMatch[0]) ? (int)$aMatch[0] : PHP_INT_MAX;
        $bNum = isset($bMatch[0]) ? (int)$bMatch[0] : PHP_INT_MAX;

        if ($aNum === $bNum) {
            return strcasecmp($a, $b);
        }

        return $aNum <=> $bNum;
    });

    $sizes = $found;
    return $sizes;
}

/**
 * Return the list of lead sources.
 *
 * @return string[]
 */
function lead_sources(): array
{
    return ['Website', 'Google', 'Facebook', 'Referral', 'Phone', 'Walk-in', 'Other'];
}

/**
 * Return the list of project types.
 *
 * @return string[]
 */
function project_types(): array
{
    return [
        'Residential',
        'Commercial',
        'Construction',
        'Roofing',
        'Estate Cleanout',
        'Storm Debris',
        'Landscaping',
        'Other',
    ];
}

/**
 * Return the map of user roles (value => label).
 *
 * @return array<string,string>
 */
function user_roles(): array
{
    return [
        'admin'      => 'Admin',
        'office'     => 'Office Staff',
        'dispatcher' => 'Dispatcher',
        'readonly'   => 'Read Only',
    ];
}

/**
 * Return true if the given date is in the past (before today).
 *
 * @param string|null $date
 * @return bool
 */
function is_overdue(?string $date): bool
{
    if ($date === null || $date === '') {
        return false;
    }
    return strtotime($date) < strtotime('today');
}

/**
 * Return a human-friendly relative date label.
 *
 * @param string|null $date
 * @return string  "Today", "Yesterday", "X days ago", or formatted date
 */
function days_ago(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }

    $ts    = strtotime($date);
    if ($ts === false) {
        return $date;
    }

    $today     = strtotime('today');
    $yesterday = strtotime('yesterday');
    $diff      = (int)(($today - strtotime(date('Y-m-d', $ts))) / 86400);

    if ($diff === 0) {
        return 'Today';
    }
    if ($diff === 1) {
        return 'Yesterday';
    }
    if ($diff > 1) {
        return $diff . ' days ago';
    }

    return date('M j, Y', $ts);
}

/**
 * Insert a row into the activity_log table.
 *
 * @param string $action  short action identifier (e.g. "login", "create")
 * @param string $desc    human-readable description
 * @param string $type    related record type (e.g. "user", "work_order")
 * @param int    $id      related record ID
 * @param int    $uid     acting user ID; defaults to $_SESSION['user_id']
 */
function log_activity(string $action, string $desc, string $type = '', int $id = 0, int $uid = 0): void
{
    if ($uid === 0) {
        $uid = (int)($_SESSION['user_id'] ?? 0);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    db_insert('activity_log', [
        'user_id'     => $uid > 0 ? $uid : null,
        'action'      => $action,
        'description' => $desc,
        'entity_type' => $type,
        'entity_id'   => $id,
        'ip_address'  => $ip,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Write a structured log entry to the filesystem.
 */
function app_log(string $channel, string $message, array $context = []): void
{
    $dir = defined('APP_LOG_DIR') ? APP_LOG_DIR : (ROOT_PATH . '/logs');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $line = [
        'ts' => date('c'),
        'channel' => $channel,
        'message' => $message,
        'context' => $context,
    ];

    @file_put_contents(
        $dir . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $channel) . '.log',
        json_encode($line, JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

/**
 * Map legacy booking/invoice payment methods to friendly labels.
 */
function payment_method_label(?string $method): string
{
    return match ((string)$method) {
        'stripe', 'card' => 'Card',
        'ach' => 'ACH',
        'cash' => 'Cash',
        'check' => 'Check',
        default => ucfirst((string)$method),
    };
}

/**
 * Release a dumpster back to 'available' if no other active bookings or
 * active work orders still hold it.
 *
 * @param int $dumpster_id
 * @param int $exclude_booking_id  Booking ID to exclude from the check (the one being cancelled/completed)
 */
function release_dumpster_if_free(int $dumpster_id, int $exclude_booking_id = 0): void
{
    if ($dumpster_id <= 0) {
        return;
    }

    // Check for other active bookings that cover today or future dates
    $other_bookings = db_fetch(
        "SELECT COUNT(*) AS cnt FROM bookings
         WHERE dumpster_id = ?
           AND id != ?
           AND booking_status NOT IN ('canceled','completed')
           AND rental_end >= CURDATE()",
        [$dumpster_id, $exclude_booking_id]
    );

    if ((int)($other_bookings['cnt'] ?? 0) > 0) {
        return; // Still held by another booking
    }

    // Check for active work orders
    $active_wo = db_fetch(
        "SELECT COUNT(*) AS cnt FROM work_orders
         WHERE dumpster_id = ?
           AND status NOT IN ('completed','canceled','picked_up')",
        [$dumpster_id]
    );

    if ((int)($active_wo['cnt'] ?? 0) > 0) {
        return; // Still held by a work order
    }

    db_update('dumpsters', [
        'status'     => 'available',
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id', $dumpster_id);
}
