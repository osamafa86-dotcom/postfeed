<?php
require __DIR__ . '/_json.php';

require_post_json();
$userId = require_user_json();
require_csrf_json();
rate_limit_json('reaction', 120, 60);

$articleId = (int)($_POST['article_id'] ?? 0);
$reaction  = $_POST['reaction'] ?? '';
if (!$articleId || !in_array($reaction, ['like', 'dislike'], true)) {
    json_out(['ok' => false, 'error' => 'bad_request'], 400);
}

$result = toggle_article_reaction($userId, $articleId, $reaction);
json_out(['ok' => true] + $result);
