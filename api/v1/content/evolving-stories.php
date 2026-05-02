<?php
/**
 * GET /api/v1/content/evolving-stories
 * List all active evolving stories (admin-defined long-running topics).
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:evolving', 240, 60);

$db = getDB();
$rows = $db->query("SELECT id, name, slug, description, icon, cover_image, accent_color,
                           article_count, last_matched_at, sort_order
                    FROM evolving_stories WHERE is_active=1
                    ORDER BY sort_order ASC, id DESC LIMIT 50")->fetchAll();

// Fetch up to 3 latest article titles per story for preview cards.
$storyIds = array_column($rows, 'id');
$latestByStory = [];
if (!empty($storyIds)) {
    $placeholders = implode(',', array_fill(0, count($storyIds), '?'));
    $latestRows = $db->prepare("
        SELECT esa.story_id, a.id AS article_id, a.title, a.published_at
        FROM evolving_story_articles esa
        JOIN articles a ON a.id = esa.article_id
        WHERE esa.story_id IN ($placeholders)
        ORDER BY a.published_at DESC
    ");
    $latestRows->execute($storyIds);
    foreach ($latestRows->fetchAll() as $lr) {
        $sid = (int)$lr['story_id'];
        if (!isset($latestByStory[$sid])) $latestByStory[$sid] = [];
        if (count($latestByStory[$sid]) < 3) {
            $latestByStory[$sid][] = [
                'id' => (int)$lr['article_id'],
                'title' => $lr['title'],
                'published_at' => $lr['published_at'],
            ];
        }
    }
}

$out = array_map(function ($r) use ($latestByStory) {
    $sid = (int)$r['id'];
    return [
        'id' => $sid,
        'name' => $r['name'],
        'slug' => $r['slug'],
        'description' => $r['description'],
        'icon' => $r['icon'],
        'cover_image' => api_image_url($r['cover_image']),
        'accent_color' => $r['accent_color'],
        'article_count' => (int)$r['article_count'],
        'last_matched_at' => $r['last_matched_at'],
        'latest' => $latestByStory[$sid] ?? [],
    ];
}, $rows);

api_ok($out);
