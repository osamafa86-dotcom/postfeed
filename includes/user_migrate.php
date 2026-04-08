<?php
/**
 * Auto-migration for the user dashboard feature.
 * Idempotent — safe to call on every request. The work only happens once;
 * after that it short-circuits via a cache flag.
 */

require_once __DIR__ . '/config.php';

function user_dashboard_migrate(): void {
    static $done = false;
    if ($done) return;

    $flagDir  = __DIR__ . '/../storage/cache';
    $flagFile = $flagDir . '/user_dashboard_migrated_v3.flag';
    if (is_file($flagFile)) { $done = true; return; }

    try {
        $db = getDB();
    } catch (Throwable $e) {
        return;
    }

    $tableHasColumn = function(PDO $db, string $table, string $col): bool {
        try {
            $stmt = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
            $stmt->execute([$table, $col]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) { return false; }
    };

    $tableExists = function(PDO $db, string $table): bool {
        try {
            $stmt = $db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) { return false; }
    };

    try {
        // Extend `users` with the reader columns, one by one, only when missing.
        $userColumns = [
            'username'        => "ADD COLUMN `username` VARCHAR(60) NULL AFTER `name`",
            'bio'             => "ADD COLUMN `bio` TEXT NULL AFTER `avatar_letter`",
            'theme'           => "ADD COLUMN `theme` VARCHAR(10) DEFAULT 'auto' AFTER `plan`",
            'reading_streak'  => "ADD COLUMN `reading_streak` INT DEFAULT 0 AFTER `theme`",
            'last_read_date'  => "ADD COLUMN `last_read_date` DATE DEFAULT NULL AFTER `reading_streak`",
            'notify_breaking' => "ADD COLUMN `notify_breaking` TINYINT(1) DEFAULT 1 AFTER `last_read_date`",
            'notify_followed' => "ADD COLUMN `notify_followed` TINYINT(1) DEFAULT 1 AFTER `notify_breaking`",
            'notify_digest'   => "ADD COLUMN `notify_digest` TINYINT(1) DEFAULT 0 AFTER `notify_followed`",
        ];
        foreach ($userColumns as $col => $ddl) {
            if (!$tableHasColumn($db, 'users', $col)) {
                try { $db->exec("ALTER TABLE `users` $ddl"); } catch (Throwable $e) {}
            }
        }

        // Allow the 'reader' role value (MySQL lets us widen the enum in place).
        try {
            $db->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin','editor','viewer','reader') DEFAULT 'reader'");
        } catch (Throwable $e) {}

        // Create child tables.
        $tables = [
            'user_bookmarks' => "CREATE TABLE IF NOT EXISTS `user_bookmarks` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `user_id` INT(11) NOT NULL,
                `article_id` INT(11) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_user_article` (`user_id`, `article_id`),
                KEY `idx_user_bookmarks` (`user_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'user_category_follows' => "CREATE TABLE IF NOT EXISTS `user_category_follows` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `user_id` INT(11) NOT NULL,
                `category_id` INT(11) NOT NULL,
                `priority` INT(11) DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_user_cat` (`user_id`, `category_id`),
                KEY `idx_user_cat_priority` (`user_id`, `priority`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'user_source_follows' => "CREATE TABLE IF NOT EXISTS `user_source_follows` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `user_id` INT(11) NOT NULL,
                `source_id` INT(11) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_user_src` (`user_id`, `source_id`),
                KEY `idx_user_src` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'user_reading_history' => "CREATE TABLE IF NOT EXISTS `user_reading_history` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `user_id` INT(11) NOT NULL,
                `article_id` INT(11) NOT NULL,
                `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `seconds_spent` INT(11) DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_user_read` (`user_id`, `read_at`),
                KEY `idx_article_read` (`article_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'user_notifications' => "CREATE TABLE IF NOT EXISTS `user_notifications` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `user_id` INT(11) NOT NULL,
                `type` VARCHAR(50) DEFAULT 'info',
                `title` VARCHAR(500) NOT NULL,
                `body` TEXT DEFAULT NULL,
                `link` VARCHAR(500) DEFAULT NULL,
                `article_id` INT(11) DEFAULT NULL,
                `icon` VARCHAR(10) DEFAULT NULL,
                `is_read` TINYINT(1) DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_un_user` (`user_id`, `is_read`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'article_comments' => "CREATE TABLE IF NOT EXISTS `article_comments` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `article_id` INT(11) NOT NULL,
                `user_id` INT(11) NOT NULL,
                `parent_id` INT(11) DEFAULT NULL,
                `body` TEXT NOT NULL,
                `likes` INT(11) DEFAULT 0,
                `is_hidden` TINYINT(1) DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ac_article` (`article_id`, `created_at`),
                KEY `idx_ac_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'comment_likes' => "CREATE TABLE IF NOT EXISTS `comment_likes` (
                `user_id` INT(11) NOT NULL,
                `comment_id` INT(11) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `comment_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'article_reactions' => "CREATE TABLE IF NOT EXISTS `article_reactions` (
                `user_id` INT(11) NOT NULL,
                `article_id` INT(11) NOT NULL,
                `reaction` ENUM('like','dislike') NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `article_id`),
                KEY `idx_ar_article` (`article_id`, `reaction`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'article_share_counts' => "CREATE TABLE IF NOT EXISTS `article_share_counts` (
                `article_id` INT(11) NOT NULL,
                `count` INT(11) NOT NULL DEFAULT 0,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`article_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($tables as $name => $ddl) {
            if (!$tableExists($db, $name)) {
                try { $db->exec($ddl); } catch (Throwable $e) {}
            }
        }
    } catch (Throwable $e) {
        // don't block pages on migration errors
        error_log('user_dashboard_migrate: ' . $e->getMessage());
        return;
    }

    if (!is_dir($flagDir)) @mkdir($flagDir, 0775, true);
    @file_put_contents($flagFile, date('c'));
    $done = true;
}
