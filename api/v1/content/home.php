<?php
/**
 * GET /api/v1/content/home
 * The "front page" payload: hero, breaking strip, category buckets,
 * trending tags, ticker. Designed so the mobile app can render the
 * entire home screen from one round-trip.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:home', 240, 60);

$db = getDB();

// Hero (newest article flagged as hero, or fallback to newest featured).
$heroRows = fetch_articles(['hero' => 1], 1, 0);
if (!$heroRows) $heroRows = fetch_articles(['featured' => 1], 1, 0);
if (!$heroRows) $heroRows = fetch_articles([], 1, 0);
$hero = $heroRows[0] ?? null;

// Breaking strip — top 10 breaking from last 24h, ordered by recency.
$breaking = fetch_articles([
    'breaking' => 1,
    'since' => date('Y-m-d H:i:s', time() - 86400),
], 10, 0);

// Latest feed (excluding hero).
$latest = fetch_articles([], 20, 0);
if ($hero) {
    $latest = array_values(array_filter($latest, fn($a) => $a['id'] !== $hero['id']));
}

// Per-category buckets (top 6 articles each).
$catRows = $db->query("SELECT id, name, slug, icon, css_class, sort_order FROM categories WHERE is_active=1 ORDER BY sort_order, id LIMIT 12")->fetchAll();
$buckets = [];
foreach ($catRows as $c) {
    $items = fetch_articles(['category_id' => (int)$c['id']], 6, 0);
    if (!$items) continue;
    $buckets[] = [
        'category' => [
            'id' => (int)$c['id'],
            'name' => $c['name'],
            'slug' => $c['slug'],
            'icon' => $c['icon'],
            'color' => $c['css_class'],
        ],
        'articles' => $items,
    ];
}

// Trending tags.
$trends = [];
try {
    $trends = $db->query("SELECT id, title, tweet_count, search_count FROM trends ORDER BY sort_order, id LIMIT 10")->fetchAll();
} catch (Throwable $e) {}

// Ticker items.
$ticker = [];
try {
    $ticker = $db->query("SELECT id, text, link FROM ticker_items WHERE is_active=1 ORDER BY sort_order, id LIMIT 20")->fetchAll();
} catch (Throwable $e) {}

// Top sources for the chip rail.
$sources = $db->query("SELECT id, name, slug, logo_letter, logo_color, logo_bg, url FROM sources WHERE is_active=1 ORDER BY articles_today DESC, id ASC LIMIT 12")->fetchAll();

api_ok([
    'hero'      => $hero,
    'breaking'  => $breaking,
    'latest'    => $latest,
    'buckets'   => $buckets,
    'trends'    => array_map(function ($t) {
        return ['id' => (int)$t['id'], 'title' => $t['title'], 'tweet_count' => (int)$t['tweet_count'], 'search_count' => (int)$t['search_count']];
    }, $trends),
    'ticker'    => array_map(function ($t) {
        return ['id' => (int)$t['id'], 'text' => $t['text'], 'link' => $t['link'] ?? null];
    }, $ticker),
    'sources'   => array_map(function ($s) {
        return [
            'id' => (int)$s['id'],
            'name' => $s['name'],
            'slug' => $s['slug'],
            'logo_letter' => $s['logo_letter'],
            'logo_color' => $s['logo_color'],
            'logo_bg' => $s['logo_bg'],
            'url' => $s['url'],
        ];
    }, $sources),
    'generated_at' => gmdate('c'),
]);
