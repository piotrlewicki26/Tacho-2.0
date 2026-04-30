-- Migration 022: fix missing activity data for driver cards with no garbage
--                (new / recently-issued cards whose circular buffer has not
--                yet wrapped around).
--
-- Background:
--   parseDddFile() and parseVehicleDdd() both filtered out any candidate record
--   header whose presenceCounter was below 500.  This guard was originally
--   inherited from JSX bounds designed to eliminate coincidental binary
--   false-positives.
--
--   However, the presenceCounter on a tachograph card increments by roughly
--   2–3 per working day.  A freshly-issued card that has been in use for less
--   than ~8 months will have a presenceCounter below 500 for all its records.
--   Such a card has no garbage – its circular buffer has never wrapped, every
--   stored record is genuine and appears only once – but the old lower-bound
--   guard caused EVERY record to be rejected as a false positive, resulting in
--   an empty analysis (zero activity days shown) even though the DDD file
--   contained valid driving data.
--
-- Fix applied to includes/functions.php:
--   The presenceCounter lower bound was lowered from 500 to 1 in both
--   parseDddFile() and parseVehicleDdd().  The IQR filter in Step 4 of
--   parseDddFile() already handles the removal of stale records from
--   previous card-use periods, so the lower bound is no longer needed as
--   a false-positive guard.
--
-- Re-parse strategy:
--   Delete ALL ddd_activity_days rows for driver-card files and reset
--   period_start / period_end so the fixed parser rebuilds the activity
--   calendar on the next driver_analysis or driver_calendar page visit.
--   Border-crossings are also reset because they depend on the activity
--   day list.

-- Step 1: Delete all activity day rows for driver-card files so the fixed
-- parser can rebuild them correctly on the next analysis pass.
DELETE dad
FROM   ddd_activity_days dad
JOIN   ddd_files f ON f.id = dad.file_id
WHERE  f.file_type  = 'driver'
  AND  f.is_deleted = 0;

-- Step 2: Reset period_start / period_end so they are recalculated from
-- scratch on the next analysis pass.
UPDATE ddd_files
   SET period_start = NULL,
       period_end   = NULL
WHERE  file_type  = 'driver'
  AND  is_deleted = 0;

-- Step 3: Reset border_crossings so the improved parser re-evaluates them
-- on the next driver_analysis visit.
UPDATE `ddd_activity_days`
   SET `border_crossings` = NULL;

UPDATE `driver_activity_calendar`
   SET `border_crossings` = NULL;
