-- Migration 007: dumpster_categories
-- Adds a managed size catalog so admins can add, rename, and delete size
-- categories without touching individual dumpster records.

CREATE TABLE IF NOT EXISTS `dumpster_categories` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `name`        varchar(50)  NOT NULL,
  `description` text                  DEFAULT NULL,
  `sort_order`  int(11)      NOT NULL DEFAULT 0,
  `active`      tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dumpster_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default sizes (IGNORE so re-running is safe)
INSERT IGNORE INTO `dumpster_categories` (`name`, `sort_order`) VALUES
('10 Yard', 10),
('15 Yard', 15),
('20 Yard', 20),
('30 Yard', 30),
('40 Yard', 40);
