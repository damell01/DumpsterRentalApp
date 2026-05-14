-- Migration 002: Allow multiple units to share one booking number.
-- Multi-unit orders (booking_group_id is set) all use the same BK-XXXX number
-- so the customer sees one order, not one number per dumpster.
-- Safe to run multiple times (IF EXISTS / IF NOT EXISTS guards).

ALTER TABLE `bookings` DROP INDEX IF EXISTS `uq_bookings_number`;
ALTER TABLE `bookings` ADD INDEX IF NOT EXISTS `idx_bookings_number` (`booking_number`);

-- Indexes for customer deduplication lookups (email + phone).
ALTER TABLE `customers` ADD INDEX IF NOT EXISTS `idx_customers_email` (`email`);
ALTER TABLE `customers` ADD INDEX IF NOT EXISTS `idx_customers_phone` (`phone`);
