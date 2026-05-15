-- =============================================================================
-- fix_booking_500.sql
-- Fixes the 500 error on /api/create-booking.php by adding columns that
-- create-booking.php tries to INSERT but were never added to the live DB.
-- Safe to run multiple times — all statements use IF NOT EXISTS / IF EXISTS.
-- Run this in Hostinger phpMyAdmin on the trash_panda database.
-- =============================================================================

-- 1. Ensure customers table exists (needed by findOrCreateByBooking)
CREATE TABLE IF NOT EXISTS `customers` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `name`            varchar(150) NOT NULL DEFAULT '',
  `email`           varchar(150)          DEFAULT NULL,
  `phone`           varchar(25)           DEFAULT NULL,
  `address`         varchar(255)          DEFAULT NULL,
  `city`            varchar(100)          DEFAULT NULL,
  `state`           varchar(50)           DEFAULT NULL,
  `zip`             varchar(20)           DEFAULT NULL,
  `notes`           text                  DEFAULT NULL,
  `stripe_customer_id` varchar(100)       DEFAULT NULL,
  `lead_id`         int(11)               DEFAULT NULL,
  `created_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customers_email` (`email`),
  KEY `idx_customers_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add customer_id to bookings (added by billing_upgrade.sql, may be missing)
ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `customer_id` int(11) DEFAULT NULL AFTER `customer_city`;

-- 3. Add terms audit columns (only added by upgrade.php, never in any .sql file)
ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `terms_accepted_at` DATETIME    DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `terms_accepted_ip` VARCHAR(45) DEFAULT NULL AFTER `terms_accepted_at`;

-- 4. Add 'ach' to the payment_method ENUM (also from billing_upgrade.sql)
ALTER TABLE `bookings`
  MODIFY COLUMN `payment_method`
    ENUM('stripe','ach','cash','check') NOT NULL DEFAULT 'stripe';

-- 5. Migration 002: allow multiple units to share one booking number.
--    Drops the UNIQUE constraint so two rows can have the same BK-XXXX.
ALTER TABLE `bookings` DROP INDEX IF EXISTS `uq_bookings_number`;
ALTER TABLE `bookings` ADD INDEX IF NOT EXISTS `idx_bookings_number` (`booking_number`);

-- Done. Bookings should work now.
