<?php
/**
 * Backfill content_type for recent articles.
 *
 * Usage:
 *   php backfill_content_types.php             # default: last 7 days, AI on
 *   php backfill_content_types.php 14          # last 14 days
 *   php backfill_content_types.php 7 --no-ai   # patterns only (cheap dry run)
 *   php backfill_content_types.php 30 --max=2000   # cap at 2000 articles
 *
 * The script:
 *   1. Lazy-adds content_type columns if they don't exist yet.
 *   2. Runs pattern-based classification on every unclassified article.
 *   3. Sends remaining ambiguous ones to Gemini in batches of 15.
 *   4. Prints a per-type summary at the end.
 *
 * Expected runtime:
 *   - ~100 articles: 5-15 seconds (patterns) + 1-3 AI calls
 *   - ~1000 articles: 1-3 minutes (more AI batches)
 *   - 7-day backfill (5k articles): 5-10 minutes, ~$0.30-0.80 AI cost
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/content_classifier.php';

// Parse args
$days  = 7;
$max   = 10000;
$useAi = true;
$reclassify = false;

foreach ($argv as $i => $arg) {
    if ($i === 0) continue;
    if ($arg === '--no-ai')               { $useAi = false; continue; }
    if ($arg === '--reclassify-weak')     { $reclassify = true; continue; }
    if (str_starts_with($arg, '--max=')) { $max = (int)substr($arg, 6); continue; }
    if (ctype_digit($arg))                { $days = (int)$arg; continue; }
}

echo "═══════════════════════════════════════════════\n";
echo "  Content-type backfill\n";
echo "═══════════════════════════════════════════════\n";
echo "  Window:    last {$days} days\n";
echo "  Max:       {$max} articles\n";
echo "  AI mode:   " . ($useAi ? 'ON (Gemini for ambiguous)' : 'OFF (patterns only)') . "\n";
if ($reclassify) echo "  Mode:      RECLASSIFY weak (confidence < 0.70) — clears stamp first\n";
echo "\n";

// Reclassify mode: clear content_type_at for any row whose previous
// pass landed it on a low-confidence pattern guess (typically the
// "default news" stamp from a --no-ai run). The next pass picks them
// up exactly like fresh unclassified rows.
if ($reclassify) {
    classify_ensure_columns();
    $db = getDB();
    $stmt = $db->prepare("UPDATE articles
                             SET content_type_at = NULL
                           WHERE published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                             AND content_type_confidence < 0.70");
    $stmt->execute([$days]);
    echo "↻ cleared " . $stmt->rowCount() . " weak classifications, reprocessing...\n\n";
}

$stats = classify_backfill($days, $max, $useAi);

echo "✓ Total processed: {$stats['total']}\n";
echo "  by pattern:      {$stats['pattern']}\n";
echo "  by AI:           {$stats['ai']}\n";
echo "  failed:          {$stats['failed']}\n";
echo "  elapsed:         {$stats['elapsed_sec']}s\n\n";

// Show resulting distribution. Percentages are over the total classified
// in the window — not over $stats['total'], which is only the rows this
// particular run touched (so it would exceed 100% on a partial reclassify).
$db = getDB();
$dist = $db->query("SELECT content_type, COUNT(*) AS cnt
                      FROM articles
                     WHERE published_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                       AND content_type IS NOT NULL
                     GROUP BY content_type
                     ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
if ($dist) {
    $windowTotal = array_sum(array_map(fn($r) => (int)$r['cnt'], $dist));
    echo "📊 Distribution (last {$days} days, {$windowTotal} classified):\n";
    $arabicNames = ['news' => 'أخبار', 'report' => 'تقارير', 'article' => 'مقالات'];
    foreach ($dist as $row) {
        $label = $arabicNames[$row['content_type']] ?? $row['content_type'];
        $pct = $windowTotal > 0 ? round(100 * (int)$row['cnt'] / $windowTotal, 1) : 0;
        echo "  {$label} ({$row['content_type']}): {$row['cnt']} ({$pct}%)\n";
    }
}

echo "\n✅ Done.\n";
