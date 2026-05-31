<?php
/**
 * Per-source Twitter fetch diagnostic. Shows exactly what each transport
 * returns for a given handle so we can tell whether the account is
 * fundamentally blocked, suspended, or just rate-limited at this moment.
 *
 * Run: php diag_tw_source.php qudsn
 *      php diag_tw_source.php palpostn
 *      php diag_tw_source.php AJArabic   (control: known-working)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/twitter_fetch.php';

$username = $argv[1] ?? 'AJArabic';
echo "=== فحص حساب @{$username} ===\n\n";

// 1) Is the account in our DB?
$db = getDB();
$src = $db->prepare("SELECT * FROM twitter_sources WHERE username = ?");
$src->execute([$username]);
$row = $src->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo "❌ الحساب غير موجود في twitter_sources\n";
    exit;
}
echo "✓ موجود في DB، is_active=" . ($row['is_active'] ?? '?') . "\n";
echo "  آخر جلب: " . ($row['last_fetched_at'] ?? 'لم يحصل') . "\n";
echo "  آخر خطأ: " . ($row['last_error'] ?? 'لا شي') . "\n";
echo "  محاولات فاشلة متتالية: " . ($row['consecutive_failures'] ?? 0) . "\n\n";

// 2) How fresh is the data we have in the DB?
$stat = $db->prepare("
    SELECT COUNT(*) AS total,
           MAX(posted_at) AS newest,
           MIN(posted_at) AS oldest,
           UNIX_TIMESTAMP() - UNIX_TIMESTAMP(MAX(posted_at)) AS age_secs
      FROM twitter_messages
     WHERE source_id = ?
");
$stat->execute([$row['id']]);
$s = $stat->fetch(PDO::FETCH_ASSOC);
echo "=== رسائل المحفوظة لهذا الحساب ===\n";
echo "  المجموع: " . ($s['total'] ?? 0) . "\n";
echo "  أحدث رسالة: " . ($s['newest'] ?? '—') . "\n";
echo "  أقدم رسالة: " . ($s['oldest'] ?? '—') . "\n";
if (!empty($s['age_secs'])) {
    $hours = round($s['age_secs'] / 3600, 1);
    echo "  عمر آخر رسالة: {$hours} ساعة\n";
}
echo "\n";

// 3) Live transport test — try fetching now.
echo "=== محاولة جلب الآن ===\n";
$start = microtime(true);
$tweets = tw_fetch_user_tweets($username, 20);
$elapsed = round(microtime(true) - $start, 2);
echo "النتيجة: " . count($tweets) . " تغريدات | {$elapsed}s\n";
if (!empty($tweets)) {
    echo "✅ يعمل! أحدث تغريدة:\n";
    echo "   [" . ($tweets[0]['posted_at'] ?? '?') . "]\n";
    echo "   " . mb_substr($tweets[0]['text'] ?? '', 0, 120) . "...\n";
} else {
    echo "❌ لم يرجع أي شي. التفاصيل بـ tail -100 ~/logs/error.log\n";
}
