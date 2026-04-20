<?php
/**
 * نيوز فيد — معرض المشهد اليومي (Daily Photo Gallery)
 *
 * URL: /gallery             → latest gallery
 *      /gallery/2026-04-12  → specific date
 *
 * Guardian-inspired daily photo gallery from ingested RSS images,
 * ranked by story importance (cluster source count). Each photo
 * links back to its article. Auto-generated or on-demand.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/gallery.php';

$viewer    = current_user();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();

$requestDate = trim((string)($_GET['date'] ?? ''));
if ($requestDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestDate)) {
    $gallery = gallery_get($requestDate);
} else {
    $gallery = gallery_get_latest();
    $requestDate = $gallery ? (string)$gallery['gallery_date'] : date('Y-m-d');
}

// Auto-generate for today if not yet built.
if (!$gallery && $requestDate === date('Y-m-d')) {
    $gallery = gallery_build($requestDate);
}

$archive = gallery_list(14);

$headline = $gallery ? (string)$gallery['headline'] : 'المشهد اليومي';
$photos   = $gallery ? (array)$gallery['photos'] : [];
$intro    = $gallery ? (string)($gallery['intro'] ?? '') : '';
$pageUrl  = SITE_URL . '/gallery' . ($requestDate ? '/' . $requestDate : '');
$metaDesc = $intro ?: 'معرض صور اليوم الإخباري — أبرز مشاهد اليوم من مصادر متعدّدة على نيوز فيد.';

// Arabic date.
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
<title>📸 <?php echo e($headline); ?> | <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<link rel="canonical" href="<?php echo e($pageUrl); ?>">
<meta property="og:title" content="<?php echo e($headline); ?>">
<meta property="og:description" content="<?php echo e($metaDesc); ?>">
<?php if (!empty($photos[0]['image_url'])): ?>
<meta property="og:image" content="<?php echo e($photos[0]['image_url']); ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#1a5c5c">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"></noscript>
<style>
:root{--bg:#0f0f0f;--bg2:#1a1a1a;--card:#222;--border:#333;--accent2:#0d9488;--gold:#f59e0b;--text:#e5e5e5;--muted:#9ca3af}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif;background:var(--bg);color:var(--text);line-height:1.6}
a{text-decoration:none;color:inherit}
.container{max-width:1200px;margin:0 auto;padding:0 24px}
.gallery-hero{text-align:center;padding:36px 20px 24px;margin-bottom:20px}
.gallery-eyebrow{
  display:inline-flex;align-items:center;gap:8px;
  background:rgba(245,158,11,.12);color:var(--gold);
  border:1px solid rgba(245,158,11,.3);padding:6px 16px;
  border-radius:999px;font-size:12px;font-weight:800;margin-bottom:16px;
}
.gallery-headline{font-size:30px;font-weight:900;line-height:1.45;color:#fff;margin-bottom:8px}
.gallery-date{font-size:14px;color:var(--muted);font-weight:600}
.gallery-intro{font-size:15px;color:var(--muted);max-width:600px;margin:12px auto 0;line-height:1.7}
.gallery-grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;
  margin-bottom:40px;
}
.gallery-item{
  position:relative;border-radius:14px;overflow:hidden;
  background:var(--card);border:1px solid var(--border);
  transition:transform .3s,box-shadow .3s;
}
.gallery-item:hover{transform:translateY(-4px);box-shadow:0 12px 40px rgba(0,0,0,.5)}
.gallery-item img{
  width:100%;height:260px;object-fit:cover;display:block;
  transition:transform .5s;
}
.gallery-item:hover img{transform:scale(1.05)}
.gallery-item-body{padding:16px 18px}
.gallery-item-title{font-size:15px;font-weight:800;color:#fff;line-height:1.5;margin-bottom:6px}
.gallery-item-title a:hover{color:var(--accent2)}
.gallery-item-caption{font-size:12px;color:var(--muted);line-height:1.65;margin-bottom:8px}
.gallery-item-meta{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--muted)}
.gallery-item-meta .dot{
  width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  color:#fff;font-weight:800;font-size:10px;
}
.gallery-item-badge{
  position:absolute;top:12px;left:12px;
  padding:4px 10px;border-radius:8px;font-size:11px;font-weight:700;
  background:rgba(0,0,0,.7);color:#fff;backdrop-filter:blur(6px);
}
.gallery-archive{margin:32px 0 48px}
.gallery-archive h3{font-size:16px;font-weight:800;margin-bottom:14px;color:#fff}
.gallery-archive-pills{display:flex;flex-wrap:wrap;gap:8px}
.gallery-archive-pills a{
  padding:8px 14px;border-radius:10px;font-size:12px;font-weight:700;
  background:var(--card);border:1px solid var(--border);color:var(--text);transition:all .2s;
}
.gallery-archive-pills a:hover,.gallery-archive-pills a.active{background:var(--accent2);color:#fff;border-color:var(--accent2)}
.empty-state{text-align:center;padding:80px 20px;color:var(--muted)}
.empty-state h3{font-size:20px;margin-bottom:8px;color:#fff;font-weight:800}
@media(max-width:640px){.gallery-grid{grid-template-columns:1fr}.gallery-headline{font-size:22px}}
</style>
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<link rel="stylesheet" href="assets/css/user.min.css?v=m1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
</head>
<body>

<?php
$activeType = 'gallery';
$activeSlug = '';
$showTicker = false;
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="container">

<?php if (empty($photos)): ?>
  <div class="empty-state" style="margin-top:40px">
    <div style="font-size:56px;margin-bottom:18px">📸</div>
    <h3>لا توجد صور كافية لهذا اليوم</h3>
    <p>المعرض يُبنى من صور الأخبار المُستقطَبة عبر RSS. عُد لاحقاً أو تصفّح <a href="/trending" style="color:var(--accent2);font-weight:700">الأكثر تداولاً</a>.</p>
  </div>
<?php else: ?>

  <div class="gallery-hero">
    <span class="gallery-eyebrow">📸 المشهد اليومي</span>
    <h1 class="gallery-headline"><?php echo e($headline); ?></h1>
    <div class="gallery-date"><?php echo e($dateAr); ?> — <?php echo count($photos); ?> صورة</div>
    <?php if ($intro !== ''): ?>
      <p class="gallery-intro"><?php echo e($intro); ?></p>
    <?php endif; ?>
  </div>

  <div class="gallery-grid">
    <?php foreach ($photos as $i => $photo): ?>
    <div class="gallery-item">
      <a href="/article/<?php echo (int)$photo['article_id']; ?>/<?php echo e($photo['slug'] ?? ''); ?>">
        <img src="<?php echo e($photo['image_url']); ?>"
             alt="<?php echo e($photo['title']); ?>"
             loading="<?php echo $i < 4 ? 'eager' : 'lazy'; ?>" decoding="async">
      </a>
      <?php if ((int)($photo['cluster_size'] ?? 0) >= 3): ?>
        <span class="gallery-item-badge">📰 <?php echo (int)$photo['cluster_size']; ?> مصادر</span>
      <?php endif; ?>
      <div class="gallery-item-body">
        <h3 class="gallery-item-title">
          <a href="/article/<?php echo (int)$photo['article_id']; ?>/<?php echo e($photo['slug'] ?? ''); ?>">
            <?php echo e($photo['title']); ?>
          </a>
        </h3>
        <?php if (!empty($photo['caption'])): ?>
          <p class="gallery-item-caption"><?php echo e($photo['caption']); ?></p>
        <?php endif; ?>
        <div class="gallery-item-meta">
          <span class="dot" style="background:<?php echo e($photo['logo_color'] ?? '#0d9488'); ?>">
            <?php echo e(mb_substr($photo['source'] ?? '?', 0, 1)); ?>
          </span>
          <span><?php echo e($photo['source'] ?? ''); ?></span>
          <?php if (!empty($photo['category'])): ?>
            <span>· <?php echo e($photo['category']); ?></span>
          <?php endif; ?>
          <span>· <?php echo timeAgo($photo['published_at'] ?? ''); ?></span>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

  <?php if (!empty($archive)): ?>
  <div class="gallery-archive">
    <h3>📅 الأرشيف</h3>
    <div class="gallery-archive-pills">
      <?php foreach ($archive as $a): ?>
        <a href="/gallery/<?php echo e($a['gallery_date']); ?>"
           class="<?php echo ($a['gallery_date'] ?? '') === $requestDate ? 'active' : ''; ?>">
          <?php echo e($a['gallery_date']); ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<script src="assets/js/user.min.js?v=m1" defer></script>
</body>
</html>
