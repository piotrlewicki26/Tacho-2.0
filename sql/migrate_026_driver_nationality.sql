-- migrate_026: Add nationality column to drivers table.
-- Populated automatically from the NationAlpha field (EU tachograph EF_Identification)
-- when a driver-card DDD file is uploaded.

ALTER TABLE `drivers`
  ADD COLUMN `nationality` VARCHAR(3) DEFAULT NULL
  COMMENT 'ISO 3166-1 alpha-2/3 country code read from the driver card (e.g. PL, DE, FR)'
  AFTER `card_valid_until`;
