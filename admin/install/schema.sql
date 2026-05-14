-- =============================================================================
-- Trash Panda Roll-Offs – Database Schema
-- Run via install/install.php or manually with: mysql -u user -p dbname < schema.sql
-- Payments are handled outside the system by the business.
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- users
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `name`           varchar(100) NOT NULL,
  `email`          varchar(150) NOT NULL,
  `password`       varchar(255) NOT NULL,
  `role`           ENUM('admin','office','dispatcher','readonly') NOT NULL DEFAULT 'office',
  `active`         tinyint(1)   NOT NULL DEFAULT 1,
  `must_change_pw` tinyint(1)   NOT NULL DEFAULT 0,
  `last_login`     datetime              DEFAULT NULL,
  `created_at`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- customers  (must come before leads so the FK in leads can reference it)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `name`            varchar(100) NOT NULL,
  `company`         varchar(150)          DEFAULT NULL,
  `email`           varchar(150)          DEFAULT NULL,
  `phone`           varchar(20)           DEFAULT NULL,
  `address`         varchar(200)          DEFAULT NULL,
  `city`            varchar(100)          DEFAULT NULL,
  `state`           varchar(50)           DEFAULT NULL,
  `zip`             varchar(20)           DEFAULT NULL,
  `billing_address` varchar(200)          DEFAULT NULL,
  `billing_city`    varchar(100)          DEFAULT NULL,
  `billing_state`   varchar(50)           DEFAULT NULL,
  `billing_zip`     varchar(20)           DEFAULT NULL,
  `type`            ENUM('residential','commercial','contractor') NOT NULL DEFAULT 'residential',
  `notes`           text                  DEFAULT NULL,
  `lead_id`         int(11)               DEFAULT NULL,
  `created_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- leads  (references users and customers)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leads` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `name`         varchar(100) NOT NULL,
  `email`        varchar(150)          DEFAULT NULL,
  `phone`        varchar(20)           DEFAULT NULL,
  `address`      varchar(200)          DEFAULT NULL,
  `city`         varchar(100)          DEFAULT NULL,
  `state`        varchar(50)           DEFAULT NULL,
  `zip`          varchar(20)           DEFAULT NULL,
  `size_needed`  varchar(50)           DEFAULT NULL,
  `project_type` varchar(100)          DEFAULT NULL,
  `source`       varchar(100)          DEFAULT NULL,
  `message`      text                  DEFAULT NULL,
  `status`       ENUM('new','contacted','quoted','won','lost') NOT NULL DEFAULT 'new',
  `assigned_to`  int(11)               DEFAULT NULL,
  `converted_to` int(11)               DEFAULT NULL,
  `archived`     tinyint(1)   NOT NULL DEFAULT 0,
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_leads_assigned_to`  FOREIGN KEY (`assigned_to`)  REFERENCES `users`     (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leads_converted_to` FOREIGN KEY (`converted_to`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Now that leads exists, add the FK from customers.lead_id → leads.id
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_lead_id`
    FOREIGN KEY IF NOT EXISTS (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- quotes
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quotes` (
  `id`             int(11)        NOT NULL AUTO_INCREMENT,
  `quote_number`   varchar(20)    NOT NULL,
  `customer_id`    int(11)                 DEFAULT NULL,
  `lead_id`        int(11)                 DEFAULT NULL,
  `cust_name`      varchar(100)   NOT NULL,
  `cust_email`     varchar(150)            DEFAULT NULL,
  `cust_phone`     varchar(20)             DEFAULT NULL,
  `cust_address`   varchar(200)            DEFAULT NULL,
  `size`           varchar(50)             DEFAULT NULL,
  `project_type`   varchar(100)            DEFAULT NULL,
  `service_address` varchar(200)           DEFAULT NULL,
  `service_city`   varchar(100)            DEFAULT NULL,
  `rental_days`    int(11)                 DEFAULT NULL,
  `rental_price`   decimal(10,2)           DEFAULT NULL,
  `delivery_fee`   decimal(10,2)           DEFAULT 0.00,
  `pickup_fee`     decimal(10,2)           DEFAULT 0.00,
  `extra_fees`     decimal(10,2)           DEFAULT 0.00,
  `extra_fee_desc` varchar(200)            DEFAULT NULL,
  `tax_rate`       decimal(5,2)            DEFAULT 0.00,
  `tax_amount`     decimal(10,2)           DEFAULT 0.00,
  `subtotal`       decimal(10,2)           DEFAULT 0.00,
  `total`          decimal(10,2)           DEFAULT 0.00,
  `notes`          text                    DEFAULT NULL,
  `terms`          text                    DEFAULT NULL,
  `status`         ENUM('draft','sent','approved','rejected') NOT NULL DEFAULT 'draft',
  `valid_until`    date                    DEFAULT NULL,
  `created_by`     int(11)                 DEFAULT NULL,
  `converted_to`   int(11)                 DEFAULT NULL,
  `created_at`     datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quotes_number` (`quote_number`),
  CONSTRAINT `fk_quotes_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_quotes_lead_id`     FOREIGN KEY (`lead_id`)     REFERENCES `leads`     (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_quotes_created_by`  FOREIGN KEY (`created_by`)  REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- dumpsters
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dumpsters` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `unit_code`       varchar(50)  NOT NULL,
  `product_name`    varchar(100)          DEFAULT NULL COMMENT 'Display name for this dumpster product',
  `type`            ENUM('dumpster','trailer') NOT NULL DEFAULT 'dumpster',
  `size`            varchar(50)  NOT NULL,
  `description`     text                  DEFAULT NULL COMMENT 'Product description',
  `daily_rate`      decimal(10,2)         NOT NULL DEFAULT 0.00,
  `weekly_rate`     decimal(10,2)         NOT NULL DEFAULT 0.00,
  `monthly_rate`    decimal(10,2)         NOT NULL DEFAULT 0.00,
  `base_price`      decimal(10,2)         NOT NULL DEFAULT 0.00 COMMENT 'Flat rental price for the included rental period',
  `rental_days`     int(11)               NOT NULL DEFAULT 7    COMMENT 'Number of days included in the base price',
  `extra_day_price` decimal(10,2)                  DEFAULT NULL COMMENT 'Per-day charge for days beyond the included rental period',
  `delivery_fee`    decimal(10,2)         NOT NULL DEFAULT 0.00 COMMENT 'Delivery fee',
  `pickup_fee`      decimal(10,2)         NOT NULL DEFAULT 0.00 COMMENT 'Pickup fee',
  `mileage_fee`     decimal(10,2)                  DEFAULT NULL COMMENT 'Optional mileage/trip fee',
  `tax_rate`        decimal(5,2)          NOT NULL DEFAULT 0.00 COMMENT 'Optional tax rate %',
  `stripe_product_id` varchar(100)        DEFAULT NULL COMMENT 'Linked Stripe product ID',
  `stripe_price_id`   varchar(100)        DEFAULT NULL COMMENT 'Linked Stripe price ID',
  `active`          tinyint(1)            NOT NULL DEFAULT 1,
  `image`           varchar(255)                   DEFAULT NULL,
  `status`       ENUM('available','reserved','in_use','maintenance') NOT NULL DEFAULT 'available',
  `condition`    ENUM('excellent','good','fair','poor')              NOT NULL DEFAULT 'good',
  `notes`        text                  DEFAULT NULL,
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dumpsters_unit_code` (`unit_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- workers  (simple driver/worker roster for job assignment)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `workers` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `phone`      varchar(25)           DEFAULT NULL,
  `active`     tinyint(1)   NOT NULL DEFAULT 1,
  `notes`      text                  DEFAULT NULL,
  `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- work_orders
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `work_orders` (
  `id`              int(11)       NOT NULL AUTO_INCREMENT,
  `wo_number`       varchar(20)   NOT NULL,
  `customer_id`     int(11)                DEFAULT NULL,
  `quote_id`        int(11)                DEFAULT NULL,
  `cust_name`       varchar(100)  NOT NULL,
  `cust_email`      varchar(150)           DEFAULT NULL,
  `cust_phone`      varchar(20)            DEFAULT NULL,
  `service_address` varchar(200)  NOT NULL,
  `service_city`    varchar(100)           DEFAULT NULL,
  `service_state`   varchar(50)            DEFAULT NULL,
  `service_zip`     varchar(20)            DEFAULT NULL,
  `size`            varchar(50)            DEFAULT NULL,
  `dumpster_id`     int(11)                DEFAULT NULL,
  `project_type`    varchar(100)           DEFAULT NULL,
  `delivery_date`   date                   DEFAULT NULL,
  `pickup_date`     date                   DEFAULT NULL,
  `actual_pickup`   date                   DEFAULT NULL,
  `assigned_driver` int(11)                DEFAULT NULL,
  `amount`          decimal(10,2)          DEFAULT 0.00,
  `status`          ENUM('scheduled','delivered','active','pickup_requested','picked_up','completed','canceled') NOT NULL DEFAULT 'scheduled',
  `priority`        ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
  `internal_notes`  text                   DEFAULT NULL,
  `footer_notes`    text                   DEFAULT NULL,
  `created_by`      int(11)                DEFAULT NULL,
  `created_at`      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_work_orders_number` (`wo_number`),
  CONSTRAINT `fk_wo_customer_id`    FOREIGN KEY (`customer_id`)    REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wo_quote_id`       FOREIGN KEY (`quote_id`)       REFERENCES `quotes`    (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wo_dumpster_id`    FOREIGN KEY (`dumpster_id`)    REFERENCES `dumpsters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wo_assigned_driver` FOREIGN KEY (`assigned_driver`) REFERENCES `users`   (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wo_created_by`     FOREIGN KEY (`created_by`)     REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- work_order_notes
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `work_order_notes` (
  `id`         int(11)   NOT NULL AUTO_INCREMENT,
  `wo_id`      int(11)   NOT NULL,
  `user_id`    int(11)            DEFAULT NULL,
  `note`       text      NOT NULL,
  `note_type`  ENUM('note','status_change','system') NOT NULL DEFAULT 'note',
  `created_at` datetime  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_won_wo_id`   FOREIGN KEY (`wo_id`)   REFERENCES `work_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_won_user_id` FOREIGN KEY (`user_id`) REFERENCES `users`       (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- lead_notes
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lead_notes` (
  `id`         int(11)   NOT NULL AUTO_INCREMENT,
  `lead_id`    int(11)   NOT NULL,
  `user_id`    int(11)            DEFAULT NULL,
  `note`       text      NOT NULL,
  `created_at` datetime  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ln_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ln_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- activity_log
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     int(11)               DEFAULT NULL,
  `action`      varchar(100) NOT NULL,
  `description` text                  DEFAULT NULL,
  `entity_type` varchar(50)           DEFAULT NULL,
  `entity_id`   int(11)               DEFAULT NULL,
  `ip_address`  varchar(45)           DEFAULT NULL,
  `created_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_al_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- settings
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `key`        varchar(100) NOT NULL,
  `value`      text                  DEFAULT NULL,
  `updated_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- notifications
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `type`         ENUM('email','sms') NOT NULL DEFAULT 'email',
  `recipient`    varchar(180)          DEFAULT NULL,
  `subject`      varchar(255)          DEFAULT NULL,
  `body`         text                  DEFAULT NULL,
  `status`       ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `related_type` varchar(50)           DEFAULT NULL,
  `related_id`   int(11)               DEFAULT NULL,
  `sent_at`      datetime              DEFAULT NULL,
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- bookings  (online/admin-created rental bookings)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bookings` (
  `id`                INT(11)       NOT NULL AUTO_INCREMENT,
  `booking_number`    VARCHAR(20)   NOT NULL,
  `customer_name`     VARCHAR(100)  NOT NULL,
  `customer_phone`    VARCHAR(25)            DEFAULT NULL,
  `customer_email`    VARCHAR(150)           DEFAULT NULL,
  `customer_address`  VARCHAR(200)           DEFAULT NULL,
  `customer_city`     VARCHAR(100)           DEFAULT NULL,
  `dumpster_id`       INT(11)                DEFAULT NULL,
  `unit_code`         VARCHAR(50)            DEFAULT NULL,
  `unit_type`         VARCHAR(50)            DEFAULT NULL,
  `unit_size`         VARCHAR(50)            DEFAULT NULL,
  `rental_start`      DATE          NOT NULL,
  `rental_end`        DATE          NOT NULL,
  `rental_days`       INT(11)       NOT NULL DEFAULT 1,
  `daily_rate`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method`    ENUM('stripe','cash','check') NOT NULL DEFAULT 'stripe',
  `payment_status`    ENUM('unpaid','pending','paid','refunded','pending_cash','paid_cash','pending_check','paid_check') NOT NULL DEFAULT 'unpaid',
  `stripe_session_id` VARCHAR(255)           DEFAULT NULL,
  `stripe_payment_id` VARCHAR(255)           DEFAULT NULL,
  `booking_status`    ENUM('pending','confirmed','paid','canceled','completed') NOT NULL DEFAULT 'pending',
  `booking_group_id`  VARCHAR(32)            DEFAULT NULL COMMENT 'Shared key linking multiple units booked together in one session',
  `notes`             TEXT                   DEFAULT NULL,
  `created_by`        INT(11)                DEFAULT NULL,
  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bookings_number` (`booking_number`),
  KEY `idx_bookings_group` (`booking_group_id`),
  CONSTRAINT `fk_bookings_dumpster_id` FOREIGN KEY (`dumpster_id`) REFERENCES `dumpsters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_created_by`  FOREIGN KEY (`created_by`)  REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- inventory_blocks  (admin-managed unavailability periods)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_blocks` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `dumpster_id` INT(11)      NOT NULL,
  `block_start` DATE         NOT NULL,
  `block_end`   DATE         NOT NULL,
  `reason`      VARCHAR(200)          DEFAULT NULL,
  `created_by`  INT(11)               DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ib_dumpster_id` FOREIGN KEY (`dumpster_id`) REFERENCES `dumpsters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ib_created_by`  FOREIGN KEY (`created_by`)  REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- invoices  (custom invoices with optional Stripe payment links)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoices` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `invoice_number`      VARCHAR(20)   NOT NULL,
  `customer_id`         INT(11)                DEFAULT NULL,
  `work_order_id`       INT(11)                DEFAULT NULL,
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
  `status`              ENUM('draft','sent','paid','void','canceled') NOT NULL DEFAULT 'draft',
  `due_date`            DATE                   DEFAULT NULL,
  `stripe_payment_link` VARCHAR(500)           DEFAULT NULL,
  `stripe_session_id`   VARCHAR(255)           DEFAULT NULL COMMENT 'Stripe Checkout session ID for invoice payment',
  `payment_method`      ENUM('stripe','cash','check') DEFAULT NULL COMMENT 'How the invoice was paid',
  `payment_notes`       TEXT                   DEFAULT NULL COMMENT 'Notes for manual payment',
  `created_by`          INT(11)                DEFAULT NULL,
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoices_number` (`invoice_number`),
  KEY `idx_invoices_work_order_id` (`work_order_id`),
  CONSTRAINT `fk_inv_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_work_order_id` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_created_by`  FOREIGN KEY (`created_by`)  REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- invoice_items
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id`          INT(11)       NOT NULL AUTO_INCREMENT,
  `invoice_id`  INT(11)       NOT NULL,
  `description` VARCHAR(255)  NOT NULL,
  `quantity`    DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit_price`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `rate_type`   ENUM('fixed','daily','weekly','monthly') NOT NULL DEFAULT 'fixed',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ii_invoice_id` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- push_subscriptions  (Web Push API subscriptions)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `subscriber_type` ENUM('admin','customer') NOT NULL DEFAULT 'customer',
  `subscriber_id`   VARCHAR(200)  NOT NULL COMMENT 'admin user_id (int as string) or customer email/phone',
  `endpoint`        TEXT          NOT NULL,
  `p256dh`          VARCHAR(255)  NOT NULL,
  `auth`            VARCHAR(64)   NOT NULL,
  `user_agent`      VARCHAR(255)           DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_endpoint` (endpoint(200)),
  KEY `idx_push_subscriber` (`subscriber_type`, `subscriber_id`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- two_factor_secrets
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `two_factor_secrets` (
  `user_id`      int(11)     NOT NULL,
  `secret`       varchar(64) NOT NULL,
  `enabled`      tinyint(1)  NOT NULL DEFAULT 0,
  `backup_codes` text                  DEFAULT NULL,
  `created_at`   datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_tfs_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- login_attempts
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `ip_address`   varchar(45)  NOT NULL,
  `email`        varchar(180)          DEFAULT NULL,
  `attempted_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip`   (`ip_address`),
  KEY `idx_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- rate_limit_locks
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limit_locks` (
  `ip_address`   varchar(45) NOT NULL,
  `attempts`     int(11)     NOT NULL DEFAULT 0,
  `locked_until` datetime             DEFAULT NULL,
  `updated_at`   datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- default settings
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('stripe_publishable_key', ''),
  ('stripe_secret_key',      ''),
  ('stripe_webhook_secret',  ''),
  ('stripe_mode',            'test'),
  ('booking_terms',          'By completing this booking, you agree to our rental terms and conditions.'),
  ('currency',               'usd'),
  ('invoice_terms',          'Payment is due within 30 days of invoice date. Thank you for your business!'),
  ('vapid_public_key',       ''),
  ('vapid_private_key',      ''),
  ('vapid_subject',          ''),
  ('portal_signing_key',     ''),
  ('portal_link_ttl_minutes','30'),
  ('stripe_statement_descriptor',''),
  ('billing_email_enabled',  '1'),
  ('billing_retry_days',     '[1,3,5]'),
  ('booking_flow_mode',      'instant'),
  ('ach_enabled',            '1'),
  ('subscription_enabled',   '1');
