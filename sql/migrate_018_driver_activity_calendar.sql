-- Migration 018 – Driver Activity Calendar
-- Creates the driver_activity_calendar table that stores a continuous,
-- file-independent activity record per driver per day.  Data is merged
-- (upserted) from every uploaded DDD file so that the calendar grows
-- automatically with each new card read.

CREATE TABLE IF NOT EXISTS `driver_activity_calendar` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`       INT UNSIGNED NOT NULL,
  `driver_id`        INT UNSIGNED NOT NULL,
  `date`             DATE         NOT NULL,
  `drive_min`        SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Total driving minutes (activity=3)',
  `work_min`         SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Total work/other-work minutes (activity=2)',
  `avail_min`        SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Total availability minutes (activity=1)',
  `rest_min`         SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Total rest minutes (activity=0)',
  `dist_km`          SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Distance in km',
  `violations`       JSON DEFAULT NULL
    COMMENT 'EU regulation violation list for this day',
  `segments`         JSON DEFAULT NULL
    COMMENT 'Array of {act,start,end,dur} slot objects for timeline rendering',
  `border_crossings` JSON DEFAULT NULL
    COMMENT 'Array of {ts,tmin,type,country} border-crossing records',
  `source_file_id`   INT UNSIGNED DEFAULT NULL
    COMMENT 'Most recent ddd_files.id that contributed data for this day',
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_driver_date (`driver_id`, `date`),
  INDEX idx_driver_date (`driver_id`, `date`),
  INDEX idx_company_driver (`company_id`, `driver_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`driver_id`)  REFERENCES `drivers`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Back-fill existing data from ddd_activity_days into the new table.
-- For each (driver_id, date) we take the row with the most activity data
-- (highest drive_min + work_min) from all non-deleted driver DDD files.
-- ON DUPLICATE KEY UPDATE keeps the row with more total activity minutes.
-- The SELECT is wrapped in a derived table (alias nr) so that the new-row
-- values can be referenced as nr.col without ambiguity (MySQL 8.0.20+
-- deprecated the VALUES() function in ON DUPLICATE KEY UPDATE).
INSERT INTO `driver_activity_calendar`
  (company_id, driver_id, date, drive_min, work_min, avail_min, rest_min,
   dist_km, violations, segments, border_crossings, source_file_id)
SELECT company_id, driver_id, date, drive_min, work_min, avail_min, rest_min,
       dist_km, violations, segments, border_crossings, source_file_id
FROM (
  SELECT
    f.company_id,
    f.driver_id,
    d.date,
    d.drive_min,
    d.work_min,
    d.avail_min,
    d.rest_min,
    d.dist_km,
    d.violations,
    d.segments,
    d.border_crossings,
    d.file_id AS source_file_id
  FROM `ddd_activity_days` d
  JOIN `ddd_files` f ON f.id = d.file_id
  WHERE f.file_type = 'driver'
    AND f.driver_id IS NOT NULL
    AND f.is_deleted = 0
  ORDER BY (d.drive_min + d.work_min + d.avail_min + d.rest_min) DESC
) AS nr
ON DUPLICATE KEY UPDATE
  drive_min        = IF(nr.drive_min+nr.work_min+nr.avail_min+nr.rest_min
                        > drive_min+work_min+avail_min+rest_min,
                        nr.drive_min,  drive_min),
  work_min         = IF(nr.drive_min+nr.work_min+nr.avail_min+nr.rest_min
                        > drive_min+work_min+avail_min+rest_min,
                        nr.work_min,   work_min),
  avail_min        = IF(nr.drive_min+nr.work_min+nr.avail_min+nr.rest_min
                        > drive_min+work_min+avail_min+rest_min,
                        nr.avail_min,  avail_min),
  rest_min         = IF(nr.drive_min+nr.work_min+nr.avail_min+nr.rest_min
                        > drive_min+work_min+avail_min+rest_min,
                        nr.rest_min,   rest_min),
  dist_km          = GREATEST(dist_km, nr.dist_km),
  violations       = IF(nr.violations IS NOT NULL AND nr.violations != '[]',
                        nr.violations,       violations),
  segments         = IF(nr.segments   IS NOT NULL AND nr.segments   != '[]',
                        nr.segments,          segments),
  border_crossings = IF(nr.border_crossings IS NOT NULL
                        AND nr.border_crossings NOT IN ('0','[]','null','false'),
                        nr.border_crossings,  border_crossings),
  source_file_id   = nr.source_file_id;
