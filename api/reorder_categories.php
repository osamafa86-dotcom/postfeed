<?php
require __DIR__ . '/_json.php';

require_post_json();
$userId = require_user_json();
require_csrf_json();
rate_limit_json('reorder', 30, 60);

$ordered = $_POST['order'] ?? '';
if (!is_string($ordered)) json_out(['ok' => false, 'error' => 'bad_request'], 400);
$ids = array_values(array_filter(array_map('intval', explode(',', $ordered))));
if (!$ids) json_out(['ok' => false, 'error' => 'empty'], 400);

try {
    user_reorder_categories($userId, $ids);
    json_out(['ok' => true]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'server'], 500);
}
