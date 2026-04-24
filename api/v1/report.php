<?php
/**
 * POST /api/v1/report — user-generated content reporting / blocking
 *
 * REQUIRED by App Store guideline 1.2 for apps with user-generated content.
 * Body: { "kind": "comment"|"user"|"article", "target_id": 123, "reason": "..." }
 *       { "kind": "block_user", "target_id": 123 } — blocks a user
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('POST');
$uid = api_require_user();
api_rate_limit('report', 30, 300);

$body = api_body();
$kind = (string)($body['kind'] ?? '');
$targetId = (int)($body['target_id'] ?? 0);
$reason = trim((string)($body['reason'] ?? ''));

if (!in_array($kind, ['comment','user','article','block_user'], true)) api_error('invalid_input', 'kind');
if ($targetId <= 0) api_error('invalid_input', 'target_id');
if ($kind !== 'block_user' && mb_strlen($reason) < 3) api_error('invalid_input', 'يرجى كتابة السبب');

try {
    $db = getDB();
    // Keep reports in a flat log table; create it on demand.
    $db->exec("CREATE TABLE IF NOT EXISTS `content_reports` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `reporter_id` INT(11) NOT NULL,
        `kind` VARCHAR(20) NOT NULL,
        `target_id` INT(11) NOT NULL,
        `reason` TEXT DEFAULT NULL,
        `status` ENUM('open','reviewing','resolved','dismissed') DEFAULT 'open',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_kind_target` (`kind`, `target_id`),
        KEY `idx_reporter` (`reporter_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ($kind === 'block_user') {
        $db->exec("CREATE TABLE IF NOT EXISTS `user_blocks` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `user_id` INT(11) NOT NULL,
            `blocked_user_id` INT(11) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_block` (`user_id`, `blocked_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if ($targetId === $uid) api_error('invalid_input', 'لا يمكنك حظر نفسك');
        $db->prepare("INSERT IGNORE INTO user_blocks (user_id, blocked_user_id) VALUES (?, ?)")->execute([$uid, $targetId]);
        api_json(['ok' => true, 'blocked' => true]);
    }

    $db->prepare("INSERT INTO content_reports (reporter_id, kind, target_id, reason) VALUES (?, ?, ?, ?)")
       ->execute([$uid, $kind, $targetId, $reason]);
    api_json(['ok' => true, 'submitted' => true]);
} catch (Throwable $e) {
    error_log('v1/report: ' . $e->getMessage());
    api_error('server_error', '', 500);
}
