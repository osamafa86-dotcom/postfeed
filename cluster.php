<?php
/**
 * نيوزفلو - قارن التغطية (Compare Coverage)
 *
 * Side-by-side view of how the same story was covered by every
 * source we ingested it from. Reads articles.cluster_key — the
 * SHA1 fingerprint produced by includes/article_cluster.php on
 * normalized title tokens — and shows every published row that
 * shares the key, ordered by publication time.
 *
 * URL: /cluster/{cluster_key}
 *
 * Why this is useful:
 *   - Readers can see at a glance which sources covered the
 *     story, when, and (via the AI summary) what each source
 *     emphasized.
 *   - Powerful long-tail SEO: every cluster is a stable, unique
 *     page about a single news event with N inbound headlines.
 *   - Editorial signal: reveals divergence in framing across
 *     sources for the same underlying event.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';
require_once __DIR__ . '/includes/article_cluster.php';
require_once __DIR__ . '/includes/smart_brevity.php';

$viewer    = current_user();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();

// Cluster keys are SHA1 hex (40 chars). Validate strictly so
// nothing weird makes it into the SQL or page metadata.
$rawKey = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
$key    = preg_match('/^[a-f0-9]{40}$/', $rawKey) ? $rawKey : '';

$db = getDB();

$articles = [];
if ($key !== '') {
    $stmt = $db->prepare("SELECT a.*, c.name AS cat_name, c.slug AS cat_slug, c.css_class,
                                 s.name AS source_name, s.logo_color, s.url AS source_website
                            FROM articles a
                            LEFT JOIN categories c ON a.category_id = c.id
                            LEFT JOIN sources    s ON a.source_id   = s.id
                           WHERE a.cluster_key = ? AND a.status = 'published'
                           ORDER BY a.published_at ASC");
    $stmt->execute([$key]);
    $articles = $stmt->fetchAll();
}

$totalCount = count($articles);

// Pick a canonical headline for the page (the longest title — proxy
// for "most descriptive") and the earliest publication time as the
// "first reported" timestamp. Used in the page header + meta tags.
$canonicalTitle = '';
$earliestAt     = null;
$latestAt       = null;
$sourceNames    = [];
foreach ($articles as $a) {
    $t = trim((string)($a['title'] ?? ''));
    if (mb_strlen($t) > mb_strlen($canonicalTitle)) $canonicalTitle = $t;
    $pa = $a['published_at'] ?? null;
    if ($pa) {
        if (!$earliestAt || $pa < $earliestAt) $earliestAt = $pa;
        if (!$latestAt   || $pa > $latestAt)   $latestAt   = $pa;
    }
    if (!empty($a['source_name'])) $sourceNames[$a['source_name']] = true;
}
$sourceCount = count($sourceNames);

$pageTitleText = $canonicalTitle !== '' ? $canonicalTitle : 'قارن التغطية';
$metaDesc = $canonicalTitle !== ''
    ? ('شاهد كيف غطّى ' . $sourceCount . ' مصدر إخبارّي خبر «' . mb_substr($canonicalTitle, 0, 100) . '» جنباً إلى جنب على نيوزفلو.')
    : 'تغطية متعدّدة المصادر لكل خبر — قارن كيف نقلته الصحف العربية.';

// Pre-fetch saved bookmarks for this page's articles.
$GLOBALS['__nf_saved_ids'] = [];
if ($viewerId && !empty($articles)) {
    $__ids = array_map(fn($a) => (int)$a['id'], $articles);
    $GLOBALS['__nf_saved_ids'] = array_flip(user_bookmark_ids_for($viewerId, $__ids));
}

$pageUrl = SITE_URL . '/cluster/' . $key;
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title><?php echo e($pageTitleText); ?> — قارن التغطية | <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<link rel="canonical" href="<?php echo e($pageUrl); ?>">
<meta property="og:type" content="article">
<meta property="og:title" content="<?php echo e($pageTitleText); ?>">
<meta property="og:description" content="<?php echo e($metaDesc); ?>">
<meta property="og:url" content="<?php echo e($pageUrl); ?>">
<?php if (!empty($articles[0]['image_url'])): ?>
<meta property="og:image" content="<?php echo e($articles[0]['image_url']); ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo e($pageTitleText); ?>">
<meta name="twitter:description" content="<?php echo e($metaDesc); ?>">
<?php if (!empty($articles[0]['image_url'])): ?>
<meta name="twitter:image" content="<?php echo e($articles[0]['image_url']); ?>">
<?php endif; ?>
<?php
    require_once __DIR__ . '/includes/seo.php';
    render_breadcrumb([
        ['name' => getSetting('site_name', SITE_NAME), 'url' => SITE_URL . '/'],
        ['name' => 'تغطية متعدّدة المصادر'],
        ['name' => mb_substr($pageTitleText, 0, 80)],
    ]);
    render_collection_ld($pageTitleText, $metaDesc, $pageUrl, $articles);
?>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#1a5c5c">
<link rel="alternate" type="application/rss+xml" title="نيوزفلو RSS" href="/rss.xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"></noscript>
<style>
  :root {
    --bg:#faf6ec; --bg2:#fdfaf2; --bg3:#e4e6eb;
    --card:#fff; --border:#e0e3e8;
    --accent:#1a73e8; --accent2:#0d9488; --accent3:#16a34a;
    --gold:#f59e0b; --gold2:#fcd34d; --gold-bg:#fef3c7; --gold-text:#92400e;
    --red:#dc2626; --text:#1a1a2e; --muted:#6b7280; --muted2:#9ca3af;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; background:var(--bg); color:var(--text); line-height:1.6; }
  a { text-decoration:none; color:inherit; }
  .container { max-width:1200px; margin:0 auto; padding:0 24px; }

  /* HEADER */
  .cluster-hero {
    background:linear-gradient(135deg,#fff 0%, #fefce8 100%);
    border:1px solid var(--gold2); border-radius:18px;
    padding:32px 28px; margin:28px 0;
    box-shadow:0 4px 24px -10px rgba(245,158,11,.18);
  }
  .cluster-eyebrow {
    display:inline-flex; align-items:center; gap:8px;
    background:var(--gold-bg); color:var(--gold-text);
    border:1px solid var(--gold2); padding:5px 14px;
    border-radius:999px; font-size:12px; font-weight:800;
    margin-bottom:14px;
  }
  .cluster-title {
    font-size:28px; font-weight:900; line-height:1.45;
    color:var(--text); margin-bottom:14px;
  }
  .cluster-meta {
    display:flex; flex-wrap:wrap; gap:20px;
    font-size:13px; color:var(--muted); font-weight:600;
  }
  .cluster-meta b { color:var(--text); font-weight:800; }

  /* TIMELINE STRIP */
  .cluster-timeline {
    display:flex; align-items:center; gap:8px; margin-top:18px;
    flex-wrap:wrap; font-size:12px; color:var(--muted);
  }
  .cluster-timeline-dot {
    width:8px; height:8px; border-radius:50%;
    background:var(--gold); flex-shrink:0;
  }

  /* COVERAGE CARDS */
  .coverage-list {
    display:grid; grid-template-columns:1fr; gap:18px;
    margin-bottom:48px;
  }
  .coverage-card {
    display:grid; grid-template-columns:200px 1fr;
    background:var(--card); border:1px solid var(--border);
    border-radius:14px; overflow:hidden;
    transition:all .25s ease;
    box-shadow:0 1px 3px rgba(0,0,0,.04);
  }
  .coverage-card:hover {
    transform:translateY(-3px);
    box-shadow:0 12px 32px -14px rgba(15,23,42,.16);
    border-color:rgba(13,148,136,.25);
  }
  .coverage-img {
    background:var(--bg3); position:relative; overflow:hidden;
  }
  .coverage-img img {
    width:100%; height:100%; object-fit:cover;
    transition:transform .5s ease;
  }
  .coverage-card:hover .coverage-img img { transform:scale(1.05); }
  .coverage-order {
    position:absolute; top:10px; right:10px;
    width:30px; height:30px; border-radius:50%;
    background:rgba(0,0,0,.7); color:#fff;
    display:flex; align-items:center; justify-content:center;
    font-size:13px; font-weight:800;
    backdrop-filter:blur(4px);
  }
  .coverage-body { padding:18px 22px; display:flex; flex-direction:column; }
  .coverage-source {
    display:flex; align-items:center; gap:10px;
    font-size:13px; font-weight:700; margin-bottom:10px;
  }
  .coverage-source .src-dot {
    width:24px; height:24px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:800; font-size:12px;
  }
  .coverage-source .when { color:var(--muted); font-weight:600; font-size:12px; }
  .coverage-title {
    font-size:17px; font-weight:800; line-height:1.55;
    color:var(--text); margin-bottom:10px;
  }
  .coverage-title a { color:inherit; }
  .coverage-title a:hover { color:var(--accent2); }
  .coverage-snippet {
    font-size:13px; color:var(--muted); line-height:1.7;
    margin-bottom:12px;
    display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;
  }
  .coverage-actions {
    margin-top:auto; display:flex; gap:10px; align-items:center; flex-wrap:wrap;
  }
  .coverage-actions a {
    display:inline-flex; align-items:center; gap:6px;
    padding:7px 14px; border-radius:8px;
    font-size:12px; font-weight:700;
    background:var(--bg2); border:1px solid var(--border);
    color:var(--text); transition:all .2s;
  }
  .coverage-actions a:hover {
    background:var(--accent2); color:#fff; border-color:var(--accent2);
  }
  .coverage-actions a.primary {
    background:var(--accent2); color:#fff; border-color:var(--accent2);
  }
  .coverage-actions a.primary:hover { background:#0f766e; }

  /* SMART BREVITY */
  .brevity-card {
    background:linear-gradient(135deg,#f0fdf4 0%,#ecfdf5 50%,#f0fdfa 100%);
    border:1px solid rgba(13,148,136,.25); border-radius:16px;
    padding:0; margin:0 0 24px; overflow:hidden;
    box-shadow:0 2px 12px -4px rgba(13,148,136,.12);
  }
  .brevity-header {
    display:flex; align-items:center; justify-content:space-between;
    padding:16px 22px; cursor:pointer; user-select:none;
    background:rgba(13,148,136,.06);
    border-bottom:1px solid rgba(13,148,136,.12);
  }
  .brevity-header:hover { background:rgba(13,148,136,.1); }
  .brevity-header h3 { font-size:15px; font-weight:800; color:#0f766e; margin:0; }
  .brevity-header .toggle { font-size:18px; transition:transform .3s; }
  .brevity-header.collapsed .toggle { transform:rotate(-90deg); }
  .brevity-body { padding:20px 22px; display:grid; gap:18px; }
  .brevity-body.hidden { display:none; }
  .brevity-section { }
  .brevity-section h4 {
    font-size:13px; font-weight:800; color:#0f766e;
    margin-bottom:6px; display:flex; align-items:center; gap:6px;
  }
  .brevity-section p, .brevity-section li {
    font-size:14px; line-height:1.75; color:var(--text);
  }
  .brevity-numbers {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px;
  }
  .brevity-num {
    background:#fff; border:1px solid rgba(13,148,136,.15);
    border-radius:10px; padding:12px 14px;
  }
  .brevity-num .val {
    font-size:22px; font-weight:900; color:#0f766e; line-height:1.2;
  }
  .brevity-num .ctx { font-size:12px; color:var(--muted); margin-top:2px; }
  .brevity-quotes { display:grid; gap:10px; }
  .brevity-quote {
    background:#fff; border-right:3px solid var(--gold);
    border-radius:0 10px 10px 0; padding:12px 16px;
  }
  .brevity-quote .qt { font-size:13px; line-height:1.7; color:var(--text); font-style:italic; }
  .brevity-quote .sp { font-size:11px; color:var(--muted); font-weight:700; margin-top:4px; }

  .empty-state { text-align:center; padding:80px 20px; color:var(--muted); }
  .empty-state .icon { font-size:56px; margin-bottom:18px; }
  .empty-state h3 { font-size:20px; margin-bottom:8px; color:var(--text); font-weight:800; }
  .empty-state a { color:var(--accent2); font-weight:700; }

  @media(max-width:760px) {
    .coverage-card { grid-template-columns:1fr; }
    .coverage-img { height:200px; }
    .cluster-title { font-size:22px; }
    .cluster-hero { padding:22px 18px; }
  }
</style>
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<link rel="stylesheet" href="assets/css/user.min.css?v=m1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
</head>
<body>

<?php
$activeType = 'cluster';
$activeSlug = '';
$showTicker = false;
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="container">

  <?php if ($key === '' || empty($articles)): ?>
    <div class="empty-state" style="margin-top:40px">
      <div class="icon">📰</div>
      <h3>لا توجد تغطية متعدّدة لهذا الخبر</h3>
      <p>قد يكون الخبر نُشر بمصدر واحد فقط، أو أنّ المعرّف غير صحيح.<br>
        عُد إلى <a href="/">الصفحة الرئيسية</a> لتصفّح آخر الأخبار.</p>
    </div>
  <?php else: ?>

    <!-- HERO -->
    <div class="cluster-hero">
      <span class="cluster-eyebrow">📰 قارن التغطية — <?php echo (int)$sourceCount; ?> مصادر</span>
      <h1 class="cluster-title"><?php echo e($canonicalTitle); ?></h1>
      <div class="cluster-meta">
        <span>🗞 <b><?php echo number_format($totalCount); ?></b> تقرير</span>
        <span>🌐 <b><?php echo number_format($sourceCount); ?></b> مصدر إخبارّي</span>
        <?php if ($earliestAt): ?>
          <span>⏱ أوّل نشر <b><?php echo timeAgo($earliestAt); ?></b></span>
        <?php endif; ?>
        <?php if ($latestAt && $latestAt !== $earliestAt): ?>
          <span>↻ آخر تحديث <b><?php echo timeAgo($latestAt); ?></b></span>
        <?php endif; ?>
      </div>
      <?php
        require_once __DIR__ . '/includes/trending.php';
        $srcVel = cluster_source_velocity($key);
        if ($srcVel['label'] !== ''):
      ?>
      <div style="margin-top:12px;display:inline-flex;align-items:center;gap:8px;
                  padding:8px 16px;border-radius:10px;font-size:13px;font-weight:800;
                  background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.2);color:#dc2626;">
        <?php echo e($srcVel['label']); ?>
      </div>
      <?php endif; ?>
      <div class="cluster-timeline">
        <span>📅 الترتيب الزمني للنشر:</span>
        <?php foreach ($articles as $idx => $a): ?>
          <span class="cluster-timeline-dot"></span>
          <span><?php echo e($a['source_name'] ?? '—'); ?></span>
        <?php endforeach; ?>
      </div>
      <?php if ($totalCount >= 3): ?>
        <div style="margin-top:20px;">
          <a href="/timeline/<?php echo e($key); ?>"
             style="display:inline-flex;align-items:center;gap:10px;padding:12px 22px;
                    background:linear-gradient(135deg,var(--accent2),#0f766e);color:#fff;
                    border-radius:12px;font-weight:800;font-size:14px;
                    box-shadow:0 6px 18px -8px rgba(13,148,136,.5);transition:all .2s;">
            📅 شاهد الخط الزمني الذكي للقصّة ←
          </a>
          <div style="font-size:11px;color:var(--muted);margin-top:6px;">
            ملخص تفاعلي مُولَّد بالذكاء الاصطناعي يُظهر كيف تطوّرت القصة عبر الزمن.
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- SMART BREVITY -->
    <?php
      $brevity = null;
      if ($totalCount >= 2) {
          $brevity = smart_brevity_for_cluster($key, $articles);
      }
    ?>
    <?php if ($brevity && !empty($brevity['why_matters'])): ?>
    <div class="brevity-card">
      <div class="brevity-header" onclick="var b=this.nextElementSibling;b.classList.toggle('hidden');this.classList.toggle('collapsed')">
        <h3>⚡ الإيجاز الذكي — خلاصة القصة في 30 ثانية</h3>
        <span class="toggle">▾</span>
      </div>
      <div class="brevity-body">
        <?php if (!empty($brevity['why_matters'])): ?>
        <div class="brevity-section">
          <h4>🎯 لماذا يهم</h4>
          <p><?php echo e($brevity['why_matters']); ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($brevity['big_picture'])): ?>
        <div class="brevity-section">
          <h4>🌍 الصورة الأكبر</h4>
          <p><?php echo e($brevity['big_picture']); ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($brevity['by_the_numbers'])): ?>
        <div class="brevity-section">
          <h4>📊 بالأرقام</h4>
          <div class="brevity-numbers">
            <?php foreach ($brevity['by_the_numbers'] as $n): ?>
              <?php if (is_array($n) && !empty($n['value'])): ?>
              <div class="brevity-num">
                <div class="val"><?php echo e($n['value']); ?></div>
                <div class="ctx"><?php echo e($n['context'] ?? ''); ?></div>
              </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($brevity['what_they_say'])): ?>
        <div class="brevity-section">
          <h4>💬 ماذا يقولون</h4>
          <div class="brevity-quotes">
            <?php foreach ($brevity['what_they_say'] as $q): ?>
              <?php if (is_array($q) && !empty($q['quote'])): ?>
              <div class="brevity-quote">
                <div class="qt">«<?php echo e($q['quote']); ?>»</div>
                <div class="sp">— <?php echo e($q['speaker'] ?? ''); ?></div>
              </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($brevity['zoom_in'])): ?>
        <div class="brevity-section">
          <h4>🔍 تقريب العدسة</h4>
          <p><?php echo e($brevity['zoom_in']); ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- COVERAGE LIST -->
    <div class="coverage-list">
      <?php foreach ($articles as $i => $article): ?>
        <?php
          $img = $article['image_url'] ?? placeholderImage(400, 300);
          $snippet = trim(strip_tags((string)($article['ai_summary'] ?? $article['excerpt'] ?? '')));
          $srcInitial = mb_substr((string)($article['source_name'] ?? '?'), 0, 1);
          $color = $article['logo_color'] ?? '#0d9488';
        ?>
        <article class="coverage-card">
          <div class="coverage-img">
            <img src="<?php echo e($img); ?>" alt="<?php echo e($article['title']); ?>" loading="lazy" decoding="async">
            <div class="coverage-order"><?php echo $i + 1; ?></div>
          </div>
          <div class="coverage-body">
            <div class="coverage-source">
              <span class="src-dot" style="background:<?php echo e($color); ?>"><?php echo e($srcInitial); ?></span>
              <span><?php echo e($article['source_name'] ?? '—'); ?></span>
              <span class="when">·  <?php echo timeAgo($article['published_at']); ?></span>
            </div>
            <h2 class="coverage-title">
              <a href="<?php echo articleUrl($article); ?>"><?php echo e($article['title']); ?></a>
            </h2>
            <?php if ($snippet !== ''): ?>
              <p class="coverage-snippet"><?php echo e(mb_substr($snippet, 0, 280)); ?><?php echo mb_strlen($snippet) > 280 ? '…' : ''; ?></p>
            <?php endif; ?>
            <div class="coverage-actions">
              <a class="primary" href="<?php echo articleUrl($article); ?>">📖 اقرأ على نيوزفلو</a>
              <?php if (!empty($article['source_url'])): ?>
                <a href="<?php echo e($article['source_url']); ?>" target="_blank" rel="noopener nofollow">↗ المصدر الأصلي</a>
              <?php endif; ?>
              <?php if (!empty($article['cat_name'])): ?>
                <span style="font-size:11px;color:var(--muted);font-weight:600;padding:6px 12px;">🏷 <?php echo e($article['cat_name']); ?></span>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>

</div>

<script src="assets/js/user.min.js?v=m1" defer></script>
</body>
</html>
