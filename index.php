<?php
/**
 * نيوزفلو - الصفحة الرئيسية
 * موقع تجميع الأخبار من مصادر متعددة
 */

require_once __DIR__ . '/includes/functions.php';

// جلب البيانات من قاعدة البيانات
$heroArticles = getHeroArticles();
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

?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>نيوزفلو - مجمع المصادر الإخبارية</title>
<style>
  :root {
    --bg: #f3ede6;
    --bg2: #faf6f1;
    --bg3: #ede6dd;
    --card: #ffffff;
    --border: #e0d6ca;
    --accent: #5a85b0;
    --accent2: #4a9b8e;
    --accent3: #6aab87;
    --red: #b05a5a;
    --text: #2c3040;
    --muted: #7a7060;
    --muted2: #a89f93;
    --gold: #a0823a;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Segoe UI',Tahoma,Arial,sans-serif; background:var(--bg); color:var(--text); overflow-x:hidden; }

  /* SCROLLBAR */
  ::-webkit-scrollbar { width:6px; }
  ::-webkit-scrollbar-track { background:var(--bg3); }
  ::-webkit-scrollbar-thumb { background:#b8c8d8; border-radius:3px; }

  /* TOP BAR */
  .topbar {
    background:linear-gradient(90deg,#e8dfd4,#ede6dd);
    padding:6px 20px;
    display:flex; align-items:center; justify-content:space-between;
    font-size:12px; color:var(--muted);
    border-bottom:1px solid var(--border);
  }
  .topbar-left { display:flex; gap:16px; align-items:center; }
  .topbar-right { display:flex; gap:12px; align-items:center; }
  .live-dot { width:7px; height:7px; border-radius:50%; background:var(--red); animation:pulse 1.2s infinite; display:inline-block; margin-left:5px; }
  @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.4)} }
  .weather-badge { background:rgba(90,133,176,.12); border:1px solid rgba(90,133,176,.25); padding:2px 10px; border-radius:20px; color:#5a85b0; font-size:11px; }

  /* HEADER */
  header {
    background:linear-gradient(135deg,#faf6f1 0%,#f3ede6 50%,#faf6f1 100%);
    padding:14px 24px;
    display:flex; align-items:center; justify-content:space-between;
    border-bottom:2px solid var(--border);
    position:sticky; top:0; z-index:1000;
    box-shadow:0 2px 16px rgba(0,0,0,.08);
  }
  .logo { display:flex; align-items:center; gap:12px; text-decoration:none; }
  .logo-icon {
    width:44px; height:44px; border-radius:12px;
    background:linear-gradient(135deg,#5a85b0,#3d6690);
    display:flex; align-items:center; justify-content:center;
    font-size:22px; font-weight:900; color:#fff;
    box-shadow:0 2px 12px rgba(90,133,176,.3);
  }
  .logo-text { font-size:22px; font-weight:800; color:#fff; letter-spacing:-0.5px; }
  .logo-text span { color:var(--accent); }
  .logo-sub { font-size:10px; color:var(--muted); margin-top:-2px; }

  /* NAV */
  nav { display:flex; gap:4px; align-items:center; }
  nav a {
    padding:7px 14px; border-radius:8px; text-decoration:none;
    color:var(--muted); font-size:13px; font-weight:500;
    transition:all .2s; white-space:nowrap;
  }
  nav a:hover, nav a.active { background:rgba(90,133,176,.12); color:var(--accent); }
  nav a.breaking { background:rgba(176,90,90,.1); color:#b05a5a; animation:breakingPulse 2s infinite; }
  @keyframes breakingPulse { 0%,100%{background:rgba(176,90,90,.08)} 50%{background:rgba(176,90,90,.16)} }

  /* HEADER ACTIONS */
  .header-actions { display:flex; gap:10px; align-items:center; }
  .search-box {
    display:flex; align-items:center; gap:8px;
    background:rgba(255,255,255,.8); border:1px solid var(--border);
    border-radius:10px; padding:7px 14px; width:200px;
    transition:all .3s;
  }
  .search-box:focus-within { border-color:var(--accent); background:#fff; width:240px; box-shadow:0 2px 8px rgba(90,133,176,.15); }
  .search-box input { background:none; border:none; outline:none; color:var(--text); font-size:13px; width:100%; }
  .search-box input::placeholder { color:var(--muted2); }
  .icon-btn {
    width:38px; height:38px; border-radius:10px;
    background:rgba(255,255,255,.8); border:1px solid var(--border);
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:all .2s; position:relative; color:var(--muted); font-size:16px;
  }
  .icon-btn:hover { background:rgba(90,133,176,.12); border-color:var(--accent); color:var(--accent); }
  .notif-badge {
    position:absolute; top:-4px; right:-4px;
    width:18px; height:18px; border-radius:50%;
    background:var(--red); font-size:10px; color:#fff;
    display:flex; align-items:center; justify-content:center;
    border:2px solid var(--bg);
  }
  .avatar {
    width:38px; height:38px; border-radius:10px;
    background:linear-gradient(135deg,#4a9b8e,#3a7a70);
    display:flex; align-items:center; justify-content:center;
    font-weight:700; font-size:15px; cursor:pointer; color:#fff;
    border:2px solid rgba(74,155,142,.3);
    transition:all .2s;
  }
  .avatar:hover { border-color:var(--accent2); box-shadow:0 0 10px rgba(74,155,142,.25); }

  /* TICKER */
  .ticker-wrap {
    background:linear-gradient(90deg,#c8daea,#b8cedf);
    padding:8px 0; overflow:hidden; position:relative;
  }
  .ticker-label {
    position:absolute; right:0; top:0; bottom:0;
    background:#5a85b0; color:#fff; padding:0 20px;
    display:flex; align-items:center; font-weight:700; font-size:13px;
    z-index:2; white-space:nowrap;
    box-shadow:-8px 0 15px rgba(0,0,0,.4);
  }
  .ticker-content {
    display:flex; animation:tickerScroll 40s linear infinite;
    padding-right:120px;
  }
  .ticker-content:hover { animation-play-state:paused; }
  @keyframes tickerScroll {
    0% { transform:translateX(0); }
    100% { transform:translateX(50%); }
  }
  .ticker-item {
    white-space:nowrap; padding:0 30px; font-size:13px;
    display:flex; align-items:center; gap:10px; color:#2c3040;
  }
  .ticker-item::before { content:'●'; color:rgba(90,133,176,.5); font-size:8px; }

  /* LAYOUT */
  .container { max-width:1400px; margin:0 auto; padding:0 20px; }
  .main-layout { display:grid; grid-template-columns:1fr 320px; gap:24px; padding:24px 20px; max-width:1400px; margin:0 auto; }

  /* SECTION HEADER */
  .section-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
  .section-title { display:flex; align-items:center; gap:10px; font-size:18px; font-weight:700; }
  .section-title .line { width:4px; height:22px; border-radius:2px; background:var(--accent); }
  .section-title.blue .line { background:var(--accent2); }
  .section-title.green .line { background:var(--accent3); }
  .section-title.gold .line { background:var(--gold); }
  .see-all {
    font-size:12px; color:var(--accent); text-decoration:none;
    background:rgba(90,133,176,.1); padding:5px 14px; border-radius:20px;
    border:1px solid rgba(90,133,176,.2); transition:all .2s;
  }
  .see-all:hover { background:rgba(90,133,176,.18); }

  /* HERO */
  .hero-grid { display:grid; grid-template-columns:1.8fr 1fr; grid-template-rows:auto auto; gap:16px; margin-bottom:28px; }
  .hero-main {
    grid-row:1/3; border-radius:16px; overflow:hidden;
    position:relative; cursor:pointer; min-height:380px;
    background:linear-gradient(135deg,#c8d8e8,#d8e4ee);
  }
  .hero-main img, .news-card img { width:100%; height:100%; object-fit:cover; }
  .hero-main .img-wrap { position:absolute; inset:0; }
  .hero-main .img-wrap::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(to top,rgba(0,0,0,.9) 0%,rgba(0,0,0,.4) 50%,transparent 100%);
  }
  .hero-overlay { position:absolute; bottom:0; left:0; right:0; padding:24px; z-index:2; }
  .source-badge {
    display:inline-flex; align-items:center; gap:6px;
    background:rgba(90,133,176,.8); color:#fff; padding:4px 12px; border-radius:20px;
    font-size:11px; font-weight:600; margin-bottom:10px;
  }
  .hero-title { font-size:20px; font-weight:700; line-height:1.4; margin-bottom:10px; color:#fff; }
  .meta { display:flex; align-items:center; gap:12px; font-size:12px; color:rgba(255,255,255,.6); }
  .meta-dot { width:4px; height:4px; border-radius:50%; background:rgba(255,255,255,.4); }

  .hero-side {
    border-radius:16px; overflow:hidden; position:relative;
    cursor:pointer; min-height:180px;
    background:var(--card);
    border:1px solid var(--border);
    transition:transform .2s, box-shadow .2s;
  }
  .hero-side:hover { transform:translateY(-3px); box-shadow:0 12px 30px rgba(0,0,0,.4); }
  .hero-side-img { height:120px; overflow:hidden; position:relative; }
  .hero-side-img::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(to bottom,transparent 40%,rgba(0,0,0,.6));
  }
  .hero-side-body { padding:12px 14px; }
  .hero-side-body h3 { font-size:14px; font-weight:600; line-height:1.4; margin-bottom:6px; }

  /* NEWS GRID */
  .news-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:28px; }
  .news-grid-2col { grid-template-columns:repeat(2,1fr); }
  .news-card {
    background:var(--card); border:1px solid var(--border);
    border-radius:14px; overflow:hidden; cursor:pointer;
    transition:transform .2s, box-shadow .2s, border-color .2s;
  }
  .news-card:hover {
    transform:translateY(-4px);
    box-shadow:0 12px 28px rgba(90,133,176,.15);
    border-color:rgba(90,133,176,.3);
  }
  .card-img { height:160px; overflow:hidden; position:relative; background:var(--bg3); }
  .card-img::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(to bottom,transparent 50%,rgba(0,0,0,.5));
  }
  .card-img img { transition:transform .4s; }
  .news-card:hover .card-img img { transform:scale(1.06); }
  .card-body { padding:14px; }
  .card-cat {
    font-size:10px; font-weight:700; padding:3px 10px; border-radius:20px;
    display:inline-block; margin-bottom:8px; text-transform:uppercase; letter-spacing:.5px;
  }
  .cat-political { background:#fae8e8; color:#8f4040; border:1px solid #f0cccc; }
  .cat-economic { background:#e5f3ec; color:#2e7a50; border:1px solid #c0dece; }
  .cat-sports { background:#e5eef8; color:#2d5e8a; border:1px solid #bcd0e8; }
  .cat-arts { background:#ede8f5; color:#5a3d8a; border:1px solid #d4c8ea; }
  .cat-reports { background:#f5ede0; color:#7a5520; border:1px solid #e0cca8; }
  .cat-media { background:#f5e8f5; color:#7a3d7a; border:1px solid #e0c4e0; }
  .cat-breaking { background:#fae8e8; color:#8f3030; border:1px solid #f0c0c0; }
  .card-title { font-size:14px; font-weight:600; line-height:1.5; margin-bottom:8px; }
  .card-meta { display:flex; align-items:center; justify-content:space-between; }
  .card-source { display:flex; align-items:center; gap:6px; font-size:11px; color:var(--muted); }
  .source-dot { width:8px; height:8px; border-radius:50%; }
  .card-time { font-size:11px; color:var(--muted2); }

  /* LIST NEWS */
  .news-list { display:flex; flex-direction:column; gap:10px; margin-bottom:28px; }
  .list-item {
    display:flex; gap:12px; align-items:flex-start;
    background:var(--card); border:1px solid var(--border);
    border-radius:12px; padding:12px; cursor:pointer;
    transition:all .2s;
  }
  .list-item:hover { border-color:rgba(90,133,176,.25); background:rgba(90,133,176,.05); }
  .list-img { width:80px; height:65px; border-radius:8px; overflow:hidden; flex-shrink:0; background:var(--bg3); }
  .list-img img { width:100%; height:100%; object-fit:cover; }
  .list-body { flex:1; }
  .list-title { font-size:13px; font-weight:600; line-height:1.5; margin-bottom:5px; }
  .list-meta { display:flex; gap:8px; align-items:center; font-size:11px; color:var(--muted); }
  .rank-num {
    width:28px; height:28px; border-radius:8px; flex-shrink:0;
    background:rgba(90,133,176,.12); color:var(--accent);
    display:flex; align-items:center; justify-content:center;
    font-size:13px; font-weight:700;
  }

  /* SIDEBAR */
  .sidebar { display:flex; flex-direction:column; gap:20px; }
  .sidebar-widget {
    background:var(--card); border:1px solid var(--border);
    border-radius:16px; overflow:hidden;
  }
  .widget-header {
    padding:14px 16px; border-bottom:1px solid var(--border);
    font-size:14px; font-weight:700;
    display:flex; align-items:center; gap:8px;
  }
  .widget-header .icon { font-size:16px; }
  .widget-body { padding:14px 16px; }

  /* SOURCES */
  .source-item {
    display:flex; align-items:center; gap:10px;
    padding:9px 0; border-bottom:1px solid var(--border);
    cursor:pointer; transition:all .2s;
  }
  .source-item:last-child { border-bottom:none; }
  .source-item:hover { padding-right:4px; }
  .source-logo {
    width:36px; height:36px; border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    font-size:13px; font-weight:700; flex-shrink:0;
  }
  .source-info { flex:1; }
  .source-name { font-size:13px; font-weight:600; }
  .source-count { font-size:11px; color:var(--muted); }
  .source-toggle {
    width:32px; height:18px; border-radius:9px;
    background:#5a85b0; position:relative; cursor:pointer;
    transition:background .2s;
  }
  .source-toggle::after {
    content:''; position:absolute;
    width:14px; height:14px; border-radius:50%;
    background:#fff; top:2px; right:2px;
    transition:right .2s;
  }
  .source-toggle.off { background:#ccc; }
  .source-toggle.off::after { right:16px; }

  /* TRENDING */
  .trend-item {
    display:flex; align-items:center; gap:10px;
    padding:8px 0; border-bottom:1px solid var(--border);
    cursor:pointer; transition:all .2s;
  }
  .trend-item:last-child { border-bottom:none; }
  .trend-item:hover { color:var(--accent); }
  .trend-num { font-size:20px; font-weight:900; color:#d0c8be; width:24px; text-align:center; }
  .trend-item:nth-child(1) .trend-num { color:#5a85b0; }
  .trend-item:nth-child(2) .trend-num { color:var(--muted2); }
  .trend-item:nth-child(3) .trend-num { color:var(--gold); }
  .trend-title { font-size:12px; font-weight:500; line-height:1.4; }
  .trend-heat { font-size:10px; color:var(--muted); margin-top:2px; }

  /* WEATHER WIDGET */
  .weather-widget {
    background:linear-gradient(135deg,#deeaf5,#cddff0);
    border:1px solid rgba(90,133,176,.2);
    border-radius:16px; padding:16px;
  }
  .weather-main { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
  .weather-temp { font-size:42px; font-weight:300; }
  .weather-icon { font-size:48px; }
  .weather-city { font-size:14px; font-weight:600; }
  .weather-desc { font-size:12px; color:var(--muted); margin-top:2px; }
  .weather-days { display:grid; grid-template-columns:repeat(4,1fr); gap:6px; margin-top:10px; }
  .weather-day {
    background:rgba(255,255,255,.6); border-radius:8px;
    padding:8px 4px; text-align:center; font-size:11px;
  }
  .weather-day .day { color:var(--muted); margin-bottom:4px; }
  .weather-day .temp { font-weight:600; }

  /* POLL WIDGET */
  .poll-option { margin-bottom:10px; }
  .poll-label { display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px; }
  .poll-bar { height:8px; border-radius:4px; background:#e8e0d5; overflow:hidden; }
  .poll-fill { height:100%; border-radius:4px; transition:width 1s ease; }

  /* SECTIONS NAV */
  .sections-nav {
    background:#faf6f1; border-bottom:1px solid var(--border);
    padding:0 24px; display:flex; gap:0; overflow-x:auto;
    scrollbar-width:none;
  }
  .sections-nav::-webkit-scrollbar { display:none; }
  .sec-btn {
    padding:12px 18px; font-size:13px; font-weight:500;
    color:var(--muted); white-space:nowrap; cursor:pointer;
    border-bottom:2px solid transparent; transition:all .2s;
    display:flex; align-items:center; gap:6px;
  }
  .sec-btn:hover { color:var(--text); }
  .sec-btn.active { color:#5a85b0; border-bottom-color:#5a85b0; font-weight:600; }

  /* NOTIFICATION PANEL */
  .notif-panel {
    position:fixed; top:70px; left:20px; width:360px;
    background:#fff; border:1px solid var(--border);
    border-radius:16px; padding:0; z-index:2000;
    box-shadow:0 12px 40px rgba(0,0,0,.12);
    display:none; overflow:hidden;
    animation:slideDown .25s ease;
  }
  .notif-panel.show { display:block; }
  @keyframes slideDown { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
  .notif-header {
    padding:14px 16px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
  }
  .notif-title { font-size:15px; font-weight:700; display:flex; align-items:center; gap:8px; }
  .notif-list { max-height:400px; overflow-y:auto; }
  .notif-item {
    padding:12px 16px; border-bottom:1px solid var(--border);
    cursor:pointer; transition:background .2s; display:flex; gap:10px;
  }
  .notif-item:hover { background:rgba(255,255,255,.04); }
  .notif-item.unread { background:#f0f5fa; }
  .notif-icon { width:36px; height:36px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:16px; }
  .notif-body { flex:1; }
  .notif-text { font-size:12px; line-height:1.5; margin-bottom:3px; }
  .notif-time { font-size:10px; color:var(--muted); }

  /* USER PANEL */
  .user-panel {
    position:fixed; top:0; right:0; bottom:0; width:400px;
    background:#faf6f1; border-right:1px solid var(--border);
    z-index:3000; transform:translateX(100%);
    transition:transform .3s ease;
    overflow-y:auto;
  }
  .user-panel.open { transform:translateX(0); }
  .user-panel-header {
    padding:20px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0; background:#faf6f1; z-index:1;
  }
  .user-panel-body { padding:20px; }
  .close-btn {
    width:34px; height:34px; border-radius:8px;
    background:var(--bg3); border:1px solid var(--border);
    color:var(--text); font-size:18px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
  }
  .user-profile-card {
    background:linear-gradient(135deg,#e8f0f8,#deeaf5);
    border:1px solid rgba(90,133,176,.2);
    border-radius:16px; padding:20px; margin-bottom:20px;
    text-align:center;
  }
  .profile-avatar {
    width:64px; height:64px; border-radius:16px;
    background:linear-gradient(135deg,#4a9b8e,#3a7a70);
    display:flex; align-items:center; justify-content:center;
    font-size:24px; font-weight:700; margin:0 auto 12px;
    border:2px solid rgba(74,155,142,.3); color:#fff;
  }
  .profile-name { font-size:18px; font-weight:700; margin-bottom:4px; }
  .profile-plan {
    display:inline-block; background:rgba(160,130,58,.12);
    color:var(--gold); padding:3px 14px; border-radius:20px;
    font-size:11px; font-weight:600; margin-top:6px;
  }
  .pref-section { margin-bottom:22px; }
  .pref-title { font-size:13px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px; }
  .pref-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
  .pref-item {
    background:#fff; border:1px solid var(--border);
    border-radius:10px; padding:10px 12px;
    display:flex; align-items:center; gap:8px;
    cursor:pointer; transition:all .2s; font-size:13px;
  }
  .pref-item.selected { background:#e8f0f8; border-color:rgba(90,133,176,.4); color:var(--accent); }
  .pref-item .check { width:16px; height:16px; border-radius:4px; border:1.5px solid var(--border); flex-shrink:0; transition:all .2s; display:flex; align-items:center; justify-content:center; font-size:10px; }
  .pref-item.selected .check { background:var(--accent); border-color:var(--accent); }
  .notif-pref { display:flex; align-items:center; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--border); font-size:13px; }
  .toggle-sw { width:44px; height:24px; border-radius:12px; background:#5a85b0; position:relative; cursor:pointer; transition:background .2s; flex-shrink:0; }
  .toggle-sw::after { content:''; position:absolute; width:18px; height:18px; border-radius:50%; background:#fff; top:3px; right:3px; transition:right .2s; }
  .toggle-sw.off { background:#ccc; }
  .toggle-sw.off::after { right:23px; }
  .save-btn {
    width:100%; padding:12px; border-radius:12px;
    background:linear-gradient(90deg,#5a85b0,#3d6690);
    border:none; color:#fff; font-size:14px; font-weight:700;
    cursor:pointer; transition:all .2s; margin-top:16px;
  }
  .save-btn:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(90,133,176,.3); }

  /* OVERLAY */
  .overlay { position:fixed; inset:0; background:rgba(44,48,64,.3); z-index:2999; display:none; backdrop-filter:blur(2px); }
  .overlay.show { display:block; }

  /* MEDIA SECTION */
  .media-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:28px; }
  .media-card {
    border-radius:12px; overflow:hidden; position:relative;
    cursor:pointer; aspect-ratio:16/9; background:var(--bg3);
    transition:transform .2s;
  }
  .media-card:hover { transform:scale(1.03); }
  .media-card img { width:100%; height:100%; object-fit:cover; }
  .media-card::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(to bottom,transparent,rgba(0,0,0,.7));
  }
  .media-play {
    position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
    width:40px; height:40px; border-radius:50%;
    background:rgba(255,255,255,.2); backdrop-filter:blur(4px);
    display:flex; align-items:center; justify-content:center;
    font-size:16px; z-index:2; border:2px solid rgba(255,255,255,.4);
  }
  .media-caption { position:absolute; bottom:8px; left:8px; right:8px; font-size:11px; z-index:2; line-height:1.4; color:#fff; }

  /* ADD SOURCE */
  .add-source-card {
    background:#fff; border:2px dashed var(--border);
    border-radius:14px; padding:24px; text-align:center;
    cursor:pointer; transition:all .2s; margin-bottom:20px;
  }
  .add-source-card:hover { border-color:var(--accent); background:#eef4fa; }
  .add-source-icon { font-size:32px; margin-bottom:8px; }
  .add-source-text { font-size:14px; color:var(--muted); }

  /* STATS BAR */
  .stats-bar {
    background:#faf6f1; border-top:1px solid var(--border); border-bottom:1px solid var(--border);
    padding:12px 24px; display:flex; gap:32px; overflow-x:auto;
  }
  .stat-item { display:flex; align-items:center; gap:8px; white-space:nowrap; }
  .stat-icon { font-size:18px; }
  .stat-val { font-size:18px; font-weight:700; }
  .stat-label { font-size:11px; color:var(--muted); margin-top:1px; }

  /* FOOTER */
  footer {
    background:#faf6f1; border-top:1px solid var(--border);
    padding:30px 24px; margin-top:30px;
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:16px;
  }
  .footer-logo { font-size:20px; font-weight:800; }
  .footer-logo span { color:var(--accent); }
  .footer-links { display:flex; gap:20px; }
  .footer-links a { font-size:12px; color:var(--muted); text-decoration:none; }
  .footer-links a:hover { color:var(--accent); }
  .footer-copy { font-size:11px; color:var(--muted2); }

  /* ADD SOURCE MODAL */
  .modal {
    position:fixed; inset:0; z-index:4000; display:none;
    align-items:center; justify-content:center;
  }
  .modal.show { display:flex; }
  .modal-box {
    background:#faf6f1; border:1px solid var(--border);
    border-radius:20px; padding:28px; width:480px; max-width:90vw;
    animation:popIn .25s ease;
  }
  @keyframes popIn { from{opacity:0;transform:scale(.95)} to{opacity:1;transform:scale(1)} }
  .modal-title { font-size:18px; font-weight:700; margin-bottom:6px; }
  .modal-sub { font-size:13px; color:var(--muted); margin-bottom:22px; }
  .form-group { margin-bottom:16px; }
  .form-label { font-size:12px; font-weight:600; color:var(--muted); margin-bottom:6px; display:block; }
  .form-input {
    width:100%; background:#fff; border:1px solid var(--border);
    border-radius:10px; padding:10px 14px; color:var(--text); font-size:13px; outline:none;
    transition:border-color .2s;
  }
  .form-input:focus { border-color:var(--accent); }
  .form-input::placeholder { color:var(--muted2); }
  .form-actions { display:flex; gap:10px; margin-top:20px; }
  .btn-primary {
    flex:1; padding:11px; border-radius:10px;
    background:linear-gradient(90deg,#5a85b0,#3d6690);
    border:none; color:#fff; font-size:13px; font-weight:700; cursor:pointer;
    transition:all .2s;
  }
  .btn-secondary {
    padding:11px 20px; border-radius:10px;
    background:#fff; border:1px solid var(--border);
    color:var(--muted); font-size:13px; cursor:pointer; transition:all .2s;
  }
  .btn-secondary:hover { color:var(--text); border-color:var(--muted); }

  .tag-row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
  .tag {
    padding:5px 14px; border-radius:20px; font-size:12px; cursor:pointer;
    background:#fff; border:1px solid var(--border); color:var(--muted);
    transition:all .2s;
  }
  .tag.active { background:#e8f0f8; border-color:rgba(90,133,176,.4); color:var(--accent); }

  /* RESPONSIVE */
  @media(max-width:900px) {
    .main-layout { grid-template-columns:1fr; }
    .hero-grid { grid-template-columns:1fr; }
    .hero-main { min-height:250px; grid-row:auto; }
    .news-grid { grid-template-columns:repeat(2,1fr); }
    .media-grid { grid-template-columns:repeat(2,1fr); }
    nav { display:none; }
    .search-box { width:160px; }
  }
  @media(max-width:560px) {
    .news-grid { grid-template-columns:1fr; }
    .media-grid { grid-template-columns:1fr 1fr; }
  }
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <div class="topbar-left">
    <span><span class="live-dot"></span> مباشر الآن</span>
    <span><?php echo date('l, j F Y', strtotime('2026-04-05')); ?></span>
    <span>🕐 <?php echo date('h:i A', strtotime('2026-04-05 12:40:00')); ?></span>
  </div>
  <div class="topbar-right">
    <span class="weather-badge">☀️ عمّان 22°</span>
    <span>USD: 0.71 JD</span>
    <span>EUR: 0.78 JD</span>
  </div>
</div>

<!-- HEADER -->
<header>
  <a class="logo" href="index.php">
    <div class="logo-icon">N</div>
    <div>
      <div class="logo-text">نيوز<span>فلو</span></div>
      <div class="logo-sub">مجمع المصادر الإخبارية</div>
    </div>
  </a>

  <nav>
    <a href="index.php" class="breaking">🔴 عاجل</a>
    <a href="index.php" class="active">الرئيسية</a>
    <a href="#latest" onclick="scrollToSection('latest')">آخر الأخبار</a>
    <a href="#political" onclick="scrollToSection('political')">سياسة</a>
    <a href="#economy" onclick="scrollToSection('economy')">اقتصاد</a>
    <a href="#sports" onclick="scrollToSection('sports')">رياضة</a>
    <a href="#arts" onclick="scrollToSection('arts')">فنون</a>
    <a href="#media" onclick="scrollToSection('media')">ميديا</a>
    <a href="#reports" onclick="scrollToSection('reports')">تقارير</a>
  </nav>

  <div class="header-actions">
    <div class="search-box">
      <span>🔍</span>
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
  <div class="ticker-label">🔴 عاجل</div>
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

    <!-- HERO -->
    <div class="section-header">
      <div class="section-title"><div class="line"></div>أبرز الأخبار</div>
      <a class="see-all" href="#">عرض الكل ›</a>
    </div>
    <div class="hero-grid">
      <?php if (!empty($heroArticles)): ?>
        <?php $first = true; ?>
        <?php foreach ($heroArticles as $article): ?>
          <?php if ($first): ?>
            <div class="hero-main">
              <div class="img-wrap">
                <img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/news1/800/500'); ?>" alt="">
              </div>
              <div class="hero-overlay">
                <div class="source-badge">🌐 <?php echo e($article['source_name']); ?></div>
                <div class="hero-title"><?php echo e($article['title']); ?></div>
                <div class="meta">
                  <span><?php echo timeAgo($article['published_at']); ?></span>
                  <span class="meta-dot"></span>
                  <span>👁 <?php echo formatViews($article['view_count']); ?></span>
                  <span class="meta-dot"></span>
                  <span>💬 <?php echo number_format($article['comments'] ?? 0); ?> تعليق</span>
                </div>
              </div>
            </div>
            <?php $first = false; ?>
          <?php else: ?>
            <div class="hero-side">
              <div class="hero-side-img">
                <img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/news' . rand(1,10) . '/400/200'); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
              </div>
              <div class="hero-side-body">
                <div class="card-cat <?php echo $article['css_class'] ?? 'cat-political'; ?>"><?php echo e($article['cat_name']); ?></div>
                <h3><?php echo e(substr($article['title'], 0, 60) . '...'); ?></h3>
                <div class="meta" style="margin-top:6px"><span><?php echo timeAgo($article['published_at']); ?></span><span class="meta-dot"></span><span>👁 <?php echo formatViews($article['view_count']); ?></span></div>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- BREAKING NEWS -->
    <div id="breaking" class="section-header">
      <div class="section-title"><div class="line" style="background:var(--red)"></div>🔴 أخبار عاجلة</div>
      <a class="see-all" href="#">عرض الكل ›</a>
    </div>
    <div class="news-list" style="margin-bottom:28px">
      <?php foreach ($breakingNews as $article): ?>
        <div class="list-item">
          <div class="list-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/brk' . rand(1,10) . '/200/150'); ?>" alt=""></div>
          <div class="list-body">
            <div class="card-cat cat-breaking">عاجل</div>
            <div class="list-title"><?php echo e($article['title']); ?></div>
            <div class="list-meta"><span>🌐 <?php echo e($article['source_name']); ?></span><span>·</span><span><?php echo timeAgo($article['published_at']); ?></span><span>·</span><span>👁 <?php echo formatViews($article['view_count']); ?></span></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- LATEST NEWS -->
    <div id="latest" class="section-header">
      <div class="section-title blue"><div class="line"></div>⏱ آخر الأخبار</div>
      <a class="see-all" href="#">عرض الكل ›</a>
    </div>
    <div class="news-grid" style="margin-bottom:28px">
      <?php foreach ($latestArticles as $article): ?>
        <div class="news-card">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/lat' . rand(1,10) . '/400/300'); ?>" alt=""></div>
          <div class="card-body">
            <span class="card-cat <?php echo $article['css_class'] ?? 'cat-political'; ?>"><?php echo e($article['cat_name']); ?></span>
            <div class="card-title"><?php echo e(substr($article['title'], 0, 50) . '...'); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- POLITICAL NEWS -->
    <div id="political" class="section-header">
      <div class="section-title"><div class="line" style="background:#b05a5a"></div>🏛 أخبار سياسية</div>
      <a class="see-all" href="#">عرض الكل ›</a>
    </div>
    <div class="news-grid news-grid-2col" style="margin-bottom:28px">
      <?php foreach ($politicalNews as $article): ?>
        <div class="news-card">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/pol' . rand(1,10) . '/400/300'); ?>" alt=""></div>
          <div class="card-body">
            <span class="card-cat cat-political">سياسة</span>
            <div class="card-title"><?php echo e(substr($article['title'], 0, 50) . '...'); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- ECONOMY -->
    <div id="economy" class="section-header">
      <div class="section-title green"><div class="line"></div>💹 أخبار اقتصادية</div>
      <a class="see-all" href="#">عرض الكل ›</a>
    </div>
    <div class="news-grid" style="margin-bottom:28px">
      <?php foreach ($economyNews as $article): ?>
        <div class="news-card">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/eco' . rand(1,10) . '/400/300'); ?>" alt=""></div>
          <div class="card-body">
            <span class="card-cat cat-economic">اقتصاد</span>
            <div class="card-title"><?php echo e(substr($article['title'], 0, 50) . '...'); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#85c1a3'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- SPORTS -->
    <div id="sports" class="section-header">
      <div class="section-title"><div class="line" style="background:#5a85b0"></div>⚽ رياضة</div>
      <a class="see-all" href="#">عرض الكل ›</a>
    </div>
    <div class="news-grid" style="margin-bottom:28px">
      <?php foreach ($sportsNews as $article): ?>
        <div class="news-card">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/sp' . rand(1,10) . '/400/300'); ?>" alt=""></div>
          <div class="card-body">
            <span class="card-cat cat-sports">رياضة</span>
            <div class="card-title"><?php echo e(substr($article['title'], 0, 50) . '...'); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- ARTS -->
    <div id="arts" class="section-header">
      <div class="section-title"><div class="line" style="background:#7a5a9a"></div>🎨 فنون وثقافة</div>
      <a class="see-all" href="#">عرض الكل ›</a>
    </div>
    <div class="news-grid news-grid-2col" style="margin-bottom:28px">
      <?php foreach ($artsNews as $article): ?>
        <div class="news-card">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/art' . rand(1,10) . '/400/300'); ?>" alt=""></div>
          <div class="card-body">
            <span class="card-cat cat-arts">فنون</span>
            <div class="card-title"><?php echo e(substr($article['title'], 0, 50) . '...'); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#a08cc8'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- MEDIA SECTION -->
    <div id="media" class="section-header">
      <div class="section-title"><div class="line" style="background:#8a5a8a"></div>🎥 ميديا</div>
      <a class="see-all" href="#">عرض الكل ›</a>
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
      <a class="see-all" href="#">عرض الكل ›</a>
    </div>
    <div class="news-grid" style="margin-bottom:28px">
      <?php foreach ($reportsNews as $article): ?>
        <div class="news-card">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/rep' . rand(1,10) . '/400/300'); ?>" alt=""></div>
          <div class="card-body">
            <span class="card-cat cat-reports">تقرير</span>
            <div class="card-title"><?php echo e(substr($article['title'], 0, 50) . '...'); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#c9ab6e'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  </div><!-- /main-col -->

  <!-- SIDEBAR -->
  <div class="sidebar">

    <!-- WEATHER -->
    <div class="weather-widget">
      <div class="section-title" style="margin-bottom:14px;font-size:14px"><div class="line" style="background:var(--accent2)"></div>☀️ الطقس الآن</div>
      <div class="weather-main">
        <div>
          <div class="weather-temp">22°</div>
          <div class="weather-city">عمّان، الأردن</div>
          <div class="weather-desc">مشمس جزئياً</div>
        </div>
        <div class="weather-icon">☀️</div>
      </div>
      <div class="weather-days">
        <div class="weather-day"><div class="day">الإث</div><div>⛅</div><div class="temp">20°</div></div>
        <div class="weather-day"><div class="day">الثل</div><div>🌤</div><div class="temp">23°</div></div>
        <div class="weather-day"><div class="day">الأر</div><div>🌧</div><div class="temp">17°</div></div>
        <div class="weather-day"><div class="day">الخم</div><div>☀️</div><div class="temp">25°</div></div>
      </div>
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
          <div class="list-item" style="padding:8px 0;background:none;border:none;<?php echo $rankNum < 3 ? 'border-bottom:1px solid var(--border);' : ''; ?>">
            <div class="rank-num"><?php echo $rankNum; ?></div>
            <div class="list-body">
              <div class="list-title" style="font-size:12px"><?php echo e(substr($article['title'], 0, 40) . '...'); ?></div>
              <div class="list-meta"><span>👁 <?php echo formatViews($article['view_count']); ?></span></div>
            </div>
          </div>
          <?php $rankNum++; ?>
        <?php endforeach; ?>
      </div>
    </div>

  </div><!-- /sidebar -->
</div><!-- /main-layout -->

<!-- FOOTER -->
<footer>
  <div class="footer-logo">نيوز<span>فلو</span></div>
  <div class="footer-links">
    <a href="#">من نحن</a>
    <a href="#">سياسة الخصوصية</a>
    <a href="#">الشروط والأحكام</a>
    <a href="#">اتصل بنا</a>
    <a href="#">إضافة مصدر</a>
  </div>
  <div class="footer-copy">© 2026 نيوزفلو — جميع الحقوق محفوظة</div>
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
  });

  // SAVE PREFS FEEDBACK
  document.querySelectorAll('.save-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      this.textContent = '✅ تم الحفظ بنجاح!';
      this.style.background = 'linear-gradient(90deg,#5a9e85,#3d7a64)';
      setTimeout(() => {
        this.textContent = '💾 حفظ التفضيلات';
        this.style.background = 'linear-gradient(90deg,#5a85b0,#3d6690)';
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
</body>
</html>
