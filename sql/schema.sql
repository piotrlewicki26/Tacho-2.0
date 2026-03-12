-- TachoPro 2.0 - Database Schema
-- MySQL 5.7+ / MariaDB 10.3+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────
-- Companies
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `companies` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`         VARCHAR(255) NOT NULL,
  `address`      VARCHAR(500) DEFAULT NULL,
  `nip`          VARCHAR(20)  DEFAULT NULL,
  `email`        VARCHAR(255) DEFAULT NULL,
  `phone`        VARCHAR(50)  DEFAULT NULL,
  `unique_code`  CHAR(64)     NOT NULL UNIQUE COMMENT 'SHA-256 hex, generated once at creation',
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- License modules
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `licenses` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`    INT UNSIGNED NOT NULL,
  `license_key`   CHAR(64)     NOT NULL UNIQUE,
  `mod_core`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Dashboard/Drivers/Vehicles',
  `mod_delegation`TINYINT(1)   NOT NULL DEFAULT 0,
  `mod_driver_analysis` TINYINT(1) NOT NULL DEFAULT 0,
  `mod_vehicle_analysis`TINYINT(1) NOT NULL DEFAULT 0,
  `valid_from`    DATE         NOT NULL,
  `valid_until`   DATE         NOT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Users
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`    INT UNSIGNED NOT NULL,
  `username`      VARCHAR(100) NOT NULL UNIQUE,
  `email`         VARCHAR(255) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('superadmin','admin','manager','viewer') NOT NULL DEFAULT 'viewer',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login`    TIMESTAMP    NULL DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Login attempts (rate limiting)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ip_address`   VARCHAR(45)  NOT NULL,
  `username`     VARCHAR(100) DEFAULT NULL,
  `attempted_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `success`      TINYINT(1)   NOT NULL DEFAULT 0,
  INDEX idx_ip_time (`ip_address`, `attempted_at`),
  INDEX idx_user_time (`username`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Driver groups
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `driver_groups` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id` INT UNSIGNED NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Drivers
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `drivers` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`        INT UNSIGNED NOT NULL,
  `group_id`          INT UNSIGNED DEFAULT NULL,
  `first_name`        VARCHAR(100) NOT NULL,
  `last_name`         VARCHAR(100) NOT NULL,
  `birth_date`        DATE         DEFAULT NULL,
  `pesel`             VARCHAR(11)  DEFAULT NULL,
  `card_number`       VARCHAR(50)  DEFAULT NULL,
  `card_valid_until`  DATE         DEFAULT NULL,
  `license_number`    VARCHAR(50)  DEFAULT NULL,
  `license_category`  VARCHAR(20)  DEFAULT NULL,
  `employment_date`   DATE         DEFAULT NULL,
  `base_salary`       DECIMAL(10,2) DEFAULT NULL,
  `is_active`         TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`group_id`)   REFERENCES `driver_groups`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Vehicles
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `vehicles` (
  `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`            INT UNSIGNED NOT NULL,
  `registration`          VARCHAR(50)  NOT NULL,
  `make`                  VARCHAR(100) DEFAULT NULL,
  `model`                 VARCHAR(100) DEFAULT NULL,
  `vin`                   VARCHAR(50)  DEFAULT NULL,
  `year`                  YEAR         DEFAULT NULL,
  `tachograph_type`       VARCHAR(50)  DEFAULT NULL COMMENT 'analog/digital/smart',
  `last_calibration_date` DATE         DEFAULT NULL,
  `next_calibration_date` DATE         DEFAULT NULL,
  `is_active`             TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Card downloads (driver card readings)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `card_downloads` (
  `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `driver_id`          INT UNSIGNED NOT NULL,
  `download_date`      DATE         NOT NULL,
  `next_required_date` DATE         DEFAULT NULL,
  `performed_by`       INT UNSIGNED DEFAULT NULL,
  `notes`              TEXT         DEFAULT NULL,
  `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`driver_id`)    REFERENCES `drivers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Vehicle data downloads
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `vehicle_downloads` (
  `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `vehicle_id`         INT UNSIGNED NOT NULL,
  `download_date`      DATE         NOT NULL,
  `next_required_date` DATE         DEFAULT NULL,
  `performed_by`       INT UNSIGNED DEFAULT NULL,
  `notes`              TEXT         DEFAULT NULL,
  `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`vehicle_id`)   REFERENCES `vehicles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- DDD / tachograph files archive
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ddd_files` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`    INT UNSIGNED NOT NULL,
  `driver_id`     INT UNSIGNED DEFAULT NULL,
  `vehicle_id`    INT UNSIGNED DEFAULT NULL,
  `file_type`     ENUM('driver','vehicle') NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name`   VARCHAR(255) NOT NULL,
  `file_size`     INT UNSIGNED DEFAULT NULL,
  `file_hash`     CHAR(64)     DEFAULT NULL,
  `download_date` DATE         DEFAULT NULL,
  `period_start`  DATE         DEFAULT NULL,
  `period_end`    DATE         DEFAULT NULL,
  `uploaded_by`   INT UNSIGNED DEFAULT NULL,
  `is_deleted`    TINYINT(1)   NOT NULL DEFAULT 0,
  `deleted_at`    TIMESTAMP    NULL DEFAULT NULL,
  `uploaded_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`driver_id`)  REFERENCES `drivers`(`id`)  ON DELETE SET NULL,
  FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`uploaded_by`)REFERENCES `users`(`id`)    ON DELETE SET NULL,
  INDEX idx_company_type (`company_id`, `file_type`),
  INDEX idx_deleted (`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Delegations
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `delegations` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`     INT UNSIGNED NOT NULL,
  `driver_id`      INT UNSIGNED NOT NULL,
  `vehicle_id`     INT UNSIGNED DEFAULT NULL,
  `start_datetime` DATETIME     NOT NULL,
  `end_datetime`   DATETIME     DEFAULT NULL,
  `route`          TEXT         DEFAULT NULL,
  `countries`      TEXT         DEFAULT NULL COMMENT 'JSON array of country codes',
  `diet_total`     DECIMAL(10,2) DEFAULT NULL,
  `mileage_km`     INT UNSIGNED  DEFAULT NULL,
  `notes`          TEXT         DEFAULT NULL,
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`driver_id`)  REFERENCES `drivers`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- System settings (per company)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `settings` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id` INT UNSIGNED NOT NULL,
  `setting_key`VARCHAR(100) NOT NULL,
  `value`      TEXT         DEFAULT NULL,
  UNIQUE KEY uq_company_key (`company_id`, `setting_key`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
