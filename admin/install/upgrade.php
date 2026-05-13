<?php
/**
 * Trash Panda Roll-Offs — Database Upgrade Script
 * ================================================
 * Run this script ONCE after pulling new code that adds database columns or tables.
 * It is safe to run multiple times — every change uses IF NOT EXISTS / IF EXISTS guards.
 *
 * HOW TO RUN:
 *   Option A (browser): https://your-domain.com/admin/install/upgrade.php?secret=YOUR_UPGRADE_SECRET
 *   Option B (CLI):     php admin/install/upgrade.php
 *
 * Set UPGRADE_SECRET below to a long random string to protect the browser URL.
 * Delete or rename this file after running it in production.
 */

// ─── Security ────────────────────────────────────────────────────────────────
define('UPGRADE_SECRET', 'change-this-to-a-random-string-before-use');

$isCli        = (PHP_SAPI === 'cli');
$isAdminCall  = defined('RUNNING_FROM_ADMIN') && RUNNING_FROM_ADMIN === true;

if (!$isCli && !$isAdminCall) {
    $provided = $_GET['secret'] ?? '';
    if ($provided !== UPGRADE_SECRET || UPGRADE_SECRET === 'change-this-to-a-random-string-before-use') {
        http_response_code(403);
        die('<pre>Access denied. Set a custom UPGRADE_SECRET in upgrade.php and pass ?secret=YOUR_SECRET in the URL.</pre>');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ─── Bootstrap ───────────────────────────────────────────────────────────────
$_admin_root = dirname(__DIR__);
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';

$pdo = get_db();

// ─── Helpers ─────────────────────────────────────────────────────────────────
$log   = [];
$errors = [];

function run_step(PDO $pdo, string $label, string $sql): void {
    global $log, $errors;
    try {
        $pdo->exec($sql);
        $log[] = "[OK]  $label";
    } catch (PDOException $e) {
        // "Duplicate column name" and "already exists" are not real errors for idempotent upgrades
        $msg = $e->getMessage();
        if (
            stripos($msg, 'Duplicate column') !== false ||
            stripos($msg, 'already exists')   !== false ||
            stripos($msg, 'Duplicate key')     !== false
        ) {
            $log[] = "[SKIP] $label (already applied)";
        } else {
            $errors[] = "[FAIL] $label — " . $msg;
        }
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

echo "Trash Panda Roll-Offs — Database Upgrade\n";
echo str_repeat('=', 60) . "\n\n";

// =============================================================================
// UPGRADE 1 — dumpsters: add type, daily_rate, active, image columns
// =============================================================================
echo "--- Upgrade 1: dumpsters table enhancements ---\n";

if (!column_exists($pdo, 'dumpsters', 'type')) {
    run_step($pdo, "dumpsters.type", "ALTER TABLE `dumpsters`
        ADD COLUMN `type` ENUM('dumpster','trailer') NOT NULL DEFAULT 'dumpster' AFTER `unit_code`");
} else {
    $log[] = "[SKIP] dumpsters.type (already exists)";
}

if (!column_exists($pdo, 'dumpsters', 'daily_rate')) {
    run_step($pdo, "dumpsters.daily_rate", "ALTER TABLE `dumpsters`
        ADD COLUMN `daily_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `size`");
} else {
    $log[] = "[SKIP] dumpsters.daily_rate (already exists)";
}

if (!column_exists($pdo, 'dumpsters', 'weekly_rate')) {
    run_step($pdo, "dumpsters.weekly_rate", "ALTER TABLE `dumpsters`
        ADD COLUMN `weekly_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `daily_rate`");
} else {
    $log[] = "[SKIP] dumpsters.weekly_rate (already exists)";
}

if (!column_exists($pdo, 'dumpsters', 'monthly_rate')) {
    run_step($pdo, "dumpsters.monthly_rate", "ALTER TABLE `dumpsters`
        ADD COLUMN `monthly_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `weekly_rate`");
} else {
    $log[] = "[SKIP] dumpsters.monthly_rate (already exists)";
}

if (!column_exists($pdo, 'dumpsters', 'active')) {
    run_step($pdo, "dumpsters.active", "ALTER TABLE `dumpsters`
        ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `monthly_rate`");
} else {
    $log[] = "[SKIP] dumpsters.active (already exists)";
}

if (!column_exists($pdo, 'dumpsters', 'image')) {
    run_step($pdo, "dumpsters.image", "ALTER TABLE `dumpsters`
        ADD COLUMN `image` VARCHAR(255) DEFAULT NULL AFTER `active`");
} else {
    $log[] = "[SKIP] dumpsters.image (already exists)";
}

// =============================================================================
// UPGRADE 2 — bookings table
// =============================================================================
echo "\n--- Upgrade 2: bookings table ---\n";

if (!table_exists($pdo, 'bookings')) {
    run_step($pdo, "CREATE TABLE bookings", "
        CREATE TABLE `bookings` (
          `id`                 INT(11)       NOT NULL AUTO_INCREMENT,
          `booking_number`     VARCHAR(20)   NOT NULL,
          `customer_name`      VARCHAR(100)  NOT NULL,
          `customer_phone`     VARCHAR(25)            DEFAULT NULL,
          `customer_email`     VARCHAR(150)           DEFAULT NULL,
          `customer_address`   VARCHAR(200)           DEFAULT NULL,
          `customer_city`      VARCHAR(100)           DEFAULT NULL,
          `dumpster_id`        INT(11)                DEFAULT NULL,
          `unit_code`          VARCHAR(50)            DEFAULT NULL,
          `unit_type`          VARCHAR(50)            DEFAULT NULL,
          `unit_size`          VARCHAR(50)            DEFAULT NULL,
          `rental_start`       DATE          NOT NULL,
          `rental_end`         DATE          NOT NULL,
          `rental_days`        INT(11)       NOT NULL DEFAULT 1,
          `daily_rate`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `total_amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `payment_method`     ENUM('stripe','cash','check') NOT NULL DEFAULT 'stripe',
          `payment_status`     ENUM('unpaid','pending','paid','refunded','pending_cash','paid_cash','pending_check','paid_check') NOT NULL DEFAULT 'unpaid',
          `stripe_session_id`  VARCHAR(255)           DEFAULT NULL,
          `stripe_payment_id`  VARCHAR(255)           DEFAULT NULL,
          `booking_status`     ENUM('pending','confirmed','paid','canceled','completed') NOT NULL DEFAULT 'pending',
          `notes`              TEXT                   DEFAULT NULL,
          `created_by`         INT(11)                DEFAULT NULL,
          `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_bookings_number` (`booking_number`),
          CONSTRAINT `fk_bookings_dumpster_id` FOREIGN KEY (`dumpster_id`) REFERENCES `dumpsters` (`id`) ON DELETE SET NULL,
          CONSTRAINT `fk_bookings_created_by`  FOREIGN KEY (`created_by`)  REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} else {
    $log[] = "[SKIP] bookings table (already exists)";
}

// =============================================================================
// UPGRADE 3 — inventory_blocks table
// =============================================================================
echo "\n--- Upgrade 3: inventory_blocks table ---\n";

if (!table_exists($pdo, 'inventory_blocks')) {
    run_step($pdo, "CREATE TABLE inventory_blocks", "
        CREATE TABLE `inventory_blocks` (
          `id`           INT(11)      NOT NULL AUTO_INCREMENT,
          `dumpster_id`  INT(11)      NOT NULL,
          `block_start`  DATE         NOT NULL,
          `block_end`    DATE         NOT NULL,
          `reason`       VARCHAR(200)          DEFAULT NULL,
          `created_by`   INT(11)               DEFAULT NULL,
          `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          CONSTRAINT `fk_ib_dumpster_id`  FOREIGN KEY (`dumpster_id`) REFERENCES `dumpsters` (`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_ib_created_by`   FOREIGN KEY (`created_by`)  REFERENCES `users`     (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} else {
    $log[] = "[SKIP] inventory_blocks table (already exists)";
}

// =============================================================================
// UPGRADE 4 — settings: Stripe & booking defaults
// =============================================================================
echo "\n--- Upgrade 4: settings defaults ---\n";

if (table_exists($pdo, 'settings')) {
    $defaults = [
        'stripe_publishable_key' => '',
        'stripe_secret_key'      => '',
        'stripe_webhook_secret'  => '',
        'stripe_mode'            => 'test',
        'booking_terms'          => 'By completing this booking, you agree to our rental terms and conditions.',
        'currency'               => 'usd',
    ];
    foreach ($defaults as $key => $value) {
        try {
            $pdo->prepare("INSERT IGNORE INTO `settings` (`key`, `value`) VALUES (?, ?)")
                ->execute([$key, $value]);
            $log[] = "[OK]  settings.$key (inserted if missing)";
        } catch (PDOException $e) {
            $errors[] = "[FAIL] settings.$key — " . $e->getMessage();
        }
    }
} else {
    $log[] = "[SKIP] settings table does not exist — skipping defaults";
}

// =============================================================================
// UPGRADE 5 — customers: remove active column reference (schema cleanup note)
// =============================================================================
echo "\n--- Upgrade 5: customers table ---\n";
// The customers table never had an `active` column per the schema.
// The create.php bug was in PHP code (now fixed). No DB change needed.
$log[] = "[INFO] customers.active — no DB change needed (PHP code already fixed)";

// =============================================================================
// UPGRADE 6 — bookings: add booking_group_id for multi-unit booking groups
// =============================================================================
echo "\n--- Upgrade 6: bookings.booking_group_id ---\n";

if (table_exists($pdo, 'bookings') && !column_exists($pdo, 'bookings', 'booking_group_id')) {
    run_step(
        $pdo,
        'bookings.booking_group_id column',
        "ALTER TABLE `bookings`
         ADD COLUMN `booking_group_id` VARCHAR(32) DEFAULT NULL
             COMMENT 'Shared key linking multiple units booked together in one session'
         AFTER `notes`"
    );
    run_step(
        $pdo,
        'bookings.booking_group_id index',
        "ALTER TABLE `bookings` ADD KEY `idx_bookings_group` (`booking_group_id`)"
    );
} else {
    $log[] = "[SKIP] bookings.booking_group_id already exists";
}

// =============================================================================
// UPGRADE 7 — notifications table
// =============================================================================
echo "\n--- Upgrade 7: notifications table ---\n";

if (!table_exists($pdo, 'notifications')) {
    run_step($pdo, "CREATE TABLE notifications", "
        CREATE TABLE `notifications` (
          `id`           INT(11)      NOT NULL AUTO_INCREMENT,
          `type`         ENUM('email','sms') NOT NULL DEFAULT 'email',
          `recipient`    VARCHAR(180)          DEFAULT NULL,
          `subject`      VARCHAR(255)          DEFAULT NULL,
          `body`         TEXT                  DEFAULT NULL,
          `status`       ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
          `related_type` VARCHAR(50)           DEFAULT NULL,
          `related_id`   INT(11)               DEFAULT NULL,
          `sent_at`      DATETIME              DEFAULT NULL,
          `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} else {
    $log[] = "[SKIP] notifications table (already exists)";
}

// =============================================================================
// UPGRADE 8 — invoices table
// =============================================================================
echo "\n--- Upgrade 8: invoices table ---\n";

if (!table_exists($pdo, 'invoices')) {
    run_step($pdo, "CREATE TABLE invoices", "
        CREATE TABLE `invoices` (
          `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
          `invoice_number`      VARCHAR(20)   NOT NULL,
          `customer_id`         INT(11)                DEFAULT NULL,
          `cust_name`           VARCHAR(100)  NOT NULL,
          `cust_email`          VARCHAR(150)           DEFAULT NULL,
          `cust_phone`          VARCHAR(20)            DEFAULT NULL,
          `cust_address`        VARCHAR(200)           DEFAULT NULL,
          `subtotal`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `tax_rate`            DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
          `tax_amount`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `total`               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `notes`               TEXT                   DEFAULT NULL,
          `terms`               TEXT                   DEFAULT NULL,
          `status`              ENUM('draft','sent','paid','void') NOT NULL DEFAULT 'draft',
          `due_date`            DATE                   DEFAULT NULL,
          `stripe_payment_link` VARCHAR(500)           DEFAULT NULL,
          `created_by`          INT(11)                DEFAULT NULL,
          `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_invoices_number` (`invoice_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} else {
    $log[] = "[SKIP] invoices table (already exists)";
}

// =============================================================================
// UPGRADE 9 — invoice_items table
// =============================================================================
echo "\n--- Upgrade 9: invoice_items table ---\n";

if (!table_exists($pdo, 'invoice_items')) {
    run_step($pdo, "CREATE TABLE invoice_items", "
        CREATE TABLE `invoice_items` (
          `id`          INT(11)       NOT NULL AUTO_INCREMENT,
          `invoice_id`  INT(11)       NOT NULL,
          `description` VARCHAR(255)  NOT NULL,
          `quantity`    DECIMAL(10,2) NOT NULL DEFAULT 1.00,
          `unit_price`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `rate_type`   ENUM('fixed','daily','weekly','monthly') NOT NULL DEFAULT 'fixed',
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} else {
    $log[] = "[SKIP] invoice_items table (already exists)";
}

// =============================================================================
// UPGRADE 10 — push_subscriptions table
// =============================================================================
echo "\n--- Upgrade 10: push_subscriptions table ---\n";

if (!table_exists($pdo, 'push_subscriptions')) {
    run_step($pdo, "CREATE TABLE push_subscriptions", "
        CREATE TABLE `push_subscriptions` (
          `id`              INT(11)       NOT NULL AUTO_INCREMENT,
          `subscriber_type` ENUM('admin','customer') NOT NULL DEFAULT 'customer',
          `subscriber_id`   VARCHAR(200)  NOT NULL,
          `endpoint`        TEXT          NOT NULL,
          `p256dh`          VARCHAR(255)  NOT NULL,
          `auth`            VARCHAR(64)   NOT NULL,
          `user_agent`      VARCHAR(255)           DEFAULT NULL,
          `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_push_endpoint` (endpoint(200)),
          KEY `idx_push_subscriber` (`subscriber_type`, `subscriber_id`(100))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} else {
    $log[] = "[SKIP] push_subscriptions table (already exists)";
}

// =============================================================================
// UPGRADE 11 — two_factor_secrets table
// =============================================================================
echo "\n--- Upgrade 11: two_factor_secrets table ---\n";

if (!table_exists($pdo, 'two_factor_secrets')) {
    run_step($pdo, "CREATE TABLE two_factor_secrets", "
        CREATE TABLE `two_factor_secrets` (
          `user_id`      INT(11)     NOT NULL,
          `secret`       VARCHAR(64) NOT NULL,
          `enabled`      TINYINT(1)  NOT NULL DEFAULT 0,
          `backup_codes` TEXT                  DEFAULT NULL,
          `created_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} else {
    $log[] = "[SKIP] two_factor_secrets table (already exists)";
}

// =============================================================================
// UPGRADE 12 — login_attempts table
// =============================================================================
echo "\n--- Upgrade 12: login_attempts table ---\n";

if (!table_exists($pdo, 'login_attempts')) {
    run_step($pdo, "CREATE TABLE login_attempts", "
        CREATE TABLE `login_attempts` (
          `id`           INT(11)      NOT NULL AUTO_INCREMENT,
          `ip_address`   VARCHAR(45)  NOT NULL,
          `email`        VARCHAR(180)          DEFAULT NULL,
          `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_ip`   (`ip_address`),
          KEY `idx_time` (`attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} else {
    $log[] = "[SKIP] login_attempts table (already exists)";
}

// =============================================================================
// UPGRADE 13 — rate_limit_locks table
// =============================================================================
echo "\n--- Upgrade 13: rate_limit_locks table ---\n";

if (!table_exists($pdo, 'rate_limit_locks')) {
    run_step($pdo, "CREATE TABLE rate_limit_locks", "
        CREATE TABLE `rate_limit_locks` (
          `ip_address`   VARCHAR(45) NOT NULL,
          `attempts`     INT(11)     NOT NULL DEFAULT 0,
          `locked_until` DATETIME             DEFAULT NULL,
          `updated_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`ip_address`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} else {
    $log[] = "[SKIP] rate_limit_locks table (already exists)";
}

// =============================================================================
// UPGRADE 14 — quotes: add subtotal column if missing
// =============================================================================
echo "\n--- Upgrade 14: quotes.subtotal ---\n";

if (!column_exists($pdo, 'quotes', 'subtotal')) {
    run_step($pdo, "quotes.subtotal", "ALTER TABLE `quotes`
        ADD COLUMN `subtotal` DECIMAL(10,2) DEFAULT 0.00 AFTER `extra_fee_desc`");
} else {
    $log[] = "[SKIP] quotes.subtotal (already exists)";
}

// =============================================================================
// UPGRADE 15 — settings: add all missing defaults
// =============================================================================
echo "\n--- Upgrade 15: settings defaults (invoice_terms, vapid keys) ---\n";

if (table_exists($pdo, 'settings')) {
    $extra_defaults = [
        'invoice_terms'   => 'Payment is due within 30 days of invoice date. Thank you for your business!',
        'vapid_public_key'  => '',
        'vapid_private_key' => '',
        'vapid_subject'     => '',
    ];
    foreach ($extra_defaults as $key => $value) {
        try {
            $pdo->prepare("INSERT IGNORE INTO `settings` (`key`, `value`) VALUES (?, ?)")
                ->execute([$key, $value]);
            $log[] = "[OK]  settings.$key (inserted if missing)";
        } catch (PDOException $e) {
            $errors[] = "[FAIL] settings.$key — " . $e->getMessage();
        }
    }
} else {
    $log[] = "[SKIP] settings table does not exist — skipping extra defaults";
}

// =============================================================================
// UPGRADE 16 — dumpsters: add base_price, rental_days, extra_day_price
// =============================================================================
echo "\n--- Upgrade 16: dumpsters pricing fields ---\n";

if (!column_exists($pdo, 'dumpsters', 'base_price')) {
    run_step($pdo, "dumpsters.base_price", "ALTER TABLE `dumpsters`
        ADD COLUMN `base_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00
        COMMENT 'Flat rental price for the included rental period' AFTER `monthly_rate`");
} else {
    $log[] = "[SKIP] dumpsters.base_price (already exists)";
}

if (!column_exists($pdo, 'dumpsters', 'rental_days')) {
    run_step($pdo, "dumpsters.rental_days", "ALTER TABLE `dumpsters`
        ADD COLUMN `rental_days` INT(11) NOT NULL DEFAULT 7
        COMMENT 'Number of days included in the base price' AFTER `base_price`");
} else {
    $log[] = "[SKIP] dumpsters.rental_days (already exists)";
}

if (!column_exists($pdo, 'dumpsters', 'extra_day_price')) {
    run_step($pdo, "dumpsters.extra_day_price", "ALTER TABLE `dumpsters`
        ADD COLUMN `extra_day_price` DECIMAL(10,2) DEFAULT NULL
        COMMENT 'Per-day charge for days beyond the included rental period' AFTER `rental_days`");
} else {
    $log[] = "[SKIP] dumpsters.extra_day_price (already exists)";
}

// =============================================================================
// UPGRADE 17 — workers table
// =============================================================================
echo "\n--- Upgrade 17: workers table ---\n";

if (!table_exists($pdo, 'workers')) {
    run_step($pdo, "CREATE TABLE workers", "
        CREATE TABLE `workers` (
          `id`         INT(11)      NOT NULL AUTO_INCREMENT,
          `name`       VARCHAR(100) NOT NULL,
          `phone`      VARCHAR(25)           DEFAULT NULL,
          `active`     TINYINT(1)   NOT NULL DEFAULT 1,
          `notes`      TEXT                  DEFAULT NULL,
          `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} else {
    $log[] = "[SKIP] workers table (already exists)";
}

// =============================================================================
// UPGRADE 18 — bookings: add worker_id column
// =============================================================================
echo "\n--- Upgrade 18: bookings.worker_id ---\n";

if (table_exists($pdo, 'bookings') && !column_exists($pdo, 'bookings', 'worker_id')) {
    run_step($pdo, "bookings.worker_id", "ALTER TABLE `bookings`
        ADD COLUMN `worker_id` INT(11) DEFAULT NULL
        COMMENT 'Assigned worker/driver for this booking' AFTER `notes`,
        ADD CONSTRAINT `fk_bookings_worker_id`
          FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE SET NULL");
} else {
    $log[] = "[SKIP] bookings.worker_id (already exists or bookings table missing)";
}

// =============================================================================
// UPGRADE 19 — bookings: add booking_group_id column if missing
// =============================================================================
echo "\n--- Upgrade 19: bookings.booking_group_id ---\n";

if (table_exists($pdo, 'bookings') && !column_exists($pdo, 'bookings', 'booking_group_id')) {
    run_step($pdo, "bookings.booking_group_id", "ALTER TABLE `bookings`
        ADD COLUMN `booking_group_id` VARCHAR(32) DEFAULT NULL
        COMMENT 'Shared key linking multiple units booked together in one session' AFTER `booking_status`,
        ADD KEY `idx_bookings_group` (`booking_group_id`)");
} else {
    $log[] = "[SKIP] bookings.booking_group_id (already exists or bookings table missing)";
}

// =============================================================================
// UPGRADE 20 — dumpsters: add product/pricing fields + Stripe IDs
// =============================================================================
echo "\n--- Upgrade 20: dumpsters product/pricing fields ---\n";

$dumpster_cols = [
    'product_name'      => "ALTER TABLE `dumpsters` ADD COLUMN `product_name` VARCHAR(100) DEFAULT NULL COMMENT 'Display name for this dumpster product' AFTER `unit_code`",
    'description'       => "ALTER TABLE `dumpsters` ADD COLUMN `description` TEXT DEFAULT NULL COMMENT 'Product description shown in UI' AFTER `product_name`",
    'delivery_fee'      => "ALTER TABLE `dumpsters` ADD COLUMN `delivery_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Delivery fee' AFTER `extra_day_price`",
    'pickup_fee'        => "ALTER TABLE `dumpsters` ADD COLUMN `pickup_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Pickup fee' AFTER `delivery_fee`",
    'mileage_fee'       => "ALTER TABLE `dumpsters` ADD COLUMN `mileage_fee` DECIMAL(10,2) DEFAULT NULL COMMENT 'Optional mileage/trip fee' AFTER `pickup_fee`",
    'tax_rate'          => "ALTER TABLE `dumpsters` ADD COLUMN `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Optional tax rate %' AFTER `mileage_fee`",
    'stripe_product_id' => "ALTER TABLE `dumpsters` ADD COLUMN `stripe_product_id` VARCHAR(100) DEFAULT NULL COMMENT 'Linked Stripe product ID' AFTER `tax_rate`",
    'stripe_price_id'   => "ALTER TABLE `dumpsters` ADD COLUMN `stripe_price_id` VARCHAR(100) DEFAULT NULL COMMENT 'Linked Stripe price ID' AFTER `stripe_product_id`",
];

foreach ($dumpster_cols as $col => $sql) {
    if (!column_exists($pdo, 'dumpsters', $col)) {
        run_step($pdo, "dumpsters.$col", $sql);
    } else {
        $log[] = "[SKIP] dumpsters.$col (already exists)";
    }
}

// =============================================================================
// UPGRADE 21 — bookings: add payment_notes column
// =============================================================================
echo "\n--- Upgrade 21: bookings.payment_notes ---\n";

if (table_exists($pdo, 'bookings') && !column_exists($pdo, 'bookings', 'payment_notes')) {
    run_step($pdo, "bookings.payment_notes", "ALTER TABLE `bookings`
        ADD COLUMN `payment_notes` TEXT DEFAULT NULL
        COMMENT 'Notes for manual cash/check payment' AFTER `notes`");
} else {
    $log[] = "[SKIP] bookings.payment_notes (already exists or bookings table missing)";
}

// =============================================================================
// UPGRADE 21b — bookings: add stripe_session_id / stripe_payment_id if missing
// (Needed when upgrading from a schema that pre-dates Stripe support.)
// =============================================================================
echo "\n--- Upgrade 21b: bookings stripe columns ---\n";

if (table_exists($pdo, 'bookings')) {
    if (!column_exists($pdo, 'bookings', 'stripe_session_id')) {
        run_step($pdo, "bookings.stripe_session_id", "ALTER TABLE `bookings`
            ADD COLUMN `stripe_session_id` VARCHAR(255) DEFAULT NULL
            COMMENT 'Stripe Checkout session ID' AFTER `payment_status`");
    } else {
        $log[] = "[SKIP] bookings.stripe_session_id (already exists)";
    }
    if (!column_exists($pdo, 'bookings', 'stripe_payment_id')) {
        run_step($pdo, "bookings.stripe_payment_id", "ALTER TABLE `bookings`
            ADD COLUMN `stripe_payment_id` VARCHAR(255) DEFAULT NULL
            COMMENT 'Stripe PaymentIntent ID' AFTER `stripe_session_id`");
    } else {
        $log[] = "[SKIP] bookings.stripe_payment_id (already exists)";
    }
} else {
    $log[] = "[SKIP] bookings.stripe_session_id/stripe_payment_id (bookings table missing)";
}

// =============================================================================
// UPGRADE 22 — invoices: add stripe_session_id + canceled status
// =============================================================================
echo "\n--- Upgrade 22: invoices stripe_session_id + canceled status ---\n";

if (table_exists($pdo, 'invoices')) {
    if (!column_exists($pdo, 'invoices', 'stripe_session_id')) {
        run_step($pdo, "invoices.stripe_session_id", "ALTER TABLE `invoices`
            ADD COLUMN `stripe_session_id` VARCHAR(255) DEFAULT NULL
            COMMENT 'Stripe Checkout session ID for invoice payment' AFTER `stripe_payment_link`");
    } else {
        $log[] = "[SKIP] invoices.stripe_session_id (already exists)";
    }
    if (!column_exists($pdo, 'invoices', 'payment_method')) {
        run_step($pdo, "invoices.payment_method", "ALTER TABLE `invoices`
            ADD COLUMN `payment_method` ENUM('stripe','cash','check') DEFAULT NULL
            COMMENT 'How the invoice was paid' AFTER `stripe_session_id`");
    } else {
        $log[] = "[SKIP] invoices.payment_method (already exists)";
    }
    if (!column_exists($pdo, 'invoices', 'payment_notes')) {
        run_step($pdo, "invoices.payment_notes", "ALTER TABLE `invoices`
            ADD COLUMN `payment_notes` TEXT DEFAULT NULL
            COMMENT 'Notes for manual payment' AFTER `payment_method`");
    } else {
        $log[] = "[SKIP] invoices.payment_notes (already exists)";
    }
    // Modify status ENUM to add 'canceled' — safe to re-run
    run_step($pdo, "invoices.status add canceled", "ALTER TABLE `invoices`
        MODIFY COLUMN `status` ENUM('draft','sent','paid','void','canceled') NOT NULL DEFAULT 'draft'");
} else {
    $log[] = "[SKIP] invoices table does not exist";
}

// =============================================================================
// UPGRADE 23 — settings: invoice_footer; logo_url for uploaded images
// =============================================================================
echo "\n--- Upgrade 23: invoice_footer setting + logo_url ---\n";

// Ensure the uploads directory exists
$uploads_dir = dirname(__DIR__) . '/uploads';
if (!is_dir($uploads_dir)) {
    if (mkdir($uploads_dir, 0755, true)) {
        $log[] = "[OK]  Created admin/uploads directory";
    } else {
        $errors[] = "[FAIL] Could not create admin/uploads directory";
    }
}
// Add .htaccess to uploads dir for security — only allow image types
$htaccess_path = $uploads_dir . '/.htaccess';
if (!file_exists($htaccess_path)) {
    file_put_contents($htaccess_path,
        "Options -Indexes\n<FilesMatch \"^(?!.*\.(png|jpg|jpeg|gif|webp|svg)$).*$\">\n  Require all denied\n</FilesMatch>\n"
    );
    $log[] = "[OK]  Created uploads/.htaccess security file";
}

$log[] = "[SKIP] invoice_footer setting — inserted via application on first save";

// =============================================================================
// Summary
// =============================================================================
echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULTS:\n\n";

$billingUpgradeFile = __DIR__ . '/billing_upgrade.sql';
if (is_file($billingUpgradeFile)) {
    echo "\n--- Upgrade 24: ACH + recurring billing schema ---\n";
    try {
        $sql = file_get_contents($billingUpgradeFile);
        if ($sql === false) {
            throw new RuntimeException('Unable to read billing_upgrade.sql');
        }

        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);
        foreach ($statements as $statement) {
            $statement = preg_replace('/--[^\n]*/', '', $statement);
            $statement = preg_replace('/\/\*.*?\*\//s', '', $statement);
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (
                    stripos($msg, 'Duplicate column') !== false ||
                    stripos($msg, 'already exists') !== false ||
                    stripos($msg, 'Duplicate key') !== false ||
                    stripos($msg, 'Duplicate foreign key') !== false
                ) {
                    continue;
                }
                $errors[] = '[FAIL] billing upgrade - ' . $msg;
            }
        }
        $log[] = '[OK]  billing_upgrade.sql applied';
    } catch (Throwable $e) {
        $errors[] = '[FAIL] billing upgrade file - ' . $e->getMessage();
    }
}

foreach ($log as $line) {
    echo "  $line\n";
}

if (!empty($errors)) {
    echo "\nERRORS:\n\n";
    foreach ($errors as $line) {
        echo "  $line\n";
    }
    echo "\nUpgrade completed with " . count($errors) . " error(s). Review the errors above.\n";
} else {
    echo "\nAll upgrades applied successfully. No errors.\n";
}

echo "\n[IMPORTANT] Delete or rename this file after running it in production:\n";
echo "  rm admin/install/upgrade.php\n";
echo "  or rename it: mv admin/install/upgrade.php admin/install/upgrade.php.done\n\n";
