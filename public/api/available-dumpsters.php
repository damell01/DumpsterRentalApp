<?php
/**
 * Public API: active dumpster sizes and stock summary
 * GET /api/available-dumpsters.php
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');

$_admin_root = dirname(__DIR__, 2) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $publicSizes = public_dumpster_sizes();
    $hasCatalog = db_table_exists('dumpster_size_options');
    $columns = ['id', 'unit_code', 'size', 'status'];
    foreach ([
        'product_name', 'type', 'description', 'base_price', 'rental_days',
        'extra_day_price', 'daily_rate', 'delivery_fee', 'pickup_fee', 'image', 'active'
    ] as $optionalColumn) {
        if (db_column_exists('dumpsters', $optionalColumn)) {
            $columns[] = $optionalColumn;
        }
    }

    $where = [];
    if (db_column_exists('dumpsters', 'active')) {
        $where[] = 'active = 1';
    }
    if (db_column_exists('dumpsters', 'type')) {
        $where[] = "type = 'dumpster'";
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = db_fetchall(
        "SELECT " . implode(', ', $columns) . "
         FROM dumpsters
         {$whereSql}
         ORDER BY size ASC, unit_code ASC"
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load dumpster inventory.']);
    exit;
}

$grouped = [];

foreach ($rows as $row) {
    $size = trim((string)($row['size'] ?? ''));
    if ($size === '') {
        continue;
    }
    if (($hasCatalog || $publicSizes) && !in_array($size, $publicSizes, true)) {
        continue;
    }

    if (!isset($grouped[$size])) {
        $grouped[$size] = [
            'size' => $size,
            'product_name' => trim((string)($row['product_name'] ?? '')) ?: $size . ' Dumpster',
            'description' => trim((string)($row['description'] ?? '')),
            'base_price' => null,
            'rental_days' => 0,
            'extra_day_price' => null,
            'daily_rate' => null,
            'delivery_fee' => null,
            'pickup_fee' => null,
            'image' => trim((string)($row['image'] ?? '')),
            'unit_count' => 0,
            'available_count' => 0,
            'reserved_count' => 0,
            'in_use_count' => 0,
            'maintenance_count' => 0,
            'unit_codes' => [],
        ];
    }

    $grouped[$size]['unit_count']++;
    $grouped[$size]['unit_codes'][] = (string)($row['unit_code'] ?? '');

    $status = (string)($row['status'] ?? '');
    if ($status === 'available') {
        $grouped[$size]['available_count']++;
    } elseif ($status === 'reserved') {
        $grouped[$size]['reserved_count']++;
    } elseif ($status === 'in_use') {
        $grouped[$size]['in_use_count']++;
    } elseif ($status === 'maintenance') {
        $grouped[$size]['maintenance_count']++;
    }

    $basePrice = $row['base_price'] !== null ? (float)$row['base_price'] : null;
    if ($basePrice !== null && ($grouped[$size]['base_price'] === null || $basePrice < $grouped[$size]['base_price'])) {
        $grouped[$size]['base_price'] = $basePrice;
    }

    $extraDay = $row['extra_day_price'] !== null ? (float)$row['extra_day_price'] : null;
    if ($extraDay !== null && ($grouped[$size]['extra_day_price'] === null || $extraDay < $grouped[$size]['extra_day_price'])) {
        $grouped[$size]['extra_day_price'] = $extraDay;
    }

    $dailyRate = $row['daily_rate'] !== null ? (float)$row['daily_rate'] : null;
    if ($dailyRate !== null && ($grouped[$size]['daily_rate'] === null || $dailyRate < $grouped[$size]['daily_rate'])) {
        $grouped[$size]['daily_rate'] = $dailyRate;
    }

    $deliveryFee = $row['delivery_fee'] !== null ? (float)$row['delivery_fee'] : null;
    if ($deliveryFee !== null && ($grouped[$size]['delivery_fee'] === null || $deliveryFee < $grouped[$size]['delivery_fee'])) {
        $grouped[$size]['delivery_fee'] = $deliveryFee;
    }

    $pickupFee = $row['pickup_fee'] !== null ? (float)$row['pickup_fee'] : null;
    if ($pickupFee !== null && ($grouped[$size]['pickup_fee'] === null || $pickupFee < $grouped[$size]['pickup_fee'])) {
        $grouped[$size]['pickup_fee'] = $pickupFee;
    }

    $rentalDays = (int)($row['rental_days'] ?? 0);
    if ($rentalDays > 0 && ($grouped[$size]['rental_days'] === 0 || $rentalDays < $grouped[$size]['rental_days'])) {
        $grouped[$size]['rental_days'] = $rentalDays;
    }

    if ($grouped[$size]['description'] === '' && !empty($row['description'])) {
        $grouped[$size]['description'] = trim((string)$row['description']);
    }

    if ($grouped[$size]['image'] === '' && !empty($row['image'])) {
        $grouped[$size]['image'] = trim((string)$row['image']);
    }
}

// Overlay category-level descriptions from dumpster_categories (admin Size Catalog)
if (db_table_exists('dumpster_categories')) {
    $catRows = db_fetchall(
        "SELECT name, description FROM dumpster_categories WHERE description IS NOT NULL AND description <> ''"
    );
    foreach ($catRows as $cat) {
        $sizeName = (string)$cat['name'];
        if (isset($grouped[$sizeName])) {
            $grouped[$sizeName]['description'] = trim((string)$cat['description']);
        }
    }
}

$items = array_values($grouped);

usort($items, static function (array $a, array $b): int {
    preg_match('/\d+/', (string)$a['size'], $aMatch);
    preg_match('/\d+/', (string)$b['size'], $bMatch);
    $aNum = isset($aMatch[0]) ? (int)$aMatch[0] : PHP_INT_MAX;
    $bNum = isset($bMatch[0]) ? (int)$bMatch[0] : PHP_INT_MAX;

    if ($aNum === $bNum) {
        return strcasecmp((string)$a['size'], (string)$b['size']);
    }

    return $aNum <=> $bNum;
});

echo json_encode([
    'success' => true,
    'count' => count($items),
    'sizes' => $items,
]);
