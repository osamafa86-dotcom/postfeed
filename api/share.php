<?php
require __DIR__ . '/_json.php';

require_post_json();
require_csrf_json();
rate_limit_json('share', 240, 60);

$articleId = (int)($_POST['article_id'] ?? 0);
if (!$articleId) json_out(['ok' => false, 'error' => 'bad_request'], 400);

$count = bump_article_share_count($articleId);
json_out(['ok' => true, 'count' => $count]);
