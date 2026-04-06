<?php
/**
 * Backfill source_url for existing articles from RSS feeds
 * Run once: php backfill_urls.php
 * Or via browser: https://postfeed.emdatra.org/backfill_urls.php
 */

require_once __DIR__ . '/includes/config.php';

$db = getDB();

// Get active sources with RSS
$sources = $db->query("SELECT * FROM sources WHERE is_active = 1 AND rss_url IS NOT NULL AND rss_url != ''")->fetchAll();

if (empty($sources)) {
    echo "No active RSS sources.\n";
    exit;
}

$totalUpdated = 0;

foreach ($sources as $source) {
    echo "Processing: {$source['name']}...\n";

    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $source['rss_url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NewsFlow/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $rssContent = curl_exec($ch);
        curl_close($ch);

        if (empty($rssContent)) {
            echo "  Failed to fetch RSS.\n";
            continue;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($rssContent);
        if ($xml === false) {
            echo "  Failed to parse XML.\n";
            continue;
        }

        $items = [];
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $items[] = [
                    'title' => trim((string) $item->title),
                    'link' => trim((string) $item->link),
                ];
            }
        } elseif (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $link = '';
                if (isset($entry->link)) {
                    foreach ($entry->link as $l) {
                        if ((string) $l['rel'] === 'alternate' || empty($link)) {
                            $link = (string) $l['href'];
                        }
                    }
                }
                $items[] = [
                    'title' => trim((string) $entry->title),
                    'link' => trim($link),
                ];
            }
        }

        $updated = 0;
        foreach ($items as $item) {
            if (empty($item['title']) || empty($item['link'])) continue;

            $stmt = $db->prepare("UPDATE articles SET source_url = ? WHERE title = ? AND source_id = ? AND (source_url IS NULL OR source_url = '')");
            $stmt->execute([$item['link'], $item['title'], $source['id']]);
            $updated += $stmt->rowCount();
        }

        $totalUpdated += $updated;
        echo "  Updated {$updated} articles.\n";

    } catch (Exception $e) {
        echo "  Error: {$e->getMessage()}\n";
    }
}

echo "\nTotal updated: {$totalUpdated} articles.\n";
echo "Done! You can delete this file now.\n";
