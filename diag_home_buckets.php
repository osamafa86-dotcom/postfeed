<?php
/**
 * Diagnostic: dump the home payload's bucket structure as plain text.
 *
 * Usage:
 *   https://feedsnews.net/diag_home_buckets.php
 *
 * Forces a fresh cache rebuild, then prints what every bucket contains.
 * Useful for confirming the new virtual buckets (ct-reports, ct-articles,
 * agg-variety) and the legacy-reports skip actually take effect after
 * a deploy, without needing to grep through JSON.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/cache.php';

header('Content-Type: text/plain; charset=utf-8');

// Bust both cache keys (old v1 and new v2) so we definitely rebuild.
cache_forget('api:home:v1');
cache_forget('api:home:v2');
echo "✓ cleared api:home:v1 and api:home:v2 cache files\n\n";

// Build the home payload directly (mirrors api/v1/content/home.php logic).
require_once __DIR__ . '/api/v1/_articles_query.php';
$db = getDB();

$catRows = $db->query("SELECT id, name, slug, icon, css_class, sort_order
                         FROM categories
                        WHERE is_active=1 AND slug <> 'reports'
                        ORDER BY sort_order, id LIMIT 12")->fetchAll();

echo "═══ REAL category buckets (legacy 'reports' excluded) ═══\n";
foreach ($catRows as $c) {
    $items = fetch_articles(['category_id' => (int)$c['id']], 6, 0);
    $cnt = count($items);
    $status = $cnt > 0 ? '✓' : '✗ (empty, will be skipped)';
    printf("  %-15s %-15s %s\n", $c['slug'], $c['name'], "$cnt articles $status");
}

echo "\n═══ VIRTUAL buckets (content_type & aggregates) ═══\n";
$virtual = [
    [9001, 'تقارير',  'ct-reports',  '📑', ['content_type' => 'report']],
    [9002, 'مقالات',  'ct-articles', '✍️', ['content_type' => 'article']],
    [9004, 'منوعات',  'agg-variety', '🎯', ['category_slugs' => ['sports', 'arts', 'tech', 'media']]],
];
foreach ($virtual as $v) {
    [$vid, $vname, $vslug, $vicon, $vfilter] = $v;
    try {
        $items = fetch_articles($vfilter, 6, 0);
        $cnt = count($items);
        $status = $cnt > 0 ? '✓' : '✗ (empty, will NOT appear in app)';
        $first = $cnt > 0 ? ' | first: ' . mb_substr($items[0]['title'] ?? '', 0, 60) : '';
        printf("  %-15s %-15s %s%s\n", $vslug, $vname, "$cnt articles $status", $first);
    } catch (Throwable $e) {
        printf("  %-15s %-15s ✗ ERROR: %s\n", $vslug, $vname, $e->getMessage());
    }
}

echo "\n═══ Content-type distribution in articles table ═══\n";
try {
    $dist = $db->query("SELECT content_type, COUNT(*) AS cnt
                          FROM articles
                         WHERE status='published'
                         GROUP BY content_type
                         ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dist as $row) {
        $type = $row['content_type'] ?? 'NULL';
        printf("  %-12s %d articles\n", $type, $row['cnt']);
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n═══ Live API check (fresh fetch) ═══\n";
$ch = curl_init('https://feedsnews.net/api/v1/content/home?nocache=1');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body) {
    $json = json_decode($body, true);
    $apiBuckets = $json['data']['buckets'] ?? [];
    echo "HTTP {$code} | " . count($apiBuckets) . " buckets returned by live API:\n";
    foreach ($apiBuckets as $b) {
        $c = $b['category'] ?? [];
        $artCnt = count($b['articles'] ?? []);
        printf("  %-15s %-15s %d articles\n",
            $c['slug'] ?? '?', $c['name'] ?? '?', $artCnt);
    }
} else {
    echo "  HTTP {$code} — no body returned\n";
}
