<?php
/**
 * GET /api/v1/media/gallery?date=YYYY-MM-DD
 * Daily curated photo gallery.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('media:gallery', 240, 60);

$date = trim((string)($_GET['date'] ?? date('Y-m-d')));

$db = getDB();
$gallery = null;
try {
    $st = $db->prepare("SELECT id, gallery_date, headline, intro, photos, created_at
                        FROM daily_gallery WHERE gallery_date=? LIMIT 1");
    $st->execute([$date]);
    $gallery = $st->fetch();
    if (!$gallery) {
        // Latest available fallback.
        $gallery = $db->query("SELECT id, gallery_date, headline, intro, photos, created_at
                                FROM daily_gallery ORDER BY gallery_date DESC LIMIT 1")->fetch();
    }
} catch (Throwable $e) {
    error_log('gallery api: ' . $e->getMessage());
}

if (!$gallery) api_err('not_found', 'لا توجد ألبومات', 404);

$photos = json_decode((string)$gallery['photos'], true);
if (!is_array($photos)) $photos = [];

$photos = array_map(function ($p) {
    if (is_array($p)) {
        if (isset($p['image_url'])) $p['image_url'] = api_image_url($p['image_url']);
        if (isset($p['url']))       $p['url']       = api_image_url($p['url']);
    }
    return $p;
}, $photos);

api_ok([
    'id' => (int)$gallery['id'],
    'date' => $gallery['gallery_date'],
    'headline' => $gallery['headline'],
    'intro' => $gallery['intro'],
    'photos' => $photos,
    'created_at' => $gallery['created_at'],
]);
