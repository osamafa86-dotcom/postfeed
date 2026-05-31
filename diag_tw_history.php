<?php
/**
 * Show every Twitter source + how active it's been over time.
 * Tells us which accounts truly stopped working and when.
 *
 * Run: php diag_tw_history.php
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

echo "=== كل حسابات تويتر + تاريخ النشاط ===\n\n";

$sources = $db->query("SELECT * FROM twitter_sources ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($sources as $src) {
    echo "📡 @{$src['username']} ({$src['display_name']}) — active={$src['is_active']}\n";

    $stat = $db->prepare("
        SELECT COUNT(*) AS total,
               MAX(posted_at) AS newest,
               MIN(posted_at) AS oldest,
               SUM(CASE WHEN posted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)  THEN 1 ELSE 0 END) AS last_7d,
               SUM(CASE WHEN posted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS last_30d,
               SUM(CASE WHEN posted_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS last_90d
          FROM twitter_messages
         WHERE source_id = ?
    ");
    $stat->execute([$src['id']]);
    $s = $stat->fetch(PDO::FETCH_ASSOC);

    echo "  المجموع: {$s['total']} | آخر 7 أيام: {$s['last_7d']} | آخر 30 يوم: {$s['last_30d']} | آخر 90 يوم: {$s['last_90d']}\n";
    echo "  أحدث: {$s['newest']} | أقدم: {$s['oldest']}\n";
    echo "  آخر خطأ: " . mb_substr($src['last_error'] ?? '—', 0, 80) . "\n";
    echo "\n";
}

echo "\n=== خلاصة: حسابات نشطة (≥1 رسالة آخر 30 يوم) ===\n";
$active = $db->query("
    SELECT s.username, s.display_name, COUNT(m.id) AS cnt, MAX(m.posted_at) AS newest
      FROM twitter_sources s
      LEFT JOIN twitter_messages m
        ON s.id = m.source_id
       AND m.posted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY s.id
     HAVING cnt > 0
     ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($active as $a) {
    echo "  ✓ @{$a['username']}: {$a['cnt']} رسالة (آخر: {$a['newest']})\n";
}
if (empty($active)) echo "  لا يوجد حساب نشط آخر 30 يوم!\n";

echo "\n=== حسابات متوقفة (0 رسالة آخر 30 يوم) ===\n";
$stale = $db->query("
    SELECT s.username, s.display_name,
           (SELECT MAX(posted_at) FROM twitter_messages WHERE source_id=s.id) AS last_ever,
           (SELECT COUNT(*)       FROM twitter_messages WHERE source_id=s.id) AS total
      FROM twitter_sources s
     WHERE s.is_active=1
       AND NOT EXISTS (
         SELECT 1 FROM twitter_messages m
          WHERE m.source_id=s.id
            AND m.posted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
       )
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($stale as $a) {
    echo "  ✗ @{$a['username']}: {$a['total']} رسالة، آخرها {$a['last_ever']}\n";
}
