<?php
/**
 * نيوز فيد — تشخيص شامل للحيّ (تلغرام + الملخصات + قدرات الخادم).
 *
 * الهدف: معرفة لماذا تأخّر تحديث تلغرام ولماذا تجمّدت الملخصات منذ أيام،
 * دون الحاجة للوصول لسجلّات الخادم.
 *
 * الوصول (أحد طريقتين):
 *   - وأنت مسجّل دخول كأدمن في اللوحة، افتح:  /diag_health.php
 *   - أو:  /diag_health.php?key=CRON_KEY
 *
 * إجراءات اختيارية (تشغيل فعلي مباشر — للتأكّد أن المسار يعمل الآن):
 *   ?run=tgsync   → يشغّل سحب تلغرام مباشرة ويُبلّغ بعدد الجديد والمدّة
 *   ?ai=1         → ينفّذ نداء AI حقيقياً صغيراً ويُبلّغ النتيجة/الخطأ
 *   ?run=spawn    → يختبر قدرة exec على إطلاق عملية PHP منفصلة
 *
 * قراءة فقط افتراضياً (لا يولّد أي ملخّص ولا يكتب شيئاً) عدا الإجراءات أعلاه.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) @session_start();

$key    = (string)($_GET['key'] ?? '');
$authed = function_exists('isAdmin') && isAdmin();
$ckey   = (string)getSetting('cron_key', '');
if (!$authed && ($ckey === '' || $key !== $ckey)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "forbidden — سجّل دخولك في اللوحة أولاً، أو أضِف ?key=CRON_KEY\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(120);

$db = getDB();

function h_age($ts): string {
    if (!$ts) return 'لا يوجد (never)';
    $s = time() - (int)$ts;
    if ($s < 0) $s = 0;
    if ($s < 90)        $out = $s . 's';
    elseif ($s < 5400)  $out = round($s / 60) . 'm';
    elseif ($s < 172800)$out = round($s / 3600, 1) . 'h';
    else                $out = round($s / 86400, 1) . 'd';
    return $out . ' مضت  (' . date('Y-m-d H:i:s', (int)$ts) . ')';
}
function h_maxts(PDO $db, string $sql, array $p = []): ?int {
    try { $st = $db->prepare($sql); $st->execute($p); $v = $st->fetchColumn(); return $v ? (int)$v : null; }
    catch (Throwable $e) { return null; }
}
function h_fn(string $f): string {
    if (!function_exists($f)) return 'مفقودة (function_exists=false)';
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return in_array($f, $dis, true) ? '❌ معطّلة عبر disable_functions' : '✅ متاحة';
}

$NOW = date('Y-m-d H:i:s');
echo "═══════════════════════════════════════════════════════════════\n";
echo "  تشخيص نيوز فيد — $NOW (UTC offset: " . date('P') . ")\n";
echo "  auth: " . ($authed ? 'admin-session' : 'cron-key') . "\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

/* ── 1) قدرات الخادم ───────────────────────────────────────────────── */
echo "1) قدرات الخادم\n";
echo "   PHP_SAPI            : " . PHP_SAPI . "\n";
echo "   PHP_VERSION         : " . PHP_VERSION . "\n";
echo "   PHP_BINARY          : " . (PHP_BINARY ?: '?') . "\n";
echo "   max_execution_time  : " . ini_get('max_execution_time') . "\n";
echo "   fastcgi_finish_req. : " . (function_exists('fastcgi_finish_request') ? '✅ متاحة' : '❌ مفقودة') . "\n";
echo "   litespeed_finish_r. : " . (function_exists('litespeed_finish_request') ? '✅ متاحة (LiteSpeed)' : '❌ مفقودة') . "\n";
echo "   nf_finish_request   : " . (function_exists('nf_finish_request') ? '✅ معرّفة (تدعم النظامين)' : '— غير منشورة بعد') . "\n";
echo "   exec()              : " . h_fn('exec') . "\n";
echo "   shell_exec()        : " . h_fn('shell_exec') . "\n";
echo "   proc_open()         : " . h_fn('proc_open') . "\n";
echo "   curl                : " . (function_exists('curl_init') ? '✅' : '❌') . "\n";
$dis = trim((string)ini_get('disable_functions'));
echo "   disable_functions   : " . ($dis === '' ? '(فارغ)' : $dis) . "\n";
echo "\n";

/* ── 2) مفاتيح/مزوّد الذكاء الاصطناعي ───────────────────────────────── */
echo "2) مزوّد الذكاء الاصطناعي (يولّد كل الملخصات)\n";
require_once __DIR__ . '/includes/ai_provider.php';
$prov = function_exists('ai_provider_active') ? ai_provider_active() : '?';
echo "   المزوّد النشِط        : $prov\n";
echo "   gemini_api_key مضبوط : " . (trim((string)getSetting('gemini_api_key','')) !== '' ? 'نعم' : 'لا') . "\n";
echo "   anthropic_api_key    : " . (trim((string)getSetting('anthropic_api_key','')) !== '' ? 'نعم' : 'لا') . "\n";
echo "   gemini_model         : " . getSetting('gemini_model','gemini-2.5-flash') . "\n";
if (($_GET['ai'] ?? '') === '1') {
    require_once __DIR__ . '/includes/ai_provider.php';
    $t0 = microtime(true);
    $r  = ai_provider_text_call('أجب بكلمة واحدة فقط: مرحبا', 16);
    $ms = round((microtime(true) - $t0) * 1000);
    echo "   نداء AI حيّ          : " . (!empty($r['ok']) ? "✅ نجح ({$ms}ms) → \"" . trim($r['text'] ?? '') . "\"" : "❌ فشل ({$ms}ms) → " . ($r['error'] ?? '?')) . "\n";
} else {
    echo "   نداء AI حيّ          : (أضِف ?ai=1 لتنفيذ نداء حقيقي صغير واختبار المفتاح)\n";
}
echo "\n";

/* ── 3) نضارة الملخصات ─────────────────────────────────────────────── */
echo "3) نضارة الملخصات (آخر توليد)\n";
$sab = h_maxts($db, "SELECT UNIX_TIMESTAMP(MAX(generated_at)) FROM sabah_briefings");
$tgs = h_maxts($db, "SELECT UNIX_TIMESTAMP(MAX(generated_at)) FROM telegram_summaries");
$tw  = h_maxts($db, "SELECT UNIX_TIMESTAMP(MAX(generated_at)) FROM social_summaries WHERE platform='twitter'");
$yt  = h_maxts($db, "SELECT UNIX_TIMESTAMP(MAX(generated_at)) FROM social_summaries WHERE platform='youtube'");
$wk  = h_maxts($db, "SELECT UNIX_TIMESTAMP(MAX(published_at)) FROM weekly_rewinds");
echo "   موجز الصباح (sabah)  : " . h_age($sab) . "   [عتبة 24h]\n";
echo "   ملخص تلغرام (tg)     : " . h_age($tgs) . "   [عتبة 4h]\n";
echo "   ملخص X/twitter       : " . h_age($tw)  . "   [عتبة 4h]\n";
echo "   ملخص youtube         : " . h_age($yt)  . "   [عتبة 4h]\n";
echo "   مراجعة الأسبوع       : " . h_age($wk)  . "   [عتبة 7d]\n";
echo "\n";

/* ── 4) نضارة تلغرام الخام ──────────────────────────────────────────── */
echo "4) رسائل تلغرام الخام (الصندوق بالرئيسية + التطبيق يقرآن من هنا)\n";
$tmPost = h_maxts($db, "SELECT UNIX_TIMESTAMP(MAX(posted_at)) FROM telegram_messages WHERE is_active=1");
$tmCre  = h_maxts($db, "SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM telegram_messages WHERE is_active=1");
$srcN   = (int)($db->query("SELECT COUNT(*) FROM telegram_sources WHERE is_active=1")->fetchColumn() ?: 0);
$fetMin = h_maxts($db, "SELECT UNIX_TIMESTAMP(MIN(last_fetched_at)) FROM telegram_sources WHERE is_active=1");
$fetMax = h_maxts($db, "SELECT UNIX_TIMESTAMP(MAX(last_fetched_at)) FROM telegram_sources WHERE is_active=1");
echo "   أحدث posted_at       : " . h_age($tmPost) . "\n";
echo "   أحدث created_at(جلب) : " . h_age($tmCre)  . "  ← متى أُدخلت آخر رسالة فعلياً في القاعدة\n";
echo "   مصادر نشطة           : $srcN\n";
echo "   last_fetched (الأقدم): " . h_age($fetMin) . "\n";
echo "   last_fetched (الأحدث): " . h_age($fetMax) . "\n";
$lock = sys_get_temp_dir() . '/nf_tg_sync.lock';
echo "   قفل السحب (tmp)      : " . (is_file($lock) ? h_age(@filemtime($lock)) . "  @ $lock" : "غير موجود @ $lock") . "\n";
echo "\n";

/* ── 5) إجراءات حيّة ────────────────────────────────────────────────── */
echo "5) إجراءات حيّة\n";
$run = (string)($_GET['run'] ?? '');
if ($run === 'tgsync') {
    require_once __DIR__ . '/includes/telegram_fetch.php';
    $t0 = microtime(true);
    $new = 0; $err = '';
    try { $new = (int) tg_sync_all_sources(); } catch (Throwable $e) { $err = $e->getMessage(); }
    $sec = round(microtime(true) - $t0, 1);
    echo "   run=tgsync           : " . ($err === '' ? "✅ تم — جديد=$new — المدّة={$sec}s" : "❌ خطأ: $err — المدّة={$sec}s") . "\n";
    echo "                          (إن كان جديد>0 الآن، فالمشكلة ليست في السحب بل في تكرار تشغيله — كرون/زيارات)\n";
} elseif ($run === 'summaries') {
    // Force-trigger the summary crons via the HTTP key path (same as the
    // self-heal). Dispatches detached; regeneration finishes within ~1-2 min.
    $fired = [];
    foreach (['cron_sabah.php','cron_tg_summary.php','cron_social_summary.php','cron_weekly_rewind.php'] as $cf) {
        $ok = function_exists('nf_trigger_cron') ? nf_trigger_cron($cf) : false;
        $fired[] = $cf . '=' . ($ok ? 'dispatched' : 'FAILED');
    }
    echo "   run=summaries        : " . implode('  ', $fired) . "\n";
    echo "                          (انتظر دقيقة-دقيقتين ثم حدّث هذه الصفحة — يجب أن تتحدّث أعمار القسم 3)\n";
} elseif ($run === 'spawn') {
    if (!function_exists('exec')) { echo "   run=spawn            : ❌ exec غير متاحة\n"; }
    else {
        $bin = PHP_BINARY ?: 'php';
        $out = []; $code = 0;
        @exec(escapeshellcmd($bin) . ' -r ' . escapeshellarg('echo "SPAWN_OK";') . ' 2>&1', $out, $code);
        echo "   run=spawn            : exit=$code out=" . trim(implode(' ', $out)) . "\n";
        echo "                          (SPAWN_OK يعني أن آلية إصلاح الملخصات الذاتية تستطيع إطلاق الكرونات)\n";
    }
} else {
    echo "   (?run=tgsync لسحب تلغرام • ?run=summaries لتوليد كل الملخصات • ?run=spawn لاختبار exec)\n";
}
echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "انسخ كامل هذا الناتج وأرسله — منه أحدّد السبب الجذري بدقّة وأصلحه.\n";
