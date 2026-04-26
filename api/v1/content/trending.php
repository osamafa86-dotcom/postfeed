<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:trending', 240, 60);

$db = getDB();

// Trending tags from `trends` table.
$tags = [];
try {
    $rows = $db->query("SELECT id, title, tweet_count, search_count, sort_order FROM trends ORDER BY sort_order, id LIMIT 30")->fetchAll();
    foreach ($rows as $r) {
        $tags[] = [
            'id' => (int)$r['id'],
            'title' => $r['title'],
            'tweet_count' => (int)$r['tweet_count'],
            'search_count' => (int)$r['search_count'],
        ];
    }
} catch (Throwable $e) {}

// Most-read articles from last 24h by view_count.
$since = date('Y-m-d H:i:s', time() - 86400);
$articles = fetch_articles(['since' => $since, 'order' => 'view_count DESC'], 20, 0);

// Most-read all-time fallback.
if (count($articles) < 5) {
    $articles = fetch_articles(['order' => 'view_count DESC'], 20, 0);
}

api_ok([
    'tags'     => $tags,
    'articles' => $articles,
]);
