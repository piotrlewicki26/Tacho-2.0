-- Migration 016: reset border_crossings rows stored as the JSON integer 0 sentinel
-- ('0') by previous versions of parseBorderCrossings().
--
-- Background:
--   When parseBorderCrossings() could not find any crossing records it stored
--   json_encode(0) = '0' as a "confirmed empty" sentinel to prevent infinite
--   re-parsing.  However, two bugs in older parser versions caused '0' to be
--   written even for cards that do contain real border-crossing data:
--
--   1. Method 1 (structured CardPointerPlaceRecord parse) accepted any first byte
--      in range 1-365 as noOfUsedPointerPlaces without checking whether the
--      resulting expected block size actually matched the TLV block length.
--      In large EF_Places blocks (e.g. 31 KB EF_CardActivityDailyRecord blocks
--      misidentified as EF_Places) this caused the parser to scan random data as
--      "pointer records" and, if the year-range window was wide enough, find a
--      spurious crossing and return early — before scanning the real data area.
--
--   2. parseDddFile() derived the border-crossing year window from min/max of ALL
--      activity-day years.  Spurious activity records from outlier years (e.g. one
--      record with a 2024 timestamp in a 2026 card) extended bcYrMin to 2023 or
--      2024, which widened the window enough to trigger bug #1.
--
--   Both bugs are fixed in the current parser (v6):
--     • parseBorderCrossings() now requires the expected CardPointerPlaceRecord
--       block size to cover ≥ 70 % of the actual TLV block before accepting a
--       noPtr value from the raw first byte (Method 1 plausibility check).
--     • parseDddFile() caps bcYrMin at max(actYears) − 2, preventing the year
--       floor from being dragged too far back by outlier spurious records.
--
--   This migration resets all rows that were stored with the stale '0' sentinel
--   back to NULL so the improved parser runs on the next driver-analysis page
--   view and correctly populates border_crossings.

UPDATE `ddd_activity_days`
   SET `border_crossings` = NULL
 WHERE `border_crossings` = '0';
