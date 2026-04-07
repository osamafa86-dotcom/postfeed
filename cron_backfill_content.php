<?php
/**
 * Backfill: re-fetch full body (≥3 paragraphs) for articles whose content is too short.
 * CLI:  php cron_backfill_content.php 50
 * HTTP: cron_backfill_content.php?key=XXX&limit=50
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/article_fetch.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403); exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(600);
$db = getDB();
$limit = (int)($_GET['limit'] ?? ($argv[1] ?? 30));
if ($limit < 1 || $limit > 200) $limit = 30;

$stmt = $db->prepare("SELECT id, source_url, content FROM articles
                    WHERE source_url IS NOT NULL AND source_url != ''
                    AND CHAR_LENGTH(content) < 600
                    ORDER BY id DESC LIMIT ?");
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$ok = 0; $skip = 0;
foreach ($rows as $r) {
    $body = fetchArticleBody($r['source_url']);
    if (!empty($body)) {
        $u = $db->prepare("UPDATE articles SET content = ? WHERE id = ?");
        $u->execute([$body, $r['id']]);
        $ok++;
        echo "  ✓ #{$r['id']}\n";
    } else {
        $skip++;
        echo "  · #{$r['id']} skipped\n";
    }
    usleep(150000);
}
echo "\nتم: $ok | تخطي: $skip\n";
