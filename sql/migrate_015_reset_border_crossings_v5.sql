-- Migration 015: reset border_crossings rows that may have been stored with
-- incorrect data by older versions of parseBorderCrossings().
--
-- Previous parser versions accepted any printable 1-3 character ASCII string
-- as a NationAlpha country code (e.g. "ROB"), which could produce false-positive
-- crossing records when scanning a large activity-data TLV block.  The improved
-- parser (v5) uses a whitelist of valid EU plate codes and also reorders the
-- record-size scan to try 10-byte records before 12-byte records.
--
-- Resetting all non-null, non-sentinel rows to NULL causes the improved parser
-- to re-run on the next page view of the driver analysis module, replacing any
-- previously cached garbage with the correct crossing data.

UPDATE `ddd_activity_days`
   SET `border_crossings` = NULL
 WHERE `border_crossings` IS NOT NULL
   AND `border_crossings` NOT IN ('0', 'null', '[]', 'false');
