<?php
/**
 * Backfill: fetch og:image for existing articles missing image_url.
 * CLI:  php cron_backfill_images.php 100
 * HTTP: cron_backfill_images.php?key=XXX&limit=100
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/article_fetch.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) { http_response_code(403); exit('forbidden'); }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(600);
$db = getDB();
$limit = (int)($_GET['limit'] ?? ($argv[1] ?? 50));
if ($limit < 1 || $limit > 300) $limit = 50;

$stmt = $db->prepare("SELECT id, source_url FROM articles
                    WHERE (image_url IS NULL OR image_url = '')
                    AND source_url IS NOT NULL AND source_url != ''
                    ORDER BY id DESC LIMIT ?");
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$ok = 0; $skip = 0;
foreach ($rows as $r) {
    $html = fetchUrlHtml($r['source_url']);
    $img  = extractArticleImage($html, $r['source_url']);
    if (!empty($img)) {
        $db->prepare("UPDATE articles SET image_url = ? WHERE id = ?")->execute([$img, $r['id']]);
        $ok++;
        echo "  ✓ #{$r['id']}\n";
    } else {
        $skip++;
        echo "  · #{$r['id']} no image\n";
    }
    usleep(120000);
}
echo "\nتم: $ok | تخطي: $skip\n";
