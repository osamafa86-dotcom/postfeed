<?php
require __DIR__ . '/_json.php';

require_post_json();
$userId = require_user_json();
require_csrf_json();
rate_limit_json('comment', 15, 300);

$action = $_POST['action'] ?? 'add';

try {
    if ($action === 'add') {
        $articleId = (int)($_POST['article_id'] ?? 0);
        $body = $_POST['body'] ?? '';
        if (!$articleId) json_out(['ok' => false, 'error' => 'bad_request'], 400);
        $commentId = add_article_comment($articleId, $userId, $body);
        if (!$commentId) json_out(['ok' => false, 'error' => 'invalid_comment'], 400);
        // Bump the article.comments counter for display
        try { getDB()->prepare("UPDATE articles SET comments = comments + 1 WHERE id = ?")->execute([$articleId]); } catch (Throwable $e) {}
        $db = getDB();
        $stmt = $db->prepare("SELECT c.*, u.name as user_name, u.avatar_letter FROM article_comments c INNER JOIN users u ON u.id = c.user_id WHERE c.id = ?");
        $stmt->execute([$commentId]);
        json_out(['ok' => true, 'comment' => $stmt->fetch()]);
    } elseif ($action === 'like') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        if (!$commentId) json_out(['ok' => false, 'error' => 'bad_request'], 400);
        $res = toggle_comment_like($userId, $commentId);
        json_out(['ok' => true] + $res);
    } else {
        json_out(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'server'], 500);
}
