-- =============================================================================
-- Trash Panda Roll-Offs - Billing / ACH / Subscription Mock Data
-- Import this ONLY AFTER:
-- 1. installer has completed
-- 2. core mock data has been imported
-- 3. billing database upgrade has been run
--
-- Import example:
--   mysql -u USER -p DB_NAME < admin/install/mock_data_billing.sql
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- Customer billing fields
-- -----------------------------------------------------------------------------
UPDATE `customers`
SET
  `stripe_customer_id` = 'cus_demo_sarah_9001',
  `billing_status` = 'active'
WHERE `id` = 9001;

UPDATE `customers`
SET
  `stripe_customer_id` = 'cus_demo_james_9002',
  `billing_status` = 'manual'
WHERE `id` = 9002;

UPDATE `customers`
SET
  `stripe_customer_id` = 'cus_demo_angela_9003',
  `billing_status` = 'past_due'
WHERE `id` = 9003;

-- -----------------------------------------------------------------------------
-- Payment methods
-- -----------------------------------------------------------------------------
INSERT INTO `payment_methods`
(`id`,`customer_id`,`stripe_payment_method_id`,`type`,`brand`,`bank_name`,`last4`,
 `exp_month`,`exp_year`,`account_holder_type`,`verification_status`,
 `is_default`,`is_active`,`created_at`,`updated_at`)
VALUES
(9901,9001,'pm_demo_card_9001','card','visa',NULL,'4242',
 12,2028,NULL,NULL,
 1,1,'2026-05-01 09:00:00','2026-05-12 09:00:00'),
(9902,9003,'pm_demo_ach_9003','us_bank_account',NULL,'First National Demo','6789',
 NULL,NULL,'company','verified',
 1,1,'2026-05-02 10:00:00','2026-05-12 10:00:00')
ON DUPLICATE KEY UPDATE
`customer_id` = VALUES(`customer_id`),
`stripe_payment_method_id` = VALUES(`stripe_payment_method_id`),
`type` = VALUES(`type`),
`brand` = VALUES(`brand`),
`bank_name` = VALUES(`bank_name`),
`last4` = VALUES(`last4`),
`exp_month` = VALUES(`exp_month`),
`exp_year` = VALUES(`exp_year`),
`account_holder_type` = VALUES(`account_holder_type`),
`verification_status` = VALUES(`verification_status`),
`is_default` = VALUES(`is_default`),
`is_active` = VALUES(`is_active`),
`updated_at` = VALUES(`updated_at`);

UPDATE `customers` SET `default_payment_method_id` = 9901 WHERE `id` = 9001;
UPDATE `customers` SET `default_payment_method_id` = 9902 WHERE `id` = 9003;

-- -----------------------------------------------------------------------------
-- Subscriptions
-- -----------------------------------------------------------------------------
INSERT INTO `subscriptions`
(`id`,`customer_id`,`service_name`,`service_address`,`amount`,
 `interval_unit`,`interval_count`,`billing_anchor`,`next_billing_date`,
 `stripe_subscription_id`,`stripe_price_id`,`stripe_payment_method_id`,
 `status`,`stripe_status`,`autopay_enabled`,`retry_count`,`last_paid_at`,
 `paused_at`,`cancel_at`,`canceled_at`,`notes`,`created_at`,`updated_at`)
VALUES
(9951,9001,'Weekly Roofing Dump Service','104 Oak Ridge Dr, Foley, AL',425.00,
 'week',1,'2026-05-14','2026-05-21',
 'sub_demo_9001','price_demo_weekly_9001','pm_demo_card_9001',
 'active','active',1,0,'2026-05-14 08:16:00',
 NULL,NULL,NULL,'Healthy recurring contractor account.','2026-05-10 08:16:00','2026-05-12 08:16:00'),
(9952,9003,'Bi-Weekly Property Cleanup Service','17 Harbor Point Ln, Orange Beach, AL',486.00,
 'week',2,'2026-05-06','2026-05-20',
 'sub_demo_9003','price_demo_biweekly_9003','pm_demo_ach_9003',
 'past_due','past_due',1,2,'2026-05-06 09:00:00',
 NULL,NULL,NULL,'ACH customer currently in retry window.','2026-05-04 09:00:00','2026-05-12 09:45:00')
ON DUPLICATE KEY UPDATE
`customer_id` = VALUES(`customer_id`),
`service_name` = VALUES(`service_name`),
`service_address` = VALUES(`service_address`),
`amount` = VALUES(`amount`),
`interval_unit` = VALUES(`interval_unit`),
`interval_count` = VALUES(`interval_count`),
`billing_anchor` = VALUES(`billing_anchor`),
`next_billing_date` = VALUES(`next_billing_date`),
`stripe_subscription_id` = VALUES(`stripe_subscription_id`),
`stripe_price_id` = VALUES(`stripe_price_id`),
`stripe_payment_method_id` = VALUES(`stripe_payment_method_id`),
`status` = VALUES(`status`),
`stripe_status` = VALUES(`stripe_status`),
`autopay_enabled` = VALUES(`autopay_enabled`),
`retry_count` = VALUES(`retry_count`),
`last_paid_at` = VALUES(`last_paid_at`),
`paused_at` = VALUES(`paused_at`),
`cancel_at` = VALUES(`cancel_at`),
`canceled_at` = VALUES(`canceled_at`),
`notes` = VALUES(`notes`),
`updated_at` = VALUES(`updated_at`);

-- -----------------------------------------------------------------------------
-- Link existing core rows to billing entities
-- -----------------------------------------------------------------------------
UPDATE `bookings`
SET
  `customer_id` = 9001,
  `subscription_id` = 9951,
  `billing_type` = 'subscription',
  `billing_cycle_label` = 'Weekly',
  `autopay_enabled` = 1,
  `payment_method` = 'stripe',
  `payment_status` = 'paid',
  `stripe_session_id` = 'cs_demo_booking_9101',
  `stripe_payment_id` = 'pi_demo_booking_9101'
WHERE `id` = 9101;

UPDATE `bookings`
SET
  `customer_id` = 9002,
  `billing_type` = 'one_time',
  `autopay_enabled` = 0
WHERE `id` = 9102;

UPDATE `bookings`
SET
  `customer_id` = 9003,
  `subscription_id` = 9952,
  `billing_type` = 'subscription',
  `billing_cycle_label` = 'Bi-Weekly',
  `autopay_enabled` = 1,
  `payment_method` = 'ach',
  `payment_status` = 'processing',
  `stripe_session_id` = 'cs_demo_booking_9103',
  `stripe_payment_id` = 'pi_demo_booking_9103'
WHERE `id` = 9103;

UPDATE `invoices`
SET
  `stripe_invoice_id` = 'in_demo_9201',
  `stripe_hosted_invoice_url` = 'https://invoice.stripe.example/in_demo_9201',
  `stripe_payment_intent_id` = 'pi_demo_invoice_9201',
  `subscription_id` = 9952,
  `collection_method` = 'charge_automatically',
  `billing_reason` = 'subscription_cycle',
  `failed_at` = '2026-05-12 09:35:00',
  `status` = 'open',
  `payment_method` = 'ach'
WHERE `id` = 9201;

UPDATE `invoices`
SET
  `paid_at` = '2026-05-12 15:00:00'
WHERE `id` = 9202;

-- -----------------------------------------------------------------------------
-- Payments
-- -----------------------------------------------------------------------------
INSERT INTO `payments`
(`id`,`booking_id`,`invoice_id`,`customer_id`,`subscription_id`,`amount`,`currency`,
 `payment_method`,`payment_status`,`stripe_session_id`,`stripe_payment_intent_id`,
 `stripe_charge_id`,`stripe_customer_id`,`stripe_payment_method_id`,
 `failure_code`,`failure_message`,`refunded_amount`,`created_at`,`updated_at`)
VALUES
(9961,9101,NULL,9001,9951,425.00,'usd',
 'stripe','paid','cs_demo_booking_9101','pi_demo_booking_9101',
 'ch_demo_booking_9101','cus_demo_sarah_9001','pm_demo_card_9001',
 NULL,NULL,NULL,'2026-05-10 08:16:00','2026-05-10 08:16:00'),
(9962,9103,NULL,9003,9952,375.00,'usd',
 'ach','processing','cs_demo_booking_9103','pi_demo_booking_9103',
 'ch_demo_booking_9103','cus_demo_angela_9003','pm_demo_ach_9003',
 NULL,NULL,NULL,'2026-05-12 14:12:00','2026-05-12 14:12:00'),
(9963,NULL,9201,9003,9952,486.00,'usd',
 'ach','failed',NULL,'pi_demo_invoice_9201',
 'ch_demo_invoice_9201','cus_demo_angela_9003','pm_demo_ach_9003',
 'insufficient_funds','ACH debit returned unpaid by bank.',NULL,
 '2026-05-12 09:30:00','2026-05-12 09:35:00'),
(9964,NULL,9202,9002,NULL,189.00,'usd',
 'cash','paid_cash',NULL,NULL,
 NULL,NULL,NULL,
 NULL,NULL,NULL,'2026-05-12 15:00:00','2026-05-12 15:00:00')
ON DUPLICATE KEY UPDATE
`booking_id` = VALUES(`booking_id`),
`invoice_id` = VALUES(`invoice_id`),
`customer_id` = VALUES(`customer_id`),
`subscription_id` = VALUES(`subscription_id`),
`amount` = VALUES(`amount`),
`currency` = VALUES(`currency`),
`payment_method` = VALUES(`payment_method`),
`payment_status` = VALUES(`payment_status`),
`stripe_session_id` = VALUES(`stripe_session_id`),
`stripe_payment_intent_id` = VALUES(`stripe_payment_intent_id`),
`stripe_charge_id` = VALUES(`stripe_charge_id`),
`stripe_customer_id` = VALUES(`stripe_customer_id`),
`stripe_payment_method_id` = VALUES(`stripe_payment_method_id`),
`failure_code` = VALUES(`failure_code`),
`failure_message` = VALUES(`failure_message`),
`refunded_amount` = VALUES(`refunded_amount`),
`updated_at` = VALUES(`updated_at`);

-- -----------------------------------------------------------------------------
-- Recurring invoice logs
-- -----------------------------------------------------------------------------
INSERT INTO `recurring_invoice_logs`
(`id`,`subscription_id`,`invoice_id`,`stripe_invoice_id`,`billing_date`,`amount`,`status`,`failure_message`,`created_at`)
VALUES
(9971,9951,NULL,'in_demo_sub_9001_20260514','2026-05-14 08:16:00',425.00,'paid',NULL,'2026-05-14 08:16:00'),
(9972,9952,9201,'in_demo_9201','2026-05-12 09:30:00',486.00,'failed','ACH payment failed after invoice finalization.','2026-05-12 09:35:00')
ON DUPLICATE KEY UPDATE
`subscription_id` = VALUES(`subscription_id`),
`invoice_id` = VALUES(`invoice_id`),
`stripe_invoice_id` = VALUES(`stripe_invoice_id`),
`billing_date` = VALUES(`billing_date`),
`amount` = VALUES(`amount`),
`status` = VALUES(`status`),
`failure_message` = VALUES(`failure_message`),
`created_at` = VALUES(`created_at`);

-- -----------------------------------------------------------------------------
-- Webhook logs
-- -----------------------------------------------------------------------------
INSERT INTO `webhook_logs`
(`id`,`stripe_event_id`,`event_type`,`livemode`,`api_version`,`payload_hash`,`payload_excerpt`,
 `processing_status`,`attempt_count`,`related_entity_type`,`related_entity_id`,
 `error_message`,`first_processed_at`,`last_processed_at`,`created_at`,`updated_at`)
VALUES
(9981,'evt_demo_checkout_complete_9101','checkout.session.completed',0,'2026-02-25.clover',
 'hash_demo_evt_9101','{"type":"checkout.session.completed","data":"demo"}',
 'processed',1,'booking',9101,
 NULL,'2026-05-10 08:16:01','2026-05-10 08:16:01','2026-05-10 08:16:01','2026-05-10 08:16:01'),
(9982,'evt_demo_invoice_failed_9201','invoice.payment_failed',0,'2026-02-25.clover',
 'hash_demo_evt_9201','{"type":"invoice.payment_failed","data":"demo"}',
 'failed',2,'invoice',9201,
 'ACH debit returned unpaid by bank.','2026-05-12 09:35:01','2026-05-12 09:36:00','2026-05-12 09:35:01','2026-05-12 09:36:00')
ON DUPLICATE KEY UPDATE
`event_type` = VALUES(`event_type`),
`livemode` = VALUES(`livemode`),
`api_version` = VALUES(`api_version`),
`payload_hash` = VALUES(`payload_hash`),
`payload_excerpt` = VALUES(`payload_excerpt`),
`processing_status` = VALUES(`processing_status`),
`attempt_count` = VALUES(`attempt_count`),
`related_entity_type` = VALUES(`related_entity_type`),
`related_entity_id` = VALUES(`related_entity_id`),
`error_message` = VALUES(`error_message`),
`first_processed_at` = VALUES(`first_processed_at`),
`last_processed_at` = VALUES(`last_processed_at`),
`updated_at` = VALUES(`updated_at`);

-- -----------------------------------------------------------------------------
-- Billing notifications
-- -----------------------------------------------------------------------------
INSERT INTO `notifications`
(`id`,`type`,`recipient`,`subject`,`template_key`,`body`,`status`,`related_type`,`related_id`,`context_json`,`sent_at`,`created_at`)
VALUES
(9991,'email','angela@brooksproperty.example','Subscription Payment Failed — Bi-Weekly Property Cleanup','subscription_payment_failed',
 'Mock ACH failure notice for billing portal demo.','sent','subscription',9952,'{"channel":"email","scenario":"ach_failed"}',
 '2026-05-12 09:36:00','2026-05-12 09:36:00'),
(9992,'email','sarah@mitchellroofing.example','Subscription Renewed — Weekly Roofing Dump Service','subscription_renewed',
 'Mock renewal receipt for recurring billing demo.','sent','subscription',9951,'{"channel":"email","scenario":"renewal_success"}',
 '2026-05-14 08:17:00','2026-05-14 08:17:00')
ON DUPLICATE KEY UPDATE
`recipient` = VALUES(`recipient`),
`subject` = VALUES(`subject`),
`template_key` = VALUES(`template_key`),
`body` = VALUES(`body`),
`status` = VALUES(`status`),
`related_type` = VALUES(`related_type`),
`related_id` = VALUES(`related_id`),
`context_json` = VALUES(`context_json`),
`sent_at` = VALUES(`sent_at`),
`created_at` = VALUES(`created_at`);

SET FOREIGN_KEY_CHECKS = 1;
