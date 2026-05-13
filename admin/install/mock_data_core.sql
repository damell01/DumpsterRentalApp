-- =============================================================================
-- Trash Panda Roll-Offs - Core Mock Data
-- Import this AFTER the installer has completed.
--
-- Safe-ish for repeat imports:
-- - Uses high numeric IDs to avoid normal production rows
-- - Uses INSERT ... ON DUPLICATE KEY UPDATE for unique number fields
--
-- Import example:
--   mysql -u USER -p DB_NAME < admin/install/mock_data_core.sql
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- Customers
-- -----------------------------------------------------------------------------
INSERT INTO `customers`
(`id`,`name`,`company`,`email`,`phone`,`address`,`city`,`state`,`zip`,
 `billing_address`,`billing_city`,`billing_state`,`billing_zip`,`type`,`notes`,
 `created_at`,`updated_at`)
VALUES
(9001,'Sarah Mitchell','Mitchell Roofing LLC','sarah@mitchellroofing.example','2515551101',
 '104 Oak Ridge Dr','Foley','AL','36535',
 '104 Oak Ridge Dr','Foley','AL','36535','contractor',
 'Weekly contractor account. Prefers early morning deliveries.',
 '2026-05-01 08:15:00','2026-05-12 09:00:00'),
(9002,'James Carter',NULL,'james.carter@example.com','2515551102',
 '228 Magnolia Ave','Gulf Shores','AL','36542',
 '228 Magnolia Ave','Gulf Shores','AL','36542','residential',
 'Estate cleanout customer. Call before pickup.',
 '2026-05-02 10:30:00','2026-05-12 10:45:00'),
(9003,'Angela Brooks','Brooks Property Services','angela@brooksproperty.example','2515551103',
 '17 Harbor Point Ln','Orange Beach','AL','36561',
 'PO Box 174','Orange Beach','AL','36561','commercial',
 'Uses invoices for multiple property jobs.',
 '2026-05-03 11:10:00','2026-05-12 14:20:00')
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`company` = VALUES(`company`),
`email` = VALUES(`email`),
`phone` = VALUES(`phone`),
`address` = VALUES(`address`),
`city` = VALUES(`city`),
`state` = VALUES(`state`),
`zip` = VALUES(`zip`),
`billing_address` = VALUES(`billing_address`),
`billing_city` = VALUES(`billing_city`),
`billing_state` = VALUES(`billing_state`),
`billing_zip` = VALUES(`billing_zip`),
`type` = VALUES(`type`),
`notes` = VALUES(`notes`),
`updated_at` = VALUES(`updated_at`);

-- -----------------------------------------------------------------------------
-- Dumpsters (mock units dedicated to demo data)
-- -----------------------------------------------------------------------------
INSERT INTO `dumpsters`
(`id`,`unit_code`,`product_name`,`type`,`size`,`description`,
 `daily_rate`,`weekly_rate`,`monthly_rate`,`base_price`,`rental_days`,`extra_day_price`,
 `delivery_fee`,`pickup_fee`,`mileage_fee`,`tax_rate`,
 `active`,`image`,`status`,`condition`,`notes`,`created_at`,`updated_at`)
VALUES
(9901,'DEMO-20A','20 Yard Demo Dumpster','dumpster','20 Yard','Demo unit for mock bookings and invoices.',
 85.00,450.00,1500.00,425.00,7,40.00,35.00,25.00,NULL,8.00,
 1,NULL,'available','good','Mock data seed unit A.',
 '2026-05-01 07:00:00','2026-05-12 07:00:00'),
(9902,'DEMO-30B','30 Yard Demo Dumpster','dumpster','30 Yard','Larger demo unit used for active jobs.',
 105.00,575.00,1850.00,550.00,7,50.00,35.00,25.00,NULL,8.00,
 1,NULL,'in_use','excellent','Mock data seed unit B.',
 '2026-05-01 07:10:00','2026-05-12 07:10:00'),
(9903,'DEMO-15C','15 Yard Demo Dumpster','dumpster','15 Yard','Smaller demo unit for residential jobs.',
 70.00,390.00,1325.00,375.00,7,30.00,35.00,25.00,NULL,8.00,
 1,NULL,'reserved','good','Mock data seed unit C.',
 '2026-05-01 07:20:00','2026-05-12 07:20:00')
ON DUPLICATE KEY UPDATE
`product_name` = VALUES(`product_name`),
`type` = VALUES(`type`),
`size` = VALUES(`size`),
`description` = VALUES(`description`),
`daily_rate` = VALUES(`daily_rate`),
`weekly_rate` = VALUES(`weekly_rate`),
`monthly_rate` = VALUES(`monthly_rate`),
`base_price` = VALUES(`base_price`),
`rental_days` = VALUES(`rental_days`),
`extra_day_price` = VALUES(`extra_day_price`),
`delivery_fee` = VALUES(`delivery_fee`),
`pickup_fee` = VALUES(`pickup_fee`),
`mileage_fee` = VALUES(`mileage_fee`),
`tax_rate` = VALUES(`tax_rate`),
`active` = VALUES(`active`),
`status` = VALUES(`status`),
`condition` = VALUES(`condition`),
`notes` = VALUES(`notes`),
`updated_at` = VALUES(`updated_at`);

-- -----------------------------------------------------------------------------
-- Bookings
-- -----------------------------------------------------------------------------
INSERT INTO `bookings`
(`id`,`booking_number`,`customer_name`,`customer_phone`,`customer_email`,
 `customer_address`,`customer_city`,`dumpster_id`,`unit_code`,`unit_type`,`unit_size`,
 `rental_start`,`rental_end`,`rental_days`,`daily_rate`,`total_amount`,
 `payment_method`,`payment_status`,`booking_status`,`booking_group_id`,`notes`,
 `created_at`,`updated_at`)
VALUES
(9101,'BK-9001','Sarah Mitchell','2515551101','sarah@mitchellroofing.example',
 '104 Oak Ridge Dr','Foley',9901,'DEMO-20A','dumpster','20 Yard',
 '2026-05-14','2026-05-21',7,85.00,425.00,
 'stripe','paid','confirmed',NULL,'Roof tear-off debris for residential reroof.',
 '2026-05-10 08:00:00','2026-05-10 08:15:00'),
(9102,'BK-9002','James Carter','2515551102','james.carter@example.com',
 '228 Magnolia Ave','Gulf Shores',9902,'DEMO-30B','dumpster','30 Yard',
 '2026-05-11','2026-05-18',7,105.00,550.00,
 'cash','pending_cash','confirmed',NULL,'Estate cleanup with heavy furniture disposal.',
 '2026-05-09 09:20:00','2026-05-11 07:45:00'),
(9103,'BK-9003','Angela Brooks','2515551103','angela@brooksproperty.example',
 '17 Harbor Point Ln','Orange Beach',9903,'DEMO-15C','dumpster','15 Yard',
 '2026-05-13','2026-05-20',7,70.00,375.00,
 'check','pending_check','pending','GRP-DEMO-01','Property turnover cleanup, back gate access.',
 '2026-05-12 14:00:00','2026-05-12 14:10:00')
ON DUPLICATE KEY UPDATE
`customer_name` = VALUES(`customer_name`),
`customer_phone` = VALUES(`customer_phone`),
`customer_email` = VALUES(`customer_email`),
`customer_address` = VALUES(`customer_address`),
`customer_city` = VALUES(`customer_city`),
`dumpster_id` = VALUES(`dumpster_id`),
`unit_code` = VALUES(`unit_code`),
`unit_type` = VALUES(`unit_type`),
`unit_size` = VALUES(`unit_size`),
`rental_start` = VALUES(`rental_start`),
`rental_end` = VALUES(`rental_end`),
`rental_days` = VALUES(`rental_days`),
`daily_rate` = VALUES(`daily_rate`),
`total_amount` = VALUES(`total_amount`),
`payment_method` = VALUES(`payment_method`),
`payment_status` = VALUES(`payment_status`),
`booking_status` = VALUES(`booking_status`),
`booking_group_id` = VALUES(`booking_group_id`),
`notes` = VALUES(`notes`),
`updated_at` = VALUES(`updated_at`);

-- -----------------------------------------------------------------------------
-- Invoices
-- -----------------------------------------------------------------------------
INSERT INTO `invoices`
(`id`,`invoice_number`,`customer_id`,`cust_name`,`cust_email`,`cust_phone`,`cust_address`,
 `subtotal`,`tax_rate`,`tax_amount`,`total`,`notes`,`terms`,`status`,`due_date`,
 `payment_method`,`payment_notes`,`created_at`,`updated_at`)
VALUES
(9201,'INV-9001',9003,'Angela Brooks','angela@brooksproperty.example','2515551103','PO Box 174, Orange Beach, AL 36561',
 450.00,8.00,36.00,486.00,
 'Monthly property cleanup invoice.',
 'Payment due within 15 days.',
 'sent','2026-05-20','stripe',NULL,
 '2026-05-05 13:00:00','2026-05-12 10:00:00'),
(9202,'INV-9002',9002,'James Carter','james.carter@example.com','2515551102','228 Magnolia Ave, Gulf Shores, AL 36542',
 175.00,8.00,14.00,189.00,
 'Extra haul and mattress disposal.',
 'Payment due on receipt.',
 'paid','2026-05-12','cash','Paid at office counter.',
 '2026-05-06 09:15:00','2026-05-12 15:00:00')
ON DUPLICATE KEY UPDATE
`customer_id` = VALUES(`customer_id`),
`cust_name` = VALUES(`cust_name`),
`cust_email` = VALUES(`cust_email`),
`cust_phone` = VALUES(`cust_phone`),
`cust_address` = VALUES(`cust_address`),
`subtotal` = VALUES(`subtotal`),
`tax_rate` = VALUES(`tax_rate`),
`tax_amount` = VALUES(`tax_amount`),
`total` = VALUES(`total`),
`notes` = VALUES(`notes`),
`terms` = VALUES(`terms`),
`status` = VALUES(`status`),
`due_date` = VALUES(`due_date`),
`payment_method` = VALUES(`payment_method`),
`payment_notes` = VALUES(`payment_notes`),
`updated_at` = VALUES(`updated_at`);

INSERT INTO `invoice_items`
(`id`,`invoice_id`,`description`,`quantity`,`unit_price`,`amount`,`rate_type`)
VALUES
(9301,9201,'Recurring cleanup haul service',1.00,350.00,350.00,'fixed'),
(9302,9201,'Driveway-safe board placement',1.00,100.00,100.00,'fixed'),
(9303,9202,'Additional dump run',1.00,125.00,125.00,'fixed'),
(9304,9202,'Mattress disposal surcharge',1.00,50.00,50.00,'fixed')
ON DUPLICATE KEY UPDATE
`invoice_id` = VALUES(`invoice_id`),
`description` = VALUES(`description`),
`quantity` = VALUES(`quantity`),
`unit_price` = VALUES(`unit_price`),
`amount` = VALUES(`amount`),
`rate_type` = VALUES(`rate_type`);

-- -----------------------------------------------------------------------------
-- Work Orders
-- -----------------------------------------------------------------------------
INSERT INTO `work_orders`
(`id`,`wo_number`,`customer_id`,`cust_name`,`cust_email`,`cust_phone`,
 `service_address`,`service_city`,`service_state`,`service_zip`,
 `size`,`dumpster_id`,`project_type`,`delivery_date`,`pickup_date`,`actual_pickup`,
 `assigned_driver`,`amount`,`status`,`priority`,`internal_notes`,`footer_notes`,
 `created_by`,`created_at`,`updated_at`)
VALUES
(9401,'WO-9001',9001,'Sarah Mitchell','sarah@mitchellroofing.example','2515551101',
 '104 Oak Ridge Dr','Foley','AL','36535',
 '20 Yard',9901,'Roofing','2026-05-14','2026-05-21',NULL,
 NULL,425.00,'scheduled','high',
 'Customer needs delivery before 8:30 AM.',
 'Thank you for choosing Trash Panda Roll-Offs.',
 NULL,'2026-05-10 08:30:00','2026-05-10 08:30:00'),
(9402,'WO-9002',9002,'James Carter','james.carter@example.com','2515551102',
 '228 Magnolia Ave','Gulf Shores','AL','36542',
 '30 Yard',9902,'Estate Cleanout','2026-05-11','2026-05-18',NULL,
 NULL,550.00,'active','normal',
 'Customer may request early pickup once garage is clear.',
 'Call office for schedule changes.',
 NULL,'2026-05-09 10:00:00','2026-05-12 09:30:00')
ON DUPLICATE KEY UPDATE
`customer_id` = VALUES(`customer_id`),
`cust_name` = VALUES(`cust_name`),
`cust_email` = VALUES(`cust_email`),
`cust_phone` = VALUES(`cust_phone`),
`service_address` = VALUES(`service_address`),
`service_city` = VALUES(`service_city`),
`service_state` = VALUES(`service_state`),
`service_zip` = VALUES(`service_zip`),
`size` = VALUES(`size`),
`dumpster_id` = VALUES(`dumpster_id`),
`project_type` = VALUES(`project_type`),
`delivery_date` = VALUES(`delivery_date`),
`pickup_date` = VALUES(`pickup_date`),
`actual_pickup` = VALUES(`actual_pickup`),
`assigned_driver` = VALUES(`assigned_driver`),
`amount` = VALUES(`amount`),
`status` = VALUES(`status`),
`priority` = VALUES(`priority`),
`internal_notes` = VALUES(`internal_notes`),
`footer_notes` = VALUES(`footer_notes`),
`created_by` = VALUES(`created_by`),
`updated_at` = VALUES(`updated_at`);

INSERT INTO `work_order_notes`
(`id`,`wo_id`,`user_id`,`note`,`note_type`,`created_at`)
VALUES
(9501,9401,NULL,'Delivery confirmed for tomorrow morning.','note','2026-05-13 16:00:00'),
(9502,9402,NULL,'Customer called and confirmed dumpster placement on left side of driveway.','note','2026-05-12 11:15:00')
ON DUPLICATE KEY UPDATE
`wo_id` = VALUES(`wo_id`),
`user_id` = VALUES(`user_id`),
`note` = VALUES(`note`),
`note_type` = VALUES(`note_type`),
`created_at` = VALUES(`created_at`);

-- -----------------------------------------------------------------------------
-- Leads
-- -----------------------------------------------------------------------------
INSERT INTO `leads`
(`id`,`name`,`email`,`phone`,`address`,`city`,`state`,`zip`,`size_needed`,
 `project_type`,`source`,`message`,`status`,`assigned_to`,`converted_to`,`archived`,
 `created_at`,`updated_at`)
VALUES
(9601,'Evan Turner','evan.turner@example.com','2515551199','91 Cypress Lake Dr','Foley','AL','36535',
 '20 Yard','Renovation','Website','Needs a dumpster next week for a kitchen remodel.','new',NULL,NULL,0,
 '2026-05-12 08:10:00','2026-05-12 08:10:00'),
(9602,'Lindsey Moore','lindsey.moore@example.com','2515551188','11 Beach Bend Rd','Gulf Shores','AL','36542',
 '15 Yard','Landscaping','Google','Looking for weekend delivery and pickup.','quoted',NULL,NULL,0,
 '2026-05-11 12:20:00','2026-05-12 09:25:00')
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`email` = VALUES(`email`),
`phone` = VALUES(`phone`),
`address` = VALUES(`address`),
`city` = VALUES(`city`),
`state` = VALUES(`state`),
`zip` = VALUES(`zip`),
`size_needed` = VALUES(`size_needed`),
`project_type` = VALUES(`project_type`),
`source` = VALUES(`source`),
`message` = VALUES(`message`),
`status` = VALUES(`status`),
`updated_at` = VALUES(`updated_at`);

-- -----------------------------------------------------------------------------
-- Notifications / Activity log
-- -----------------------------------------------------------------------------
INSERT INTO `notifications`
(`id`,`type`,`recipient`,`subject`,`body`,`status`,`related_type`,`related_id`,`sent_at`,`created_at`)
VALUES
(9701,'email','sarah@mitchellroofing.example','Booking Confirmed — BK-9001','Mock booking confirmation email body.','sent','booking',9101,'2026-05-10 08:20:00','2026-05-10 08:20:00'),
(9702,'email','info@trashpandarolloffs.example','New Invoice — INV-9001','Mock invoice notification body.','queued','invoice',9201,NULL,'2026-05-12 10:05:00')
ON DUPLICATE KEY UPDATE
`recipient` = VALUES(`recipient`),
`subject` = VALUES(`subject`),
`body` = VALUES(`body`),
`status` = VALUES(`status`),
`related_type` = VALUES(`related_type`),
`related_id` = VALUES(`related_id`),
`sent_at` = VALUES(`sent_at`),
`created_at` = VALUES(`created_at`);

INSERT INTO `activity_log`
(`id`,`user_id`,`action`,`description`,`entity_type`,`entity_id`,`ip_address`,`created_at`)
VALUES
(9801,NULL,'mock_seed','Inserted core mock data set','system',0,'127.0.0.1','2026-05-13 09:00:00'),
(9802,NULL,'booking_lookup','Mock customer booking lookup','booking',9101,'127.0.0.1','2026-05-13 09:05:00')
ON DUPLICATE KEY UPDATE
`action` = VALUES(`action`),
`description` = VALUES(`description`),
`entity_type` = VALUES(`entity_type`),
`entity_id` = VALUES(`entity_id`),
`ip_address` = VALUES(`ip_address`),
`created_at` = VALUES(`created_at`);

SET FOREIGN_KEY_CHECKS = 1;
