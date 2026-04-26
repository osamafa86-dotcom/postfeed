<?php
/**
 * GET /api/v1/media/youtube?limit=&since_id=
 * Returns the aggregated YouTube videos stream.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('media:youtube', 240, 60);

$limit = max(1, min((int)($_GET['limit'] ?? 30), 100));
$sinceId = max(0, (int)($_GET['since_id'] ?? 0));

$db = getDB();
$videos = [];
try {
    if ($sinceId > 0) {
        $stmt = $db->prepare("SELECT v.id, v.source_id, v.video_url, v.video_id, v.title, v.description,
                                     v.thumbnail_url, v.duration_seconds, v.posted_at,
                                     s.display_name, s.channel_id, s.avatar_url
                               FROM youtube_videos v
                               JOIN youtube_sources s ON v.source_id = s.id
                               WHERE v.is_active=1 AND s.is_active=1 AND v.id > ?
                               ORDER BY v.id DESC LIMIT ?");
        $stmt->bindValue(1, $sinceId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    } else {
        $stmt = $db->prepare("SELECT v.id, v.source_id, v.video_url, v.video_id, v.title, v.description,
                                     v.thumbnail_url, v.duration_seconds, v.posted_at,
                                     s.display_name, s.channel_id, s.avatar_url
                               FROM youtube_videos v
                               JOIN youtube_sources s ON v.source_id = s.id
                               WHERE v.is_active=1 AND s.is_active=1
                               ORDER BY v.posted_at DESC, v.id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $videos[] = [
            'id' => (int)$r['id'],
            'video_id' => $r['video_id'],
            'video_url' => $r['video_url'],
            'title' => $r['title'],
            'description' => $r['description'],
            'thumbnail_url' => api_image_url($r['thumbnail_url']),
            'duration_seconds' => (int)$r['duration_seconds'],
            'posted_at' => $r['posted_at'],
            'source' => [
                'id' => (int)$r['source_id'],
                'display_name' => $r['display_name'],
                'channel_id' => $r['channel_id'],
                'avatar_url' => api_image_url($r['avatar_url']),
            ],
        ];
    }
} catch (Throwable $e) {
    error_log('youtube api: ' . $e->getMessage());
}

api_ok($videos, [
    'count' => count($videos),
    'latest_id' => $videos[0]['id'] ?? $sinceId,
]);
