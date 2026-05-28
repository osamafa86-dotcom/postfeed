<?php
/**
 * GET /api/v1/content/evolving-story-quotes?slug=...
 * Returns notable quotes extracted from the story's articles.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:evolving:quotes', 120, 60);

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') api_err('invalid_input', 'يلزم slug', 422);

$db = getDB();
$st = $db->prepare("SELECT id, name, accent_color FROM evolving_stories WHERE slug=? AND is_active=1 LIMIT 1");
$st->execute([$slug]);
$story = $st->fetch();
if (!$story) api_err('not_found', 'القصة غير موجودة', 404);

[$page, $limit, $offset] = api_pagination(20, 100);

$rows = [];
try {
    // Real columns on evolving_story_quotes: quote_text, speaker,
    // speaker_role, context, article_id, extracted_at. The old query
    // named `quote` and `created_at`, which don't exist and made the
    // SELECT throw → caught silently → empty quotes forever.
    $sql = "SELECT q.id, q.quote_text, q.speaker, q.speaker_role, q.context,
                   q.article_id, q.extracted_at,
                   a.title AS article_title, a.slug AS article_slug, a.image_url
            FROM evolving_story_quotes q
            LEFT JOIN articles a ON a.id = q.article_id
            WHERE q.story_id=?
            ORDER BY q.extracted_at DESC LIMIT $limit OFFSET $offset";
    $ps = $db->prepare($sql);
    $ps->execute([(int)$story['id']]);
    $rows = $ps->fetchAll();
} catch (Throwable $e) {
    error_log('evolving-story-quotes: ' . $e->getMessage());
}

$quotes = array_map(function ($r) {
    // Use `null` (not '') for empty speaker/context so the app's
    // `if (quote.context != null)` guards short-circuit and we don't
    // render blank attribution rows under quotes that have no speaker.
    $speaker = trim((string)($r['speaker'] ?? ''));
    $speakerRole = trim((string)($r['speaker_role'] ?? ''));
    $context = trim((string)($r['context'] ?? ''));
    return [
        'id'         => (int)$r['id'],
        'quote'      => $r['quote_text'] ?? '',
        'speaker'    => $speaker !== '' ? $speaker : null,
        'speaker_role' => $speakerRole !== '' ? $speakerRole : null,
        'context'    => $context !== '' ? $context : null,
        'created_at' => $r['extracted_at'] ?? null,
        'article'    => $r['article_id'] ? [
            'id'        => (int)$r['article_id'],
            'title'     => $r['article_title'],
            'slug'      => $r['article_slug'],
            'image_url' => api_image_url($r['image_url']),
        ] : null,
    ];
}, $rows);

api_ok([
    'story' => [
        'id' => (int)$story['id'],
        'name' => $story['name'],
        'slug' => $slug,
        'accent_color' => $story['accent_color'],
    ],
    'quotes' => $quotes,
]);
