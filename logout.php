<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
startSession();
// Log before destroying session
if (!empty($_SESSION['user_id'])) {
    auditLog('logout', 'user', (int)$_SESSION['user_id'], 'Wylogowano: ' . ($_SESSION['username'] ?? ''));
}
logout();
