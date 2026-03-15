-- Migration 019: reset ALL border_crossings after parseBorderCrossings "best-result" fix.
--
-- Background:
--   parseBorderCrossings() previously returned on the FIRST non-empty TLV block match.
--   For cards like Palusinski (C_20260311_0402_D_Palusinski) and Szczepanski
--   (C_20260311_0245_T_Szczepanski) this caused an early exit on a spurious small
--   block (e.g. a 256-byte 0x0522 block containing coincidental timestamp data)
--   before the real large data block (typically tagged 0x050B or 0x0504, several
--   kilobytes long) was ever reached.  The result was either 0 or 1 fake crossing
--   stored in ddd_activity_days, while the actual 30+ international crossings were
--   completely missed.
--
--   The fix accumulates the "best" (most crossing records) result across ALL matching
--   TLV blocks and returns it after scanning the whole file.  This surfaces the
--   correct crossings for cards that were previously undetected.
--
--   To trigger a full re-parse for all affected files:
--     • ddd_activity_days.border_crossings is reset to NULL.
--       The driver_analysis module re-parses the binary file on next page access
--       whenever any row for that file is NULL (and persists the new result).
--     • driver_activity_calendar.border_crossings is also reset to NULL so the
--       calendar view no longer shows stale data from false-positive crossings.
--       Calendar rows will be repopulated the next time a DDD file for that driver
--       is uploaded, or by re-running the back-fill INSERT from migration 018.

UPDATE `ddd_activity_days`
   SET `border_crossings` = NULL;

UPDATE `driver_activity_calendar`
   SET `border_crossings` = NULL;
