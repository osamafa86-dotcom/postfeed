<?php
/**
 * POST /api/v1/user/reactions
 * Body: { article_id, reaction: 'like'|'love'|'sad'|'angry'|'wow' }
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
$user = api_require_user();
$db = getDB();

$body = api_body();
$aid = (int)($body['article_id'] ?? 0);
$reaction = (string)($body['reaction'] ?? '');

if (!$aid) api_err('invalid_input', 'يلزم article_id', 422);
$valid = ['like', 'love', 'sad', 'angry', 'wow', 'fire'];
if (!in_array($reaction, $valid, true)) api_err('invalid_input', 'تفاعل غير معروف', 422);

try {
    $db->exec("CREATE TABLE IF NOT EXISTS article_reactions (
        user_id INT NOT NULL,
        article_id INT NOT NULL,
        reaction VARCHAR(20) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, article_id),
        KEY idx_article (article_id, reaction)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

$db->prepare("INSERT INTO article_reactions (user_id, article_id, reaction) VALUES (?,?,?)
              ON DUPLICATE KEY UPDATE reaction=VALUES(reaction)")
   ->execute([(int)$user['id'], $aid, $reaction]);

// Aggregate counts.
$st = $db->prepare("SELECT reaction, COUNT(*) AS c FROM article_reactions WHERE article_id=? GROUP BY reaction");
$st->execute([$aid]);
$counts = [];
foreach ($st->fetchAll() as $row) $counts[$row['reaction']] = (int)$row['c'];

api_ok(['reaction' => $reaction, 'counts' => $counts]);
