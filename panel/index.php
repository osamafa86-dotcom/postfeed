<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$db = getDB();

// Stats
$totalArticles = $db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$totalSources = $db->query("SELECT COUNT(*) FROM sources")->fetchColumn();
$totalCategories = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$totalViews = $db->query("SELECT COALESCE(SUM(view_count),0) FROM articles")->fetchColumn();
$todayViews = $db->query("SELECT COALESCE(SUM(view_count),0) FROM articles WHERE DATE(published_at) = CURDATE()")->fetchColumn();
$todayArticles = $db->query("SELECT COUNT(*) FROM articles WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$weekArticles = $db->query("SELECT COUNT(*) FROM articles WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$lastWeekArticles = $db->query("SELECT COUNT(*) FROM articles WHERE created_at BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$breakingCount = $db->query("SELECT COUNT(*) FROM articles WHERE is_breaking = 1")->fetchColumn();
$growthPercent = $lastWeekArticles > 0 ? round(($weekArticles - $lastWeekArticles) / $lastWeekArticles * 100, 1) : 0;
$breakingArticles = $db->query("SELECT title FROM articles WHERE is_breaking = 1 ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$recentArticles = $db->query("SELECT a.id, a.title, a.view_count, a.status, a.is_breaking, a.published_at, a.created_at, c.name as cat_name, c.css_class, s.name as source_name FROM articles a LEFT JOIN categories c ON a.category_id = c.id LEFT JOIN sources s ON a.source_id = s.id ORDER BY a.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$topSources = $db->query("SELECT s.name, COUNT(a.id) as count FROM sources s LEFT JOIN articles a ON a.source_id = s.id GROUP BY s.id, s.name ORDER BY count DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$articlesByCategory = $db->query("SELECT c.name, c.css_class, COUNT(a.id) as count FROM categories c LEFT JOIN articles a ON a.category_id = c.id GROUP BY c.id, c.name, c.css_class ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);
$topViewed = $db->query("SELECT title, view_count FROM articles ORDER BY view_count DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$maxViews = $db->query("SELECT COALESCE(MAX(view_count),1) FROM articles")->fetchColumn();
$avgViews = $totalArticles > 0 ? round($totalViews / $totalArticles) : 0;
$adminName = $_SESSION['admin_name'] ?? 'المدير';

$maxCatCount = 1;
foreach ($articlesByCategory as $c) { if ($c['count'] > $maxCatCount) $maxCatCount = $c['count']; }
$maxSourceCount = 1;
foreach ($topSources as $s) { if ($s['count'] > $maxSourceCount) $maxSourceCount = $s['count']; }

$sourceColors = ['var(--primary)', 'var(--success)', 'var(--secondary)', 'var(--warning)', 'var(--purple)', 'var(--teal)'];
$catBarColors = ['var(--primary)', 'var(--secondary)', 'var(--success)', 'var(--warning)', 'var(--purple)', 'var(--teal)', 'var(--accent)'];
$thumbBgs = ['var(--primary-light)', 'var(--success-light)', 'var(--purple-light)', 'var(--warning-light)', 'var(--teal-light)', 'var(--secondary-light)', 'var(--accent-light)'];
$thumbGrads = [
    'linear-gradient(135deg,var(--primary),#8ab8f0)',
    'linear-gradient(135deg,var(--success),var(--teal))',
    'linear-gradient(135deg,var(--purple),var(--secondary))',
    'linear-gradient(135deg,var(--warning),var(--accent))',
    'linear-gradient(135deg,var(--teal),var(--primary))',
    'linear-gradient(135deg,var(--secondary),var(--warning))',
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>لوحة التحكم - نيوزفلو</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap');

  :root {
    --primary:       #4a7fcb;
    --primary-light: #dce9f9;
    --primary-soft:  #eef4fd;
    --secondary:     #e07b6a;
    --secondary-light: #fde8e5;
    --accent:        #f0a050;
    --accent-light:  #fef3e2;
    --success:       #52b788;
    --success-light: #dff2ea;
    --warning:       #e8a838;
    --warning-light: #fef3dc;
    --danger:        #d9534f;
    --danger-light:  #fde9e8;
    --purple:        #8b6fcb;
    --purple-light:  #ede8f9;
    --teal:          #4aabaa;
    --teal-light:    #ddf1f0;

    --bg-page:    #f4f6fb;
    --bg-card:    #ffffff;
    --bg-sidebar: #e8f4f4;
    --bg-hover:   #d6ecec;
    --bg-input:   #f7f9fc;
    --sidebar-border: #b8dada;
    --sidebar-text:   #1d4a4a;
    --sidebar-muted:  #5a8888;

    --text-primary:   #2c3a52;
    --text-secondary: #6b7a95;
    --text-muted:     #a3afc4;

    --border:       #e4eaf5;
    --border-light: #edf1fa;
    --shadow:       0 2px 12px rgba(74,127,203,0.08);
    --shadow-md:    0 4px 20px rgba(74,127,203,0.12);
    --transition:   all 0.25s cubic-bezier(0.4,0,0.2,1);
  }

  * { margin:0; padding:0; box-sizing:border-box; }

  body {
    font-family: 'Tajawal', sans-serif;
    background: var(--bg-page);
    color: var(--text-primary);
    display: flex;
    min-height: 100vh;
    overflow-x: hidden;
  }

  ::-webkit-scrollbar { width:5px; height:5px; }
  ::-webkit-scrollbar-track { background: var(--bg-page); }
  ::-webkit-scrollbar-thumb { background: var(--border); border-radius:10px; }

  .sidebar {
    width: 255px; min-height: 100vh;
    background: var(--bg-sidebar);
    border-left: 1px solid var(--sidebar-border);
    display: flex; flex-direction: column;
    position: fixed; right: 0; top: 0; bottom: 0;
    z-index: 100; overflow-y: auto;
    box-shadow: 2px 0 16px rgba(74,150,150,0.10);
  }

  .sidebar-logo {
    padding: 22px 18px;
    border-bottom: 1px solid var(--sidebar-border);
    display: flex; align-items: center; gap: 11px;
  }

  .logo-icon {
    width: 44px; height: 44px;
    background: linear-gradient(135deg, var(--primary), #6b9fe8);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    box-shadow: 0 4px 14px rgba(74,127,203,0.3);
  }

  .logo-text span:first-child { font-size: 16px; font-weight: 800; color: var(--sidebar-text); display:block; }
  .logo-text span:last-child  { font-size: 10px; color: var(--sidebar-muted); letter-spacing:2px; }

  .sidebar-section { padding: 10px 0; }

  .sidebar-section-label {
    padding: 8px 18px 4px;
    font-size: 10px; font-weight: 700;
    color: var(--sidebar-muted);
    text-transform: uppercase; letter-spacing:1.5px;
  }

  .nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 14px; margin: 2px 10px;
    border-radius: 11px; cursor: pointer;
    transition: var(--transition);
    color: var(--sidebar-text); opacity: 0.82;
    text-decoration: none;
  }

  .nav-item:hover { background: var(--bg-hover); opacity: 1; }

  .nav-item.active {
    background: rgba(255,255,255,0.65);
    color: #1a5c5c; font-weight: 700; opacity: 1;
    box-shadow: 0 2px 8px rgba(74,150,150,0.12);
  }

  .nav-icon {
    width: 32px; height: 32px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
    background: rgba(255,255,255,0.40);
    transition: var(--transition);
  }

  .nav-item.active .nav-icon { background: rgba(255,255,255,0.75); }
  .nav-item span.label { font-size: 13.5px; flex:1; }

  .nav-badge {
    font-size: 10px; font-weight: 700;
    padding: 2px 8px; border-radius: 20px;
    background: rgba(255,255,255,0.5); color: #1a5c5c;
  }
  .nav-badge.green  { background: #c6ead8; color: #2a7a54; }
  .nav-badge.yellow { background: #fce8c0; color: #a06820; }
  .nav-badge.blue   { background: rgba(255,255,255,0.65); color: #1a5c5c; }

  .main { flex:1; margin-right:255px; display:flex; flex-direction:column; min-height:100vh; }

  .topbar {
    height: 64px; background: #ffffff;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; padding: 0 24px; gap: 14px;
    position: sticky; top:0; z-index:90;
    box-shadow: 0 1px 8px rgba(74,127,203,0.07);
  }

  .topbar-greeting { flex:1; }
  .topbar-greeting h3 { font-size:16px; font-weight:700; color:var(--text-primary); }
  .topbar-greeting p  { font-size:12px; color:var(--text-muted); }

  .search-box {
    display: flex; align-items: center; gap: 8px;
    background: var(--bg-input);
    border: 1.5px solid var(--border);
    border-radius: 12px; padding: 8px 14px; width: 250px;
    transition: var(--transition);
  }
  .search-box:focus-within { border-color: var(--primary); background:#fff; box-shadow: 0 0 0 3px var(--primary-light); }
  .search-box input { background:none; border:none; outline:none; font-family:'Tajawal',sans-serif; font-size:13px; color:var(--text-primary); flex:1; direction:rtl; }
  .search-box input::placeholder { color:var(--text-muted); }
  .search-icon { color:var(--text-muted); font-size:15px; }

  .topbar-actions { display:flex; align-items:center; gap:8px; }

  .icon-btn {
    width:38px; height:38px; border-radius:11px;
    background:var(--bg-input); border:1.5px solid var(--border);
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:var(--transition); font-size:16px;
    color: var(--text-secondary); position:relative;
  }
  .icon-btn:hover { background:var(--primary-light); border-color:var(--primary); color:var(--primary); }

  .notif-dot {
    position:absolute; top:6px; right:6px;
    width:8px; height:8px;
    background:var(--secondary); border-radius:50%;
    border:2px solid #fff;
  }

  .user-avatar {
    width:38px; height:38px; border-radius:11px;
    background: linear-gradient(135deg, var(--primary), #6b9fe8);
    display:flex; align-items:center; justify-content:center;
    font-weight:700; font-size:14px; color:#fff;
    cursor:pointer; border:2px solid var(--primary-light);
    box-shadow: 0 2px 8px rgba(74,127,203,0.2);
  }

  .breaking-ticker {
    background: linear-gradient(135deg, #c0392b, #e74c3c);
    border-bottom: none;
    padding: 10px 24px;
    display: flex; align-items:center; gap:14px;
    overflow:hidden;
    box-shadow: 0 2px 10px rgba(192,57,43,0.25);
  }

  .breaking-label {
    background: rgba(255,255,255,0.22);
    color:#fff; padding:4px 14px; border-radius:20px;
    font-size:11px; font-weight:800; white-space:nowrap;
    letter-spacing:1px;
    animation: pulse 2s infinite;
    border: 1px solid rgba(255,255,255,0.3);
  }

  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.75} }

  .ticker-wrap { flex:1; overflow:hidden; }
  .ticker-content {
    display:flex; gap:40px;
    animation: ticker 35s linear infinite;
    white-space:nowrap;
    color: rgba(255,255,255,0.92); font-size:13px;
  }
  .ticker-content span::before { content:"◆ "; color:rgba(255,255,255,0.5); }
  @keyframes ticker {
    0%  { transform:translateX(-100%); }
    100%{ transform:translateX(100%);  }
  }

  .content { padding:24px; flex:1; }

  .page-header {
    display:flex; justify-content:space-between; align-items:center; margin-bottom:22px;
  }
  .page-header h2 { font-size:20px; font-weight:800; color:var(--text-primary); }
  .page-header p  { font-size:12px; color:var(--text-muted); margin-top:3px; }

  .btn-primary {
    background: linear-gradient(135deg, var(--primary), #6b9fe8);
    color:#fff; border:none; padding:9px 18px; border-radius:11px;
    font-size:13px; font-weight:600; cursor:pointer;
    font-family:'Tajawal',sans-serif;
    display:flex; align-items:center; gap:6px;
    transition:var(--transition); white-space:nowrap;
    box-shadow: 0 3px 10px rgba(74,127,203,0.25);
    text-decoration:none;
  }
  .btn-primary:hover { transform:translateY(-1px); box-shadow:0 5px 16px rgba(74,127,203,0.35); }

  .btn-secondary {
    background:var(--secondary-light); color:var(--secondary);
    border:none; padding:9px 18px; border-radius:11px;
    font-size:13px; font-weight:600; cursor:pointer;
    font-family:'Tajawal',sans-serif;
    display:flex; align-items:center; gap:6px; transition:var(--transition);
    text-decoration:none;
  }
  .btn-secondary:hover { background:var(--secondary); color:#fff; }

  .btn-outline {
    background:none; color:var(--text-secondary);
    border:1.5px solid var(--border); padding:7px 14px; border-radius:10px;
    font-size:12px; font-weight:600; cursor:pointer;
    font-family:'Tajawal',sans-serif; transition:var(--transition);
    text-decoration:none;
  }
  .btn-outline:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-soft); }

  .stats-grid {
    display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:22px;
  }

  .stat-card {
    background:var(--bg-card); border:1.5px solid var(--border);
    border-radius:18px; padding:20px;
    transition:var(--transition); cursor:pointer;
    box-shadow: var(--shadow);
    position:relative; overflow:hidden;
  }
  .stat-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }

  .stat-card::after {
    content:'';
    position:absolute; bottom:0; left:0; right:0; height:3px; border-radius:0 0 18px 18px;
  }
  .stat-card.blue::after   { background: linear-gradient(90deg, var(--primary), #8ab8f0); }
  .stat-card.red::after    { background: linear-gradient(90deg, var(--secondary), #f0a090); }
  .stat-card.green::after  { background: linear-gradient(90deg, var(--success), #80d4a8); }
  .stat-card.yellow::after { background: linear-gradient(90deg, var(--warning), #f0c870); }

  .stat-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; }

  .stat-icon {
    width:46px; height:46px; border-radius:13px;
    display:flex; align-items:center; justify-content:center; font-size:20px;
  }
  .stat-card.blue   .stat-icon { background:var(--primary-light); }
  .stat-card.red    .stat-icon { background:var(--secondary-light); }
  .stat-card.green  .stat-icon { background:var(--success-light); }
  .stat-card.yellow .stat-icon { background:var(--warning-light); }

  .stat-trend {
    display:flex; align-items:center; gap:4px;
    font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px;
  }
  .stat-trend.up   { background:var(--success-light); color:var(--success); }
  .stat-trend.down { background:var(--danger-light);  color:var(--danger);  }

  .stat-value { font-size:28px; font-weight:800; color:var(--text-primary); margin-bottom:4px; }
  .stat-label { font-size:13px; color:var(--text-muted); }

  .mini-chart {
    margin-top:14px; height:38px;
    display:flex; align-items:flex-end; gap:4px;
  }
  .mini-bar {
    flex:1; border-radius:5px 5px 0 0; opacity:0.45;
    transition:var(--transition);
    animation: growUp 0.8s ease-out;
  }
  @keyframes growUp { from{transform:scaleY(0);transform-origin:bottom;} to{transform:scaleY(1);transform-origin:bottom;} }
  .stat-card.blue   .mini-bar { background:var(--primary); }
  .stat-card.red    .mini-bar { background:var(--secondary); }
  .stat-card.green  .mini-bar { background:var(--success); }
  .stat-card.yellow .mini-bar { background:var(--warning); }

  .card {
    background:var(--bg-card); border:1.5px solid var(--border);
    border-radius:18px; overflow:hidden; box-shadow:var(--shadow);
  }

  .card-header {
    padding:17px 20px; border-bottom:1px solid var(--border-light);
    display:flex; align-items:center; justify-content:space-between;
  }
  .card-title {
    font-size:15px; font-weight:700; color:var(--text-primary);
    display:flex; align-items:center; gap:8px;
  }
  .card-title-dot { width:9px; height:9px; border-radius:50%; }
  .dot-blue   { background:var(--primary); }
  .dot-red    { background:var(--secondary); }
  .dot-green  { background:var(--success); }
  .dot-yellow { background:var(--warning); }
  .dot-purple { background:var(--purple); }
  .dot-teal   { background:var(--teal); }

  .card-body { padding:20px; }

  .card-actions { display:flex; gap:6px; }

  .chip {
    padding:5px 13px; border-radius:20px; font-size:11px; font-weight:600;
    cursor:pointer; border:1.5px solid var(--border);
    background:none; color:var(--text-muted);
    font-family:'Tajawal',sans-serif; transition:var(--transition);
  }
  .chip:hover  { border-color:var(--primary); color:var(--primary); background:var(--primary-soft); }
  .chip.active { background:var(--primary); border-color:var(--primary); color:#fff; }

  .charts-row { display:grid; grid-template-columns:2fr 1fr; gap:16px; margin-bottom:22px; }

  .chart-container { position:relative; height:195px; }
  .chart-svg { width:100%; height:100%; }

  .donut-wrapper { display:flex; align-items:center; gap:18px; }
  .donut-chart { position:relative; width:140px; height:140px; flex-shrink:0; }
  .donut-center {
    position:absolute; inset:0;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
  }
  .donut-center .value { font-size:22px; font-weight:800; color:var(--text-primary); }
  .donut-center .label { font-size:10px; color:var(--text-muted); }

  .donut-legend { flex:1; }
  .legend-item {
    display:flex; align-items:center; justify-content:space-between;
    padding:8px 0; border-bottom:1px solid var(--border-light); font-size:13px;
  }
  .legend-item:last-child { border:none; }
  .legend-dot { width:10px; height:10px; border-radius:3px; flex-shrink:0; }
  .legend-left { display:flex; align-items:center; gap:8px; color:var(--text-secondary); }
  .legend-val  { font-weight:700; color:var(--text-primary); }

  .mid-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:22px; }

  .table-section { margin-bottom:22px; }

  table { width:100%; border-collapse:collapse; }
  th {
    padding:11px 16px; text-align:right;
    font-size:11px; font-weight:700; color:var(--text-muted);
    text-transform:uppercase; letter-spacing:1px;
    border-bottom:1.5px solid var(--border);
    background:var(--bg-page);
  }
  td {
    padding:13px 16px; font-size:13px;
    border-bottom:1px solid var(--border-light);
    vertical-align:middle;
  }
  tr:hover td { background:var(--bg-input); }
  tr:last-child td { border:none; }

  .article-info { display:flex; align-items:center; gap:12px; }
  .article-thumb {
    width:44px; height:44px; border-radius:12px;
    display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;
  }
  .article-title-text { font-weight:600; font-size:13.5px; margin-bottom:3px; color:var(--text-primary); max-width:280px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
  .article-meta { font-size:11px; color:var(--text-muted); }

  .badge {
    padding:3px 10px; border-radius:20px;
    font-size:11px; font-weight:600; display:inline-block;
  }
  .badge-success { background:var(--success-light); color:var(--success); }
  .badge-warning { background:var(--warning-light); color:var(--warning); }
  .badge-danger  { background:var(--danger-light);  color:var(--danger);  }
  .badge-primary { background:var(--primary-light); color:var(--primary); }
  .badge-purple  { background:var(--purple-light);  color:var(--purple);  }
  .badge-teal    { background:var(--teal-light);    color:var(--teal);    }
  .badge-muted   { background:var(--bg-hover); color:var(--text-muted); }

  .views-bar { display:flex; align-items:center; gap:8px; font-size:12px; }
  .bar-track { flex:1; height:5px; background:var(--bg-hover); border-radius:10px; overflow:hidden; }
  .bar-fill  { height:100%; border-radius:10px; background:var(--primary); }

  .action-btn {
    background:none; border:1.5px solid var(--border);
    color:var(--text-secondary); padding:5px 12px; border-radius:9px;
    font-size:12px; cursor:pointer; font-family:'Tajawal',sans-serif;
    transition:var(--transition); font-weight:500;
    text-decoration:none; display:inline-block;
  }
  .action-btn:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-soft); }

  .progress-item { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
  .progress-label { width:75px; font-size:12px; color:var(--text-secondary); text-align:right; }
  .progress-track { flex:1; height:6px; background:var(--bg-hover); border-radius:10px; overflow:hidden; }
  .progress-fill  { height:100%; border-radius:10px; }
  .progress-val   { width:36px; font-size:12px; font-weight:700; text-align:left; color:var(--text-primary); }

  .notif-item {
    display:flex; gap:10px; padding:11px;
    border-radius:11px; margin-bottom:5px;
    transition:var(--transition); cursor:pointer;
  }
  .notif-item:hover { background:var(--bg-hover); }
  .notif-icon-wrap { width:38px; height:38px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
  .notif-title { font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:2px; }
  .notif-sub   { font-size:11px; color:var(--text-muted); }

  .quick-action-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .quick-action-btn {
    display:flex; align-items:center; gap:10px;
    padding:14px; border-radius:14px; border:1.5px solid var(--border);
    background:var(--bg-card); cursor:pointer; transition:var(--transition);
    text-decoration:none; color:var(--text-primary);
  }
  .quick-action-btn:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); border-color:var(--primary); }
  .quick-action-icon {
    width:40px; height:40px; border-radius:11px;
    display:flex; align-items:center; justify-content:center;
    font-size:18px; flex-shrink:0;
  }
  .quick-action-name { font-size:13px; font-weight:700; }
  .quick-action-desc { font-size:11px; color:var(--text-muted); }

  .summary-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .summary-item {
    text-align:center; padding:16px;
    background:var(--bg-page); border-radius:13px; border:1px solid var(--border-light);
  }
  .summary-item .val { font-size:24px; font-weight:800; }
  .summary-item .lbl { font-size:11px; color:var(--text-muted); margin-top:2px; }

  .bottom-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:22px; }

  .live-badge {
    display:inline-flex; align-items:center; gap:5px;
    background:#fde8e5; color:#c0392b;
    padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700;
    border:1px solid #f5c2bb;
  }
  .live-dot { width:6px; height:6px; background:#c0392b; border-radius:50%; animation:pulse 1s infinite; }

  .footer-bar {
    padding:13px 24px; border-top:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
    font-size:12px; color:var(--text-muted); background:#fff;
  }
  .footer-status { display:flex; align-items:center; gap:6px; }
  .status-dot { width:8px; height:8px; background:var(--success); border-radius:50%; animation:pulse 2s infinite; }

  @media(max-width:1200px) {
    .stats-grid { grid-template-columns:repeat(2,1fr); }
    .charts-row { grid-template-columns:1fr; }
    .mid-row    { grid-template-columns:1fr 1fr; }
    .bottom-row { grid-template-columns:1fr; }
  }
  @media(max-width:768px) {
    .sidebar { display:none; }
    .main { margin-right:0; }
    .stats-grid { grid-template-columns:1fr; }
    .mid-row { grid-template-columns:1fr; }
    .topbar { flex-wrap:wrap; height:auto; padding:12px; }
    .search-box { width:100%; }
  }
</style>
</head>
<body>

<!-- ===== SIDEBAR ===== -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">📰</div>
    <div class="logo-text">
      <span>نيوزفلو</span>
      <span>DASHBOARD</span>
    </div>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">الرئيسية</div>
    <a href="index.php" class="nav-item active">
      <div class="nav-icon">📊</div>
      <span class="label">لوحة التحكم</span>
    </a>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">المحتوى</div>
    <a href="articles.php" class="nav-item">
      <div class="nav-icon">✍️</div>
      <span class="label">الأخبار</span>
      <span class="nav-badge blue"><?php echo $totalArticles; ?></span>
    </a>
    <a href="categories.php" class="nav-item">
      <div class="nav-icon">📂</div>
      <span class="label">الأقسام</span>
    </a>
    <a href="sources.php" class="nav-item">
      <div class="nav-icon">🌐</div>
      <span class="label">المصادر</span>
    </a>
    <a href="ticker.php" class="nav-item">
      <div class="nav-icon">📢</div>
      <span class="label">الشريط الإخباري</span>
      <?php if ($breakingCount > 0): ?><span class="nav-badge yellow"><?php echo $breakingCount; ?></span><?php endif; ?>
    </a>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">الإدارة</div>
    <a href="settings.php" class="nav-item">
      <div class="nav-icon">⚙️</div>
      <span class="label">الإعدادات</span>
    </a>
    <a href="logout.php" class="nav-item">
      <div class="nav-icon">🚪</div>
      <span class="label">تسجيل الخروج</span>
    </a>
  </div>
</aside>

<!-- ===== MAIN ===== -->
<main class="main">

  <!-- TOPBAR -->
  <header class="topbar">
    <div class="topbar-greeting">
      <h3>مرحباً بك، <?php echo e($adminName); ?> 👋</h3>
      <p><?php echo date('l، j F Y'); ?></p>
    </div>

    <div class="search-box">
      <span class="search-icon">🔍</span>
      <input type="text" placeholder="ابحث في لوحة التحكم..." disabled>
    </div>

    <div class="topbar-actions">
      <div class="live-badge"><span class="live-dot"></span>مباشر</div>
      <a href="../" class="icon-btn" title="الموقع" target="_blank">🌐</a>
      <div class="user-avatar"><?php echo mb_substr($adminName, 0, 1, 'UTF-8'); ?></div>
    </div>
  </header>

  <!-- BREAKING TICKER -->
  <?php if (!empty($breakingArticles)): ?>
  <div class="breaking-ticker">
    <div class="breaking-label">⚡ عاجل</div>
    <div class="ticker-wrap">
      <div class="ticker-content">
        <?php foreach ($breakingArticles as $ba): ?>
          <span><?php echo e($ba['title']); ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="content">

    <!-- PAGE HEADER -->
    <div class="page-header">
      <div>
        <h2>نظرة عامة على الموقع</h2>
        <p>آخر تحديث: <?php echo date('Y/m/d H:i'); ?> · البيانات مباشرة</p>
      </div>
      <div style="display:flex;gap:10px;align-items:center;">
        <a href="articles.php?action=add" class="btn-secondary">✏️ مقال جديد</a>
        <a href="ticker.php?action=add" class="btn-primary">🔥 خبر عاجل</a>
      </div>
    </div>

    <!-- STATS GRID -->
    <div class="stats-grid">
      <div class="stat-card blue">
        <div class="stat-header">
          <div class="stat-icon">👁️</div>
          <div class="stat-trend <?php echo $growthPercent >= 0 ? 'up' : 'down'; ?>"><?php echo $growthPercent >= 0 ? '▲' : '▼'; ?> <?php echo abs($growthPercent); ?>%</div>
        </div>
        <div class="stat-value"><?php echo number_format($totalViews); ?></div>
        <div class="stat-label">إجمالي المشاهدات</div>
        <div class="mini-chart">
          <?php for ($i = 0; $i < 8; $i++): ?><div class="mini-bar" style="height:<?php echo rand(30, 100); ?>%"></div><?php endfor; ?>
        </div>
      </div>

      <div class="stat-card red">
        <div class="stat-header">
          <div class="stat-icon">📝</div>
          <div class="stat-trend up">▲ <?php echo $todayArticles; ?> اليوم</div>
        </div>
        <div class="stat-value"><?php echo number_format($totalArticles); ?></div>
        <div class="stat-label">المقالات المنشورة</div>
        <div class="mini-chart">
          <?php for ($i = 0; $i < 8; $i++): ?><div class="mini-bar" style="height:<?php echo rand(30, 100); ?>%"></div><?php endfor; ?>
        </div>
      </div>

      <div class="stat-card green">
        <div class="stat-header">
          <div class="stat-icon">🌐</div>
          <div class="stat-trend up"><?php echo $totalSources; ?> مصدر</div>
        </div>
        <div class="stat-value"><?php echo number_format($totalSources); ?></div>
        <div class="stat-label">المصادر النشطة</div>
        <div class="mini-chart">
          <?php for ($i = 0; $i < 8; $i++): ?><div class="mini-bar" style="height:<?php echo rand(30, 100); ?>%"></div><?php endfor; ?>
        </div>
      </div>

      <div class="stat-card yellow">
        <div class="stat-header">
          <div class="stat-icon">📂</div>
          <div class="stat-trend up"><?php echo $totalCategories; ?> قسم</div>
        </div>
        <div class="stat-value"><?php echo number_format($totalCategories); ?></div>
        <div class="stat-label">الأقسام</div>
        <div class="mini-chart">
          <?php for ($i = 0; $i < 8; $i++): ?><div class="mini-bar" style="height:<?php echo rand(30, 100); ?>%"></div><?php endfor; ?>
        </div>
      </div>
    </div>

    <!-- CHARTS ROW -->
    <div class="charts-row">

      <!-- LINE CHART -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">
            <span class="card-title-dot dot-blue"></span>تحليل الزيارات
          </div>
          <div class="card-actions">
            <button class="chip active">يومي</button>
            <button class="chip">أسبوعي</button>
            <button class="chip">شهري</button>
          </div>
        </div>
        <div class="card-body">
          <div style="display:flex;gap:20px;margin-bottom:16px;flex-wrap:wrap;">
            <div style="padding:12px 16px;background:var(--primary-soft);border-radius:12px;border:1px solid var(--primary-light);">
              <div style="font-size:22px;font-weight:800;color:var(--primary);"><?php echo number_format($todayViews); ?></div>
              <div style="font-size:11px;color:var(--text-muted);">زيارات اليوم</div>
            </div>
            <div style="padding:12px 16px;background:var(--success-light);border-radius:12px;border:1px solid #c8e8d8;">
              <div style="font-size:22px;font-weight:800;color:var(--success);"><?php echo $growthPercent >= 0 ? '+' : ''; ?><?php echo $growthPercent; ?>%</div>
              <div style="font-size:11px;color:var(--text-muted);">نسبة النمو</div>
            </div>
            <div style="padding:12px 16px;background:var(--warning-light);border-radius:12px;border:1px solid #f0ddb0;">
              <div style="font-size:22px;font-weight:800;color:var(--warning);"><?php echo number_format($avgViews); ?></div>
              <div style="font-size:11px;color:var(--text-muted);">متوسط المشاهدات</div>
            </div>
            <div style="padding:12px 16px;background:var(--purple-light);border-radius:12px;border:1px solid #d8ccf0;">
              <div style="font-size:22px;font-weight:800;color:var(--purple);"><?php echo $breakingCount; ?></div>
              <div style="font-size:11px;color:var(--text-muted);">أخبار عاجلة</div>
            </div>
          </div>
          <div class="chart-container">
            <svg class="chart-svg" viewBox="0 0 600 180" preserveAspectRatio="none">
              <defs>
                <linearGradient id="gradBlue" x1="0%" y1="0%" x2="0%" y2="100%">
                  <stop offset="0%"   style="stop-color:#4a7fcb;stop-opacity:0.18"/>
                  <stop offset="100%" style="stop-color:#4a7fcb;stop-opacity:0"/>
                </linearGradient>
                <linearGradient id="gradRed" x1="0%" y1="0%" x2="0%" y2="100%">
                  <stop offset="0%"   style="stop-color:#e07b6a;stop-opacity:0.14"/>
                  <stop offset="100%" style="stop-color:#e07b6a;stop-opacity:0"/>
                </linearGradient>
              </defs>
              <line x1="0" y1="36"  x2="600" y2="36"  stroke="#e4eaf5" stroke-width="1" stroke-dasharray="5,4"/>
              <line x1="0" y1="72"  x2="600" y2="72"  stroke="#e4eaf5" stroke-width="1" stroke-dasharray="5,4"/>
              <line x1="0" y1="108" x2="600" y2="108" stroke="#e4eaf5" stroke-width="1" stroke-dasharray="5,4"/>
              <line x1="0" y1="144" x2="600" y2="144" stroke="#e4eaf5" stroke-width="1" stroke-dasharray="5,4"/>
              <path d="M0,140 C60,120 110,95 160,75 C210,55 260,85 310,65 C360,45 410,35 460,22 C510,12 560,30 600,18 L600,180 L0,180Z" fill="url(#gradBlue)"/>
              <path d="M0,158 C60,148 110,138 160,128 C210,118 260,138 310,118 C360,98 410,108 460,88 C510,68 560,78 600,58 L600,180 L0,180Z" fill="url(#gradRed)"/>
              <path d="M0,140 C60,120 110,95 160,75 C210,55 260,85 310,65 C360,45 410,35 460,22 C510,12 560,30 600,18" fill="none" stroke="#4a7fcb" stroke-width="2.5" stroke-linecap="round"/>
              <path d="M0,158 C60,148 110,138 160,128 C210,118 260,138 310,118 C360,98 410,108 460,88 C510,68 560,78 600,58" fill="none" stroke="#e07b6a" stroke-width="2" stroke-linecap="round" stroke-dasharray="6,4"/>
              <circle cx="160" cy="75"  r="5" fill="#fff" stroke="#4a7fcb" stroke-width="2.5"/>
              <circle cx="310" cy="65"  r="5" fill="#fff" stroke="#4a7fcb" stroke-width="2.5"/>
              <circle cx="460" cy="22"  r="5" fill="#fff" stroke="#4a7fcb" stroke-width="2.5"/>
              <circle cx="600" cy="18"  r="5" fill="#4a7fcb" stroke="#fff" stroke-width="2"/>
            </svg>
          </div>
          <div style="display:flex;gap:16px;margin-top:12px;">
            <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-secondary);">
              <span style="width:22px;height:3px;background:var(--primary);border-radius:2px;display:inline-block;"></span>الزيارات
            </div>
            <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-secondary);">
              <span style="width:22px;height:0;display:inline-block;border-top:2px dashed var(--secondary);"></span>المشاهدات
            </div>
          </div>
        </div>
      </div>

      <!-- DONUT -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><span class="card-title-dot dot-red"></span>توزيع الأقسام</div>
        </div>
        <div class="card-body">
          <div class="donut-wrapper">
            <div class="donut-chart">
              <svg viewBox="0 0 100 100" style="transform:rotate(-90deg)">
                <circle cx="50" cy="50" r="40" fill="none" stroke="#edf1fa" stroke-width="13"/>
                <?php
                $donutColors = ['#4a7fcb', '#e07b6a', '#52b788', '#e8a838', '#8b6fcb', '#4aabaa'];
                $totalCatArticles = 0;
                foreach ($articlesByCategory as $c) $totalCatArticles += $c['count'];
                $offset = 0;
                $circumference = 2 * M_PI * 40;
                foreach ($articlesByCategory as $idx => $cat):
                    if ($idx >= 6) break;
                    $pct = $totalCatArticles > 0 ? $cat['count'] / $totalCatArticles : 0;
                    $dash = $pct * $circumference;
                    $gap = $circumference - $dash;
                    $color = $donutColors[$idx % count($donutColors)];
                ?>
                <circle cx="50" cy="50" r="40" fill="none" stroke="<?php echo $color; ?>" stroke-width="13" stroke-dasharray="<?php echo round($dash, 1); ?> <?php echo round($gap, 1); ?>" stroke-dashoffset="<?php echo round(-$offset, 1); ?>" stroke-linecap="round"/>
                <?php
                    $offset += $dash;
                endforeach;
                ?>
              </svg>
              <div class="donut-center">
                <div class="value"><?php echo $totalCatArticles; ?></div>
                <div class="label">مقال</div>
              </div>
            </div>
            <div class="donut-legend">
              <?php foreach ($articlesByCategory as $idx => $cat):
                  if ($idx >= 6) break;
                  $color = $donutColors[$idx % count($donutColors)];
                  $pct = $totalCatArticles > 0 ? round($cat['count'] / $totalCatArticles * 100) : 0;
              ?>
              <div class="legend-item">
                <div class="legend-left"><div class="legend-dot" style="background:<?php echo $color; ?>"></div><?php echo e($cat['name']); ?></div>
                <div class="legend-val"><?php echo $pct; ?>%</div>
              </div>
              <?php endforeach; ?>
              <?php if (empty($articlesByCategory)): ?>
              <div style="text-align:center;color:var(--text-muted);padding:20px 0;font-size:13px;">لا توجد أقسام</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MID ROW -->
    <div class="mid-row">

      <!-- CATEGORIES PROGRESS -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><span class="card-title-dot dot-blue"></span>المقالات حسب القسم</div>
          <a href="categories.php" class="btn-outline">إدارة</a>
        </div>
        <div class="card-body">
          <?php foreach ($articlesByCategory as $idx => $cat):
              $color = $catBarColors[$idx % count($catBarColors)];
              $pct = $maxCatCount > 0 ? round($cat['count'] / $maxCatCount * 100) : 0;
              if ($pct < 5 && $cat['count'] > 0) $pct = 5;
          ?>
          <div class="progress-item">
            <div class="progress-label"><?php echo e($cat['name']); ?></div>
            <div class="progress-track"><div class="progress-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div></div>
            <div class="progress-val"><?php echo $cat['count']; ?></div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($articlesByCategory)): ?>
          <div style="text-align:center;color:var(--text-muted);padding:20px 0;font-size:13px;">لا توجد أقسام بعد</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- TOP SOURCES -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><span class="card-title-dot dot-green"></span>أفضل المصادر</div>
          <a href="sources.php" class="btn-outline">عرض الكل</a>
        </div>
        <div class="card-body">
          <?php foreach ($topSources as $idx => $source):
              $color = $sourceColors[$idx % count($sourceColors)];
              $pct = $maxSourceCount > 0 ? round($source['count'] / $maxSourceCount * 100) : 0;
          ?>
          <div class="progress-item">
            <div class="progress-label"><?php echo e($source['name']); ?></div>
            <div class="progress-track"><div class="progress-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div></div>
            <div class="progress-val"><?php echo $source['count']; ?></div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($topSources)): ?>
          <div style="text-align:center;color:var(--text-muted);padding:20px 0;font-size:13px;">لا توجد مصادر بعد</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- NOTIFICATIONS -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><span class="card-title-dot dot-yellow"></span>ملخص سريع</div>
        </div>
        <div class="card-body" style="padding:8px 16px;">
          <div class="notif-item">
            <div class="notif-icon-wrap" style="background:var(--secondary-light);">📝</div>
            <div><div class="notif-title">مقالات اليوم</div><div class="notif-sub"><?php echo $todayArticles; ?> مقال تم إضافته اليوم</div></div>
          </div>
          <div class="notif-item">
            <div class="notif-icon-wrap" style="background:var(--primary-light);">📰</div>
            <div><div class="notif-title">مقالات الأسبوع</div><div class="notif-sub"><?php echo $weekArticles; ?> مقال خلال 7 أيام</div></div>
          </div>
          <div class="notif-item">
            <div class="notif-icon-wrap" style="background:var(--danger-light);">🔥</div>
            <div><div class="notif-title">أخبار عاجلة</div><div class="notif-sub"><?php echo $breakingCount; ?> خبر عاجل نشط</div></div>
          </div>
          <div class="notif-item">
            <div class="notif-icon-wrap" style="background:var(--success-light);">👁️</div>
            <div><div class="notif-title">مشاهدات اليوم</div><div class="notif-sub"><?php echo number_format($todayViews); ?> مشاهدة</div></div>
          </div>
          <div class="notif-item">
            <div class="notif-icon-wrap" style="background:var(--warning-light);">📊</div>
            <div><div class="notif-title">متوسط المشاهدات</div><div class="notif-sub"><?php echo number_format($avgViews); ?> لكل مقال</div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ARTICLES TABLE -->
    <div class="table-section">
      <div class="card">
        <div class="card-header">
          <div class="card-title"><span class="card-title-dot dot-blue"></span>أحدث المقالات</div>
          <div style="display:flex;gap:8px;align-items:center;">
            <a href="articles.php" class="btn-outline">عرض الكل</a>
            <a href="articles.php?action=add" class="btn-primary" style="font-size:12px;padding:7px 14px;">✏️ مقال جديد</a>
          </div>
        </div>
        <table>
          <thead>
            <tr>
              <th>المقال</th>
              <th>المصدر</th>
              <th>التصنيف</th>
              <th>المشاهدات</th>
              <th>الحالة</th>
              <th>التاريخ</th>
              <th>إجراء</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentArticles as $idx => $article):
                $thumbBg = $thumbBgs[$idx % count($thumbBgs)];
                $thumbGrad = $thumbGrads[$idx % count($thumbGrads)];
                $firstChar = mb_substr($article['source_name'] ?? $article['title'], 0, 1, 'UTF-8');
                $viewPct = $maxViews > 0 ? round($article['view_count'] / $maxViews * 100) : 0;
                $statusBadge = ($article['status'] === 'published') ? 'badge-success' : 'badge-muted';
                $statusText = ($article['status'] === 'published') ? 'منشور' : 'مسودة';
                $catBadge = 'badge-primary';
                $css = $article['css_class'] ?? '';
                if (strpos($css, 'political') !== false) $catBadge = 'badge-danger';
                elseif (strpos($css, 'economic') !== false || strpos($css, 'econ') !== false) $catBadge = 'badge-success';
                elseif (strpos($css, 'sport') !== false) $catBadge = 'badge-warning';
                elseif (strpos($css, 'art') !== false || strpos($css, 'culture') !== false) $catBadge = 'badge-teal';
                elseif (strpos($css, 'tech') !== false) $catBadge = 'badge-purple';
                elseif (strpos($css, 'report') !== false) $catBadge = 'badge-primary';
            ?>
            <tr>
              <td>
                <div class="article-info">
                  <div class="article-thumb" style="background:<?php echo $thumbBg; ?>;">
                    <?php echo e($firstChar); ?>
                  </div>
                  <div>
                    <div class="article-title-text">
                      <?php if ($article['is_breaking']): ?><span class="badge badge-danger" style="font-size:10px;padding:1px 6px;margin-left:4px;">عاجل</span><?php endif; ?>
                      <?php echo e($article['title']); ?>
                    </div>
                    <div class="article-meta"><?php echo e($article['source_name'] ?? '—'); ?> · <?php echo timeAgo($article['created_at']); ?></div>
                  </div>
                </div>
              </td>
              <td>
                <div style="display:flex;align-items:center;gap:6px;">
                  <div style="width:28px;height:28px;border-radius:9px;background:<?php echo $thumbGrad; ?>;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;"><?php echo e($firstChar); ?></div>
                  <span style="color:var(--text-secondary)"><?php echo e($article['source_name'] ?? '—'); ?></span>
                </div>
              </td>
              <td><span class="badge <?php echo $catBadge; ?>"><?php echo e($article['cat_name'] ?? '—'); ?></span></td>
              <td>
                <div class="views-bar">
                  <span style="min-width:48px;font-weight:700;color:var(--text-primary);"><?php echo number_format($article['view_count']); ?></span>
                  <div class="bar-track"><div class="bar-fill" style="width:<?php echo $viewPct; ?>%"></div></div>
                </div>
              </td>
              <td><span class="badge <?php echo $statusBadge; ?>"><?php echo $statusText; ?></span></td>
              <td style="color:var(--text-muted);font-size:12px;"><?php echo date('j M', strtotime($article['created_at'])); ?></td>
              <td><a href="articles.php?action=edit&id=<?php echo $article['id']; ?>" class="action-btn">✏️ تعديل</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentArticles)): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:30px;">لا توجد مقالات بعد</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        <div style="padding:13px 18px;border-top:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--text-muted);">
          <span>عرض <?php echo count($recentArticles); ?> من <?php echo number_format($totalArticles); ?> مقال</span>
          <a href="articles.php" class="action-btn">عرض الكل</a>
        </div>
      </div>
    </div>

    <!-- BOTTOM ROW -->
    <div class="bottom-row">

      <!-- QUICK ACTIONS -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><span class="card-title-dot dot-green"></span>إجراءات سريعة</div>
        </div>
        <div class="card-body">
          <div class="quick-action-grid">
            <a href="articles.php?action=add" class="quick-action-btn">
              <div class="quick-action-icon" style="background:var(--primary-light);">✏️</div>
              <div>
                <div class="quick-action-name">إضافة مقال</div>
                <div class="quick-action-desc">كتابة مقال جديد</div>
              </div>
            </a>
            <a href="sources.php?action=add" class="quick-action-btn">
              <div class="quick-action-icon" style="background:var(--success-light);">🌐</div>
              <div>
                <div class="quick-action-name">إضافة مصدر</div>
                <div class="quick-action-desc">ربط مصدر أخبار</div>
              </div>
            </a>
            <a href="categories.php" class="quick-action-btn">
              <div class="quick-action-icon" style="background:var(--warning-light);">📂</div>
              <div>
                <div class="quick-action-name">إدارة الأقسام</div>
                <div class="quick-action-desc">تعديل التصنيفات</div>
              </div>
            </a>
            <a href="ticker.php" class="quick-action-btn">
              <div class="quick-action-icon" style="background:var(--danger-light);">🔥</div>
              <div>
                <div class="quick-action-name">الشريط الإخباري</div>
                <div class="quick-action-desc">إدارة الأخبار العاجلة</div>
              </div>
            </a>
          </div>
        </div>
      </div>

      <!-- TOP VIEWED -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><span class="card-title-dot dot-purple"></span>الأكثر مشاهدة</div>
        </div>
        <div class="card-body" style="padding:8px 16px;">
          <?php foreach ($topViewed as $idx => $article):
              $rankBg = 'var(--bg-page)';
              $rankColor = 'var(--text-muted)';
              if ($idx === 0) { $rankBg = 'var(--warning-light)'; $rankColor = 'var(--warning)'; }
              elseif ($idx === 1) { $rankBg = 'var(--primary-light)'; $rankColor = 'var(--primary)'; }
              elseif ($idx === 2) { $rankBg = 'var(--secondary-light)'; $rankColor = 'var(--secondary)'; }
          ?>
          <div class="notif-item">
            <div class="notif-icon-wrap" style="background:<?php echo $rankBg; ?>;color:<?php echo $rankColor; ?>;font-weight:800;font-size:14px;"><?php echo $idx + 1; ?></div>
            <div style="flex:1;min-width:0;">
              <div class="notif-title" style="overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"><?php echo e($article['title']); ?></div>
              <div class="notif-sub"><?php echo number_format($article['view_count']); ?> مشاهدة</div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($topViewed)): ?>
          <div style="text-align:center;color:var(--text-muted);padding:20px 0;font-size:13px;">لا توجد مقالات بعد</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- SUMMARY CARD -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><span class="card-title-dot dot-teal"></span>إحصائيات سريعة</div>
        </div>
        <div class="card-body">
          <div class="summary-grid">
            <div class="summary-item">
              <div class="val" style="color:var(--primary);"><?php echo $todayArticles; ?></div>
              <div class="lbl">مقالات اليوم</div>
            </div>
            <div class="summary-item">
              <div class="val" style="color:var(--success);"><?php echo $weekArticles; ?></div>
              <div class="lbl">مقالات الأسبوع</div>
            </div>
            <div class="summary-item">
              <div class="val" style="color:var(--danger);"><?php echo $breakingCount; ?></div>
              <div class="lbl">أخبار عاجلة</div>
            </div>
            <div class="summary-item">
              <div class="val" style="color:var(--warning);"><?php echo number_format($todayViews); ?></div>
              <div class="lbl">مشاهدات اليوم</div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- FOOTER -->
  <footer class="footer-bar">
    <div class="footer-status"><span class="status-dot"></span>جميع الأنظمة تعمل بشكل طبيعي</div>
    <div>نيوزفلو · لوحة التحكم</div>
    <div>جميع الحقوق محفوظة © <?php echo date('Y'); ?></div>
  </footer>

</main>

<script>
  document.querySelectorAll('.chip').forEach(chip => {
    chip.addEventListener('click', function() {
      this.closest('.card-actions').querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
      this.classList.add('active');
    });
  });
</script>
</body>
</html>