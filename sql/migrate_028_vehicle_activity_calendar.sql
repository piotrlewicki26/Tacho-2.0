-- Migration 028: Create vehicle_activity_calendar table
-- Stores per-vehicle, per-day distance aggregated from vehicle DDD files.
-- Populated on every vehicle file upload via api/files.php.

CREATE TABLE IF NOT EXISTS `vehicle_activity_calendar` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`     INT UNSIGNED NOT NULL,
  `vehicle_id`     INT UNSIGNED NOT NULL,
  `date`           DATE NOT NULL,
  `dist_km`        INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Distance driven in km on this day',
  `source_file_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'ID of the ddd_files row that provided this data',
  UNIQUE KEY `uq_vac` (`company_id`, `vehicle_id`, `date`),
  KEY `idx_vac_vid_date` (`vehicle_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Aggregated per-vehicle activity calendar built from vehicle DDD files';
