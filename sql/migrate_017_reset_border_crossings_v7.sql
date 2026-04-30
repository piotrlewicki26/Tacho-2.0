-- Migration 017: reset border_crossings rows stored as JSON integer 0 sentinel ('0')
-- by the upload handler in api/files.php after migration 016 was run.
--
-- Background:
--   When parseDddFile() / parseBorderCrossings() could not find any crossing records
--   at upload time, api/files.php stored json_encode(0) = '0' as a "confirmed empty"
--   sentinel.  However, the driver_analysis re-parse trigger list did NOT include '0',
--   so these rows were never re-parsed even when a newer, improved parser could
--   successfully detect the crossings (e.g. the driver on 09.02.2026 crossed CZ→PL
--   but the upload-time result was '0' and the improved parser now finds 3 crossings).
--
--   Additionally, the update loop in driver_analysis also skipped '0' rows, so even
--   when the re-parse was triggered by other NULL rows in the same file, the '0' row
--   for the border-crossing day was never updated.
--
--   Both issues are fixed in this PR:
--     • api/files.php now stores NULL (not '0') when no crossings are found at upload
--       time, keeping the row in the "needs re-parse" state.
--     • driver_analysis/index.php now treats '0' like the other stale-empty sentinels
--       (triggers re-parse, updates the row).
--
--   This migration resets all rows with the stale '0' sentinel back to NULL so the
--   improved parser runs on the next driver-analysis page view.

UPDATE `ddd_activity_days`
   SET `border_crossings` = NULL
 WHERE `border_crossings` = '0';
