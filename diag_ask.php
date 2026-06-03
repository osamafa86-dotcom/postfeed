<?php
/**
 * Ask AI diagnostics — verify the new broad-query path picks the right
 * stories and excludes the briefing aggregations.
 *
 * Run on the server:
 *   php diag_ask.php
 *   php diag_ask.php "ما أبرز الأخبار اليوم؟"
 *
 * Read-only — no AI call is made.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ai_qa.php';

$queries = !empty($argv[1]) ? [$argv[1]] : [
    'ما أبرز الأخبار اليوم؟',
    'ما أهم الأحداث؟',
    'ما الجديد من غزة اليوم؟',  // broad phrase BUT specific topic (gaza)
    'آخر تطورات الكنيست',
    'ملخص اليوم',
];

echo "═══════════════════════════════════════════════\n";
echo "  تشخيص نظام اسأل (Ask AI)\n";
echo "═══════════════════════════════════════════════\n\n";

foreach ($queries as $q) {
    echo "▸ السؤال: {$q}\n";
    $isBroad = qa_is_broad_query($q);
    echo "  نوع: " . ($isBroad ? 'عام (broad → clusters)' : 'محدد (specific → keywords)') . "\n";

    $kws = qa_extract_keywords($q);
    echo "  كلمات مفتاحية: " . implode(', ', $kws) . "\n";

    $rows = $isBroad
        ? qa_retrieve_top_today(8, 24)
        : qa_retrieve_articles($q, 8, 14);

    echo "  مقالات مسترجعة: " . count($rows) . "\n";
    if (empty($rows)) {
        echo "    ⚠️ لا نتائج\n";
    } else {
        foreach ($rows as $r) {
            $t = mb_substr((string)$r['title'], 0, 70);
            $src = isset($r['_src_count']) ? " [{$r['_src_count']}مصادر]" : '';
            echo "    • #{$r['id']}{$src} — {$t}\n";
        }
    }
    echo "\n";
}

// Sanity check: aggregation articles MUST not appear in broad results.
echo "─── فحص استبعاد التجميعات ──────────────────────\n";
$db = getDB();
$leakedCount = 0;
$rows = qa_retrieve_top_today(20, 48);
foreach ($rows as $r) {
    if (preg_match('/^موجز\s+(أخبار|اخبار|الساعة|اليوم)/u', (string)$r['title'])) {
        echo "  ❌ تسرّب: #{$r['id']} — {$r['title']}\n";
        $leakedCount++;
    }
}
if ($leakedCount === 0) {
    echo "  ✅ لا توجد تجميعات في النتائج (" . count($rows) . " مقالًا فحصها)\n";
}

echo "\n═══════════════════════════════════════════════\n";
echo "💡 لاختبار توليد إجابة فعلًا (يستهلك من الـ API):\n";
echo "   php -r \"require 'includes/config.php'; require 'includes/functions.php'; require 'includes/ai_qa.php'; print_r(qa_ask('ما أبرز الأخبار اليوم؟'));\"\n";
echo "═══════════════════════════════════════════════\n";
