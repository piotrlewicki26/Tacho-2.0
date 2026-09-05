-- TachoPro 2.0 – Migration 004: Global system settings
--
-- Stores superadmin-configurable settings (Stripe keys, SMTP/email) that
-- are not tied to any specific company.  Using a dedicated table avoids the
-- foreign-key constraint on the existing `settings` table.

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT         DEFAULT NULL,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
