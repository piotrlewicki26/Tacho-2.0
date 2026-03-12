<?php
/**
 * TachoPro 2.0 – Access checker
 *
 * Two paid tiers:
 *
 *   PRO        – core features: drivers, vehicles, DDD archive,
 *                driver analysis, vehicle analysis.
 *                One company per account. No delegation / violations / vacation reports.
 *
 *   PRO Module+ – everything in PRO plus: delegation management (per diems,
 *                 mobility package), driver violation reports with penalties,
 *                 vacation reports, and multi-company management.
 *
 * During an active demo trial ALL modules are accessible.
 * After trial expiry the user is redirected to billing.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/subscription.php';

/**
 * Modules available on the PRO plan (and above).
 * These are also accessible during an active demo trial.
 */
const PRO_MODULES = ['core', 'driver_analysis', 'vehicle_analysis'];

/**
 * Modules that require the PRO Module+ plan.
 * They are accessible during an active demo trial, but NOT on the PRO plan.
 */
const PRO_PLUS_MODULES = ['delegation', 'violations', 'reports', 'multi_company'];

/**
 * Returns true when the current company has access to the given module.
 *
 *  PRO Module+ : full access to all modules.
 *  PRO         : access to PRO_MODULES only.
 *  Demo (active) : access to all modules (same as PRO Module+ during trial).
 *  Demo (expired): no access.
 */
function hasModule(string $module): bool {
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if (!$companyId) return false;

    $co = getCompanyPlan($companyId);
    if (!$co) return false;

    // PRO Module+ plan: full access to everything
    if ($co['plan'] === PLAN_PRO_PLUS) return true;

    // PRO plan: access to PRO_MODULES only
    if ($co['plan'] === PLAN_PRO) {
        return in_array($module, PRO_MODULES, true);
    }

    // Demo: all modules accessible while the trial is still valid
    if ($co['plan'] === PLAN_DEMO) {
        return !isTrialExpired($companyId);
    }

    return false;
}

/**
 * Require access or redirect with an appropriate upgrade message.
 */
function requireModule(string $module): void {
    requireLogin();

    if (!hasModule($module)) {
        $co   = getCompanyPlan((int)($_SESSION['company_id'] ?? 0));
        $plan = $co['plan'] ?? PLAN_DEMO;

        // PRO user trying to access a PRO Module+-only feature
        if ($plan === PLAN_PRO && in_array($module, PRO_PLUS_MODULES, true)) {
            flashSet('warning', 'Ta funkcja jest dostępna wyłącznie w pakiecie PRO Module+. Przejdź na wyższy plan.');
            header('Location: /billing.php#upgrade-section');
            exit;
        }

        // Demo expired (or unknown plan)
        flashSet('warning', 'Twój okres próbny wygasł. Wybierz plan PRO lub PRO Module+, aby kontynuować.');
        header('Location: /billing.php');
        exit;
    }
}

/**
 * Check whether adding one more entity of $type is within the plan limits.
 * Demo: max 2 drivers, 2 vehicles. Paid plans: unlimited.
 */
function licenseAllowsMore(string $type): bool {
    return subscriptionAllowsMore($type);
}

/**
 * Return the numeric limit for a given type.
 * Returns 0 for unlimited (paid plans), demo limits for demo.
 */
function licenseLimit(string $type): int {
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    $co = getCompanyPlan($companyId);
    if ($co && ($co['plan'] === PLAN_PRO || $co['plan'] === PLAN_PRO_PLUS)) return 0; // unlimited

    return match($type) {
        'drivers'  => DEMO_MAX_DRIVERS,
        'vehicles' => DEMO_MAX_VEHICLES,
        'users'    => 0,
        default    => 0,
    };
}

/**
 * @deprecated No longer used. The licenses table is no longer checked for access control.
 *             Kept only as a stub for any third-party code that may call this function.
 * @return null always
 */
function getActiveLicense(): ?array {
    return null;
}

