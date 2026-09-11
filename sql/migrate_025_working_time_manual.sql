-- TachoPro 2.0 – Migracja 025
-- Tabela do ręcznego wprowadzania ewidencji czasu pracy kierowców
-- pojazdów 2,8-3,5t (bez tachografu cyfrowego / bez plików DDD).
--
-- Każdy wiersz = jeden dzień jednego kierowcy w danej firmie.

CREATE TABLE IF NOT EXISTS `working_time_manual_entries` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`    INT UNSIGNED NOT NULL,
  `driver_id`     INT UNSIGNED DEFAULT NULL COMMENT 'NULL = kierowca wpisany ręcznie (nie z tabeli drivers)',
  `driver_name`   VARCHAR(200) DEFAULT NULL COMMENT 'Imię i nazwisko – wypełniane gdy driver_id IS NULL',
  `entry_date`    DATE         NOT NULL,
  `start_time`    VARCHAR(5)   DEFAULT NULL COMMENT 'HH:MM godzina rozpoczęcia',
  `end_time`      VARCHAR(5)   DEFAULT NULL COMMENT 'HH:MM godzina zakończenia',
  `drive_min`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Jazda (minuty)',
  `other_work_min` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Inna praca (minuty)',
  `avail_min`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dyspozycje (minuty)',
  `break_min`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Przerwy (minuty)',
  `rest_min`      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Odpoczynek dobowy (minuty)',
  `night_min`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Praca w porze nocnej (minuty)',
  `duty_min`      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dyżury 50% (minuty)',
  `idle_min`      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Przestoje (minuty)',
  `dist_km`       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dystans km',
  `route`         VARCHAR(500) DEFAULT NULL COMMENT 'Trasa / relacja',
  `notes`         VARCHAR(500) DEFAULT NULL COMMENT 'Uwagi',
  `created_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_company_driver_date` (`company_id`, `driver_id`, `driver_name`(100), `entry_date`),
  INDEX `idx_company_date` (`company_id`, `entry_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`driver_id`)  REFERENCES `drivers`(`id`)   ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
