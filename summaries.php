<?php
/**
 * نيوز فيد — هاب الملخصات
 * ========================
 * صفحة مركزية تعرض أحدث ملخص لكل قسم + رابط للأرشيف الكامل.
 *
 * الأقسام:
 *   ☕ موجز الصباح
 *   📅 مراجعة الأسبوع
 *   📢 تلغرام
 *   𝕏 منصة X
 *   ▶ يوتيوب
 *
 * لكل ملخص: قراءة كاملة / معاينة / تنزيل PDF / طباعة.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/sabah.php';
require_once __DIR__ . '/includes/weekly_rewind.php';
require_once __DIR__ . '/includes/ai_helper.php';
require_once __DIR__ . '/includes/auto_summaries.php';

// Self-heal: if any briefing pipeline (sabah / tg / social /
// weekly) hasn't generated a fresh row within its expected
// cadence, fire the matching cron in the background. The helper
// is single-flight locked per surface so concurrent visitors
// don't fan out multiple spawns.
auto_trigger_summaries_if_stale();

$pageTheme  = current_theme();
$viewer     = current_user();
$viewerId   = (int)($viewer['id'] ?? 0);
$userUnread = $viewerId ? getUnreadNotifCount() : 0;

/**
 * Arabic-friendly long datetime formatter used on every card so the
 * reader sees exactly when the briefing was generated: "الإثنين، 8
 * يونيو 2026 — 14:32". Falls back to the raw input if parsing fails.
 */
function summaries_format_datetime($raw): string {
    if (!$raw) return '';
    $ts = is_numeric($raw) ? (int)$raw : strtotime((string)$raw);
    if (!$ts) return (string)$raw;
    static $days = ['Sunday'=>'الأحد','Monday'=>'الإثنين','Tuesday'=>'الثلاثاء','Wednesday'=>'الأربعاء','Thursday'=>'الخميس','Friday'=>'الجمعة','Saturday'=>'السبت'];
    static $months = [1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',5=>'مايو',6=>'يونيو',7=>'يوليو',8=>'أغسطس',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
    $day   = $days[date('l', $ts)] ?? date('l', $ts);
    $d     = (int)date('j', $ts);
    $m     = $months[(int)date('n', $ts)] ?? '';
    $y     = date('Y', $ts);
    $time  = date('H:i', $ts);
    return $day . '، ' . $d . ' ' . $m . ' ' . $y . ' — ' . $time;
}

// Each surface returns the latest briefing only — the user explicitly
// asked us to stop listing all of them on the hub. The "تصفح الأرشيف"
// button under each card takes the reader to the existing archive
// page for that surface.
$sabahLatest    = function_exists('sabah_get_latest')         ? sabah_get_latest()                : null;
$weeklyLatest   = function_exists('wr_get_latest')            ? wr_get_latest()                   : null;
$tgLatest       = function_exists('tg_summary_get_latest')    ? tg_summary_get_latest()           : null;
$twitterLatest  = function_exists('social_summary_get_latest')? social_summary_get_latest('twitter'): null;
$youtubeLatest  = function_exists('social_summary_get_latest')? social_summary_get_latest('youtube'): null;

$cards = [];

// 1) Sabah (morning briefing) — keyed by briefing_date.
if ($sabahLatest) {
    $date = $sabahLatest['briefing_date'] ?? '';
    $cards[] = [
        'label'    => '☕ موجز الصباح',
        'color'    => '#B8860B',
        'title'    => $sabahLatest['headline'] ?? ('موجز ' . $date),
        'snippet'  => mb_substr(strip_tags((string)($sabahLatest['summary'] ?? '')), 0, 180),
        'when'     => summaries_format_datetime($sabahLatest['generated_at'] ?? $date),
        'url_read' => '/sabah/' . rawurlencode($date),
        'url_print'=> '/sabah/' . rawurlencode($date) . '?print=1',
        'archive'  => '/sabah',
    ];
}

// 2) Weekly Rewind — schema differs from the other surfaces:
//    cover_title / cover_subtitle / intro_text / published_at /
//    start_date / end_date (not headline/summary/generated_at).
//    Build a sensible card with fallback chains so we never render
//    a blank "أسبوع X" tile when the rewind has actual content.
if ($weeklyLatest) {
    $yw         = $weeklyLatest['year_week'] ?? '';
    $title      = trim((string)($weeklyLatest['cover_title'] ?? ''));
    if ($title === '') $title = trim((string)($weeklyLatest['cover_subtitle'] ?? ''));
    if ($title === '') {
        $start = $weeklyLatest['start_date'] ?? '';
        $end   = $weeklyLatest['end_date'] ?? '';
        $title = ($start && $end)
            ? ('مراجعة الأسبوع — ' . $start . ' إلى ' . $end)
            : ('مراجعة أسبوع ' . $yw);
    }
    $snippet = mb_substr(strip_tags((string)($weeklyLatest['intro_text'] ?? $weeklyLatest['cover_subtitle'] ?? '')), 0, 180);
    $when    = summaries_format_datetime($weeklyLatest['published_at'] ?? ($weeklyLatest['end_date'] ?? ''));
    $cards[] = [
        'label'    => '📅 مراجعة الأسبوع',
        'color'    => '#3D5A28',
        'title'    => $title,
        'snippet'  => $snippet,
        'when'     => $when,
        'url_read' => '/weekly/' . rawurlencode($yw),
        'url_print'=> '/weekly/' . rawurlencode($yw) . '?print=1',
        'archive'  => '/weekly/archive',
    ];
}

// 3) Telegram briefing.
if ($tgLatest) {
    $cards[] = [
        'label'    => '📢 ملخص تلغرام',
        'color'    => '#0088cc',
        'title'    => $tgLatest['headline'] ?? ('موجز تلغرام #' . $tgLatest['id']),
        'snippet'  => mb_substr(strip_tags((string)($tgLatest['summary'] ?? '')), 0, 180),
        'when'     => summaries_format_datetime($tgLatest['generated_at'] ?? ''),
        'url_read' => '/tg-report/' . (int)$tgLatest['id'],
        'url_print'=> '/tg-report/' . (int)$tgLatest['id'] . '?print=1',
        'archive'  => '/telegram.php',
    ];
}

// 4) Twitter / X.
if ($twitterLatest) {
    $cards[] = [
        'label'    => '𝕏 ملخص منصة X',
        'color'    => '#1d9bf0',
        'title'    => $twitterLatest['headline'] ?? ('موجز X #' . $twitterLatest['id']),
        'snippet'  => mb_substr(strip_tags((string)($twitterLatest['summary'] ?? '')), 0, 180),
        'when'     => summaries_format_datetime($twitterLatest['generated_at'] ?? ''),
        'url_read' => '/summary-view.php?platform=twitter&id=' . (int)$twitterLatest['id'],
        'url_print'=> '/summary-view.php?platform=twitter&id=' . (int)$twitterLatest['id'] . '&print=1',
        'archive'  => '/twitter_feed.php',
    ];
}

// 5) YouTube.
if ($youtubeLatest) {
    $cards[] = [
        'label'    => '▶ ملخص يوتيوب',
        'color'    => '#ff0000',
        'title'    => $youtubeLatest['headline'] ?? ('موجز يوتيوب #' . $youtubeLatest['id']),
        'snippet'  => mb_substr(strip_tags((string)($youtubeLatest['summary'] ?? '')), 0, 180),
        'when'     => summaries_format_datetime($youtubeLatest['generated_at'] ?? ''),
        'url_read' => '/summary-view.php?platform=youtube&id=' . (int)$youtubeLatest['id'],
        'url_print'=> '/summary-view.php?platform=youtube&id=' . (int)$youtubeLatest['id'] . '&print=1',
        'archive'  => '/youtube_feed.php',
    ];
}

$pageTitle = 'الملخصات | نيوز فيد';
$pageDescription = 'أحدث ملخصات نيوز فيد في مكان واحد: موجز الصباح، مراجعة الأسبوع، ملخصات تلغرام، X، ويوتيوب — مع قراءة كاملة، معاينة، تنزيل وطباعة.';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($pageTitle); ?></title>
<meta name="description" content="<?php echo e($pageDescription); ?>">
<link rel="stylesheet" href="/assets/css/home.min.css?v=<?php echo filemtime(__DIR__ . '/assets/css/home.min.css'); ?>">
<link rel="stylesheet" href="/assets/css/home-redesign.css?v=<?php echo filemtime(__DIR__ . '/assets/css/home-redesign.css'); ?>">
<?php /* Inline the same critical CSS the homepage uses so the site
  header (logo, search box, nav pills, top bar) renders correctly
  without waiting on the main bundle. Without this the header
  paints as un-styled text — exactly the "مشوّه" look the user
  reported on /summaries. */ ?>
<style><?php
  $__sh = __DIR__ . '/assets/css/site-header.min.css';
  $__ch = __DIR__ . '/assets/css/critical-home.min.css';
  if (file_exists($__sh)) readfile($__sh);
  if (file_exists($__ch)) readfile($__ch);
?></style>
<style>
.sum-hero{background:linear-gradient(135deg,#3D5A28,#1f3417);color:#fff;padding:40px 24px;margin-bottom:36px;}
.sum-hero-inner{max-width:1100px;margin:0 auto;}
.sum-hero h1{font-size:30px;font-weight:900;margin:0 0 12px;}
.sum-hero p{font-size:15px;line-height:1.8;opacity:.88;margin:0;max-width:680px;}
.sum-container{max-width:1100px;margin:0 auto;padding:0 24px 60px;}
.sum-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:22px;}
.sum-card{background:var(--surface,#fff);border:1px solid var(--border,#e7e1d4);border-radius:16px;padding:22px;display:flex;flex-direction:column;gap:12px;box-shadow:0 1px 3px rgba(60,40,20,.05);transition:box-shadow .15s,transform .15s;}
.sum-card:hover{box-shadow:0 10px 28px -16px rgba(60,40,20,.2);transform:translateY(-2px);}
.sum-card-eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:800;color:var(--text-2,#4a4030);text-transform:uppercase;letter-spacing:.5px;align-self:flex-start;padding:5px 12px;border-radius:999px;background:var(--bg-2,#fbf9f4);border:1px solid var(--border,#e7e1d4);}
.sum-card-eyebrow .dot{width:8px;height:8px;border-radius:50%;}
.sum-card-title{font-size:18px;font-weight:800;line-height:1.55;color:var(--text,#2c2416);margin:0;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}
.sum-card-snippet{font-size:13.5px;line-height:1.75;color:var(--muted,#7a6e5d);display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}
.sum-card-when{font-size:12px;font-weight:700;color:var(--muted,#7a6e5d);display:flex;align-items:center;gap:6px;}
.sum-card-when::before{content:"🕐";font-size:13px;}
.sum-card-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:auto;padding-top:14px;border-top:1px solid var(--bg-3,#efeae0);}
.sum-act{display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:9px 10px;border-radius:10px;font-size:12.5px;font-weight:700;text-decoration:none;cursor:pointer;border:1px solid var(--border,#e7e1d4);background:var(--surface,#fff);color:var(--text-2,#4a4030);font-family:inherit;transition:all .15s;}
.sum-act:hover{background:#3D5A28;color:#fff;border-color:#3D5A28;}
.sum-act-primary{background:linear-gradient(135deg,#5B7F3B,#3D5A28);color:#fff;border-color:#3D5A28;grid-column:1 / -1;padding:11px 14px;font-size:13.5px;}
.sum-act-primary:hover{filter:brightness(1.08);}
.sum-archive{display:block;text-align:center;margin-top:10px;padding:9px;font-size:12.5px;font-weight:700;color:var(--muted,#7a6e5d);text-decoration:none;border-radius:10px;border:1px dashed var(--border,#e7e1d4);}
.sum-archive:hover{background:var(--bg-2,#fbf9f4);color:var(--text,#2c2416);border-style:solid;}
.sum-empty{padding:36px 24px;text-align:center;color:var(--muted,#7a6e5d);font-size:14px;background:var(--bg-2,#fbf9f4);border:1px dashed var(--border,#e7e1d4);border-radius:16px;grid-column:1 / -1;}
@media (max-width:640px){
  .sum-hero{padding:26px 16px;margin-bottom:24px;}
  .sum-hero h1{font-size:24px;}
  .sum-container{padding:0 16px 40px;}
  .sum-grid{grid-template-columns:1fr;gap:16px;}
}
</style>
</head>
<body class="nf-redesign">
<?php
$activeType = 'summaries';
$showTicker = false;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="sum-hero">
  <div class="sum-hero-inner">
    <h1>☕ ملخصات نيوز فيد</h1>
    <p>أحدث ملخص في كل قسم — موجز الصباح، مراجعة الأسبوع، تلغرام، X، ويوتيوب — في مكان واحد. اضغط على <b>قراءة</b> لفتح الموجز كاملاً، أو <b>تصفح الأرشيف</b> لرؤية كل الملخصات السابقة.</p>
  </div>
</div>

<div class="sum-container">
  <?php if (empty($cards)): ?>
    <div class="sum-empty">لا توجد ملخصات منشورة في أي قسم بعد. ستظهر هنا حال صدور أول موجز.</div>
  <?php else: ?>
    <div class="sum-grid">
      <?php foreach ($cards as $c): ?>
        <article class="sum-card">
          <span class="sum-card-eyebrow">
            <span class="dot" style="background:<?php echo e($c['color']); ?>"></span>
            <?php echo e($c['label']); ?>
          </span>
          <h3 class="sum-card-title"><?php echo e($c['title']); ?></h3>
          <?php if (!empty($c['when'])): ?>
            <div class="sum-card-when"><?php echo e($c['when']); ?></div>
          <?php endif; ?>
          <?php if (!empty($c['snippet'])): ?>
            <p class="sum-card-snippet"><?php echo e($c['snippet']); ?>…</p>
          <?php endif; ?>
          <div class="sum-card-actions">
            <a class="sum-act sum-act-primary" href="<?php echo e($c['url_read']); ?>">📖 قراءة الموجز كاملاً</a>
            <a class="sum-act" href="<?php echo e($c['url_print']); ?>" target="_blank" rel="noopener" title="فتح في تبويب جديد للمعاينة">👁 معاينة</a>
            <a class="sum-act" href="<?php echo e($c['url_print']); ?>" target="_blank" rel="noopener" title="افتح ثم اختر &laquo;Save as PDF&raquo; من نافذة الطباعة">⬇ تنزيل PDF</a>
          </div>
          <a class="sum-archive" href="<?php echo e($c['archive']); ?>">📚 تصفح أرشيف <?php echo e($c['label']); ?> ›</a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<footer>
  <div class="footer-bottom" style="max-width:1400px;margin:0 auto;padding:20px 24px;text-align:center;">
    <span>© <?php echo date('Y'); ?> نيوز فيد — جميع الحقوق محفوظة</span>
  </div>
</footer>

</body>
</html>
