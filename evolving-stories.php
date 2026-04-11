<?php
/**
 * نيوزفلو — فهرس القصص المتطوّرة المُعرَّفة من الإدارة
 *
 * The public discovery page for admin-curated persistent stories
 * (أخبار الأقصى، أخبار الأسرى، غزة، الضفة، الاستيطان…).
 *
 * Unlike /timelines which surfaces *auto-generated* AI story
 * timelines (cluster-based, ephemeral), this page surfaces the
 * hand-picked topics the editorial team owns. Each card shows the
 * 3 latest articles from the story plus a live counter.
 *
 * URL: /evolving-stories  (rewritten by .htaccess)
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';
require_once __DIR__ . '/includes/evolving_stories.php';

$viewer     = current_user();
$viewerId   = $viewer ? (int)$viewer['id'] : 0;
$pageTheme  = current_theme();
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;

// Cache for 3 minutes — content is live but not second-by-second.
$stories = cache_remember('evolving_stories_index_v1', 180, function() {
    return evolving_stories_with_previews(4);
});

$totalArticles = 0;
foreach ($stories as $s) $totalArticles += (int)$s['article_count'];

$metaDesc = 'القصص المتطوّرة على نيوزفلو — متابعة متواصلة لأبرز القضايا المركزية مع تغطية من مصادر متعددة.';
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title>القصص المتطوّرة — متابعة دائمة · نيوزفلو</title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<meta property="og:title" content="القصص المتطوّرة — نيوزفلو">
<meta property="og:description" content="<?php echo e($metaDesc); ?>">
<meta property="og:type" content="website">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"></noscript>
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<link rel="stylesheet" href="assets/css/home.min.css?v=m4">
<link rel="stylesheet" href="assets/css/user.min.css?v=m1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<style>
  :root {
    --bg:#faf6ec; --bg2:#fdfaf2; --card:#fff; --border:#e0e3e8;
    --accent2:#0d9488; --text:#1a1a2e; --muted:#6b7280;
  }
  body { background:var(--bg); font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; color:var(--text); }
  .es-container { max-width:1200px; margin:0 auto; padding:0 24px; }

  .es-hero {
    background:linear-gradient(135deg,#fff 0%, #fef3c7 100%);
    border:1px solid rgba(217,119,6,.25); border-radius:20px;
    padding:36px 30px; margin:28px 0 28px;
    box-shadow:0 8px 28px -14px rgba(217,119,6,.22);
    position:relative; overflow:hidden;
  }
  .es-hero::before {
    content:''; position:absolute; inset:0;
    background:radial-gradient(circle at 88% 12%, rgba(13,148,136,.1), transparent 60%);
    pointer-events:none;
  }
  .es-eyebrow {
    display:inline-flex; align-items:center; gap:8px;
    background:rgba(217,119,6,.10); color:#b45309;
    border:1px solid rgba(217,119,6,.28); padding:7px 15px;
    border-radius:999px; font-size:12px; font-weight:800;
    margin-bottom:14px; position:relative;
  }
  .es-eyebrow .live-dot {
    width:8px; height:8px; border-radius:50%; background:#ef4444;
    box-shadow:0 0 0 0 rgba(239,68,68,.5);
    animation:es-pulse 2s infinite;
  }
  @keyframes es-pulse {
    0% { box-shadow:0 0 0 0 rgba(239,68,68,.5); }
    70% { box-shadow:0 0 0 10px rgba(239,68,68,0); }
    100% { box-shadow:0 0 0 0 rgba(239,68,68,0); }
  }
  .es-title { font-size:32px; font-weight:900; line-height:1.4; margin-bottom:10px; position:relative; }
  .es-lede  { font-size:15.5px; line-height:1.85; color:#3a3a52; max-width:800px; position:relative; }
  .es-stats {
    display:flex; flex-wrap:wrap; gap:18px; margin-top:20px;
    font-size:13px; color:var(--muted); font-weight:600; position:relative;
  }
  .es-stats b { color:var(--text); font-weight:800; }

  .es-grid {
    display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr));
    gap:22px; margin-bottom:56px;
  }
  .es-card {
    background:var(--card); border:1px solid var(--border);
    border-radius:18px; overflow:hidden; text-decoration:none; color:inherit;
    display:flex; flex-direction:column;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease;
    box-shadow:0 2px 10px -4px rgba(0,0,0,.06);
    position:relative;
  }
  .es-card:hover {
    transform:translateY(-4px);
    box-shadow:0 18px 40px -20px rgba(13,148,136,.25);
    border-color:rgba(13,148,136,.3);
  }
  .es-card-cover {
    height:180px; background-size:cover; background-position:center;
    background-color:#e5e7eb; position:relative;
  }
  .es-card-cover::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(180deg, rgba(0,0,0,0) 40%, rgba(0,0,0,.82) 100%);
  }
  .es-card-accent {
    position:absolute; top:0; left:0; right:0; height:5px;
  }
  .es-card-header {
    position:absolute; bottom:14px; right:14px; left:14px;
    z-index:2; color:#fff; display:flex; align-items:center; gap:10px;
  }
  .es-card-icon {
    width:44px; height:44px; border-radius:12px;
    background:rgba(255,255,255,.96); display:flex; align-items:center; justify-content:center;
    font-size:24px; flex-shrink:0;
    box-shadow:0 4px 12px rgba(0,0,0,.3);
  }
  .es-card-name { font-size:19px; font-weight:900; line-height:1.3; text-shadow:0 2px 6px rgba(0,0,0,.4); }
  .es-card-count {
    position:absolute; top:12px; left:12px; z-index:2;
    background:rgba(255,255,255,.95); color:#1a1a2e;
    padding:5px 12px; border-radius:999px; font-size:12px; font-weight:800;
    box-shadow:0 2px 8px rgba(0,0,0,.2);
  }
  .es-card-live {
    position:absolute; top:12px; right:12px; z-index:2;
    background:#dc2626; color:#fff;
    padding:5px 11px; border-radius:999px; font-size:11px; font-weight:800;
    display:flex; align-items:center; gap:6px;
    box-shadow:0 2px 8px rgba(220,38,38,.4);
  }
  .es-card-live .dot {
    width:7px; height:7px; border-radius:50%; background:#fff;
    box-shadow:0 0 0 0 rgba(255,255,255,.7);
    animation:es-pulse 2s infinite;
  }

  .es-card-body { padding:18px 18px 16px; display:flex; flex-direction:column; flex:1; }
  .es-card-desc {
    font-size:13px; line-height:1.75; color:var(--muted);
    margin-bottom:14px;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
  }
  .es-latest-list { list-style:none; padding:0; margin:0 0 14px; }
  .es-latest-item {
    display:flex; gap:10px; align-items:flex-start;
    padding:10px 0; border-bottom:1px dashed rgba(0,0,0,.07);
    font-size:13.5px; line-height:1.6;
  }
  .es-latest-item:last-child { border-bottom:none; }
  .es-latest-bullet {
    width:6px; height:6px; border-radius:50%; margin-top:8px; flex-shrink:0;
  }
  .es-latest-title { flex:1; color:var(--text); font-weight:600; }
  .es-latest-time { color:var(--muted); font-size:11.5px; white-space:nowrap; }

  .es-card-foot {
    margin-top:auto; padding-top:12px; border-top:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
    font-size:12.5px; color:var(--muted);
  }
  .es-card-foot .view {
    color:var(--accent2); font-weight:800;
    display:inline-flex; align-items:center; gap:4px;
  }

  .es-empty {
    background:#fff; border:1px solid var(--border); border-radius:16px;
    padding:60px 24px; text-align:center; margin:24px 0 56px;
  }
  .es-empty .icon { font-size:52px; margin-bottom:14px; }
  .es-empty h3 { font-size:19px; margin-bottom:10px; }
  .es-empty p { color:var(--muted); font-size:14px; line-height:1.8; max-width:480px; margin:0 auto; }

  @media(max-width:760px) {
    .es-title { font-size:23px; }
    .es-hero  { padding:24px 20px; }
    .es-grid  { grid-template-columns:1fr; gap:16px; }
    .es-card-cover { height:160px; }
  }
</style>
</head>
<body>

<?php
$activeType = 'evolving';
$activeSlug = '';
$showTicker = false;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="es-container">

  <div class="es-hero">
    <span class="es-eyebrow"><span class="live-dot"></span> متابعة دائمة ومتواصلة</span>
    <h1 class="es-title">📅 القصص المتطوّرة</h1>
    <p class="es-lede">
      قضايا مركزية تُتابعها نيوزفلو على مدار الساعة — يجمع النظام كل خبر جديد حولها
      من مصادر متعددة ويعيد ترتيبه في صفحة واحدة، لتحصل على الصورة الكاملة دون
      مطاردة عشرات الروابط.
    </p>
    <div class="es-stats">
      <span>📅 <b><?php echo number_format(count($stories)); ?></b> قصة قيد المتابعة</span>
      <span>📰 <b><?php echo number_format($totalArticles); ?></b> تقرير مرتبط</span>
      <span>⚙️ تُحدَّث تلقائياً</span>
    </div>
  </div>

  <?php if (empty($stories)): ?>
    <div class="es-empty">
      <div class="icon">📭</div>
      <h3>لا توجد قصص متطوّرة نشطة</h3>
      <p>ستُضاف القصص قريباً من إدارة التحرير، وسيبدأ النظام تلقائياً بتغذيتها من الأخبار الواردة.</p>
    </div>
  <?php else: ?>
    <div class="es-grid">
      <?php foreach ($stories as $st):
        $sUrl   = evolving_story_url($st);
        $sCover = !empty($st['cover_image']) ? $st['cover_image'] : placeholderImage(600, 300);
        $color  = $st['accent_color'] ?: '#0d9488';
      ?>
        <a class="es-card" href="<?php echo e($sUrl); ?>">
          <div class="es-card-cover" style="background-image:url('<?php echo e($sCover); ?>');">
            <div class="es-card-accent" style="background:<?php echo e($color); ?>;"></div>
            <span class="es-card-count">📰 <?php echo number_format($st['article_count']); ?> تقرير</span>
            <?php if (!empty($st['last_matched_at']) && strtotime($st['last_matched_at']) > (time() - 7200)): ?>
              <span class="es-card-live"><span class="dot"></span>مباشر</span>
            <?php endif; ?>
            <div class="es-card-header">
              <div class="es-card-icon"><?php echo e($st['icon'] ?: '📅'); ?></div>
              <div class="es-card-name"><?php echo e($st['name']); ?></div>
            </div>
          </div>
          <div class="es-card-body">
            <?php if (!empty($st['description'])): ?>
              <p class="es-card-desc"><?php echo e($st['description']); ?></p>
            <?php endif; ?>
            <?php if (!empty($st['latest'])): ?>
              <ul class="es-latest-list">
                <?php foreach (array_slice($st['latest'], 0, 3) as $a): ?>
                  <li class="es-latest-item">
                    <span class="es-latest-bullet" style="background:<?php echo e($color); ?>;"></span>
                    <span class="es-latest-title"><?php echo e(mb_substr((string)$a['title'], 0, 85)); ?></span>
                    <span class="es-latest-time"><?php echo e(timeAgo($a['published_at'])); ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <div class="es-card-foot">
              <?php if (!empty($st['last_matched_at']) && $st['last_matched_at'] !== '0000-00-00 00:00:00'): ?>
                <span>↻ آخر تحديث <?php echo e(timeAgo($st['last_matched_at'])); ?></span>
              <?php else: ?>
                <span>في انتظار أول خبر…</span>
              <?php endif; ?>
              <span class="view">متابعة ←</span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<script src="assets/js/user.min.js?v=m1" defer></script>
</body>
</html>
