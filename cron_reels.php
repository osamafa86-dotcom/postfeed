<?php
/**
 * Pulls fresh Instagram Reels from every active row in `reels_sources`
 * and INSERT IGNOREs them into `reels`. Designed for cPanel cron:
 *
 *   curl -s "https://feedsnews.net/cron_reels.php?key=YOUR_KEY"
 *
 * Recommended schedule: every 6–12 hours. Running more often gets
 * the IP rate-limited by Instagram.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/instagram_fetch.php';

if (function_exists('cache_flush')) cache_flush();

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(300);

$db = getDB();
$sources = $db->query("SELECT id, username, display_name
                       FROM reels_sources
                       WHERE is_active = 1
                       ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

if (!$sources) {
    echo "No active reels_sources. Add Instagram usernames in panel/reels.php first.\n";
    exit;
}

$totals = ['fetched' => 0, 'new' => 0, 'failed' => 0];
$start = microtime(true);

foreach ($sources as $src) {
    echo "→ @{$src['username']} ({$src['display_name']})\n";
    $r = ig_fetch_user((string)$src['username']);
    if (!$r['ok']) {
        echo "   ⚠ FAILED: {$r['error']}\n";
        $totals['failed']++;
        // Pause longer after a failure (often a rate limit kicking in).
        sleep(5);
        continue;
    }
    $reels = ig_extract_reels($r['user']);
    $newCount = 0;
    foreach ($reels as $reel) {
        if (empty($reel['shortcode'])) continue;
        $url = 'https://www.instagram.com/p/' . $reel['shortcode'] . '/';
        try {
            $stmt = $db->prepare("INSERT IGNORE INTO reels
                (source_id, instagram_url, shortcode, caption, thumbnail_url,
                 is_active, sort_order, created_at)
                VALUES (?, ?, ?, ?, ?, 1, 0, NOW())");
            $stmt->execute([
                (int)$src['id'],
                $url,
                $reel['shortcode'],
                $reel['caption'],
                $reel['thumbnail_url'],
            ]);
            if ($stmt->rowCount() > 0) $newCount++;
        } catch (Throwable $e) {
            error_log('reel insert: ' . $e->getMessage());
        }
    }
    $totals['fetched'] += count($reels);
    $totals['new']     += $newCount;
    echo "   fetched=" . count($reels) . " new=$newCount\n";
    // Polite pause to stay under Instagram's invisible per-IP limit.
    sleep(2);
}

$dur = round(microtime(true) - $start, 2);
echo "\n=== DONE ===\n";
echo "Sources: " . count($sources) . " (failed: {$totals['failed']})\n";
echo "Fetched: {$totals['fetched']} | New: {$totals['new']}\n";
echo "Time: {$dur}s\n";
