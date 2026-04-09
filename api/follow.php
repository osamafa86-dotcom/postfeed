<?php
require __DIR__ . '/_json.php';
require_once __DIR__ . '/../includes/personalize.php';

require_post_json();
$userId = require_user_json();
require_csrf_json();
rate_limit_json('follow', 120, 60);

$kind = $_POST['kind'] ?? '';
$id = (int)($_POST['id'] ?? 0);
if (!$id || !in_array($kind, ['cat', 'src'], true)) json_out(['ok' => false, 'error' => 'bad_request'], 400);

try {
    if ($kind === 'cat') {
        $current = user_followed_category_ids($userId);
        if (in_array($id, $current, true)) {
            user_unfollow_category($userId, $id);
            $now = false;
        } else {
            user_follow_category($userId, $id);
            $now = true;
        }
    } else {
        $current = user_followed_source_ids($userId);
        if (in_array($id, $current, true)) {
            user_unfollow_source($userId, $id);
            $now = false;
        } else {
            user_follow_source($userId, $id);
            $now = true;
        }
    }
    // Any follow/unfollow changes the "for you" feed — drop the cached copy
    // so the homepage reflects the new preferences on the very next visit.
    personalize_invalidate($userId);
    json_out(['ok' => true, 'following' => $now]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'server'], 500);
}
