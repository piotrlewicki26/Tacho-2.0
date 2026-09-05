-- Migration 011: reset stale border_crossings data so next page-load re-parses
-- with the corrected parser (primary tag: 05 22 / also tries 05 20 / 05 0E / 05 0B / 05 04 / 05 14).
--
-- Rows that hold '[]' were parsed by an earlier version that searched for the
-- wrong TLV tag and never found any crossings.  Setting them to NULL lets the
-- application re-parse on next view.  After re-parse the column will hold
-- either actual crossing data (e.g. '[{"ts":..}]') or JSON null ('null' string
-- as a confirmed-empty sentinel), so this re-parse only happens once.
--
-- Rows with JSON null ('null') or actual crossing data are unaffected since
-- the WHERE clause only matches the legacy empty-array string '[]'.

UPDATE `ddd_activity_days`
   SET `border_crossings` = NULL
 WHERE `border_crossings` = '[]';
