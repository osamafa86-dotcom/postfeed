<?php
/**
 * نيوزفلو - الخط الزمني للقصّة (Smart Story Timeline)
 *
 * Guardian-Live-inspired chronological view of an ongoing news story.
 * Takes a cluster_key, pulls every published article in that cluster,
 * and shows an AI-aggregated timeline of how the story unfolded.
 *
 * URL: /timeline/{cluster_key}
 *
 * Why this page exists separately from /cluster/{key}:
 *   - /cluster/ shows raw coverage, side-by-side, how each source
 *     reported it. Good for comparing framing.
 *   - /timeline/ shows a narrative — merged events in chronological
 *     order — so a reader who just landed on an ongoing story can
 *     catch up in 60 seconds instead of reading 20 near-identical
 *     articles.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';
require_once __DIR__ . '/includes/story_timeline.php';

$viewer    = current_user();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();

$rawKey = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
$key    = preg_match('/^[a-f0-9]{40}$/', $rawKey) ? $rawKey : '';

$timeline = null;
$articleCount = 0;
$sourceCount  = 0;
$error = '';

if ($key === '') {
    $error = 'رابط غير صالح.';
} else {
    try {
        $timeline = story_timeline_for($key);
    } catch (Throwable $e) {
        error_log('[timeline.php] ' . $e->getMessage());
        $error = 'تعذّر توليد الخط الزمني حالياً.';
    }
    if (!$timeline) {
        $articleCount = story_timeline_article_count($key);
        if ($articleCount < STORY_TIMELINE_MIN_ARTICLES) {
            // Not enough coverage to warrant a timeline — send the
            // reader to the side-by-side comparison page instead.
            if ($articleCount > 0) {
                header('Location: /cluster/' . $key, true, 302);
                exit;
            }
            $error = 'لا توجد تغطية كافية لبناء خط زمني لهذه القصة.';
        }
    }
}

// Fetch the underlying articles — we use them for the sidebar "full
// coverage" list so readers can drill into any specific report.
$articles = [];
if ($timeline || ($key && $articleCount >= STORY_TIMELINE_MIN_ARTICLES)) {
    $articles = story_timeline_fetch_articles($key);
    if (!$articleCount) $articleCount = count($articles);
    if (!$sourceCount) {
        $seen = [];
        foreach ($articles as $a) {
            if (!empty($a['source_id'])) $seen[$a['source_id']] = true;
        }
        $sourceCount = count($seen);
    }
}

// Build an id → article lookup so the timeline events can link back
// to the original reports Claude cited.
$articlesById = [];
foreach ($articles as $a) {
    $articlesById[(int)$a['id']] = $a;
}

$canonicalTitle = $timeline['headline'] ?? '';
if ($canonicalTitle === '') {
    foreach ($articles as $a) {
        $t = trim((string)($a['title'] ?? ''));
        if (mb_strlen($t) > mb_strlen($canonicalTitle)) $canonicalTitle = $t;
    }
}
if ($canonicalTitle === '') $canonicalTitle = 'الخط الزمني للقصّة';

$pageUrl  = SITE_URL . '/timeline/' . $key;
$metaDesc = $timeline && !empty($timeline['intro'])
    ? mb_substr(strip_tags((string)$timeline['intro']), 0, 180)
    : ('الخط الزمني الذكي للقصّة: كيف تطوّرت عبر ' . $articleCount . ' تقرير من ' . $sourceCount . ' مصدر على نيوزفلو.');
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title><?php echo e($canonicalTitle); ?> — الخط الزمني | <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<link rel="canonical" href="<?php echo e($pageUrl); ?>">
<meta property="og:type" content="article">
<meta property="og:title" content="<?php echo e($canonicalTitle); ?>">
<meta property="og:description" content="<?php echo e($metaDesc); ?>">
<meta property="og:url" content="<?php echo e($pageUrl); ?>">
<?php if (!empty($articles[0]['image_url'])): ?>
<meta property="og:image" content="<?php echo e($articles[0]['image_url']); ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#1a5c5c">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap"></noscript>
<style>
  :root {
    --bg:#faf6ec; --bg2:#fdfaf2; --bg3:#eef1f6;
    --card:#fff; --border:#e0e3e8;
    --accent:#1a73e8; --accent2:#0d9488; --accent3:#16a34a;
    --gold:#f59e0b; --gold2:#fcd34d; --gold-bg:#fef3c7; --gold-text:#92400e;
    --red:#dc2626; --text:#1a1a2e; --muted:#6b7280; --muted2:#9ca3af;
    --line:#d7dce4;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; background:var(--bg); color:var(--text); line-height:1.6; }
  a { text-decoration:none; color:inherit; }
  .container { max-width:1200px; margin:0 auto; padding:0 24px; }

  /* ============ HERO ============ */
  .tl-hero {
    background:linear-gradient(135deg,#fff 0%, #f0fdfa 100%);
    border:1px solid rgba(13,148,136,.25); border-radius:18px;
    padding:32px 28px; margin:28px 0 24px;
    box-shadow:0 4px 24px -10px rgba(13,148,136,.16);
    position:relative; overflow:hidden;
  }
  .tl-hero::before {
    content:''; position:absolute; inset:0;
    background:radial-gradient(circle at 90% 10%, rgba(245,158,11,.08), transparent 60%);
    pointer-events:none;
  }
  .tl-eyebrow {
    display:inline-flex; align-items:center; gap:8px;
    background:rgba(13,148,136,.08); color:var(--accent2);
    border:1px solid rgba(13,148,136,.22); padding:6px 14px;
    border-radius:999px; font-size:12px; font-weight:800;
    margin-bottom:14px; position:relative;
  }
  .tl-eyebrow .live-dot {
    width:8px; height:8px; border-radius:50%; background:#ef4444;
    box-shadow:0 0 0 0 rgba(239,68,68,.5);
    animation:tl-pulse 2s infinite;
  }
  @keyframes tl-pulse {
    0% { box-shadow:0 0 0 0 rgba(239,68,68,.5); }
    70% { box-shadow:0 0 0 10px rgba(239,68,68,0); }
    100% { box-shadow:0 0 0 0 rgba(239,68,68,0); }
  }
  .tl-title {
    font-size:30px; font-weight:900; line-height:1.4;
    color:var(--text); margin-bottom:16px; position:relative;
  }
  .tl-intro {
    font-size:15px; line-height:1.85; color:#3a3a52;
    max-width:800px; position:relative;
  }
  .tl-meta {
    display:flex; flex-wrap:wrap; gap:18px; margin-top:20px;
    font-size:13px; color:var(--muted); font-weight:600; position:relative;
  }
  .tl-meta b { color:var(--text); font-weight:800; }
  .tl-topics {
    display:flex; flex-wrap:wrap; gap:8px; margin-top:16px;
    position:relative;
  }
  .tl-topics a {
    background:#fff; border:1px solid var(--border);
    padding:5px 12px; border-radius:999px;
    font-size:12px; font-weight:700; color:var(--accent2);
    transition:all .2s;
  }
  .tl-topics a:hover {
    background:var(--accent2); color:#fff; border-color:var(--accent2);
  }

  /* ============ LAYOUT ============ */
  .tl-layout {
    display:grid; grid-template-columns:1fr 340px; gap:28px;
    margin-bottom:56px;
  }
  @media(max-width:900px) {
    .tl-layout { grid-template-columns:1fr; }
  }

  /* ============ SEARCH BAR ============ */
  .tl-toolbar {
    display:flex; gap:12px; align-items:center;
    background:var(--card); border:1px solid var(--border);
    padding:10px 14px; border-radius:12px;
    margin-bottom:18px;
  }
  .tl-search-input {
    flex:1; border:none; outline:none; font:inherit;
    background:transparent; color:var(--text);
    font-size:14px; padding:6px 0;
  }
  .tl-search-input::placeholder { color:var(--muted2); }
  .tl-search-clear {
    background:transparent; border:none; cursor:pointer;
    color:var(--muted); font-size:18px; padding:4px 8px;
    display:none;
  }
  .tl-search-clear:hover { color:var(--red); }
  .tl-result-count {
    font-size:12px; color:var(--muted); font-weight:600; white-space:nowrap;
  }

  /* ============ TIMELINE RAIL ============ */
  .tl-rail { position:relative; padding-right:40px; }
  .tl-rail::before {
    content:''; position:absolute; top:12px; bottom:12px; right:18px;
    width:3px; background:linear-gradient(180deg, var(--accent2), var(--gold));
    border-radius:3px;
  }

  .tl-event {
    position:relative; margin-bottom:28px;
    background:var(--card); border:1px solid var(--border);
    border-radius:14px; padding:20px 22px;
    box-shadow:0 1px 3px rgba(0,0,0,.04);
    transition:all .25s ease;
  }
  .tl-event:hover {
    transform:translateX(-3px);
    box-shadow:0 12px 32px -14px rgba(15,23,42,.14);
    border-color:rgba(13,148,136,.3);
  }
  .tl-event::before {
    content:''; position:absolute; top:26px; right:-32px;
    width:18px; height:18px; border-radius:50%;
    background:var(--card); border:4px solid var(--accent2);
    z-index:1; box-shadow:0 0 0 4px var(--bg);
  }
  .tl-event.first::before { border-color:var(--gold); }
  .tl-event.last::before {
    border-color:var(--red); background:var(--red);
    box-shadow:0 0 0 4px var(--bg), 0 0 0 0 rgba(239,68,68,.4);
    animation:tl-pulse 2s infinite;
  }

  .tl-event-head {
    display:flex; align-items:center; gap:10px;
    margin-bottom:10px; flex-wrap:wrap;
  }
  .tl-event-date {
    display:inline-flex; align-items:center; gap:6px;
    background:var(--gold-bg); color:var(--gold-text);
    padding:4px 12px; border-radius:999px;
    font-size:12px; font-weight:800;
    border:1px solid var(--gold2);
  }
  .tl-event-icon {
    font-size:22px; line-height:1;
  }
  .tl-event-title {
    font-size:18px; font-weight:800; line-height:1.5;
    color:var(--text); margin-bottom:10px;
  }
  .tl-event-summary {
    font-size:14px; line-height:1.9; color:#3a3a52;
    margin-bottom:14px;
  }
  .tl-event-sources {
    display:flex; flex-wrap:wrap; gap:8px;
    padding-top:12px; border-top:1px dashed var(--border);
  }
  .tl-event-sources .label {
    font-size:11px; color:var(--muted); font-weight:700;
    align-self:center; margin-left:4px;
  }
  .tl-src-chip {
    display:inline-flex; align-items:center; gap:6px;
    background:var(--bg2); border:1px solid var(--border);
    padding:5px 11px; border-radius:8px;
    font-size:11px; font-weight:700; color:var(--text);
    transition:all .2s;
    max-width:240px;
  }
  .tl-src-chip:hover {
    background:var(--accent2); color:#fff; border-color:var(--accent2);
  }
  .tl-src-chip .src-dot {
    width:16px; height:16px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:800; font-size:9px;
    flex-shrink:0;
  }
  .tl-src-chip .src-name {
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }

  .tl-event.hidden { display:none; }
  .tl-event.highlight {
    background:linear-gradient(135deg, #fff 0%, #fef3c7 100%);
    border-color:var(--gold);
  }

  /* ============ SIDEBAR (full coverage) ============ */
  .tl-side-card {
    background:var(--card); border:1px solid var(--border);
    border-radius:14px; padding:18px 20px;
    margin-bottom:18px; position:sticky; top:88px;
  }
  .tl-side-card h3 {
    font-size:14px; font-weight:800; color:var(--text);
    margin-bottom:14px; padding-bottom:10px;
    border-bottom:1px solid var(--border);
    display:flex; align-items:center; gap:8px;
  }
  .tl-side-list {
    display:flex; flex-direction:column; gap:12px;
    max-height:560px; overflow-y:auto;
    padding-left:4px;
  }
  .tl-side-list::-webkit-scrollbar { width:6px; }
  .tl-side-list::-webkit-scrollbar-thumb { background:var(--border); border-radius:3px; }
  .tl-side-item {
    padding:10px; border:1px solid var(--border);
    border-radius:10px; transition:all .2s;
    display:block;
  }
  .tl-side-item:hover {
    background:var(--bg2); border-color:var(--accent2);
  }
  .tl-side-item .when {
    font-size:10px; color:var(--muted);
    font-weight:700; margin-bottom:4px;
    display:flex; gap:6px; align-items:center;
  }
  .tl-side-item .src-dot {
    width:6px; height:6px; border-radius:50%;
    background:var(--accent2);
  }
  .tl-side-item .t {
    font-size:12px; font-weight:700; color:var(--text);
    line-height:1.55;
    display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;
  }

  .tl-side-card .alt-links {
    margin-top:12px; padding-top:12px; border-top:1px dashed var(--border);
    display:flex; flex-direction:column; gap:8px;
  }
  .tl-side-card .alt-links a {
    font-size:12px; color:var(--accent2); font-weight:700;
    padding:8px 10px; border-radius:8px;
    background:var(--bg2); border:1px solid var(--border);
    text-align:center; transition:all .2s;
  }
  .tl-side-card .alt-links a:hover {
    background:var(--accent2); color:#fff; border-color:var(--accent2);
  }

  /* ============ EMPTY STATE ============ */
  .empty-state { text-align:center; padding:80px 20px; color:var(--muted); }
  .empty-state .icon { font-size:56px; margin-bottom:18px; }
  .empty-state h3 { font-size:20px; margin-bottom:8px; color:var(--text); font-weight:800; }
  .empty-state a { color:var(--accent2); font-weight:700; }

  @media(max-width:760px) {
    .tl-title { font-size:22px; }
    .tl-hero { padding:22px 18px; }
    .tl-rail { padding-right:30px; }
    .tl-rail::before { right:12px; }
    .tl-event::before { right:-26px; width:14px; height:14px; border-width:3px; }
    .tl-event { padding:16px 18px; }
    .tl-event-title { font-size:16px; }
    .tl-side-card { position:static; }
  }
</style>
<link rel="stylesheet" href="assets/css/site-header.css?v=1">
<link rel="stylesheet" href="assets/css/user.css?v=17">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
</head>
<body>

<?php
$activeType = 'timeline';
$activeSlug = '';
$showTicker = false;
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="container">

  <?php if ($error !== '' || !$timeline): ?>
    <div class="empty-state" style="margin-top:40px">
      <div class="icon">📅</div>
      <h3><?php echo $error ? e($error) : 'لا يوجد خط زمني لهذه القصة'; ?></h3>
      <p>قد تكون القصة لا تزال في بدايتها ولم تُجمع لها تقارير كافية بعد.<br>
        عُد إلى <a href="/">الصفحة الرئيسية</a> لتصفّح آخر الأخبار.</p>
    </div>
  <?php else: ?>

    <!-- HERO -->
    <div class="tl-hero">
      <span class="tl-eyebrow"><span class="live-dot"></span> خط زمني ذكي — قصة متطوّرة</span>
      <h1 class="tl-title"><?php echo e($timeline['headline'] ?: $canonicalTitle); ?></h1>
      <?php if (!empty($timeline['intro'])): ?>
        <p class="tl-intro"><?php echo e($timeline['intro']); ?></p>
      <?php endif; ?>
      <div class="tl-meta">
        <span>📅 <b><?php echo (int)count($timeline['events']); ?></b> حدث</span>
        <span>🗞 <b><?php echo number_format($articleCount); ?></b> تقرير</span>
        <span>🌐 <b><?php echo number_format($sourceCount); ?></b> مصدر إخباري</span>
        <?php if (!empty($timeline['generated_at'])): ?>
          <span>🤖 حُدّث <b><?php echo e(timeAgo($timeline['generated_at'])); ?></b></span>
        <?php endif; ?>
      </div>
      <?php if (!empty($timeline['topics'])): ?>
        <div class="tl-topics">
          <?php foreach ($timeline['topics'] as $topic): ?>
            <a href="/topic/<?php echo e(rawurlencode((string)$topic)); ?>">#<?php echo e((string)$topic); ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- LAYOUT -->
    <div class="tl-layout">
      <div class="tl-main">
        <!-- SEARCH BAR -->
        <div class="tl-toolbar">
          <span style="font-size:16px;">🔍</span>
          <input type="search" id="tlSearch" class="tl-search-input"
                 placeholder="ابحث داخل الخط الزمني…" autocomplete="off">
          <button type="button" id="tlSearchClear" class="tl-search-clear" aria-label="مسح">×</button>
          <span id="tlResultCount" class="tl-result-count"></span>
        </div>

        <!-- TIMELINE RAIL -->
        <div class="tl-rail" id="tlRail">
          <?php
            $totalEvents = count($timeline['events']);
            foreach ($timeline['events'] as $idx => $event):
              $klass = 'tl-event';
              if ($idx === 0) $klass .= ' first';
              if ($idx === $totalEvents - 1) $klass .= ' last';
              $dateLabel = '';
              if (!empty($event['date'])) {
                  $ts = strtotime((string)$event['date']);
                  if ($ts) $dateLabel = date('Y/m/d', $ts);
              }
          ?>
            <article class="<?php echo $klass; ?>" data-idx="<?php echo $idx + 1; ?>">
              <div class="tl-event-head">
                <?php if ($dateLabel !== ''): ?>
                  <span class="tl-event-date">📅 <?php echo e($dateLabel); ?></span>
                <?php endif; ?>
                <?php if (!empty($event['icon'])): ?>
                  <span class="tl-event-icon"><?php echo e($event['icon']); ?></span>
                <?php endif; ?>
              </div>
              <h2 class="tl-event-title"><?php echo e($event['title']); ?></h2>
              <p class="tl-event-summary"><?php echo e($event['summary']); ?></p>
              <?php if (!empty($event['source_ids'])): ?>
                <div class="tl-event-sources">
                  <span class="label">📰 المصادر:</span>
                  <?php foreach ($event['source_ids'] as $sid):
                      $src = $articlesById[(int)$sid] ?? null;
                      if (!$src) continue;
                      $initial = mb_substr((string)($src['source_name'] ?? '?'), 0, 1);
                      $color   = $src['logo_color'] ?? '#0d9488';
                  ?>
                    <a class="tl-src-chip" href="<?php echo articleUrl($src); ?>">
                      <span class="src-dot" style="background:<?php echo e($color); ?>"><?php echo e($initial); ?></span>
                      <span class="src-name"><?php echo e(mb_substr((string)($src['source_name'] ?? '—'), 0, 20)); ?></span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- SIDEBAR -->
      <aside class="tl-sidebar">
        <div class="tl-side-card">
          <h3>📰 كل التقارير (<?php echo number_format(count($articles)); ?>)</h3>
          <div class="tl-side-list">
            <?php foreach ($articles as $a): ?>
              <a class="tl-side-item" href="<?php echo articleUrl($a); ?>">
                <div class="when">
                  <span class="src-dot" style="background:<?php echo e($a['logo_color'] ?? '#0d9488'); ?>"></span>
                  <?php echo e($a['source_name'] ?? '—'); ?>
                  · <?php echo e(timeAgo($a['published_at'])); ?>
                </div>
                <div class="t"><?php echo e($a['title']); ?></div>
              </a>
            <?php endforeach; ?>
          </div>
          <div class="alt-links">
            <a href="/cluster/<?php echo e($key); ?>">📊 قارن التغطية (Side-by-side)</a>
          </div>
        </div>
      </aside>
    </div>

  <?php endif; ?>

</div>

<script>
(function() {
  // Timeline search: filters .tl-event nodes by title/summary match.
  // Runs entirely client-side — no network hit per keystroke. Also
  // highlights the matched events and dims the rest so the visual
  // flow of the rail is preserved even while filtering.
  var input  = document.getElementById('tlSearch');
  var clear  = document.getElementById('tlSearchClear');
  var count  = document.getElementById('tlResultCount');
  var rail   = document.getElementById('tlRail');
  if (!input || !rail) return;

  var events = Array.prototype.slice.call(rail.querySelectorAll('.tl-event'));
  function norm(s) {
    return (s || '')
      .toLowerCase()
      // Unify Arabic letter variants — matches the clustering logic
      // so "غزّة" and "غزة" both match "غزة".
      .replace(/[إأآا]/g, 'ا')
      .replace(/[ىي]/g, 'ي')
      .replace(/[ؤو]/g, 'و')
      .replace(/[ة]/g, 'ه')
      .replace(/[\u064B-\u0652\u0670]/g, '');
  }
  var index = events.map(function(el) {
    var title = el.querySelector('.tl-event-title');
    var summary = el.querySelector('.tl-event-summary');
    return {
      el: el,
      text: norm((title ? title.textContent : '') + ' ' + (summary ? summary.textContent : '')),
    };
  });

  function apply(q) {
    q = norm(q).trim();
    clear.style.display = q ? 'block' : 'none';
    var matched = 0;
    index.forEach(function(item) {
      if (!q) {
        item.el.classList.remove('hidden', 'highlight');
        matched++;
      } else if (item.text.indexOf(q) !== -1) {
        item.el.classList.remove('hidden');
        item.el.classList.add('highlight');
        matched++;
      } else {
        item.el.classList.add('hidden');
        item.el.classList.remove('highlight');
      }
    });
    count.textContent = q ? (matched + ' نتيجة') : '';
  }

  var debounceT = null;
  input.addEventListener('input', function() {
    clearTimeout(debounceT);
    var v = input.value;
    debounceT = setTimeout(function() { apply(v); }, 80);
  });
  clear.addEventListener('click', function() {
    input.value = '';
    apply('');
    input.focus();
  });
})();
</script>

<script src="assets/js/user.js?v=17" defer></script>
</body>
</html>
