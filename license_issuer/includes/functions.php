<?php
/**
 * License Issuer – Auth helpers
 */

function isLILoggedIn(): bool {
    return !empty($_SESSION['li_user_id']) && !empty($_SESSION['li_role'])
        && in_array($_SESSION['li_role'], ['superadmin'], true);
}

function liRequireLogin(): void {
    if (!isLILoggedIn()) {
        header('Location: index.php?page=login');
        exit;
    }
}

/**
 * Attempt login against the users table (superadmin only).
 */
function liLogin(string $username, string $password): bool {
    $db = liGetDB();
    $stmt = $db->prepare(
        'SELECT id, password_hash, role, is_active
         FROM users WHERE username=? AND role="superadmin" LIMIT 1'
    );
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active']) return false;
    if (!password_verify($password, $user['password_hash'])) return false;

    // Regenerate session to prevent fixation
    session_regenerate_id(true);
    $_SESSION['li_user_id'] = $user['id'];
    $_SESSION['li_role']    = $user['role'];
    $_SESSION['li_username']= $username;

    // Update last_login
    $db->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$user['id']]);
    return true;
}

/**
 * Generate a license key tied to the company's unique code, modules and validity.
 */
function liGenerateLicenseKey(string $companyCode, array $modules, string $validUntil, int $maxUsers, int $maxVehicles, int $maxDrivers): string {
    $payload = $companyCode
             . implode('|', $modules)
             . $validUntil
             . $maxUsers . '/' . $maxVehicles . '/' . $maxDrivers
             . bin2hex(random_bytes(16));
    return hash('sha256', $payload);
}

/**
 * Escape for HTML output.
 */
function liE(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Simple CSRF token (stored in session).
 */
function liCsrfToken(): string {
    if (empty($_SESSION['li_csrf'])) {
        $_SESSION['li_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['li_csrf'];
}

function liValidateCsrf(string $token): bool {
    return hash_equals($_SESSION['li_csrf'] ?? '', $token);
}

function fmtDate(?string $d): string {
    if (!$d) return '—';
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('d.m.Y') : htmlspecialchars($d, ENT_QUOTES, 'UTF-8');
}

function liFlash(string $type, string $msg): void {
    $_SESSION['li_flash'] = ['type' => $type, 'msg' => $msg];
}

function liFlashHtml(): string {
    if (empty($_SESSION['li_flash'])) return '';
    $f = $_SESSION['li_flash'];
    unset($_SESSION['li_flash']);
    $type = in_array($f['type'], ['success','danger','warning','info']) ? $f['type'] : 'info';
    return '<div class="alert alert-'.$type.' alert-dismissible fade show" role="alert">'
         . liE($f['msg'])
         . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
