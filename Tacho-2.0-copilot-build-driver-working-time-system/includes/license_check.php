<?php
/**
 * TachoPro 2.0 – Access / plan gating
 *
 * Plans:
 *   demo     – 14-day trial, max DEMO_MAX_DRIVERS / DEMO_MAX_VEHICLES, all
 *              features accessible; blocked after trial expires.
 *   pro      – Full DDD read & analysis (driver_analysis, vehicle_analysis,
 *              reports). NOT delegation, violations, multi_company.
 *              PLN 15 net/driver + PLN 10 net/vehicle per month.
 *   pro_plus – Everything in PRO plus delegation management, infringement
 *              reports, and multi-company support.
 *              PLN 25 net/driver + PLN 15 net/vehicle per month.
 *
 * Module IDs used across the codebase:
 *   core             – dashboard, drivers, vehicles, files, audit, settings
 *   driver_analysis  – DDD tachograph timeline + EU 561 violation indicators
 *   vehicle_analysis – Vehicle DDD analysis
 *   reports          – Reports page
 *   delegation       – Delegation management (PRO+ only)
 *   violations       – Infringement reports (PRO+ only)
 *   multi_company    – Multiple companies per account (PRO+ only)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/subscription.php';

/**
 * Return the minimum plan required by a given module.
 *   'any'      – any logged-in user (including demo)
 *   'pro'      – PRO or PRO+ (or demo during active trial)
 *   'pro_plus' – PRO+ only (or demo during active trial)
 */
function _moduleRequiredPlan(string $module): string {
    return match ($module) {
        'delegation',
        'violations',
        'multi_company' => 'pro_plus',
        'core', 'any'   => 'any',
        default         => 'pro', // driver_analysis, vehicle_analysis, reports, …
    };
}

/**
 * Return true if the current company's plan grants access to $module.
 */
function hasModule(string $module): bool {
    if (empty($_SESSION['user_id'])) return false;

    $cid      = (int)($_SESSION['company_id'] ?? 0);
    $required = _moduleRequiredPlan($module);

    if ($required === 'any') return true;

    $co   = getCompanyPlan($cid);
    $plan = $co['plan'] ?? PLAN_DEMO;

    // PRO+ has access to every module.
    if ($plan === PLAN_PRO_PLUS) return true;

    // PRO has access to 'pro' modules only.
    if ($plan === PLAN_PRO) return $required === 'pro';

    // Demo plan: access allowed only while the trial is still active.
    return isTrialActive($cid);
}

/**
 * Require login + module access.
 * Redirects to /no_access.php with the appropriate reason when access is denied.
 */
function requireModule(string $module): void {
    requireLogin();

    if (hasModule($module)) return;

    $cid  = (int)($_SESSION['company_id'] ?? 0);
    $isPlusMod = _moduleRequiredPlan($module) === 'pro_plus';

    if (isDemo($cid) && isTrialExpired($cid)) {
        $reason = 'trial_expired';
    } elseif ($isPlusMod) {
        $reason = 'pro_plus_required';
    } else {
        $reason = 'pro_required';
    }

    $ref = urlencode($_SERVER['REQUEST_URI'] ?? '/dashboard.php');
    header('Location: /no_access.php?reason=' . $reason . '&ref=' . $ref);
    exit;
}

/**
 * Check whether the company can add more entities given plan/demo limits.
 */
function licenseAllowsMore(string $type, ?int $companyId = null): bool {
    return subscriptionAllowsMore($type, $companyId);
}

/**
 * Return the hard limit for a resource type under the current plan.
 * Returns 0 (= unlimited) for paid plans.
 */
function licenseLimit(string $type): int {
    $cid = (int)($_SESSION['company_id'] ?? 0);
    $co  = getCompanyPlan($cid);
    if (!$co || in_array($co['plan'], [PLAN_PRO, PLAN_PRO_PLUS], true)) return 0;
    return demoLimit($type);
}

/**
 * @deprecated Kept for compatibility. Returns basic company plan info.
 */
function getActiveLicense(): ?array {
    $cid = (int)($_SESSION['company_id'] ?? 0);
    return $cid ? getCompanyPlan($cid) : null;
}
