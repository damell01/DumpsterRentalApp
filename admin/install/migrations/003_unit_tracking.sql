-- Migration 003: Individual unit tracking — maintenance dates and service log.
-- Safe to run multiple times (IF EXISTS / IF NOT EXISTS guards).

ALTER TABLE `dumpsters`
    ADD COLUMN IF NOT EXISTS `last_maintenance_date` date DEFAULT NULL
        COMMENT 'Date of last maintenance/service' AFTER `condition`,
    ADD COLUMN IF NOT EXISTS `purchase_date` date DEFAULT NULL
        COMMENT 'Date unit was acquired' AFTER `last_maintenance_date`;

CREATE TABLE IF NOT EXISTS `dumpster_maintenance_logs` (
  `id`               int(11)       NOT NULL AUTO_INCREMENT,
  `dumpster_id`      int(11)       NOT NULL,
  `maintenance_date` date          NOT NULL,
  `description`      varchar(255)  NOT NULL DEFAULT '',
  `performed_by`     varchar(100)  DEFAULT NULL,
  `cost`             decimal(10,2) DEFAULT NULL,
  `created_by`       int(11)       DEFAULT NULL,
  `created_at`       datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dml_dumpster_id` (`dumpster_id`),
  CONSTRAINT `fk_dml_dumpster_id`
      FOREIGN KEY (`dumpster_id`) REFERENCES `dumpsters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
