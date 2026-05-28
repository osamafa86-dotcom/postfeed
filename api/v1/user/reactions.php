<?php
/**
 * POST /api/v1/user/reactions
 * Body: { article_id, reaction: 'like'|'love'|'sad'|'angry'|'wow'|'fire'|'share' }
 *
 * The production schema declared `reaction ENUM('like','dislike')`, so
 * every non-like/dislike INSERT silently 500'd ("Data truncated for
 * column 'reaction'"). We lazy-ALTER the column to VARCHAR(20) on first
 * call, and special-case 'share' into the dedicated article_share_counts
 * table instead of stuffing it as a reaction.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
$user = api_require_user();
$db = getDB();

$body = api_body();
$aid = (int)($body['article_id'] ?? 0);
$reaction = (string)($body['reaction'] ?? '');

if (!$aid) api_err('invalid_input', 'يلزم article_id', 422);

// ── Share tracking — dedicated counter, not a reaction ──
if ($reaction === 'share') {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS article_share_counts (
            article_id INT NOT NULL PRIMARY KEY,
            count INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->prepare("INSERT INTO article_share_counts (article_id, count) VALUES (?, 1)
                      ON DUPLICATE KEY UPDATE count = count + 1")
           ->execute([$aid]);
    } catch (Throwable $e) {
        error_log('share counter: ' . $e->getMessage());
    }
    api_ok(['shared' => true]);
}

$valid = ['like', 'love', 'sad', 'angry', 'wow', 'fire'];
if (!in_array($reaction, $valid, true)) api_err('invalid_input', 'تفاعل غير معروف', 422);

// Lazy schema: create the table if it doesn't exist, then widen the
// reaction column if it's still the legacy ENUM('like','dislike').
try {
    $db->exec("CREATE TABLE IF NOT EXISTS article_reactions (
        user_id INT NOT NULL,
        article_id INT NOT NULL,
        reaction VARCHAR(20) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, article_id),
        KEY idx_article (article_id, reaction)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Widen legacy ENUM to VARCHAR so love/sad/angry/wow/fire insert.
    $col = $db->query("SHOW COLUMNS FROM article_reactions LIKE 'reaction'")->fetch();
    if ($col && stripos((string)$col['Type'], 'enum') !== false) {
        $db->exec("ALTER TABLE article_reactions MODIFY reaction VARCHAR(20) NOT NULL");
    }
} catch (Throwable $e) {
    error_log('article_reactions schema migrate: ' . $e->getMessage());
}

$db->prepare("INSERT INTO article_reactions (user_id, article_id, reaction) VALUES (?,?,?)
              ON DUPLICATE KEY UPDATE reaction=VALUES(reaction)")
   ->execute([(int)$user['id'], $aid, $reaction]);

// Aggregate counts.
$st = $db->prepare("SELECT reaction, COUNT(*) AS c FROM article_reactions WHERE article_id=? GROUP BY reaction");
$st->execute([$aid]);
$counts = [];
foreach ($st->fetchAll() as $row) $counts[$row['reaction']] = (int)$row['c'];

api_ok(['reaction' => $reaction, 'counts' => $counts]);
