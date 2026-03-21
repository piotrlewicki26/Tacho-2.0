<?php
/**
 * TachoPro 2.0 – Audit log helpers
 * Records who did what, when, and what changed.
 */

require_once __DIR__ . '/db.php';

/**
 * Write an audit log entry.
 *
 * @param string      $action      e.g. 'create', 'update', 'delete', 'login', 'logout'
 * @param string|null $entityType  e.g. 'driver', 'vehicle', 'user', 'company'
 * @param int|null    $entityId    Primary key of the affected record
 * @param string|null $description Human-readable summary
 * @param array|null  $oldValues   Previous field values (for update/delete)
 * @param array|null  $newValues   New field values (for create/update)
 */
function auditLog(
    string  $action,
    ?string $entityType  = null,
    ?int    $entityId    = null,
    ?string $description = null,
    ?array  $oldValues   = null,
    ?array  $newValues   = null
): void {
    try {
        $db        = getDB();
        $companyId = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : null;
        $userId    = isset($_SESSION['user_id'])    ? (int)$_SESSION['user_id']    : null;
        $username  = $_SESSION['username'] ?? null;
        $ip        = getClientIpForAudit();

        // Remove sensitive fields from logged values
        $oldValues = sanitiseAuditValues($oldValues);
        $newValues = sanitiseAuditValues($newValues);

        $db->prepare(
            'INSERT INTO audit_log
             (company_id, user_id, username, action, entity_type, entity_id,
              description, old_values, new_values, ip_address)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $companyId,
            $userId,
            $username,
            $action,
            $entityType,
            $entityId,
            $description,
            $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
            $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
            $ip,
        ]);
    } catch (Throwable $e) {
        // Audit failures must never break the application
        error_log('AuditLog error: ' . $e->getMessage());
    }
}

/**
 * Strip fields that should not appear in audit logs.
 */
function sanitiseAuditValues(?array $values): ?array {
    if (!$values) return null;
    $sensitive = ['password', 'password_hash', 'csrf_token', 'stripe_secret'];
    foreach ($sensitive as $k) {
        if (array_key_exists($k, $values)) {
            $values[$k] = '***';
        }
    }
    return $values;
}

/**
 * Get client IP (audit context – same logic as auth.php but standalone).
 */
function getClientIpForAudit(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (in_array($ip, ['127.0.0.1', '::1']) && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
}

/**
 * Retrieve audit log entries for a company (paginated).
 */
function getAuditLog(int $companyId, int $limit = 50, int $offset = 0): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM audit_log
         WHERE company_id = ?
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->execute([$companyId, $limit, $offset]);
    return $stmt->fetchAll();
}

/**
 * Count audit log entries for a company.
 */
function countAuditLog(int $companyId): int {
    $db   = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM audit_log WHERE company_id = ?');
    $stmt->execute([$companyId]);
    return (int)$stmt->fetchColumn();
}
