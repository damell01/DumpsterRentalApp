-- =============================================================================
-- Upgrade Migration — run this on existing databases to add new features
-- Safe to run multiple times (uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)
-- =============================================================================

-- 1. Fix missing `subtotal` column in quotes table (should come before tax_rate logically)
ALTER TABLE `quotes`
  ADD COLUMN IF NOT EXISTS `subtotal` DECIMAL(10,2) DEFAULT 0.00 AFTER `extra_fee_desc`;

-- 2. Add weekly and monthly rates to dumpsters
ALTER TABLE `dumpsters`
  ADD COLUMN IF NOT EXISTS `type`         ENUM('dumpster','trailer') NOT NULL DEFAULT 'dumpster' AFTER `unit_code`,
  ADD COLUMN IF NOT EXISTS `daily_rate`   DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `size`,
  ADD COLUMN IF NOT EXISTS `weekly_rate`  DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `daily_rate`,
  ADD COLUMN IF NOT EXISTS `monthly_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `weekly_rate`,
  ADD COLUMN IF NOT EXISTS `active`       TINYINT(1) NOT NULL DEFAULT 1 AFTER `monthly_rate`,
  ADD COLUMN IF NOT EXISTS `image`        VARCHAR(255) DEFAULT NULL AFTER `active`;

-- 3. Bookings table (if not already present)
CREATE TABLE IF NOT EXISTS `bookings` (
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
  `booking_group_id`   VARCHAR(32)            DEFAULT NULL,
  `notes`              TEXT                   DEFAULT NULL,
  `created_by`         INT(11)                DEFAULT NULL,
  `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bookings_number` (`booking_number`),
  KEY `idx_bookings_group` (`booking_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Inventory blocks table (if not already present)
CREATE TABLE IF NOT EXISTS `inventory_blocks` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `dumpster_id`  INT(11)      NOT NULL,
  `block_start`  DATE         NOT NULL,
  `block_end`    DATE         NOT NULL,
  `reason`       VARCHAR(200)          DEFAULT NULL,
  `created_by`   INT(11)               DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Invoices table (custom invoices with Stripe payment links)
CREATE TABLE IF NOT EXISTS `invoices` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Invoice items table
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id`          INT(11)       NOT NULL AUTO_INCREMENT,
  `invoice_id`  INT(11)       NOT NULL,
  `description` VARCHAR(255)  NOT NULL,
  `quantity`    DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit_price`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `rate_type`   ENUM('fixed','daily','weekly','monthly') NOT NULL DEFAULT 'fixed',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Default settings
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('stripe_publishable_key', ''),
  ('stripe_secret_key',      ''),
  ('stripe_webhook_secret',  ''),
  ('stripe_mode',            'test'),
  ('booking_terms',          'By completing this booking, you agree to our rental terms and conditions.'),
  ('currency',               'usd'),
  ('invoice_terms',          'Payment is due within 30 days of invoice date. Thank you for your business!'),
  ('portal_signing_key',     ''),
  ('portal_link_ttl_minutes','30'),
  ('stripe_statement_descriptor',''),
  ('billing_email_enabled',  '1'),
  ('billing_retry_days',     '[1,3,5]'),
  ('ach_enabled',            '1'),
  ('subscription_enabled',   '1');
