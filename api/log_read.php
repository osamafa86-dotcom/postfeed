<?php
require __DIR__ . '/_json.php';

require_post_json();
$userId = require_user_json();
require_csrf_json();
rate_limit_json('log_read', 120, 60);

$articleId = (int)($_POST['article_id'] ?? 0);
if (!$articleId) json_out(['ok' => false, 'error' => 'bad_request'], 400);

try {
    user_log_read($userId, $articleId);
    json_out(['ok' => true]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'server'], 500);
}
