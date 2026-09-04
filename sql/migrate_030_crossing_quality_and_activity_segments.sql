-- TachoPro 2.0 – Migration 030:
-- 1) Extend driver_border_crossings with quality metadata
-- 2) Add normalized driver_activity_segments table

ALTER TABLE `driver_border_crossings`
  ADD COLUMN `quality` ENUM('raw','inferred','validated') NOT NULL DEFAULT 'raw' AFTER `country_code`,
  ADD COLUMN `confidence` TINYINT UNSIGNED NOT NULL DEFAULT 70 AFTER `quality`;

ALTER TABLE `driver_border_crossings`
  ADD KEY `idx_dbc_quality_date` (`company_id`,`driver_id`,`quality`,`crossing_date`);

CREATE TABLE IF NOT EXISTS `driver_activity_segments` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`     INT UNSIGNED NOT NULL,
  `driver_id`      INT UNSIGNED NOT NULL,
  `source_file_id` INT UNSIGNED NOT NULL,
  `activity_date`  DATE NOT NULL,
  `start_min`      SMALLINT UNSIGNED NOT NULL,
  `end_min`        SMALLINT UNSIGNED NOT NULL,
  `duration_min`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `activity_type`  TINYINT UNSIGNED NOT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_driver_activity_segment`
    (`company_id`,`driver_id`,`source_file_id`,`activity_date`,`start_min`,`end_min`,`activity_type`),
  KEY `idx_das_driver_date` (`company_id`,`driver_id`,`activity_date`),
  KEY `idx_das_file` (`source_file_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`source_file_id`) REFERENCES `ddd_files`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
