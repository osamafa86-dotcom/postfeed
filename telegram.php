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
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap"></noscript>
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

  /* ============ AI NEWS SUMMARIES ============ */
  .summary-btn {
    display:inline-flex; align-items:center; gap:8px;
    padding:8px 16px; border-radius:999px;
    background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 60%,#c026d3 100%);
    color:#fff; font-size:12.5px; font-weight:800;
    border:0; cursor:pointer;
    box-shadow:0 4px 14px rgba(124,58,237,.35);
    transition:all .2s ease;
    font-family:inherit;
  }
  .summary-btn:hover {
    transform:translateY(-1px);
    box-shadow:0 8px 22px rgba(124,58,237,.5);
  }
  .summary-btn.loading { opacity:.7; cursor:wait; }
  .summary-btn-icon { font-size:14px; animation:sparkTwinkle 2.5s ease-in-out infinite; }
  @keyframes sparkTwinkle {
    0%,100% { transform:rotate(0deg) scale(1); opacity:1; }
    50%     { transform:rotate(12deg) scale(1.15); opacity:.85; }
  }

  .tg-summary {
    margin:4px 0 22px;
    background:linear-gradient(135deg,#faf7ff 0%,#f3f0ff 100%);
    border:1px solid #e4defb;
    border-radius:16px;
    padding:0;
    overflow:hidden;
    max-height:0;
    opacity:0;
    transition:max-height .35s ease, opacity .25s ease, margin .25s ease;
  }
  .tg-summary.open {
    max-height:6000px;
    opacity:1;
    box-shadow:0 10px 30px rgba(79,70,229,.12);
  }
  .tg-summary-head {
    display:flex; align-items:center; justify-content:space-between;
    padding:16px 20px;
    border-bottom:1px solid rgba(79,70,229,.12);
    background:rgba(255,255,255,.55);
    flex-wrap:wrap; gap:10px;
  }
  .tg-summary-title {
    display:flex; align-items:center; gap:10px;
    font-weight:900; font-size:15px; color:#4f46e5;
  }
  .tg-summary-spark { font-size:18px; }
  .tg-summary-meta { display:flex; align-items:center; gap:10px; font-size:12px; color:var(--muted); }
  .tg-summary-badge {
    background:#dcfce7; color:#166534;
    padding:3px 10px; border-radius:999px;
    font-weight:800; font-size:11px;
  }
  .tg-summary-ts { font-variant-numeric:tabular-nums; }
  .tg-summary-refresh,
  .tg-summary-close {
    width:30px; height:30px;
    border:1px solid rgba(79,70,229,.25);
    background:#fff; color:#4f46e5;
    border-radius:8px; cursor:pointer;
    font-size:16px; font-weight:900; line-height:1;
    display:inline-flex; align-items:center; justify-content:center;
    font-family:inherit;
    transition:all .2s;
  }
  .tg-summary-refresh:hover,
  .tg-summary-close:hover {
    background:#4f46e5; color:#fff;
    transform:translateY(-1px);
  }
  .tg-summary-refresh.spinning { animation:spinRefresh .9s linear infinite; }
  @keyframes spinRefresh { to { transform:rotate(360deg); } }

  .tg-summary-body { padding:20px 22px; min-height:120px; }
  .tg-summary-loading {
    display:flex; align-items:center; justify-content:center; gap:14px;
    padding:30px 20px; color:var(--muted); font-size:14px;
  }
  .tg-summary-spinner {
    width:22px; height:22px; border-radius:50%;
    border:3px solid #ede9fe; border-top-color:#7c3aed;
    animation:spinRefresh .8s linear infinite;
  }
  .tg-summary-error {
    padding:20px; text-align:center;
    background:#fef2f2; border:1px solid #fee2e2; border-radius:10px;
    color:#b91c1c; font-size:13.5px; font-weight:600;
  }
  .tg-summary-headline {
    font-size:20px; font-weight:900; color:var(--text);
    margin-bottom:12px; line-height:1.5;
    padding-right:14px;
    border-right:4px solid #7c3aed;
  }
  .tg-summary-text {
    font-size:14.5px; line-height:1.9; color:#374151;
    margin-bottom:20px;
    padding:14px 16px;
    background:rgba(255,255,255,.65);
    border:1px solid rgba(124,58,237,.12);
    border-radius:12px;
  }
  .tg-summary-sections {
    display:flex; flex-direction:column; gap:14px;
    margin:0 0 16px;
  }
  .tg-summary-section {
    background:#fff;
    border:1px solid #e4defb;
    border-radius:14px;
    padding:14px 16px 12px;
    box-shadow:0 2px 6px rgba(79,70,229,.04);
  }
  .tg-summary-section-head {
    display:flex; align-items:center; gap:10px;
    margin-bottom:10px;
    padding-bottom:10px;
    border-bottom:1px dashed rgba(124,58,237,.18);
  }
  .tg-summary-section-icon {
    font-size:20px; line-height:1;
    width:36px; height:36px;
    display:inline-flex; align-items:center; justify-content:center;
    background:linear-gradient(135deg,#ede9fe,#ddd6fe);
    border-radius:10px; flex:0 0 36px;
  }
  .tg-summary-section-title {
    font-size:15px; font-weight:900;
    color:#4c1d95; line-height:1.4; flex:1;
  }
  .tg-summary-section-items {
    list-style:none; padding:0; margin:0;
    display:flex; flex-direction:column; gap:7px;
  }
  .tg-summary-section-items li {
    position:relative;
    padding:8px 30px 8px 10px;
    font-size:13.5px; line-height:1.8; color:#1f2937;
    font-weight:500;
    border-right:3px solid #c4b5fd;
    background:#fafaff;
    border-radius:8px;
  }
  .tg-summary-section-items li::before {
    content:'◂';
    position:absolute; right:12px; top:7px;
    color:#7c3aed; font-weight:900; font-size:13px;
  }
  /* Fallback for legacy flat bullets (if Claude ignores sections). */
  .tg-summary-bullets {
    list-style:none; padding:0; margin:0 0 14px;
    display:flex; flex-direction:column; gap:8px;
  }
  .tg-summary-bullets li {
    position:relative;
    padding:10px 40px 10px 14px;
    background:#fff;
    border:1px solid #e4defb;
    border-radius:10px;
    font-size:13.5px; line-height:1.75; color:#1f2937;
    font-weight:500;
  }
  .tg-summary-bullets li::before {
    content:'▸';
    position:absolute; right:14px; top:10px;
    color:#7c3aed; font-weight:900; font-size:15px;
  }
  .tg-summary-topics {
    display:flex; flex-wrap:wrap; gap:6px;
    margin-top:14px;
    padding-top:14px;
    border-top:1px dashed rgba(124,58,237,.18);
  }
  .tg-summary-topic {
    background:#ede9fe; color:#5b21b6;
    padding:5px 12px; border-radius:999px;
    font-size:11.5px; font-weight:800;
  }
  .tg-summary-foot {
    display:flex; align-items:center; justify-content:space-between;
    padding:10px 20px 14px; font-size:11.5px; color:var(--muted);
    border-top:1px dashed rgba(79,70,229,.15);
    background:rgba(255,255,255,.4);
    flex-wrap:wrap; gap:8px;
  }

  @media (max-width:900px) {
    .tg-grid { grid-template-columns:1fr; }
    .page-title { font-size:22px; }
  }
  @media (max-width:560px) {
    .container { padding:0 14px; }
    .tg-item { padding:12px; gap:10px; }
    .tg-item-img { flex:0 0 80px; height:80px; }
    .tg-item-text { font-size:13px; -webkit-line-clamp:4; }
    .tg-summary-body { padding:16px; }
    .tg-summary-head { padding:12px 14px; }
    .tg-summary-headline { font-size:16px; }
    .tg-summary-text { font-size:13.5px; }
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
        <button type="button" class="summary-btn" id="tgSummaryBtn" title="ملخص ذكي لآخر نصف ساعة من رسائل القنوات">
          <span class="summary-btn-icon">✨</span>
          <span>ملخصات إخبارية</span>
        </button>
        <div class="page-count"><span id="tgTotalCount"><?php echo number_format($totalCount); ?></span> تحديث</div>
      </div>
    </div>
  </div>

  <!-- AI News summary panel (collapsed by default, expanded on click) -->
  <section class="tg-summary" id="tgSummary" aria-hidden="true">
    <div class="tg-summary-head">
      <div class="tg-summary-title">
        <span class="tg-summary-spark">✨</span>
        <span>ملخص إخباري لآخر <span id="tgSummaryWindow">30</span> دقيقة</span>
      </div>
      <div class="tg-summary-meta">
        <span class="tg-summary-badge" id="tgSummaryBadge" hidden>محدَّث</span>
        <span class="tg-summary-ts" id="tgSummaryTs"></span>
        <button type="button" class="tg-summary-refresh" id="tgSummaryRefresh" title="تحديث الملخص">↻</button>
        <button type="button" class="tg-summary-close" id="tgSummaryClose" aria-label="إغلاق">×</button>
      </div>
    </div>
    <div class="tg-summary-body" id="tgSummaryBody">
      <div class="tg-summary-loading">
        <div class="tg-summary-spinner"></div>
        <span>جارٍ توليد الملخص من آخر الرسائل...</span>
      </div>
    </div>
    <div class="tg-summary-foot" id="tgSummaryFoot" hidden>
      <span id="tgSummaryCount"></span>
      <span class="tg-summary-next" id="tgSummaryNext"></span>
    </div>
  </section>

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
<script>
/**
 * AI news summary panel on /telegram.php.
 *
 * Clicking "ملخصات إخبارية" opens the panel and requests a summary
 * from /telegram_summary.php. The server caches per half-hour bucket
 * across visitors, so the first viewer of a new bucket pays the
 * Claude call and everyone else gets it from disk cache.
 *
 * The refresh (↻) button also hits the endpoint with force=1 to
 * explicitly regenerate, but the server throttles that path so
 * rapid clicks won't burn API calls.
 */
(function(){
  var btn      = document.getElementById('tgSummaryBtn');
  var panel    = document.getElementById('tgSummary');
  var body     = document.getElementById('tgSummaryBody');
  var footEl   = document.getElementById('tgSummaryFoot');
  var tsEl     = document.getElementById('tgSummaryTs');
  var badge    = document.getElementById('tgSummaryBadge');
  var nextEl   = document.getElementById('tgSummaryNext');
  var countEl  = document.getElementById('tgSummaryCount');
  var winEl    = document.getElementById('tgSummaryWindow');
  var closeBtn = document.getElementById('tgSummaryClose');
  var refBtn   = document.getElementById('tgSummaryRefresh');
  if (!btn || !panel) return;

  var inFlight = false;
  var loaded   = false;

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function fmtTime(iso) {
    if (!iso) return '';
    try {
      var d = new Date(iso);
      return d.toLocaleTimeString('ar-EG', { hour:'2-digit', minute:'2-digit' });
    } catch (e) { return ''; }
  }

  function openPanel() {
    panel.classList.add('open');
    panel.setAttribute('aria-hidden', 'false');
  }

  function closePanel() {
    panel.classList.remove('open');
    panel.setAttribute('aria-hidden', 'true');
  }

  function showLoading() {
    body.innerHTML = '<div class="tg-summary-loading"><div class="tg-summary-spinner"></div><span>جارٍ توليد الملخص من آخر الرسائل...</span></div>';
    footEl.hidden = true;
    badge.hidden  = true;
    tsEl.textContent = '';
  }

  function showError(msg) {
    body.innerHTML = '<div class="tg-summary-error">' + escapeHtml(msg || 'تعذّر توليد الملخص.') + '</div>';
    footEl.hidden = true;
    badge.hidden  = true;
    tsEl.textContent = '';
  }

  function render(payload) {
    if (!payload || !payload.ok) {
      showError(payload && payload.error);
      return;
    }
    if (winEl) winEl.textContent = payload.window_mins || 30;

    var html = '';
    if (payload.headline) {
      html += '<div class="tg-summary-headline">' + escapeHtml(payload.headline) + '</div>';
    }
    if (payload.summary) {
      html += '<div class="tg-summary-text">' + escapeHtml(payload.summary) + '</div>';
    }

    // Preferred layout: structured sections (file-style report).
    // Fall back to the legacy flat bullets if Claude returned the old shape.
    if (payload.sections && payload.sections.length) {
      html += '<div class="tg-summary-sections">';
      for (var s = 0; s < payload.sections.length; s++) {
        var sec = payload.sections[s] || {};
        var items = sec.items || [];
        if (!items.length) continue;
        html += '<div class="tg-summary-section">';
        html +=   '<div class="tg-summary-section-head">';
        html +=     '<span class="tg-summary-section-icon">' + escapeHtml(sec.icon || '📰') + '</span>';
        html +=     '<span class="tg-summary-section-title">' + escapeHtml(sec.title || '') + '</span>';
        html +=   '</div>';
        html +=   '<ul class="tg-summary-section-items">';
        for (var k = 0; k < items.length; k++) {
          html += '<li>' + escapeHtml(items[k]) + '</li>';
        }
        html +=   '</ul>';
        html += '</div>';
      }
      html += '</div>';
    } else if (payload.bullets && payload.bullets.length) {
      html += '<ul class="tg-summary-bullets">';
      for (var i = 0; i < payload.bullets.length; i++) {
        html += '<li>' + escapeHtml(payload.bullets[i]) + '</li>';
      }
      html += '</ul>';
    }

    if (payload.topics && payload.topics.length) {
      html += '<div class="tg-summary-topics">';
      for (var j = 0; j < payload.topics.length; j++) {
        html += '<span class="tg-summary-topic">#' + escapeHtml(payload.topics[j]) + '</span>';
      }
      html += '</div>';
    }
    body.innerHTML = html || '<div class="tg-summary-error">الرد فارغ — لا توجد رسائل كافية للتلخيص.</div>';

    // Footer: message count + next refresh time.
    if (countEl) {
      var cnt = payload.message_count || 0;
      countEl.textContent = 'مبني على ' + cnt.toLocaleString('ar-EG') + ' رسالة';
    }
    if (nextEl && payload.next_refresh) {
      nextEl.textContent = 'تحديث تلقائي الساعة ' + fmtTime(payload.next_refresh);
    }
    footEl.hidden = false;

    // "محدَّث" badge when server returned a fresh (non-cached) result.
    if (badge) badge.hidden = !!payload.cached;
    if (tsEl)  tsEl.textContent  = payload.generated_at ? fmtTime(payload.generated_at) : '';
  }

  function fetchSummary(force) {
    if (inFlight) return;
    inFlight = true;
    btn.classList.add('loading');
    if (force && refBtn) refBtn.classList.add('spinning');
    if (!loaded || force) showLoading();

    var url = 'telegram_summary.php?window=30' + (force ? '&force=1' : '') + '&_=' + Date.now();
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function(r){ return r.json(); })
      .catch(function(){ return { ok:false, error:'خطأ في الاتصال بالخادم.' }; })
      .then(function(data){
        inFlight = false;
        loaded = true;
        btn.classList.remove('loading');
        if (refBtn) refBtn.classList.remove('spinning');
        render(data);
      });
  }

  btn.addEventListener('click', function(){
    var isOpen = panel.classList.contains('open');
    if (isOpen) {
      closePanel();
      return;
    }
    openPanel();
    if (!loaded) fetchSummary(false);
  });

  if (closeBtn) closeBtn.addEventListener('click', closePanel);
  if (refBtn)   refBtn.addEventListener('click', function(){ fetchSummary(true); });
})();
</script>
</body>
</html>
