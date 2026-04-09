<?php
/**
 * Public source profile page
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';

$viewer = current_user();
$viewerId = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();

$db = getDB();

// Auto-migrate
try {
    $cols = $db->query("SHOW COLUMNS FROM sources LIKE 'cover_image'")->fetch();
    if (!$cols) {
        $db->exec("ALTER TABLE sources
            ADD COLUMN cover_image VARCHAR(500) DEFAULT NULL,
            ADD COLUMN description TEXT,
            ADD COLUMN followers_count INT DEFAULT 0");
    }
} catch (Exception $e) {}

$slug = $_GET['slug'] ?? '';
$id = (int)($_GET['id'] ?? 0);

if ($slug) {
    $stmt = $db->prepare("SELECT * FROM sources WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
} elseif ($id) {
    $stmt = $db->prepare("SELECT * FROM sources WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
} else {
    header('Location: index.php');
    exit;
}
$source = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$source) { http_response_code(404); echo 'المصدر غير موجود'; exit; }

$articles = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug FROM articles a LEFT JOIN categories c ON a.category_id = c.id WHERE a.source_id = ? AND a.status='published' ORDER BY a.published_at DESC LIMIT 50");
$articles->execute([$source['id']]);
$articles = $articles->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch saved bookmarks for this page's articles
$GLOBALS['__nf_saved_ids'] = [];
if ($viewerId && !empty($articles)) {
    $__ids = array_map(fn($a) => (int)$a['id'], $articles);
    $GLOBALS['__nf_saved_ids'] = array_flip(user_bookmark_ids_for($viewerId, $__ids));
}

$totalArticles = $db->prepare("SELECT COUNT(*) FROM articles WHERE source_id = ?");
$totalArticles->execute([$source['id']]);
$totalArticles = (int)$totalArticles->fetchColumn();

// Following state: prefer logged-in user's follow list, fall back to cookie for anonymous visitors
if ($viewerId) {
    $isFollowing = in_array((int)$source['id'], user_followed_source_ids($viewerId), true);
} else {
    $followed = isset($_COOKIE['followed_sources']) ? explode(',', $_COOKIE['followed_sources']) : [];
    $isFollowing = in_array((string)$source['id'], $followed, true);
}

// Pre-fetch saved bookmarks for this page's articles
$GLOBALS['__nf_saved_ids'] = [];
if ($viewerId && !empty($articles)) {
    $__ids = array_map(fn($a) => (int)$a['id'], $articles);
    $GLOBALS['__nf_saved_ids'] = array_flip(user_bookmark_ids_for($viewerId, $__ids));
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($source['name']); ?> - <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="description" content="<?php echo e('أحدث الأخبار من ' . $source['name'] . ' على ' . getSetting('site_name', SITE_NAME)); ?>">
<link rel="canonical" href="<?php echo e(SITE_URL . '/source.php?id=' . (int)$source['id']); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Tajawal',sans-serif; background:#faf6ec; color:#1a1a2e; line-height:1.6; }
  a { text-decoration:none; color:inherit; }
  .top-bar { background:#1a1a2e; color:#fff; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; }
  .top-bar .back { color:#fff; background:rgba(255,255,255,.1); padding:8px 16px; border-radius:20px; font-size:13px; }
  .container { max-width:900px; margin:0 auto; padding:20px; }
  .profile-card { background:#fff; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,.06); margin-bottom:24px; }
  .cover {
    height:240px;
    background: <?php echo e($source['logo_bg'] ?? '#5a85b0'); ?>;
    background-image: <?php if(!empty($source['cover_image'])): ?>url('<?php echo e($source['cover_image']); ?>')<?php else: ?>linear-gradient(135deg,<?php echo e($source['logo_bg'] ?? '#5a85b0'); ?>,#1a1a2e)<?php endif; ?>;
    background-size:cover; background-position:center;
    position:relative;
  }
  .profile-body { padding:24px; position:relative; }
  .avatar {
    width:110px; height:110px; border-radius:24px; background:<?php echo e($source['logo_bg'] ?? '#5a85b0'); ?>;
    color:<?php echo e($source['logo_color'] ?? '#fff'); ?>;
    display:flex; align-items:center; justify-content:center;
    font-size:42px; font-weight:900;
    border:5px solid #fff; box-shadow:0 4px 20px rgba(0,0,0,.15);
    margin-top:-80px; margin-bottom:14px;
  }
  .name { font-size:26px; font-weight:900; margin-bottom:6px; }
  .meta { color:#666; font-size:13px; margin-bottom:14px; }
  .stats { display:flex; gap:24px; margin-bottom:20px; }
  .stat strong { display:block; font-size:22px; font-weight:900; color:#1a1a2e; }
  .stat span { font-size:12px; color:#666; }
  .actions { display:flex; gap:10px; flex-wrap:wrap; }
  .btn-follow {
    padding:11px 28px; border-radius:24px; border:0; cursor:pointer;
    font-family:inherit; font-size:14px; font-weight:700;
    background:#1a73e8; color:#fff; transition:all .2s;
  }
  .btn-follow:hover { background:#1557b0; }
  .btn-follow.following { background:#e8f0fe; color:#1a73e8; border:1px solid #1a73e8; }
  .btn-visit {
    padding:11px 22px; border-radius:24px; border:1px solid #e4e6eb;
    background:#fff; color:#1a1a2e; font-weight:600; font-size:14px;
  }
  .description { color:#444; font-size:14px; margin-top:14px; line-height:1.8; }

  .articles-section { background:#fff; border-radius:16px; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,.06); }
  .section-title { font-size:20px; font-weight:800; margin-bottom:20px; padding-right:14px; border-right:4px solid #1a73e8; }
  .article-item {
    display:flex; gap:14px; padding:16px 0; border-bottom:1px solid #f0f2f5;
  }
  .article-item:last-child { border-bottom:0; }
  .article-item img { width:140px; height:90px; object-fit:cover; border-radius:10px; flex-shrink:0; }
  .article-body { flex:1; min-width:0; }
  .article-title { font-size:15px; font-weight:700; margin-bottom:6px; color:#1a1a2e; }
  .article-item:hover .article-title { color:#1a73e8; }
  .article-excerpt { font-size:13px; color:#666; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  .article-meta { font-size:11px; color:#999; margin-top:8px; }

  @media(max-width:600px) {
    .container { padding:12px; }
    .cover { height:160px; }
    .avatar { width:90px; height:90px; font-size:34px; margin-top:-60px; }
    .name { font-size:22px; }
    .article-item img { width:100px; height:70px; }
  }
</style>
<link rel="stylesheet" href="assets/css/user.css?v=11">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
</head>
<body>

<div class="top-bar">
  <a class="back" href="index.php">← العودة للرئيسية</a>
  <strong><?php echo e(getSetting('site_name', SITE_NAME)); ?></strong>
</div>

<div class="container">
  <div class="profile-card">
    <div class="cover"></div>
    <div class="profile-body">
      <div class="avatar"><?php echo e($source['logo_letter'] ?: mb_substr($source['name'], 0, 1)); ?></div>
      <h1 class="name"><?php echo e($source['name']); ?></h1>
      <div class="meta">
        <?php if (!empty($source['url'])): ?>
          <a href="<?php echo e($source['url']); ?>" target="_blank" style="color:#1a73e8;">🔗 <?php echo e(parse_url($source['url'], PHP_URL_HOST)); ?></a>
        <?php endif; ?>
      </div>
      <div class="stats">
        <div class="stat">
          <strong id="followCount"><?php echo number_format((int)$source['followers_count']); ?></strong>
          <span>متابع</span>
        </div>
        <div class="stat">
          <strong><?php echo number_format($totalArticles); ?></strong>
          <span>خبر</span>
        </div>
      </div>
      <div class="actions">
        <button class="btn-follow <?php echo $isFollowing ? 'following' : ''; ?>" id="followBtn" onclick="toggleFollow(<?php echo (int)$source['id']; ?>)">
          <?php echo $isFollowing ? '✓ متابَع' : '+ متابعة'; ?>
        </button>
        <?php if (!empty($source['url'])): ?>
          <a class="btn-visit" href="<?php echo e($source['url']); ?>" target="_blank">زيارة الموقع</a>
        <?php endif; ?>
      </div>
      <?php if (!empty($source['description'])): ?>
        <div class="description"><?php echo nl2br(e($source['description'])); ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="articles-section">
    <h2 class="section-title">📰 آخر أخبار <?php echo e($source['name']); ?></h2>
    <?php if (empty($articles)): ?>
      <p style="text-align:center;color:#999;padding:40px;">لا توجد أخبار من هذا المصدر بعد</p>
    <?php else: ?>
      <?php foreach ($articles as $a): ?>
        <?php $__sid = (int)($a['id'] ?? 0); $__ss = !empty($GLOBALS['__nf_saved_ids']) && isset($GLOBALS['__nf_saved_ids'][$__sid]); ?>
        <a class="article-item" href="<?php echo articleUrl($a); ?>" style="position:relative;">
          <button type="button" class="nf-bookmark-btn <?php echo $__ss ? 'saved' : ''; ?>" title="<?php echo $__ss ? 'إزالة من المحفوظات' : 'حفظ'; ?>" data-save-id="<?php echo $__sid; ?>" onclick="event.preventDefault(); event.stopPropagation(); NF.toggleSave(this)">🔖</button>
          <?php if (!empty($a['image_url'])): ?>
            <img src="<?php echo e($a['image_url']); ?>" alt="" loading="lazy" decoding="async">
          <?php endif; ?>
          <div class="article-body">
            <div class="article-title"><?php echo e($a['title']); ?></div>
            <div class="article-excerpt"><?php echo e(mb_substr($a['excerpt'] ?? '', 0, 160)); ?></div>
            <div class="article-meta">
              <?php if (!empty($a['cat_name'])): ?>📁 <?php echo e($a['cat_name']); ?> · <?php endif; ?>
              <?php echo timeAgo($a['published_at']); ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
async function toggleFollow(sourceId) {
  const btn = document.getElementById('followBtn');
  const isFollowing = btn.classList.contains('following');
  const action = isFollowing ? 'unfollow' : 'follow';
  try {
    const res = await fetch('follow_source.php?id=' + sourceId + '&action=' + action);
    const data = await res.json();
    if (data.ok) {
      btn.classList.toggle('following');
      btn.innerHTML = btn.classList.contains('following') ? '✓ متابَع' : '+ متابعة';
      document.getElementById('followCount').textContent = Number(data.count).toLocaleString('ar-EG');
    }
  } catch(e) { alert('خطأ في الاتصال'); }
}
</script>

<div class="nf-toast" id="nfToast"></div>
<script src="assets/js/user.js?v=4" defer></script>
</body>
</html>
