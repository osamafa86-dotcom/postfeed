<?php
/**
 * GET /api/v1/media/telegram?limit=&since_id=
 * Returns the Telegram aggregated stream.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('media:telegram', 240, 60);

$limit    = max(1, min((int)($_GET['limit'] ?? 30), 100));
$sinceId  = max(0, (int)($_GET['since_id'] ?? 0));
// before_id = cursor for "load older messages" pagination. App passes
// the smallest id it's already shown and asks for the next batch
// behind it. since_id and before_id are mutually exclusive — since_id
// wins when both are sent.
$beforeId = max(0, (int)($_GET['before_id'] ?? 0));

$db = getDB();
$messages = [];
try {
    if ($sinceId > 0) {
        $stmt = $db->prepare("SELECT m.id, m.source_id, m.post_url, m.text, m.image_url, m.posted_at,
                                     s.display_name, s.username, s.avatar_url
                               FROM telegram_messages m
                               JOIN telegram_sources s ON m.source_id = s.id
                               WHERE m.is_active=1 AND s.is_active=1 AND m.id > ?
                               ORDER BY m.id DESC LIMIT ?");
        $stmt->bindValue(1, $sinceId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    } elseif ($beforeId > 0) {
        $stmt = $db->prepare("SELECT m.id, m.source_id, m.post_url, m.text, m.image_url, m.posted_at,
                                     s.display_name, s.username, s.avatar_url
                               FROM telegram_messages m
                               JOIN telegram_sources s ON m.source_id = s.id
                               WHERE m.is_active=1 AND s.is_active=1 AND m.id < ?
                               ORDER BY m.posted_at DESC, m.id DESC LIMIT ?");
        $stmt->bindValue(1, $beforeId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    } else {
        $stmt = $db->prepare("SELECT m.id, m.source_id, m.post_url, m.text, m.image_url, m.posted_at,
                                     s.display_name, s.username, s.avatar_url
                               FROM telegram_messages m
                               JOIN telegram_sources s ON m.source_id = s.id
                               WHERE m.is_active=1 AND s.is_active=1
                               ORDER BY m.posted_at DESC, m.id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
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
    error_log('telegram api: ' . $e->getMessage());
}

// Optional near-duplicate collapsing (?dedup=1). Done before computing
// count so the meta reflects what the client actually receives.
if (!empty($_GET['dedup'])) {
    require_once __DIR__ . '/../../../includes/dedup.php';
    $messages = nf_dedup_messages($messages);
}

api_ok($messages, [
    'count' => count($messages),
    'latest_id' => $messages[0]['id'] ?? $sinceId,
]);
