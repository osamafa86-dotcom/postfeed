<?php
/**
 * Admin-only backfill runner: re-fetches full body for articles whose content
 * is too short (under 600 chars), using the improved extractor in
 * includes/article_fetch.php.
 *
 * URL: /panel/backfill_content.php?limit=100
 * Auth: panel admin session (via requireAdmin()).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/article_fetch.php';
requireAdmin();

@set_time_limit(600);
header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$limit = (int)($_GET['limit'] ?? 50);
if ($limit < 1 || $limit > 200) $limit = 50;

echo "=== إعادة جلب محتوى المقالات ===\n";
echo "الحد الأقصى: $limit\n\n";

// Flush output as we go so the admin sees progress in real time
@ob_implicit_flush(true);
while (ob_get_level()) ob_end_flush();

$stmt = $db->prepare("SELECT id, source_url, content FROM articles
                      WHERE source_url IS NOT NULL AND source_url != ''
                      AND CHAR_LENGTH(content) < 600
                      ORDER BY id DESC LIMIT ?");
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

if (!$rows) {
    echo "لا يوجد مقالات بحاجة لإعادة جلب.\n";
    exit;
}

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
echo "\nلإعادة التشغيل على مقالات إضافية: افتح الرابط مرة ثانية.\n";
