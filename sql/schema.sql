-- TachoPro 2.0 - Database Schema
-- MySQL 5.7+ / MariaDB 10.3+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────
-- Companies
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `companies` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`                 VARCHAR(255) NOT NULL,
  `address`              VARCHAR(500) DEFAULT NULL,
  `nip`                  VARCHAR(20)  DEFAULT NULL,
  `email`                VARCHAR(255) DEFAULT NULL,
  `phone`                VARCHAR(50)  DEFAULT NULL,
  `unique_code`          CHAR(64)     NOT NULL UNIQUE COMMENT 'SHA-256 hex, generated once at creation',
  `plan`                 ENUM('demo','pro','pro_plus') NOT NULL DEFAULT 'demo' COMMENT 'demo=14-day trial; pro=PRO package (1 company); pro_plus=PRO Module+ (multi-company)',
  `trial_ends_at`        DATE         DEFAULT NULL COMMENT 'When the 14-day demo expires',
  `stripe_customer_id`   VARCHAR(100) DEFAULT NULL COMMENT 'Stripe Customer ID',
  `created_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- License modules
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `licenses` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`           INT UNSIGNED NOT NULL,
  `license_key`          CHAR(64)          NOT NULL UNIQUE,
  `mod_core`             TINYINT(1)        NOT NULL DEFAULT 1 COMMENT 'Dashboard/Drivers/Vehicles',
  `mod_delegation`       TINYINT(1)        NOT NULL DEFAULT 0,
  `mod_driver_analysis`  TINYINT(1)        NOT NULL DEFAULT 0,
  `mod_vehicle_analysis` TINYINT(1)        NOT NULL DEFAULT 0,
  `version`              VARCHAR(20)       NOT NULL DEFAULT '1.0'  COMMENT 'TachoPro version this license covers',
  `published_at`         DATE              NOT NULL                COMMENT 'License publication / issue date',
  `max_users`            SMALLINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '0 = unlimited',
  `max_vehicles`         SMALLINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '0 = unlimited',
  `max_drivers`          SMALLINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '0 = unlimited',
  `valid_from`           DATE              NOT NULL,
  `valid_until`          DATE              NOT NULL,
  `created_at`           TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
  `stored_subdir` VARCHAR(200) DEFAULT NULL
    COMMENT 'Relative path inside uploads/ddd/ to the folder; NULL = legacy {company_id}/ root',
  `card_number`   VARCHAR(50)  DEFAULT NULL
    COMMENT 'Driver card number extracted from EF_Identification (driver card files only)',
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
  INDEX idx_deleted (`is_deleted`),
  UNIQUE KEY `uq_company_hash` (`company_id`, `file_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Pre-parsed DDD activity data (per day, per file)
-- Populated during file upload; used by driver_analysis module
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ddd_activity_days` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `file_id`    INT UNSIGNED NOT NULL,
  `date`       DATE         NOT NULL,
  `drive_min`  SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Total driving minutes (activity=3)',
  `work_min`   SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Total work/other-work minutes (activity=2)',
  `avail_min`  SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Total availability minutes (activity=1)',
  `rest_min`   SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Total rest minutes (activity=0)',
  `dist_km`    SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Distance in km from presenceCounter record header',
  `violations` JSON         DEFAULT NULL
    COMMENT 'EU regulation violation list for this day',
  `segments`   JSON         DEFAULT NULL
    COMMENT 'Array of {act,start,end,dur} slot objects for timeline rendering',
  UNIQUE KEY uq_file_date (`file_id`, `date`),
  INDEX idx_file_date (`file_id`, `date`),
  FOREIGN KEY (`file_id`) REFERENCES `ddd_files`(`id`) ON DELETE CASCADE
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
  `countries`      TEXT         DEFAULT NULL COMMENT 'Legacy: JSON array of country codes',
  `trasa`          JSON         DEFAULT NULL COMMENT 'Per-leg route: [{country,days,hours,operationType,kilometers}]',
  `diet_total`     DECIMAL(10,2) DEFAULT NULL,
  `min_wage_total` DECIMAL(10,2) DEFAULT NULL COMMENT 'Mobility Package minimum wage total (EUR)',
  `base_salary_pln` DECIMAL(10,2) DEFAULT NULL COMMENT 'Driver base salary snapshot (PLN)',
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

-- ─────────────────────────────────────────────
-- Subscriptions (monthly per-seat billing)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`            INT UNSIGNED NOT NULL,
  `stripe_subscription_id` VARCHAR(100) DEFAULT NULL,
  `status`                ENUM('active','past_due','canceled','trialing','incomplete') NOT NULL DEFAULT 'active',
  `billing_period_start`  DATE         NOT NULL,
  `billing_period_end`    DATE         NOT NULL,
  `active_drivers`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `active_vehicles`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `price_per_driver`      DECIMAL(10,2) NOT NULL DEFAULT 15.00 COMMENT 'PLN net/month',
  `price_per_vehicle`     DECIMAL(10,2) NOT NULL DEFAULT 10.00 COMMENT 'PLN net/month',
  `amount_net`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount_vat`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount_gross`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `paid_at`               TIMESTAMP    NULL DEFAULT NULL,
  `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  INDEX idx_company_period (`company_id`, `billing_period_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Invoices
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `invoices` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`        INT UNSIGNED NOT NULL,
  `subscription_id`   INT UNSIGNED DEFAULT NULL,
  `invoice_number`    VARCHAR(50)  NOT NULL UNIQUE COMMENT 'e.g. FV/2025/001',
  `issue_date`        DATE         NOT NULL,
  `due_date`          DATE         NOT NULL,
  `period_start`      DATE         NOT NULL,
  `period_end`        DATE         NOT NULL,
  `buyer_name`        VARCHAR(255) NOT NULL,
  `buyer_address`     VARCHAR(500) DEFAULT NULL,
  `buyer_nip`         VARCHAR(20)  DEFAULT NULL,
  `active_drivers`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `active_vehicles`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `amount_net`        DECIMAL(10,2) NOT NULL,
  `amount_vat`        DECIMAL(10,2) NOT NULL,
  `amount_gross`      DECIMAL(10,2) NOT NULL,
  `vat_rate`          DECIMAL(5,2)  NOT NULL DEFAULT 23.00,
  `status`            ENUM('issued','paid','overdue','canceled') NOT NULL DEFAULT 'issued',
  `stripe_invoice_id` VARCHAR(100) DEFAULT NULL,
  `paid_at`           TIMESTAMP    NULL DEFAULT NULL,
  `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`)      REFERENCES `companies`(`id`)     ON DELETE CASCADE,
  FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions`(`id`) ON DELETE SET NULL,
  INDEX idx_company_date (`company_id`, `issue_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Audit log (change history)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_id`   INT UNSIGNED DEFAULT NULL,
  `user_id`      INT UNSIGNED DEFAULT NULL,
  `username`     VARCHAR(100) DEFAULT NULL  COMMENT 'snapshot at time of action',
  `action`       VARCHAR(50)  NOT NULL      COMMENT 'create|update|delete|login|...',
  `entity_type`  VARCHAR(50)  DEFAULT NULL  COMMENT 'driver|vehicle|user|company|...',
  `entity_id`    INT UNSIGNED DEFAULT NULL,
  `description`  TEXT         DEFAULT NULL  COMMENT 'Human-readable summary',
  `old_values`   JSON         DEFAULT NULL,
  `new_values`   JSON         DEFAULT NULL,
  `ip_address`   VARCHAR(45)  DEFAULT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_company_time (`company_id`, `created_at`),
  INDEX idx_user_time    (`user_id`,    `created_at`),
  INDEX idx_entity       (`entity_type`, `entity_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Stripe payment events (idempotency log)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `stripe_events` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id`   VARCHAR(100) NOT NULL UNIQUE COMMENT 'Stripe event ID',
  `event_type` VARCHAR(100) NOT NULL,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `payload`    JSON         DEFAULT NULL,
  `processed`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
