<?php
/**
 * نيوزفلو - الصفحة الرئيسية
 * موقع تجميع الأخبار من مصادر متعددة
 */

require_once __DIR__ . '/includes/functions.php';

// جلب البيانات من قاعدة البيانات
$heroArticles = getHeroArticles();
$palestineNews = getPalestineNews(5);
$breakingNews = getBreakingNews();
$latestArticles = getLatestArticles(6);
$categories = getCategories();
$notifications = getNotifications(6);
$unreadCount = getUnreadNotifCount();
$poll = getActivePoll();
$trends = getTrends();
$sources = getActiveSources();
$mostRead = getMostRead();
$mediaItems = getMediaItems(4);
$tickerItems = getTickerItems();

// إحصائيات
$totalArticles = countArticles();
$totalSources = count($sources);

// جلب أخبار التصنيفات المختلفة
$politicalNews = getArticlesByCategory('political', 4);
$economyNews = getArticlesByCategory('economy', 3);
$sportsNews = getArticlesByCategory('sports', 3);
$artsNews = getArticlesByCategory('arts', 2);
$reportsNews = getArticlesByCategory('reports', 3);

// جلب الريلز للعرض في الصفحة الرئيسية
$homeReels = [];
try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT r.*, s.username, s.display_name, s.avatar_url
                         FROM reels r
                         LEFT JOIN reels_sources s ON r.source_id = s.id
                         WHERE r.is_active = 1
                         ORDER BY r.sort_order DESC, r.created_at DESC
                         LIMIT 8");
    $homeReels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    $homeReels = [];
}

?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e(getSetting('site_name', SITE_NAME)); ?> - <?php echo e(getSetting('site_tagline', SITE_TAGLINE)); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #f0f2f5;
    --bg2: #f8f9fa;
    --bg3: #e4e6eb;
    --card: #ffffff;
    --border: #e0e3e8;
    --accent: #1a73e8;
    --accent2: #0d9488;
    --accent3: #16a34a;
    --red: #dc2626;
    --text: #1a1a2e;
    --text2: #374151;
    --muted: #6b7280;
    --muted2: #9ca3af;
    --gold: #d97706;
    --header-bg: #1a1a2e;
    --header-text: #e5e7eb;
    --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
    --shadow-md: 0 4px 16px rgba(0,0,0,.08);
    --shadow-lg: 0 10px 40px rgba(0,0,0,.12);
    --radius: 12px;
    --radius-lg: 16px;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; background:var(--bg); color:var(--text); overflow-x:hidden; line-height:1.6; }
  a { text-decoration:none; color:inherit; }

  /* SCROLLBAR */
  ::-webkit-scrollbar { width:6px; }
  ::-webkit-scrollbar-track { background:transparent; }
  ::-webkit-scrollbar-thumb { background:#c1c5cc; border-radius:3px; }
  ::-webkit-scrollbar-thumb:hover { background:#a0a4ab; }

  /* TOP BAR */
  .topbar {
    background:var(--header-bg);
    padding:8px 24px;
    display:flex; align-items:center; justify-content:space-between;
    font-size:12px; color:rgba(255,255,255,.65);
    border-bottom:1px solid rgba(255,255,255,.08);
  }
  .topbar-left { display:flex; gap:18px; align-items:center; }
  .topbar-right { display:flex; gap:14px; align-items:center; }
  .live-dot { width:7px; height:7px; border-radius:50%; background:#ef4444; animation:pulse 1.5s ease infinite; display:inline-block; margin-left:6px; }
  @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(1.5)} }
  .weather-badge { background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.15); padding:3px 12px; border-radius:20px; color:rgba(255,255,255,.8); font-size:11px; backdrop-filter:blur(4px); }

  /* HEADER */
  header {
    background:var(--header-bg);
    padding:0 24px;
    display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0; z-index:1000;
    height:64px;
    box-shadow:0 4px 20px rgba(0,0,0,.15);
  }
  .logo { display:flex; align-items:center; gap:12px; text-decoration:none; }
  .logo-icon {
    width:42px; height:42px; border-radius:10px;
    background:linear-gradient(135deg,#1a73e8,#4f46e5);
    display:flex; align-items:center; justify-content:center;
    font-size:20px; font-weight:900; color:#fff;
    box-shadow:0 4px 12px rgba(26,115,232,.4);
    letter-spacing:-1px;
  }
  .logo-text { font-size:24px; font-weight:900; color:#fff; letter-spacing:-0.5px; }
  .logo-text span { color:#60a5fa; }
  .logo-sub { font-size:10px; color:rgba(255,255,255,.45); margin-top:-3px; letter-spacing:.5px; }

  /* NAV */
  nav { display:flex; gap:2px; align-items:center; height:100%; }
  nav a {
    padding:8px 16px; border-radius:8px; text-decoration:none;
    color:rgba(255,255,255,.6); font-size:13px; font-weight:500;
    transition:all .2s; white-space:nowrap;
    height:40px; display:flex; align-items:center;
  }
  nav a:hover { background:rgba(255,255,255,.08); color:rgba(255,255,255,.9); }
  nav a.active { background:rgba(26,115,232,.25); color:#60a5fa; font-weight:700; }
  nav a.breaking { background:rgba(220,38,38,.15); color:#fca5a5; animation:breakingPulse 2.5s ease infinite; }
  @keyframes breakingPulse { 0%,100%{background:rgba(220,38,38,.1)} 50%{background:rgba(220,38,38,.22)} }

  /* HEADER ACTIONS */
  .header-actions { display:flex; gap:8px; align-items:center; }
  .search-box {
    display:flex; align-items:center; gap:8px;
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.1);
    border-radius:10px; padding:8px 14px; width:220px;
    transition:all .3s;
  }
  .search-box:focus-within { border-color:rgba(96,165,250,.5); background:rgba(255,255,255,.12); width:260px; box-shadow:0 0 0 3px rgba(26,115,232,.15); }
  .search-box input { background:none; border:none; outline:none; color:#fff; font-size:13px; width:100%; font-family:inherit; }
  .search-box input::placeholder { color:rgba(255,255,255,.35); }
  .search-box .search-icon { color:rgba(255,255,255,.4); font-size:14px; }
  .icon-btn {
    width:40px; height:40px; border-radius:10px;
    background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:all .2s; position:relative; color:rgba(255,255,255,.6); font-size:16px;
  }
  .icon-btn:hover { background:rgba(255,255,255,.12); border-color:rgba(255,255,255,.2); color:#fff; }
  .notif-badge {
    position:absolute; top:-4px; right:-4px;
    min-width:18px; height:18px; border-radius:9px; padding:0 4px;
    background:#ef4444; font-size:10px; color:#fff;
    display:flex; align-items:center; justify-content:center;
    border:2px solid var(--header-bg); font-weight:700;
  }
  .avatar {
    width:40px; height:40px; border-radius:10px;
    background:linear-gradient(135deg,#0d9488,#0f766e);
    display:flex; align-items:center; justify-content:center;
    font-weight:700; font-size:15px; cursor:pointer; color:#fff;
    border:2px solid rgba(13,148,136,.3);
    transition:all .2s;
  }
  .avatar:hover { border-color:rgba(13,148,136,.6); box-shadow:0 0 12px rgba(13,148,136,.3); transform:scale(1.05); }

  /* TICKER */
  .ticker-wrap {
    background:linear-gradient(90deg,#1e293b,#1a1a2e);
    padding:10px 0; overflow:hidden; position:relative;
    border-bottom:1px solid rgba(255,255,255,.05);
  }
  .ticker-label {
    position:absolute; right:0; top:0; bottom:0;
    background:linear-gradient(135deg,#dc2626,#b91c1c); color:#fff; padding:0 22px;
    display:flex; align-items:center; font-weight:700; font-size:13px;
    z-index:2; white-space:nowrap;
    box-shadow:-10px 0 20px rgba(0,0,0,.5);
    gap:6px;
  }
  .ticker-label::before { content:''; width:8px; height:8px; border-radius:50%; background:#fff; animation:pulse 1.5s ease infinite; }
  .ticker-content {
    display:flex; animation:tickerScroll 40s linear infinite;
    padding-right:140px;
  }
  .ticker-content:hover { animation-play-state:paused; }
  @keyframes tickerScroll {
    0% { transform:translateX(0); }
    100% { transform:translateX(50%); }
  }
  .ticker-item {
    white-space:nowrap; padding:0 32px; font-size:13px;
    display:flex; align-items:center; gap:10px; color:rgba(255,255,255,.8);
    font-weight:500;
  }
  .ticker-item::before { content:''; width:4px; height:4px; border-radius:50%; background:rgba(255,255,255,.3); flex-shrink:0; }

  /* LAYOUT */
  .container { max-width:1400px; margin:0 auto; padding:0 20px; }
  .main-layout { display:grid; grid-template-columns:1fr 340px; gap:28px; padding:28px 24px; max-width:1400px; margin:0 auto; }

  /* SECTION HEADER */
  .section-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
  .section-title { display:flex; align-items:center; gap:10px; font-size:20px; font-weight:800; color:var(--text); }
  .section-title .line { width:4px; height:24px; border-radius:2px; background:var(--accent); }
  .section-title.blue .line { background:var(--accent2); }
  .section-title.green .line { background:var(--accent3); }
  .section-title.gold .line { background:var(--gold); }
  .see-all {
    font-size:13px; color:var(--accent); text-decoration:none;
    background:rgba(26,115,232,.06); padding:6px 16px; border-radius:8px;
    border:1px solid rgba(26,115,232,.15); transition:all .2s;
    font-weight:600;
  }
  .see-all:hover { background:rgba(26,115,232,.12); border-color:rgba(26,115,232,.3); }

  /* HERO */
  .hero-grid { display:grid; grid-template-columns:1.6fr 1fr; grid-template-rows:1fr 1fr; gap:16px; margin-bottom:32px; height:480px; }
  .hero-main {
    grid-row:1/3; border-radius:var(--radius-lg); overflow:hidden;
    position:relative; cursor:pointer;
    background:linear-gradient(135deg,#1e293b,#334155);
    text-decoration:none;
  }
  .hero-main img, .news-card img { width:100%; height:100%; object-fit:cover; }
  .hero-main .img-wrap { position:absolute; inset:0; }
  .hero-main .img-wrap img { transition:transform 6s ease; }
  .hero-main:hover .img-wrap img { transform:scale(1.05); }
  .hero-main .img-wrap::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(to top,rgba(0,0,0,.88) 0%,rgba(0,0,0,.35) 40%,transparent 65%);
  }
  .hero-overlay { position:absolute; bottom:0; left:0; right:0; padding:28px; z-index:2; }
  .source-badge {
    display:inline-flex; align-items:center; gap:6px;
    background:rgba(26,115,232,.85); color:#fff; padding:5px 14px; border-radius:6px;
    font-size:11px; font-weight:700; margin-bottom:12px;
    backdrop-filter:blur(4px); letter-spacing:.3px;
  }
  .hero-title { font-size:24px; font-weight:800; line-height:1.5; margin-bottom:8px; color:#fff; text-shadow:0 2px 8px rgba(0,0,0,.3); }
  .hero-excerpt { font-size:14px; line-height:1.7; color:rgba(255,255,255,.6); margin-bottom:12px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  .meta { display:flex; align-items:center; gap:12px; font-size:12px; color:rgba(255,255,255,.55); font-weight:500; }
  .meta-dot { width:3px; height:3px; border-radius:50%; background:rgba(255,255,255,.35); }

  .hero-side {
    border-radius:var(--radius-lg); overflow:hidden; position:relative;
    cursor:pointer;
    background:linear-gradient(135deg,#1e293b,#334155);
    transition:all .3s ease;
    box-shadow:var(--shadow-sm);
    text-decoration:none; display:block;
  }
  .hero-side:hover { transform:translateY(-3px); box-shadow:var(--shadow-lg); }
  .hero-side .img-wrap { position:absolute; inset:0; }
  .hero-side .img-wrap img { width:100%; height:100%; object-fit:cover; transition:transform .5s ease; }
  .hero-side:hover .img-wrap img { transform:scale(1.06); }
  .hero-side .img-wrap::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(to top,rgba(0,0,0,.85) 0%,rgba(0,0,0,.25) 50%,transparent 70%);
  }
  .hero-side-overlay {
    position:absolute; bottom:0; left:0; right:0; padding:18px; z-index:2;
  }
  .hero-side-overlay .card-cat { margin-bottom:8px; }
  .hero-side-overlay h3 { font-size:15px; font-weight:700; line-height:1.5; color:#fff; margin-bottom:6px; text-shadow:0 1px 4px rgba(0,0,0,.3); }
  .hero-side-overlay .meta { font-size:11px; }

  /* PALESTINE NEWS - HERO + GRID */
  .ps-hero {
    display:grid; grid-template-columns:1fr 1.2fr; gap:0;
    background:#fff; border-radius:var(--radius-lg); overflow:hidden;
    margin-bottom:22px; box-shadow:0 1px 6px rgba(0,0,0,.07);
    border:1px solid #f0f0f0; text-decoration:none; cursor:pointer;
    transition:all .3s ease;
  }
  .ps-hero:hover { box-shadow:0 8px 28px rgba(0,0,0,.1); transform:translateY(-2px); }
  .ps-hero-img { overflow:hidden; min-height:340px; }
  .ps-hero-img img { width:100%; height:100%; object-fit:cover; transition:transform .5s ease; }
  .ps-hero:hover .ps-hero-img img { transform:scale(1.04); }
  .ps-hero-text {
    padding:32px 30px; display:flex; flex-direction:column; justify-content:center;
  }
  .ps-hero-text h3 {
    font-size:22px; font-weight:800; line-height:1.7; color:#b33a3a;
    margin-bottom:14px;
  }
  .ps-hero-text .ps-hero-excerpt {
    font-size:14px; line-height:1.8; color:#6b7280; margin-bottom:18px;
    display:-webkit-box; -webkit-line-clamp:4; -webkit-box-orient:vertical; overflow:hidden;
  }
  .ps-hero-meta {
    display:flex; align-items:center; gap:14px; font-size:13px; color:#9ca3af;
  }
  .ps-hero-meta .source-icon {
    width:32px; height:32px; border-radius:50%; background:#16a34a;
    color:#fff; display:flex; align-items:center; justify-content:center;
    font-size:13px; font-weight:700; flex-shrink:0;
  }
  .ps-hero-meta .meta-text { display:flex; align-items:center; gap:8px; }

  .palestine-grid {
    display:grid; grid-template-columns:repeat(4,1fr);
    gap:18px; margin-bottom:32px;
  }
  .ps-card {
    border-radius:var(--radius-lg); overflow:hidden;
    cursor:pointer; text-decoration:none; display:flex; flex-direction:column;
    background:#fff; transition:all .3s ease;
    box-shadow:0 1px 4px rgba(0,0,0,.07);
    border:1px solid #f0f0f0;
  }
  .ps-card:hover { transform:translateY(-4px); box-shadow:0 8px 24px rgba(0,0,0,.12); }
  .ps-card .img-wrap { position:relative; height:190px; overflow:hidden; }
  .ps-card .img-wrap img { width:100%; height:100%; object-fit:cover; transition:transform .5s ease; }
  .ps-card:hover .img-wrap img { transform:scale(1.06); }
  .ps-card .img-date {
    position:absolute; bottom:10px; right:10px; z-index:2;
    display:flex; align-items:center; gap:5px;
    font-size:11px; color:#fff; background:rgba(0,0,0,.45);
    padding:4px 10px; border-radius:20px; backdrop-filter:blur(4px);
  }
  .ps-card-body {
    padding:14px 16px 16px; flex:1; display:flex; flex-direction:column;
  }
  .ps-card-body h3 {
    font-size:15px; font-weight:700; line-height:1.7; color:#b33a3a;
    margin-bottom:10px;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
  }
  .ps-card-footer {
    display:flex; align-items:center; gap:8px; margin-top:auto;
    font-size:12px; color:#9ca3af;
  }
  .ps-card-footer .source-dot {
    width:24px; height:24px; border-radius:50%; background:#16a34a;
    color:#fff; display:flex; align-items:center; justify-content:center;
    font-size:10px; font-weight:700; flex-shrink:0;
  }

  /* NEWS GRID */
  .news-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; margin-bottom:32px; }
  .news-grid-2col { grid-template-columns:repeat(2,1fr); }
  .news-card {
    background:var(--card); border:1px solid var(--border);
    border-radius:var(--radius); overflow:hidden; cursor:pointer;
    transition:all .3s ease;
    box-shadow:var(--shadow-sm);
  }
  .news-card:hover {
    transform:translateY(-5px);
    box-shadow:var(--shadow-lg);
    border-color:rgba(26,115,232,.2);
  }
  .card-img { height:175px; overflow:hidden; position:relative; background:var(--bg3); }
  .card-img::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(to bottom,transparent 60%,rgba(0,0,0,.35));
  }
  .card-img img { transition:transform .5s ease; }
  .news-card:hover .card-img img { transform:scale(1.06); }
  .card-body { padding:16px; }
  .card-cat {
    font-size:10px; font-weight:700; padding:4px 10px; border-radius:6px;
    display:inline-block; margin-bottom:10px; letter-spacing:.3px;
  }
  .cat-political { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
  .cat-economic { background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0; }
  .cat-sports { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
  .cat-arts { background:#faf5ff; color:#7c3aed; border:1px solid #ddd6fe; }
  .cat-reports { background:#fffbeb; color:#b45309; border:1px solid #fde68a; }
  .cat-media { background:#fdf4ff; color:#a21caf; border:1px solid #f0abfc; }
  .cat-breaking { background:#fef2f2; color:#dc2626; border:1px solid #fca5a5; }
  .card-title { font-size:15px; font-weight:700; line-height:1.6; margin-bottom:8px; color:var(--text); }
  .card-excerpt {
    font-size:13px; line-height:1.7; color:var(--muted); margin-bottom:10px;
    display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;
  }
  .card-meta { display:flex; align-items:center; justify-content:space-between; }
  .card-source { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--muted); font-weight:500; }
  .source-dot { width:8px; height:8px; border-radius:50%; }
  .card-time { font-size:11px; color:var(--muted2); font-weight:500; }

  /* LIST NEWS */
  .news-list { display:flex; flex-direction:column; gap:12px; margin-bottom:32px; }
  .list-item {
    display:flex; gap:14px; align-items:flex-start;
    background:var(--card); border:1px solid var(--border);
    border-radius:var(--radius); padding:14px; cursor:pointer;
    transition:all .25s ease;
    box-shadow:var(--shadow-sm);
  }
  .list-item:hover { border-color:rgba(26,115,232,.2); box-shadow:var(--shadow-md); transform:translateX(-4px); }
  .list-img { width:90px; height:72px; border-radius:8px; overflow:hidden; flex-shrink:0; background:var(--bg3); }
  .list-img img { width:100%; height:100%; object-fit:cover; transition:transform .4s; }
  .list-item:hover .list-img img { transform:scale(1.06); }
  .list-body { flex:1; }
  .list-title { font-size:14px; font-weight:700; line-height:1.5; margin-bottom:6px; color:var(--text); }
  .list-meta { display:flex; gap:8px; align-items:center; font-size:12px; color:var(--muted); font-weight:500; }
  .rank-num {
    width:30px; height:30px; border-radius:8px; flex-shrink:0;
    background:rgba(26,115,232,.08); color:var(--accent);
    display:flex; align-items:center; justify-content:center;
    font-size:14px; font-weight:800;
  }

  /* SIDEBAR */
  .sidebar { display:flex; flex-direction:column; gap:22px; }
  .sidebar-widget {
    background:var(--card); border:1px solid var(--border);
    border-radius:var(--radius-lg); overflow:hidden;
    box-shadow:var(--shadow-sm);
    transition:box-shadow .3s;
  }
  .sidebar-widget:hover { box-shadow:var(--shadow-md); }
  .widget-header {
    padding:16px 18px; border-bottom:1px solid var(--border);
    font-size:15px; font-weight:700;
    display:flex; align-items:center; gap:8px;
    background:var(--bg2);
  }
  .widget-header .icon { font-size:16px; }
  .widget-body { padding:16px 18px; }

  /* SOURCES */
  .source-item {
    display:flex; align-items:center; gap:12px;
    padding:10px 0; border-bottom:1px solid var(--border);
    cursor:pointer; transition:all .2s;
  }
  .source-item:last-child { border-bottom:none; }
  .source-item:hover { padding-right:6px; }
  .source-logo {
    width:38px; height:38px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:14px; font-weight:800; flex-shrink:0;
  }
  .source-info { flex:1; }
  .source-name { font-size:13px; font-weight:700; color:var(--text); }
  .source-count { font-size:11px; color:var(--muted); margin-top:1px; }
  .source-toggle {
    width:36px; height:20px; border-radius:10px;
    background:var(--accent); position:relative; cursor:pointer;
    transition:background .3s;
  }
  .source-toggle::after {
    content:''; position:absolute;
    width:16px; height:16px; border-radius:50%;
    background:#fff; top:2px; right:2px;
    transition:right .3s; box-shadow:0 1px 3px rgba(0,0,0,.15);
  }
  .source-toggle.off { background:#d1d5db; }
  .source-toggle.off::after { right:18px; }

  /* TRENDING */
  .trend-item {
    display:flex; align-items:center; gap:12px;
    padding:10px 0; border-bottom:1px solid var(--border);
    cursor:pointer; transition:all .2s;
  }
  .trend-item:last-child { border-bottom:none; }
  .trend-item:hover .trend-title { color:var(--accent); }
  .trend-num { font-size:22px; font-weight:900; color:#d1d5db; width:28px; text-align:center; line-height:1; }
  .trend-item:nth-child(1) .trend-num { color:var(--accent); }
  .trend-item:nth-child(2) .trend-num { color:var(--muted); }
  .trend-item:nth-child(3) .trend-num { color:var(--gold); }
  .trend-title { font-size:13px; font-weight:600; line-height:1.5; transition:color .2s; }
  .trend-heat { font-size:11px; color:var(--muted); margin-top:2px; }

  /* WEATHER WIDGET */
  .weather-widget {
    background:linear-gradient(135deg,#1e3a5f,#1a2744);
    border:none;
    border-radius:var(--radius-lg); padding:20px;
    color:#fff;
    box-shadow:var(--shadow-md);
  }
  .weather-widget .section-title { color:#fff; }
  .weather-widget .section-title .line { background:#60a5fa; }
  .weather-main { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
  .weather-temp { font-size:48px; font-weight:300; letter-spacing:-2px; }
  .weather-icon { font-size:52px; }
  .weather-city { font-size:15px; font-weight:700; }
  .weather-desc { font-size:12px; color:rgba(255,255,255,.55); margin-top:3px; }
  .weather-days { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-top:12px; }
  .weather-day {
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.1);
    border-radius:10px; padding:10px 4px; text-align:center; font-size:12px;
    backdrop-filter:blur(4px);
  }
  .weather-day .day { color:rgba(255,255,255,.5); margin-bottom:4px; font-size:11px; }
  .weather-day .temp { font-weight:700; }
  .weather-cities { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; }
  .weather-city-btn {
    background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.15);
    color:rgba(255,255,255,.7); padding:4px 12px; border-radius:20px;
    font-size:11px; cursor:pointer; transition:all .2s; font-family:inherit;
  }
  .weather-city-btn:hover, .weather-city-btn.active {
    background:rgba(96,165,250,.3); border-color:rgba(96,165,250,.5); color:#fff;
  }

  /* CURRENCY WIDGET */
  .currency-widget {
    background:#fff; border:1px solid #f0f0f0;
    border-radius:var(--radius-lg); padding:18px; margin-top:16px;
    box-shadow:0 1px 4px rgba(0,0,0,.06); cursor:pointer; transition:all .2s;
  }
  .currency-widget:hover { box-shadow:0 4px 16px rgba(0,0,0,.1); }
  .currency-row {
    display:flex; align-items:center; justify-content:space-between;
    padding:10px 0; border-bottom:1px solid #f5f5f5;
  }
  .currency-row:last-child { border-bottom:none; }
  .currency-flag { font-size:20px; margin-left:8px; }
  .currency-name { font-size:13px; color:#555; font-weight:500; }
  .currency-rate { font-size:14px; font-weight:700; color:#1a1a2e; direction:ltr; }
  .currency-change { font-size:11px; margin-right:6px; }
  .currency-change.up { color:#16a34a; }
  .currency-change.down { color:#dc2626; }

  /* CURRENCY MODAL */
  .modal-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);
    z-index:9999; align-items:center; justify-content:center;
    backdrop-filter:blur(3px);
  }
  .modal-overlay.show { display:flex; }
  .modal-box {
    background:#fff; border-radius:16px; width:90%; max-width:550px;
    max-height:85vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.25);
    animation:modalIn .3s ease;
  }
  @keyframes modalIn { from { transform:scale(.9); opacity:0; } to { transform:scale(1); opacity:1; } }
  .modal-header {
    display:flex; align-items:center; justify-content:space-between;
    padding:20px 24px; border-bottom:1px solid #f0f0f0;
  }
  .modal-header h2 { font-size:18px; color:#1a1a2e; }
  .modal-close {
    width:32px; height:32px; border-radius:50%; border:none;
    background:#f5f5f5; font-size:18px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:background .2s; font-family:inherit;
  }
  .modal-close:hover { background:#e5e5e5; }
  .modal-body { padding:16px 24px 24px; }
  .modal-currency-row {
    display:flex; align-items:center; justify-content:space-between;
    padding:14px 0; border-bottom:1px solid #f5f5f5;
  }
  .modal-currency-row:last-child { border-bottom:none; }
  .modal-currency-info { display:flex; align-items:center; gap:12px; }
  .modal-currency-flag { font-size:28px; }
  .modal-currency-name { font-size:14px; font-weight:600; color:#333; }
  .modal-currency-code { font-size:11px; color:#999; }
  .modal-currency-rates { text-align:left; direction:ltr; }
  .modal-rate-buy, .modal-rate-sell { font-size:13px; color:#555; }
  .modal-rate-buy span, .modal-rate-sell span { font-weight:700; color:#1a1a2e; }

  /* REELS SECTION */
  .reels-wrap { margin-bottom:28px; }
  .reels-wrap .section-header { margin-bottom:16px; }
  .reels-scroll {
    display:flex; gap:16px; overflow-x:auto; padding:8px 4px 16px;
    scroll-snap-type:x mandatory; scrollbar-width:thin;
  }
  .reels-scroll::-webkit-scrollbar { height:8px; }
  .reels-scroll::-webkit-scrollbar-thumb { background:rgba(0,0,0,.15); border-radius:4px; }
  .reel-card {
    flex:0 0 300px; scroll-snap-align:start;
    background:#000; border-radius:18px; overflow:hidden; position:relative;
    aspect-ratio:9/16; cursor:pointer; isolation:isolate;
    box-shadow:0 10px 30px rgba(0,0,0,.2); transition:transform .3s;
  }
  .reel-card:hover { transform:translateY(-4px); }
  .reel-card iframe {
    position:absolute; left:50%; transform:translateX(-50%);
    width:110%; border:0;
    top:-72px; height:calc(100% + 480px);
  }

  /* POLL WIDGET */
  .poll-option { margin-bottom:12px; }
  .poll-label { display:flex; justify-content:space-between; font-size:13px; margin-bottom:5px; font-weight:500; }
  .poll-bar { height:8px; border-radius:4px; background:var(--bg3); overflow:hidden; }
  .poll-fill { height:100%; border-radius:4px; transition:width 1.2s cubic-bezier(.4,0,.2,1); }

  /* SECTIONS NAV */
  .sections-nav {
    background:var(--card); border-bottom:1px solid var(--border);
    padding:0 24px; display:flex; gap:0; overflow-x:auto;
    scrollbar-width:none;
    box-shadow:0 1px 3px rgba(0,0,0,.04);
  }
  .sections-nav::-webkit-scrollbar { display:none; }
  .sec-btn {
    padding:14px 20px; font-size:13px; font-weight:500;
    color:var(--muted); white-space:nowrap; cursor:pointer;
    border-bottom:2.5px solid transparent; transition:all .2s;
    display:flex; align-items:center; gap:6px;
  }
  .sec-btn:hover { color:var(--text); background:rgba(26,115,232,.03); }
  .sec-btn.active { color:var(--accent); border-bottom-color:var(--accent); font-weight:700; }

  /* NOTIFICATION PANEL */
  .notif-panel {
    position:fixed; top:74px; left:20px; width:380px;
    background:#fff; border:1px solid var(--border);
    border-radius:var(--radius-lg); padding:0; z-index:2000;
    box-shadow:var(--shadow-lg);
    display:none; overflow:hidden;
    animation:slideDown .3s cubic-bezier(.4,0,.2,1);
  }
  .notif-panel.show { display:block; }
  @keyframes slideDown { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
  .notif-header {
    padding:16px 18px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
    background:var(--bg2);
  }
  .notif-title { font-size:16px; font-weight:700; display:flex; align-items:center; gap:8px; }
  .notif-list { max-height:420px; overflow-y:auto; }
  .notif-item {
    padding:14px 18px; border-bottom:1px solid var(--border);
    cursor:pointer; transition:background .2s; display:flex; gap:12px;
  }
  .notif-item:hover { background:var(--bg2); }
  .notif-item.unread { background:#eff6ff; border-right:3px solid var(--accent); }
  .notif-icon { width:38px; height:38px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:16px; }
  .notif-body { flex:1; }
  .notif-text { font-size:13px; line-height:1.5; margin-bottom:4px; font-weight:500; }
  .notif-time { font-size:11px; color:var(--muted); }

  /* USER PANEL */
  .user-panel {
    position:fixed; top:0; right:0; bottom:0; width:420px;
    background:var(--bg2); border-right:1px solid var(--border);
    z-index:3000; transform:translateX(100%);
    transition:transform .35s cubic-bezier(.4,0,.2,1);
    overflow-y:auto;
    box-shadow:-10px 0 40px rgba(0,0,0,.1);
  }
  .user-panel.open { transform:translateX(0); }
  .user-panel-header {
    padding:20px 24px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0; background:var(--bg2); z-index:1;
  }
  .user-panel-body { padding:24px; }
  .close-btn {
    width:36px; height:36px; border-radius:10px;
    background:var(--bg3); border:1px solid var(--border);
    color:var(--text); font-size:18px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:all .2s;
  }
  .close-btn:hover { background:var(--border); }
  .user-profile-card {
    background:linear-gradient(135deg,#1e3a5f,#1a2744);
    border:none;
    border-radius:var(--radius-lg); padding:24px; margin-bottom:24px;
    text-align:center; color:#fff;
  }
  .profile-avatar {
    width:68px; height:68px; border-radius:var(--radius-lg);
    background:linear-gradient(135deg,#0d9488,#0f766e);
    display:flex; align-items:center; justify-content:center;
    font-size:26px; font-weight:800; margin:0 auto 14px;
    border:3px solid rgba(13,148,136,.4); color:#fff;
    box-shadow:0 4px 16px rgba(13,148,136,.3);
  }
  .profile-name { font-size:20px; font-weight:800; margin-bottom:4px; }
  .profile-plan {
    display:inline-block; background:rgba(217,119,6,.2);
    color:#fbbf24; padding:4px 16px; border-radius:6px;
    font-size:11px; font-weight:700; margin-top:8px;
  }
  .pref-section { margin-bottom:24px; }
  .pref-title { font-size:12px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.8px; margin-bottom:14px; }
  .pref-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
  .pref-item {
    background:#fff; border:1px solid var(--border);
    border-radius:10px; padding:10px 12px;
    display:flex; align-items:center; gap:8px;
    cursor:pointer; transition:all .2s; font-size:13px; font-weight:500;
  }
  .pref-item.selected { background:#eff6ff; border-color:rgba(26,115,232,.3); color:var(--accent); }
  .pref-item .check { width:18px; height:18px; border-radius:5px; border:2px solid var(--border); flex-shrink:0; transition:all .2s; display:flex; align-items:center; justify-content:center; font-size:10px; }
  .pref-item.selected .check { background:var(--accent); border-color:var(--accent); color:#fff; }
  .notif-pref { display:flex; align-items:center; justify-content:space-between; padding:12px 0; border-bottom:1px solid var(--border); font-size:13px; font-weight:500; }
  .toggle-sw { width:44px; height:24px; border-radius:12px; background:var(--accent); position:relative; cursor:pointer; transition:background .3s; flex-shrink:0; }
  .toggle-sw::after { content:''; position:absolute; width:18px; height:18px; border-radius:50%; background:#fff; top:3px; right:3px; transition:right .3s; box-shadow:0 1px 3px rgba(0,0,0,.15); }
  .toggle-sw.off { background:#d1d5db; }
  .toggle-sw.off::after { right:23px; }
  .save-btn {
    width:100%; padding:14px; border-radius:var(--radius);
    background:linear-gradient(135deg,#1a73e8,#4f46e5);
    border:none; color:#fff; font-size:15px; font-weight:700;
    cursor:pointer; transition:all .2s; margin-top:20px;
    font-family:inherit;
  }
  .save-btn:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(26,115,232,.35); }

  /* OVERLAY */
  .overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:2999; display:none; backdrop-filter:blur(4px); }
  .overlay.show { display:block; }

  /* MEDIA SECTION */
  .media-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:32px; }
  .media-card {
    border-radius:var(--radius); overflow:hidden; position:relative;
    cursor:pointer; aspect-ratio:16/9; background:var(--bg3);
    transition:all .3s ease;
  }
  .media-card:hover { transform:scale(1.03); box-shadow:var(--shadow-lg); }
  .media-card img { width:100%; height:100%; object-fit:cover; }
  .media-card::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(to bottom,transparent 30%,rgba(0,0,0,.75));
  }
  .media-play {
    position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
    width:44px; height:44px; border-radius:50%;
    background:rgba(255,255,255,.15); backdrop-filter:blur(8px);
    display:flex; align-items:center; justify-content:center;
    font-size:16px; z-index:2; border:2px solid rgba(255,255,255,.3);
    transition:all .3s;
  }
  .media-card:hover .media-play { background:rgba(26,115,232,.8); border-color:rgba(26,115,232,.4); transform:translate(-50%,-50%) scale(1.1); }
  .media-caption { position:absolute; bottom:10px; left:10px; right:10px; font-size:12px; z-index:2; line-height:1.5; color:#fff; font-weight:600; }

  /* ADD SOURCE */
  .add-source-card {
    background:var(--bg2); border:2px dashed var(--border);
    border-radius:var(--radius); padding:24px; text-align:center;
    cursor:pointer; transition:all .3s; margin-bottom:20px;
  }
  .add-source-card:hover { border-color:var(--accent); background:#eff6ff; }
  .add-source-icon { font-size:28px; margin-bottom:8px; }
  .add-source-text { font-size:13px; color:var(--muted); font-weight:500; }

  /* STATS BAR */
  .stats-bar {
    background:var(--card); border-bottom:1px solid var(--border);
    padding:16px 24px; display:flex; gap:0; overflow-x:auto;
    justify-content:center;
  }
  .stat-item {
    display:flex; align-items:center; gap:10px; white-space:nowrap;
    padding:0 28px; border-left:1px solid var(--border);
  }
  .stat-item:last-child { border-left:none; }
  .stat-icon {
    width:40px; height:40px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:18px; background:var(--bg);
  }
  .stat-val { font-size:18px; font-weight:800; color:var(--text); }
  .stat-label { font-size:11px; color:var(--muted); margin-top:2px; font-weight:500; }

  /* FOOTER */
  footer {
    background:var(--header-bg);
    padding:48px 24px 24px; margin-top:40px;
    color:rgba(255,255,255,.7);
  }
  .footer-inner {
    max-width:1400px; margin:0 auto;
    display:grid; grid-template-columns:2fr 1fr 1fr 1fr; gap:40px;
    padding-bottom:32px;
    border-bottom:1px solid rgba(255,255,255,.08);
  }
  .footer-brand {}
  .footer-logo { font-size:26px; font-weight:900; color:#fff; margin-bottom:10px; }
  .footer-logo span { color:#60a5fa; }
  .footer-desc { font-size:13px; line-height:1.8; color:rgba(255,255,255,.45); max-width:320px; }
  .footer-col-title { font-size:13px; font-weight:700; color:#fff; margin-bottom:14px; text-transform:uppercase; letter-spacing:.5px; }
  .footer-col a {
    display:block; font-size:13px; color:rgba(255,255,255,.45);
    padding:5px 0; transition:all .2s; text-decoration:none;
  }
  .footer-col a:hover { color:#60a5fa; padding-right:6px; }
  .footer-bottom {
    max-width:1400px; margin:0 auto;
    display:flex; align-items:center; justify-content:space-between;
    padding-top:20px; flex-wrap:wrap; gap:12px;
  }
  .footer-copy { font-size:12px; color:rgba(255,255,255,.3); }
  .footer-social { display:flex; gap:10px; }
  .footer-social a {
    width:36px; height:36px; border-radius:8px;
    background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
    display:flex; align-items:center; justify-content:center;
    color:rgba(255,255,255,.5); font-size:14px;
    transition:all .2s; text-decoration:none;
  }
  .footer-social a:hover { background:rgba(26,115,232,.2); color:#60a5fa; border-color:rgba(96,165,250,.3); }

  /* ADD SOURCE MODAL */
  .modal {
    position:fixed; inset:0; z-index:4000; display:none;
    align-items:center; justify-content:center;
  }
  .modal.show { display:flex; }
  .modal-box {
    background:var(--card); border:1px solid var(--border);
    border-radius:var(--radius-lg); padding:32px; width:500px; max-width:90vw;
    animation:popIn .3s cubic-bezier(.4,0,.2,1);
    box-shadow:var(--shadow-lg);
  }
  @keyframes popIn { from{opacity:0;transform:scale(.95) translateY(10px)} to{opacity:1;transform:scale(1) translateY(0)} }
  .modal-title { font-size:20px; font-weight:800; margin-bottom:6px; }
  .modal-sub { font-size:13px; color:var(--muted); margin-bottom:24px; }
  .form-group { margin-bottom:18px; }
  .form-label { font-size:12px; font-weight:700; color:var(--muted); margin-bottom:6px; display:block; text-transform:uppercase; letter-spacing:.3px; }
  .form-input {
    width:100%; background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; padding:11px 16px; color:var(--text); font-size:14px; outline:none;
    transition:all .2s; font-family:inherit;
  }
  .form-input:focus { border-color:var(--accent); background:#fff; box-shadow:0 0 0 3px rgba(26,115,232,.1); }
  .form-input::placeholder { color:var(--muted2); }
  .form-actions { display:flex; gap:10px; margin-top:24px; }
  .btn-primary {
    flex:1; padding:12px; border-radius:10px;
    background:linear-gradient(135deg,#1a73e8,#4f46e5);
    border:none; color:#fff; font-size:14px; font-weight:700; cursor:pointer;
    transition:all .2s; font-family:inherit;
  }
  .btn-primary:hover { box-shadow:0 6px 20px rgba(26,115,232,.35); transform:translateY(-1px); }
  .btn-secondary {
    padding:12px 22px; border-radius:10px;
    background:var(--bg2); border:1px solid var(--border);
    color:var(--muted); font-size:14px; cursor:pointer; transition:all .2s; font-family:inherit;
  }
  .btn-secondary:hover { color:var(--text); border-color:var(--muted); background:var(--bg3); }

  .tag-row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
  .tag {
    padding:6px 16px; border-radius:8px; font-size:12px; cursor:pointer;
    background:var(--bg2); border:1px solid var(--border); color:var(--muted);
    transition:all .2s; font-weight:600;
  }
  .tag.active { background:#eff6ff; border-color:rgba(26,115,232,.3); color:var(--accent); }

  /* RESPONSIVE */
  @media(max-width:1100px) {
    .main-layout { grid-template-columns:1fr; }
    .footer-inner { grid-template-columns:1fr 1fr; }
  }
  @media(max-width:900px) {
    .hero-grid { grid-template-columns:1fr; height:auto; }
    .hero-main { min-height:300px; grid-row:auto; }
    .hero-side { min-height:200px; }
    .ps-hero { grid-template-columns:1fr; }
    .ps-hero-img { min-height:250px; }
    .ps-hero-text { padding:20px; }
    .ps-hero-text h3 { font-size:18px; }
    .palestine-grid { grid-template-columns:repeat(2,1fr); }
    .news-grid { grid-template-columns:repeat(2,1fr); }
    .media-grid { grid-template-columns:repeat(2,1fr); }
    nav { display:none; }
    .search-box { width:160px; }
    .topbar { display:none; }
    header { height:56px; }
    .stats-bar { justify-content:flex-start; }
    .stat-item { padding:0 16px; }
    .footer-inner { grid-template-columns:1fr; gap:24px; }
  }
  @media(max-width:560px) {
    .news-grid { grid-template-columns:1fr; }
    .news-grid-2col { grid-template-columns:1fr; }
    .media-grid { grid-template-columns:1fr 1fr; }
    .hero-main { min-height:240px; }
    .hero-side { min-height:180px; }
    .hero-title { font-size:18px; }
    .hero-excerpt { display:none; }
    .palestine-grid { grid-template-columns:1fr 1fr; }
    .ps-card .img-wrap { height:160px; }
    .ps-hero-text h3 { font-size:17px; }
    .main-layout { padding:16px 12px; }
    .stats-bar { padding:12px 16px; }
    .user-panel { width:100%; }
  }
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <div class="topbar-left">
    <span><span class="live-dot"></span> مباشر الآن</span>
    <span><?php echo date('l, j F Y'); ?></span>
    <span id="liveTime"><?php echo date('h:i A'); ?></span>
  </div>
  <div class="topbar-right">
    <span class="weather-badge" id="topWeather">☀ القدس --°</span>
    <span>USD: 0.71 JD</span>
    <span>EUR: 0.78 JD</span>
  </div>
</div>

<!-- HEADER -->
<header>
  <a class="logo" href="index.php">
    <div class="logo-icon">N</div>
    <div>
      <div class="logo-text"><?php echo e(getSetting('site_name', SITE_NAME)); ?></div>
      <div class="logo-sub"><?php echo e(getSetting('site_tagline', SITE_TAGLINE)); ?></div>
    </div>
  </a>

  <nav>
    <a href="category.php?type=breaking" class="breaking">🔴 عاجل</a>
    <a href="index.php" class="active">الرئيسية</a>
    <a href="category.php?type=latest">آخر الأخبار</a>
    <a href="category.php?slug=political">سياسة</a>
    <a href="category.php?slug=economy">اقتصاد</a>
    <a href="category.php?slug=sports">رياضة</a>
    <a href="category.php?slug=arts">فنون</a>
    <a href="category.php?slug=media">ميديا</a>
    <a href="category.php?slug=reports">تقارير</a>
    <a href="reels.php">🎬 ريلز</a>
  </nav>

  <div class="header-actions">
    <div class="search-box">
      <span class="search-icon">&#x1F50D;</span>
      <input type="text" placeholder="ابحث عن خبر...">
    </div>
    <div class="icon-btn" onclick="toggleNotif()">
      🔔
      <span class="notif-badge"><?php echo e($unreadCount); ?></span>
    </div>
    <div class="icon-btn" onclick="openAddSource()">➕</div>
    <div class="avatar" onclick="openUserPanel()">أ</div>
  </div>
</header>

<!-- SECTIONS NAV -->
<div class="sections-nav">
  <div class="sec-btn active" onclick="filterSection(this,'all')">📰 الكل</div>
  <div class="sec-btn" onclick="filterSection(this,'breaking')">🔴 عاجل</div>
  <div class="sec-btn" onclick="filterSection(this,'latest')">⏱ آخر الأخبار</div>
  <div class="sec-btn" onclick="filterSection(this,'political')">🏛 سياسة</div>
  <div class="sec-btn" onclick="filterSection(this,'economy')">💹 اقتصاد</div>
  <div class="sec-btn" onclick="filterSection(this,'sports')">⚽ رياضة</div>
  <div class="sec-btn" onclick="filterSection(this,'arts')">🎨 فنون</div>
  <div class="sec-btn" onclick="filterSection(this,'media')">🎥 ميديا</div>
  <div class="sec-btn" onclick="filterSection(this,'reports')">📊 تقارير</div>
</div>

<!-- TICKER -->
<div class="ticker-wrap">
  <div class="ticker-label">عاجل</div>
  <div class="ticker-content">
    <?php foreach ($tickerItems as $item): ?>
      <div class="ticker-item"><?php echo e($item['text']); ?></div>
    <?php endforeach; ?>
    <?php foreach ($tickerItems as $item): ?>
      <div class="ticker-item"><?php echo e($item['text']); ?></div>
    <?php endforeach; ?>
  </div>
</div>

<!-- STATS BAR -->
<div class="stats-bar">
  <div class="stat-item">
    <span class="stat-icon">📰</span>
    <div>
      <div class="stat-val"><?php echo number_format($totalArticles); ?></div>
      <div class="stat-label">خبر</div>
    </div>
  </div>
  <div class="stat-item">
    <span class="stat-icon">🌐</span>
    <div>
      <div class="stat-val"><?php echo $totalSources; ?></div>
      <div class="stat-label">مصدر نشط</div>
    </div>
  </div>
  <div class="stat-item">
    <span class="stat-icon">👁</span>
    <div>
      <div class="stat-val">3.2M</div>
      <div class="stat-label">مشاهدة اليوم</div>
    </div>
  </div>
  <div class="stat-item">
    <span class="stat-icon">🔥</span>
    <div>
      <div class="stat-val">سياسة</div>
      <div class="stat-label">الأكثر تداولاً</div>
    </div>
  </div>
  <div class="stat-item">
    <span class="stat-icon">⏱</span>
    <div>
      <div class="stat-val">منذ 2 دق</div>
      <div class="stat-label">آخر تحديث</div>
    </div>
  </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-layout">
  <div class="main-col">

    <!-- PALESTINE NEWS -->
    <div class="section-header">
      <div class="section-title"><div class="line" style="background:#16a34a"></div>🇵🇸 أحدث الأخبار الفلسطينية</div>
    </div>
    <?php if (!empty($palestineNews)): ?>
      <?php $psFirst = $palestineNews[0]; ?>
      <a class="ps-hero" href="article.php?id=<?php echo (int)$psFirst['id']; ?>">
        <div class="ps-hero-text">
          <h3><?php echo e($psFirst['title']); ?></h3>
          <div class="ps-hero-excerpt"><?php echo e(mb_substr(strip_tags($psFirst['content'] ?? $psFirst['excerpt'] ?? ''), 0, 200)); ?></div>
          <div class="ps-hero-meta">
            <span class="source-icon"><?php echo e(mb_substr($psFirst['source_name'], 0, 1)); ?></span>
            <div class="meta-text">
              <span><?php echo e($psFirst['source_name']); ?></span>
              <span class="meta-dot"></span>
              <span><?php echo timeAgo($psFirst['published_at']); ?></span>
            </div>
          </div>
        </div>
        <div class="ps-hero-img">
          <img src="<?php echo e($psFirst['image_url'] ?? 'https://picsum.photos/seed/ps0/800/500'); ?>" alt="">
        </div>
      </a>

      <div class="palestine-grid">
        <?php for ($pIdx = 1; $pIdx < count($palestineNews); $pIdx++): $article = $palestineNews[$pIdx]; ?>
          <a class="ps-card" href="article.php?id=<?php echo (int)$article['id']; ?>">
            <div class="img-wrap">
              <img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/ps' . $pIdx . '/400/300'); ?>" alt="">
              <div class="img-date"><?php echo timeAgo($article['published_at']); ?></div>
            </div>
            <div class="ps-card-body">
              <h3><?php echo e($article['title']); ?></h3>
              <div class="ps-card-footer">
                <span class="source-dot"><?php echo e(mb_substr($article['source_name'], 0, 1)); ?></span>
                <span><?php echo e($article['source_name']); ?></span>
              </div>
            </div>
          </a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

    <!-- BREAKING NEWS -->
    <div id="breaking" class="section-header">
      <div class="section-title"><div class="line" style="background:var(--red)"></div>🔴 أخبار عاجلة</div>
      <a class="see-all" href="category.php?type=breaking">عرض الكل ›</a>
    </div>
    <div class="news-list" style="margin-bottom:28px">
      <?php foreach ($breakingNews as $article): ?>
        <a class="list-item" href="article.php?id=<?php echo (int)$article['id']; ?>">
          <div class="list-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/brk' . rand(1,10) . '/200/150'); ?>" alt=""></div>
          <div class="list-body">
            <div class="card-cat cat-breaking">عاجل</div>
            <div class="list-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt" style="margin-bottom:6px"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 120)); ?></div>
            <div class="list-meta"><span>🌐 <?php echo e($article['source_name']); ?></span><span>·</span><span><?php echo timeAgo($article['published_at']); ?></span><span>·</span><span>👁 <?php echo formatViews($article['view_count']); ?></span></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- LATEST NEWS -->
    <div id="latest" class="section-header">
      <div class="section-title blue"><div class="line"></div>⏱ آخر الأخبار</div>
      <a class="see-all" href="category.php?type=latest">عرض الكل ›</a>
    </div>
    <div class="news-grid" style="margin-bottom:28px">
      <?php foreach ($latestArticles as $article): ?>
        <a class="news-card" href="article.php?id=<?php echo (int)$article['id']; ?>">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/lat' . rand(1,10) . '/400/300'); ?>" alt=""></div>
          <div class="card-body">
            <span class="card-cat <?php echo $article['css_class'] ?? 'cat-political'; ?>"><?php echo e($article['cat_name']); ?></span>
            <div class="card-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- POLITICAL NEWS -->
    <div id="political" class="section-header">
      <div class="section-title"><div class="line" style="background:#b05a5a"></div>🏛 أخبار سياسية</div>
      <a class="see-all" href="category.php?slug=political">عرض الكل ›</a>
    </div>
    <div class="news-grid news-grid-2col" style="margin-bottom:28px">
      <?php foreach ($politicalNews as $article): ?>
        <a class="news-card" href="article.php?id=<?php echo (int)$article['id']; ?>">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/pol' . rand(1,10) . '/400/300'); ?>" alt=""></div>
          <div class="card-body">
            <span class="card-cat cat-political">سياسة</span>
            <div class="card-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- ECONOMY -->
    <div id="economy" class="section-header">
      <div class="section-title green"><div class="line"></div>💹 أخبار اقتصادية</div>
      <a class="see-all" href="category.php?slug=economy">عرض الكل ›</a>
    </div>
    <div class="news-grid" style="margin-bottom:28px">
      <?php foreach ($economyNews as $article): ?>
        <a class="news-card" href="article.php?id=<?php echo (int)$article['id']; ?>">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/eco' . rand(1,10) . '/400/300'); ?>" alt=""></div>
          <div class="card-body">
            <span class="card-cat cat-economic">اقتصاد</span>
            <div class="card-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#85c1a3'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- SPORTS -->
    <div id="sports" class="section-header">
      <div class="section-title"><div class="line" style="background:#5a85b0"></div>⚽ رياضة</div>
      <a class="see-all" href="category.php?slug=sports">عرض الكل ›</a>
    </div>
    <div class="news-grid" style="margin-bottom:28px">
      <?php foreach ($sportsNews as $article): ?>
        <a class="news-card" href="article.php?id=<?php echo (int)$article['id']; ?>">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/sp' . rand(1,10) . '/400/300'); ?>" alt=""></div>
          <div class="card-body">
            <span class="card-cat cat-sports">رياضة</span>
            <div class="card-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- ARTS -->
    <div id="arts" class="section-header">
      <div class="section-title"><div class="line" style="background:#7a5a9a"></div>🎨 فنون وثقافة</div>
      <a class="see-all" href="category.php?slug=arts">عرض الكل ›</a>
    </div>
    <div class="news-grid news-grid-2col" style="margin-bottom:28px">
      <?php foreach ($artsNews as $article): ?>
        <a class="news-card" href="article.php?id=<?php echo (int)$article['id']; ?>">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/art' . rand(1,10) . '/400/300'); ?>" alt=""></div>
          <div class="card-body">
            <span class="card-cat cat-arts">فنون</span>
            <div class="card-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#a08cc8'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- MEDIA SECTION -->
    <div id="media" class="section-header">
      <div class="section-title"><div class="line" style="background:#8a5a8a"></div>🎥 ميديا</div>
      <a class="see-all" href="category.php?slug=media">عرض الكل ›</a>
    </div>
    <div class="media-grid" style="margin-bottom:28px">
      <?php foreach ($mediaItems as $media): ?>
        <div class="media-card">
          <img src="<?php echo e($media['image_url'] ?? 'https://picsum.photos/seed/med' . rand(1,10) . '/400/225'); ?>" alt="">
          <div class="media-play">▶</div>
          <div class="media-caption"><?php echo e($media['title']); ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- REPORTS -->
    <div id="reports" class="section-header">
      <div class="section-title gold"><div class="line"></div>📊 التقارير</div>
      <a class="see-all" href="category.php?slug=reports">عرض الكل ›</a>
    </div>
    <div class="news-grid" style="margin-bottom:28px">
      <?php foreach ($reportsNews as $article): ?>
        <a class="news-card" href="article.php?id=<?php echo (int)$article['id']; ?>">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/rep' . rand(1,10) . '/400/300'); ?>" alt=""></div>
          <div class="card-body">
            <span class="card-cat cat-reports">تقرير</span>
            <div class="card-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#c9ab6e'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- REELS -->
    <?php if (!empty($homeReels)): ?>
    <div id="reels" class="reels-wrap">
      <div class="section-header">
        <div class="section-title"><div class="line" style="background:#fd1d1d"></div>🎬 ريلز</div>
        <a class="see-all" href="reels.php">عرض الكل ›</a>
      </div>
      <div class="reels-scroll">
        <?php foreach ($homeReels as $reel): ?>
          <div class="reel-card" title="<?php echo e($reel['caption'] ?? ''); ?>">
            <iframe src="https://www.instagram.com/reel/<?php echo e($reel['shortcode']); ?>/embed/" scrolling="no" allowtransparency="true" allow="autoplay; encrypted-media" allowfullscreen loading="lazy"></iframe>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /main-col -->

  <!-- SIDEBAR -->
  <div class="sidebar">

    <!-- WEATHER -->
    <div class="weather-widget">
      <div class="section-title" style="margin-bottom:14px;font-size:14px"><div class="line" style="background:var(--accent2)"></div>☀️ الطقس الآن</div>
      <div class="weather-cities">
        <button class="weather-city-btn active" data-city="Jerusalem" data-name="القدس">القدس</button>
        <button class="weather-city-btn" data-city="Gaza" data-name="غزة">غزة</button>
        <button class="weather-city-btn" data-city="Ramallah" data-name="رام الله">رام الله</button>
        <button class="weather-city-btn" data-city="Nablus" data-name="نابلس">نابلس</button>
        <button class="weather-city-btn" data-city="Hebron" data-name="الخليل">الخليل</button>
        <button class="weather-city-btn" data-city="Jenin" data-name="جنين">جنين</button>
      </div>
      <div class="weather-main">
        <div>
          <div class="weather-temp" id="wTemp">--°</div>
          <div class="weather-city" id="wCity">القدس، فلسطين</div>
          <div class="weather-desc" id="wDesc">جارٍ التحميل...</div>
        </div>
        <div class="weather-icon" id="wIcon">🌤</div>
      </div>
      <div class="weather-days" id="wForecast">
        <div class="weather-day"><div class="day">--</div><div>🌤</div><div class="temp">--°</div></div>
        <div class="weather-day"><div class="day">--</div><div>🌤</div><div class="temp">--°</div></div>
        <div class="weather-day"><div class="day">--</div><div>🌤</div><div class="temp">--°</div></div>
        <div class="weather-day"><div class="day">--</div><div>🌤</div><div class="temp">--°</div></div>
      </div>
    </div>

    <!-- CURRENCY -->
    <div class="currency-widget" onclick="openCurrencyModal()">
      <div style="font-size:14px;font-weight:700;margin-bottom:12px;color:#1a1a2e">💱 أسعار الصرف</div>
      <div class="currency-row">
        <div style="display:flex;align-items:center"><span class="currency-flag">🇺🇸</span><span class="currency-name">دولار أمريكي</span></div>
        <div><span class="currency-rate" id="cUSD">--</span> <span class="currency-change" id="cUSDc"></span></div>
      </div>
      <div class="currency-row">
        <div style="display:flex;align-items:center"><span class="currency-flag">🇮🇱</span><span class="currency-name">شيقل</span></div>
        <div><span class="currency-rate" id="cILS">--</span> <span class="currency-change" id="cILSc"></span></div>
      </div>
      <div class="currency-row">
        <div style="display:flex;align-items:center"><span class="currency-flag">🇯🇴</span><span class="currency-name">دينار أردني</span></div>
        <div><span class="currency-rate" id="cJOD">--</span> <span class="currency-change" id="cJODc"></span></div>
      </div>
      <div style="text-align:center;font-size:11px;color:#aaa;margin-top:8px">اضغط لعرض التفاصيل</div>
    </div>

    <!-- TRENDING -->
    <div class="sidebar-widget">
      <div class="widget-header"><span class="icon">🔥</span>الأكثر تداولاً</div>
      <div class="widget-body" style="padding:8px 16px">
        <?php $trendNum = 1; ?>
        <?php foreach ($trends as $trend): ?>
          <div class="trend-item">
            <div class="trend-num"><?php echo $trendNum; ?></div>
            <div>
              <div class="trend-title"><?php echo e($trend['title']); ?></div>
              <div class="trend-heat">🔥 <?php echo number_format($trend['tweet_count']); ?> تغريدة</div>
            </div>
          </div>
          <?php $trendNum++; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- SOURCES -->
    <div class="sidebar-widget">
      <div class="widget-header"><span class="icon">🌐</span>مصادرك النشطة</div>
      <div class="widget-body" style="padding:6px 16px">
        <?php foreach (array_slice($sources, 0, 5) as $source): ?>
          <div class="source-item">
            <div class="source-logo" style="background:<?php echo e($source['logo_bg']); ?>;color:<?php echo e($source['logo_color']); ?>"><?php echo e($source['logo_letter']); ?></div>
            <div class="source-info">
              <div class="source-name"><?php echo e($source['name']); ?></div>
              <div class="source-count"><?php echo rand(50, 300); ?> خبر اليوم</div>
            </div>
            <div class="source-toggle" onclick="this.classList.toggle('off')"></div>
          </div>
        <?php endforeach; ?>
        <div class="add-source-card" style="margin-top:12px;margin-bottom:0;padding:16px" onclick="openAddSource()">
          <div class="add-source-icon">➕</div>
          <div class="add-source-text" style="font-size:13px">إضافة مصدر جديد</div>
        </div>
      </div>
    </div>

    <!-- POLL -->
    <?php if ($poll): ?>
      <div class="sidebar-widget">
        <div class="widget-header"><span class="icon">📊</span>استطلاع الرأي</div>
        <div class="widget-body">
          <div style="font-size:13px;font-weight:600;margin-bottom:14px;line-height:1.5"><?php echo e($poll['question']); ?></div>
          <?php $totalVotes = array_sum(array_column($poll['options'], 'votes')); ?>
          <?php foreach ($poll['options'] as $option): ?>
            <?php $percentage = $totalVotes > 0 ? round(($option['votes'] / $totalVotes) * 100) : 0; ?>
            <div class="poll-option">
              <div class="poll-label"><span><?php echo e($option['text']); ?></span><span style="color:var(--accent);font-weight:700"><?php echo $percentage; ?>%</span></div>
              <div class="poll-bar"><div class="poll-fill" style="width:<?php echo $percentage; ?>%;background:var(--accent)"></div></div>
            </div>
          <?php endforeach; ?>
          <div style="font-size:11px;color:var(--muted);margin-top:10px;text-align:center"><?php echo number_format($totalVotes); ?> مصوّت · ينتهي خلال 2 يوم</div>
        </div>
      </div>
    <?php endif; ?>

    <!-- MOST READ -->
    <div class="sidebar-widget">
      <div class="widget-header"><span class="icon">👁</span>الأكثر قراءة</div>
      <div class="widget-body" style="padding:8px 16px">
        <?php $rankNum = 1; ?>
        <?php foreach (array_slice($mostRead, 0, 3) as $article): ?>
          <a class="list-item" href="article.php?id=<?php echo (int)$article['id']; ?>" style="padding:8px 0;background:none;border:none;<?php echo $rankNum < 3 ? 'border-bottom:1px solid var(--border);' : ''; ?>">
            <div class="rank-num"><?php echo $rankNum; ?></div>
            <div class="list-body">
              <div class="list-title" style="font-size:12px"><?php echo e(substr($article['title'], 0, 40) . '...'); ?></div>
              <div class="list-meta"><span>👁 <?php echo formatViews($article['view_count']); ?></span></div>
            </div>
          </a>
          <?php $rankNum++; ?>
        <?php endforeach; ?>
      </div>
    </div>

  </div><!-- /sidebar -->
</div><!-- /main-layout -->

<!-- FOOTER -->
<footer>
  <div class="footer-inner">
    <div class="footer-brand">
      <div class="footer-logo"><?php echo e(getSetting('site_name', SITE_NAME)); ?></div>
      <p class="footer-desc">منصتك الشاملة لتجميع الأخبار من مصادر متعددة وموثوقة. نوفر لك تجربة إخبارية متكاملة بأحدث التقنيات.</p>
    </div>
    <div class="footer-col">
      <div class="footer-col-title">الأقسام</div>
      <a href="category.php?slug=political">سياسة</a>
      <a href="category.php?slug=economy">اقتصاد</a>
      <a href="category.php?slug=sports">رياضة</a>
      <a href="category.php?slug=arts">فنون وثقافة</a>
      <a href="category.php?slug=tech">تكنولوجيا</a>
    </div>
    <div class="footer-col">
      <div class="footer-col-title">المزيد</div>
      <a href="category.php?slug=reports">تقارير</a>
      <a href="category.php?slug=media">ميديا</a>
      <a href="category.php?type=breaking">أخبار عاجلة</a>
      <a href="category.php?type=latest">آخر الأخبار</a>
    </div>
    <div class="footer-col">
      <div class="footer-col-title">روابط مهمة</div>
      <a href="#">من نحن</a>
      <a href="#">سياسة الخصوصية</a>
      <a href="#">الشروط والأحكام</a>
      <a href="#">اتصل بنا</a>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo e(getSetting('site_name', SITE_NAME)); ?> &mdash; جميع الحقوق محفوظة</div>
    <div class="footer-social">
      <a href="#" title="Twitter">&#x1D54F;</a>
      <a href="#" title="Facebook">f</a>
      <a href="#" title="Instagram">&#x1D540;</a>
      <a href="#" title="YouTube">&#x25B6;</a>
    </div>
  </div>
</footer>

<!-- NOTIFICATION PANEL -->
<div class="notif-panel" id="notifPanel">
  <div class="notif-header">
    <div class="notif-title">🔔 الإشعارات <span style="background:var(--red);color:#fff;padding:2px 8px;border-radius:20px;font-size:11px"><?php echo $unreadCount; ?></span></div>
    <a href="#" style="font-size:12px;color:var(--muted)">تعليم الكل كمقروء</a>
  </div>
  <div class="notif-list">
    <?php foreach ($notifications as $notification): ?>
      <div class="notif-item <?php echo !$notification['is_read'] ? 'unread' : ''; ?>">
        <div class="notif-icon" style="background:#fae8e8">🔔</div>
        <div class="notif-body">
          <div class="notif-text"><?php echo e($notification['message']); ?></div>
          <div class="notif-time"><?php echo timeAgo($notification['created_at']); ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="padding:12px 16px;border-top:1px solid var(--border);text-align:center">
    <a href="#" style="font-size:12px;color:var(--accent)">عرض جميع الإشعارات</a>
  </div>
</div>

<!-- USER PANEL OVERLAY -->
<div class="overlay" id="overlay" onclick="closeAll()"></div>

<!-- USER PANEL -->
<div class="user-panel" id="userPanel">
  <div class="user-panel-header">
    <div style="font-size:16px;font-weight:700">لوحة التحكم</div>
    <button class="close-btn" onclick="closeAll()">×</button>
  </div>
  <div class="user-panel-body">
    <div class="user-profile-card">
      <div class="profile-avatar">أ</div>
      <div class="profile-name">أسامة المعايضة</div>
      <div style="font-size:12px;color:var(--muted)">osama.fa.mayadmeh@gmail.com</div>
      <div class="profile-plan">⭐ Premium</div>
      <div style="display:flex;gap:20px;justify-content:center;margin-top:14px">
        <div style="text-align:center"><div style="font-size:18px;font-weight:700">284</div><div style="font-size:11px;color:var(--muted)">مقالة محفوظة</div></div>
        <div style="text-align:center"><div style="font-size:18px;font-weight:700">12</div><div style="font-size:11px;color:var(--muted)">مصدر نشط</div></div>
        <div style="text-align:center"><div style="font-size:18px;font-weight:700">47</div><div style="font-size:11px;color:var(--muted)">يوم متواصل</div></div>
      </div>
    </div>

    <div class="pref-section">
      <div class="pref-title">تفضيلات الأخبار</div>
      <div class="pref-grid">
        <div class="pref-item selected" onclick="this.classList.toggle('selected')"><span class="check">✓</span>🏛 سياسة</div>
        <div class="pref-item selected" onclick="this.classList.toggle('selected')"><span class="check">✓</span>💹 اقتصاد</div>
        <div class="pref-item selected" onclick="this.classList.toggle('selected')"><span class="check">✓</span>⚽ رياضة</div>
        <div class="pref-item" onclick="this.classList.toggle('selected')"><span class="check"></span>🎨 فنون</div>
        <div class="pref-item selected" onclick="this.classList.toggle('selected')"><span class="check">✓</span>💻 تكنولوجيا</div>
        <div class="pref-item" onclick="this.classList.toggle('selected')"><span class="check"></span>🏥 صحة</div>
        <div class="pref-item" onclick="this.classList.toggle('selected')"><span class="check"></span>🔬 علوم</div>
        <div class="pref-item" onclick="this.classList.toggle('selected')"><span class="check"></span>🌍 بيئة</div>
      </div>
    </div>

    <div class="pref-section">
      <div class="pref-title">إعدادات الإشعارات</div>
      <div class="notif-pref">
        <span>🔴 أخبار عاجلة</span>
        <div class="toggle-sw" onclick="this.classList.toggle('off')"></div>
      </div>
      <div class="notif-pref">
        <span>⚽ نتائج الرياضة</span>
        <div class="toggle-sw" onclick="this.classList.toggle('off')"></div>
      </div>
      <div class="notif-pref">
        <span>💹 تحركات السوق</span>
        <div class="toggle-sw off" onclick="this.classList.toggle('off')"></div>
      </div>
      <div class="notif-pref">
        <span>📊 تقارير جديدة</span>
        <div class="toggle-sw" onclick="this.classList.toggle('off')"></div>
      </div>
      <div class="notif-pref">
        <span>🌙 وضع عدم الإزعاج</span>
        <div class="toggle-sw off" onclick="this.classList.toggle('off')"></div>
      </div>
    </div>

    <div class="pref-section">
      <div class="pref-title">المظهر واللغة</div>
      <div style="display:flex;gap:8px;margin-bottom:12px">
        <div style="flex:1;padding:10px;border-radius:10px;background:var(--bg);border:2px solid var(--accent);text-align:center;font-size:12px;cursor:pointer">🌙 داكن</div>
        <div style="flex:1;padding:10px;border-radius:10px;background:var(--bg3);border:1px solid var(--border);text-align:center;font-size:12px;cursor:pointer;color:var(--muted)">☀️ فاتح</div>
        <div style="flex:1;padding:10px;border-radius:10px;background:var(--bg3);border:1px solid var(--border);text-align:center;font-size:12px;cursor:pointer;color:var(--muted)">🌓 تلقائي</div>
      </div>
    </div>

    <button class="save-btn">💾 حفظ التفضيلات</button>
  </div>
</div>

<!-- ADD SOURCE MODAL -->
<div class="modal" id="addSourceModal">
  <div class="overlay show" onclick="closeAddSource()" style="position:fixed"></div>
  <div class="modal-box" style="position:relative;z-index:1">
    <div class="modal-title">➕ إضافة مصدر إخباري</div>
    <div class="modal-sub">أضف موقعاً إخبارياً جديداً لمتابعة أخباره</div>

    <div class="form-group">
      <label class="form-label">رابط الموقع (RSS أو URL)</label>
      <input class="form-input" type="text" placeholder="https://example-news.com/rss">
    </div>
    <div class="form-group">
      <label class="form-label">اسم المصدر</label>
      <input class="form-input" type="text" placeholder="مثال: صحيفة الغد">
    </div>
    <div class="form-group">
      <label class="form-label">التصنيف</label>
      <div class="tag-row">
        <div class="tag active" onclick="this.classList.toggle('active')">🏛 سياسة</div>
        <div class="tag" onclick="this.classList.toggle('active')">💹 اقتصاد</div>
        <div class="tag" onclick="this.classList.toggle('active')">⚽ رياضة</div>
        <div class="tag" onclick="this.classList.toggle('active')">🎨 فنون</div>
        <div class="tag" onclick="this.classList.toggle('active')">💻 تقنية</div>
        <div class="tag" onclick="this.classList.toggle('active')">🌍 عام</div>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">تفعيل الإشعارات من هذا المصدر</label>
      <div style="display:flex;align-items:center;gap:10px;font-size:13px">
        <div class="toggle-sw" onclick="this.classList.toggle('off')"></div>
        <span style="color:var(--muted)">إرسال إشعار لكل خبر جديد</span>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeAddSource()">إلغاء</button>
      <button class="btn-primary" onclick="closeAddSource()">✔ إضافة المصدر</button>
    </div>
  </div>
</div>

<script>
  // NOTIFICATION PANEL
  function toggleNotif() {
    const p = document.getElementById('notifPanel');
    const ov = document.getElementById('overlay');
    const isOpen = p.classList.contains('show');
    if(isOpen) { p.classList.remove('show'); ov.classList.remove('show'); }
    else { p.classList.add('show'); ov.classList.add('show'); }
  }

  // USER PANEL
  function openUserPanel() {
    document.getElementById('userPanel').classList.add('open');
    document.getElementById('overlay').classList.add('show');
  }

  function closeAll() {
    document.getElementById('userPanel').classList.remove('open');
    document.getElementById('notifPanel').classList.remove('show');
    document.getElementById('overlay').classList.remove('show');
  }

  // ADD SOURCE MODAL
  function openAddSource() {
    document.getElementById('addSourceModal').classList.add('show');
  }
  function closeAddSource() {
    document.getElementById('addSourceModal').classList.remove('show');
  }

  // SECTIONS NAV
  function filterSection(el, section) {
    document.querySelectorAll('.sec-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    if(section !== 'all') {
      const target = document.getElementById(section);
      if(target) target.scrollIntoView({ behavior:'smooth', block:'start' });
    }
  }

  function scrollToSection(id) {
    const el = document.getElementById(id);
    if(el) el.scrollIntoView({ behavior:'smooth', block:'start' });
  }

  // LIVE CLOCK
  setInterval(() => {
    const now = new Date();
    const h = now.getHours() % 12 || 12;
    const m = String(now.getMinutes()).padStart(2,'0');
    const ampm = now.getHours() >= 12 ? 'PM' : 'AM';
    const el = document.getElementById('liveTime');
    if(el) el.textContent = h + ':' + m + ' ' + ampm;
  }, 1000);

  // ANIMATED COUNTERS ON LOAD
  window.addEventListener('load', () => {
    // Animate poll bars
    setTimeout(() => {
      document.querySelectorAll('.poll-fill').forEach(b => {
        const w = b.style.width;
        b.style.width = '0%';
        setTimeout(() => { b.style.width = w; }, 100);
      });
    }, 300);

    // Fade in cards on scroll
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if(entry.isIntersecting) {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
          observer.unobserve(entry.target);
        }
      });
    }, { threshold:0.1 });
    document.querySelectorAll('.news-card, .list-item, .media-card').forEach(el => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(20px)';
      el.style.transition = 'opacity .5s ease, transform .5s ease';
      observer.observe(el);
    });
  });

  // SAVE PREFS FEEDBACK
  document.querySelectorAll('.save-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      this.textContent = 'تم الحفظ بنجاح!';
      this.style.background = 'linear-gradient(135deg,#0d9488,#0f766e)';
      setTimeout(() => {
        this.textContent = 'حفظ التفضيلات';
        this.style.background = 'linear-gradient(135deg,#1a73e8,#4f46e5)';
      }, 2000);
    });
  });

  // SIMULATE NEW NOTIFICATION BADGE
  let notifCount = <?php echo $unreadCount; ?>;
  setInterval(() => {
    const badge = document.querySelector('.notif-badge');
    if(Math.random() > 0.7) {
      notifCount++;
      badge.textContent = notifCount;
      badge.style.transform = 'scale(1.4)';
      setTimeout(() => badge.style.transform = '', 300);
    }
  }, 15000);
</script>
<!-- CURRENCY MODAL -->
<div class="modal-overlay" id="currencyModal">
  <div class="modal-box">
    <div class="modal-header">
      <h2>💱 أسعار صرف العملات</h2>
      <button class="modal-close" onclick="closeCurrencyModal()">&times;</button>
    </div>
    <div class="modal-body" id="currencyModalBody">
      <div style="text-align:center;padding:30px;color:#999">جارٍ تحميل الأسعار...</div>
    </div>
    <div style="padding:0 24px 16px;text-align:center;font-size:11px;color:#bbb">
      الأسعار تقريبية وقد تختلف عن أسعار السوق الفعلية
    </div>
  </div>
</div>

<script>
// WEATHER API (Open-Meteo - free, no key needed)
const weatherCodes = {
  0:'☀️', 1:'🌤', 2:'⛅', 3:'☁️', 45:'🌫', 48:'🌫',
  51:'🌦', 53:'🌦', 55:'🌧', 61:'🌧', 63:'🌧', 65:'🌧',
  71:'🌨', 73:'🌨', 75:'❄️', 80:'🌦', 81:'🌧', 82:'⛈', 95:'⛈', 96:'⛈', 99:'⛈'
};
const weatherDesc = {
  0:'صافي', 1:'صافي غالباً', 2:'غائم جزئياً', 3:'غائم', 45:'ضبابي', 48:'ضبابي',
  51:'رذاذ خفيف', 53:'رذاذ', 55:'رذاذ كثيف', 61:'مطر خفيف', 63:'مطر', 65:'مطر غزير',
  71:'ثلوج خفيفة', 73:'ثلوج', 75:'ثلوج كثيفة', 80:'أمطار متفرقة', 81:'أمطار', 82:'أمطار غزيرة',
  95:'عواصف رعدية', 96:'عواصف مع برد', 99:'عواصف شديدة'
};
const dayNames = ['الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
const dayShort = ['الأح','الإث','الثل','الأر','الخم','الجم','السب'];

const cities = {
  Jerusalem: { lat:31.7683, lon:35.2137, name:'القدس' },
  Gaza: { lat:31.5017, lon:34.4668, name:'غزة' },
  Ramallah: { lat:31.9038, lon:35.2034, name:'رام الله' },
  Nablus: { lat:32.2211, lon:35.2544, name:'نابلس' },
  Hebron: { lat:31.5326, lon:35.0998, name:'الخليل' },
  Jenin: { lat:32.4607, lon:35.2953, name:'جنين' }
};

function fetchWeather(cityKey) {
  const c = cities[cityKey];
  if (!c) return;
  const url = `https://api.open-meteo.com/v1/forecast?latitude=${c.lat}&longitude=${c.lon}&current=temperature_2m,weather_code&daily=weather_code,temperature_2m_max&timezone=Asia/Jerusalem&forecast_days=5`;
  fetch(url).then(r => r.json()).then(data => {
    const cur = data.current;
    const temp = Math.round(cur.temperature_2m);
    const code = cur.weather_code;
    document.getElementById('wTemp').textContent = temp + '°';
    document.getElementById('wCity').textContent = c.name + '، فلسطين';
    document.getElementById('wDesc').textContent = weatherDesc[code] || 'غير معروف';
    document.getElementById('wIcon').textContent = weatherCodes[code] || '🌤';
    document.getElementById('topWeather').textContent = (weatherCodes[code]||'☀') + ' ' + c.name + ' ' + temp + '°';

    // Forecast
    const daily = data.daily;
    let forecastHTML = '';
    for (let i = 1; i <= 4; i++) {
      const d = new Date(daily.time[i]);
      const dCode = daily.weather_code[i];
      const dTemp = Math.round(daily.temperature_2m_max[i]);
      forecastHTML += `<div class="weather-day"><div class="day">${dayShort[d.getDay()]}</div><div>${weatherCodes[dCode]||'🌤'}</div><div class="temp">${dTemp}°</div></div>`;
    }
    document.getElementById('wForecast').innerHTML = forecastHTML;
  }).catch(() => {});
}

// City buttons
document.querySelectorAll('.weather-city-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.weather-city-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    fetchWeather(this.dataset.city);
  });
});

// Load default
fetchWeather('Jerusalem');

// CURRENCY (using exchangerate.host or frankfurter.app - free)
const currencyData = [
  { code:'USD', flag:'🇺🇸', name:'دولار أمريكي', nameEn:'US Dollar' },
  { code:'ILS', flag:'🇮🇱', name:'شيقل إسرائيلي', nameEn:'Israeli Shekel' },
  { code:'JOD', flag:'🇯🇴', name:'دينار أردني', nameEn:'Jordanian Dinar' },
  { code:'EUR', flag:'🇪🇺', name:'يورو', nameEn:'Euro' },
  { code:'GBP', flag:'🇬🇧', name:'جنيه إسترليني', nameEn:'British Pound' },
  { code:'SAR', flag:'🇸🇦', name:'ريال سعودي', nameEn:'Saudi Riyal' },
  { code:'EGP', flag:'🇪🇬', name:'جنيه مصري', nameEn:'Egyptian Pound' },
  { code:'TRY', flag:'🇹🇷', name:'ليرة تركية', nameEn:'Turkish Lira' },
  { code:'AED', flag:'🇦🇪', name:'درهم إماراتي', nameEn:'UAE Dirham' },
  { code:'KWD', flag:'🇰🇼', name:'دينار كويتي', nameEn:'Kuwaiti Dinar' }
];

let exchangeRates = {};

function fetchCurrency() {
  fetch('https://api.frankfurter.app/latest?from=USD&to=ILS,JOD,EUR,GBP,SAR,EGP,TRY,AED,KWD')
    .then(r => r.json())
    .then(data => {
      exchangeRates = data.rates;
      exchangeRates['USD'] = 1;
      // Update sidebar
      document.getElementById('cUSD').textContent = '1.00 $';
      document.getElementById('cILS').textContent = (exchangeRates['ILS'] || 3.65).toFixed(2) + ' ₪';
      document.getElementById('cJOD').textContent = (exchangeRates['JOD'] || 0.71).toFixed(3) + ' د.أ';
    }).catch(() => {
      document.getElementById('cUSD').textContent = '1.00 $';
      document.getElementById('cILS').textContent = '3.65 ₪';
      document.getElementById('cJOD').textContent = '0.709 د.أ';
    });
}
fetchCurrency();

function openCurrencyModal() {
  const modal = document.getElementById('currencyModal');
  modal.classList.add('show');
  let html = '';
  const symbols = { USD:'$', ILS:'₪', JOD:'د.أ', EUR:'€', GBP:'£', SAR:'ر.س', EGP:'ج.م', TRY:'₺', AED:'د.إ', KWD:'د.ك' };
  currencyData.forEach(c => {
    const rate = c.code === 'USD' ? 1 : (exchangeRates[c.code] || '--');
    const rateStr = typeof rate === 'number' ? rate.toFixed(c.code === 'JOD' || c.code === 'KWD' ? 3 : 2) : rate;
    html += `
      <div class="modal-currency-row">
        <div class="modal-currency-info">
          <span class="modal-currency-flag">${c.flag}</span>
          <div>
            <div class="modal-currency-name">${c.name}</div>
            <div class="modal-currency-code">${c.code} - ${c.nameEn}</div>
          </div>
        </div>
        <div class="modal-currency-rates">
          <div class="modal-rate-buy"><span>${rateStr}</span> ${symbols[c.code] || ''}</div>
        </div>
      </div>`;
  });
  html += '<div style="text-align:center;font-size:11px;color:#bbb;margin-top:12px">سعر الصرف مقابل 1 دولار أمريكي</div>';
  document.getElementById('currencyModalBody').innerHTML = html;
}

function closeCurrencyModal() {
  document.getElementById('currencyModal').classList.remove('show');
}
document.getElementById('currencyModal').addEventListener('click', function(e) {
  if (e.target === this) closeCurrencyModal();
});
</script>

</body>
</html>
