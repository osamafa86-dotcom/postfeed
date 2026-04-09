<?php
/**
 * نيوزفلو - صفحة الريلز
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

// Fetch active reels with source info
try {
    $reels = $db->query("
        SELECT r.*, s.display_name as source_name, s.username as source_username, s.avatar_url as source_avatar
        FROM reels r
        LEFT JOIN reels_sources s ON r.source_id = s.id
        WHERE r.is_active = 1
        ORDER BY r.sort_order ASC, r.created_at DESC
    ")->fetchAll();
} catch (Exception $e) {
    $reels = [];
}

// Optional filter by source
$filterSource = isset($_GET['source']) ? (int)$_GET['source'] : 0;
if ($filterSource) {
    $reels = array_filter($reels, fn($r) => (int)$r['source_id'] === $filterSource);
}

try {
    $sources = $db->query("SELECT * FROM reels_sources WHERE is_active = 1 ORDER BY sort_order, display_name")->fetchAll();
} catch (Exception $e) {
    $sources = [];
}

$siteName = getSetting('site_name', SITE_NAME);
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>الريلز - <?php echo e($siteName); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap"></noscript>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Tajawal',sans-serif; background:#faf6ec; color:#1a1a2e; line-height:1.6; }
  a { text-decoration:none; color:inherit; }

  /* Header */
  .reels-header {
    background: linear-gradient(135deg, #833ab4, #fd1d1d, #fcb045);
    padding: 40px 20px 60px;
    text-align: center;
    color: #fff;
    position: relative;
    overflow: hidden;
  }
  .reels-header::before {
    content: ''; position: absolute; inset: 0;
    background: radial-gradient(circle at 30% 50%, rgba(255,255,255,.15), transparent 60%);
  }
  .reels-header h1 { font-size: 36px; font-weight: 800; margin-bottom: 8px; position: relative; }
  .reels-header p { font-size: 15px; opacity: .9; position: relative; }
  .reels-header .back-link {
    position: absolute; top: 20px; right: 20px;
    color: #fff; background: rgba(255,255,255,.2);
    padding: 8px 16px; border-radius: 20px;
    font-size: 13px; font-weight: 600;
    backdrop-filter: blur(10px);
  }

  /* Container */
  .container { max-width: 1200px; margin: -30px auto 40px; padding: 0 16px; position: relative; z-index: 2; }

  /* Sources filter */
  .sources-bar {
    background: #fff;
    border-radius: 16px;
    padding: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,.08);
    margin-bottom: 24px;
    display: flex;
    gap: 12px;
    overflow-x: auto;
    scrollbar-width: none;
  }
  .sources-bar::-webkit-scrollbar { display: none; }
  .source-chip {
    flex-shrink: 0;
    display: flex; align-items: center; gap: 8px;
    padding: 8px 16px;
    background: #f4f6fb;
    border: 2px solid transparent;
    border-radius: 30px;
    font-size: 13px; font-weight: 700;
    color: #2c3a52;
    cursor: pointer;
    transition: all .2s;
  }
  .source-chip:hover { background: #e8f0fe; }
  .source-chip.active {
    background: linear-gradient(135deg, #833ab4, #fd1d1d);
    color: #fff;
    border-color: transparent;
  }
  .source-chip img { width: 24px; height: 24px; border-radius: 50%; object-fit: cover; }
  .source-chip .ig-dot {
    width: 24px; height: 24px; border-radius: 50%;
    background: linear-gradient(135deg, #833ab4, #fd1d1d, #fcb045);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 12px;
  }

  /* Reels grid */
  .reels-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
  }

  .reel-card {
    background: #fff;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,.06);
    transition: all .3s;
  }
  .reel-card:hover { transform: translateY(-4px); box-shadow: 0 10px 30px rgba(0,0,0,.1); }

  .reel-source {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 16px;
    border-bottom: 1px solid #f0f2f5;
  }
  .reel-source-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, #833ab4, #fd1d1d, #fcb045);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 800; font-size: 14px;
    flex-shrink: 0; padding: 2px;
  }
  .reel-source-avatar img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
  .reel-source-info { flex: 1; min-width: 0; }
  .reel-source-name { font-size: 14px; font-weight: 700; color: #1a1a2e; }
  .reel-source-username { font-size: 11px; color: #6b7280; }
  .reel-time { font-size: 11px; color: #9ca3af; }

  .reel-embed { position: relative; width: 100%; background: #000; aspect-ratio: 9 / 16; max-height: 540px; overflow: hidden; }
  .reel-embed iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: none; }

  .reel-footer {
    padding: 14px 16px;
  }
  .reel-caption {
    font-size: 13px; color: #374151;
    line-height: 1.6;
    max-height: 60px;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
  }
  .reel-open {
    display: inline-flex; align-items: center; gap: 6px;
    margin-top: 10px;
    font-size: 12px; font-weight: 700;
    color: #833ab4;
  }

  .empty-state {
    grid-column: 1 / -1;
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border-radius: 16px;
    color: #6b7280;
  }
  .empty-state .icon { font-size: 64px; margin-bottom: 16px; opacity: .3; }
  .empty-state h3 { font-size: 20px; font-weight: 800; color: #1a1a2e; margin-bottom: 8px; }

  @media (max-width: 600px) {
    .reels-header { padding: 30px 16px 50px; }
    .reels-header h1 { font-size: 26px; }
    .reels-grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<header class="reels-header">
  <a href="index.php" class="back-link">← العودة للرئيسية</a>
  <h1>🎬 ريلز</h1>
  <p>أحدث الفيديوهات من أبرز المصادر الإخبارية</p>
</header>

<div class="container">

  <?php if (!empty($sources)): ?>
  <div class="sources-bar">
    <a href="reels.php" class="source-chip <?php echo !$filterSource ? 'active' : ''; ?>">
      <span class="ig-dot">🎬</span>
      <span>الكل</span>
    </a>
    <?php foreach ($sources as $src): ?>
      <a href="reels.php?source=<?php echo (int)$src['id']; ?>" class="source-chip <?php echo $filterSource === (int)$src['id'] ? 'active' : ''; ?>">
        <?php if ($src['avatar_url']): ?>
          <img src="<?php echo e($src['avatar_url']); ?>" alt="">
        <?php else: ?>
          <span class="ig-dot">📷</span>
        <?php endif; ?>
        <span><?php echo e($src['display_name']); ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="reels-grid">
    <?php if (empty($reels)): ?>
      <div class="empty-state">
        <div class="icon">🎬</div>
        <h3>لا توجد ريلز بعد</h3>
        <p>سيتم إضافة الريلز قريباً من قبل فريق التحرير</p>
      </div>
    <?php else: ?>
      <?php foreach ($reels as $reel): ?>
        <div class="reel-card">
          <div class="reel-source">
            <div class="reel-source-avatar">
              <?php if ($reel['source_avatar']): ?>
                <img src="<?php echo e($reel['source_avatar']); ?>" alt="">
              <?php else: ?>
                📷
              <?php endif; ?>
            </div>
            <div class="reel-source-info">
              <div class="reel-source-name"><?php echo e($reel['source_name'] ?? 'انستغرام'); ?></div>
              <?php if ($reel['source_username']): ?>
                <div class="reel-source-username">@<?php echo e($reel['source_username']); ?></div>
              <?php endif; ?>
            </div>
            <div class="reel-time"><?php echo timeAgo($reel['created_at']); ?></div>
          </div>

          <div class="reel-embed">
            <iframe
              src="https://www.instagram.com/reel/<?php echo e($reel['shortcode']); ?>/embed/"
              scrolling="no"
              allowtransparency="true"
              allow="encrypted-media"
              loading="lazy">
            </iframe>
          </div>

          <?php if (!empty($reel['caption'])): ?>
          <div class="reel-footer">
            <div class="reel-caption"><?php echo e($reel['caption']); ?></div>
            <a href="<?php echo e($reel['instagram_url']); ?>" target="_blank" class="reel-open">
              مشاهدة على انستغرام ←
            </a>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

</body>
</html>
