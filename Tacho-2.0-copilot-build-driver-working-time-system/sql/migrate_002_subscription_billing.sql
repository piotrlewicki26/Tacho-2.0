-- TachoPro 2.0 – Migration 002: Subscription billing, demo mode, audit log
-- Run after migrate_001_license_limits.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Extend companies with billing info ───────────────────────
ALTER TABLE `companies`
  ADD COLUMN `plan`              ENUM('demo','pro') NOT NULL DEFAULT 'demo'
      COMMENT 'demo = 14-day trial; pro = active subscription'
      AFTER `unique_code`,
  ADD COLUMN `trial_ends_at`     DATE DEFAULT NULL
      COMMENT 'When the 14-day demo expires'
      AFTER `plan`,
  ADD COLUMN `stripe_customer_id` VARCHAR(100) DEFAULT NULL
      COMMENT 'Stripe Customer ID for recurring billing'
      AFTER `trial_ends_at`;

-- ── Subscriptions (monthly per-seat billing) ─────────────────
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

-- ── Invoices ─────────────────────────────────────────────────
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

-- ── Audit log (change history) ───────────────────────────────
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
  INDEX idx_company_time  (`company_id`, `created_at`),
  INDEX idx_user_time     (`user_id`,    `created_at`),
  INDEX idx_entity        (`entity_type`, `entity_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Stripe payment events log ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `stripe_events` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id`   VARCHAR(100) NOT NULL UNIQUE COMMENT 'Stripe event ID for idempotency',
  `event_type` VARCHAR(100) NOT NULL,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `payload`    JSON         DEFAULT NULL,
  `processed`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
