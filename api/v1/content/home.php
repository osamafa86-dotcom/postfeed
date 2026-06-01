<?php
/**
 * GET /api/v1/content/home
 * The "front page" payload: hero, breaking strip, category buckets,
 * trending tags, ticker. Designed so the mobile app can render the
 * entire home screen from one round-trip.
 *
 * Cached for 60s. The home payload is the single most-hit endpoint
 * — without caching, every cold app launch fans out into ~15 SQL
 * queries (hero + breaking + latest + 12 category buckets + trends
 * + ticker + sources). 60s is short enough that breaking news still
 * surfaces fast, but eliminates the thundering-herd cost.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';
require_once __DIR__ . '/../../../includes/cache.php';

api_method('GET');
api_rate_limit('content:home', 240, 60);

// Cache-busting hook: ?nocache=1 forces a fresh build and updates the
// stored value. Useful when a recent code change altered the payload
// shape (new buckets, removed legacy categories, etc.) and the 60-second
// TTL hasn't expired yet.
$noCache = !empty($_GET['nocache']);
if ($noCache) {
    require_once __DIR__ . '/../../../includes/cache.php';
    cache_forget('api:home:v4');
}

$payload = cache_remember('api:home:v4', 60, function () {
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

    // Latest feed (deferred — we build it AFTER the section buckets so
    // we can dedupe against everything those buckets already claimed.
    // The over-sample (40) leaves room for that filtering.
    try {
        $latestRaw = fetch_articles(['content_type' => 'news'], 40, 0);
        if (empty($latestRaw)) $latestRaw = fetch_articles([], 40, 0);
    } catch (Throwable $e) {
        $latestRaw = fetch_articles([], 40, 0);
    }
    $latest = [];
    $heroId = $hero ? (int)$hero['id'] : 0;

    // Homepage buckets — six virtual sections, no more raw topical
    // categories. Each is a single deliberate slice of the feed so the
    // user always knows what they're getting:
    //
    //   أخبار فلسطين  → content_type=news, Palestine keywords only
    //   عربي ودولي    → content_type=news, NOT Palestine (no overlap)
    //   تقارير        → content_type=report
    //   مقالات رأي    → content_type=article
    //   منوعات        → sports + arts + tech + media topical aggregate
    //   صحة           → health topical category
    //
    // Slugs are prefixed so the client routes them to the right filter
    // view (category_screen.dart) instead of a missing /category/<slug>.
    // IDs in the 9000+ range never collide with real categories table rows.
    $virtual = [
        [9000, 'أخبار فلسطين', 'palestine-news', '🇵🇸', 'cat-political', ['content_type' => 'news', 'palestine' => 1]],
        [9001, 'عربي ودولي',   'arab-intl',      '🌍', 'cat-political', ['content_type' => 'news', 'not_palestine' => 1]],
        [9002, 'تقارير',       'ct-reports',     '📑', 'cat-reports',   ['content_type' => 'report']],
        [9003, 'مقالات رأي',   'ct-articles',    '✍️', 'cat-arts',      ['content_type' => 'article']],
        [9004, 'منوعات',       'agg-variety',    '🎯', 'cat-arts',      ['category_slugs' => ['sports', 'arts', 'tech', 'media']]],
        [9005, 'صحة',          'cat-health',     '🏥', 'cat-political', ['category' => 'health']],
    ];
    // Buckets are rendered top-down on the home screen, so we pull an
    // over-sample (12 instead of 6) for each one and then dedupe by
    // article id across buckets in declaration order. The first bucket
    // claims an article; later buckets skip it. Result: the same story
    // never shows up in two tabs on the same screen, no matter how the
    // filters overlap (a Palestinian sports news item won't appear in
    // both أخبار فلسطين and منوعات).
    $buckets   = [];
    $usedIds   = [];
    foreach ($virtual as $v) {
        [$vid, $vname, $vslug, $vicon, $vcss, $vfilter] = $v;
        try {
            $items = fetch_articles($vfilter, 12, 0);
        } catch (Throwable $e) {
            // content_type column missing — first deploy before classifier ran.
            $items = [];
        }
        if (!$items) continue;

        $deduped = [];
        foreach ($items as $a) {
            $aid = (int)($a['id'] ?? 0);
            if ($aid > 0 && isset($usedIds[$aid])) continue;
            $usedIds[$aid] = true;
            $deduped[] = $a;
            if (count($deduped) >= 6) break;
        }
        if (!$deduped) continue;

        $buckets[] = [
            'category' => [
                'id' => $vid,
                'name' => $vname,
                'slug' => $vslug,
                'icon' => $vicon,
                'color' => $vcss,
            ],
            'articles' => $deduped,
        ];
    }

    // Build latest now, skipping the hero + every article already
    // claimed by a bucket above. Falls back to the raw list if the
    // dedupe stripped everything (unlikely but defensive).
    foreach ($latestRaw as $a) {
        $aid = (int)($a['id'] ?? 0);
        if ($aid === $heroId) continue;
        if ($aid > 0 && isset($usedIds[$aid])) continue;
        $latest[] = $a;
        if (count($latest) >= 20) break;
    }
    if (empty($latest)) $latest = $latestRaw; // emergency fallback

    // ── Cluster-coverage counts (one query for the whole payload) ──
    // Walk every article surfaced on this home payload, collect their
    // cluster_keys, run a single GROUP BY to learn how many sources are
    // covering each story, and inject the count back. The Flutter app
    // shows a "📰 N مصادر" badge whenever cluster_count >= 2 and routes
    // taps to /cluster/<key>.
    $clusterKeys = [];
    $collectKey = function ($a) use (&$clusterKeys) {
        if (is_array($a) && !empty($a['cluster_key'])) {
            $clusterKeys[(string)$a['cluster_key']] = true;
        }
    };
    if ($hero) $collectKey($hero);
    foreach ($breaking as $a) $collectKey($a);
    foreach ($latest as $a) $collectKey($a);
    foreach ($buckets as $bucket) {
        foreach (($bucket['articles'] ?? []) as $a) $collectKey($a);
    }
    $clusterCounts = [];
    if (!empty($clusterKeys)) {
        try {
            $keys = array_keys($clusterKeys);
            $ph = implode(',', array_fill(0, count($keys), '?'));
            $st = $db->prepare("SELECT cluster_key, COUNT(*) AS cnt
                                  FROM articles
                                 WHERE cluster_key IN ($ph)
                                   AND status='published'
                                 GROUP BY cluster_key");
            $st->execute($keys);
            foreach ($st->fetchAll() as $row) {
                $clusterCounts[(string)$row['cluster_key']] = (int)$row['cnt'];
            }
        } catch (Throwable $e) {
            error_log('home cluster counts: ' . $e->getMessage());
        }
    }
    $attachCount = function (&$a) use ($clusterCounts) {
        if (!is_array($a)) return;
        $ck = $a['cluster_key'] ?? null;
        $a['cluster_count'] = ($ck && isset($clusterCounts[$ck])) ? $clusterCounts[$ck] : 1;
    };
    if ($hero) $attachCount($hero);
    foreach ($breaking as &$a) $attachCount($a); unset($a);
    foreach ($latest   as &$a) $attachCount($a); unset($a);
    foreach ($buckets  as &$bucket) {
        if (!empty($bucket['articles'])) {
            foreach ($bucket['articles'] as &$a) $attachCount($a);
            unset($a);
        }
    }
    unset($bucket);

    // Trending tags.
    $trends = [];
    try {
        $trends = $db->query("SELECT id, title, tweet_count, search_count FROM trends ORDER BY sort_order, id LIMIT 10")->fetchAll();
    } catch (Throwable $e) {}
    // Fallback: essential Palestine topics if trends table is empty
    if (empty($trends)) {
        $fallback = ['فلسطين', 'غزة', 'الضفة', 'الأسرى', 'القدس', 'الاستيطان'];
        foreach ($fallback as $i => $t) {
            $trends[] = ['id' => $i + 1, 'title' => $t, 'tweet_count' => 0, 'search_count' => 0];
        }
    }

    // Ticker items.
    $ticker = [];
    try {
        // `link` column doesn't exist on ticker_items in production — leaving
        // it in the SELECT made the whole query throw and the ticker came
        // back empty on home.
        $ticker = $db->query("SELECT id, text FROM ticker_items WHERE is_active=1 ORDER BY sort_order, id LIMIT 20")->fetchAll();
    } catch (Throwable $e) {
        error_log('home ticker: ' . $e->getMessage());
    }

    // Top sources for the chip rail.
    $sources = $db->query("SELECT id, name, slug, logo_letter, logo_color, logo_bg, url FROM sources WHERE is_active=1 ORDER BY articles_today DESC, id ASC LIMIT 12")->fetchAll();

    return [
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
    ];
});

// generated_at is always fresh so the client can detect stale caches.
$payload['generated_at'] = gmdate('c');
api_ok($payload);
