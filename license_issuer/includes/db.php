<?php
/**
 * License Issuer – Database connection (PDO)
 * Reuses the same database as TachoPro 2.0.
 */

if (!defined('CFG_DB_HOST')) {
    $configPath = __DIR__ . '/../config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    }
}

define('LI_DB_HOST',    defined('CFG_DB_HOST') ? CFG_DB_HOST : ($_ENV['DB_HOST'] ?? 'localhost'));
define('LI_DB_NAME',    defined('CFG_DB_NAME') ? CFG_DB_NAME : ($_ENV['DB_NAME'] ?? 'tachopro'));
define('LI_DB_USER',    defined('CFG_DB_USER') ? CFG_DB_USER : ($_ENV['DB_USER'] ?? 'root'));
define('LI_DB_PASS',    defined('CFG_DB_PASS') ? CFG_DB_PASS : ($_ENV['DB_PASS'] ?? ''));

function liGetDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . LI_DB_HOST . ';dbname=' . LI_DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, LI_DB_USER, LI_DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('LI DB connection failed: ' . $e->getMessage());
            die('<div style="font-family:sans-serif;padding:2rem;color:#c00"><b>Database connection failed.</b> Check config.php credentials.</div>');
        }
    }
    return $pdo;
}
