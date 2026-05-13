-- =============================================================================
-- ACH + Recurring Billing Upgrade
-- Safe to run multiple times on MySQL 8 / MariaDB with IF NOT EXISTS support.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `service_name` varchar(150) NOT NULL,
  `service_address` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `interval_unit` enum('day','week','month','year') NOT NULL DEFAULT 'month',
  `interval_count` int(11) NOT NULL DEFAULT 1,
  `billing_anchor` date DEFAULT NULL,
  `next_billing_date` date DEFAULT NULL,
  `stripe_subscription_id` varchar(100) DEFAULT NULL,
  `stripe_price_id` varchar(100) DEFAULT NULL,
  `stripe_payment_method_id` varchar(100) DEFAULT NULL,
  `status` enum('trialing','active','past_due','paused','canceled','incomplete') NOT NULL DEFAULT 'active',
  `stripe_status` varchar(50) DEFAULT NULL,
  `autopay_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `retry_count` int(11) NOT NULL DEFAULT 0,
  `last_paid_at` datetime DEFAULT NULL,
  `paused_at` datetime DEFAULT NULL,
  `cancel_at` datetime DEFAULT NULL,
  `canceled_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subscriptions_stripe_subscription_id` (`stripe_subscription_id`),
  KEY `idx_subscriptions_customer_status` (`customer_id`,`status`),
  CONSTRAINT `fk_subscriptions_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `stripe_payment_method_id` varchar(100) NOT NULL,
  `type` enum('card','us_bank_account') NOT NULL,
  `brand` varchar(50) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `last4` varchar(10) DEFAULT NULL,
  `exp_month` int(11) DEFAULT NULL,
  `exp_year` int(11) DEFAULT NULL,
  `account_holder_type` varchar(50) DEFAULT NULL,
  `verification_status` varchar(50) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_methods_stripe_payment_method_id` (`stripe_payment_method_id`),
  KEY `idx_payment_methods_customer_default` (`customer_id`,`is_default`,`is_active`),
  CONSTRAINT `fk_payment_methods_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `subscription_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) NOT NULL DEFAULT 'usd',
  `payment_method` enum('stripe','ach','cash','check') NOT NULL DEFAULT 'stripe',
  `payment_status` enum('unpaid','pending','processing','paid','failed','refunded','canceled','pending_cash','paid_cash','pending_check','paid_check') NOT NULL DEFAULT 'pending',
  `stripe_session_id` varchar(255) DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) DEFAULT NULL,
  `stripe_charge_id` varchar(255) DEFAULT NULL,
  `stripe_customer_id` varchar(100) DEFAULT NULL,
  `stripe_payment_method_id` varchar(100) DEFAULT NULL,
  `failure_code` varchar(100) DEFAULT NULL,
  `failure_message` varchar(255) DEFAULT NULL,
  `refunded_amount` decimal(10,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_stripe_payment_intent_id` (`stripe_payment_intent_id`),
  KEY `idx_payments_booking_id` (`booking_id`),
  KEY `idx_payments_invoice_id` (`invoice_id`),
  KEY `idx_payments_customer_status` (`customer_id`,`payment_status`),
  CONSTRAINT `fk_payments_booking_id` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_invoice_id` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_subscription_id` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recurring_invoice_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subscription_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `stripe_invoice_id` varchar(100) DEFAULT NULL,
  `billing_date` datetime NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('paid','failed','void') NOT NULL DEFAULT 'paid',
  `failure_message` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recurring_invoice_logs_subscription_date` (`subscription_id`,`billing_date`),
  CONSTRAINT `fk_recurring_invoice_logs_subscription_id` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recurring_invoice_logs_invoice_id` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webhook_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stripe_event_id` varchar(100) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `livemode` tinyint(1) NOT NULL DEFAULT 0,
  `api_version` varchar(50) DEFAULT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `payload_excerpt` text DEFAULT NULL,
  `processing_status` enum('received','processed','duplicate','failed') NOT NULL DEFAULT 'received',
  `attempt_count` int(11) NOT NULL DEFAULT 1,
  `related_entity_type` varchar(50) DEFAULT NULL,
  `related_entity_id` int(11) DEFAULT NULL,
  `error_message` varchar(1000) DEFAULT NULL,
  `first_processed_at` datetime DEFAULT NULL,
  `last_processed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_logs_stripe_event_id` (`stripe_event_id`),
  KEY `idx_webhook_logs_type_status` (`event_type`,`processing_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `stripe_customer_id` varchar(100) DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `default_payment_method_id` int(11) DEFAULT NULL AFTER `stripe_customer_id`,
  ADD COLUMN IF NOT EXISTS `portal_access_token_hash` varchar(255) DEFAULT NULL AFTER `default_payment_method_id`,
  ADD COLUMN IF NOT EXISTS `portal_access_expires_at` datetime DEFAULT NULL AFTER `portal_access_token_hash`,
  ADD COLUMN IF NOT EXISTS `billing_status` varchar(50) DEFAULT NULL AFTER `portal_access_expires_at`;

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `customer_id` int(11) DEFAULT NULL AFTER `customer_city`,
  ADD COLUMN IF NOT EXISTS `subscription_id` int(11) DEFAULT NULL AFTER `customer_id`,
  ADD COLUMN IF NOT EXISTS `billing_type` enum('one_time','subscription') NOT NULL DEFAULT 'one_time' AFTER `subscription_id`,
  ADD COLUMN IF NOT EXISTS `billing_cycle_label` varchar(100) DEFAULT NULL AFTER `billing_type`,
  ADD COLUMN IF NOT EXISTS `autopay_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `billing_cycle_label`;

ALTER TABLE `invoices`
  ADD COLUMN IF NOT EXISTS `stripe_invoice_id` varchar(100) DEFAULT NULL AFTER `stripe_session_id`,
  ADD COLUMN IF NOT EXISTS `stripe_hosted_invoice_url` varchar(500) DEFAULT NULL AFTER `stripe_invoice_id`,
  ADD COLUMN IF NOT EXISTS `stripe_payment_intent_id` varchar(255) DEFAULT NULL AFTER `stripe_hosted_invoice_url`,
  ADD COLUMN IF NOT EXISTS `subscription_id` int(11) DEFAULT NULL AFTER `customer_id`,
  ADD COLUMN IF NOT EXISTS `collection_method` varchar(50) DEFAULT NULL AFTER `stripe_payment_intent_id`,
  ADD COLUMN IF NOT EXISTS `billing_reason` varchar(100) DEFAULT NULL AFTER `collection_method`,
  ADD COLUMN IF NOT EXISTS `paid_at` datetime DEFAULT NULL AFTER `billing_reason`,
  ADD COLUMN IF NOT EXISTS `failed_at` datetime DEFAULT NULL AFTER `paid_at`;

ALTER TABLE `notifications`
  ADD COLUMN IF NOT EXISTS `template_key` varchar(100) DEFAULT NULL AFTER `subject`,
  ADD COLUMN IF NOT EXISTS `context_json` text DEFAULT NULL AFTER `related_id`;

ALTER TABLE `bookings`
  MODIFY COLUMN `payment_method` ENUM('stripe','ach','cash','check') NOT NULL DEFAULT 'stripe',
  MODIFY COLUMN `payment_status` ENUM('unpaid','pending','processing','paid','failed','refunded','canceled','pending_cash','paid_cash','pending_check','paid_check') NOT NULL DEFAULT 'unpaid';

ALTER TABLE `invoices`
  MODIFY COLUMN `status` ENUM('draft','sent','open','paid','void','canceled','uncollectible') NOT NULL DEFAULT 'draft',
  MODIFY COLUMN `payment_method` ENUM('stripe','ach','cash','check') DEFAULT NULL;

ALTER TABLE `customers`
  ADD UNIQUE KEY `uq_customers_stripe_customer_id` (`stripe_customer_id`);

ALTER TABLE `bookings`
  ADD KEY `idx_bookings_customer_id` (`customer_id`),
  ADD KEY `idx_bookings_subscription_id` (`subscription_id`);

ALTER TABLE `invoices`
  ADD KEY `idx_invoices_subscription_id` (`subscription_id`),
  ADD KEY `idx_invoices_stripe_invoice_id` (`stripe_invoice_id`);

ALTER TABLE `notifications`
  ADD KEY `idx_notifications_template_key` (`template_key`);

ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_default_payment_method_id`
    FOREIGN KEY (`default_payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL;

ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_bookings_customer_id`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL;

ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_bookings_subscription_id`
    FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE SET NULL;

ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_subscription_id`
    FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE SET NULL;

INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('portal_signing_key', ''),
  ('portal_link_ttl_minutes', '30'),
  ('stripe_statement_descriptor', ''),
  ('billing_email_enabled', '1'),
  ('billing_retry_days', '[1,3,5]'),
  ('ach_enabled', '1'),
  ('subscription_enabled', '1');

UPDATE `bookings` b
LEFT JOIN `customers` c
  ON (
    (c.email IS NOT NULL AND c.email != '' AND LOWER(c.email) = LOWER(b.customer_email))
    OR
    (c.phone IS NOT NULL AND c.phone != '' AND c.phone = b.customer_phone)
  )
SET b.customer_id = c.id
WHERE b.customer_id IS NULL;

UPDATE `invoices` i
LEFT JOIN `customers` c
  ON (
    (c.email IS NOT NULL AND c.email != '' AND LOWER(c.email) = LOWER(i.cust_email))
    OR
    (c.phone IS NOT NULL AND c.phone != '' AND c.phone = i.cust_phone)
  )
SET i.customer_id = c.id
WHERE i.customer_id IS NULL;
