-- Migration 005: Performance indexes on status, date, and email columns.
-- Safe to run multiple times (IF NOT EXISTS / error-tolerant ADD INDEX).
-- Run via: mysql -u user -p dbname < migrations/005_performance_indexes.sql
-- Or apply each statement individually in a MySQL client.

-- leads
ALTER TABLE `leads`
  ADD INDEX IF NOT EXISTS `idx_leads_status`     (`status`),
  ADD INDEX IF NOT EXISTS `idx_leads_created_at` (`created_at`);

-- quotes
ALTER TABLE `quotes`
  ADD INDEX IF NOT EXISTS `idx_quotes_status`     (`status`),
  ADD INDEX IF NOT EXISTS `idx_quotes_created_at` (`created_at`);

-- dumpsters
ALTER TABLE `dumpsters`
  ADD INDEX IF NOT EXISTS `idx_dumpsters_status` (`status`),
  ADD INDEX IF NOT EXISTS `idx_dumpsters_active` (`active`);

-- work_orders
ALTER TABLE `work_orders`
  ADD INDEX IF NOT EXISTS `idx_wo_status`        (`status`),
  ADD INDEX IF NOT EXISTS `idx_wo_delivery_date` (`delivery_date`),
  ADD INDEX IF NOT EXISTS `idx_wo_pickup_date`   (`pickup_date`),
  ADD INDEX IF NOT EXISTS `idx_wo_created_at`    (`created_at`);

-- notifications
ALTER TABLE `notifications`
  ADD INDEX IF NOT EXISTS `idx_notif_status`     (`status`),
  ADD INDEX IF NOT EXISTS `idx_notif_created_at` (`created_at`);

-- bookings
ALTER TABLE `bookings`
  ADD INDEX IF NOT EXISTS `idx_bookings_status`         (`booking_status`),
  ADD INDEX IF NOT EXISTS `idx_bookings_payment_status` (`payment_status`),
  ADD INDEX IF NOT EXISTS `idx_bookings_rental_start`   (`rental_start`),
  ADD INDEX IF NOT EXISTS `idx_bookings_email`          (`customer_email`);

-- invoices
ALTER TABLE `invoices`
  ADD INDEX IF NOT EXISTS `idx_invoices_status`     (`status`),
  ADD INDEX IF NOT EXISTS `idx_invoices_cust_email` (`cust_email`),
  ADD INDEX IF NOT EXISTS `idx_invoices_created_at` (`created_at`);

-- activity_log  (used by rate-limit checks and audit queries)
ALTER TABLE `activity_log`
  ADD INDEX IF NOT EXISTS `idx_al_action`     (`action`),
  ADD INDEX IF NOT EXISTS `idx_al_ip`         (`ip_address`),
  ADD INDEX IF NOT EXISTS `idx_al_created_at` (`created_at`);
