-- Migration 021: fix missing activity days caused by false-positive deduplication.
--
-- Background:
--   parseDddFile() deduplicates binary candidates by date, keeping only the
--   candidate with the median presenceCounter.  When a coincidental binary
--   pattern (false positive) happened to have the median presenceCounter for
--   a given date, it was chosen as the "representative" candidate.  If that
--   false-positive candidate failed the activity-total validation (sum of slot
--   durations outside 1350–1460 minutes), the entire day was silently dropped
--   from the result – even though another candidate at a nearby presenceCounter
--   would have parsed correctly.
--
--   Example: February 17th had a false-positive candidate at presCounter=1234
--   (median for that date) producing a slot total of 980 minutes, while the
--   genuine record at presCounter=1231 would produce total=1440.  The genuine
--   record was never tried, so the day never appeared in the analysis.
--
-- Fix applied to includes/functions.php:
--   Step 2 now keeps ALL candidates per date, ordered median-first then
--   alternating neighbours.  Step 6 iterates over them and uses the first
--   candidate that produces a valid 1350–1460 minute total.  The primary
--   (median) candidate is still tried first, so there is zero regression risk
--   for files that already parse correctly.
--
-- Re-parse strategy:
--   Delete all ddd_activity_days and reset period_start/period_end for all
--   driver-card files so the fixed parser re-builds the activity calendar on
--   the next driver_analysis or driver_calendar page visit.
--   Border-crossings are also reset (they depend on the activity day list).

-- Step 1: Delete all activity day rows for driver-card files so the fixed
-- parser can rebuild them on the next analysis pass.
DELETE dad
FROM   ddd_activity_days dad
JOIN   ddd_files f ON f.id = dad.file_id
WHERE  f.file_type  = 'driver'
  AND  f.is_deleted = 0;

-- Step 2: Reset period_start / period_end so they are recalculated from scratch.
UPDATE ddd_files
   SET period_start = NULL,
       period_end   = NULL
WHERE  file_type  = 'driver'
  AND  is_deleted = 0;

-- Step 3: Reset border_crossings in both tables so the improved parser
-- re-evaluates them on the next driver_analysis visit.
UPDATE `ddd_activity_days`
   SET `border_crossings` = NULL;

UPDATE `driver_activity_calendar`
   SET `border_crossings` = NULL;
