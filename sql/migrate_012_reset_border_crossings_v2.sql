-- Migration 012: reset ALL stale border_crossings rows (including rows
-- previously set to JSON null by the old parser after a failed re-parse).
--
-- The improved parser in this release adds more TLV tag variants and a
-- whole-file fallback scan, so it will find crossings that older versions
-- missed.  By resetting both '[]' and the JSON null sentinel ('null') to
-- SQL NULL, the application will re-parse every file on next view.
--
-- WARNING: This will trigger re-parsing of ALL driver files on next page
-- load (one at a time, on-demand).  On large installations this may be
-- slow for the first few requests after the migration.
--
-- If you only want to reset '[]' rows (faster, safer), use migration 011
-- instead.

UPDATE `ddd_activity_days`
   SET `border_crossings` = NULL
 WHERE `border_crossings` IN ('[]', 'null');
