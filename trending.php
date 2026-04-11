<?php
/**
 * نيوزفلو - الأكثر تداولاً الآن (Trending Now full page)
 *
 * Top 20 stories by velocity. Velocity = (views_last_hour × 4) +
 * views_last_6h, computed in includes/trending.php from the
 * article_view_events log. Aggregated by cluster_key so the page
 * shows distinct stories, not eight rewrites of the same headline.
 *
 * URL: /trending  (rewritten by .htaccess)
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';
require_once __DIR__ . '/includes/trending.php';

$viewer    = current_user();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();

$trendingTop  = trending_get_top(20);
$readersNow   = trending_active_readers();
$totalArticles = countArticles();
$totalSources  = count(getActiveSources());

$pageUrl = SITE_URL . '/trending';
$metaDesc = 'الأكثر تداولاً الآن على نيوزفلو — أعلى الأخبار سرعة في القراءة خلال آخر ساعة وست ساعات.';
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title>🔥 الأكثر تداولاً الآن — <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<link rel="canonical" href="<?php echo e($pageUrl); ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="🔥 الأكثر تداولاً الآن — <?php echo e(getSetting('site_name', SITE_NAME)); ?>">
<meta property="og:description" content="<?php echo e($metaDesc); ?>">
<meta property="og:url" content="<?php echo e($pageUrl); ?>">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#1a5c5c">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"></noscript>
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<link rel="stylesheet" href="assets/css/home.min.css?v=m3">
<link rel="stylesheet" href="assets/css/user.min.css?v=m1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
</head>
<body>

<?php
$activeType = 'trending';
$activeSlug = '';
$showTicker = false;
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;
include __DIR__ . '/includes/components/site_header.php';
?>

<main class="trending-page">
  <div class="trending-page-head">
    <div class="trending-strip-title" style="justify-content:center;">
      <span class="fire-badge" aria-hidden="true">🔥</span>
      <h1>الأكثر تداولاً الآن</h1>
      <span class="trending-live-dot" aria-hidden="true"></span>
      <span class="trending-live-label">مباشر</span>
    </div>
    <p class="sub">
      أخبار ترتفع قراءاتها بسرعة الآن — مرتبة حسب درجة السرعة (آخر ساعة × 4 + آخر 6 ساعات)
      <?php if ($readersNow > 0): ?>
        · <b style="color:#dc2626;"><?php echo number_format($readersNow); ?></b> يقرأ الآن
      <?php endif; ?>
    </p>
  </div>

  <?php if (empty($trendingTop)): ?>
    <div style="text-align:center;padding:50px 20px;color:#64748b;">
      <div style="font-size:48px;margin-bottom:12px;">📊</div>
      <h3 style="margin:0 0 6px;color:#0f172a;">لا توجد بيانات سرعة بعد</h3>
      <p>سيتم تحديث هذه الصفحة فور بدء القراء بمشاهدة المقالات.</p>
      <p style="margin-top:18px;"><a href="index.php" style="color:#1a5c5c;font-weight:700;">↩ العودة للصفحة الرئيسية</a></p>
    </div>
  <?php else: ?>
    <div class="trending-list">
      <?php foreach ($trendingTop as $i => $t):
          $rank = $i + 1;
          $ck   = (string)($t['cluster_key'] ?? '');
          $hasCluster = ($ck !== '' && $ck !== '-' && (int)$t['cluster_size'] > 1);
          $href = $hasCluster
              ? ('cluster.php?key=' . urlencode($ck))
              : articleUrl($t);
          $vel  = (int)$t['velocity_score'];
          $vh   = (int)$t['views_last_hour'];
          $v6   = (int)$t['views_last_6h'];
          $summary = trim((string)($t['ai_summary'] ?? $t['excerpt'] ?? ''));
      ?>
      <a class="trending-row" href="<?php echo e($href); ?>">
        <div class="trending-row-rank">#<?php echo $rank; ?></div>
        <div class="trending-row-thumb" style="background-image:url('<?php echo e($t['image_url'] ?? placeholderImage(400, 250)); ?>');"></div>
        <div class="trending-row-body">
          <h3 class="trending-row-title"><?php echo e($t['title']); ?></h3>
          <?php if ($summary !== ''): ?>
            <p class="trending-row-summary"><?php echo e(mb_substr($summary, 0, 200)); ?></p>
          <?php endif; ?>
          <div class="trending-row-meta">
            <span>⚡ سرعة <b><?php echo number_format($vel); ?></b></span>
            <?php if ($vh > 0): ?><span>⏱ <b><?php echo number_format($vh); ?></b> قراءة/الساعة الأخيرة</span><?php endif; ?>
            <?php if ($v6 > 0): ?><span>🕕 <b><?php echo number_format($v6); ?></b> خلال 6 ساعات</span><?php endif; ?>
            <?php if ($hasCluster): ?><span>📰 <b><?php echo (int)$t['cluster_size']; ?></b> مصادر</span><?php endif; ?>
            <?php if (!empty($t['cat_name'])): ?>
              <span>🏷 <?php echo e($t['cat_name']); ?></span>
            <?php endif; ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<footer>
  <div class="footer-logo"><?php echo e(getSetting('site_name', SITE_NAME)); ?></div>
  <div class="footer-links">
    <a href="index.php">الرئيسية</a>
    <a href="trending.php">الأكثر تداولاً</a>
  </div>
  <div class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo e(getSetting('site_name', SITE_NAME)); ?> &mdash; جميع الحقوق محفوظة</div>
</footer>

<div class="nf-toast" id="nfToast"></div>
<script src="assets/js/user.min.js?v=m1" defer></script>
</body>
</html>
