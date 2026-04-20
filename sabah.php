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
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#1a5c5c">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"></noscript>
<style>
:root{--bg:#faf6ec;--bg2:#fdfaf2;--card:#fff;--border:#e0e3e8;--accent2:#0d9488;--gold:#f59e0b;--gold2:#fcd34d;--gold-bg:#fef3c7;--gold-text:#92400e;--text:#1a1a2e;--muted:#6b7280}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif;background:var(--bg);color:var(--text);line-height:1.7}
a{text-decoration:none;color:inherit}
.container{max-width:820px;margin:0 auto;padding:0 24px}
.sabah-hero{
  background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 50%,#fefce8 100%);
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
.sabah-section p{font-size:15px;line-height:1.85;color:#374151}
.sabah-closing{
  text-align:center;padding:28px 24px;margin:24px 0;
  background:linear-gradient(135deg,#f0fdfa,#ecfdf5);
  border:1px solid rgba(13,148,136,.2);border-radius:16px;
  font-size:18px;font-weight:800;color:#0f766e;line-height:1.6;
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
@media(max-width:640px){.sabah-headline{font-size:22px}.sabah-hero{padding:24px 18px}.container{padding:0 16px}}
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

<div class="container">

<?php if (!$briefing): ?>
  <div class="empty-state" style="margin-top:40px">
    <div style="font-size:56px;margin-bottom:18px">☀️</div>
    <h3>لم يُنشر موجز الصباح بعد لهذا اليوم</h3>
    <p>الموجز يُولَّد يومياً في الصباح الباكر. عُد لاحقاً أو تصفّح <a href="/trending" style="color:var(--accent2);font-weight:700">الأكثر تداولاً</a>.</p>
  </div>
<?php else: ?>

  <div class="sabah-hero">
    <span class="sabah-eyebrow">☀️ موجز الصباح</span>
    <h1 class="sabah-headline"><?php echo e($briefing['headline']); ?></h1>
    <div class="sabah-date"><?php echo e($dateAr); ?></div>
  </div>

  <div class="sabah-hook">
    <?php echo nl2br(e($briefing['hook'])); ?>
  </div>

  <?php foreach ($briefing['sections'] as $sec): ?>
  <div class="sabah-section">
    <h3><?php echo e($sec['icon'] ?? ''); ?> <?php echo e($sec['title']); ?></h3>
    <p><?php echo nl2br(e($sec['body'])); ?></p>
  </div>
  <?php endforeach; ?>

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
</body>
</html>
