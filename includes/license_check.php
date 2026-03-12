<?php
/**
 * TachoPro 2.0 – License checker
 * Verifies that the company has a valid license for the requested module.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Load the active license for the current company.
 * Returns the license row or null.
 */
function getActiveLicense(): ?array {
    static $license = false;   // false = not yet loaded
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
 * Modules: core, delegation, driver_analysis, vehicle_analysis
 */
function hasModule(string $module): bool {
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
