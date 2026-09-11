-- TachoPro 2.0 – Migration 006
-- Adds card_number to ddd_files so the card identifier parsed from the DDD
-- binary is stored per-file (e.g. "1940102113500000").

ALTER TABLE `ddd_files`
  ADD COLUMN `card_number` VARCHAR(50) DEFAULT NULL
    COMMENT 'Driver card number extracted from EF_Identification (driver card files only)'
  AFTER `stored_subdir`;
