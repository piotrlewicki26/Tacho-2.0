-- Migration 020: fix truncated activity data caused by asymmetric IQR filtering.
--
-- Background:
--   parseDddFile() used a symmetric IQR filter (p25 ± 3×IQR) on presenceCounter
--   values to remove stale records from old card use periods.  When a driver
--   switched to a vehicle with a higher-counter tachograph, the most recent
--   activity records had presenceCounters above p_max (p75 + 3×IQR) and were
--   incorrectly discarded.
--
--   For example, Szczepanski's card has two tachograph sessions:
--     • Session 1: pres 1860–2457  (May 2025 – 01 Feb 2026)
--     • Session 2: pres 4096–4151  (02 Feb 2026 – 11 Mar 2026)
--   The IQR was calibrated on session 1 (p_max ≈ 3405) so all 38 days of session 2
--   were filtered out, leaving ddd_activity_days with no rows after 2026-02-01.
--   Border crossings that occurred during that period (2026-02-06 to 2026-03-10)
--   were therefore never stored and never shown in the driver_analysis view.
--
--   A second issue: parseDddFile() accepted candidate timestamps up to
--   $curYear+1 (Dec 2027 when run in 2026), allowing coincidental binary patterns
--   with far-future timestamps to pass slot-validation (e.g. Palusinski's spurious
--   2027-08-13 record), causing period_end to be reported 17 months too late.
--
-- Fixes applied to includes/functions.php (this migration triggers re-parse):
--   1. IQR lower-fence-only: only records below p25 - 3×IQR are dropped; records
--      above p75 + 3×IQR are kept (they are the most recent data from a higher-
--      counter tachograph and must not be silently discarded).
--   2. tsMax ceiling: candidate timestamps more than 90 days in the future are
--      rejected before IQR, preventing coincidental far-future byte sequences from
--      appearing as valid activity records or distorting period_end.
--
-- Re-parse strategy:
--   • Files where MAX(ddd_activity_days.date) is more than 28 days before the
--     file's download_date have their ddd_activity_days rows deleted and their
--     period_start/period_end reset to NULL.
--     The driver_analysis fallback path (binary re-parse) will recreate the rows
--     correctly using the fixed parser on the next page visit.
--   • ALL border_crossings are reset to NULL so every file's crossings are
--     re-evaluated by the improved parser on the next driver_analysis visit.

-- Step 1: Delete ddd_activity_days for files whose stored activity period is
-- truncated (last activity date > 28 days before the file's download date).
-- This covers the Szczepanski-style bug where the most recent session was cut off.
DELETE dad
FROM   ddd_activity_days dad
JOIN   ddd_files f ON f.id = dad.file_id
WHERE  f.file_type  = 'driver'
  AND  f.is_deleted = 0
  AND  f.download_date IS NOT NULL
  AND  (
         SELECT MAX(d2.date)
         FROM   ddd_activity_days d2
         WHERE  d2.file_id = dad.file_id
       ) < DATE_SUB(f.download_date, INTERVAL 28 DAY);

-- Step 2: Reset period_start / period_end for those files so they are
-- recalculated on the next analysis pass.
UPDATE ddd_files f
   SET f.period_start = NULL,
       f.period_end   = NULL
WHERE  f.file_type  = 'driver'
  AND  f.is_deleted = 0
  AND  f.download_date IS NOT NULL
  AND  f.period_end IS NOT NULL
  AND  NOT EXISTS (
         SELECT 1
         FROM   ddd_activity_days d
         WHERE  d.file_id = f.id
       );

-- Step 3: Reset ALL border_crossings to NULL so the fixed parser re-evaluates
-- every file's crossings on the next driver_analysis visit.
UPDATE `ddd_activity_days`
   SET `border_crossings` = NULL;

UPDATE `driver_activity_calendar`
   SET `border_crossings` = NULL;
