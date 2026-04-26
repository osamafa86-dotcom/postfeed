<?php
/**
 * GET /api/v1/media/twitter?limit=&since_id=
 * Returns the aggregated Twitter/X stream.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('media:twitter', 240, 60);

$limit = max(1, min((int)($_GET['limit'] ?? 30), 100));
$sinceId = max(0, (int)($_GET['since_id'] ?? 0));

$db = getDB();
$messages = [];
try {
    if ($sinceId > 0) {
        $stmt = $db->prepare("SELECT m.id, m.source_id, m.post_url, m.text, m.image_url, m.posted_at,
                                     s.display_name, s.username, s.avatar_url
                               FROM twitter_messages m
                               JOIN twitter_sources s ON m.source_id = s.id
                               WHERE m.is_active=1 AND s.is_active=1 AND m.id > ?
                               ORDER BY m.id DESC LIMIT ?");
        $stmt->bindValue(1, $sinceId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    } else {
        $stmt = $db->prepare("SELECT m.id, m.source_id, m.post_url, m.text, m.image_url, m.posted_at,
                                     s.display_name, s.username, s.avatar_url
                               FROM twitter_messages m
                               JOIN twitter_sources s ON m.source_id = s.id
                               WHERE m.is_active=1 AND s.is_active=1
                               ORDER BY m.posted_at DESC, m.id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $messages[] = [
            'id' => (int)$r['id'],
            'text' => $r['text'],
            'image_url' => api_image_url($r['image_url']),
            'post_url' => $r['post_url'],
            'posted_at' => $r['posted_at'],
            'source' => [
                'id' => (int)$r['source_id'],
                'display_name' => $r['display_name'],
                'username' => $r['username'],
                'avatar_url' => api_image_url($r['avatar_url']),
            ],
        ];
    }
} catch (Throwable $e) {
    error_log('twitter api: ' . $e->getMessage());
}

api_ok($messages, [
    'count' => count($messages),
    'latest_id' => $messages[0]['id'] ?? $sinceId,
]);
