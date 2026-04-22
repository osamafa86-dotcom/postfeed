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
$pageTitle  = $pageTitle  ?? 'نيوز فيد';

// Build fingerprint for the admin too — same console banner + meta
// tag pattern as the public site, so you can confirm a deploy from
// the dashboard without opening a second tab.
require_once __DIR__ . '/../../includes/version.php';
$__nfVer = app_version();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="site-version" content="<?php echo htmlspecialchars($__nfVer['full'], ENT_QUOTES, 'UTF-8'); ?>">
<meta name="site-deployed-at" content="<?php echo htmlspecialchars($__nfVer['deployed_iso'], ENT_QUOTES, 'UTF-8'); ?>">
<script>
(function () {
  try {
    var v = <?php echo json_encode($__nfVer, JSON_UNESCAPED_SLASHES); ?>;
    var deployed = v.deployed_at ? new Date(v.deployed_at * 1000).toISOString().replace('T', ' ').slice(0, 16) : '?';
    console.log(
      '%c نيوز فيد — لوحة %c v' + v.full + ' %c ' + deployed + ' UTC ',
      'background:#6366f1;color:#fff;padding:2px 8px;border-radius:4px 0 0 4px;font-weight:700',
      'background:#312e81;color:#c7d2fe;padding:2px 8px;font-family:monospace',
      'background:#1f2937;color:#d1d5db;padding:2px 8px;border-radius:0 4px 4px 0;font-family:monospace'
    );
    window.__NF_VERSION = v;
  } catch (_) {}
})();
</script>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap');

  :root {
    --primary:       #6366f1;
    --primary-light: #eef2ff;
    --primary-soft:  #f5f3ff;
    --primary-dark:  #4f46e5;
    --secondary:     #ec6b56;
    --secondary-light: #fef2f0;
    --accent:        #f59e0b;
    --accent-light:  #fffbeb;
    --success:       #10b981;
    --success-light: #ecfdf5;
    --warning:       #f59e0b;
    --warning-light: #fffbeb;
    --danger:        #ef4444;
    --danger-light:  #fef2f2;
    --purple:        #8b5cf6;
    --purple-light:  #f5f3ff;
    --teal:          #14b8a6;
    --teal-light:    #f0fdfa;
    --cyan:          #06b6d4;
    --cyan-light:    #ecfeff;
    --rose:          #f43f5e;
    --rose-light:    #fff1f2;

    --bg-page:    #f1f5f9;
    --bg-card:    #ffffff;
    --bg-sidebar: #0f172a;
    --bg-sidebar-hover: #1e293b;
    --bg-sidebar-active: #6366f1;
    --bg-hover:   #f8fafc;
    --bg-input:   #f8fafc;
    --sidebar-border: #1e293b;
    --sidebar-text:   #cbd5e1;
    --sidebar-muted:  #64748b;

    --text-primary:   #0f172a;
    --text-secondary: #475569;
    --text-muted:     #94a3b8;

    --border:       #e2e8f0;
    --border-light: #f1f5f9;
    --shadow:       0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
    --shadow-md:    0 4px 6px rgba(0,0,0,0.05), 0 10px 15px rgba(0,0,0,0.04);
    --shadow-lg:    0 10px 25px rgba(0,0,0,0.06), 0 20px 48px rgba(0,0,0,0.04);
    --transition:   all 0.2s cubic-bezier(0.4,0,0.2,1);
    --radius:       12px;
    --radius-lg:    16px;
  }

  * { margin:0; padding:0; box-sizing:border-box; }

  body {
    font-family: 'Tajawal', sans-serif;
    background: var(--bg-page);
    color: var(--text-primary);
    display: flex;
    min-height: 100vh;
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
  }

  ::-webkit-scrollbar { width:6px; height:6px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius:10px; }
  ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

  /* ===== SIDEBAR ===== */
  .sidebar {
    width: 260px; min-height: 100vh;
    background: var(--bg-sidebar);
    display: flex; flex-direction: column;
    position: fixed; right: 0; top: 0; bottom: 0;
    z-index: 100; overflow-y: auto;
  }
  .sidebar::-webkit-scrollbar-thumb { background: #334155; }

  .sidebar-logo {
    padding: 20px 18px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    display: flex; align-items: center; gap: 12px;
  }

  .logo-icon {
    width: 42px; height: 42px;
    background: linear-gradient(135deg, #6366f1, #818cf8);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 19px;
    box-shadow: 0 4px 12px rgba(99,102,241,0.4);
  }

  .logo-text span:first-child { font-size: 17px; font-weight: 800; color: #f1f5f9; display:block; }
  .logo-text span:last-child  { font-size: 10px; color: #64748b; letter-spacing:2.5px; }

  .sidebar-section { padding: 8px 0; }
  .sidebar-section + .sidebar-section { border-top: 1px solid rgba(255,255,255,0.04); }

  .sidebar-section-label {
    padding: 10px 20px 6px;
    font-size: 10px; font-weight: 700;
    color: #475569;
    text-transform: uppercase; letter-spacing:2px;
  }

  .nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 14px; margin: 2px 10px;
    border-radius: 10px; cursor: pointer;
    transition: var(--transition);
    color: var(--sidebar-text);
    text-decoration: none;
  }
  .nav-item:hover { background: var(--bg-sidebar-hover); color: #f1f5f9; }
  .nav-item.active {
    background: var(--bg-sidebar-active);
    color: #fff; font-weight: 700;
    box-shadow: 0 4px 12px rgba(99,102,241,0.35);
  }

  .nav-icon {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
    background: rgba(255,255,255,0.06);
    transition: var(--transition);
  }
  .nav-item:hover .nav-icon { background: rgba(255,255,255,0.10); }
  .nav-item.active .nav-icon { background: rgba(255,255,255,0.20); }
  .nav-item span.label { font-size: 13.5px; flex:1; }

  .nav-badge {
    font-size: 10px; font-weight: 700;
    padding: 2px 8px; border-radius: 20px;
    background: rgba(255,255,255,0.10); color: #94a3b8;
  }
  .nav-badge.green  { background: rgba(16,185,129,0.15); color: #34d399; }
  .nav-badge.yellow { background: rgba(245,158,11,0.15); color: #fbbf24; }
  .nav-badge.blue   { background: rgba(99,102,241,0.15); color: #a5b4fc; }
  .nav-item.active .nav-badge { background: rgba(255,255,255,0.20); color: #fff; }

  /* ===== MAIN + TOPBAR ===== */
  .main { flex:1; margin-right:260px; display:flex; flex-direction:column; min-height:100vh; }

  .topbar {
    height: 64px; background: #ffffff;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; padding: 0 24px; gap: 14px;
    position: sticky; top:0; z-index:90;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
  }
  .topbar-greeting { flex:1; }
  .topbar-greeting h3 { font-size:15px; font-weight:700; color:var(--text-primary); }
  .topbar-greeting p  { font-size:12px; color:var(--text-muted); }

  .search-box {
    display: flex; align-items: center; gap: 8px;
    background: var(--bg-input);
    border: 1.5px solid var(--border);
    border-radius: var(--radius); padding: 8px 14px; width: 260px;
    transition: var(--transition);
  }
  .search-box:focus-within { border-color: var(--primary); background:#fff; box-shadow: 0 0 0 3px var(--primary-light); }
  .search-box input { background:none; border:none; outline:none; font-family:'Tajawal',sans-serif; font-size:13px; color:var(--text-primary); flex:1; direction:rtl; }
  .search-box input::placeholder { color:var(--text-muted); }
  .search-icon { color:var(--text-muted); font-size:15px; }

  .topbar-actions { display:flex; align-items:center; gap:8px; }

  .icon-btn {
    width:38px; height:38px; border-radius:10px;
    background:var(--bg-input); border:1.5px solid var(--border);
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:var(--transition); font-size:16px;
    color: var(--text-secondary); position:relative;
    text-decoration:none;
  }
  .icon-btn:hover { background:var(--primary-light); border-color:var(--primary); color:var(--primary); }

  .user-avatar {
    width:38px; height:38px; border-radius:10px;
    background: linear-gradient(135deg, #6366f1, #818cf8);
    display:flex; align-items:center; justify-content:center;
    font-weight:700; font-size:14px; color:#fff;
    cursor:pointer; border:2px solid var(--primary-light);
    box-shadow: 0 2px 8px rgba(99,102,241,0.25);
  }

  .live-badge {
    display:inline-flex; align-items:center; gap:5px;
    background:var(--danger-light); color:var(--danger);
    padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700;
    border:1px solid #fecaca;
  }
  .live-dot { width:6px; height:6px; background:var(--danger); border-radius:50%; animation:pulse 1.5s infinite; }
  @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.6;transform:scale(0.85)} }

  /* ===== CONTENT ===== */
  .content { padding:24px; flex:1; max-width:1400px; }

  .page-header {
    display:flex; justify-content:space-between; align-items:center; margin-bottom:22px;
  }
  .page-header h2 { font-size:20px; font-weight:800; color:var(--text-primary); }
  .page-header p  { font-size:12px; color:var(--text-muted); margin-top:3px; }

  /* ===== BUTTONS ===== */
  .btn-primary {
    background: var(--primary);
    color:#fff; border:none; padding:9px 18px; border-radius:10px;
    font-size:13px; font-weight:600; cursor:pointer;
    font-family:'Tajawal',sans-serif;
    display:inline-flex; align-items:center; gap:6px;
    transition:var(--transition); white-space:nowrap;
    box-shadow: 0 1px 3px rgba(99,102,241,0.3);
    text-decoration:none;
  }
  .btn-primary:hover { background:var(--primary-dark); box-shadow:0 4px 12px rgba(99,102,241,0.35); transform:translateY(-1px); }

  .btn-secondary {
    background:var(--secondary-light); color:var(--secondary);
    border:none; padding:9px 18px; border-radius:10px;
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

  /* ===== CARDS ===== */
  .card {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--radius-lg); overflow:hidden; box-shadow:var(--shadow);
  }
  .card-header {
    padding:16px 20px; border-bottom:1px solid var(--border-light);
    display:flex; align-items:center; justify-content:space-between;
  }
  .card-title {
    font-size:14px; font-weight:700; color:var(--text-primary);
    display:flex; align-items:center; gap:8px;
  }
  .card-body { padding:20px; }

  /* ===== TABLES ===== */
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
  tr:hover td { background:var(--bg-hover); }
  tr:last-child td { border:none; }

  /* ===== BADGES ===== */
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

  /* ===== FORMS ===== */
  .form-card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-lg); padding:24px; box-shadow:var(--shadow); margin-bottom:20px; }
  .form-group { margin-bottom:16px; }
  .form-group label { display:block; font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:6px; }
  .form-control { width:100%; padding:10px 14px; border:1.5px solid var(--border); border-radius:var(--radius); font-family:'Tajawal',sans-serif; font-size:13px; background:var(--bg-input); color:var(--text-primary); outline:none; transition:var(--transition); }
  .form-control:focus { border-color:var(--primary); background:#fff; box-shadow:0 0 0 3px var(--primary-light); }
  textarea.form-control { min-height:120px; resize:vertical; }
  .form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .alert { padding:12px 16px; border-radius:var(--radius); font-size:13px; margin-bottom:16px; border:1.5px solid; }
  .alert-success { background:var(--success-light); color:#065f46; border-color:#a7f3d0; }
  .alert-danger { background:var(--danger-light); color:#991b1b; border-color:#fecaca; }
  .alert-error { background:var(--danger-light); color:#991b1b; border-color:#fecaca; }
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

  /* ===== COMMAND PALETTE (Cmd+K) ===== */
  .kbd-hint {
    display:inline-block; padding:2px 7px; border-radius:5px;
    background:#fff; border:1px solid var(--border);
    font-size:10px; font-family:monospace; color:var(--text-muted);
    font-weight:600; letter-spacing:0.5px;
  }
  .cmd-backdrop {
    position:fixed; inset:0; background:rgba(15,23,42,0.55);
    backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
    z-index:1000; display:none;
    animation:cmdFadeIn 0.15s ease;
  }
  .cmd-backdrop.open { display:flex; align-items:flex-start; justify-content:center; padding-top:12vh; }
  .cmd-panel {
    width:560px; max-width:92vw; background:#fff;
    border-radius:16px; overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,0.3), 0 0 0 1px rgba(0,0,0,0.04);
    animation:cmdSlideIn 0.2s cubic-bezier(0.4,0,0.2,1);
  }
  @keyframes cmdFadeIn { from{opacity:0} to{opacity:1} }
  @keyframes cmdSlideIn { from{opacity:0;transform:translateY(-12px) scale(0.98)} to{opacity:1;transform:translateY(0) scale(1)} }
  .cmd-input-wrap {
    display:flex; align-items:center; gap:10px;
    padding:14px 18px; border-bottom:1px solid var(--border-light);
  }
  .cmd-input-wrap .ico { font-size:18px; color:var(--text-muted); }
  .cmd-input {
    flex:1; border:none; outline:none;
    font-family:'Tajawal',sans-serif; font-size:16px;
    color:var(--text-primary); direction:rtl; background:none;
  }
  .cmd-input::placeholder { color:var(--text-muted); }
  .cmd-results { max-height:52vh; overflow-y:auto; padding:6px 0; }
  .cmd-section-label {
    font-size:10px; font-weight:700; color:var(--text-muted);
    padding:10px 18px 6px; text-transform:uppercase; letter-spacing:1.5px;
  }
  .cmd-item {
    display:flex; align-items:center; gap:12px;
    padding:10px 18px; cursor:pointer;
    text-decoration:none; color:var(--text-primary);
    transition:background 0.1s;
  }
  .cmd-item:hover, .cmd-item.active {
    background:var(--primary-soft);
  }
  .cmd-item.active { background:var(--primary-light); }
  .cmd-item .cmd-ico {
    width:32px; height:32px; border-radius:8px;
    background:var(--bg-page); display:flex;
    align-items:center; justify-content:center; font-size:15px;
    flex-shrink:0;
  }
  .cmd-item.active .cmd-ico { background:#fff; }
  .cmd-item-body { flex:1; min-width:0; }
  .cmd-item-title { font-size:13.5px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .cmd-item-sub { font-size:11px; color:var(--text-muted); margin-top:2px; }
  .cmd-enter {
    font-size:10px; padding:2px 7px; border-radius:5px;
    background:var(--border-light); color:var(--text-muted);
    font-family:monospace; opacity:0; transition:opacity 0.1s;
  }
  .cmd-item.active .cmd-enter { opacity:1; background:var(--primary); color:#fff; }
  .cmd-footer {
    padding:10px 18px; border-top:1px solid var(--border-light);
    display:flex; justify-content:space-between; align-items:center;
    font-size:11px; color:var(--text-muted);
    background:var(--bg-page);
  }
  .cmd-footer .kbd { background:#fff; border:1px solid var(--border); padding:2px 6px; border-radius:4px; font-family:monospace; color:var(--text-secondary); }
  .cmd-empty { padding:40px 18px; text-align:center; color:var(--text-muted); font-size:13px; }

  /* ===== TOAST NOTIFICATIONS ===== */
  .toast-stack {
    position:fixed; top:20px; left:20px;
    display:flex; flex-direction:column; gap:8px;
    z-index:1100; pointer-events:none;
  }
  .toast {
    pointer-events:auto;
    background:#fff; padding:12px 18px; border-radius:10px;
    box-shadow:0 10px 30px rgba(0,0,0,0.12), 0 0 0 1px rgba(0,0,0,0.04);
    display:flex; align-items:center; gap:10px;
    min-width:260px; max-width:400px;
    font-size:13px; font-weight:600;
    animation:toastIn 0.25s cubic-bezier(0.4,0,0.2,1);
    border-right:4px solid var(--primary);
  }
  .toast.success { border-right-color:var(--success); color:var(--success); }
  .toast.error   { border-right-color:var(--danger);  color:var(--danger); }
  .toast.warn    { border-right-color:var(--warning); color:var(--warning); }
  .toast.exit { animation:toastOut 0.2s ease forwards; }
  @keyframes toastIn  { from{opacity:0;transform:translateX(-30px)} to{opacity:1;transform:translateX(0)} }
  @keyframes toastOut { from{opacity:1;transform:translateX(0)} to{opacity:0;transform:translateX(-30px)} }

  /* ===== QUICK CAPTURE FAB ===== */
  .qc-fab {
    position:fixed; bottom:24px; left:24px; z-index:500;
    width:52px; height:52px; border-radius:16px;
    background:linear-gradient(135deg, #6366f1, #818cf8);
    color:#fff; border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    font-size:22px; box-shadow:0 6px 20px rgba(99,102,241,0.45);
    transition:all 0.25s cubic-bezier(0.4,0,0.2,1);
  }
  .qc-fab:hover { transform:scale(1.08) translateY(-2px); box-shadow:0 8px 28px rgba(99,102,241,0.55); }
  .qc-fab:active { transform:scale(0.95); }

  .qc-panel {
    position:fixed; bottom:86px; left:24px; z-index:500;
    width:360px; max-width:calc(100vw - 48px);
    background:#fff; border-radius:16px;
    box-shadow:0 20px 60px rgba(0,0,0,0.2), 0 0 0 1px rgba(0,0,0,0.04);
    display:none; animation:qcSlideUp 0.25s cubic-bezier(0.4,0,0.2,1);
  }
  .qc-panel.open { display:block; }
  @keyframes qcSlideUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
  .qc-panel-header {
    padding:16px 18px; border-bottom:1px solid var(--border-light);
    display:flex; align-items:center; justify-content:space-between;
  }
  .qc-panel-header h4 { font-size:14px; font-weight:800; display:flex; align-items:center; gap:8px; }
  .qc-panel-body { padding:14px 18px; }
  .qc-input {
    width:100%; padding:10px 14px; border:1.5px solid var(--border);
    border-radius:10px; font-family:'Tajawal',sans-serif;
    font-size:14px; font-weight:600; direction:rtl;
    background:var(--bg-input); color:var(--text-primary);
    outline:none; transition:var(--transition);
    margin-bottom:10px;
  }
  .qc-input:focus { border-color:var(--primary); background:#fff; box-shadow:0 0 0 3px var(--primary-light); }
  .qc-textarea {
    width:100%; min-height:80px; padding:10px 14px;
    border:1.5px solid var(--border); border-radius:10px;
    font-family:'Tajawal',sans-serif; font-size:13px;
    direction:rtl; background:var(--bg-input); color:var(--text-primary);
    outline:none; transition:var(--transition); resize:vertical;
    margin-bottom:12px;
  }
  .qc-textarea:focus { border-color:var(--primary); background:#fff; box-shadow:0 0 0 3px var(--primary-light); }
  .qc-actions { display:flex; gap:8px; }
  .qc-actions button {
    flex:1; padding:10px; border-radius:10px; border:none;
    font-family:'Tajawal',sans-serif; font-size:13px; font-weight:700;
    cursor:pointer; transition:var(--transition);
  }
  .qc-save {
    background:var(--primary); color:#fff;
    box-shadow:0 2px 8px rgba(99,102,241,0.3);
  }
  .qc-save:hover { background:var(--primary-dark); }
  .qc-draft { background:var(--bg-input); color:var(--text-secondary); border:1.5px solid var(--border) !important; }
  .qc-draft:hover { border-color:var(--primary) !important; color:var(--primary); }
  .qc-saved-list {
    max-height:150px; overflow-y:auto; margin-top:12px;
    border-top:1px solid var(--border-light); padding-top:10px;
  }
  .qc-saved-item {
    display:flex; align-items:center; gap:8px;
    padding:8px 10px; border-radius:8px; margin-bottom:4px;
    font-size:12px; font-weight:600; color:var(--text-primary);
    transition:var(--transition); cursor:pointer;
  }
  .qc-saved-item:hover { background:var(--bg-hover); }
  .qc-saved-item .qc-del {
    margin-right:auto; color:var(--text-muted); font-size:14px;
    opacity:0; transition:opacity 0.15s;
  }
  .qc-saved-item:hover .qc-del { opacity:1; }
</style>
</head>
<body>

<!-- Command Palette -->
<div class="cmd-backdrop" id="cmdBackdrop" onclick="if(event.target===this)closeCommandPalette()">
  <div class="cmd-panel">
    <div class="cmd-input-wrap">
      <span class="ico">⌘</span>
      <input type="text" class="cmd-input" id="cmdInput" placeholder="ابحث عن مقال أو صفحة أو أمر...">
      <span class="kbd-hint">ESC</span>
    </div>
    <div class="cmd-results" id="cmdResults"></div>
    <div class="cmd-footer">
      <span>تنقّل: <span class="kbd">↑↓</span> · اختر: <span class="kbd">↵</span></span>
      <span>⌘ Command Palette</span>
    </div>
  </div>
</div>

<!-- Toast stack -->
<div class="toast-stack" id="toastStack"></div>

<!-- Quick Capture FAB -->
<button class="qc-fab" id="qcFab" onclick="toggleQuickCapture()" title="التقاط سريع (Ctrl+Shift+N)">✏️</button>
<div class="qc-panel" id="qcPanel">
  <div class="qc-panel-header">
    <h4>⚡ التقاط سريع</h4>
    <span class="kbd-hint">Ctrl+Shift+N</span>
  </div>
  <div class="qc-panel-body">
    <input type="text" class="qc-input" id="qcTitle" placeholder="فكرة أو عنوان خبر...">
    <textarea class="qc-textarea" id="qcNotes" placeholder="ملاحظات (اختياري)..."></textarea>
    <div class="qc-actions">
      <button class="qc-save" onclick="qcSaveAndCreate()">🚀 إنشاء خبر</button>
      <button class="qc-draft" onclick="qcSaveLocally()">💾 حفظ محلياً</button>
    </div>
    <div class="qc-saved-list" id="qcSavedList"></div>
  </div>
</div>

<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">📰</div>
    <div class="logo-text">
      <span>نيوز فيد</span>
      <span>DASHBOARD</span>
    </div>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">الرئيسية</div>
    <a href="index.php" class="nav-item<?php echo $activePage==='index'?' active':''; ?>">
      <div class="nav-icon">📊</div>
      <span class="label">لوحة التحكم</span>
    </a>
    <a href="analytics.php" class="nav-item<?php echo $activePage==='analytics'?' active':''; ?>">
      <div class="nav-icon">📈</div>
      <span class="label">التحليلات</span>
    </a>
  </div>

  <?php
    // Sidebar visibility follows the same role hierarchy as
    // requireRole(): viewers see only the dashboard, editors see
    // content management pages, admins see everything.
    $__role     = function_exists('currentRole') ? (currentRole() ?? 'viewer') : 'admin';
    $__canEdit  = function_exists('hasRole') ? hasRole('editor') : true;
    $__canAdmin = function_exists('hasRole') ? hasRole('admin')  : true;
    $__roleLabels = ['admin' => 'مدير', 'editor' => 'محرّر', 'viewer' => 'مشاهد'];
    $__roleColors = ['admin' => '#6366f1', 'editor' => '#10b981', 'viewer' => '#64748b'];
  ?>
  <?php if ($__canEdit): ?>
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
    <a href="calendar.php" class="nav-item<?php echo $activePage==='calendar'?' active':''; ?>">
      <div class="nav-icon">📅</div>
      <span class="label">التقويم التحريري</span>
    </a>
    <a href="evolving_stories.php" class="nav-item<?php echo $activePage==='evolving_stories'?' active':''; ?>">
      <div class="nav-icon">📖</div>
      <span class="label">القصص المتطوّرة</span>
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
    <a href="twitter.php" class="nav-item<?php echo $activePage==='twitter'?' active':''; ?>">
      <div class="nav-icon">🐦</div>
      <span class="label">تويتر / X</span>
    </a>
    <a href="youtube.php" class="nav-item<?php echo $activePage==='youtube'?' active':''; ?>">
      <div class="nav-icon">▶️</div>
      <span class="label">يوتيوب</span>
    </a>
    <?php if ($__canAdmin): ?>
    <a href="ai.php" class="nav-item<?php echo $activePage==='ai'?' active':''; ?>">
      <div class="nav-icon">🤖</div>
      <span class="label">الذكاء الاصطناعي</span>
    </a>
    <a href="tts.php" class="nav-item<?php echo $activePage==='tts'?' active':''; ?>">
      <div class="nav-icon">🎙</div>
      <span class="label">الصوت والقراءة</span>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="sidebar-section">
    <div class="sidebar-section-label">الإدارة</div>
    <?php if ($__canAdmin): ?>
    <a href="settings.php" class="nav-item<?php echo $activePage==='settings'?' active':''; ?>">
      <div class="nav-icon">⚙️</div>
      <span class="label">الإعدادات</span>
    </a>
    <a href="twofa.php" class="nav-item<?php echo $activePage==='twofa'?' active':''; ?>">
      <div class="nav-icon">🔐</div>
      <span class="label">المصادقة الثنائية</span>
    </a>
    <a href="audit.php" class="nav-item<?php echo $activePage==='audit'?' active':''; ?>">
      <div class="nav-icon">📋</div>
      <span class="label">سجل التدقيق</span>
    </a>
    <a href="newsletter.php" class="nav-item<?php echo $activePage==='newsletter'?' active':''; ?>">
      <div class="nav-icon">📬</div>
      <span class="label">النشرة البريدية</span>
    </a>
    <a href="weekly_rewind.php" class="nav-item<?php echo $activePage==='weekly_rewind'?' active':''; ?>">
      <div class="nav-icon">📅</div>
      <span class="label">مراجعة الأسبوع</span>
    </a>
    <a href="preview.php" class="nav-item<?php echo $activePage==='preview'?' active':''; ?>">
      <div class="nav-icon">🧪</div>
      <span class="label">معاينة فرع</span>
    </a>
    <?php endif; ?>
    <div class="nav-item" style="cursor:default;background:transparent;">
      <div class="nav-icon">👤</div>
      <span class="label">صلاحيتك</span>
      <span class="nav-badge" style="background:<?php echo $__roleColors[$__role] ?? '#6b7280'; ?>;color:#fff;"><?php echo e($__roleLabels[$__role] ?? $__role); ?></span>
    </div>
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
    <div class="search-box" id="cmdTrigger" role="button" tabindex="0" style="cursor:pointer;" onclick="openCommandPalette()" onkeydown="if(event.key==='Enter')openCommandPalette()">
      <span class="search-icon">🔍</span>
      <input type="text" placeholder="ابحث أو نفّذ أمراً..." readonly style="cursor:pointer;pointer-events:none;flex:1;">
      <span class="kbd-hint">Ctrl+K</span>
    </div>
    <div class="topbar-actions">
      <div class="live-badge"><span class="live-dot"></span>مباشر</div>
      <span class="live-badge" style="background:var(--primary-light);color:var(--primary);border-color:#c7d2fe;direction:ltr;font-family:monospace;" title="النسخة المنشورة · <?php echo htmlspecialchars($__nfVer['deployed_iso'], ENT_QUOTES, 'UTF-8'); ?>">v<?php echo htmlspecialchars($__nfVer['full'], ENT_QUOTES, 'UTF-8'); ?></span>
      <a href="../" class="icon-btn" title="الموقع" target="_blank">🌐</a>
      <div class="user-avatar"><?php echo mb_substr($adminName, 0, 1, 'UTF-8'); ?></div>
    </div>
  </header>
