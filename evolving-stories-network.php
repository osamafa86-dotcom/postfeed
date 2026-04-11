<?php
/**
 * نيوزفلو — شبكة القصص المتطوّرة (Story Network)
 *
 * Interactive force-directed graph showing how admin-curated
 * persistent stories overlap with each other. Each node is a story
 * sized by its article count; each edge connects two stories that
 * share articles (e.g. a bombing in Gaza that also appears in the
 * "الأسرى" story), thickness = number of shared articles.
 *
 * Pulls its data from evolving_stories_network_graph() — one SQL
 * aggregate over evolving_story_articles, so it's cheap even at
 * scale. D3.js v7 is loaded from a CDN (no build step, no bundler).
 *
 * URL: /evolving-stories/network  (rewritten by .htaccess)
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';
require_once __DIR__ . '/includes/evolving_stories.php';
require_once __DIR__ . '/includes/cache.php';

$viewer     = current_user();
$viewerId   = $viewer ? (int)$viewer['id'] : 0;
$pageTheme  = current_theme();
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;

// Cached 5 minutes — the graph is a cross-story aggregate that would
// be wasteful to recompute on every page view. The cron rss insert
// path updates article links frequently, so a short TTL keeps things
// live enough without hammering the DB.
$graph = cache_remember('evolving_stories_network_v1', 300, function() {
    return evolving_stories_network_graph(2);
});

$nodeCount = count($graph['nodes'] ?? []);
$linkCount = count($graph['links'] ?? []);

$metaDesc = 'شبكة القصص المتطوّرة على نيوزفلو — اكتشف كيف تتقاطع القضايا المركزية عبر التقارير المشتركة في خريطة تفاعلية.';
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title>🕸️ شبكة القصص — نيوزفلو</title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<meta property="og:title" content="شبكة القصص المتطوّرة — نيوزفلو">
<meta property="og:description" content="<?php echo e($metaDesc); ?>">
<meta property="og:type" content="website">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"></noscript>
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<link rel="stylesheet" href="assets/css/home.min.css?v=m4">
<link rel="stylesheet" href="assets/css/user.min.css?v=m1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<style>
  :root {
    --bg:#faf6ec; --bg2:#fdfaf2; --card:#fff; --border:#e0e3e8;
    --accent2:#0d9488; --text:#1a1a2e; --muted:#6b7280;
  }
  body { background:var(--bg); font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; color:var(--text); }
  .net-container { max-width:1200px; margin:0 auto; padding:0 24px; }

  .net-hero {
    background:linear-gradient(135deg,#1a1a2e 0%, #0f172a 100%);
    border-radius:20px; padding:30px 30px; margin:28px 0 20px;
    color:#fff; position:relative; overflow:hidden;
    box-shadow:0 12px 40px -18px rgba(0,0,0,.4);
  }
  .net-hero::before {
    content:''; position:absolute; inset:0;
    background:
      radial-gradient(circle at 12% 20%, rgba(13,148,136,.28), transparent 55%),
      radial-gradient(circle at 88% 80%, rgba(245,158,11,.20), transparent 55%);
    pointer-events:none;
  }
  .net-eyebrow {
    display:inline-flex; align-items:center; gap:8px;
    background:rgba(13,148,136,.20); color:#5eead4;
    border:1px solid rgba(13,148,136,.45); padding:6px 14px;
    border-radius:999px; font-size:12px; font-weight:800;
    margin-bottom:14px; position:relative;
  }
  .net-title { font-size:30px; font-weight:900; line-height:1.4; margin-bottom:10px; position:relative; }
  .net-lede { font-size:14.5px; line-height:1.85; color:#cbd5e1; max-width:780px; position:relative; }
  .net-stats {
    display:flex; flex-wrap:wrap; gap:18px; margin-top:18px;
    font-size:13px; color:#94a3b8; font-weight:600; position:relative;
  }
  .net-stats b { color:#fff; font-weight:900; }

  .net-stage-wrap {
    background:var(--card); border:1px solid var(--border);
    border-radius:20px; padding:14px; margin-bottom:20px;
    box-shadow:0 6px 24px -14px rgba(0,0,0,.14);
    position:relative; overflow:hidden;
  }
  .net-stage { width:100%; height:620px; display:block; cursor:grab; }
  .net-stage:active { cursor:grabbing; }
  .net-stage text {
    font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif;
    font-weight:800; pointer-events:none;
    text-shadow:0 2px 6px rgba(0,0,0,.35);
  }
  .net-stage .node-ring {
    stroke:#fff; stroke-width:3px; cursor:pointer;
    transition:filter .2s ease, stroke-width .2s ease;
  }
  .net-stage .node-ring:hover {
    filter:brightness(1.12) drop-shadow(0 6px 18px rgba(0,0,0,.22));
    stroke-width:5px;
  }
  .net-stage .link {
    stroke:#94a3b8; stroke-opacity:.45; fill:none;
    transition:stroke .2s ease, stroke-opacity .2s ease;
  }
  .net-stage .link.highlight { stroke:#0d9488; stroke-opacity:.95; }
  .net-stage .link.dim       { stroke-opacity:.08; }
  .net-stage .node-group.dim .node-ring { opacity:.25; }
  .net-stage .node-group.dim text        { opacity:.25; }

  .net-legend {
    display:flex; flex-wrap:wrap; gap:12px; align-items:center;
    padding:12px 16px; background:var(--bg2); border:1px dashed var(--border);
    border-radius:14px; margin-bottom:12px;
    font-size:12.5px; color:var(--muted); font-weight:700;
  }
  .net-legend .chip {
    display:inline-flex; align-items:center; gap:6px;
    padding:4px 10px; background:#fff; border:1px solid var(--border);
    border-radius:999px;
  }
  .net-legend .chip .dot { width:10px; height:10px; border-radius:50%; }

  .net-tip {
    position:absolute; pointer-events:none;
    background:#1a1a2e; color:#fff; padding:10px 14px;
    border-radius:10px; font-size:12.5px; font-weight:700;
    box-shadow:0 12px 28px -14px rgba(0,0,0,.55);
    opacity:0; transform:translate(-50%, -100%) translateY(-10px);
    transition:opacity .15s ease; z-index:10;
    max-width:260px; line-height:1.6;
  }
  .net-tip.show { opacity:1; }
  .net-tip b { color:#5eead4; }

  .net-empty {
    background:#fff; border:1px solid var(--border); border-radius:16px;
    padding:56px 24px; text-align:center; margin:24px 0 56px;
  }
  .net-empty .icon { font-size:52px; margin-bottom:14px; }
  .net-empty h3 { font-size:19px; margin-bottom:10px; }
  .net-empty p { color:var(--muted); font-size:14px; line-height:1.8; max-width:520px; margin:0 auto; }

  .net-back {
    display:inline-flex; align-items:center; gap:6px;
    color:var(--accent2); font-weight:800; font-size:13px;
    margin-bottom:8px;
  }

  @media(max-width:760px) {
    .net-title { font-size:22px; }
    .net-hero  { padding:22px 20px; }
    .net-stage { height:480px; }
  }
</style>
</head>
<body>

<?php
$activeType = 'evolving';
$activeSlug = '';
$showTicker = false;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="net-container">

  <a class="net-back" href="/evolving-stories">← كل القصص المتطوّرة</a>

  <div class="net-hero">
    <span class="net-eyebrow">🕸️ استكشاف بصري</span>
    <h1 class="net-title">شبكة القصص المتطوّرة</h1>
    <p class="net-lede">
      كل دائرة تمثّل قصة متواصلة المتابعة على نيوزفلو، وكل خط يربط قصتين يتقاطعان في
      تقارير مشتركة — كلّما سُمك الخط، كلّما كان التقاطع أكبر. اسحب الدوائر لتحريكها،
      مرّر عليها لإبراز جيرانها، واضغط لفتح صفحة القصة.
    </p>
    <div class="net-stats">
      <span>📅 <b><?php echo number_format($nodeCount); ?></b> قصة</span>
      <span>🔗 <b><?php echo number_format($linkCount); ?></b> رابط تقاطع</span>
      <span>⚙️ تحديث تلقائي</span>
    </div>
  </div>

  <?php if ($nodeCount === 0): ?>
    <div class="net-empty">
      <div class="icon">📭</div>
      <h3>لا توجد قصص بعد لرسم الشبكة</h3>
      <p>أضف قصتين أو أكثر من لوحة الإدارة ليظهر الرسم البياني.</p>
    </div>
  <?php else: ?>

    <div class="net-legend">
      <span>🔍 اقرأ هكذا:</span>
      <span class="chip"><span class="dot" style="background:#0d9488;"></span> حجم الدائرة ∝ عدد التقارير</span>
      <span class="chip">خط سميك = تقاطع أكبر</span>
      <span class="chip">اسحب لإعادة الترتيب</span>
      <span class="chip">اضغط دائرة لفتح القصة</span>
    </div>

    <div class="net-stage-wrap">
      <svg class="net-stage" id="netStage"></svg>
      <div class="net-tip" id="netTip"></div>
    </div>

  <?php endif; ?>

</div>

<?php if ($nodeCount > 0): ?>
<script src="https://d3js.org/d3.v7.min.js"></script>
<script>
(function() {
  const GRAPH = <?php echo json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const svgEl = document.getElementById('netStage');
  const tip   = document.getElementById('netTip');
  if (!svgEl || !GRAPH.nodes || !GRAPH.nodes.length) return;

  // d3 mutates the links array to replace string ids with node refs,
  // so we clone to keep the source payload clean in case we need it.
  const nodes = GRAPH.nodes.map(n => ({...n}));
  const links = GRAPH.links.map(l => ({...l}));

  const width  = svgEl.clientWidth;
  const height = svgEl.clientHeight;
  const svg = d3.select(svgEl)
    .attr('viewBox', [0, 0, width, height])
    .attr('preserveAspectRatio', 'xMidYMid meet');

  // Defensive scaling — the story with the most articles becomes
  // the biggest circle, the one with the fewest becomes the smallest.
  // Both floors and ceilings are hard-coded so a single huge story
  // doesn't crush the others visually.
  const counts = nodes.map(n => n.count || 0);
  const maxCount = Math.max(1, ...counts);
  const minCount = Math.min(...counts, 0);
  const rScale = d3.scaleSqrt()
    .domain([Math.max(1, minCount), Math.max(2, maxCount)])
    .range([22, 58]);
  nodes.forEach(n => { n.r = rScale(Math.max(1, n.count || 1)); });

  // Link thickness scales with the shared-article count, capped
  // at 8px so dense overlaps don't drown out the nodes.
  const maxLink = Math.max(1, ...links.map(l => l.value || 0));
  const wScale = d3.scaleSqrt().domain([1, maxLink]).range([1.2, 8]);

  const sim = d3.forceSimulation(nodes)
    .force('link', d3.forceLink(links).id(d => d.id).distance(d => 140 + wScale(d.value) * 4).strength(.55))
    .force('charge', d3.forceManyBody().strength(-420))
    .force('center', d3.forceCenter(width / 2, height / 2))
    .force('collide', d3.forceCollide(d => d.r + 8))
    .alphaDecay(0.035);

  const link = svg.append('g')
    .attr('stroke-linecap', 'round')
    .selectAll('line')
    .data(links)
    .join('line')
    .attr('class', 'link')
    .attr('stroke-width', d => wScale(d.value));

  const node = svg.append('g')
    .selectAll('g')
    .data(nodes)
    .join('g')
    .attr('class', 'node-group')
    .style('cursor', 'pointer')
    .call(d3.drag()
      .on('start', (event, d) => {
        if (!event.active) sim.alphaTarget(0.3).restart();
        d.fx = d.x; d.fy = d.y;
      })
      .on('drag', (event, d) => { d.fx = event.x; d.fy = event.y; })
      .on('end', (event, d) => {
        if (!event.active) sim.alphaTarget(0);
        d.fx = null; d.fy = null;
      })
    )
    .on('click', (event, d) => {
      if (d.slug) window.location.href = '/evolving-story/' + encodeURIComponent(d.slug);
    })
    .on('mousemove', (event, d) => {
      tip.classList.add('show');
      const rect = svgEl.getBoundingClientRect();
      tip.style.left = (event.clientX - rect.left) + 'px';
      tip.style.top  = (event.clientY - rect.top)  + 'px';
      tip.innerHTML = '<b>' + (d.icon || '📅') + ' ' + escapeHtml(d.name) + '</b><br>' +
                      (d.count || 0) + ' تقرير — اضغط للفتح';
    })
    .on('mouseover', (event, d) => highlight(d))
    .on('mouseout', () => unhighlight());

  node.append('circle')
    .attr('class', 'node-ring')
    .attr('r', d => d.r)
    .attr('fill', d => d.color || '#0d9488');

  node.append('text')
    .attr('text-anchor', 'middle')
    .attr('dy', '.15em')
    .attr('font-size', d => Math.min(22, d.r * 0.7))
    .attr('fill', '#fff')
    .text(d => d.icon || '📅');

  node.append('text')
    .attr('text-anchor', 'middle')
    .attr('dy', d => d.r + 18)
    .attr('font-size', 13)
    .attr('fill', '#1a1a2e')
    .text(d => d.name);

  sim.on('tick', () => {
    // Clamp inside the viewBox so nodes don't float off-screen when
    // the simulation shakes itself settled on a wide link.
    nodes.forEach(n => {
      n.x = Math.max(n.r + 4, Math.min(width  - n.r - 4, n.x));
      n.y = Math.max(n.r + 4, Math.min(height - n.r - 4, n.y));
    });
    link
      .attr('x1', d => d.source.x).attr('y1', d => d.source.y)
      .attr('x2', d => d.target.x).attr('y2', d => d.target.y);
    node.attr('transform', d => `translate(${d.x},${d.y})`);
  });

  function highlight(d) {
    const neighborIds = new Set([d.id]);
    links.forEach(l => {
      if (l.source.id === d.id) neighborIds.add(l.target.id);
      if (l.target.id === d.id) neighborIds.add(l.source.id);
    });
    node.classed('dim', n => !neighborIds.has(n.id));
    link.classed('highlight', l => l.source.id === d.id || l.target.id === d.id);
    link.classed('dim',       l => l.source.id !== d.id && l.target.id !== d.id);
  }
  function unhighlight() {
    node.classed('dim', false);
    link.classed('highlight', false).classed('dim', false);
    tip.classList.remove('show');
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }
})();
</script>
<?php endif; ?>

<script src="assets/js/user.min.js?v=m1" defer></script>
</body>
</html>
