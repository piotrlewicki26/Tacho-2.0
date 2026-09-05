-- Migration 029: Create dedicated driver border crossings events table
-- Purpose:
--   Store border crossings as event-level records (1 row = 1 crossing)
--   independent from daily activity JSON aggregates.

CREATE TABLE IF NOT EXISTS `driver_border_crossings` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`     INT UNSIGNED NOT NULL,
  `driver_id`      INT UNSIGNED NOT NULL,
  `source_file_id` INT UNSIGNED NOT NULL,
  `crossing_date`  DATE NOT NULL,
  `crossing_tmin`  SMALLINT UNSIGNED NOT NULL
    COMMENT 'Minute of day (0..1439)',
  `crossing_ts`    INT UNSIGNED DEFAULT NULL
    COMMENT 'Optional absolute timestamp',
  `crossing_type`  TINYINT UNSIGNED NOT NULL DEFAULT 2
    COMMENT '0=entry, 1=exit, 2=passage/other',
  `country_code`   VARCHAR(8) NOT NULL
    COMMENT 'Country code displayed in timeline marker',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_driver_crossing`
    (`company_id`,`driver_id`,`crossing_date`,`crossing_tmin`,`crossing_type`,`country_code`),
  KEY `idx_dbc_driver_date` (`driver_id`,`crossing_date`),
  KEY `idx_dbc_file` (`source_file_id`),
  FOREIGN KEY (`company_id`)     REFERENCES `companies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`driver_id`)      REFERENCES `drivers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`source_file_id`) REFERENCES `ddd_files`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
