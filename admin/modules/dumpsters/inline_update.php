<?php
/**
 * Dumpsters – Inline / Bulk Pricing AJAX Update
 * POST JSON → saves pricing fields → auto-syncs to Stripe if configured.
 * Returns JSON {success, stripe, message, ...}
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_role('admin', 'office');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit;
}

// CSRF — token lives in JSON under the session key name
$submitted = $payload[CSRF_TOKEN_NAME] ?? '';
$stored    = $_SESSION[CSRF_TOKEN_NAME] ?? '';
if ($stored === '' || $submitted === '' || !hash_equals($stored, $submitted)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF check failed. Refresh and try again.']);
    exit;
}

// ── Allowed pricing fields ────────────────────────────────────────────────────
const PRICE_FIELDS = [
    'base_price', 'rental_days', 'extra_day_price',
    'delivery_fee', 'pickup_fee',
    'weekly_rate', 'monthly_rate', 'daily_rate',
];

function coerce_field(string $name, mixed $val): mixed
{
    if ($name === 'rental_days') {
        return max(1, (int)$val);
    }
    if ($name === 'extra_day_price') {
        return ($val !== null && $val !== '') ? (float)$val : null;
    }
    return (float)$val;
}

// ── Stripe sync helper ────────────────────────────────────────────────────────
function sync_to_stripe(int $id): array
{
    $key = trim(get_setting('stripe_secret_key', ''));
    if ($key === '') {
        return ['active' => false];
    }
    require_once INC_PATH . '/stripe.php';
    $d = db_fetch('SELECT * FROM dumpsters WHERE id = ? LIMIT 1', [$id]);
    if (!$d) {
        return ['active' => true, 'synced' => false, 'error' => 'Dumpster not found after save.'];
    }
    try {
        $result = stripe_sync_dumpster_product($d);
        db_update('dumpsters', [
            'stripe_product_id' => $result['stripe_product_id'],
            'stripe_price_id'   => $result['stripe_price_id'],
            'updated_at'        => date('Y-m-d H:i:s'),
        ], 'id', $id);
        return ['active' => true, 'synced' => true];
    } catch (\Throwable $e) {
        return ['active' => true, 'synced' => false, 'error' => $e->getMessage()];
    }
}

// ── Bulk mode: {rows: [{id, field…}, …]} ─────────────────────────────────────
if (isset($payload['rows'])) {
    $rows    = (array)$payload['rows'];
    $updated = [];
    $errors  = [];

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            $errors[] = 'Row missing valid ID.';
            continue;
        }
        $d = db_fetch('SELECT unit_code FROM dumpsters WHERE id = ? LIMIT 1', [$id]);
        if (!$d) {
            $errors[] = "ID $id not found.";
            continue;
        }

        $upd = ['updated_at' => date('Y-m-d H:i:s')];
        foreach (PRICE_FIELDS as $f) {
            if (!array_key_exists($f, $row)) {
                continue;
            }
            $upd[$f] = coerce_field($f, $row[$f]);
        }

        if (count($upd) > 1) {
            db_update('dumpsters', $upd, 'id', $id);
            log_activity('update', "Bulk pricing update: {$d['unit_code']}", 'dumpster', $id);
            $updated[] = $id;
        }
    }

    $stripe_results = [];
    foreach ($updated as $id) {
        $stripe_results[$id] = sync_to_stripe($id);
    }

    echo json_encode([
        'success'        => empty($errors),
        'updated'        => $updated,
        'errors'         => $errors,
        'stripe_results' => $stripe_results,
        'message'        => count($updated) . ' dumpster(s) updated'
            . (empty($stripe_results) ? '.' : ' and synced to Stripe.'),
    ]);
    exit;
}

// ── Single inline update: {id, fields: {field: val, …}} ──────────────────────
$id     = (int)($payload['id'] ?? 0);
$fields = $payload['fields'] ?? [];

if ($id <= 0 || !is_array($fields) || empty($fields)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request: id and fields required.']);
    exit;
}

$d = db_fetch('SELECT unit_code FROM dumpsters WHERE id = ? LIMIT 1', [$id]);
if (!$d) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Dumpster not found.']);
    exit;
}

$upd = ['updated_at' => date('Y-m-d H:i:s')];
foreach ($fields as $f => $val) {
    if (!in_array($f, PRICE_FIELDS, true)) {
        continue;
    }
    $upd[$f] = coerce_field($f, $val);
}

if (count($upd) <= 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No recognised pricing fields to update.']);
    exit;
}

db_update('dumpsters', $upd, 'id', $id);
log_activity('update', "Inline pricing update: {$d['unit_code']}", 'dumpster', $id);

$stripe = sync_to_stripe($id);

$msg = "Saved {$d['unit_code']}";
if ($stripe['active'] ?? false) {
    $msg .= ($stripe['synced'] ?? false) ? ' — synced to Stripe.' : ' (Stripe sync failed).';
}

echo json_encode([
    'success' => true,
    'stripe'  => $stripe,
    'message' => $msg,
]);
