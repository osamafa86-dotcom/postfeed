<?php
/**
 * نيوز فيد — فهرس القصص المتطوّرة (Evolving Stories index)
 *
 * Dedicated discovery page for every Smart Timeline that has been
 * generated, plus fresh cluster candidates that don't yet have one.
 * This is the page the "قصص متطوّرة" nav link opens.
 *
 * URL: /timelines
 *
 * Why this page exists:
 *   Previously the homepage had a narrow "Ongoing Stories" rail that
 *   maxed out at 6 cards. As more timelines get generated, readers
 *   need a place where they can browse all of them, sorted by
 *   freshness. That's this page.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';
require_once __DIR__ . '/includes/story_timeline.php';

$viewer    = current_user();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;

// Build the full list — cached for 5 min. Merges stored timelines
// (which have AI-written headlines and freshness timestamps) with a
// handful of still-brewing candidates so the page feels alive even
// during quiet news hours.
$stories = cache_remember('timelines_index_v1', 300, function() {
    $out  = [];
    $seen = [];

    // Pull up to 30 stored timelines, ordered by freshness.
    foreach (story_timeline_list(30) as $t) {
        $ck = (string)$t['cluster_key'];
        if ($ck === '' || isset($seen[$ck])) continue;
        $seen[$ck] = true;

        // Fetch one representative article to get a cover image —
        // story_timeline_list doesn't return media, so we query for
        // the newest article in the cluster and use its image.
        $img = '';
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT image_url FROM articles
                                   WHERE cluster_key = ? AND status='published'
                                     AND image_url IS NOT NULL AND image_url <> ''
                                ORDER BY published_at DESC LIMIT 1");
            $stmt->execute([$ck]);
            $img = (string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {}

        $out[] = [
            'cluster_key'   => $ck,
            'headline'      => (string)$t['headline'],
            'intro'         => (string)$t['intro'],
            'image_url'     => $img,
            'article_count' => (int)$t['article_count'],
            'source_count'  => (int)$t['source_count'],
            'has_timeline'  => true,
            'generated_at'  => (string)$t['generated_at'],
        ];
    }

    // Pad with a few hot candidates that don't have a timeline yet —
    // clicking them triggers lazy generation on the timeline page.
    foreach (story_timeline_candidates(7, 4, 12) as $cand) {
        $ck = (string)$cand['cluster_key'];
        if ($ck === '' || isset($seen[$ck])) continue;
        $seen[$ck] = true;
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT title, image_url, published_at
                                    FROM articles
                                   WHERE cluster_key = ? AND status='published'
                                   ORDER BY published_at DESC LIMIT 1");
            $stmt->execute([$ck]);
            $rep = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $rep = null; }
        if (!$rep) continue;
        $out[] = [
            'cluster_key'   => $ck,
            'headline'      => (string)$rep['title'],
            'intro'         => '',
            'image_url'     => (string)($rep['image_url'] ?? ''),
            'article_count' => (int)$cand['article_count'],
            'source_count'  => (int)$cand['source_count'],
            'has_timeline'  => false,
            'generated_at'  => (string)$cand['last_seen'],
        ];
    }

    return $out;
});

$storedCount    = 0;
$candidateCount = 0;
foreach ($stories as $s) {
    if (!empty($s['has_timeline'])) $storedCount++;
    else $candidateCount++;
}

$metaDesc = 'فهرس القصص المتطوّرة على نيوز فيد — ' . $storedCount
          . ' خط زمني ذكي يتتبّع تطوّر أهم الأحداث عبر الزمن.';
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title>قصص متطوّرة — خطوط زمنية ذكية · نيوز فيد</title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<meta property="og:title" content="قصص متطوّرة — نيوز فيد">
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
    --accent2:#0d9488; --gold:#f59e0b; --text:#1a1a2e; --muted:#6b7280;
  }
  body { background:var(--bg); font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; color:var(--text); }
  .tli-container { max-width:1200px; margin:0 auto; padding:0 24px; }
  .tli-hero {
    background:linear-gradient(135deg,#fff 0%, #f0fdfa 100%);
    border:1px solid rgba(13,148,136,.25); border-radius:18px;
    padding:32px 28px; margin:28px 0 24px;
    box-shadow:0 4px 24px -10px rgba(13,148,136,.16);
    position:relative; overflow:hidden;
  }
  .tli-hero::before {
    content:''; position:absolute; inset:0;
    background:radial-gradient(circle at 90% 10%, rgba(245,158,11,.08), transparent 60%);
    pointer-events:none;
  }
  .tli-eyebrow {
    display:inline-flex; align-items:center; gap:8px;
    background:rgba(13,148,136,.08); color:var(--accent2);
    border:1px solid rgba(13,148,136,.22); padding:6px 14px;
    border-radius:999px; font-size:12px; font-weight:800;
    margin-bottom:14px; position:relative;
  }
  .tli-eyebrow .live-dot {
    width:8px; height:8px; border-radius:50%; background:#ef4444;
    box-shadow:0 0 0 0 rgba(239,68,68,.5);
    animation:tli-pulse 2s infinite;
  }
  @keyframes tli-pulse {
    0% { box-shadow:0 0 0 0 rgba(239,68,68,.5); }
    70% { box-shadow:0 0 0 10px rgba(239,68,68,0); }
    100% { box-shadow:0 0 0 0 rgba(239,68,68,0); }
  }
  .tli-title { font-size:30px; font-weight:900; line-height:1.4; margin-bottom:10px; position:relative; }
  .tli-lede  { font-size:15px; line-height:1.85; color:#3a3a52; max-width:760px; position:relative; }
  .tli-stats {
    display:flex; flex-wrap:wrap; gap:18px; margin-top:18px;
    font-size:13px; color:var(--muted); font-weight:600; position:relative;
  }
  .tli-stats b { color:var(--text); font-weight:800; }

  .tli-empty {
    background:#fff; border:1px solid var(--border); border-radius:16px;
    padding:56px 24px; text-align:center; margin:24px 0 56px;
  }
  .tli-empty .icon { font-size:48px; margin-bottom:12px; }
  .tli-empty h3 { font-size:18px; margin-bottom:8px; }
  .tli-empty p { color:var(--muted); font-size:14px; }

  @media(max-width:760px) {
    .tli-title { font-size:22px; }
    .tli-hero { padding:22px 18px; }
  }
</style>
</head>
<body>

<?php
$activeType = 'timelines';
$activeSlug = '';
$showTicker = false;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="tli-container">

  <div class="tli-hero">
    <span class="tli-eyebrow"><span class="live-dot"></span> قصص تتطوّر الآن</span>
    <h1 class="tli-title">📅 قصص متطوّرة — خطوط زمنية ذكية</h1>
    <p class="tli-lede">
      خطوط زمنية مُولَّدة بالذكاء الاصطناعي تتتبّع كيف تتطوّر أبرز القصص الإخبارية خطوة بخطوة —
      من أول بلاغ حتى آخر تحديث — بدلاً من قراءة عشرات التقارير المتشابهة.
    </p>
    <div class="tli-stats">
      <span>📅 <b><?php echo number_format($storedCount); ?></b> خط زمني جاهز</span>
      <?php if ($candidateCount > 0): ?>
        <span>✨ <b><?php echo number_format($candidateCount); ?></b> قصة تُجمَّع الآن</span>
      <?php endif; ?>
      <span>🤖 مُولَّد بـ Claude Haiku 4.5</span>
    </div>
  </div>

  <?php if (empty($stories)): ?>
    <div class="tli-empty">
      <div class="icon">📭</div>
      <h3>لا توجد قصص متطوّرة حالياً</h3>
      <p>عُد لاحقاً — القصص تُكتشف تلقائياً عندما تُغطّيها عدة مصادر عبر أيام متتالية.</p>
    </div>
  <?php else: ?>
    <div class="stories-grid" style="margin-bottom:56px;">
      <?php foreach ($stories as $st):
        $stKey  = (string)$st['cluster_key'];
        $stImg  = !empty($st['image_url']) ? $st['image_url'] : placeholderImage(400, 240);
        $stHref = '/timeline/' . $stKey;
      ?>
        <a class="story-card" href="<?php echo e($stHref); ?>">
          <div class="story-card-img" style="background-image:url('<?php echo e($stImg); ?>');">
            <?php if (!empty($st['has_timeline'])): ?>
              <span class="story-badge story-badge-live"><span class="live-dot"></span> خط زمني جاهز</span>
            <?php else: ?>
              <span class="story-badge">✨ جديد</span>
            <?php endif; ?>
            <span class="story-badge-count">📅 <?php echo (int)$st['article_count']; ?> تقرير</span>
          </div>
          <div class="story-card-body">
            <h3 class="story-card-title"><?php echo e(mb_substr((string)$st['headline'], 0, 110)); ?></h3>
            <?php if (!empty($st['intro'])): ?>
              <p class="story-card-intro"><?php echo e(mb_substr((string)$st['intro'], 0, 160)); ?>…</p>
            <?php endif; ?>
            <div class="story-card-meta">
              <span>🌐 <?php echo (int)$st['source_count']; ?> مصدر</span>
              <span class="sep">·</span>
              <span>↻ <?php echo e(timeAgo($st['generated_at'])); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<script src="assets/js/user.min.js?v=m1" defer></script>
</body>
</html>
