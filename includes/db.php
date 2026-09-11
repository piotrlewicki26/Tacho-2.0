<?php
/**
 * TachoPro 2.0 – Database connection (PDO)
 * Configuration is read from environment variables or config.php
 */

if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

define('DB_HOST',    defined('CFG_DB_HOST')    ? CFG_DB_HOST    : ($_ENV['DB_HOST']    ?? 'localhost'));
define('DB_NAME',    defined('CFG_DB_NAME')    ? CFG_DB_NAME    : ($_ENV['DB_NAME']    ?? 'tachopro'));
define('DB_USER',    defined('CFG_DB_USER')    ? CFG_DB_USER    : ($_ENV['DB_USER']    ?? 'root'));
define('DB_PASS',    defined('CFG_DB_PASS')    ? CFG_DB_PASS    : ($_ENV['DB_PASS']    ?? ''));
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Do not expose credentials in error message
            error_log('DB connection failed: ' . $e->getMessage());
            die(json_encode(['error' => 'Database connection failed.']));
        }
    }
    return $pdo;
}
