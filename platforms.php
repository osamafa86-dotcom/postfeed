<?php
/**
 * نيوز فيد — صفحة "المنصات" (Telegram / X / YouTube).
 *
 * Web counterpart of the app's PlatformsScreen. Self-contained page that
 * consumes the existing public API under /api/v1/media/ (already live):
 *   - social-summary  → rich, de-duplicated daily AI summary per platform
 *   - stats           → live analytics (totals, timeline, top sources/topics)
 *   - telegram/twitter/youtube?dedup=1 → feed with near-duplicates collapsed
 *
 * Deliberately additive: it does not modify index.php / telegram.php, so it
 * carries zero risk to the existing (working) feed sections.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';

$pageTheme = current_theme();
$viewer    = current_user();

$pageUrl   = SITE_URL . '/platforms';
$pageTitle = '📡 المنصات — ' . e(getSetting('site_name', SITE_NAME));
$metaDesc  = 'تابع أحدث ما يُنشر على تلغرام ومنصة X ويوتيوب، مع ملخص يومي ذكي وإحصاءات حيّة وإخفاء الأخبار المكرّرة.';
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title><?php echo $pageTitle; ?></title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<link rel="canonical" href="<?php echo e($pageUrl); ?>">
<meta property="og:title" content="<?php echo $pageTitle; ?>">
<meta property="og:description" content="<?php echo e($metaDesc); ?>">
<meta property="og:url" content="<?php echo e($pageUrl); ?>">
<meta property="og:type" content="website">
<?php include __DIR__ . '/includes/components/pwa_head.php'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" media="print" onload="this.media='all'">
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<style>
  /* ===== Self-contained design tokens (light / dark / auto) ===== */
  :root{
    --pf-bg:#F1F5F9; --pf-card:#FFFFFF; --pf-surface:#F8FAFC; --pf-border:#E6EBF2;
    --pf-text:#0F172A; --pf-text-2:#334155; --pf-muted:#64748B;
    --pf-shadow:0 6px 20px rgba(15,23,42,.06);
    --pf-accent:#0ea5e9; --pf-on-accent:#fff;
    --pf-accent-ink:#0369a1;                                    /* fallback for no color-mix */
    --pf-accent-ink:color-mix(in srgb, var(--pf-accent) 72%, #000);
    --pf-accent-btn:#0369a1;                                    /* white text always passes AA */
    --pf-accent-btn:color-mix(in srgb, var(--pf-accent) 76%, #000);
    --pf-tint:color-mix(in srgb, var(--pf-accent) 12%, var(--pf-card));
    --pf-track:color-mix(in srgb, var(--pf-accent) 18%, var(--pf-card));
  }
  [data-theme=dark]{
    --pf-bg:#0A1020; --pf-card:#121C30; --pf-surface:#1A2336; --pf-border:#243049;
    --pf-text:#E6ECF5; --pf-text-2:#C2CCDC; --pf-muted:#93A4BD;
    --pf-shadow:0 8px 24px rgba(0,0,0,.45);
    --pf-accent-ink:#7dd3fc;
    --pf-accent-ink:color-mix(in srgb, var(--pf-accent) 60%, #fff);
  }
  @media (prefers-color-scheme:dark){
    [data-theme=auto]{
      --pf-bg:#0A1020; --pf-card:#121C30; --pf-surface:#1A2336; --pf-border:#243049;
      --pf-text:#E6ECF5; --pf-text-2:#C2CCDC; --pf-muted:#93A4BD;
      --pf-shadow:0 8px 24px rgba(0,0,0,.45);
      --pf-accent-ink:#7dd3fc;
      --pf-accent-ink:color-mix(in srgb, var(--pf-accent) 60%, #fff);
    }
  }

  body{ background:var(--pf-bg); color:var(--pf-text);
        font-family:"Tajawal","Segoe UI",Tahoma,Arial,sans-serif; }

  .pf-wrap { max-width:980px; margin:0 auto; padding:18px 16px 64px; }
  .pf-h1 { font-size:26px; font-weight:900; margin:6px 0 4px; display:flex; align-items:center; gap:8px; color:var(--pf-text); }
  .pf-sub { color:var(--pf-muted); font-size:14px; line-height:1.7; margin-bottom:18px; }

  /* Tabs (segmented) */
  .pf-tabs { display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap; }
  .pf-tab {
    flex:1 1 0; min-width:104px; display:flex; align-items:center; justify-content:center; gap:8px;
    padding:12px 14px; border-radius:14px; border:1px solid var(--pf-border);
    background:var(--pf-card); color:var(--pf-text); font-weight:800; font-size:14px; cursor:pointer;
    transition:transform .12s, background .15s, color .15s, box-shadow .15s;
  }
  .pf-tab:hover { transform:translateY(-1px); }
  .pf-tab.active { color:var(--pf-on-accent); border-color:transparent; background:var(--pf-accent-btn); box-shadow:var(--pf-shadow); }

  /* Cards */
  .pf-card { background:var(--pf-card); border:1px solid var(--pf-border); border-radius:18px; padding:18px; margin-bottom:16px; box-shadow:var(--pf-shadow); }

  /* Summary */
  .pf-strip { height:4px; border-radius:4px; background:var(--pf-accent); margin:0 0 14px; }
  .pf-sum-head { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
  .pf-sum-head .ttl { font-weight:900; font-size:16px; color:var(--pf-accent-ink); }
  .pf-sum-head .ts { margin-inline-start:auto; color:var(--pf-muted); font-size:12px; }
  .pf-sum-headline { font-weight:900; font-size:18px; line-height:1.5; margin:6px 0; color:var(--pf-text); }
  .pf-sum-text { font-size:14.5px; line-height:1.85; color:var(--pf-text-2); }
  .pf-knums { display:flex; flex-wrap:wrap; gap:10px; margin-top:14px; }
  .pf-knum { background:var(--pf-tint); border-radius:12px; padding:9px 12px; min-width:92px; }
  .pf-knum b { display:block; font-size:16px; font-weight:900; color:var(--pf-accent-ink); }
  .pf-knum span { font-size:11.5px; color:var(--pf-muted); }
  .pf-sec { margin-top:16px; }
  .pf-sec h4 { font-size:15px; font-weight:800; margin:0 0 6px; color:var(--pf-text); }
  .pf-sec ul { margin:0; padding-inline-start:18px; }
  .pf-sec li { font-size:13.5px; line-height:1.8; margin-bottom:4px; color:var(--pf-text-2); }
  .pf-sec .why { font-size:12px; font-style:italic; color:var(--pf-muted); margin-top:6px; }
  .pf-topics { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; }
  .pf-topic { padding:6px 12px; border-radius:20px; border:1px solid var(--pf-border); font-size:12.5px; color:var(--pf-accent-ink); font-weight:700; }
  .pf-sum-foot { display:flex; align-items:center; gap:10px; margin-top:14px; }
  .pf-sum-foot .cnt { color:var(--pf-muted); font-size:12.5px; }
  .pf-btn {
    margin-inline-start:auto; background:var(--pf-accent-btn); border:none; color:var(--pf-on-accent);
    font-weight:800; font-size:13px; cursor:pointer; display:flex; align-items:center; gap:5px;
    padding:9px 14px; border-radius:10px;
  }
  .pf-collapsed { display:none; }

  /* Stats */
  .pf-stats-toggle {
    display:inline-flex; align-items:center; gap:6px; cursor:pointer; user-select:none;
    background:var(--pf-card); border:1px solid var(--pf-border); border-radius:12px; padding:10px 15px;
    font-weight:800; font-size:13.5px; color:var(--pf-text); margin-bottom:16px; box-shadow:var(--pf-shadow);
  }
  .pf-ranges { display:flex; gap:6px; margin-bottom:14px; }
  .pf-range { padding:7px 13px; border-radius:9px; border:1px solid var(--pf-border); background:var(--pf-surface); color:var(--pf-text-2); cursor:pointer; font-size:12.5px; font-weight:700; }
  .pf-range.active { background:var(--pf-accent-btn); color:var(--pf-on-accent); border-color:transparent; }
  .pf-kpis { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
  @media(min-width:560px){ .pf-kpis { grid-template-columns:repeat(4,1fr); } }
  .pf-kpi { background:var(--pf-surface); border:1px solid var(--pf-border); border-radius:14px; padding:14px; }
  .pf-kpi b { display:block; font-size:22px; font-weight:900; color:var(--pf-text); }
  .pf-kpi span { font-size:11.5px; color:var(--pf-muted); }
  .pf-bars { display:flex; align-items:flex-end; gap:4px; height:120px; margin:16px 0 4px; }
  .pf-bar-col { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; }
  .pf-bar { width:100%; background:var(--pf-accent); border-radius:4px 4px 0 0; min-height:3px; }
  .pf-bar-lbl { font-size:8px; color:var(--pf-muted); margin-top:4px; }
  .pf-sec-h { margin-top:18px; font-weight:800; font-size:14px; margin-bottom:10px; color:var(--pf-text); }
  .pf-srow { margin-bottom:11px; }
  .pf-srow .top { display:flex; justify-content:space-between; font-size:13px; font-weight:700; margin-bottom:5px; color:var(--pf-text); }
  .pf-srow .top span:last-child { color:var(--pf-accent-ink); }
  .pf-srow .track { height:8px; background:var(--pf-track); border-radius:4px; overflow:hidden; }
  .pf-srow .fill { height:100%; background:var(--pf-accent); border-radius:4px; }

  /* Feed */
  .pf-feed-bar { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
  .pf-feed-bar .lbl { font-weight:900; font-size:16px; color:var(--pf-text); }
  .pf-live { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:800; color:#16a34a; background:rgba(22,163,74,.12); padding:2px 9px; border-radius:20px; margin-inline-start:7px; vertical-align:middle; }
  .pf-live::before { content:''; width:7px; height:7px; border-radius:50%; background:#16a34a; animation:pfpulse 1.4s infinite; }
  @keyframes pfpulse { 0%{ box-shadow:0 0 0 0 rgba(22,163,74,.55); } 70%{ box-shadow:0 0 0 6px rgba(22,163,74,0); } 100%{ box-shadow:0 0 0 0 rgba(22,163,74,0); } }
  .pf-msg.pf-new { animation:pfflash 2.2s ease; }
  @keyframes pfflash { 0%{ background:var(--pf-tint); } 55%{ background:var(--pf-tint); } 100%{ background:var(--pf-card); } }
  .pf-switch { margin-inline-start:auto; display:flex; align-items:center; gap:8px; font-size:13px; color:var(--pf-muted); cursor:pointer; user-select:none; }
  .pf-switch input { appearance:none; -webkit-appearance:none; width:40px; height:22px; border-radius:11px; background:var(--pf-border); position:relative; cursor:pointer; transition:background .15s; flex:0 0 auto; }
  .pf-switch input:checked { background:var(--pf-accent-btn); }
  .pf-switch input::before { content:''; position:absolute; top:3px; inset-inline-start:3px; width:16px; height:16px; border-radius:50%; background:#fff; transition:inset-inline-start .15s; }
  .pf-switch input:checked::before { inset-inline-start:21px; }
  .pf-msg { background:var(--pf-card); border:1px solid var(--pf-border); border-radius:16px; padding:15px; margin-bottom:12px; display:block; color:inherit; text-decoration:none; box-shadow:var(--pf-shadow); transition:transform .12s; }
  .pf-msg:hover { transform:translateY(-1px); }
  .pf-msg-head { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:9px; }
  .pf-idg { display:flex; align-items:center; gap:10px; }
  .pf-who { display:flex; flex-direction:column; gap:1px; }
  .pf-who .nm { font-weight:800; font-size:14.5px; color:var(--pf-text); }
  .pf-who .un { font-size:11.5px; color:var(--pf-muted); }
  .pf-msg-head .tm { font-size:11.5px; color:var(--pf-muted); white-space:nowrap; }
  .pf-av { width:42px; height:42px; border-radius:50%; background:var(--pf-accent-btn); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:17px; overflow:hidden; flex:0 0 auto; }
  .pf-av img { width:100%; height:100%; object-fit:cover; }
  .pf-msg-text { font-size:15px; line-height:1.8; color:var(--pf-text); }
  .pf-msg-img { margin-top:10px; border-radius:12px; overflow:hidden; max-height:300px; }
  .pf-msg-img img { width:100%; display:block; object-fit:cover; }
  .pf-dup { display:inline-flex; align-items:center; gap:5px; margin-top:11px; padding:5px 10px; border-radius:9px; background:var(--pf-tint); color:var(--pf-accent-ink); font-size:12.5px; font-weight:700; }
  .pf-yt-thumb { position:relative; border-radius:12px; overflow:hidden; aspect-ratio:16/9; margin-bottom:10px; }
  .pf-yt-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
  .pf-yt-play { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:42px; color:#fff; text-shadow:0 2px 10px rgba(0,0,0,.55); }
  .pf-loading, .pf-empty, .pf-error { text-align:center; color:var(--pf-muted); padding:30px 14px; font-size:14px; line-height:1.7; }
  .pf-empty .ic, .pf-error .ic { font-size:30px; display:block; margin-bottom:8px; }
  .pf-empty .ti { display:block; font-size:16px; font-weight:800; color:var(--pf-text); margin-bottom:4px; }
  .pf-spinner { width:28px; height:28px; border:3px solid var(--pf-border); border-top-color:var(--pf-accent); border-radius:50%; animation:pfspin .8s linear infinite; margin:0 auto 12px; }
  @keyframes pfspin { to { transform:rotate(360deg); } }

  /* ===== Desktop: two-column layout (matches the desktop redesign) ===== */
  .pf-aside-title { display:none; }
  @media (min-width:1024px){
    .pf-wrap { max-width:1180px; }
    .pf-grid {
      display:grid;
      grid-template-columns:minmax(0,1fr) 360px;
      grid-template-areas:"summary stats" "feed stats";
      column-gap:22px; align-items:start;
    }
    #pfSummary { grid-area:summary; }
    .pf-aside { grid-area:stats; position:sticky; top:16px; }
    .pf-feedwrap { grid-area:feed; }
    #pfStatsToggle { display:none; }          /* stats are always shown in the sidebar */
    .pf-aside-title { display:flex; align-items:center; gap:7px; font-size:17px; font-weight:900; margin:2px 0 12px; color:var(--pf-text); }
  }
</style>
</head>
<body>

<?php
$activeType = 'platforms';
$showTicker = false;
include __DIR__ . '/includes/components/site_header.php';
?>

<main class="pf-wrap">
  <h1 class="pf-h1">📡 المنصات</h1>
  <p class="pf-sub">أحدث ما يُنشر على تلغرام ومنصة X ويوتيوب — مع ملخص يومي ذكي، إحصاءات حيّة، وإخفاء الأخبار المكرّرة.</p>

  <div class="pf-tabs" id="pfTabs">
    <button class="pf-tab active" data-p="telegram">✈️ تلغرام</button>
    <button class="pf-tab" data-p="twitter">𝕏 منصة X</button>
    <button class="pf-tab" data-p="youtube">▶️ يوتيوب</button>
  </div>

  <div class="pf-grid">
    <!-- Daily AI summary -->
    <div class="pf-card" id="pfSummary"><div class="pf-loading"><div class="pf-spinner"></div>جارٍ تحميل الملخص…</div></div>

    <!-- Stats: collapsible on mobile, a persistent sidebar on desktop -->
    <aside class="pf-aside">
      <h2 class="pf-aside-title">📊 إحصاءات اليوم</h2>
      <div class="pf-stats-toggle" id="pfStatsToggle">📊 <span>عرض الإحصاءات</span></div>
      <div class="pf-card pf-collapsed" id="pfStatsCard"></div>
    </aside>

    <!-- Feed -->
    <div class="pf-feedwrap">
      <div class="pf-feed-bar">
        <span class="lbl">أحدث المنشورات <span class="pf-live" title="يتحدّث تلقائياً">مباشر</span></span>
        <label class="pf-switch"><input type="checkbox" id="pfDedup" checked> إخفاء المكرر</label>
      </div>
      <div id="pfFeed"><div class="pf-loading"><div class="pf-spinner"></div>جارٍ التحميل…</div></div>
    </div>
  </div>
</main>

<script>
(function(){
  'use strict';
  var API = '/api/v1/media/';
  var ACCENT = { telegram:'#0ea5e9', twitter:'#1f2937', youtube:'#dc2626' };
  var state = { platform:'telegram', range:'24h', dedup:true, seen:null };

  var elTabs    = document.getElementById('pfTabs');
  var elSummary = document.getElementById('pfSummary');
  var elStatsToggle = document.getElementById('pfStatsToggle');
  var elStatsCard = document.getElementById('pfStatsCard');
  var elFeed    = document.getElementById('pfFeed');
  var elDedup   = document.getElementById('pfDedup');

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

  // Western → Arabic-Indic digits (display text only — never on style/markup values).
  function ar(s){ return String(s==null?'':s).replace(/[0-9]/g,function(d){ return '٠١٢٣٤٥٦٧٨٩'[+d]; }); }

  // Avatar markup: source image when available, otherwise the first letter of the name.
  function avatarHTML(s){
    if(s && s.avatar_url) return '<img src="'+esc(s.avatar_url)+'" alt="" loading="lazy">';
    var nm = (s && s.display_name ? String(s.display_name).trim() : '');
    return esc(nm ? nm.charAt(0) : '•');
  }

  function timeAgo(str){
    if(!str) return '';
    var t = Date.parse(String(str).replace(' ','T'));
    if(isNaN(t)) return '';
    var s = Math.floor((Date.now()-t)/1000);
    if(s<60) return 'الآن';
    if(s<3600) return 'قبل '+ar(Math.floor(s/60))+' د';
    if(s<86400) return 'قبل '+ar(Math.floor(s/3600))+' س';
    return 'قبل '+ar(Math.floor(s/86400))+' يوم';
  }

  function setAccent(){ document.documentElement.style.setProperty('--pf-accent', ACCENT[state.platform]); }

  function api(path){
    return fetch(API+path, { credentials:'same-origin', cache:'no-store' })
      .then(function(r){ return r.json().catch(function(){ return {ok:false}; }); });
  }

  // ---------- Summary ----------
  function loadSummary(){
    elSummary.innerHTML = '<div class="pf-loading"><div class="pf-spinner"></div>جارٍ تحميل الملخص…</div>';
    var p = state.platform;
    api('social-summary?platform='+p).then(function(res){
      if(state.platform!==p) return;
      if(!res || !res.ok || !res.data || !res.data.summary){
        elSummary.innerHTML = '<div class="pf-empty"><span class="ic">🗒️</span><span class="ti">لا يوجد ملخص متاح بعد</span>يُولَّد الملخص الذكي تلقائياً يومياً بمجرد توفّر عددٍ كافٍ من المنشورات.</div>';
        return;
      }
      renderSummary(res.data);
    });
  }

  function renderSummary(d){
    var h = '<div class="pf-strip"></div>';
    h += '<div class="pf-sum-head"><span style="font-size:18px">🧠</span><span class="ttl">ملخص اليوم</span>';
    if(d.generated_at) h += '<span class="ts">'+esc(timeAgo(d.generated_at))+'</span>';
    h += '</div>';
    if(d.headline) h += '<div class="pf-sum-headline">'+esc(d.headline)+'</div>';
    if(d.summary)  h += '<div class="pf-sum-text">'+esc(d.summary)+'</div>';

    // Expandable rich body.
    var body = '';
    if(d.key_numbers && d.key_numbers.length){
      body += '<div class="pf-knums">';
      d.key_numbers.forEach(function(n){ body += '<div class="pf-knum"><b>'+esc(n.value)+'</b><span>'+esc(n.context)+'</span></div>'; });
      body += '</div>';
    }
    (d.sections||[]).forEach(function(sec){
      body += '<div class="pf-sec"><h4>'+(sec.icon?esc(sec.icon)+' ':'')+esc(sec.title)+'</h4><ul>';
      (sec.items||[]).forEach(function(it){ body += '<li>'+esc(it)+'</li>'; });
      body += '</ul>';
      if(sec.why_matters) body += '<div class="why">لماذا يهم؟ '+esc(sec.why_matters)+'</div>';
      body += '</div>';
    });
    if(d.topics && d.topics.length){
      body += '<div class="pf-topics">';
      d.topics.forEach(function(t){ body += '<span class="pf-topic">#'+esc(t)+'</span>'; });
      body += '</div>';
    }
    if(body) h += '<div class="pf-collapsed" id="pfSumBody">'+body+'</div>';

    h += '<div class="pf-sum-foot">';
    if(d.message_count) h += '<span class="cnt">📡 جُمِّع من '+ar(d.message_count)+' منشور بلا تكرار</span>';
    if(body) h += '<button class="pf-btn" id="pfSumExpand">الملخص الكامل ▾</button>';
    h += '</div>';
    elSummary.innerHTML = h;

    var expand = document.getElementById('pfSumExpand');
    if(expand){
      expand.addEventListener('click', function(){
        var b = document.getElementById('pfSumBody');
        var open = b.classList.toggle('pf-collapsed');
        expand.textContent = open ? 'الملخص الكامل ▾' : 'طيّ ▴';
      });
    }
  }

  // ---------- Stats ----------
  var statsOpen = false;
  function loadStats(){
    elStatsCard.innerHTML = '<div class="pf-loading"><div class="pf-spinner"></div>جارٍ تحميل الإحصاءات…</div>';
    var p = state.platform, r = state.range;
    api('stats?platform='+p+'&range='+r).then(function(res){
      if(state.platform!==p || state.range!==r) return;
      if(!res || !res.ok || !res.data){ elStatsCard.innerHTML='<div class="pf-error"><span class="ic">⚠️</span>تعذّر تحميل الإحصاءات.</div>'; return; }
      renderStats(res.data);
    });
  }

  function renderStats(d){
    var ranges = [['24h','٢٤ ساعة'],['7d','٧ أيام'],['30d','٣٠ يوم']];
    var h = '<div class="pf-ranges">';
    ranges.forEach(function(rr){ h += '<button class="pf-range'+(state.range===rr[0]?' active':'')+'" data-r="'+rr[0]+'">'+rr[1]+'</button>'; });
    h += '</div>';

    if(!d.total){ elStatsCard.innerHTML = h + '<div class="pf-empty"><span class="ic">📊</span>لا توجد بيانات في هذه الفترة.</div>'; bindRanges(); return; }

    h += '<div class="pf-kpis">';
    h += '<div class="pf-kpi"><b>'+ar(d.total)+'</b><span>إجمالي المنشورات</span></div>';
    h += '<div class="pf-kpi"><b>'+ar(d.active_sources)+'</b><span>مصادر نشطة</span></div>';
    h += '<div class="pf-kpi"><b>'+ar(Math.round((d.palestine_share||0)*100))+'٪</b><span>محتوى فلسطيني</span></div>';
    h += '<div class="pf-kpi"><b>'+ar(esc(d.peak||'—'))+'</b><span>ذروة النشاط</span></div>';
    h += '</div>';

    if(d.timeline && d.timeline.length){
      var max = d.timeline.reduce(function(m,b){ return b.count>m?b.count:m; },1);
      var step = Math.ceil(d.timeline.length/8);
      h += '<div class="pf-sec-h">النشاط عبر الفترة</div>';
      h += '<div class="pf-bars">';
      d.timeline.forEach(function(b,i){
        var pct = Math.round((b.count/max)*100);
        h += '<div class="pf-bar-col"><div class="pf-bar" style="height:'+pct+'%" title="'+esc(ar(b.label))+': '+ar(b.count)+'"></div>'
           + '<span class="pf-bar-lbl">'+((i%step===0)?esc(ar(b.label)):'')+'</span></div>';
      });
      h += '</div>';
    }

    if(d.top_sources && d.top_sources.length){
      var smax = d.top_sources[0].count || 1;
      h += '<div class="pf-sec-h">أكثر المصادر نشاطاً</div>';
      d.top_sources.forEach(function(s){
        var w = Math.max(5, Math.round((s.count/smax)*100));
        h += '<div class="pf-srow"><div class="top"><span>'+esc(s.name)+'</span><span>'+ar(s.count)+'</span></div><div class="track"><div class="fill" style="width:'+w+'%"></div></div></div>';
      });
    }

    if(d.top_topics && d.top_topics.length){
      h += '<div class="pf-sec-h">أكثر المواضيع تداولاً</div><div class="pf-topics">';
      d.top_topics.forEach(function(t){ h += '<span class="pf-topic">#'+esc(t.tag)+' · '+ar(t.count)+'</span>'; });
      h += '</div>';
    }

    elStatsCard.innerHTML = h;
    bindRanges();
  }

  function bindRanges(){
    elStatsCard.querySelectorAll('.pf-range').forEach(function(btn){
      btn.addEventListener('click', function(){ state.range = btn.getAttribute('data-r'); loadStats(); });
    });
  }

  elStatsToggle.addEventListener('click', function(){
    statsOpen = !statsOpen;
    elStatsCard.classList.toggle('pf-collapsed', !statsOpen);
    elStatsToggle.querySelector('span').textContent = statsOpen ? 'إخفاء الإحصاءات' : 'عرض الإحصاءات';
    if(statsOpen) loadStats();
  });

  // ---------- Feed ----------
  function feedQuery(p, limit){ return p + '?limit=' + (limit||40) + ((state.dedup && p!=='youtube') ? '&dedup=1' : ''); }
  function cardFor(p, m){ return p==='youtube' ? videoCard(m) : msgCard(m); }

  function loadFeed(){
    elFeed.innerHTML = '<div class="pf-loading"><div class="pf-spinner"></div>جارٍ التحميل…</div>';
    state.seen = null;                       // pause live polling until the base list is in
    var p = state.platform;
    api(feedQuery(p, 40)).then(function(res){
      if(state.platform!==p) return;
      if(!res || !res.ok || !res.data || !res.data.length){
        elFeed.innerHTML = '<div class="pf-empty"><span class="ic">📭</span><span class="ti">لا توجد منشورات بعد</span>سيظهر هنا أحدث ما يُنشر على المنصة فور وصوله.</div>';
        state.seen = {};
        return;
      }
      elFeed.innerHTML = res.data.map(function(m){ return cardFor(p, m); }).join('');
      state.seen = {};
      res.data.forEach(function(m){ state.seen[m.id] = 1; });
    });
  }

  // ---------- Live updates: prepend newer posts without a manual refresh ----------
  function pollFeed(){
    if(document.hidden || !state.seen) return;            // skip when tab hidden or list not ready
    var p = state.platform;
    api(feedQuery(p, 20)).then(function(res){
      if(state.platform!==p || !state.seen) return;        // platform switched mid-flight
      if(!res || !res.ok || !res.data || !res.data.length) return;
      var fresh = res.data.filter(function(m){ return !state.seen[m.id]; });
      if(!fresh.length) return;
      fresh.forEach(function(m){ state.seen[m.id] = 1; });
      var emptyEl = elFeed.querySelector('.pf-empty');
      if(emptyEl) elFeed.innerHTML = '';
      elFeed.insertAdjacentHTML('afterbegin', fresh.map(function(m){ return cardFor(p, m); }).join(''));
      for(var k=0; k<fresh.length; k++){ var n = elFeed.children[k]; if(n && n.classList) n.classList.add('pf-new'); }
    });
  }

  function msgCard(m){
    var s = m.source || {};
    var h = '<a class="pf-msg" href="'+esc(m.post_url||'#')+'" target="_blank" rel="noopener">';
    h += '<div class="pf-msg-head"><div class="pf-idg"><div class="pf-av">'+avatarHTML(s)+'</div>'
       + '<div class="pf-who"><span class="nm">'+esc(s.display_name||'')+'</span>'
       + (s.username ? '<span class="un">@'+esc(s.username)+'</span>' : '')
       + '</div></div><span class="tm">'+esc(timeAgo(m.posted_at))+'</span></div>';
    if(m.text) h += '<div class="pf-msg-text">'+esc(m.text)+'</div>';
    if(m.image_url) h += '<div class="pf-msg-img"><img src="'+esc(m.image_url)+'" alt="" loading="lazy"></div>';
    if(m.duplicate_count && m.duplicate_count>0){
      var also = (m.also_reported_by||[]).join('، ');
      h += '<span class="pf-dup" title="'+esc(also)+'">📡 ورد من '+ar(m.duplicate_count+1)+' مصادر</span>';
    }
    h += '</a>';
    return h;
  }

  function videoCard(v){
    var s = v.source || {};
    var h = '<a class="pf-msg" href="'+esc(v.video_url||'#')+'" target="_blank" rel="noopener">';
    if(v.thumbnail_url) h += '<div class="pf-yt-thumb"><img src="'+esc(v.thumbnail_url)+'" alt="" loading="lazy"><span class="pf-yt-play">▶</span></div>';
    h += '<div class="pf-msg-head"><div class="pf-idg"><div class="pf-av">'+avatarHTML(s)+'</div>'
       + '<div class="pf-who"><span class="nm">'+esc(s.display_name||'')+'</span></div></div>'
       + '<span class="tm">'+esc(timeAgo(v.posted_at))+'</span></div>';
    h += '<div class="pf-msg-text">'+esc(v.title||'')+'</div></a>';
    return h;
  }

  // ---------- Tabs ----------
  elTabs.querySelectorAll('.pf-tab').forEach(function(tab){
    tab.addEventListener('click', function(){
      if(tab.classList.contains('active')) return;
      elTabs.querySelectorAll('.pf-tab').forEach(function(t){ t.classList.remove('active'); });
      tab.classList.add('active');
      state.platform = tab.getAttribute('data-p');
      setAccent();
      loadSummary();
      loadFeed();
      if(statsOpen) loadStats();
    });
  });

  elDedup.addEventListener('change', function(){ state.dedup = elDedup.checked; loadFeed(); });

  // ---------- Responsive: stats become a persistent sidebar on desktop ----------
  var mqDesktop = window.matchMedia('(min-width:1024px)');
  function syncStatsLayout(){
    if(mqDesktop.matches){
      elStatsCard.classList.remove('pf-collapsed');
      if(!statsOpen){ statsOpen = true; loadStats(); }
    }
  }
  if(mqDesktop.addEventListener) mqDesktop.addEventListener('change', syncStatsLayout);
  else if(mqDesktop.addListener) mqDesktop.addListener(syncStatsLayout);

  // ---------- Init ----------
  setAccent();
  loadSummary();
  loadFeed();
  syncStatsLayout();
  setInterval(pollFeed, 20000);   // near-real-time feed (every 20s, paused when tab hidden)
})();
</script>

</body>
</html>
