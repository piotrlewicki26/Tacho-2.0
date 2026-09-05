-- TachoPro 2.0 – Migration 005
-- Adds stored_subdir to ddd_files to track the company-name-based folder
-- structure (uploads/ddd/{company_name}/Drivers/ or /Vehicles/).
-- Rows with stored_subdir = NULL use the legacy uploads/ddd/{company_id}/ path.

ALTER TABLE `ddd_files`
  ADD COLUMN `stored_subdir` VARCHAR(200) DEFAULT NULL
    COMMENT 'Relative path inside uploads/ddd/ to the containing folder; NULL = legacy {company_id}/ root'
  AFTER `stored_name`;
