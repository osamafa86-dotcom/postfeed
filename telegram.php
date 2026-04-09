<?php
/**
 * نيوزفلو - صفحة أخبار تيليغرام
 * عرض كل رسائل قنوات تيليغرام مع التحديث الفوري (polling).
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';

$viewer    = current_user();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();

$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 24;
$offset  = ($page - 1) * $perPage;

$db = getDB();

// Make sure the tables exist (first visit on a fresh deploy). Silent on error.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS telegram_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        display_name VARCHAR(150) NOT NULL,
        avatar_url VARCHAR(500) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        last_fetched_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS telegram_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_id INT NOT NULL,
        message_id INT NOT NULL,
        post_url VARCHAR(500) NOT NULL,
        text TEXT,
        image_url VARCHAR(500) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        posted_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_msg (source_id, message_id),
        INDEX idx_posted (posted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

$totalCount = 0;
$messages   = [];
try {
    $countRow = $db->query("SELECT COUNT(*) FROM telegram_messages m
                             JOIN telegram_sources s ON m.source_id = s.id
                             WHERE m.is_active=1 AND s.is_active=1");
    $totalCount = (int)$countRow->fetchColumn();

    $stmt = $db->prepare("SELECT m.*, s.display_name, s.username, s.avatar_url
                           FROM telegram_messages m
                           JOIN telegram_sources s ON m.source_id = s.id
                           WHERE m.is_active=1 AND s.is_active=1
                           ORDER BY m.posted_at DESC, m.id DESC
                           LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log('telegram page: ' . $e->getMessage()); }

$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);

// Latest message id on this page — used by the poll to fetch only newer items
$latestId = 0;
foreach ($messages as $m) {
    if ((int)$m['id'] > $latestId) $latestId = (int)$m['id'];
}

function tg_page_url(int $p): string {
    return 'telegram.php?page=' . $p;
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title>📢 أخبار تيليغرام — <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="description" content="أحدث رسائل قنوات تيليغرام الإخبارية من <?php echo e(getSetting('site_name', SITE_NAME)); ?>">
<link rel="canonical" href="<?php echo e(SITE_URL . '/telegram.php'); ?>">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<meta name="theme-color" content="#229ED9">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site-header.css?v=1">
<link rel="stylesheet" href="assets/css/user.css?v=17">
<style>
  :root {
    --bg: #faf6ec;
    --bg2: #fdfaf2;
    --bg3: #e4e6eb;
    --card: #ffffff;
    --border: #e0e3e8;
    --accent: #1a73e8;
    --tg: #229ED9;
    --tg-dark: #1b82b5;
    --text: #1a1a2e;
    --muted: #6b7280;
    --muted2: #9ca3af;
    --header-bg: #1a1a2e;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; background:var(--bg); color:var(--text); overflow-x:hidden; line-height:1.6; }
  a { text-decoration:none; color:inherit; }
  ::-webkit-scrollbar { width:6px; }
  ::-webkit-scrollbar-track { background:transparent; }
  ::-webkit-scrollbar-thumb { background:#c1c5cc; border-radius:3px; }

  .container { max-width:1400px; margin:0 auto; padding:0 24px; }

  .page-header { padding:32px 0 22px; }
  .page-header-inner {
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:14px;
  }
  .page-title {
    display:flex; align-items:center; gap:12px;
    font-size:26px; font-weight:900;
  }
  .page-title .line { width:5px; height:32px; border-radius:3px; background:var(--tg); }
  .page-title .icon { font-size:28px; }
  .page-meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .page-count {
    font-size:13px; color:var(--muted); font-weight:600;
    background:var(--card); border:1px solid var(--border);
    padding:7px 18px; border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.04);
  }
  .live-pill {
    display:inline-flex; align-items:center; gap:7px;
    background:#eaf6fd; border:1px solid #bde3f4; color:var(--tg-dark);
    padding:7px 14px; border-radius:999px;
    font-size:12px; font-weight:800;
  }
  .live-pill.updating { background:#fff7e6; border-color:#fde7b4; color:#b45309; }
  .live-dot {
    width:8px; height:8px; border-radius:50%; background:var(--tg);
    animation: tgPulse 1.6s infinite ease-in-out;
  }
  .live-pill.updating .live-dot { background:#d97706; }
  @keyframes tgPulse {
    0%, 100% { transform:scale(1); opacity:1; }
    50% { transform:scale(1.4); opacity:.5; }
  }

  .tg-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:28px; }
  .tg-item {
    display:flex; gap:14px; padding:16px; background:var(--card);
    border:1px solid var(--border); border-right:4px solid var(--tg);
    border-radius:14px; text-decoration:none; color:var(--text);
    transition:all .2s;
    box-shadow:0 1px 3px rgba(0,0,0,.04);
  }
  .tg-item:hover {
    box-shadow:0 10px 30px rgba(34,158,217,.18);
    transform:translateY(-3px);
    border-right-color:var(--tg-dark);
  }
  .tg-item.is-new {
    animation: tgNew .8s ease-out;
    border-right-width:6px;
  }
  @keyframes tgNew {
    0% { background:#e6f6fd; transform:translateY(-8px); opacity:0; }
    100% { background:var(--card); transform:translateY(0); opacity:1; }
  }
  .tg-item-img {
    flex:0 0 110px; height:110px; border-radius:10px;
    overflow:hidden; background:var(--bg3);
  }
  .tg-item-img img { width:100%; height:100%; object-fit:cover; display:block; }
  .tg-item-body { flex:1; min-width:0; display:flex; flex-direction:column; }
  .tg-item-source {
    display:flex; align-items:center; gap:8px; font-size:12px;
    margin-bottom:8px; flex-wrap:wrap;
  }
  .tg-item-badge {
    background:var(--tg); color:#fff; padding:3px 9px;
    border-radius:10px; font-size:10.5px; font-weight:800;
    display:inline-flex; align-items:center; gap:4px;
  }
  .tg-item-source strong { color:var(--tg-dark); font-weight:800; }
  .tg-item-time { color:var(--muted); font-size:11px; margin-inline-start:auto; }
  .tg-item-text {
    font-size:13.5px; line-height:1.75; color:var(--text);
    display:-webkit-box; -webkit-line-clamp:5; -webkit-box-orient:vertical; overflow:hidden;
    white-space:pre-wrap; word-break:break-word;
  }

  .empty-state {
    text-align:center; padding:80px 20px; color:var(--muted);
  }
  .empty-state .icon { font-size:56px; margin-bottom:18px; }
  .empty-state h3 { font-size:20px; margin-bottom:8px; color:var(--text); font-weight:800; }
  .empty-state p { font-size:14px; }

  .pagination {
    display:flex; align-items:center; justify-content:center;
    gap:6px; padding:18px 0 48px; flex-wrap:wrap;
  }
  .pagination a, .pagination span {
    min-width:40px; height:40px; display:flex; align-items:center; justify-content:center;
    border-radius:10px; font-size:14px; font-weight:600;
    text-decoration:none; transition:all .2s; padding:0 12px;
  }
  .pagination a {
    background:var(--card); border:1px solid var(--border);
    color:var(--text); box-shadow:0 1px 3px rgba(0,0,0,.04);
  }
  .pagination a:hover {
    background:rgba(34,158,217,.08); border-color:var(--tg);
    color:var(--tg-dark);
  }
  .pagination .current {
    background:var(--tg); color:#fff; border:1px solid var(--tg);
    box-shadow:0 4px 12px rgba(34,158,217,.3);
  }
  .pagination .dots { background:none; border:none; color:var(--muted); min-width:auto; padding:0 4px; }

  footer {
    background:var(--header-bg);
    padding:32px 24px; margin-top:40px;
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:16px; color:rgba(255,255,255,.5);
  }
  .footer-logo { font-size:22px; font-weight:900; color:#fff; }
  .footer-links { display:flex; gap:20px; }
  .footer-links a { font-size:12px; color:rgba(255,255,255,.4); transition:color .2s; }
  .footer-links a:hover { color:#60a5fa; }
  .footer-copy { font-size:11px; color:rgba(255,255,255,.3); }

  @media (max-width:900px) {
    .tg-grid { grid-template-columns:1fr; }
    .page-title { font-size:22px; }
  }
  @media (max-width:560px) {
    .container { padding:0 14px; }
    .tg-item { padding:12px; gap:10px; }
    .tg-item-img { flex:0 0 80px; height:80px; }
    .tg-item-text { font-size:13px; -webkit-line-clamp:4; }
  }
</style>
</head>
<body>

<?php
$activeType  = 'telegram';
$activeSlug  = '';
$showTicker  = false;
$userUnread  = $viewerId ? user_unread_notifications_count($viewerId) : 0;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="container">

  <div class="page-header">
    <div class="page-header-inner">
      <div class="page-title">
        <div class="line"></div>
        <span class="icon">📢</span>
        أخبار تيليغرام
      </div>
      <div class="page-meta">
        <span class="live-pill" id="tgLivePill" title="يتحدّث تلقائياً كل 30 ثانية">
          <span class="live-dot"></span>
          <span id="tgLiveLabel">مباشر</span>
        </span>
        <div class="page-count"><span id="tgTotalCount"><?php echo number_format($totalCount); ?></span> تحديث</div>
      </div>
    </div>
  </div>

  <?php if (empty($messages)): ?>
    <div class="empty-state">
      <div class="icon">📭</div>
      <h3>لا توجد تحديثات حالياً</h3>
      <p>لم يتم جلب أي رسائل من قنوات تيليغرام بعد. عد لاحقاً.</p>
    </div>
  <?php else: ?>
    <div class="tg-grid" id="tgGrid" data-latest-id="<?php echo (int)$latestId; ?>" data-page="<?php echo (int)$page; ?>">
      <?php foreach ($messages as $m): ?>
        <a class="tg-item" href="<?php echo e($m['post_url']); ?>" target="_blank" rel="noopener" data-tg-id="<?php echo (int)$m['id']; ?>">
          <?php if (!empty($m['image_url'])): ?>
            <div class="tg-item-img"><img src="<?php echo e($m['image_url']); ?>" alt="" loading="lazy" decoding="async"></div>
          <?php endif; ?>
          <div class="tg-item-body">
            <div class="tg-item-source">
              <span class="tg-item-badge">📢 تيليغرام</span>
              <strong>@<?php echo e($m['username']); ?></strong>
              <span class="tg-item-time" data-tg-time="<?php echo e($m['posted_at']); ?>"><?php echo timeAgo($m['posted_at']); ?></span>
            </div>
            <div class="tg-item-text"><?php echo e(mb_substr($m['text'] ?? '', 0, 600)); ?><?php echo mb_strlen($m['text'] ?? '')>600?'...':''; ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="<?php echo e(tg_page_url($page - 1)); ?>">→ السابق</a>
        <?php endif; ?>
        <?php
        $range = 2;
        $startPage = max(1, $page - $range);
        $endPage = min($totalPages, $page + $range);
        if ($startPage > 1): ?>
          <a href="<?php echo e(tg_page_url(1)); ?>">1</a>
          <?php if ($startPage > 2): ?><span class="dots">...</span><?php endif; ?>
        <?php endif; ?>
        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
          <?php if ($i === $page): ?>
            <span class="current"><?php echo $i; ?></span>
          <?php else: ?>
            <a href="<?php echo e(tg_page_url($i)); ?>"><?php echo $i; ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($endPage < $totalPages): ?>
          <?php if ($endPage < $totalPages - 1): ?><span class="dots">...</span><?php endif; ?>
          <a href="<?php echo e(tg_page_url($totalPages)); ?>"><?php echo $totalPages; ?></a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a href="<?php echo e(tg_page_url($page + 1)); ?>">التالي ←</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</div>

<footer>
  <div class="footer-logo"><?php echo e(getSetting('site_name', SITE_NAME)); ?></div>
  <div class="footer-links">
    <a href="/">الرئيسية</a>
    <a href="category.php?type=breaking">عاجل</a>
    <a href="category.php?type=latest">آخر الأخبار</a>
  </div>
  <div class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo e(getSetting('site_name', SITE_NAME)); ?></div>
</footer>

<script src="assets/js/telegram-live.js?v=2" defer></script>
</body>
</html>
