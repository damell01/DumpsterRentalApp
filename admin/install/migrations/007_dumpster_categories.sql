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

-- Seed default sizes with descriptions (IGNORE so re-running is safe)
INSERT IGNORE INTO `dumpster_categories` (`name`, `description`, `sort_order`) VALUES
('10 Yard', 'Perfect for small cleanouts, single-room remodels, and garage or attic purges.', 10),
('15 Yard', 'Great for medium home cleanouts, flooring removal, and compact renovation projects.', 15),
('20 Yard', 'Ideal for whole-home remodels, roofing tear-offs, and mid-size construction debris.', 20),
('30 Yard', 'Best for large renovations, new construction waste, and major property cleanouts.', 30),
('40 Yard', 'Suited for commercial jobs, large-scale demolition, and heavy debris removal.', 40);
