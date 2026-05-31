<?php
/**
 * نيوز فيد — التقرير اليومي من قنوات تيليغرام
 *
 * URL: /tg-report          → latest report
 *      /tg-report/123      → specific report by id
 *
 * Generated once per day at 22:00 Jerusalem time by cron_tg_summary.php.
 * Pulls every Telegram message from the last 24h, dedupes across
 * channels, prioritizes Palestinian coverage (80% of sections),
 * renders here with v2 fields (subheadline, key_numbers, regions,
 * why_matters per section) plus print/PDF export.
 *
 * Same visual language as /sabah so users feel at home.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/ai_helper.php';

$viewer    = current_user();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();

// Pick the report — by id from path or query, otherwise the latest.
$requestId = (int)($_GET['id'] ?? 0);
$report    = null;
if ($requestId > 0) {
    $report = tg_summary_get_by_id($requestId);
}
if (!$report) {
    $report = tg_summary_get_latest();
}

// Recent reports for the archive pills (last ~30 days, one per day).
$archive = [];
try {
    $db = getDB();
    $rows = $db->query("SELECT id, headline, generated_at,
                               UNIX_TIMESTAMP(generated_at) AS generated_at_unix
                          FROM telegram_summaries
                         ORDER BY generated_at DESC
                         LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $archive[] = $r;
    }
} catch (Throwable $e) {}

$headline = $report ? (string)$report['headline'] : 'التقرير اليومي من تيليغرام';
$pageUrl  = SITE_URL . '/tg-report' . ($report ? ('/' . (int)$report['id']) : '');
$metaDesc = $report
    ? mb_substr((string)$report['summary'], 0, 160)
    : 'التقرير اليومي من قنوات تيليغرام — ملخص شامل بتركيز فلسطيني يصدر مساء كل يوم.';

// Localized Arabic date.
$dateAr = '';
if ($report) {
    $ts = is_numeric($report['generated_at'])
        ? (int)$report['generated_at']
        : strtotime((string)$report['generated_at']);
    if ($ts > 0) {
        $days   = ['الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
        $months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
        $dateAr = $days[date('w', $ts)] . '، ' . date('j', $ts) . ' ' . $months[date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }
}

// Helper: turn a generated_at into "10:23 م" for a chip.
function _tgr_time_chip(?string $iso): string {
    if (!$iso) return '';
    $ts = strtotime($iso);
    if ($ts <= 0) return '';
    $h = (int)date('H', $ts);
    $m = (int)date('i', $ts);
    $period = $h >= 12 ? 'م' : 'ص';
    $h12 = $h % 12; if ($h12 === 0) $h12 = 12;
    return sprintf('%d:%02d %s', $h12, $m, $period);
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title>📡 <?php echo e($headline); ?> — التقرير اليومي | <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<link rel="canonical" href="<?php echo e($pageUrl); ?>">
<meta property="og:title" content="<?php echo e($headline); ?>">
<meta property="og:description" content="<?php echo e($metaDesc); ?>">
<meta property="og:type" content="article">
<?php include __DIR__ . '/includes/components/pwa_head.php'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"></noscript>
<style>
:root{--bg:#F2EEE8;--bg2:#F7F3ED;--card:#fff;--border:#DDD5C7;--accent2:#3D5A28;--gold:#0EA5E9;--gold2:#7DD3FC;--gold-bg:#E0F2FE;--gold-text:#075985;--text:#2C2416;--muted:#7A6E5D}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif;background:var(--bg);color:var(--text);line-height:1.7}
a{text-decoration:none;color:inherit}
.container{max-width:820px;margin:0 auto;padding:0 24px}

.tgr-hero{
  background:linear-gradient(135deg,#E0F2FE 0%,#BAE6FD 50%,#7DD3FC 100%);
  border:1px solid var(--gold2);border-radius:20px;
  padding:36px 32px;margin:28px 0;
  box-shadow:0 4px 24px -10px rgba(14,165,233,.25);
}
.tgr-eyebrow{
  display:inline-flex;align-items:center;gap:8px;
  background:var(--gold-bg);color:var(--gold-text);
  border:1px solid var(--gold2);padding:6px 16px;
  border-radius:999px;font-size:12px;font-weight:800;margin-bottom:16px;
}
.tgr-headline{font-size:30px;font-weight:900;line-height:1.45;margin-bottom:14px;color:#0C4A6E}
.tgr-subhead{font-size:17px;font-weight:500;line-height:1.7;color:#0369A1;margin-top:8px}
.tgr-date{font-size:14px;color:#0369A1;font-weight:700;margin-top:14px}

.tgr-actions{display:flex;gap:10px;margin:18px 0;flex-wrap:wrap}
.tgr-actions button,.tgr-actions a{
  display:inline-flex;align-items:center;gap:8px;
  padding:10px 18px;border-radius:10px;font-size:13px;font-weight:800;
  background:var(--gold);color:#fff;border:none;cursor:pointer;transition:all .2s;
}
.tgr-actions button:hover,.tgr-actions a:hover{background:#0284C7;transform:translateY(-1px)}

.tgr-hook{
  font-size:17px;line-height:1.85;color:var(--text);
  margin:24px 0;padding:20px 24px;
  background:var(--card);border-radius:14px;border:1px solid var(--border);
  border-right:4px solid var(--gold);
  box-shadow:0 1px 4px rgba(0,0,0,.03);
}

.tgr-numbers{margin:24px 0}
.tgr-numbers-title{font-size:13px;font-weight:800;color:var(--gold-text);margin-bottom:10px;letter-spacing:.5px}
.tgr-numbers-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}
.tgr-numbers-grid .stat{
  background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px;
}
.tgr-numbers-grid .stat .v{font-size:22px;font-weight:900;color:var(--gold);margin-bottom:4px}
.tgr-numbers-grid .stat .c{font-size:12.5px;color:#5C5240;line-height:1.5}

.tgr-section{
  background:var(--card);border:1px solid var(--border);border-radius:14px;
  padding:22px 24px;margin-bottom:16px;
  box-shadow:0 1px 3px rgba(0,0,0,.03);
}
.tgr-section h3{font-size:18px;font-weight:800;margin-bottom:12px;display:flex;align-items:center;gap:10px;color:var(--text)}
.tgr-section h3 .icon{font-size:24px}
.tgr-section ul{list-style:none;padding:0;margin:0}
.tgr-section ul li{
  font-size:15px;line-height:1.85;color:#4A4030;
  padding:8px 0 8px 18px;position:relative;
  border-bottom:1px dashed var(--border);
}
.tgr-section ul li:last-child{border-bottom:none}
.tgr-section ul li::before{
  content:"";position:absolute;right:0;top:14px;
  width:8px;height:8px;border-radius:50%;background:var(--gold);
}
.tgr-why{
  background:var(--gold-bg);border-right:3px solid var(--gold);border-radius:8px;
  padding:10px 14px;margin-top:14px;font-size:13.5px;line-height:1.6;color:var(--gold-text);
}
.tgr-why b{color:#0C4A6E}

.tgr-topics{display:flex;flex-wrap:wrap;gap:6px;margin:16px 0}
.tgr-topics span{
  display:inline-block;
  font-size:12px;font-weight:700;padding:5px 12px;border-radius:6px;
  background:var(--card);border:1px solid var(--border);color:var(--text);
}

.tgr-archive{margin:32px 0 48px}
.tgr-archive h3{font-size:16px;font-weight:800;margin-bottom:14px}
.tgr-archive-pills{display:flex;flex-wrap:wrap;gap:8px}
.tgr-archive-pills a{
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 14px;border-radius:10px;font-size:12px;font-weight:700;
  background:var(--card);border:1px solid var(--border);color:var(--text);transition:all .2s;
}
.tgr-archive-pills a:hover{background:var(--gold);color:#fff;border-color:var(--gold)}
.tgr-archive-pills a.active{background:var(--gold);color:#fff;border-color:var(--gold)}

.empty-state{text-align:center;padding:80px 20px;color:var(--muted)}
.empty-state h3{font-size:20px;margin-bottom:8px;color:var(--text);font-weight:800}

@media(max-width:640px){.tgr-headline{font-size:22px}.tgr-hero{padding:24px 18px}.container{padding:0 16px}}

/* Print / PDF — clean printable layout. */
@media print{
  body{background:#fff;color:#000;font-size:12pt}
  .site-header,.site-footer,.tgr-archive,.tgr-actions,nav,header,footer,
  .pwa-prompt,.cookie-bar,script,.no-print,.pdf-preview-bar{display:none !important}
  .container{max-width:100% !important;padding:0 !important}
  .tgr-hero{
    background:#E0F2FE !important;border:1px solid #0EA5E9 !important;
    border-radius:8px !important;page-break-inside:avoid;
  }
  .tgr-section,.tgr-numbers .stat{
    page-break-inside:avoid;box-shadow:none !important;
  }
  .tgr-headline{font-size:22pt !important}
  .tgr-subhead{font-size:13pt !important}
  a{color:#000 !important;text-decoration:none !important}
}

/* PDF preview mode — paper-like rendering before saving. */
body.pdf-preview{background:#9CA3AF !important}
body.pdf-preview .site-header,
body.pdf-preview .tgr-archive,
body.pdf-preview .tgr-actions,
body.pdf-preview header,
body.pdf-preview nav,
body.pdf-preview footer,
body.pdf-preview .pwa-prompt,
body.pdf-preview .cookie-bar{display:none !important}
body.pdf-preview .container{
  max-width:794px !important;
  background:#fff;
  margin:80px auto 40px;
  padding:48px 56px !important;
  box-shadow:0 8px 32px rgba(0,0,0,.25);
  border-radius:4px;
  min-height:1000px;
}
.pdf-preview-bar{
  display:none;position:fixed;top:0;left:0;right:0;z-index:9999;
  background:#1F2937;color:#fff;padding:12px 24px;
  align-items:center;gap:12px;flex-wrap:wrap;
  box-shadow:0 2px 12px rgba(0,0,0,.4);
}
body.pdf-preview .pdf-preview-bar{display:flex}
.pdf-preview-bar .label{font-size:14px;font-weight:700;margin-inline-end:auto}
.pdf-preview-bar button{
  background:var(--gold);color:#fff;border:none;
  padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;
  cursor:pointer;display:inline-flex;align-items:center;gap:6px;
}
.pdf-preview-bar button:hover{background:#0284C7}
.pdf-preview-bar button.secondary{background:transparent;border:1px solid rgba(255,255,255,.3)}
.pdf-preview-bar button.secondary:hover{background:rgba(255,255,255,.1)}
</style>
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<link rel="stylesheet" href="assets/css/user.min.css?v=m1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
</head>
<body>

<?php
$activeType = 'tg-report';
$activeSlug = '';
$showTicker = false;
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;
include __DIR__ . '/includes/components/site_header.php';
?>

<!-- PDF preview top bar — only visible when body.pdf-preview is active. -->
<div class="pdf-preview-bar">
  <span class="label">👁️ معاينة PDF — هكذا سيظهر الملف عند الحفظ</span>
  <button onclick="window.print()">📄 حفظ PDF</button>
  <button class="secondary" onclick="tgrHidePreview()">✕ إغلاق المعاينة</button>
</div>

<div class="container">

<?php if (!$report): ?>
  <div class="empty-state" style="margin-top:40px">
    <div style="font-size:56px;margin-bottom:18px">📡</div>
    <h3>لم يصدر التقرير اليومي بعد</h3>
    <p>التقرير يصدر مساء كل يوم في الساعة 10 بتوقيت القدس. عد لاحقاً.</p>
  </div>
<?php else: ?>

  <?php
    $sections    = is_array($report['sections']    ?? null) ? $report['sections']    : [];
    $topics      = is_array($report['topics']      ?? null) ? $report['topics']      : [];
    $keyNumbers  = is_array($report['key_numbers'] ?? null) ? $report['key_numbers'] : [];
    $regions     = is_array($report['regions']     ?? null) ? $report['regions']     : [];
    $subheadline = (string)($report['subheadline'] ?? '');
  ?>

  <div class="tgr-hero">
    <span class="tgr-eyebrow">📡 التقرير اليومي من تيليغرام</span>
    <h1 class="tgr-headline"><?php echo e($report['headline']); ?></h1>
    <?php if ($subheadline !== ''): ?>
      <div class="tgr-subhead"><?php echo e($subheadline); ?></div>
    <?php endif; ?>
    <div class="tgr-date">
      <?php echo e($dateAr); ?> • <?php echo _tgr_time_chip($report['generated_at']); ?>
      <?php if (!empty($report['message_count'])): ?>
        • مبني على <?php echo (int)$report['message_count']; ?> رسالة
      <?php endif; ?>
      <?php if (!empty($regions)): ?>
        • <?php echo e(implode(' • ', $regions)); ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="tgr-actions no-print">
    <button onclick="tgrShowPreview()" title="معاينة قبل التحميل">
      👁️ معاينة PDF
    </button>
    <button onclick="window.print()" title="حفظ كـ PDF مباشرة">
      📄 حفظ كـ PDF
    </button>
    <button onclick="if(navigator.share){navigator.share({title:document.title,url:location.href})}else{navigator.clipboard.writeText(location.href);this.textContent='✓ تم نسخ الرابط'}" style="background:#3D5A28">
      🔗 مشاركة
    </button>
  </div>

  <div class="tgr-hook">
    <?php echo nl2br(e($report['summary'])); ?>
  </div>

  <?php if (!empty($keyNumbers)): ?>
  <div class="tgr-numbers">
    <div class="tgr-numbers-title">📊 أرقام اليوم</div>
    <div class="tgr-numbers-grid">
      <?php foreach ($keyNumbers as $n): if (!is_array($n)) continue; ?>
        <div class="stat">
          <div class="v"><?php echo e((string)($n['value'] ?? '')); ?></div>
          <div class="c"><?php echo e((string)($n['context'] ?? '')); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php foreach ($sections as $sec): if (!is_array($sec) || empty($sec['items'])) continue; ?>
  <div class="tgr-section">
    <h3>
      <span class="icon"><?php echo e((string)($sec['icon'] ?? '📰')); ?></span>
      <span><?php echo e((string)($sec['title'] ?? '')); ?></span>
    </h3>
    <ul>
      <?php foreach ((array)$sec['items'] as $it): ?>
        <li><?php echo e((string)$it); ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if (!empty($sec['why_matters'])): ?>
      <div class="tgr-why">
        💡 <b>لماذا يهمّك؟</b> <?php echo e((string)$sec['why_matters']); ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <?php if (!empty($topics)): ?>
  <div class="tgr-topics">
    <?php foreach ($topics as $t): if (!is_string($t) || $t === '') continue; ?>
      <span>#<?php echo e($t); ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php endif; ?>

  <?php if (!empty($archive)): ?>
  <div class="tgr-archive">
    <h3>📅 أرشيف التقارير اليومية</h3>
    <div class="tgr-archive-pills">
      <?php foreach ($archive as $a):
        $ts = (int)($a['generated_at_unix'] ?? strtotime($a['generated_at']));
        $label = date('Y/m/d', $ts);
        $isActive = $report && (int)$a['id'] === (int)($report['id'] ?? 0);
      ?>
        <a href="/tg-report/<?php echo (int)$a['id']; ?>"
           class="<?php echo $isActive ? 'active' : ''; ?>">
          <?php echo e($label); ?>
          <span style="color:var(--muted);font-size:11px"><?php echo e(mb_substr($a['headline'], 0, 28)); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<script src="assets/js/user.min.js?v=m1" defer></script>
<script>
  function tgrShowPreview() {
    document.body.classList.add('pdf-preview');
    window.scrollTo({top: 0, behavior: 'smooth'});
  }
  function tgrHidePreview() {
    document.body.classList.remove('pdf-preview');
  }
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.body.classList.contains('pdf-preview')) {
      tgrHidePreview();
    }
  });
</script>
</body>
</html>
