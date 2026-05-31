<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';
require_once __DIR__ . '/../../../includes/cache.php';

api_method('GET');
api_rate_limit('content:trending', 240, 60);

// Trending rebuilds rarely (cron updates `trends`, view_count drifts
// slowly). 120s cache eliminates the duplicate fetch_articles cost.
$payload = cache_remember('api:trending:v1', 120, function () {
    $db = getDB();

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

    $since = date('Y-m-d H:i:s', time() - 86400);
    $articles = fetch_articles(['since' => $since, 'order' => 'view_count DESC'], 20, 0);

    if (count($articles) < 5) {
        $articles = fetch_articles(['order' => 'view_count DESC'], 20, 0);
    }

    return [
        'tags'     => $tags,
        'articles' => $articles,
    ];
});

api_ok($payload);
