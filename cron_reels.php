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

// One-time schema fix: the original reels_schema.sql declared
// idx_shortcode as a non-UNIQUE INDEX, so INSERT IGNORE never dedupes
// and every cron run inserts duplicate rows. Dedupe + add the right
// UNIQUE key. Each ALTER is wrapped in its own try/catch so subsequent
// runs (when the migration is already applied) just no-op.
try {
    $db->exec("DELETE r1 FROM reels r1
               INNER JOIN reels r2
               WHERE r1.id > r2.id AND r1.shortcode = r2.shortcode");
} catch (Throwable $e) {
    error_log('reels dedupe: ' . $e->getMessage());
}
try {
    $db->exec("ALTER TABLE reels DROP INDEX idx_shortcode");
} catch (Throwable $e) {
    // Already dropped or never existed — fine.
}
try {
    $db->exec("ALTER TABLE reels ADD UNIQUE KEY uniq_shortcode (shortcode)");
} catch (Throwable $e) {
    // Already added — fine.
}

try {
    $sources = $db->query("SELECT id, username, display_name
                           FROM reels_sources
                           WHERE is_active = 1
                           ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Failed to load reels_sources: " . $e->getMessage() . "\n";
    echo "Make sure the reels feature schema (reels_schema.sql) has been applied.\n";
    exit;
}

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
