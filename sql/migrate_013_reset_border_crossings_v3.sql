-- Migration 013: reset border_crossings rows that may have been partially parsed
-- by the previous parser (which skipped pointer places with noRec > 10).
--
-- The improved parser now caps noRec at 10 instead of skipping such pointer
-- places, so it will find crossings that older versions missed.  By resetting
-- rows that have non-null crossing data back to SQL NULL, the application will
-- re-parse every file on next view and store the complete set of crossings.
--
-- Rows already set to SQL NULL (e.g. from migration 012) are not affected.

UPDATE `ddd_activity_days`
   SET `border_crossings` = NULL
 WHERE `border_crossings` IS NOT NULL;
