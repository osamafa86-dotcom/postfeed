<?php
/**
 * نيوز فيد — صفحة موجز الصباح (Morning Briefing page)
 *
 * URL: /sabah           → latest briefing
 *      /sabah/2026-04-12 → specific date
 *
 * NYT "The Morning"-inspired daily editorial page: one hook essay,
 * thematic sections with context, and a closing question. Permanent
 * archive — every day gets its own URL.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/sabah.php';

$viewer    = current_user();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();

// Parse date from URL or use latest.
$requestDate = trim((string)($_GET['date'] ?? ''));
if ($requestDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestDate)) {
    $briefing = sabah_get($requestDate);
} else {
    $briefing = sabah_get_latest();
    $requestDate = $briefing ? (string)$briefing['briefing_date'] : date('Y-m-d');
}

$archive = sabah_list(14);

$headline = $briefing ? (string)$briefing['headline'] : 'موجز الصباح';
$pageUrl  = SITE_URL . '/sabah' . ($requestDate ? '/' . $requestDate : '');
$metaDesc = $briefing ? mb_substr((string)$briefing['hook'], 0, 160) : 'موجز الصباح اليومي من نيوز فيد — أبرز ما حدث في العالم العربي بأسلوب صباحي.';

// Format date in Arabic.
$dateAr = '';
if ($requestDate) {
    $ts = strtotime($requestDate);
    $days = ['الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
    $months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
    $dateAr = $days[date('w', $ts)] . '، ' . date('j', $ts) . ' ' . $months[date('n', $ts) - 1] . ' ' . date('Y', $ts);
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title>☀️ <?php echo e($headline); ?> — موجز الصباح | <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
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
:root{--bg:#F2EEE8;--bg2:#F7F3ED;--card:#fff;--border:#DDD5C7;--accent2:#3D5A28;--gold:#C99624;--gold2:#E2C264;--gold-bg:#F5EBCE;--gold-text:#6B4F0B;--text:#2C2416;--muted:#7A6E5D}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif;background:var(--bg);color:var(--text);line-height:1.7}
a{text-decoration:none;color:inherit}
.container{max-width:820px;margin:0 auto;padding:0 24px}
.sabah-hero{
  background:linear-gradient(135deg,#F8F0DD 0%,#F5EBCE 50%,#F5EBCE 100%);
  border:1px solid var(--gold2);border-radius:20px;
  padding:36px 32px;margin:28px 0;
  box-shadow:0 4px 24px -10px rgba(245,158,11,.2);
}
.sabah-eyebrow{
  display:inline-flex;align-items:center;gap:8px;
  background:var(--gold-bg);color:var(--gold-text);
  border:1px solid var(--gold2);padding:6px 16px;
  border-radius:999px;font-size:12px;font-weight:800;margin-bottom:16px;
}
.sabah-headline{font-size:30px;font-weight:900;line-height:1.45;margin-bottom:14px}
.sabah-date{font-size:14px;color:var(--muted);font-weight:600}
.sabah-hook{
  font-size:17px;line-height:1.85;color:var(--text);
  margin:24px 0;padding:20px 24px;
  background:var(--card);border-radius:14px;border:1px solid var(--border);
  box-shadow:0 1px 4px rgba(0,0,0,.03);
}
.sabah-section{
  background:var(--card);border:1px solid var(--border);border-radius:14px;
  padding:22px 24px;margin-bottom:16px;
  box-shadow:0 1px 3px rgba(0,0,0,.03);
}
.sabah-section h3{font-size:17px;font-weight:800;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.sabah-section p{font-size:15px;line-height:1.85;color:#4A4030}
.sabah-closing{
  text-align:center;padding:28px 24px;margin:24px 0;
  background:linear-gradient(135deg,#f0fdfa,#ecfdf5);
  border:1px solid rgba(61,90,40,.2);border-radius:16px;
  font-size:18px;font-weight:800;color:#2D4520;line-height:1.6;
}
.sabah-archive{margin:32px 0 48px}
.sabah-archive h3{font-size:16px;font-weight:800;margin-bottom:14px}
.sabah-archive-pills{display:flex;flex-wrap:wrap;gap:8px}
.sabah-archive-pills a{
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 14px;border-radius:10px;font-size:12px;font-weight:700;
  background:var(--card);border:1px solid var(--border);color:var(--text);transition:all .2s;
}
.sabah-archive-pills a:hover{background:var(--accent2);color:#fff;border-color:var(--accent2)}
.sabah-archive-pills a.active{background:var(--accent2);color:#fff;border-color:var(--accent2)}
.empty-state{text-align:center;padding:80px 20px;color:var(--muted)}
.empty-state h3{font-size:20px;margin-bottom:8px;color:var(--text);font-weight:800}

/* v2 fields */
.sabah-subhead{font-size:17px;font-weight:500;line-height:1.7;margin-top:8px;color:#5C5240}
.sabah-actions{display:flex;gap:10px;margin:18px 0;flex-wrap:wrap}
.sabah-actions button,.sabah-actions a{
  display:inline-flex;align-items:center;gap:8px;
  padding:10px 18px;border-radius:10px;font-size:13px;font-weight:800;
  background:var(--gold);color:#fff;border:none;cursor:pointer;transition:all .2s;
}
.sabah-actions button:hover,.sabah-actions a:hover{background:#A37516;transform:translateY(-1px)}
.sabah-numbers{margin:24px 0}
.sabah-numbers-title{font-size:13px;font-weight:800;color:var(--gold-text);margin-bottom:10px;letter-spacing:.5px}
.sabah-numbers-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}
.sabah-numbers-grid .stat{
  background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px;
}
.sabah-numbers-grid .stat .v{font-size:22px;font-weight:900;color:var(--gold);margin-bottom:4px}
.sabah-numbers-grid .stat .c{font-size:12.5px;color:#5C5240;line-height:1.5}
.sabah-regions{margin:6px 0 0;font-size:13px;color:var(--muted)}
.sabah-regions strong{color:var(--accent2)}
.sabah-why{
  background:#FEF3C7;border-right:3px solid var(--gold);border-radius:8px;
  padding:10px 14px;margin-top:12px;font-size:13.5px;line-height:1.6;color:#6B4F0B;
}
.sabah-why b{color:var(--gold-text)}
.sabah-tags{display:flex;flex-wrap:wrap;gap:5px;margin-top:10px}
.sabah-tags span{
  font-size:11px;font-weight:700;padding:3px 9px;border-radius:5px;
  background:rgba(201,150,36,.08);color:var(--gold-text);
}
.sabah-quote{
  background:linear-gradient(135deg,#F1F5F9,#E2E8F0);border-radius:14px;
  padding:24px;margin:24px 0;border-right:4px solid var(--gold);
}
.sabah-quote .q-label{font-size:12px;font-weight:800;color:var(--gold-text);margin-bottom:10px;letter-spacing:.5px}
.sabah-quote .q-text{font-size:16px;font-style:italic;line-height:1.85;margin-bottom:12px;color:#2C2416}
.sabah-quote .q-speaker{font-size:13px;font-weight:800;color:#374151}
.sabah-quote .q-context{font-size:11.5px;color:#6B7280;margin-top:3px}

@media(max-width:640px){.sabah-headline{font-size:22px}.sabah-hero{padding:24px 18px}.container{padding:0 16px}}

/* Print / PDF — give users a clean printable version that browsers can
   save directly as PDF via the standard print dialog. */
@media print{
  body{background:#fff;color:#000;font-size:12pt}
  .site-header,.site-footer,.sabah-archive,.sabah-actions,nav,header,footer,
  .pwa-prompt,.cookie-bar,script,.no-print,.pdf-preview-bar{display:none !important}
  .container{max-width:100% !important;padding:0 !important}
  .sabah-hero{
    background:#FEF3C7 !important;border:1px solid #D97706 !important;
    border-radius:8px !important;page-break-inside:avoid;
  }
  .sabah-section,.sabah-quote,.sabah-numbers .stat{
    page-break-inside:avoid;box-shadow:none !important;
  }
  .sabah-headline{font-size:22pt !important}
  .sabah-subhead{font-size:13pt !important}
  a{color:#000 !important;text-decoration:none !important}
}

/* PDF preview mode — toggled with body.pdf-preview. Shows the page in
   a clean, paper-like format so users can see exactly how the PDF will
   look before saving. Hides everything except the briefing + a fixed
   action bar at the top. */
body.pdf-preview{background:#9CA3AF !important}
body.pdf-preview .site-header,
body.pdf-preview .sabah-archive,
body.pdf-preview .sabah-actions,
body.pdf-preview header,
body.pdf-preview nav,
body.pdf-preview footer,
body.pdf-preview .pwa-prompt,
body.pdf-preview .cookie-bar{display:none !important}
body.pdf-preview .container{
  max-width:794px !important;     /* ~A4 width at 96dpi */
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
  display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  box-shadow:0 2px 12px rgba(0,0,0,.4);
}
body.pdf-preview .pdf-preview-bar{display:flex}
.pdf-preview-bar .label{font-size:14px;font-weight:700;margin-inline-end:auto}
.pdf-preview-bar button{
  background:var(--gold);color:#fff;border:none;
  padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;
  cursor:pointer;display:inline-flex;align-items:center;gap:6px;
}
.pdf-preview-bar button:hover{background:#A37516}
.pdf-preview-bar button.secondary{background:transparent;border:1px solid rgba(255,255,255,.3)}
.pdf-preview-bar button.secondary:hover{background:rgba(255,255,255,.1)}
</style>
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<link rel="stylesheet" href="assets/css/user.min.css?v=m1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
</head>
<body>

<?php
$activeType = 'sabah';
$activeSlug = '';
$showTicker = false;
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;
include __DIR__ . '/includes/components/site_header.php';
?>

<!-- PDF preview top bar — only visible when body.pdf-preview is active.
     Lets the user save or share without leaving the preview, or back
     out of it. Kept outside .container so it stays fixed across scroll. -->
<div class="pdf-preview-bar">
  <span class="label">👁️ معاينة PDF — هكذا سيظهر الملف عند الحفظ</span>
  <button onclick="window.print()">📄 حفظ PDF</button>
  <button class="secondary" onclick="sabahHidePreview()">✕ إغلاق المعاينة</button>
</div>

<div class="container">

<?php if (!$briefing): ?>
  <div class="empty-state" style="margin-top:40px">
    <div style="font-size:56px;margin-bottom:18px">☀️</div>
    <h3>لم يُنشر موجز الصباح بعد لهذا اليوم</h3>
    <p>الموجز يُولَّد يومياً في الصباح الباكر. عُد لاحقاً أو تصفّح <a href="/trending" style="color:var(--accent2);font-weight:700">الأكثر تداولاً</a>.</p>
  </div>
<?php else: ?>

  <?php
    // v2 fields — gracefully fall back when an older row is missing them.
    $subheadline = (string)($briefing['subheadline'] ?? '');
    $keyNumbers  = is_array($briefing['key_numbers'] ?? null) ? $briefing['key_numbers'] : [];
    $regionsArr  = is_array($briefing['regions']     ?? null) ? $briefing['regions']     : [];
    $quote       = is_array($briefing['quote_of_day'] ?? null) ? $briefing['quote_of_day'] : null;
  ?>
  <div class="sabah-hero">
    <span class="sabah-eyebrow">☀️ موجز الصباح</span>
    <h1 class="sabah-headline"><?php echo e($briefing['headline']); ?></h1>
    <?php if ($subheadline !== ''): ?>
      <div class="sabah-subhead"><?php echo e($subheadline); ?></div>
    <?php endif; ?>
    <div class="sabah-date" style="margin-top:14px"><?php echo e($dateAr); ?>
      <?php if (!empty($briefing['article_count'])): ?>
        • من <?php echo (int)$briefing['article_count']; ?> خبراً
      <?php endif; ?>
      <?php if (!empty($regionsArr)): ?>
        • <?php echo e(implode(' • ', $regionsArr)); ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="sabah-actions no-print">
    <button onclick="sabahShowPreview()" title="معاينة قبل التحميل">
      👁️ معاينة PDF
    </button>
    <button onclick="window.print()" title="حفظ كـ PDF مباشرة">
      📄 حفظ كـ PDF
    </button>
    <button onclick="if(navigator.share){navigator.share({title:document.title,url:location.href})}else{navigator.clipboard.writeText(location.href);this.textContent='✓ تم نسخ الرابط'}" style="background:#3D5A28">
      🔗 مشاركة
    </button>
  </div>

  <div class="sabah-hook">
    <?php echo nl2br(e($briefing['hook'])); ?>
  </div>

  <?php if (!empty($keyNumbers)): ?>
  <div class="sabah-numbers">
    <div class="sabah-numbers-title">📊 أرقام اليوم</div>
    <div class="sabah-numbers-grid">
      <?php foreach ($keyNumbers as $n): if (!is_array($n)) continue; ?>
        <div class="stat">
          <div class="v"><?php echo e((string)($n['value'] ?? '')); ?></div>
          <div class="c"><?php echo e((string)($n['context'] ?? '')); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php foreach ($briefing['sections'] as $sec): ?>
  <div class="sabah-section">
    <h3><?php echo e($sec['icon'] ?? ''); ?> <?php echo e($sec['title']); ?></h3>
    <p><?php echo nl2br(e($sec['body'])); ?></p>
    <?php if (!empty($sec['why_matters'])): ?>
      <div class="sabah-why">
        💡 <b>لماذا يهمّك؟</b> <?php echo e((string)$sec['why_matters']); ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($sec['tags']) && is_array($sec['tags'])): ?>
      <div class="sabah-tags">
        <?php foreach ($sec['tags'] as $t): if (!is_string($t) || $t === '') continue; ?>
          <span>#<?php echo e($t); ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <?php if ($quote): ?>
  <div class="sabah-quote">
    <div class="q-label">💬 اقتباس اليوم</div>
    <div class="q-text">"<?php echo e((string)($quote['text'] ?? '')); ?>"</div>
    <div class="q-speaker">— <?php echo e((string)($quote['speaker'] ?? '')); ?></div>
    <?php if (!empty($quote['context'])): ?>
      <div class="q-context"><?php echo e((string)$quote['context']); ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($briefing['closing_question'])): ?>
  <div class="sabah-closing">
    💭 <?php echo e($briefing['closing_question']); ?>
  </div>
  <?php endif; ?>

<?php endif; ?>

  <?php if (!empty($archive)): ?>
  <div class="sabah-archive">
    <h3>📅 الأرشيف</h3>
    <div class="sabah-archive-pills">
      <?php foreach ($archive as $a): ?>
        <a href="/sabah/<?php echo e($a['briefing_date']); ?>"
           class="<?php echo ($a['briefing_date'] ?? '') === $requestDate ? 'active' : ''; ?>">
          <?php echo e($a['briefing_date']); ?>
          <span style="color:var(--muted);font-size:11px"><?php echo e(mb_substr($a['headline'], 0, 30)); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<script src="assets/js/user.min.js?v=m1" defer></script>
<script>
  // PDF preview toggle — swaps the page into a paper-like rendering so
  // the user can see exactly how the PDF will look before triggering
  // window.print(). Esc closes the preview.
  function sabahShowPreview() {
    document.body.classList.add('pdf-preview');
    window.scrollTo({top: 0, behavior: 'smooth'});
  }
  function sabahHidePreview() {
    document.body.classList.remove('pdf-preview');
  }
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.body.classList.contains('pdf-preview')) {
      sabahHidePreview();
    }
  });
  // When opened from /summaries with ?print=1, auto-fire the print
  // dialog. The browser's "Save as PDF" target is the standard PDF
  // download path on every modern browser, so the same handler
  // serves preview / download / print actions from the hub.
  (function(){
    var qs = new URLSearchParams(window.location.search);
    if (qs.get('print') === '1') {
      window.addEventListener('load', function() {
        setTimeout(function() { window.print(); }, 600);
      });
    }
  })();
</script>
</body>
</html>
