<?php
/**
 * TachoPro 2.0 – Authentication helpers
 */
require_once __DIR__ . '/db.php';

define('SESSION_NAME',      'tachopro_sess');
define('SESSION_LIFETIME',  3600 * 8);     // 8 hours
define('MAX_ATTEMPTS',      5);
define('LOCKOUT_MINUTES',   15);
define('CSRF_TOKEN_LENGTH', 32);

/**
 * Start a secure session.
 */
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Strict');
        // Use HTTPS cookie flag only when actually on HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', 1);
        }
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

/**
 * Return or create a CSRF token for the current session.
 */
function getCsrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a submitted CSRF token.
 */
function validateCsrf(string $token): bool {
    startSession();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Return the client's real IP address.
 */
function getClientIp(): string {
    $trusted = ['127.0.0.1', '::1'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Accept forwarded IP only from trusted proxies
    if (in_array($ip, $trusted) && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($forwarded[0]);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
}

/**
 * Check if an IP or username is currently locked out.
 */
function isLockedOut(string $ip, string $username): bool {
    $db = getDB();
    $cutoff = date('Y-m-d H:i:s', time() - LOCKOUT_MINUTES * 60);
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE (ip_address = ? OR username = ?) AND attempted_at >= ? AND success = 0'
    );
    $stmt->execute([$ip, $username, $cutoff]);
    return (int)$stmt->fetchColumn() >= MAX_ATTEMPTS;
}

/**
 * Record a login attempt.
 */
function recordAttempt(string $ip, string $username, bool $success): void {
    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO login_attempts (ip_address, username, success) VALUES (?, ?, ?)'
    );
    $stmt->execute([$ip, $username, $success ? 1 : 0]);
    // Prune old records to keep table lean
    $db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
}

/**
 * Attempt to log the user in.
 * Returns user row on success, false on failure.
 */
function attemptLogin(string $username, string $password): array|false {
    $ip = getClientIp();

    if (isLockedOut($ip, $username)) {
        return false;
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT u.*, c.unique_code AS company_code
         FROM users u
         JOIN companies c ON c.id = u.company_id
         WHERE u.username = ? AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        recordAttempt($ip, $username, false);
        return false;
    }

    // Rehash if algorithm/cost has changed
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
           ->execute([$newHash, $user['id']]);
    }

    recordAttempt($ip, $username, true);
    $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

    startSession();
    session_regenerate_id(true);

    $_SESSION['user_id']    = $user['id'];
    $_SESSION['company_id'] = $user['company_id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['company_code'] = $user['company_code'];
    $_SESSION['last_active']  = time();

    // Audit log (load audit helper lazily to avoid circular require issues)
    if (function_exists('auditLog')) {
        auditLog('login', 'user', $user['id'], 'Zalogowano: ' . $user['username']);
    }

    return $user;
}

/**
 * Ensure the user is authenticated; redirect otherwise.
 */
function requireLogin(): void {
    startSession();

    // Session expiry
    if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > SESSION_LIFETIME) {
        session_destroy();
        header('Location: /login.php?expired=1');
        exit;
    }

    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }

    $_SESSION['last_active'] = time();
}

/**
 * Check if the current user has at least the given role.
 * Hierarchy: superadmin > admin > manager > viewer
 */
function hasRole(string $minRole): bool {
    $hierarchy = ['viewer' => 1, 'manager' => 2, 'admin' => 3, 'superadmin' => 4];
    $current   = $hierarchy[$_SESSION['role'] ?? 'viewer'] ?? 0;
    $required  = $hierarchy[$minRole] ?? 999;
    return $current >= $required;
}

/**
 * Log the current user out.
 */
function logout(): void {
    startSession();
    session_unset();
    session_destroy();
    // Invalidate cookie
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: /login.php');
    exit;
}
