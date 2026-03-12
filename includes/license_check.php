<?php
/**
 * TachoPro 2.0 – License checker
 * Verifies that the company has a valid license for the requested module.
 * Enforces per-company limits on users, vehicles, and drivers.
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

/**
 * Check whether adding one more entity of $type is within the license limit.
 * $type: 'users' | 'vehicles' | 'drivers'
 * Returns true if allowed, false if limit would be exceeded.
 * A limit of 0 means unlimited.
 */
function licenseAllowsMore(string $type): bool {
    $license = getActiveLicense();
    if (!$license) return false;

    $limitCol = match($type) {
        'users'    => 'max_users',
        'vehicles' => 'max_vehicles',
        'drivers'  => 'max_drivers',
        default    => null,
    };
    if (!$limitCol) return true;

    $limit = (int)($license[$limitCol] ?? 0);
    if ($limit === 0) return true;   // unlimited

    $companyId = (int)($_SESSION['company_id'] ?? 0);
    $db  = getDB();

    $countSql = match($type) {
        'users'    => 'SELECT COUNT(*) FROM users    WHERE company_id=? AND is_active=1 AND role != "superadmin"',
        'vehicles' => 'SELECT COUNT(*) FROM vehicles WHERE company_id=? AND is_active=1',
        'drivers'  => 'SELECT COUNT(*) FROM drivers  WHERE company_id=? AND is_active=1',
        default    => null,
    };
    if (!$countSql) return true;

    $stmt = $db->prepare($countSql);
    $stmt->execute([$companyId]);
    $current = (int)$stmt->fetchColumn();

    return $current < $limit;
}

/**
 * Return the numeric limit for a given type from the active license.
 * Returns 0 for unlimited.
 */
function licenseLimit(string $type): int {
    $license = getActiveLicense();
    if (!$license) return 0;
    $col = match($type) {
        'users'    => 'max_users',
        'vehicles' => 'max_vehicles',
        'drivers'  => 'max_drivers',
        default    => null,
    };
    return $col ? (int)($license[$col] ?? 0) : 0;
}

