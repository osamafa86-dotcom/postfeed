<?php
/**
 * POST /api/v1/reactions — like/dislike an article
 * Body: { "article_id": 1, "reaction": "like"|"dislike"|null }
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('POST');
$uid = api_require_user();
api_rate_limit('reactions', 120, 60);

$body = api_body();
$articleId = (int)($body['article_id'] ?? 0);
$reaction = $body['reaction'] ?? null;

if ($articleId <= 0) api_error('invalid_input', 'article_id');
if ($reaction !== null && !in_array($reaction, ['like','dislike'], true)) {
    api_error('invalid_input', 'reaction');
}

try {
    $db = getDB();
    if ($reaction === null) {
        $db->prepare("DELETE FROM article_reactions WHERE user_id = ? AND article_id = ?")->execute([$uid, $articleId]);
    } else {
        $db->prepare("INSERT INTO article_reactions (user_id, article_id, reaction) VALUES (?, ?, ?)
                      ON DUPLICATE KEY UPDATE reaction = VALUES(reaction)")->execute([$uid, $articleId, $reaction]);
    }

    $likes = (int)$db->query("SELECT COUNT(*) FROM article_reactions WHERE article_id = " . (int)$articleId . " AND reaction = 'like'")->fetchColumn();
    $dislikes = (int)$db->query("SELECT COUNT(*) FROM article_reactions WHERE article_id = " . (int)$articleId . " AND reaction = 'dislike'")->fetchColumn();

    api_json(['ok' => true, 'reaction' => $reaction, 'likes' => $likes, 'dislikes' => $dislikes]);
} catch (Throwable $e) {
    error_log('v1/reactions: ' . $e->getMessage());
    api_error('server_error', '', 500);
}
