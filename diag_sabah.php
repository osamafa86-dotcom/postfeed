<?php
/**
 * Temporary diagnostic for the morning-briefing "not enough clusters"
 * problem. Delete after use.
 *
 * Run: php diag_sabah.php
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

echo "=== مقالات آخر 24 ساعة ===\n";
$row = $db->query("
    SELECT
        COUNT(*) AS total,
        COUNT(cluster_key) AS with_cluster,
        SUM(CASE WHEN cluster_key IS NOT NULL AND cluster_key <> '-' THEN 1 ELSE 0 END) AS valid_cluster,
        COUNT(DISTINCT cluster_key) AS unique_clusters
    FROM articles
    WHERE published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
      AND status = 'published'
")->fetch(PDO::FETCH_ASSOC);
echo "إجمالي المقالات: {$row['total']}\n";
echo "لها cluster_key: {$row['with_cluster']}\n";
echo "cluster_key صالح (≠ '-'): {$row['valid_cluster']}\n";
echo "عدد المجموعات الفريدة: {$row['unique_clusters']}\n\n";

echo "=== المجموعات متعددة المصادر (شرط الموجز الأصلي src>=2) ===\n";
$multi = $db->query("
    SELECT a.cluster_key,
           COUNT(DISTINCT a.source_id) AS src_count,
           COUNT(*) AS art_count
    FROM articles a
    WHERE a.status = 'published'
      AND a.cluster_key IS NOT NULL AND a.cluster_key <> '-'
      AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY a.cluster_key
    HAVING src_count >= 2
    ORDER BY src_count DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
echo "عدد المجموعات متعددة المصادر: " . count($multi) . "\n";
foreach ($multi as $m) {
    echo "  - {$m['cluster_key']}: {$m['src_count']} مصادر، {$m['art_count']} مقال\n";
}

echo "\n=== المجموعات بأي عدد مصادر (fallback الجديد) ===\n";
$any = $db->query("
    SELECT a.cluster_key,
           COUNT(*) AS art_count
    FROM articles a
    WHERE a.status = 'published'
      AND a.cluster_key IS NOT NULL AND a.cluster_key <> '-'
      AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY a.cluster_key
    ORDER BY art_count DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
echo "عدد كل المجموعات: " . count($any) . "\n";
foreach (array_slice($any, 0, 5) as $m) {
    echo "  - {$m['cluster_key']}: {$m['art_count']} مقال\n";
}

echo "\n=== فحص دالة الجمع الفعلية ===\n";
require_once __DIR__ . '/includes/sabah.php';
$data = sabah_collect_top_clusters(8);
echo "corpus count: {$data['count']}\n";
echo "article_count: {$data['article_count']}\n";
echo "corpus length: " . mb_strlen($data['corpus']) . " حرف\n";
if ($data['count'] > 0) {
    echo "--- أول 500 حرف من الـ corpus ---\n";
    echo mb_substr($data['corpus'], 0, 500) . "\n";
}

echo "\n=== فحص إعداد AI ===\n";
echo "ai_provider: " . getSetting('ai_provider', 'gemini') . "\n";
$gkey = getSetting('gemini_api_key', '');
echo "gemini_api_key: " . ($gkey !== '' ? 'مُعدّ (' . mb_substr($gkey, 0, 8) . '...)' : 'فارغ ❌') . "\n";
$akey = getSetting('anthropic_api_key', '');
echo "anthropic_api_key: " . ($akey !== '' ? 'مُعدّ' : 'فارغ') . "\n";
