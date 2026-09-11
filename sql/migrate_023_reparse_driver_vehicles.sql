-- Migration 023: Re-analyze driver DDD files for correct vehicle usage data
--
-- Background:
--   parseDriverCardVehicles() previously accepted binary-parsing artefacts as
--   valid vehicle registration numbers and allowed implausible timestamps and
--   odometer readings.  Three validation rules were added/tightened:
--
--   1. Registration length guard: the registration string must contain at least
--      4 non-space characters.  This rejects single- and double-char artefacts
--      such as "0 D" that arise from misaligned reads of EF_CardVehiclesUsed.
--
--   2. Letter-first guard: the registration must begin with a letter [A-Z].
--      EU vehicle plates always start with a country/area letter prefix.
--      Strings such as "7KNO" that start with a digit are false positives.
--
--   3. Must-contain-digit guard: the registration must include at least one
--      digit [0-9].  Purely alphabetic strings are binary noise.
--
--   Additionally, the existing timestamp window guard (tsMin = -20 years) now
--   correctly filters out records with timestamps like 2007/2008 that fall
--   outside the plausible activity window for a modern tachograph card, and
--   the odometer upper-bound check (> 9,999,999 km) catches astronomically
--   large odo values such as 6,357,095 km and 1,076,504 km that cannot
--   represent real vehicle readings and indicate a misaligned binary read.
--
--   Because vehicle usage records (parseDriverCardVehicles) are re-parsed live
--   from the binary DDD files on every "Pojazdy" tab request, the UI now
--   immediately shows only genuine vehicle records without requiring any DB
--   change.  This migration resets the associated per-day activity cache
--   (ddd_activity_days) and the continuous driver_activity_calendar so that
--   the next analysis page visit triggers a full re-parse of every driver card
--   with the improved parser, ensuring all cached data (activity minutes,
--   segments, violations, border crossings) is consistent with the corrected
--   vehicle list.
--
-- Re-parse strategy:
--   1. Delete all ddd_activity_days rows for driver-card files so the fixed
--      parser rebuilds them on the next driver_analysis or driver_calendar visit.
--   2. Reset period_start / period_end in ddd_files so the next upload or
--      analysis pass recalculates the period from scratch.
--   3. Reset border_crossings to NULL in driver_activity_calendar so the lazy
--      re-parse logic fires and refreshes crossing data alongside vehicle data.

-- Step 1: Delete all per-day activity cache rows for driver-card files so the
-- improved parser can rebuild them from the binary DDD files on next access.
DELETE dad
FROM   ddd_activity_days  dad
JOIN   ddd_files          f ON f.id = dad.file_id
WHERE  f.file_type  = 'driver'
  AND  f.is_deleted = 0;

-- Step 2: Clear the cached period range so the next analysis pass re-derives
-- it from the freshly parsed activity days.
UPDATE ddd_files
   SET period_start = NULL,
       period_end   = NULL
WHERE  file_type  = 'driver'
  AND  is_deleted = 0;

-- Step 3: Reset border_crossings in the continuous driver_activity_calendar so
-- the lazy re-parse mechanism re-evaluates crossings alongside the updated
-- vehicle data on the next calendar or timeline page load.
UPDATE driver_activity_calendar
   SET border_crossings = NULL;
