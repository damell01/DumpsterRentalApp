-- Migration 004: Unified API rate limiting table.
-- Safe to run multiple times (IF NOT EXISTS guard).

CREATE TABLE IF NOT EXISTS `api_rate_limits` (
  `bucket`       varchar(120) NOT NULL COMMENT 'action:ip composite key',
  `attempts`     int(11)      NOT NULL DEFAULT 1,
  `window_start` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_until` datetime              DEFAULT NULL,
  `updated_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bucket`),
  KEY `idx_arl_window_start` (`window_start`),
  KEY `idx_arl_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
