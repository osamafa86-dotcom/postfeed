<?php
/**
 * Shared panel layout head + sidebar + topbar.
 * Expects: $pageTitle (string), $activePage (string).
 */
if (!isset($db) || !$db) {
    $db = getDB();
}
if (!isset($totalArticles)) {
    try { $totalArticles = $db->query("SELECT COUNT(*) FROM articles")->fetchColumn(); }
    catch (Exception $e) { $totalArticles = 0; }
}
if (!isset($breakingCount)) {
    try { $breakingCount = $db->query("SELECT COUNT(*) FROM articles WHERE is_breaking = 1")->fetchColumn(); }
    catch (Exception $e) { $breakingCount = 0; }
}
$adminName = $_SESSION['admin_name'] ?? 'المدير';
$activePage = $activePage ?? '';
$pageTitle  = $pageTitle  ?? 'نيوزفلو';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
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
    text-decoration:none;
  }
  .icon-btn:hover { background:var(--primary-light); border-color:var(--primary); color:var(--primary); }

  .user-avatar {
    width:38px; height:38px; border-radius:11px;
    background: linear-gradient(135deg, var(--primary), #6b9fe8);
    display:flex; align-items:center; justify-content:center;
    font-weight:700; font-size:14px; color:#fff;
    cursor:pointer; border:2px solid var(--primary-light);
    box-shadow: 0 2px 8px rgba(74,127,203,0.2);
  }

  .live-badge {
    display:inline-flex; align-items:center; gap:5px;
    background:#fde8e5; color:#c0392b;
    padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700;
    border:1px solid #f5c2bb;
  }
  .live-dot { width:6px; height:6px; background:#c0392b; border-radius:50%; animation:pulse 1s infinite; }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.75} }

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
    display:inline-flex; align-items:center; gap:6px;
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
    display:inline-flex; align-items:center; gap:6px; transition:var(--transition);
    text-decoration:none;
  }
  .btn-secondary:hover { background:var(--secondary); color:#fff; }

  .btn-outline {
    background:none; color:var(--text-secondary);
    border:1.5px solid var(--border); padding:7px 14px; border-radius:10px;
    font-size:12px; font-weight:600; cursor:pointer;
    font-family:'Tajawal',sans-serif; transition:var(--transition);
    text-decoration:none; display:inline-block;
  }
  .btn-outline:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-soft); }

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
  .card-body { padding:20px; }

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

  .action-btn {
    background:none; border:1.5px solid var(--border);
    color:var(--text-secondary); padding:5px 12px; border-radius:9px;
    font-size:12px; cursor:pointer; font-family:'Tajawal',sans-serif;
    transition:var(--transition); font-weight:500;
    text-decoration:none; display:inline-block;
  }
  .action-btn:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-soft); }

  .form-card { background:var(--bg-card); border:1.5px solid var(--border); border-radius:18px; padding:24px; box-shadow:var(--shadow); margin-bottom:20px; }
  .form-group { margin-bottom:16px; }
  .form-group label { display:block; font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:6px; }
  .form-control { width:100%; padding:10px 14px; border:1.5px solid var(--border); border-radius:11px; font-family:'Tajawal',sans-serif; font-size:13px; background:var(--bg-input); color:var(--text-primary); outline:none; transition:var(--transition); }
  .form-control:focus { border-color:var(--primary); background:#fff; box-shadow:0 0 0 3px var(--primary-light); }
  textarea.form-control { min-height:120px; resize:vertical; }
  .form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .alert { padding:12px 16px; border-radius:12px; font-size:13px; margin-bottom:16px; border:1.5px solid; }
  .alert-success { background:var(--success-light); color:var(--success); border-color:#c8e8d8; }
  .alert-danger { background:var(--danger-light); color:var(--danger); border-color:#f5c2bb; }
  .alert-error { background:var(--danger-light); color:var(--danger); border-color:#f5c2bb; }
  .btn-danger { background:var(--danger-light); color:var(--danger); border:none; padding:7px 14px; border-radius:10px; font-family:'Tajawal',sans-serif; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; transition:var(--transition); }
  .btn-danger:hover { background:var(--danger); color:#fff; }
  .checkbox-group { display:flex; gap:16px; flex-wrap:wrap; }
  .checkbox-item { display:flex; align-items:center; gap:6px; font-size:13px; }
  .page-actions { display:flex; gap:10px; align-items:center; }

  .pagination { display:flex; justify-content:center; gap:6px; margin-top:20px; padding:16px; flex-wrap:wrap; }
  .pagination a, .pagination span { padding:7px 12px; border:1.5px solid var(--border); border-radius:9px; text-decoration:none; color:var(--text-secondary); font-size:12px; font-weight:600; }
  .pagination a:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-soft); }
  .pagination .active { background:var(--primary); color:#fff; border-color:var(--primary); }
  .pagination .disabled { color:var(--text-muted); opacity:0.6; }

  @media(max-width:1200px) {
    .form-row { grid-template-columns:1fr; }
  }
  @media(max-width:768px) {
    .sidebar { display:none; }
    .main { margin-right:0; }
    .topbar { flex-wrap:wrap; height:auto; padding:12px; }
    .search-box { width:100%; }
  }
</style>
</head>
<body>

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
    <a href="index.php" class="nav-item<?php echo $activePage==='index'?' active':''; ?>">
      <div class="nav-icon">📊</div>
      <span class="label">لوحة التحكم</span>
    </a>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">المحتوى</div>
    <a href="articles.php" class="nav-item<?php echo $activePage==='articles'?' active':''; ?>">
      <div class="nav-icon">✍️</div>
      <span class="label">الأخبار</span>
      <span class="nav-badge blue"><?php echo (int)$totalArticles; ?></span>
    </a>
    <a href="categories.php" class="nav-item<?php echo $activePage==='categories'?' active':''; ?>">
      <div class="nav-icon">📂</div>
      <span class="label">الأقسام</span>
    </a>
    <a href="sources.php" class="nav-item<?php echo $activePage==='sources'?' active':''; ?>">
      <div class="nav-icon">🌐</div>
      <span class="label">المصادر</span>
    </a>
    <a href="ticker.php" class="nav-item<?php echo $activePage==='ticker'?' active':''; ?>">
      <div class="nav-icon">📢</div>
      <span class="label">الشريط الإخباري</span>
      <?php if ($breakingCount > 0): ?><span class="nav-badge yellow"><?php echo (int)$breakingCount; ?></span><?php endif; ?>
    </a>
    <a href="reels.php" class="nav-item<?php echo $activePage==='reels'?' active':''; ?>">
      <div class="nav-icon">🎬</div>
      <span class="label">الريلز</span>
    </a>
    <a href="telegram.php" class="nav-item<?php echo $activePage==='telegram'?' active':''; ?>">
      <div class="nav-icon">📢</div>
      <span class="label">تيليغرام</span>
    </a>
    <a href="ai.php" class="nav-item<?php echo $activePage==='ai'?' active':''; ?>">
      <div class="nav-icon">🤖</div>
      <span class="label">الذكاء الاصطناعي</span>
    </a>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">الإدارة</div>
    <a href="settings.php" class="nav-item<?php echo $activePage==='settings'?' active':''; ?>">
      <div class="nav-icon">⚙️</div>
      <span class="label">الإعدادات</span>
    </a>
    <a href="audit.php" class="nav-item<?php echo $activePage==='audit'?' active':''; ?>">
      <div class="nav-icon">📋</div>
      <span class="label">سجل التدقيق</span>
    </a>
    <a href="logout.php" class="nav-item">
      <div class="nav-icon">🚪</div>
      <span class="label">تسجيل الخروج</span>
    </a>
  </div>
</aside>

<main class="main">
  <header class="topbar">
    <div class="topbar-greeting">
      <h3>مرحباً بك، <?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?> 👋</h3>
      <p><?php echo date('Y/m/d H:i'); ?></p>
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
