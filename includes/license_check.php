<?php
/**
 * TachoPro 2.0 – License checker
 * Verifies that the company has a valid subscription / plan.
 * Works with the new subscription-based billing model.
 *
 * For backward-compatibility the legacy `licenses` table check is kept as a
 * fallback – a company that has a valid legacy license record still gets access.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/subscription.php';

/**
 * Load the active license for the current company (legacy table).
 * Returns the license row or null.
 */
function getActiveLicense(): ?array {
    static $license = false;
    if ($license !== false) return $license ?: null;

    $companyId = $_SESSION['company_id'] ?? null;
    if (!$companyId) { $license = null; return null; }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM licenses
         WHERE company_id = ?
           AND valid_from  <= CURDATE()
           AND valid_until >= CURDATE()
         ORDER BY valid_until DESC
         LIMIT 1'
    );
    $stmt->execute([$companyId]);
    $license = $stmt->fetch() ?: null;
    return $license;
}

/**
 * Check whether the current company has access to a given module.
 *
 * Access is granted when EITHER:
 *  1. The company has plan='pro' (subscription model), OR
 *  2. The company has a valid legacy license row with the module enabled, OR
 *  3. The company has plan='demo' with an active trial (core module only).
 */
function hasModule(string $module): bool {
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if (!$companyId) return false;

    // Subscription model: pro plan grants all modules
    $co = getCompanyPlan($companyId);
    if ($co && $co['plan'] === 'pro') return true;

    // Demo plan: only 'core' is accessible while trial is active
    if ($co && $co['plan'] === 'demo') {
        if (isTrialExpired($companyId)) return false;
        return $module === 'core';
    }

    // Legacy license fallback
    $license = getActiveLicense();
    if (!$license) return false;
    $col = 'mod_' . $module;
    return isset($license[$col]) && (bool)$license[$col];
}

/**
 * Require a module or show a "not licensed" page.
 */
function requireModule(string $module): void {
    if (!hasModule($module)) {
        include __DIR__ . '/../templates/no_license.php';
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

