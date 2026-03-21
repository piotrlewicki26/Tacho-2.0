-- TachoPro 2.0 – Migration 007: unique index on (company_id, file_hash) in ddd_files
-- Prevents duplicate file uploads at the database level.
-- Run this against any existing installation after schema.sql.

ALTER TABLE `ddd_files`
  ADD UNIQUE KEY `uq_company_hash` (`company_id`, `file_hash`);
