<?php
/**
 * نيوزفلو — صفحة نتائج البحث
 * URL: /search?q=...
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';

$viewer    = current_user();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$results = [];
$totalCount = 0;

if (mb_strlen($q) >= 2) {
    $db = getDB();
    $searchTerm = '%' . $q . '%';
    // Count
    $cnt = $db->prepare("SELECT COUNT(*) FROM articles WHERE status='published' AND (title LIKE ? OR excerpt LIKE ?)");
    $cnt->execute([$searchTerm, $searchTerm]);
    $totalCount = (int)$cnt->fetchColumn();
    // Results
    $stmt = $db->prepare("SELECT a.id, a.title, a.slug, a.excerpt, a.image_url, a.published_at,
                          a.view_count, c.name as cat_name, c.slug as cat_slug, c.css_class,
                          s.name as source_name
                          FROM articles a
                          LEFT JOIN categories c ON a.category_id = c.id
                          LEFT JOIN sources s ON a.source_id = s.id
                          WHERE a.status='published' AND (a.title LIKE ? OR a.excerpt LIKE ?)
                          ORDER BY a.published_at DESC LIMIT 50");
    $stmt->execute([$searchTerm, $searchTerm]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Pre-fetch bookmarks
$GLOBALS['__nf_saved_ids'] = [];
if ($viewerId && !empty($results)) {
    $ids = array_map(fn($a) => (int)$a['id'], $results);
    $GLOBALS['__nf_saved_ids'] = array_flip(user_bookmark_ids_for($viewerId, $ids));
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title>بحث: <?php echo e($q); ?> — <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"></noscript>
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m2">
<link rel="stylesheet" href="assets/css/home.min.css?v=m2">
<link rel="stylesheet" href="assets/css/user.min.css?v=m1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<style>
  body { font-family:'Tajawal','Segoe UI',Tahoma,sans-serif; background:var(--bg,#faf6ec); color:var(--text,#1a1a2e); }
  .sr-container { max-width:900px; margin:0 auto; padding:0 20px; }
  .sr-header { padding:28px 0 20px; }
  .sr-header h1 { font-size:24px; font-weight:900; margin-bottom:6px; }
  .sr-header p { font-size:14px; color:var(--muted,#6b7280); }
  .sr-header mark { background:rgba(245,158,11,.2); color:inherit; padding:0 4px; border-radius:4px; }
  .sr-results { display:flex; flex-direction:column; gap:12px; margin-bottom:48px; }
  .sr-card {
    display:flex; gap:16px; padding:16px; background:var(--card,#fff);
    border:1px solid var(--border,#e0e3e8); border-radius:14px;
    transition:all .25s; text-decoration:none; color:inherit;
    box-shadow:0 1px 3px rgba(0,0,0,.04); position:relative;
  }
  .sr-card:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,.08); border-color:rgba(26,115,232,.2); }
  .sr-card img { width:160px; height:110px; border-radius:10px; object-fit:cover; flex-shrink:0; background:#e5e7eb; }
  .sr-card-body { flex:1; min-width:0; display:flex; flex-direction:column; }
  .sr-card-cat { display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700; color:#fff; margin-bottom:6px; width:fit-content; }
  .sr-card-title { font-size:16px; font-weight:800; line-height:1.5; margin-bottom:6px; }
  .sr-card-excerpt { font-size:13px; color:var(--muted,#6b7280); line-height:1.7; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  .sr-card-meta { font-size:12px; color:var(--muted,#6b7280); margin-top:auto; padding-top:8px; display:flex; gap:8px; align-items:center; }
  .sr-empty { text-align:center; padding:60px 20px; }
  .sr-empty .icon { font-size:48px; margin-bottom:12px; }
  .sr-empty h2 { font-size:20px; margin-bottom:8px; }
  .sr-empty p { color:var(--muted,#6b7280); font-size:14px; }
  @media(max-width:640px) {
    .sr-card { flex-direction:column; }
    .sr-card img { width:100%; height:180px; }
  }
</style>
</head>
<body>

<?php
$activeType = '';
$activeSlug = '';
$showTicker = false;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="sr-container">
  <div class="sr-header">
    <?php if ($q): ?>
      <h1>🔍 نتائج البحث عن: <mark><?php echo e($q); ?></mark></h1>
      <p><?php echo number_format($totalCount); ?> نتيجة</p>
    <?php else: ?>
      <h1>🔍 البحث</h1>
      <p>اكتب كلمة بحث في الأعلى للبدء</p>
    <?php endif; ?>
  </div>

  <?php if ($q && empty($results)): ?>
    <div class="sr-empty">
      <div class="icon">🔍</div>
      <h2>لا توجد نتائج</h2>
      <p>لم نعثر على نتائج لـ "<?php echo e($q); ?>". جرّب كلمات بحث مختلفة.</p>
    </div>
  <?php elseif (!empty($results)): ?>
    <div class="sr-results">
      <?php foreach ($results as $a):
        $aUrl = articleUrl($a);
      ?>
        <a class="sr-card" href="<?php echo e($aUrl); ?>">
          <?php if (!empty($a['image_url'])): ?>
            <img src="<?php echo e($a['image_url']); ?>" alt="" loading="lazy" decoding="async">
          <?php endif; ?>
          <div class="sr-card-body">
            <?php if (!empty($a['cat_name'])): ?>
              <span class="sr-card-cat <?php echo e($a['css_class'] ?? ''); ?>"><?php echo e($a['cat_name']); ?></span>
            <?php endif; ?>
            <h3 class="sr-card-title"><?php echo e($a['title']); ?></h3>
            <?php if (!empty($a['excerpt'])): ?>
              <p class="sr-card-excerpt"><?php echo e(mb_substr($a['excerpt'], 0, 200)); ?></p>
            <?php endif; ?>
            <div class="sr-card-meta">
              <span><?php echo e($a['source_name'] ?? ''); ?></span>
              <span>·</span>
              <span><?php echo timeAgo($a['published_at']); ?></span>
              <span>·</span>
              <span>👁 <?php echo formatViews($a['view_count']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script src="assets/js/user.min.js?v=m2" defer></script>
</body>
</html>
