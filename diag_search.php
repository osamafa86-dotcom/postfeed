<?php
/**
 * Search diagnostics — measure the difference between the old LIKE
 * path and the new FULLTEXT path, and verify the tokenizer.
 *
 * Run on the server:
 *   php diag_search.php
 *   php diag_search.php "الأقصى"
 *
 * Read-only — no rows are modified.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/api/v1/_articles_query.php';

$queries = !empty($argv[1]) ? [$argv[1]] : [
    'الأقصى',
    'حماس',
    'وقف اطلاق النار',
    'الكنيست',
    'غزة الاحتلال',
];

echo "═══════════════════════════════════════════════\n";
echo "  تشخيص البحث (FULLTEXT vs LIKE)\n";
echo "═══════════════════════════════════════════════\n\n";

// 1. Verify the wide FULLTEXT index exists
$db = getDB();
try {
    $idx = $db->query("SHOW INDEX FROM articles WHERE Key_name = 'ft_articles_search'")->fetch();
    if ($idx) {
        echo "✅ ft_articles_search index موجود\n\n";
    } else {
        echo "❌ ft_articles_search مش موجود — شغّل: php migrate.php\n\n";
    }
} catch (Throwable $e) {
    echo "✗ خطأ: " . $e->getMessage() . "\n\n";
}

foreach ($queries as $q) {
    echo "▸ السؤال: «{$q}»\n";

    // Tokenizer
    $clause = articles_search_clause($q);
    if ($clause) {
        echo "  BOOLEAN expr: {$clause['param']}\n";
    } else {
        echo "  ⚠️ لم ينتج expression (سيتم استخدام LIKE)\n";
    }

    // FULLTEXT timing
    if ($clause) {
        $t0 = microtime(true);
        $stmt = $db->prepare("SELECT COUNT(*) FROM articles a
                              WHERE a.status='published' AND {$clause['sql']}");
        $stmt->execute([$clause['param']]);
        $cnt = (int)$stmt->fetchColumn();
        $dt = round((microtime(true) - $t0) * 1000, 1);
        echo "  FULLTEXT: {$cnt} نتيجة في {$dt}ms\n";
    }

    // LIKE timing (for comparison)
    $t0 = microtime(true);
    $stmt = $db->prepare("SELECT COUNT(*) FROM articles a
                          WHERE a.status='published'
                            AND (a.title LIKE ? OR a.excerpt LIKE ? OR a.ai_summary LIKE ?)");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $cnt = (int)$stmt->fetchColumn();
    $dt = round((microtime(true) - $t0) * 1000, 1);
    echo "  LIKE:     {$cnt} نتيجة في {$dt}ms\n";

    // Top results via fetch_articles (uses FULLTEXT now)
    $rows = fetch_articles(['q' => $q], 5, 0);
    echo "  أعلى 5 (مرتبة بالـ relevance):\n";
    foreach ($rows as $r) {
        $t = mb_substr((string)($r['title'] ?? ''), 0, 75);
        echo "    • #{$r['id']} — {$t}\n";
    }
    echo "\n";
}

echo "═══════════════════════════════════════════════\n";
