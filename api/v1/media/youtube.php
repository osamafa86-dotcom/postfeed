<?php
/**
 * GET /api/v1/media/youtube?limit=&since_id=
 * Returns the aggregated YouTube videos stream.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('media:youtube', 240, 60);

$limit    = max(1, min((int)($_GET['limit'] ?? 30), 100));
$sinceId  = max(0, (int)($_GET['since_id'] ?? 0));
$beforeId = max(0, (int)($_GET['before_id'] ?? 0));  // "load older" cursor

$db = getDB();
$videos = [];
// Column list matches the actual youtube_videos schema: post_url (NOT
// video_url), no duration_seconds column. The previous SELECT named
// columns that don't exist, which made PDO throw and the catch below
// swallow it — every request quietly returned []. That's exactly why
// the app showed "no videos" while the cron was inserting rows fine.
try {
    if ($sinceId > 0) {
        $stmt = $db->prepare("SELECT v.id, v.source_id, v.post_url, v.video_id, v.title, v.description,
                                     v.thumbnail_url, v.posted_at,
                                     s.display_name, s.channel_id, s.avatar_url
                               FROM youtube_videos v
                               JOIN youtube_sources s ON v.source_id = s.id
                               WHERE v.is_active=1 AND s.is_active=1 AND v.id > ?
                               ORDER BY v.id DESC LIMIT ?");
        $stmt->bindValue(1, $sinceId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    } elseif ($beforeId > 0) {
        $stmt = $db->prepare("SELECT v.id, v.source_id, v.post_url, v.video_id, v.title, v.description,
                                     v.thumbnail_url, v.posted_at,
                                     s.display_name, s.channel_id, s.avatar_url
                               FROM youtube_videos v
                               JOIN youtube_sources s ON v.source_id = s.id
                               WHERE v.is_active=1 AND s.is_active=1 AND v.id < ?
                               ORDER BY v.posted_at DESC, v.id DESC LIMIT ?");
        $stmt->bindValue(1, $beforeId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    } else {
        $stmt = $db->prepare("SELECT v.id, v.source_id, v.post_url, v.video_id, v.title, v.description,
                                     v.thumbnail_url, v.posted_at,
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
            // Keep JSON key as `video_url` for app compat — the column
            // happens to be named `post_url` in the DB.
            'video_url' => $r['post_url'],
            'title' => $r['title'],
            'description' => $r['description'],
            'thumbnail_url' => api_image_url($r['thumbnail_url']),
            // The schema has no duration column; the app treats 0 as
            // "don't show a duration overlay".
            'duration_seconds' => 0,
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
