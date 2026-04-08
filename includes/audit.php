<?php
/**
 * Audit logging for admin actions.
 *
 * Usage:
 *   audit_log('article.delete', 'article', $id, ['title' => $title]);
 */

require_once __DIR__ . '/rate_limit.php'; // for client_ip()

function audit_ensure_table() {
    static $ensured = false;
    if ($ensured) return;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED DEFAULT NULL,
            user_name VARCHAR(100) DEFAULT NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(50) DEFAULT NULL,
            entity_id INT UNSIGNED DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            meta TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_action (action),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ensured = true;
    } catch (Exception $e) {
        error_log('audit table: ' . $e->getMessage());
    }
}

/**
 * Record an admin action.
 */
function audit_log($action, $entityType = null, $entityId = null, array $meta = []) {
    audit_ensure_table();
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO audit_log
            (user_id, user_name, action, entity_type, entity_id, ip, user_agent, meta)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['admin_id']   ?? null,
            $_SESSION['admin_name'] ?? null,
            mb_substr($action, 0, 80),
            $entityType ? mb_substr($entityType, 0, 50) : null,
            $entityId !== null ? (int)$entityId : null,
            client_ip(),
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Exception $e) {
        error_log('audit_log: ' . $e->getMessage());
    }
}
