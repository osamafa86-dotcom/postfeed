<?php
/**
 * Sabah morning briefing diagnostics — why didn't today's briefing
 * publish? Checks history, cron log, corpus, and cron_key.
 *
 * Run on the server:
 *   php diag_sabah.php
 *
 * Read-only — no briefing is generated.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sabah.php';

echo "═══════════════════════════════════════════════\n";
echo "  تشخيص موجز الصباح\n";
echo "═══════════════════════════════════════════════\n\n";

// 1. Today's briefing
$today = date('Y-m-d');
echo "1) موجز اليوم ($today):\n";
$brief = sabah_get($today);
if ($brief) {
    echo "   ✅ موجود (#{$brief['id']})\n";
    echo "   عنوان: " . mb_substr($brief['headline'] ?? '', 0, 80) . "\n";
    echo "   تم توليده: " . ($brief['generated_at'] ?? '?') . "\n";
} else {
    echo "   ❌ لا يوجد موجز لليوم — السبب أحد:\n";
    echo "      • الـ cron لم يعمل صباحًا\n";
    echo "      • الـ cron عمل لكن sabah_generate فشل (AI 429، نقص clusters...)\n";
    echo "      • الـ cron مجدول لكن وقته لم يحن بعد (الآن: " . date('H:i') . " UTC)\n";
}

// 2. Last 7 days history
echo "\n2) تاريخ آخر ٧ أيام:\n";
$history = sabah_list(7);
if (empty($history)) {
    echo "   ❌ لا توجد أي موجزات (الجدول فارغ — أول مرة، أو لم يعمل أبدًا)\n";
} else {
    foreach ($history as $b) {
        $tag = ($b['date'] ?? '') === $today ? '◀️ اليوم' : '';
        echo "   • " . ($b['date'] ?? '?') . " — #{$b['id']} — " . mb_substr($b['headline'] ?? '', 0, 60) . " $tag\n";
    }
    $newest = $history[0];
    $newestDate = strtotime($newest['date'] ?? '1970-01-01');
    $hoursAgo = round((time() - $newestDate) / 3600, 1);
    echo "   آخر موجز عمره: {$hoursAgo} ساعة\n";
}

// 3. Cron log
echo "\n3) آخر تشغيل للـ cron (cron_log.txt):\n";
$logFile = __DIR__ . '/cron_log.txt';
if (is_file($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
    if ($lines) {
        $sabahLines = array_filter($lines, fn($l) => stripos($l, 'sabah') !== false);
        if (empty($sabahLines)) {
            echo "   ❌ لا يوجد ذكر لـ sabah في cron_log.txt\n";
            echo "   → الـ cron لـ sabah غير مسجل في cPanel\n";
        } else {
            $last5 = array_slice($sabahLines, -5);
            foreach ($last5 as $l) echo "   $l\n";
        }
    } else {
        echo "   (cron_log.txt فاضي)\n";
    }
} else {
    echo "   ❌ cron_log.txt غير موجود — الـ crons لا تكتب فيه أو لم تعمل بعد\n";
}

// 4. Corpus availability
echo "\n4) المحتوى المتاح للموجز:\n";
$db = getDB();
try {
    $last24h = (int)$db->query("SELECT COUNT(*) FROM articles WHERE published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status='published'")->fetchColumn();
    echo "   مقالات آخر 24 ساعة: $last24h\n";
    if ($last24h < 30) {
        echo "   ⚠️ قليل (< 30) — sabah_generate قد يفشل لقلة المحتوى\n";
    } else {
        echo "   ✅ كافٍ\n";
    }
} catch (Throwable $e) {
    echo "   ✗ DB error: {$e->getMessage()}\n";
}

// 5. Settings: cron_key
echo "\n5) cron_key (للوصول عبر HTTP):\n";
$cronKey = getSetting('cron_key', '');
if ($cronKey) {
    echo "   ✅ مضبوط: " . substr($cronKey, 0, 8) . "...\n";
    echo "   URL للاختبار: https://feedsnews.net/cron_sabah.php?key={$cronKey}\n";
} else {
    echo "   ❌ غير مضبوط — لا يمكن تشغيل الـ cron عبر HTTP\n";
    echo "   → اضبط cron_key من admin panel أولًا\n";
}

// 6. Suggested cron line
echo "\n6) سطر cron الموصى به (للنشر 9 صباحًا بتوقيت القدس = 7 UTC):\n";
if ($cronKey) {
    echo "   0 7 * * * curl -fsS \"https://feedsnews.net/cron_sabah.php?key={$cronKey}\" > /dev/null 2>&1\n";
} else {
    echo "   (يحتاج cron_key أولًا)\n";
}
echo "\n   ضع هذا في cPanel → Cron Jobs\n";

echo "\n═══════════════════════════════════════════════\n";
echo "💡 لتوليد موجز اليوم يدويًا الآن (لاختبار التوليد بدون cron):\n";
echo "   php cron_sabah.php\n";
echo "   أو لإعادة توليد إن كان موجود:\n";
echo "   php cron_sabah.php force=1\n";
echo "═══════════════════════════════════════════════\n";
