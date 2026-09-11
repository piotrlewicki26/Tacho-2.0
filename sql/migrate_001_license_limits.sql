-- TachoPro 2.0 – Migration: license limits & versioning
-- Run this against an existing installation that was set up with schema.sql v1.

ALTER TABLE `licenses`
  ADD COLUMN `version`       VARCHAR(20)   NOT NULL DEFAULT '1.0'  COMMENT 'TachoPro version this license was issued for' AFTER `mod_vehicle_analysis`,
  ADD COLUMN `published_at`  DATE          NOT NULL DEFAULT (CURDATE()) COMMENT 'License publication / issue date' AFTER `version`,
  ADD COLUMN `max_users`     SMALLINT UNSIGNED NOT NULL DEFAULT 0   COMMENT '0 = unlimited' AFTER `published_at`,
  ADD COLUMN `max_vehicles`  SMALLINT UNSIGNED NOT NULL DEFAULT 0   COMMENT '0 = unlimited' AFTER `max_users`,
  ADD COLUMN `max_drivers`   SMALLINT UNSIGNED NOT NULL DEFAULT 0   COMMENT '0 = unlimited' AFTER `max_vehicles`;
