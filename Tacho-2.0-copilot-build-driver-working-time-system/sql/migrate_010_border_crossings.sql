-- Migration 010: add border_crossings column to ddd_activity_days
-- Run once; safe to re-run (IF NOT EXISTS guard via COLUMN_NAME check).

ALTER TABLE `ddd_activity_days`
  ADD COLUMN IF NOT EXISTS `border_crossings` JSON DEFAULT NULL
    COMMENT 'Array of {ts,tmin,type,country} border-crossing records for this day';
