-- Migration 006: Geocode address cache.
-- Stores lat/lng for previously geocoded service addresses so the
-- dispatch map loads instantly instead of re-querying Nominatim on each visit.
-- Safe to run multiple times (IF NOT EXISTS guard).

CREATE TABLE IF NOT EXISTS `geocode_cache` (
  `address_hash` CHAR(32)      NOT NULL COMMENT 'md5 of normalised full address',
  `address`      VARCHAR(500)  NOT NULL,
  `lat`          DECIMAL(10,7) NOT NULL,
  `lng`          DECIMAL(10,7) NOT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`address_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
