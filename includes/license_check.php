<?php
/**
 * TachoPro 2.0 – Access checker
 *
 * The module licensing system has been removed.
 * All modules are available to any company that has an active account
 * (plan='pro', or plan='demo' with a non-expired trial).
 *
 * `hasModule()` / `requireModule()` are kept as thin wrappers so that
 * existing call-sites do not need to change.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/subscription.php';

/**
 * Returns true when the current company has access.
 * All modules are granted equally – no per-module restrictions.
 *
 * Access is granted when:
 *  - company plan = 'pro', OR
 *  - company plan = 'demo' AND trial has not expired
 */
function hasModule(string $module): bool {
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if (!$companyId) return false;

    $co = getCompanyPlan($companyId);
    if (!$co) return false;

    if ($co['plan'] === 'pro') return true;

    // Demo: all modules accessible while trial is still valid
    if ($co['plan'] === 'demo') {
        return !isTrialExpired($companyId);
    }

    return false;
}

/**
 * Require access or redirect to dashboard with a message.
 * No longer shows the "module unavailable" page.
 */
function requireModule(string $module): void {
    requireLogin();   // Must be logged in first

    if (!hasModule($module)) {
        // Trial has expired – send to billing page so they can upgrade
        flashSet('warning', 'Twój okres próbny wygasł. Przejdź na plan Pro, aby kontynuować.');
        header('Location: /billing.php');
        exit;
    }
}

/**
 * Check whether adding one more entity of $type is within the plan limits.
 * Demo: max 2 drivers, 2 vehicles. Pro: unlimited.
 */
function licenseAllowsMore(string $type): bool {
    return subscriptionAllowsMore($type);
}

/**
 * Return the numeric limit for a given type.
 * Returns 0 for unlimited (Pro), demo limits for demo.
 */
function licenseLimit(string $type): int {
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    $co = getCompanyPlan($companyId);
    if ($co && $co['plan'] === 'pro') return 0; // unlimited

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

