<?php
/**
 * GET /api/v1/evolving-stories.php — admin-curated persistent topics
 * GET /api/v1/evolving-stories.php?slug=gaza — single story + timeline
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET');
api_rate_limit('evolving', 120, 60);

$slug = trim((string)($_GET['slug'] ?? ''));

try {
    $db = getDB();

    if ($slug !== '') {
        $stmt = $db->prepare("SELECT id, slug, name, description, icon, cover_image, accent_color, article_count
                              FROM evolving_stories WHERE slug = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$slug]);
        $story = $stmt->fetch();
        if (!$story) api_error('not_found', '', 404);

        $arts = $db->prepare("SELECT a.id, a.title, a.slug, a.excerpt, a.image_url, a.published_at, a.view_count, a.comments, a.is_breaking, a.is_featured,
                                     c.id AS category_id, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, c.css_class AS category_class,
                                     s.id AS source_id, s.name AS source_name, s.logo_letter, s.logo_color, s.logo_bg, s.url AS source_site_url
                              FROM evolving_story_articles esa
                              JOIN articles a ON a.id = esa.article_id
                              LEFT JOIN categories c ON a.category_id = c.id
                              LEFT JOIN sources s ON a.source_id = s.id
                              WHERE esa.story_id = ? AND a.status = 'published'
                              ORDER BY esa.matched_at DESC LIMIT 30");
        $arts->execute([(int)$story['id']]);
        $articles = array_map(fn($r) => api_article_shape($r, false), $arts->fetchAll());

        $timeline = null;
        try {
            $tl = $db->prepare("SELECT headline, intro, generated_at FROM story_timelines WHERE story_id = ? ORDER BY generated_at DESC LIMIT 1");
            $tl->execute([(int)$story['id']]);
            $timeline = $tl->fetch() ?: null;
        } catch (Throwable $e) {}

        api_json([
            'ok' => true,
            'story' => [
                'id' => (int)$story['id'],
                'slug' => $story['slug'],
                'name' => $story['name'],
                'description' => $story['description'],
                'icon' => $story['icon'],
                'cover_image' => $story['cover_image'],
                'accent_color' => $story['accent_color'],
                'article_count' => (int)$story['article_count'],
            ],
            'articles' => $articles,
            'timeline' => $timeline,
        ]);
    }

    $items = [];
    try {
        $rows = $db->query("SELECT id, slug, name, description, icon, cover_image, accent_color, article_count
                            FROM evolving_stories WHERE is_active = 1
                            ORDER BY article_count DESC, id ASC LIMIT 20")->fetchAll();
        $items = array_map(fn($r) => [
            'id' => (int)$r['id'],
            'slug' => $r['slug'],
            'name' => $r['name'],
            'description' => $r['description'],
            'icon' => $r['icon'],
            'cover_image' => $r['cover_image'],
            'accent_color' => $r['accent_color'],
            'article_count' => (int)$r['article_count'],
        ], $rows);
    } catch (Throwable $e) {}
    api_json(['ok' => true, 'count' => count($items), 'items' => $items]);
} catch (Throwable $e) {
    error_log('v1/evolving: ' . $e->getMessage());
    api_error('server_error', '', 500);
}
