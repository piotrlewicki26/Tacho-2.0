-- Migration 024: Re-parse driver DDD files to fix cross-midnight rest day rejection
--
-- Background:
--   parseDddFile() prepended an implicit carry-over REST segment (00:00 → first
--   activity-change entry) only when the gap was ≤ 4 hours (240 min).  When a
--   driver rested from the previous day and the rest ended more than 4 hours after
--   midnight (firstTmin > 240), no implicit segment was added.  This caused:
--
--     $total = 1440 − firstTmin  (e.g. rest ending at 14:00 → total = 600 min)
--
--   which fell below the 1350-min acceptance threshold, so the day record was
--   REJECTED and stored as NULL (absent) in ddd_activity_days.
--
--   JavaScript buildRestSpans() treats absent days as all-rest, extending any
--   ongoing rest span through the full 24-hour empty day.  For a weekly rest of
--   ~40-44 hours that ends on a Sunday afternoon, this inflated the computed rest
--   duration above 45 hours (the EU 561/2006 threshold for a regular weekly rest),
--   which suppressed the shortfall calculation and prevented the compensation-for-
--   shortened-weekly-rest purple overlay from appearing on the timeline.
--
--   Fix applied in includes/functions.php:
--     The $firstTmin <= 240 guard was removed; an implicit carry-over REST segment
--     is now prepended for ANY firstTmin > 0.  The $total = 1440 assertion then
--     always passes, and the day record is accepted with the correct activities.
--     The $total range check [1350, 1460] still protects against garbage records
--     whose remaining activity slots sum outside the valid range.
--
-- Re-parse strategy: identical to migrate_023 – delete the per-day activity cache
-- so the corrected parser rebuilds it on the next driver analysis or calendar load.

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
-- activity data on the next calendar or timeline page load.
UPDATE driver_activity_calendar
   SET border_crossings = NULL;
