<?php
/**
 * نيوزفلو — صفحة قصة متطوّرة واحدة (Admin-defined)
 *
 * Public reader page for a single persistent story. Shows:
 *   1. Hero with name, description, and live-article counter.
 *   2. An AI-generated narrative (reuses story_timeline_generate()
 *      over the latest N articles of the story). Cached so we don't
 *      hammer Claude on every page view.
 *   3. Timeline rail of events (when the AI call succeeded).
 *   4. A chronological list of every underlying article so readers
 *      who want the raw coverage can drill in.
 *
 * URL: /evolving-story/{slug}  (rewritten by .htaccess)
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';
require_once __DIR__ . '/includes/evolving_stories.php';
require_once __DIR__ . '/includes/story_timeline.php';
require_once __DIR__ . '/includes/cache.php';

$viewer     = current_user();
$viewerId   = $viewer ? (int)$viewer['id'] : 0;
$pageTheme  = current_theme();
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;

$slug  = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
$story = $slug !== '' ? evolving_story_get_by_slug($slug) : null;

if (!$story || !$story['is_active']) {
    http_response_code(404);
    $notFound = true;
} else {
    $notFound = false;
}

// ------------------------------------------------------------------
// Time Machine (⏰) — ?as_of=YYYY-MM-DD lets readers reconstruct how
// the story looked on any day between its first and last article.
// Empty/missing param → current state.
// ------------------------------------------------------------------
$asOf       = '';   // DB-ready datetime ("YYYY-MM-DD 23:59:59") or ''
$asOfDate   = '';   // just the YYYY-MM-DD for the UI
$dateRange  = ['first' => null, 'last' => null];
$isTimeTravel = false;

if (!$notFound) {
    $dateRange = evolving_story_date_range((int)$story['id']);
    $raw = isset($_GET['as_of']) ? trim((string)$_GET['as_of']) : '';
    // Only accept a strict YYYY-MM-DD, and only if it's within the
    // story's actual lifespan — otherwise silently drop the param so
    // old links don't produce empty pages.
    if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        $ts = strtotime($raw);
        $firstTs = !empty($dateRange['first']) ? strtotime($dateRange['first']) : 0;
        $lastTs  = !empty($dateRange['last'])  ? strtotime($dateRange['last'])  : time();
        if ($ts && $ts >= strtotime(date('Y-m-d', $firstTs))
                && $ts <= strtotime(date('Y-m-d', $lastTs))) {
            $asOfDate = $raw;
            $asOf     = $raw . ' 23:59:59';
            $isTimeTravel = true;
        }
    }
}

// ------------------------------------------------------------------
// Data loading (only when story found)
// ------------------------------------------------------------------
$articles        = [];      // DESC (newest first) — used for the raw list below
$articlesAsc     = [];      // ASC (oldest first)  — used for the AI timeline + label lookup
$sourceCount     = 0;
$aiTimeline      = null;
$aiTimelineError = '';

if (!$notFound) {
    // Pull all articles for the story (capped at 40 for perf and to
    // keep the AI prompt size under control). When time-travelling we
    // pass $asOf so only articles published on or before that date
    // are loaded — everything else on the page inherits that slice.
    $articles    = evolving_story_articles((int)$story['id'], 40, 0, $asOf ?: null);
    // story_timeline_generate labels articles A1/A2/… in the order we
    // pass them, and Claude then cites those labels back at us. A
    // chronological (oldest→newest) order makes the AI narrative
    // easier to construct and keeps the label math aligned with the
    // output.
    $articlesAsc = array_reverse($articles);

    // Count distinct sources for the header stat.
    $seen = [];
    foreach ($articles as $a) {
        $sk = $a['source_name'] ?? '';
        if ($sk !== '') $seen[$sk] = true;
    }
    $sourceCount = count($seen);

    // Generate the AI narrative — cached for 6 hours per story. Only
    // generate if there are at least 3 articles, same threshold as
    // the cluster-based timelines.
    //
    // When the Time Machine is engaged we append the as_of date to
    // the cache key so each historical snapshot gets its own cached
    // narrative. The first time someone drags the slider to a new
    // date the call to Claude is paid, but every subsequent reader
    // at that date reads from cache.
    if (count($articlesAsc) >= STORY_TIMELINE_MIN_ARTICLES) {
        $cacheKey = 'evolving_story_timeline_' . (int)$story['id']
                  . ($asOfDate !== '' ? '_' . $asOfDate : '');
        $aiTimeline = cache_get($cacheKey);
        if (!$aiTimeline || !is_array($aiTimeline)) {
            // story_timeline_generate() expects articles that look
            // like the cluster fetch output — published_at, title,
            // excerpt, ai_summary, source_name. That's exactly what
            // evolving_story_articles() returns.
            $result = story_timeline_generate($articlesAsc);
            if (!empty($result['ok'])) {
                $aiTimeline = $result;
                cache_set($cacheKey, $aiTimeline, 6 * 3600);
            } else {
                $aiTimelineError = (string)($result['error'] ?? '');
            }
        }
    }
}

// Build id → article lookup so event source chips can resolve labels
// back to the original article links.
$articlesById = [];
foreach ($articles as $a) {
    $articlesById[(int)$a['id']] = $a;
}

$pageName = $notFound ? 'قصة غير موجودة' : (string)$story['name'];
$metaDesc = $notFound
    ? 'القصة المطلوبة غير متاحة.'
    : ($story['description'] ?: ('تغطية متواصلة لـ' . $story['name'] . ' على نيوزفلو.'));
$pageUrl  = SITE_URL . '/evolving-story/' . rawurlencode($slug);
// Canonical always points to the live view, not the time-travel slice —
// otherwise every historical snapshot would fight for the same ranking.
$canonicalUrl = $pageUrl;
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title><?php echo e($pageName); ?> — قصة متطوّرة · <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<link rel="canonical" href="<?php echo e($canonicalUrl); ?>">
<?php if ($isTimeTravel): ?>
<meta name="robots" content="noindex">
<?php endif; ?>
<meta property="og:title" content="<?php echo e($pageName); ?>">
<meta property="og:description" content="<?php echo e($metaDesc); ?>">
<meta property="og:type" content="article">
<meta property="og:url" content="<?php echo e($pageUrl); ?>">
<?php if (!$notFound && !empty($story['cover_image'])): ?>
<meta property="og:image" content="<?php echo e($story['cover_image']); ?>">
<?php endif; ?>
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
    --es-accent: <?php echo !$notFound ? e($story['accent_color']) : '#0d9488'; ?>;
    --bg:#faf6ec; --bg2:#fdfaf2; --card:#fff; --border:#e0e3e8;
    --text:#1a1a2e; --muted:#6b7280; --gold:#f59e0b; --red:#dc2626;
  }
  body { font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; background:var(--bg); color:var(--text); }
  a { text-decoration:none; color:inherit; }
  .es1-container { max-width:1100px; margin:0 auto; padding:0 24px; }

  /* ============ HERO ============ */
  .es1-hero {
    position:relative; overflow:hidden;
    border-radius:22px; margin:28px 0 24px;
    min-height:260px;
    background:#1a1a2e;
    box-shadow:0 12px 40px -18px rgba(0,0,0,.35);
  }
  .es1-hero-img {
    position:absolute; inset:0; background-size:cover; background-position:center;
    filter:brightness(.5) saturate(1.1);
  }
  .es1-hero-shade {
    position:absolute; inset:0;
    background:linear-gradient(135deg, rgba(26,26,46,.82) 0%, rgba(26,26,46,.45) 60%, rgba(26,26,46,.78) 100%);
  }
  .es1-hero-accent {
    position:absolute; top:0; right:0; left:0; height:6px;
    background:var(--es-accent);
  }
  .es1-hero-content {
    position:relative; padding:40px 32px 34px; color:#fff;
    display:flex; gap:22px; align-items:flex-start;
  }
  .es1-hero-icon {
    width:76px; height:76px; border-radius:18px;
    background:rgba(255,255,255,.96); color:#1a1a2e;
    display:flex; align-items:center; justify-content:center;
    font-size:40px; flex-shrink:0;
    box-shadow:0 6px 20px rgba(0,0,0,.3);
  }
  .es1-hero-main { flex:1; }
  .es1-hero-live {
    display:inline-flex; align-items:center; gap:8px;
    background:var(--red); color:#fff;
    padding:6px 14px; border-radius:999px;
    font-size:12px; font-weight:800; margin-bottom:12px;
  }
  .es1-hero-live .dot {
    width:8px; height:8px; border-radius:50%; background:#fff;
    box-shadow:0 0 0 0 rgba(255,255,255,.6);
    animation:es1-pulse 2s infinite;
  }
  @keyframes es1-pulse {
    0% { box-shadow:0 0 0 0 rgba(255,255,255,.6); }
    70% { box-shadow:0 0 0 10px rgba(255,255,255,0); }
    100% { box-shadow:0 0 0 0 rgba(255,255,255,0); }
  }
  .es1-hero-title {
    font-size:34px; font-weight:900; line-height:1.3; margin-bottom:10px;
    text-shadow:0 3px 12px rgba(0,0,0,.4);
  }
  .es1-hero-desc {
    font-size:15px; line-height:1.85; color:#e5e7eb;
    max-width:740px; margin-bottom:16px;
  }
  .es1-hero-stats {
    display:flex; flex-wrap:wrap; gap:20px;
    font-size:13px; color:#e5e7eb; font-weight:600;
  }
  .es1-hero-stats b { color:#fff; font-weight:900; }

  /* ============ Time Machine ⏰ ============ */
  .es1-tm {
    background:linear-gradient(135deg, #fff 0%, #f8fafc 100%);
    border:1px solid var(--border); border-radius:16px;
    padding:18px 22px; margin-bottom:20px;
    box-shadow:0 3px 14px -8px rgba(0,0,0,.08);
  }
  .es1-tm.is-travelling {
    background:linear-gradient(135deg, #fef3c7 0%, #fffbeb 100%);
    border-color:#fcd34d;
    box-shadow:0 6px 22px -12px rgba(217,119,6,.3);
  }
  .es1-tm-head {
    display:flex; align-items:center; justify-content:space-between;
    gap:12px; flex-wrap:wrap; margin-bottom:14px;
  }
  .es1-tm-title {
    display:flex; align-items:center; gap:10px;
    font-size:14px; font-weight:700; color:var(--text);
  }
  .es1-tm-title b { color:#b45309; font-weight:900; }
  .es1-tm-icon {
    width:36px; height:36px; border-radius:10px;
    background:var(--es-accent); color:#fff;
    display:flex; align-items:center; justify-content:center;
    font-size:18px; flex-shrink:0;
  }
  .es1-tm.is-travelling .es1-tm-icon {
    background:#d97706;
    animation:es1-tm-spin 3s ease-in-out infinite;
  }
  @keyframes es1-tm-spin {
    0%,100% { transform:rotate(0deg); }
    50%     { transform:rotate(-18deg); }
  }
  .es1-tm-reset {
    display:inline-flex; align-items:center; gap:6px;
    padding:7px 14px; border-radius:999px;
    background:#fff; border:1px solid var(--border);
    color:var(--text); font-size:12px; font-weight:800;
    transition:all .2s ease;
  }
  .es1-tm-reset:hover { background:var(--es-accent); color:#fff; border-color:var(--es-accent); }
  .es1-tm-slider-wrap {
    display:flex; align-items:center; gap:12px;
  }
  .es1-tm-bound {
    font-size:11px; font-weight:700; color:var(--muted);
    white-space:nowrap;
  }
  .es1-tm-slider-wrap input[type=range] {
    flex:1; -webkit-appearance:none; appearance:none;
    background:transparent; height:32px; cursor:pointer; direction:ltr;
  }
  .es1-tm-slider-wrap input[type=range]::-webkit-slider-runnable-track {
    height:6px; background:linear-gradient(90deg, var(--es-accent), #f59e0b);
    border-radius:999px;
  }
  .es1-tm-slider-wrap input[type=range]::-moz-range-track {
    height:6px; background:linear-gradient(90deg, var(--es-accent), #f59e0b);
    border-radius:999px; border:none;
  }
  .es1-tm-slider-wrap input[type=range]::-webkit-slider-thumb {
    -webkit-appearance:none; appearance:none;
    width:22px; height:22px; border-radius:50%;
    background:#fff; border:3px solid var(--es-accent);
    margin-top:-8px; cursor:grab;
    box-shadow:0 4px 12px -4px rgba(0,0,0,.3);
    transition:transform .15s ease;
  }
  .es1-tm-slider-wrap input[type=range]::-webkit-slider-thumb:hover { transform:scale(1.15); }
  .es1-tm-slider-wrap input[type=range]::-webkit-slider-thumb:active { cursor:grabbing; }
  .es1-tm-slider-wrap input[type=range]::-moz-range-thumb {
    width:22px; height:22px; border-radius:50%;
    background:#fff; border:3px solid var(--es-accent);
    cursor:grab; box-shadow:0 4px 12px -4px rgba(0,0,0,.3);
  }
  .es1-tm-readout {
    margin-top:12px; text-align:center;
    font-size:13px; font-weight:800; color:var(--text);
    padding:7px 14px; background:#fff; border:1px dashed var(--border);
    border-radius:10px; display:inline-block;
    position:relative; right:50%; transform:translateX(50%);
  }

  /* ============ AI narrative block ============ */
  .es1-narrative {
    background:linear-gradient(135deg, #fff 0%, #f0fdfa 100%);
    border:1px solid rgba(13,148,136,.25);
    border-radius:18px; padding:26px 28px; margin-bottom:24px;
    box-shadow:0 4px 20px -10px rgba(13,148,136,.14);
  }
  .es1-narrative-head {
    display:flex; align-items:center; gap:10px; margin-bottom:14px;
  }
  .es1-narrative-badge {
    background:#0d9488; color:#fff;
    padding:5px 12px; border-radius:999px;
    font-size:11px; font-weight:800;
  }
  .es1-narrative-title { font-size:22px; font-weight:900; line-height:1.4; }
  .es1-narrative-intro { font-size:15px; line-height:1.9; color:#3a3a52; }

  /* ============ Timeline rail ============ */
  .es1-rail { position:relative; padding-right:40px; margin-bottom:28px; }
  .es1-rail::before {
    content:''; position:absolute; top:12px; bottom:12px; right:18px;
    width:3px; background:linear-gradient(180deg, var(--es-accent), var(--gold));
    border-radius:3px;
  }
  .es1-event {
    position:relative; margin-bottom:22px;
    background:var(--card); border:1px solid var(--border);
    border-radius:14px; padding:18px 22px;
    box-shadow:0 1px 3px rgba(0,0,0,.04);
  }
  .es1-event::before {
    content:''; position:absolute; top:22px; right:-32px;
    width:18px; height:18px; border-radius:50%;
    background:var(--card); border:4px solid var(--es-accent);
    z-index:1; box-shadow:0 0 0 4px var(--bg);
  }
  .es1-event-head {
    display:flex; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap;
  }
  .es1-event-date {
    background:#fef3c7; color:#92400e;
    padding:3px 11px; border-radius:999px;
    font-size:11.5px; font-weight:800;
    border:1px solid #fcd34d;
  }
  .es1-event-icon { font-size:20px; }
  .es1-event-title { font-size:17px; font-weight:800; line-height:1.5; margin-bottom:8px; }
  .es1-event-summary { font-size:14px; line-height:1.85; color:#3a3a52; margin-bottom:10px; }
  .es1-event-sources {
    display:flex; flex-wrap:wrap; gap:6px;
    padding-top:10px; border-top:1px dashed var(--border);
  }
  .es1-event-sources .label {
    font-size:11px; color:var(--muted); font-weight:700; align-self:center;
  }
  .es1-src-chip {
    background:var(--bg2); border:1px solid var(--border);
    padding:4px 10px; border-radius:8px;
    font-size:11px; font-weight:700; color:var(--text);
    max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  .es1-src-chip:hover { background:var(--es-accent); color:#fff; border-color:var(--es-accent); }

  /* ============ Articles list ============ */
  .es1-section-head {
    display:flex; align-items:center; justify-content:space-between;
    margin:32px 0 16px;
  }
  .es1-section-head h2 { font-size:22px; font-weight:900; }
  .es1-section-head .count { color:var(--muted); font-size:13px; font-weight:700; }

  .es1-articles { display:flex; flex-direction:column; gap:12px; margin-bottom:56px; }
  .es1-article {
    display:flex; gap:16px; padding:14px;
    background:var(--card); border:1px solid var(--border); border-radius:14px;
    transition:all .25s ease;
  }
  .es1-article:hover {
    transform:translateY(-2px);
    box-shadow:0 10px 28px -14px rgba(0,0,0,.14);
    border-color:var(--es-accent);
  }
  .es1-article-thumb {
    flex:0 0 140px; height:96px; border-radius:10px;
    background-size:cover; background-position:center; background-color:#e5e7eb;
  }
  .es1-article-body { flex:1; min-width:0; }
  .es1-article-title { font-size:15.5px; font-weight:800; line-height:1.55; margin-bottom:6px; }
  .es1-article-excerpt {
    font-size:13px; color:var(--muted); line-height:1.7;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
    margin-bottom:8px;
  }
  .es1-article-meta {
    display:flex; gap:10px; font-size:12px; color:var(--muted); flex-wrap:wrap;
  }
  .es1-article-meta .dot {
    width:8px; height:8px; border-radius:50%; background:var(--es-accent);
  }

  .es1-empty {
    background:#fff; border:1px solid var(--border); border-radius:16px;
    padding:56px 24px; text-align:center; margin:24px 0 56px;
  }
  .es1-empty .icon { font-size:48px; margin-bottom:12px; }

  @media(max-width:760px) {
    .es1-hero-content { flex-direction:column; padding:28px 22px 26px; }
    .es1-hero-icon { width:60px; height:60px; font-size:32px; }
    .es1-hero-title { font-size:24px; }
    .es1-article { flex-direction:column; }
    .es1-article-thumb { width:100%; flex:0 0 auto; height:160px; }
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

<div class="es1-container">

  <?php if ($notFound): ?>
    <div class="es1-empty" style="margin-top:40px;">
      <div class="icon">🔍</div>
      <h2>القصة غير موجودة</h2>
      <p style="color:var(--muted);margin-top:10px;">ربما تم حذفها أو تعطيلها.</p>
      <p style="margin-top:20px;"><a href="/evolving-stories" style="color:var(--es-accent);font-weight:800;">↩ كل القصص المتطوّرة</a></p>
    </div>
  <?php else: ?>

    <!-- HERO -->
    <div class="es1-hero">
      <?php if (!empty($story['cover_image'])): ?>
        <div class="es1-hero-img" style="background-image:url('<?php echo e($story['cover_image']); ?>');"></div>
      <?php elseif (!empty($articles[0]['image_url'])): ?>
        <div class="es1-hero-img" style="background-image:url('<?php echo e($articles[0]['image_url']); ?>');"></div>
      <?php endif; ?>
      <div class="es1-hero-shade"></div>
      <div class="es1-hero-accent"></div>
      <div class="es1-hero-content">
        <div class="es1-hero-icon"><?php echo e($story['icon'] ?: '📅'); ?></div>
        <div class="es1-hero-main">
          <?php if (!$isTimeTravel && !empty($story['last_matched_at']) && strtotime($story['last_matched_at']) > (time() - 7200)): ?>
            <span class="es1-hero-live"><span class="dot"></span>متابعة حيّة</span>
          <?php endif; ?>
          <h1 class="es1-hero-title"><?php echo e($story['name']); ?></h1>
          <?php if (!empty($story['description'])): ?>
            <p class="es1-hero-desc"><?php echo e($story['description']); ?></p>
          <?php endif; ?>
          <div class="es1-hero-stats">
            <span>📰 <b><?php
              echo number_format($isTimeTravel ? count($articles) : $story['article_count']);
            ?></b> تقرير<?php echo $isTimeTravel ? ' حتى ذلك التاريخ' : ''; ?></span>
            <?php if ($sourceCount > 0): ?>
              <span>🌐 <b><?php echo number_format($sourceCount); ?></b> مصدر</span>
            <?php endif; ?>
            <?php if ($isTimeTravel): ?>
              <span>🕰️ وضع آلة الزمن</span>
            <?php elseif (!empty($story['last_matched_at']) && $story['last_matched_at'] !== '0000-00-00 00:00:00'): ?>
              <span>↻ آخر تحديث <b><?php echo e(timeAgo($story['last_matched_at'])); ?></b></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- TIME MACHINE ⏰ — reconstruct the story on any past date -->
    <?php
      $hasRange = !empty($dateRange['first']) && !empty($dateRange['last'])
                  && $dateRange['first'] !== $dateRange['last'];
      if ($hasRange):
        $firstDay = date('Y-m-d', strtotime($dateRange['first']));
        $lastDay  = date('Y-m-d', strtotime($dateRange['last']));
        $activeDay = $asOfDate !== '' ? $asOfDate : $lastDay;
    ?>
      <div class="es1-tm<?php echo $isTimeTravel ? ' is-travelling' : ''; ?>" id="es1TimeMachine">
        <div class="es1-tm-head">
          <div class="es1-tm-title">
            <span class="es1-tm-icon">⏰</span>
            <span>آلة الزمن —
              <?php if ($isTimeTravel): ?>
                تعرض القصة كما كانت في
                <b><?php echo e(date('j F Y', strtotime($asOfDate))); ?></b>
              <?php else: ?>
                اسحب المؤشر لاستعادة القصة في تاريخ معيّن
              <?php endif; ?>
            </span>
          </div>
          <?php if ($isTimeTravel): ?>
            <a class="es1-tm-reset" href="<?php echo e(evolving_story_url($story)); ?>">↺ العودة إلى اليوم</a>
          <?php endif; ?>
        </div>
        <div class="es1-tm-slider-wrap">
          <span class="es1-tm-bound"><?php echo e(date('j F Y', strtotime($firstDay))); ?></span>
          <input
            type="range"
            id="es1TmSlider"
            min="<?php echo e((string)strtotime($firstDay)); ?>"
            max="<?php echo e((string)strtotime($lastDay)); ?>"
            step="86400"
            value="<?php echo e((string)strtotime($activeDay)); ?>"
            data-slug="<?php echo e($story['slug']); ?>"
            aria-label="اختر تاريخاً لإعادة بناء القصة">
          <span class="es1-tm-bound"><?php echo e(date('j F Y', strtotime($lastDay))); ?></span>
        </div>
        <div class="es1-tm-readout" id="es1TmReadout">
          <?php echo e(date('l، j F Y', strtotime($activeDay))); ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- AI narrative (when available) -->
    <?php if ($aiTimeline && !empty($aiTimeline['headline'])): ?>
      <div class="es1-narrative">
        <div class="es1-narrative-head">
          <span class="es1-narrative-badge">🤖 سرد ذكي</span>
          <span style="color:var(--muted);font-size:12px;">مُولَّد من آخر <?php echo count($articles); ?> تقرير</span>
        </div>
        <h2 class="es1-narrative-title"><?php echo e($aiTimeline['headline']); ?></h2>
        <?php if (!empty($aiTimeline['intro'])): ?>
          <p class="es1-narrative-intro" style="margin-top:12px;"><?php echo e($aiTimeline['intro']); ?></p>
        <?php endif; ?>
      </div>

      <?php if (!empty($aiTimeline['events']) && is_array($aiTimeline['events'])): ?>
        <div class="es1-section-head">
          <h2>📍 خط الأحداث</h2>
          <span class="count"><?php echo count($aiTimeline['events']); ?> حدث</span>
        </div>
        <div class="es1-rail">
          <?php foreach ($aiTimeline['events'] as $ev): ?>
            <div class="es1-event">
              <div class="es1-event-head">
                <?php if (!empty($ev['icon'])): ?>
                  <span class="es1-event-icon"><?php echo e($ev['icon']); ?></span>
                <?php endif; ?>
                <?php if (!empty($ev['date'])): ?>
                  <span class="es1-event-date"><?php echo e($ev['date']); ?></span>
                <?php endif; ?>
              </div>
              <?php if (!empty($ev['title'])): ?>
                <div class="es1-event-title"><?php echo e($ev['title']); ?></div>
              <?php endif; ?>
              <?php if (!empty($ev['summary'])): ?>
                <div class="es1-event-summary"><?php echo e($ev['summary']); ?></div>
              <?php endif; ?>
              <?php if (!empty($ev['sources']) && is_array($ev['sources'])): ?>
                <div class="es1-event-sources">
                  <span class="label">المصادر:</span>
                  <?php foreach ($ev['sources'] as $label):
                    // Labels are A1/A2/... indices into the ASC
                    // articles array we passed to story_timeline_generate.
                    $idx = (int)preg_replace('/[^0-9]/', '', (string)$label);
                    if ($idx > 0 && $idx <= count($articlesAsc)) {
                      $src = $articlesAsc[$idx - 1];
                      $srcName = (string)($src['source_name'] ?? '');
                      $srcUrl  = articleUrl($src);
                  ?>
                    <a class="es1-src-chip" href="<?php echo e($srcUrl); ?>">
                      <?php echo e($srcName ?: $label); ?>
                    </a>
                  <?php } endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Raw articles -->
    <div class="es1-section-head">
      <h2>📋 كل التقارير</h2>
      <span class="count"><?php echo count($articles); ?> تقرير</span>
    </div>

    <?php if (empty($articles)): ?>
      <div class="es1-empty">
        <div class="icon">📭</div>
        <h3>لا توجد تقارير مرتبطة بعد</h3>
        <p style="color:var(--muted);margin-top:8px;">سيبدأ النظام تلقائياً بتغذية هذه القصة من الأخبار الواردة.</p>
      </div>
    <?php else: ?>
      <div class="es1-articles">
        <?php foreach ($articles as $a):
          $img  = !empty($a['image_url']) ? $a['image_url'] : placeholderImage(300, 200);
          $href = articleUrl($a);
        ?>
          <a class="es1-article" href="<?php echo e($href); ?>">
            <div class="es1-article-thumb" style="background-image:url('<?php echo e($img); ?>');"></div>
            <div class="es1-article-body">
              <h3 class="es1-article-title"><?php echo e(mb_substr((string)$a['title'], 0, 140)); ?></h3>
              <?php if (!empty($a['excerpt'])): ?>
                <p class="es1-article-excerpt"><?php echo e(mb_substr(strip_tags((string)$a['excerpt']), 0, 200)); ?></p>
              <?php endif; ?>
              <div class="es1-article-meta">
                <span class="dot"></span>
                <span><?php echo e($a['source_name'] ?? '—'); ?></span>
                <span>·</span>
                <span><?php echo e(timeAgo($a['published_at'])); ?></span>
                <?php if (!empty($a['cat_name'])): ?>
                  <span>·</span>
                  <span>🏷 <?php echo e($a['cat_name']); ?></span>
                <?php endif; ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>

<script src="assets/js/user.min.js?v=m1" defer></script>
<script>
(function() {
  // Time Machine slider — live-updates the readout label as the user
  // drags, and on release navigates to the story URL with ?as_of set.
  // We do a full reload rather than AJAX because the hero, narrative,
  // timeline rail and article list all depend on the cached slice, so
  // a server-side re-render is cheapest and keeps the URL shareable.
  const slider = document.getElementById('es1TmSlider');
  const readout = document.getElementById('es1TmReadout');
  if (!slider || !readout) return;

  const monthsAr = ['يناير','فبراير','مارس','أبريل','مايو','يونيو',
                    'يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
  const daysAr   = ['الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];

  function formatAr(ts) {
    const d = new Date(ts * 1000);
    return daysAr[d.getDay()] + '، ' + d.getDate() + ' ' + monthsAr[d.getMonth()] + ' ' + d.getFullYear();
  }
  function toIso(ts) {
    const d = new Date(ts * 1000);
    const pad = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  const slug = slider.getAttribute('data-slug');
  const maxTs = parseInt(slider.max, 10);

  slider.addEventListener('input', () => {
    const ts = parseInt(slider.value, 10);
    readout.textContent = formatAr(ts);
  });
  slider.addEventListener('change', () => {
    const ts = parseInt(slider.value, 10);
    const url = '/evolving-story/' + encodeURIComponent(slug);
    // Landing on the most-recent day is equivalent to the default
    // view — don't pollute the URL with a redundant ?as_of.
    if (ts >= maxTs) {
      window.location.href = url;
    } else {
      window.location.href = url + '?as_of=' + toIso(ts);
    }
  });
})();
</script>
</body>
</html>
