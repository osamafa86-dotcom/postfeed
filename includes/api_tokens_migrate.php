<?php
/**
 * Auto-migration for native mobile API (Bearer tokens + APNs device tokens).
 * Idempotent: short-circuits after first successful run via a flag file.
 */

require_once __DIR__ . '/config.php';

function api_tokens_migrate(): void {
    static $done = false;
    if ($done) return;

    $flagDir  = __DIR__ . '/../storage/cache';
    $flagFile = $flagDir . '/api_tokens_migrated_v1.flag';
    if (is_file($flagFile)) { $done = true; return; }

    try {
        $db = getDB();
    } catch (Throwable $e) {
        return;
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `api_tokens` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `user_id` INT(11) NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `token_prefix` VARCHAR(16) NOT NULL,
            `platform` ENUM('ios','android','web') NOT NULL DEFAULT 'ios',
            `device_name` VARCHAR(120) DEFAULT NULL,
            `app_version` VARCHAR(32) DEFAULT NULL,
            `last_used_at` TIMESTAMP NULL DEFAULT NULL,
            `last_ip` VARCHAR(45) DEFAULT NULL,
            `revoked_at` TIMESTAMP NULL DEFAULT NULL,
            `expires_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_token_hash` (`token_hash`),
            KEY `idx_user_active` (`user_id`, `revoked_at`),
            KEY `idx_prefix` (`token_prefix`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `device_tokens` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `user_id` INT(11) DEFAULT NULL,
            `api_token_id` INT(11) DEFAULT NULL,
            `platform` ENUM('ios','android') NOT NULL,
            `push_token` VARCHAR(255) NOT NULL,
            `bundle_id` VARCHAR(120) DEFAULT NULL,
            `locale` VARCHAR(10) DEFAULT 'ar',
            `app_version` VARCHAR(32) DEFAULT NULL,
            `os_version` VARCHAR(32) DEFAULT NULL,
            `device_model` VARCHAR(60) DEFAULT NULL,
            `is_sandbox` TINYINT(1) DEFAULT 0,
            `last_seen_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_push_token` (`push_token`),
            KEY `idx_user_platform` (`user_id`, `platform`),
            KEY `idx_api_token` (`api_token_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if (!is_dir($flagDir)) @mkdir($flagDir, 0755, true);
        @file_put_contents($flagFile, date('c'));
        $done = true;
    } catch (Throwable $e) {
        error_log('api_tokens_migrate: ' . $e->getMessage());
    }
}
