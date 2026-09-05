-- Migration 014: reset border_crossings rows that were stored as JSON false
-- ('false') – the "confirmed empty after re-parse" sentinel from the previous
-- parser version.
--
-- Rows stored as JSON false will not be re-parsed automatically because the
-- old driver_analysis code excludes them from the re-parse trigger.  Resetting
-- them to SQL NULL causes the improved parseBorderCrossings() (which now
-- accepts a single crossing record and derives the year window from the actual
-- activity dates) to re-run on the next page view of the driver analysis module.

UPDATE `ddd_activity_days`
   SET `border_crossings` = NULL
 WHERE `border_crossings` = 'false';
