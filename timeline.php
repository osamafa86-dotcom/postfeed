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

  /* ============ TIER 2 — STORY DYNAMICS ============
     Three things layered into the existing event card:
       1. severity chip in the head (.tl-sev-*)
       2. trajectory arrow rail dot variants (.tl-event[data-traj=...])
       3. "what's new" inline diff line (.tl-whats-new)
     The first event never carries a trajectory or whats_new, so the
     arrow + diff only appear from event 2 onward. */
  .tl-event-sev {
    display:inline-flex; align-items:center; gap:5px;
    padding:4px 11px; border-radius:999px;
    font-size:11px; font-weight:800;
    border:1px solid transparent;
    text-transform:uppercase; letter-spacing:.3px;
  }
  .tl-event-sev.tl-sev-breaking {
    background:#fef2f2; color:#991b1b; border-color:#fecaca;
    animation:tlSevPulse 2.4s ease-in-out infinite;
  }
  .tl-event-sev.tl-sev-major {
    background:#fff7ed; color:#9a3412; border-color:#fed7aa;
  }
  .tl-event-sev.tl-sev-update {
    background:#ecfeff; color:#155e75; border-color:#a5f3fc;
  }
  .tl-event-sev.tl-sev-context {
    background:#f3f4f6; color:#4b5563; border-color:#d1d5db;
  }
  @keyframes tlSevPulse {
    0%, 100% { box-shadow:0 0 0 0 rgba(220,38,38,.35); }
    50%      { box-shadow:0 0 0 6px rgba(220,38,38,0); }
  }

  /* Severity colors the rail dot too — strongest visual signal of
     where the breaking moments are when scanning the rail. */
  .tl-event[data-sev="breaking"]::before {
    border-color:var(--red); background:#fff;
    box-shadow:0 0 0 4px var(--bg), 0 0 0 8px rgba(220,38,38,.18);
  }
  .tl-event[data-sev="major"]::before { border-color:#f97316; }
  .tl-event[data-sev="context"]::before { border-color:#9ca3af; }

  /* "What's new" diff line — shows up between the title and summary.
     Visually a soft teal callout so it never competes with the title
     but is impossible to miss when skimming. */
  .tl-whats-new {
    display:flex; align-items:flex-start; gap:8px;
    margin:-4px 0 12px;
    padding:8px 12px;
    background:linear-gradient(90deg, rgba(13,148,136,.08), transparent);
    border-right:3px solid var(--accent2);
    border-radius:0 8px 8px 0;
    font-size:12.5px; font-weight:600;
    color:#155e63;
    line-height:1.55;
  }
  .tl-whats-new .tl-whats-new-icon {
    flex-shrink:0; font-size:14px; line-height:1.4;
  }

  /* Trajectory pill appears INSIDE the event head, right after the
     date chip — keeps the rail itself uncluttered while still telling
     the story arc at a glance. */
  .tl-event-traj {
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 10px; border-radius:999px;
    font-size:11px; font-weight:800;
    border:1px solid var(--border);
    background:#fff;
  }
  .tl-event-traj.tl-traj-escalation    { color:#b91c1c; border-color:#fecaca; background:#fef2f2; }
  .tl-event-traj.tl-traj-de-escalation { color:#15803d; border-color:#bbf7d0; background:#f0fdf4; }
  .tl-event-traj.tl-traj-steady        { color:#475569; border-color:#e2e8f0; background:#f8fafc; }
  .tl-event-traj.tl-traj-shift         { color:#7c3aed; border-color:#ddd6fe; background:#f5f3ff; }

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

  /* ============ PROGRESS BAR (top of viewport) ============
     Shows how far the reader has scrolled through the events rail,
     not the whole page — so it stays at 0% above the rail and 100%
     once the final event is fully in view. */
  .tl-progress-bar {
    position:fixed; top:0; left:0; right:0;
    height:3px; background:rgba(13,148,136,.08);
    z-index:1000; pointer-events:none;
  }
  .tl-progress-fill {
    height:100%; width:0%;
    background:linear-gradient(90deg, var(--accent2) 0%, var(--gold) 50%, var(--red) 100%);
    transition:width .08s linear;
    box-shadow:0 0 10px rgba(13,148,136,.5);
  }

  /* ============ STICKY DATE RAIL (left edge in RTL = start) ============
     A thin floating strip of date dots; the dot for the date currently
     in the viewport is enlarged and labeled. Hidden on mobile. */
  .tl-date-nav {
    position:fixed;
    top:50%; transform:translateY(-50%);
    left:18px;
    z-index:900;
    background:rgba(255,255,255,.92);
    backdrop-filter:blur(10px);
    border:1px solid var(--border);
    border-radius:14px;
    padding:14px 10px;
    box-shadow:0 10px 30px -12px rgba(15,23,42,.15);
    max-height:min(70vh, 540px);
    overflow-y:auto;
  }
  .tl-date-nav::-webkit-scrollbar { width:4px; }
  .tl-date-nav::-webkit-scrollbar-thumb { background:var(--border); border-radius:2px; }
  .tl-date-nav-title {
    font-size:10px; font-weight:800; color:var(--muted);
    text-align:center; margin-bottom:10px;
    text-transform:uppercase; letter-spacing:.5px;
  }
  .tl-date-nav ul {
    list-style:none; padding:0; margin:0;
    display:flex; flex-direction:column; gap:2px;
  }
  .tl-date-nav li { position:relative; }
  .tl-date-nav a {
    display:flex; align-items:center; gap:8px;
    padding:6px 8px; border-radius:8px;
    font-size:11px; font-weight:700;
    color:var(--muted); text-decoration:none;
    transition:all .2s;
    white-space:nowrap;
  }
  .tl-date-nav a::before {
    content:''; width:8px; height:8px;
    border-radius:50%; background:var(--border);
    flex-shrink:0; transition:all .2s;
  }
  .tl-date-nav a:hover {
    background:var(--bg2); color:var(--text);
  }
  .tl-date-nav a:hover::before {
    background:var(--accent2);
  }
  .tl-date-nav a.active {
    background:var(--accent2); color:#fff;
    box-shadow:0 4px 12px -4px rgba(13,148,136,.5);
  }
  .tl-date-nav a.active::before {
    background:#fff;
    box-shadow:0 0 0 3px rgba(255,255,255,.35);
  }

  @media(max-width:1100px) {
    .tl-date-nav { display:none; }
  }

  /* ============ STICKY CURRENT-DATE PILL (mobile alternative) ============ */
  .tl-current-date-pill {
    position:sticky; top:60px; z-index:50;
    display:none;
    margin:0 auto 14px;
    width:fit-content;
    padding:8px 16px;
    background:var(--accent2); color:#fff;
    font-size:12px; font-weight:800;
    border-radius:999px;
    box-shadow:0 6px 18px -6px rgba(13,148,136,.5);
  }
  @media(max-width:1100px) {
    .tl-current-date-pill { display:block; }
  }

  /* ============ KEY QUOTE BLOCK ============ */
  .tl-event-quote {
    position:relative;
    margin:14px 0 10px;
    padding:14px 18px 14px 44px;
    background:linear-gradient(135deg, rgba(245,158,11,.08) 0%, rgba(245,158,11,.02) 100%);
    border-right:4px solid var(--gold);
    border-radius:10px;
    font-style:italic;
  }
  .tl-event-quote::before {
    content:'"';
    position:absolute;
    top:4px; right:10px;
    font-size:44px;
    line-height:1;
    color:var(--gold);
    font-family:Georgia, 'Times New Roman', serif;
    font-style:normal;
    font-weight:700;
    opacity:.7;
  }
  .tl-event-quote p {
    margin:0 0 6px;
    font-size:14.5px; line-height:1.85;
    color:#3a3a52; font-weight:500;
  }
  .tl-event-quote cite {
    display:block;
    font-size:12px; font-weight:700;
    color:var(--gold-text);
    font-style:normal;
  }

  /* ============ ENTITIES PANEL ============ */
  .tl-entities-card {
    padding:0 !important;
  }
  .tl-entities-head {
    padding:14px 18px 10px;
    border-bottom:1px solid var(--border);
  }
  .tl-entities-head h3 {
    margin:0; padding:0; border:0;
    font-size:14px; font-weight:800; color:var(--text);
    display:flex; align-items:center; gap:8px;
  }
  .tl-entities-sub {
    margin:4px 0 0;
    font-size:10.5px; color:var(--muted);
    font-weight:500;
  }
  .tl-entity-tabs {
    display:flex;
    padding:0 10px; gap:4px;
    border-bottom:1px solid var(--border);
    background:var(--bg2);
  }
  .tl-entity-tab {
    flex:1;
    background:transparent;
    border:none; cursor:pointer;
    padding:10px 6px;
    font:inherit;
    font-size:11.5px; font-weight:700;
    color:var(--muted);
    border-bottom:2px solid transparent;
    transition:all .2s;
  }
  .tl-entity-tab:hover { color:var(--text); }
  .tl-entity-tab.active {
    color:var(--accent2);
    border-bottom-color:var(--accent2);
  }
  .tl-entity-panel {
    padding:12px 14px;
    max-height:400px;
    overflow-y:auto;
  }
  .tl-entity-panel::-webkit-scrollbar { width:4px; }
  .tl-entity-panel::-webkit-scrollbar-thumb { background:var(--border); border-radius:2px; }
  .tl-entity-panel[hidden] { display:none; }
  .tl-entity-chip {
    display:block; width:100%;
    text-align:right;
    margin-bottom:8px; padding:10px 12px;
    background:var(--bg2);
    border:1px solid var(--border);
    border-radius:10px;
    cursor:pointer;
    font:inherit;
    color:var(--text);
    transition:all .2s;
  }
  .tl-entity-chip:last-child { margin-bottom:0; }
  .tl-entity-chip:hover {
    border-color:var(--accent2);
    background:#fff;
  }
  .tl-entity-chip.active {
    background:var(--accent2);
    border-color:var(--accent2);
    color:#fff;
    box-shadow:0 6px 18px -6px rgba(13,148,136,.45);
  }
  .tl-entity-chip .ent-name {
    display:block;
    font-size:13px; font-weight:800;
    margin-bottom:2px;
  }
  .tl-entity-chip .ent-desc {
    display:block;
    font-size:11px;
    color:var(--muted);
    font-weight:500;
    line-height:1.5;
  }
  .tl-entity-chip.active .ent-desc {
    color:rgba(255,255,255,.85);
  }
  .tl-entity-count {
    display:inline-block;
    margin-right:6px;
    padding:1px 7px;
    background:var(--border);
    color:var(--muted);
    border-radius:999px;
    font-size:10px;
    font-weight:700;
  }
  .tl-entity-panel-empty {
    padding:18px 10px;
    text-align:center;
    color:var(--muted);
    font-size:12px;
    font-weight:600;
  }

  /* ============ ENTITY FILTER MODE ============
     Active when the reader clicks an entity chip. Events that don't
     reference the selected entity dim out; matching ones gain a gold
     glow. Clicking the chip again (or any other chip) clears it. */
  body.tl-entity-filter .tl-event:not(.entity-match) {
    opacity:.25;
    filter:grayscale(.6);
  }
  body.tl-entity-filter .tl-event.entity-match {
    border-color:var(--gold);
    box-shadow:0 14px 32px -14px rgba(245,158,11,.45);
  }
  body.tl-entity-filter .tl-event.entity-match::before {
    border-color:var(--gold);
  }
  .tl-entity-clear {
    position:sticky; top:0;
    margin:-12px -14px 10px;
    padding:8px 14px;
    background:linear-gradient(90deg, var(--gold-bg), transparent);
    border-bottom:1px solid var(--gold2);
    font-size:11px; font-weight:800;
    color:var(--gold-text);
    display:none;
    cursor:pointer;
    text-align:center;
  }
  .tl-entity-clear:hover { background:var(--gold-bg); }
  body.tl-entity-filter .tl-entity-clear { display:block; }

  /* Brief flash when a date-rail link smooth-scrolls to an event —
     lets the reader see *which* event the click landed on. */
  @keyframes tlFlash {
    0%   { box-shadow:0 0 0 0 rgba(13,148,136,.55); }
    60%  { box-shadow:0 0 0 10px rgba(13,148,136,0); }
    100% { box-shadow:0 0 0 0 rgba(13,148,136,0); }
  }
  .tl-event.tl-flash {
    animation:tlFlash 1.1s ease-out;
    border-color:var(--teal);
  }

  @media(max-width:760px) {
    .tl-title { font-size:22px; }
    .tl-hero { padding:22px 18px; }
    .tl-rail { padding-right:30px; }
    .tl-rail::before { right:12px; }
    .tl-event::before { right:-26px; width:14px; height:14px; border-width:3px; }
    .tl-event { padding:16px 18px; }
    .tl-event-title { font-size:16px; }
    .tl-side-card { position:static; }
    .tl-event-quote { padding:12px 14px 12px 36px; }
    .tl-event-quote::before { font-size:36px; }
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

    <!-- PROGRESS BAR (top of viewport) -->
    <div class="tl-progress-bar" aria-hidden="true">
      <div class="tl-progress-fill" id="tlProgressFill"></div>
    </div>

    <!-- STICKY DATE RAIL (desktop) — one entry per unique date -->
    <?php
      $uniqueDates = [];
      foreach ($timeline['events'] as $evIdx => $ev) {
          $d = substr((string)($ev['date'] ?? ''), 0, 10);
          if ($d === '') continue;
          if (!isset($uniqueDates[$d])) {
              $uniqueDates[$d] = $evIdx + 1; // first event that lives on this date
          }
      }
    ?>
    <?php if (count($uniqueDates) > 1): ?>
      <nav class="tl-date-nav" id="tlDateNav" aria-label="الخط الزمني حسب التاريخ">
        <div class="tl-date-nav-title">📅 تنقّل</div>
        <ul>
          <?php foreach ($uniqueDates as $d => $firstIdx):
            $ts = strtotime($d);
            $short = $ts ? date('d/m', $ts) : $d;
            $long  = $ts ? date('Y/m/d', $ts) : $d;
          ?>
            <li>
              <a href="#tl-event-<?php echo (int)$firstIdx; ?>"
                 data-date="<?php echo e($d); ?>"
                 title="<?php echo e($long); ?>"><?php echo e($short); ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
      </nav>
    <?php endif; ?>

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

        <!-- STICKY CURRENT-DATE PILL (mobile; hidden on desktop by CSS) -->
        <div id="tlCurrentDatePill" class="tl-current-date-pill">📅 —</div>

        <!-- TIMELINE RAIL -->
        <div class="tl-rail" id="tlRail">
          <?php
            $totalEvents = count($timeline['events']);
            foreach ($timeline['events'] as $idx => $event):
              $klass = 'tl-event';
              if ($idx === 0) $klass .= ' first';
              if ($idx === $totalEvents - 1) $klass .= ' last';
              $dateLabel = '';
              $dateKey   = '';
              if (!empty($event['date'])) {
                  $ts = strtotime((string)$event['date']);
                  if ($ts) {
                      $dateLabel = date('Y/m/d', $ts);
                      $dateKey   = date('Y-m-d', $ts);
                  }
              }
              // Build entity-refs attribute (pipe-separated) so the JS
              // can do a single string-match per event.
              $entityAttr = '';
              if (!empty($event['entity_refs'])) {
                  $entityAttr = implode('|', array_map('strval', (array)$event['entity_refs']));
              }
          ?>
            <?php
              // Tier 2 — severity / trajectory / whats_new presentation.
              $sev = (string)($event['severity'] ?? 'update');
              $sevLabels = [
                  'breaking' => '🔥 عاجل',
                  'major'    => '⚡ كبير',
                  'update'   => '📌 تحديث',
                  'context'  => '📚 سياق',
              ];
              $sevLabel = $sevLabels[$sev] ?? $sevLabels['update'];

              $traj = (string)($event['trajectory'] ?? '');
              $trajLabels = [
                  'escalation'    => ['↑', 'تصعيد'],
                  'de-escalation' => ['↓', 'تهدئة'],
                  'steady'        => ['→', 'استمرار'],
                  'shift'         => ['⇄', 'تحوّل'],
              ];
              $trajLabel = $trajLabels[$traj] ?? null;

              $whatsNew = trim((string)($event['whats_new'] ?? ''));
            ?>
            <article id="tl-event-<?php echo $idx + 1; ?>"
                     class="<?php echo $klass; ?>"
                     data-idx="<?php echo $idx + 1; ?>"
                     data-date="<?php echo e($dateKey); ?>"
                     data-sev="<?php echo e($sev); ?>"
                     data-traj="<?php echo e($traj); ?>"
                     data-entities="<?php echo e($entityAttr); ?>">
              <div class="tl-event-head">
                <span class="tl-event-sev tl-sev-<?php echo e($sev); ?>"><?php echo e($sevLabel); ?></span>
                <?php if ($dateLabel !== ''): ?>
                  <span class="tl-event-date">📅 <?php echo e($dateLabel); ?></span>
                <?php endif; ?>
                <?php if ($trajLabel): ?>
                  <span class="tl-event-traj tl-traj-<?php echo e($traj); ?>" title="<?php echo e($trajLabel[1]); ?> مقارنة بالحدث السابق">
                    <?php echo e($trajLabel[0]); ?> <?php echo e($trajLabel[1]); ?>
                  </span>
                <?php endif; ?>
                <?php if (!empty($event['icon'])): ?>
                  <span class="tl-event-icon"><?php echo e($event['icon']); ?></span>
                <?php endif; ?>
              </div>
              <h2 class="tl-event-title"><?php echo e($event['title']); ?></h2>
              <?php if ($whatsNew !== ''): ?>
                <div class="tl-whats-new">
                  <span class="tl-whats-new-icon">✨</span>
                  <span><b>الجديد:</b> <?php echo e($whatsNew); ?></span>
                </div>
              <?php endif; ?>
              <p class="tl-event-summary"><?php echo e($event['summary']); ?></p>

              <?php if (!empty($event['quote']) && !empty($event['quote']['text'])): ?>
                <blockquote class="tl-event-quote">
                  <p><?php echo e($event['quote']['text']); ?></p>
                  <?php if (!empty($event['quote']['speaker'])): ?>
                    <cite>— <?php echo e($event['quote']['speaker']); ?></cite>
                  <?php endif; ?>
                </blockquote>
              <?php endif; ?>

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

        <?php
          // Entities panel — only rendered when Claude actually returned
          // entities for this timeline. Safely skipped otherwise.
          $ent = $timeline['entities'] ?? [];
          $people = $ent['people'] ?? [];
          $places = $ent['places'] ?? [];
          $orgs   = $ent['organizations'] ?? [];
          $hasEntities = !empty($people) || !empty($places) || !empty($orgs);
        ?>
        <?php if ($hasEntities): ?>
          <div class="tl-side-card tl-entities-card" id="tlEntitiesCard">
            <div class="tl-entities-head">
              <h3>🎭 من في القصة؟</h3>
              <p class="tl-entities-sub">اضغط على أي اسم لتصفية الأحداث المرتبطة به</p>
            </div>
            <div class="tl-entity-tabs">
              <button type="button" class="tl-entity-tab active" data-ent-tab="people">
                👤 أشخاص <span class="tl-entity-count"><?php echo count($people); ?></span>
              </button>
              <button type="button" class="tl-entity-tab" data-ent-tab="places">
                📍 أماكن <span class="tl-entity-count"><?php echo count($places); ?></span>
              </button>
              <button type="button" class="tl-entity-tab" data-ent-tab="orgs">
                🏛 منظمات <span class="tl-entity-count"><?php echo count($orgs); ?></span>
              </button>
            </div>

            <div class="tl-entity-panel" data-ent-panel="people">
              <div class="tl-entity-clear" data-ent-clear>✕ أزل التصفية — اعرض كل الأحداث</div>
              <?php if (empty($people)): ?>
                <div class="tl-entity-panel-empty">لم يُحدَّد أشخاص لهذه القصة.</div>
              <?php else: foreach ($people as $p): ?>
                <button type="button" class="tl-entity-chip" data-entity="<?php echo e($p['name']); ?>">
                  <span class="ent-name"><?php echo e($p['name']); ?></span>
                  <?php if (!empty($p['role'])): ?>
                    <span class="ent-desc"><?php echo e($p['role']); ?></span>
                  <?php endif; ?>
                </button>
              <?php endforeach; endif; ?>
            </div>

            <div class="tl-entity-panel" data-ent-panel="places" hidden>
              <div class="tl-entity-clear" data-ent-clear>✕ أزل التصفية — اعرض كل الأحداث</div>
              <?php if (empty($places)): ?>
                <div class="tl-entity-panel-empty">لم تُحدَّد أماكن لهذه القصة.</div>
              <?php else: foreach ($places as $p): ?>
                <button type="button" class="tl-entity-chip" data-entity="<?php echo e($p['name']); ?>">
                  <span class="ent-name"><?php echo e($p['name']); ?></span>
                  <?php if (!empty($p['context'])): ?>
                    <span class="ent-desc"><?php echo e($p['context']); ?></span>
                  <?php endif; ?>
                </button>
              <?php endforeach; endif; ?>
            </div>

            <div class="tl-entity-panel" data-ent-panel="orgs" hidden>
              <div class="tl-entity-clear" data-ent-clear>✕ أزل التصفية — اعرض كل الأحداث</div>
              <?php if (empty($orgs)): ?>
                <div class="tl-entity-panel-empty">لم تُحدَّد منظمات لهذه القصة.</div>
              <?php else: foreach ($orgs as $o): ?>
                <button type="button" class="tl-entity-chip" data-entity="<?php echo e($o['name']); ?>">
                  <span class="ent-name"><?php echo e($o['name']); ?></span>
                  <?php if (!empty($o['context'])): ?>
                    <span class="ent-desc"><?php echo e($o['context']); ?></span>
                  <?php endif; ?>
                </button>
              <?php endforeach; endif; ?>
            </div>
          </div>
        <?php endif; ?>

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

/* =========================================================
   Tier 1 — sticky date rail + scroll progress + current pill
   Uses IntersectionObserver to track which event is currently
   in view, and updates the progress bar + active date link +
   mobile date pill in lock-step. Falls back gracefully when
   IntersectionObserver is unavailable (very old browsers).
   ========================================================= */
(function() {
  var rail = document.getElementById('tlRail');
  if (!rail) return;

  var events      = Array.prototype.slice.call(rail.querySelectorAll('.tl-event'));
  if (!events.length) return;

  var progressEl  = document.getElementById('tlProgressFill');
  var datePill    = document.getElementById('tlCurrentDatePill');
  var dateNav     = document.getElementById('tlDateNav');
  var dateLinks   = dateNav
    ? Array.prototype.slice.call(dateNav.querySelectorAll('a[data-date]'))
    : [];

  // Pretty-format a YYYY-MM-DD date into Arabic-friendly "YYYY/MM/DD".
  function prettyDate(iso) {
    if (!iso) return '—';
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    return m ? (m[1] + '/' + m[2] + '/' + m[3]) : iso;
  }

  function setActiveDate(iso) {
    if (datePill) {
      datePill.textContent = '📅 ' + prettyDate(iso);
    }
    if (!dateLinks.length) return;
    dateLinks.forEach(function(a) {
      if (a.getAttribute('data-date') === iso) {
        a.classList.add('active');
      } else {
        a.classList.remove('active');
      }
    });
  }

  // Scroll progress — updates on scroll using rAF throttling.
  var ticking = false;
  function updateProgress() {
    if (!progressEl) return;
    var rect = rail.getBoundingClientRect();
    var vh   = window.innerHeight || document.documentElement.clientHeight;
    var total = rect.height + vh * 0.4; // start filling a bit before rail top
    var scrolled = (vh - rect.top);
    var pct = Math.max(0, Math.min(1, scrolled / total));
    progressEl.style.width = (pct * 100).toFixed(2) + '%';
    ticking = false;
  }
  function onScroll() {
    if (ticking) return;
    ticking = true;
    (window.requestAnimationFrame || function(cb){ setTimeout(cb, 16); })(updateProgress);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  updateProgress();

  // Active-event tracking via IntersectionObserver. The event that
  // is closest to the 30% line of the viewport wins — that avoids
  // flicker when two events are both partially visible.
  if ('IntersectionObserver' in window) {
    var visible = {};
    var io = new IntersectionObserver(function(entries) {
      entries.forEach(function(ent) {
        var id = ent.target.getAttribute('data-idx');
        if (ent.isIntersecting) {
          visible[id] = {
            el: ent.target,
            top: ent.boundingClientRect.top,
          };
        } else {
          delete visible[id];
        }
      });
      // Pick the visible event whose top is closest to (but above) 30% of viewport.
      var vh = window.innerHeight || document.documentElement.clientHeight;
      var anchor = vh * 0.3;
      var best = null, bestDist = Infinity;
      Object.keys(visible).forEach(function(k) {
        var v = visible[k];
        var dist = Math.abs(v.top - anchor);
        if (dist < bestDist) { bestDist = dist; best = v.el; }
      });
      if (best) {
        var iso = best.getAttribute('data-date') || '';
        setActiveDate(iso);
      }
    }, {
      // Shrink the top/bottom of the viewport so we only "activate"
      // events that are truly in the reading zone.
      rootMargin: '-20% 0px -55% 0px',
      threshold: [0, 0.25, 0.5, 0.75, 1],
    });
    events.forEach(function(el) { io.observe(el); });
  } else {
    // Fallback — just use the first event's date.
    var first = events[0];
    if (first) setActiveDate(first.getAttribute('data-date') || '');
  }

  // Smooth scroll + briefly flash the target event when a date
  // link is clicked.
  dateLinks.forEach(function(a) {
    a.addEventListener('click', function(e) {
      var href = a.getAttribute('href') || '';
      if (href.charAt(0) !== '#') return;
      var target = document.querySelector(href);
      if (!target) return;
      e.preventDefault();
      var y = target.getBoundingClientRect().top + window.pageYOffset - 80;
      window.scrollTo({ top: y, behavior: 'smooth' });
      target.classList.add('tl-flash');
      setTimeout(function() { target.classList.remove('tl-flash'); }, 1200);
    });
  });
})();

/* =========================================================
   Tier 1 — entities panel: tab switcher + click-to-filter
   Clicking a chip adds `body.tl-entity-filter` and marks every
   event whose data-entities contains the chip name with
   `.entity-match`. Re-clicking the same chip, or clicking the
   clear button, removes the filter.
   ========================================================= */
(function() {
  var card = document.getElementById('tlEntitiesCard');
  if (!card) return;

  var rail    = document.getElementById('tlRail');
  if (!rail) return;
  var events  = Array.prototype.slice.call(rail.querySelectorAll('.tl-event'));

  // --- Tab switcher ---
  var tabs   = Array.prototype.slice.call(card.querySelectorAll('.tl-entity-tab'));
  var panels = Array.prototype.slice.call(card.querySelectorAll('[data-ent-panel]'));
  tabs.forEach(function(tab) {
    tab.addEventListener('click', function() {
      var key = tab.getAttribute('data-ent-tab');
      tabs.forEach(function(t) { t.classList.toggle('active', t === tab); });
      panels.forEach(function(p) {
        if (p.getAttribute('data-ent-panel') === key) {
          p.removeAttribute('hidden');
        } else {
          p.setAttribute('hidden', '');
        }
      });
    });
  });

  // --- Chip filter ---
  var chips = Array.prototype.slice.call(card.querySelectorAll('.tl-entity-chip'));
  var activeEntity = null;

  function clearFilter() {
    activeEntity = null;
    document.body.classList.remove('tl-entity-filter');
    chips.forEach(function(c) { c.classList.remove('active'); });
    events.forEach(function(el) { el.classList.remove('entity-match'); });
  }

  function applyFilter(name) {
    if (!name) return clearFilter();
    activeEntity = name;
    document.body.classList.add('tl-entity-filter');
    chips.forEach(function(c) {
      c.classList.toggle('active', c.getAttribute('data-entity') === name);
    });
    var needle = name.toLowerCase();
    var matched = 0;
    events.forEach(function(el) {
      var raw = (el.getAttribute('data-entities') || '').toLowerCase();
      if (!raw) {
        el.classList.remove('entity-match');
        return;
      }
      var parts = raw.split('|');
      var hit = false;
      for (var i = 0; i < parts.length; i++) {
        if (parts[i] === needle) { hit = true; break; }
      }
      el.classList.toggle('entity-match', hit);
      if (hit) matched++;
    });
    // If nothing matched, still show the filter state — the clear
    // button will be visible so the user can escape the empty view.
  }

  chips.forEach(function(chip) {
    chip.addEventListener('click', function() {
      var name = chip.getAttribute('data-entity') || '';
      if (activeEntity === name) {
        clearFilter();
      } else {
        applyFilter(name);
      }
    });
  });

  var clearBtns = Array.prototype.slice.call(card.querySelectorAll('[data-ent-clear]'));
  clearBtns.forEach(function(btn) {
    btn.addEventListener('click', clearFilter);
  });

  // Escape key clears the filter too.
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && activeEntity) clearFilter();
  });
})();
</script>

<script src="assets/js/user.js?v=17" defer></script>
</body>
</html>
