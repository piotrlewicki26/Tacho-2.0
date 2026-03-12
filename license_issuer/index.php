<?php
/**
 * TachoPro 2.0 – License Issuer Application
 * Standalone app for generating and managing licenses.
 * Connects to the same MySQL database as TachoPro 2.0.
 *
 * Copy config.example.php from the main application to:
 *   license_issuer/config.php
 * and fill in your database credentials.
 */

// ── Bootstrap ────────────────────────────────────────────────
define('LI_VERSION', '2.0');
define('LI_PUBLISHED', '2025-01-01');

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    die('<b>Error:</b> Configuration file not found.<br>Copy <code>config.example.php</code> to <code>license_issuer/config.php</code> and fill in your database credentials.');
}
require_once $configPath;
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start();

// ── Route ────────────────────────────────────────────────────
$route = $_GET['page'] ?? 'login';

if ($route !== 'login' && !isLILoggedIn()) {
    header('Location: index.php?page=login');
    exit;
}

switch ($route) {
    case 'logout':
        session_destroy();
        header('Location: index.php?page=login');
        exit;

    case 'login':
        include __DIR__ . '/pages/login.php';
        break;

    case 'dashboard':
        include __DIR__ . '/pages/dashboard.php';
        break;

    case 'issue':
        include __DIR__ . '/pages/issue.php';
        break;

    case 'view':
        include __DIR__ . '/pages/view.php';
        break;

    default:
        header('Location: index.php?page=dashboard');
        exit;
}
