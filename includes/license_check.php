<?php
/**
 * TachoPro 2.0 – Access checker
 *
 * All modules are freely accessible to any authenticated user.
 * The old plan/license gating has been removed.
 */
require_once __DIR__ . '/auth.php';

/**
 * Returns true for every logged-in user (no plan restriction).
 * The $module parameter is kept for backward compatibility with call sites.
 */
function hasModule(string $module): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Require login; redirect to login page if not authenticated.
 */
function requireModule(string $module): void {
    requireLogin();
}

/**
 * Always returns true – no per-entity limits enforced.
 */
function licenseAllowsMore(string $type): bool {
    return true;
}

/**
 * Returns 0 (= unlimited) for every type.
 */
function licenseLimit(string $type): int {
    return 0;
}

/**
 * @deprecated Kept as stub for compatibility. Always returns null.
 */
function getActiveLicense(): ?array {
    return null;
}
