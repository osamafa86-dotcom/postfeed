<?php
require __DIR__ . '/_json.php';

require_post_json();
$userId = require_user_json();
require_csrf_json();
rate_limit_json('bookmark', 60, 60);

$articleId = (int)($_POST['article_id'] ?? 0);
if (!$articleId) json_out(['ok' => false, 'error' => 'bad_request'], 400);

try {
    $saved = user_bookmark_toggle($userId, $articleId);
    json_out(['ok' => true, 'saved' => $saved, 'count' => user_bookmark_count($userId)]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'server'], 500);
}
