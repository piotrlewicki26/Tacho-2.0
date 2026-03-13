-- TachoPro 2.0 – Migration 008: per-leg route data + min-wage tracking in delegations
-- Adds:
--   trasa          JSON  – per-leg array [{country,days,hours,operationType,kilometers}]
--   min_wage_total DECIMAL(10,2) – calculated Mobility Package minimum wage total (EUR)
--   base_salary_pln DECIMAL(10,2) – driver base salary snapshot at delegation creation (PLN)
-- The legacy `countries` column is kept for backward compatibility.

ALTER TABLE `delegations`
  ADD COLUMN `trasa`           JSON          DEFAULT NULL
    COMMENT 'Per-leg route: [{country,days,hours,operationType,kilometers}]'
    AFTER `countries`,
  ADD COLUMN `min_wage_total`  DECIMAL(10,2) DEFAULT NULL
    COMMENT 'Total Mobility Package minimum wage obligation (EUR)'
    AFTER `diet_total`,
  ADD COLUMN `base_salary_pln` DECIMAL(10,2) DEFAULT NULL
    COMMENT 'Driver base salary snapshot (PLN) at time of delegation creation'
    AFTER `min_wage_total`;
