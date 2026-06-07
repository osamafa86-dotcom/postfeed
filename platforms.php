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
  :root { --pf-accent:#0ea5e9; }
  .pf-wrap { max-width:920px; margin:0 auto; padding:18px 16px 60px; }
  .pf-h1 { font-size:26px; font-weight:900; margin:6px 0 4px; display:flex; align-items:center; gap:8px; }
  .pf-sub { color:var(--muted); font-size:14px; margin-bottom:18px; }

  /* Tabs */
  .pf-tabs { display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap; }
  .pf-tab {
    flex:1 1 0; min-width:96px; display:flex; align-items:center; justify-content:center; gap:8px;
    padding:11px 12px; border-radius:12px; border:1px solid var(--border);
    background:var(--card); color:var(--text); font-weight:800; font-size:14px; cursor:pointer;
    transition:all .15s;
  }
  .pf-tab.active { color:#fff; border-color:transparent; }
  .pf-tab[data-p="telegram"].active { background:#0ea5e9; }
  .pf-tab[data-p="twitter"].active  { background:#1f2937; }
  .pf-tab[data-p="youtube"].active  { background:#dc2626; }

  /* Cards */
  .pf-card { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:16px; margin-bottom:16px; }

  /* Summary */
  .pf-sum-head { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
  .pf-sum-head .ttl { font-weight:900; font-size:16px; }
  .pf-sum-head .ts { margin-inline-start:auto; color:var(--muted); font-size:12px; }
  .pf-sum-headline { font-weight:900; font-size:17px; line-height:1.5; margin:6px 0; }
  .pf-sum-text { font-size:14.5px; line-height:1.9; color:var(--text); }
  .pf-knums { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; }
  .pf-knum { background:rgba(14,165,233,.10); border-radius:10px; padding:8px 11px; min-width:90px; }
  .pf-knum b { display:block; font-size:16px; font-weight:900; color:var(--pf-accent); }
  .pf-knum span { font-size:11px; color:var(--muted); }
  .pf-sec { margin-top:16px; }
  .pf-sec h4 { font-size:15px; font-weight:800; margin:0 0 6px; }
  .pf-sec ul { margin:0; padding-inline-start:18px; }
  .pf-sec li { font-size:13.5px; line-height:1.8; margin-bottom:4px; }
  .pf-sec .why { font-size:12px; font-style:italic; color:var(--muted); margin-top:5px; }
  .pf-topics { display:flex; flex-wrap:wrap; gap:6px; margin-top:14px; }
  .pf-topic { padding:5px 11px; border-radius:20px; border:1px solid var(--border); font-size:12px; color:var(--pf-accent); font-weight:600; }
  .pf-sum-foot { display:flex; align-items:center; gap:10px; margin-top:12px; }
  .pf-sum-foot .cnt { color:var(--muted); font-size:12px; }
  .pf-btn {
    margin-inline-start:auto; background:none; border:none; color:var(--pf-accent);
    font-weight:800; font-size:13px; cursor:pointer; display:flex; align-items:center; gap:4px;
  }
  .pf-collapsed { display:none; }

  /* Stats */
  .pf-stats-toggle {
    display:inline-flex; align-items:center; gap:6px; cursor:pointer; user-select:none;
    background:var(--card); border:1px solid var(--border); border-radius:10px; padding:9px 14px;
    font-weight:800; font-size:13.5px; color:var(--text); margin-bottom:16px;
  }
  .pf-ranges { display:flex; gap:6px; margin-bottom:14px; }
  .pf-range { padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:var(--card); cursor:pointer; font-size:12.5px; font-weight:700; }
  .pf-range.active { background:var(--pf-accent); color:#fff; border-color:transparent; }
  .pf-kpis { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
  @media(min-width:560px){ .pf-kpis { grid-template-columns:repeat(4,1fr); } }
  .pf-kpi { background:var(--surface,var(--bg)); border:1px solid var(--border); border-radius:12px; padding:12px; }
  .pf-kpi b { display:block; font-size:20px; font-weight:900; }
  .pf-kpi span { font-size:11.5px; color:var(--muted); }
  .pf-bars { display:flex; align-items:flex-end; gap:3px; height:120px; margin:14px 0 4px; }
  .pf-bar-col { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; }
  .pf-bar { width:100%; background:var(--pf-accent); border-radius:3px 3px 0 0; min-height:2px; opacity:.85; }
  .pf-bar-lbl { font-size:8px; color:var(--muted); margin-top:3px; }
  .pf-srow { margin-bottom:9px; }
  .pf-srow .top { display:flex; justify-content:space-between; font-size:13px; font-weight:600; margin-bottom:4px; }
  .pf-srow .track { height:7px; background:rgba(14,165,233,.12); border-radius:4px; overflow:hidden; }
  .pf-srow .fill { height:100%; background:var(--pf-accent); border-radius:4px; }

  /* Feed */
  .pf-feed-bar { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
  .pf-feed-bar .lbl { font-weight:800; font-size:15px; }
  .pf-switch { margin-inline-start:auto; display:flex; align-items:center; gap:7px; font-size:13px; color:var(--muted); cursor:pointer; user-select:none; }
  .pf-switch input { width:38px; height:20px; }
  .pf-msg { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:13px; margin-bottom:10px; display:block; color:inherit; text-decoration:none; }
  .pf-msg-head { display:flex; align-items:center; gap:8px; margin-bottom:7px; }
  .pf-msg-head .nm { font-weight:800; font-size:14px; }
  .pf-msg-head .un { font-size:11px; color:var(--muted); }
  .pf-msg-head .tm { margin-inline-start:auto; font-size:11px; color:var(--muted); }
  .pf-msg-text { font-size:14px; line-height:1.75; }
  .pf-msg-img { margin-top:8px; border-radius:10px; overflow:hidden; max-height:280px; }
  .pf-msg-img img { width:100%; display:block; object-fit:cover; }
  .pf-dup { display:inline-flex; align-items:center; gap:5px; margin-top:9px; padding:4px 9px; border-radius:8px; background:rgba(14,165,233,.10); color:var(--pf-accent); font-size:12px; font-weight:700; }
  .pf-yt-thumb { position:relative; border-radius:10px; overflow:hidden; aspect-ratio:16/9; margin-bottom:9px; }
  .pf-yt-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
  .pf-yt-play { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:40px; color:#fff; text-shadow:0 2px 8px rgba(0,0,0,.5); }
  .pf-loading, .pf-empty, .pf-error { text-align:center; color:var(--muted); padding:26px 12px; font-size:14px; }
  .pf-spinner { width:26px; height:26px; border:3px solid var(--border); border-top-color:var(--pf-accent); border-radius:50%; animation:pfspin .8s linear infinite; margin:0 auto 10px; }
  @keyframes pfspin { to { transform:rotate(360deg); } }
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

  <!-- Daily AI summary -->
  <div class="pf-card" id="pfSummary"><div class="pf-loading"><div class="pf-spinner"></div>جارٍ تحميل الملخص…</div></div>

  <!-- Stats (lazy) -->
  <div class="pf-stats-toggle" id="pfStatsToggle">📊 <span>عرض الإحصاءات</span></div>
  <div class="pf-card pf-collapsed" id="pfStatsCard"></div>

  <!-- Feed -->
  <div class="pf-feed-bar">
    <span class="lbl">أحدث المنشورات</span>
    <label class="pf-switch"><input type="checkbox" id="pfDedup" checked> إخفاء المكرر</label>
  </div>
  <div id="pfFeed"><div class="pf-loading"><div class="pf-spinner"></div>جارٍ التحميل…</div></div>
</main>

<script>
(function(){
  'use strict';
  var API = '/api/v1/media/';
  var ACCENT = { telegram:'#0ea5e9', twitter:'#1f2937', youtube:'#dc2626' };
  var state = { platform:'telegram', range:'24h', dedup:true };

  var elTabs    = document.getElementById('pfTabs');
  var elSummary = document.getElementById('pfSummary');
  var elStatsToggle = document.getElementById('pfStatsToggle');
  var elStatsCard = document.getElementById('pfStatsCard');
  var elFeed    = document.getElementById('pfFeed');
  var elDedup   = document.getElementById('pfDedup');

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

  function timeAgo(str){
    if(!str) return '';
    var t = Date.parse(String(str).replace(' ','T'));
    if(isNaN(t)) return '';
    var s = Math.floor((Date.now()-t)/1000);
    if(s<60) return 'الآن';
    if(s<3600) return 'قبل '+Math.floor(s/60)+' د';
    if(s<86400) return 'قبل '+Math.floor(s/3600)+' س';
    return 'قبل '+Math.floor(s/86400)+' يوم';
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
        elSummary.innerHTML = '<div class="pf-empty">لا يوجد ملخص متاح بعد لهذه المنصة. يُولَّد تلقائياً يومياً.</div>';
        return;
      }
      renderSummary(res.data);
    });
  }

  function renderSummary(d){
    var h = '';
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
    if(d.message_count) h += '<span class="cnt">📡 جُمِّع من '+d.message_count+' منشور بلا تكرار</span>';
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
      if(!res || !res.ok || !res.data){ elStatsCard.innerHTML='<div class="pf-error">تعذّر تحميل الإحصاءات.</div>'; return; }
      renderStats(res.data);
    });
  }

  function renderStats(d){
    var ranges = [['24h','٢٤ ساعة'],['7d','٧ أيام'],['30d','٣٠ يوم']];
    var h = '<div class="pf-ranges">';
    ranges.forEach(function(rr){ h += '<button class="pf-range'+(state.range===rr[0]?' active':'')+'" data-r="'+rr[0]+'">'+rr[1]+'</button>'; });
    h += '</div>';

    if(!d.total){ elStatsCard.innerHTML = h + '<div class="pf-empty">لا توجد بيانات في هذه الفترة.</div>'; bindRanges(); return; }

    h += '<div class="pf-kpis">';
    h += '<div class="pf-kpi"><b>'+d.total+'</b><span>إجمالي المنشورات</span></div>';
    h += '<div class="pf-kpi"><b>'+d.active_sources+'</b><span>مصادر نشطة</span></div>';
    h += '<div class="pf-kpi"><b>'+Math.round((d.palestine_share||0)*100)+'%</b><span>محتوى فلسطيني</span></div>';
    h += '<div class="pf-kpi"><b>'+esc(d.peak||'—')+'</b><span>ذروة النشاط</span></div>';
    h += '</div>';

    if(d.timeline && d.timeline.length){
      var max = d.timeline.reduce(function(m,b){ return b.count>m?b.count:m; },1);
      var step = Math.ceil(d.timeline.length/8);
      h += '<div class="pf-bars">';
      d.timeline.forEach(function(b,i){
        var pct = Math.round((b.count/max)*100);
        h += '<div class="pf-bar-col"><div class="pf-bar" style="height:'+pct+'%" title="'+esc(b.label)+': '+b.count+'"></div>'
           + '<span class="pf-bar-lbl">'+((i%step===0)?esc(b.label):'')+'</span></div>';
      });
      h += '</div>';
    }

    if(d.top_sources && d.top_sources.length){
      var smax = d.top_sources[0].count || 1;
      h += '<div style="margin-top:16px;font-weight:800;font-size:14px;margin-bottom:8px">أكثر المصادر نشاطاً</div>';
      d.top_sources.forEach(function(s){
        var w = Math.max(5, Math.round((s.count/smax)*100));
        h += '<div class="pf-srow"><div class="top"><span>'+esc(s.name)+'</span><span>'+s.count+'</span></div><div class="track"><div class="fill" style="width:'+w+'%"></div></div></div>';
      });
    }

    if(d.top_topics && d.top_topics.length){
      h += '<div style="margin-top:16px;font-weight:800;font-size:14px;margin-bottom:8px">أكثر المواضيع تداولاً</div><div class="pf-topics">';
      d.top_topics.forEach(function(t){ h += '<span class="pf-topic">#'+esc(t.tag)+' · '+t.count+'</span>'; });
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
  function loadFeed(){
    elFeed.innerHTML = '<div class="pf-loading"><div class="pf-spinner"></div>جارٍ التحميل…</div>';
    var p = state.platform;
    var q = p + '?limit=40' + ((state.dedup && p!=='youtube') ? '&dedup=1' : '');
    api(q).then(function(res){
      if(state.platform!==p) return;
      if(!res || !res.ok || !res.data || !res.data.length){
        elFeed.innerHTML = '<div class="pf-empty">لا توجد منشورات.</div>';
        return;
      }
      elFeed.innerHTML = res.data.map(function(m){ return p==='youtube' ? videoCard(m) : msgCard(m); }).join('');
    });
  }

  function msgCard(m){
    var s = m.source || {};
    var h = '<a class="pf-msg" href="'+esc(m.post_url||'#')+'" target="_blank" rel="noopener">';
    h += '<div class="pf-msg-head"><span class="nm">'+esc(s.display_name||'')+'</span>';
    if(s.username) h += '<span class="un">@'+esc(s.username)+'</span>';
    h += '<span class="tm">'+esc(timeAgo(m.posted_at))+'</span></div>';
    if(m.text) h += '<div class="pf-msg-text">'+esc(m.text)+'</div>';
    if(m.image_url) h += '<div class="pf-msg-img"><img src="'+esc(m.image_url)+'" alt="" loading="lazy"></div>';
    if(m.duplicate_count && m.duplicate_count>0){
      var also = (m.also_reported_by||[]).join('، ');
      h += '<span class="pf-dup" title="'+esc(also)+'">📡 ورد من '+(m.duplicate_count+1)+' مصدر</span>';
    }
    h += '</a>';
    return h;
  }

  function videoCard(v){
    var s = v.source || {};
    var h = '<a class="pf-msg" href="'+esc(v.video_url||'#')+'" target="_blank" rel="noopener">';
    if(v.thumbnail_url) h += '<div class="pf-yt-thumb"><img src="'+esc(v.thumbnail_url)+'" alt="" loading="lazy"><span class="pf-yt-play">▶</span></div>';
    h += '<div class="pf-msg-head"><span class="nm">'+esc(s.display_name||'')+'</span><span class="tm">'+esc(timeAgo(v.posted_at))+'</span></div>';
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

  // ---------- Init ----------
  setAccent();
  loadSummary();
  loadFeed();
})();
</script>

</body>
</html>
