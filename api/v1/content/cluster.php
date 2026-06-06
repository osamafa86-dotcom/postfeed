<?php
/**
 * GET /api/v1/content/cluster?key=<sha1>
 *
 * Single-cluster detail payload for the mobile "قارن التغطية" screen.
 * Mirrors what /cluster.php renders on the website so the app shows
 * the exact same view: canonical headline, source-velocity tag,
 * Smart Brevity card, News Mirror card, and the chronologically
 * ordered coverage list.
 *
 * The brevity + mirror helpers are AI-backed and lazy-generate on
 * first call; subsequent calls (within their 30-minute cache) are
 * free. The 60s wrapper cache here keeps repeated app launches off
 * the AI provider.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';
require_once __DIR__ . '/../../../includes/cache.php';
require_once __DIR__ . '/../../../includes/article_cluster.php';
require_once __DIR__ . '/../../../includes/smart_brevity.php';
require_once __DIR__ . '/../../../includes/news_mirror.php';
require_once __DIR__ . '/../../../includes/trending.php';
require_once __DIR__ . '/../../../includes/story_timeline.php';

api_method('GET');
api_rate_limit('content:cluster', 240, 60);

$rawKey = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
$key    = preg_match('/^[a-f0-9]{40}$/', $rawKey) ? $rawKey : '';

if ($key === '') {
    api_ok(null, ['error' => 'invalid_key']);
}

$payload = cache_remember('api:cluster:v1:' . $key, 60, function () use ($key) {
    $db = getDB();

    // Pull every published article in the cluster, oldest-first so the
    // mobile timeline strip can walk left→right just like the website.
    $stmt = $db->prepare(articles_select_sql() . "
        WHERE a.cluster_key = ? AND a.status = 'published'
        ORDER BY a.published_at ASC");
    $stmt->execute([$key]);
    $rows = $stmt->fetchAll();
    if (!$rows) return null;

    // Canonical headline = the longest title in the cluster, which is
    // a cheap proxy for "the most descriptive phrasing". Earliest /
    // latest timestamps + distinct source count power the hero card.
    $canonical    = '';
    $earliestAt   = null;
    $latestAt     = null;
    $sourceNames  = [];
    $heroImage    = null;
    foreach ($rows as $a) {
        $t = trim((string)($a['title'] ?? ''));
        if (mb_strlen($t) > mb_strlen($canonical)) $canonical = $t;
        $pa = $a['published_at'] ?? null;
        if ($pa) {
            if (!$earliestAt || $pa < $earliestAt) $earliestAt = $pa;
            if (!$latestAt   || $pa > $latestAt)   $latestAt   = $pa;
        }
        if (!empty($a['source_name'])) $sourceNames[$a['source_name']] = true;
        if (!$heroImage && !empty($a['image_url'])) $heroImage = $a['image_url'];
    }

    $articles = array_map('api_format_article', $rows);
    // Number each card so the app can render the "1, 2, 3…" badge
    // the website shows over each coverage image.
    foreach ($articles as $i => &$a) $a['order'] = $i + 1;
    unset($a);

    // Compact timeline strip: just source name + published_at per row,
    // in chronological order. Cheap to render on the client.
    $timeline = array_map(fn($a) => [
        'source'       => $a['source_name'] ?? null,
        'published_at' => $a['published_at'] ?? null,
    ], $rows);

    $velocity = cluster_source_velocity($key);
    $brevity  = (count($rows) >= 2) ? smart_brevity_for_cluster($key, $rows) : null;
    $mirror   = (count($sourceNames) >= 2) ? news_mirror_for_cluster($key, $rows) : null;

    // Smart story timeline marker — the website shows a CTA to
    // /timeline/<key> when the cluster has ≥3 stories AND a stored
    // timeline summary exists. The app does the same routing.
    $hasTimeline = false;
    try {
        $hasTimeline = story_timeline_get($key) !== null;
    } catch (Throwable $e) { /* table not present yet */ }

    return [
        'key'              => $key,
        'canonical_title'  => $canonical,
        'article_count'    => count($rows),
        'source_count'     => count($sourceNames),
        'earliest_at'      => $earliestAt,
        'latest_at'        => $latestAt,
        'hero_image'       => api_image_url($heroImage),
        'velocity'         => [
            'sources_15m' => (int)($velocity['sources_15m'] ?? 0),
            'sources_1h'  => (int)($velocity['sources_1h']  ?? 0),
            'sources_6h'  => (int)($velocity['sources_6h']  ?? 0),
            'score'       => (int)($velocity['score']       ?? 0),
            'label'       => (string)($velocity['label']    ?? ''),
        ],
        'timeline'         => $timeline,
        'has_story_timeline' => $hasTimeline && count($rows) >= 3,
        'brevity'          => $brevity ? [
            'why_matters'    => (string)($brevity['why_matters']    ?? ''),
            'big_picture'    => (string)($brevity['big_picture']    ?? ''),
            'by_the_numbers' => array_values(array_filter((array)($brevity['by_the_numbers'] ?? []), fn($n) => is_array($n) && !empty($n['value']))),
            'what_they_say'  => array_values(array_filter((array)($brevity['what_they_say']  ?? []), fn($q) => is_array($q) && !empty($q['quote']))),
            'zoom_in'        => (string)($brevity['zoom_in']        ?? ''),
        ] : null,
        'mirror'           => $mirror ? [
            'neutral_summary' => (string)($mirror['neutral_summary'] ?? ''),
            'divergent_terms' => (array)($mirror['divergent_terms']  ?? []),
            'framings'        => (array)($mirror['framings']         ?? []),
        ] : null,
        'articles'         => $articles,
    ];
});

if ($payload === null) {
    api_ok(null, ['error' => 'not_found']);
}

api_ok($payload);
