<?php
declare(strict_types=1);

namespace NewsFlow\Security;

use PDO;
use NewsFlow\Database;

final class AuditLogger
{
    private PDO $db;
    private static bool $ensured = false;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function ensureTable(): void
    {
        if (self::$ensured) return;
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS audit_log (
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
            self::$ensured = true;
        } catch (\Throwable $e) {
            error_log('AuditLogger::ensureTable: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $meta = []
    ): void {
        $this->ensureTable();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO audit_log
                 (user_id, user_name, action, entity_type, entity_id, ip, user_agent, meta)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $_SESSION['admin_id']   ?? null,
                $_SESSION['admin_name'] ?? null,
                mb_substr($action, 0, 80),
                $entityType ? mb_substr($entityType, 0, 50) : null,
                $entityId,
                RateLimiter::clientIp(),
                mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            error_log('AuditLogger::log: ' . $e->getMessage());
        }
    }
}
