-- TachoPro 2.0 – Migration 003: Two-package system (PRO / PRO Module+)
--
-- Changes the `plan` column of `companies` to include 'pro_plus' as a new tier.
--
-- Package summary:
--   pro       – Full DDD analysis, DDD archive, driver & vehicle analysis modules,
--               unlimited drivers/vehicles, ONE company per account.
--               PLN 15 net/driver/month + PLN 10 net/vehicle/month.
--
--   pro_plus  – Everything in PRO plus: driver violation reports with penalties,
--               delegation management (per diems + mobility package),
--               vacation reports, and the ability to manage multiple companies.

ALTER TABLE `companies`
  MODIFY COLUMN `plan`
    ENUM('demo','pro','pro_plus') NOT NULL DEFAULT 'demo'
    COMMENT 'demo=14-day trial; pro=PRO package (1 company); pro_plus=PRO Module+ (multi-company)';
