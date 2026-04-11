<?php
/**
 * نيوزفلو — جدار الاقتباسات لقصة متطوّرة
 * (Evolving Stories Phase 2 #6 — Quote Wall)
 *
 * Public reader page for the full archive of direct quotes pulled out
 * of a single story's articles by cron_evolving_ai.php. Paginated in
 * batches of 30, newest first.
 *
 * URL: /evolving-story/{slug}/quotes  (rewritten by .htaccess)
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';
require_once __DIR__ . '/includes/evolving_stories.php';
require_once __DIR__ . '/includes/evolving_stories_ai.php';
require_once __DIR__ . '/includes/cache.php';

$viewer    = current_user();
$pageTheme = current_theme();

$slug  = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
$story = $slug !== '' ? evolving_story_get_by_slug($slug) : null;

if (!$story || !$story['is_active']) {
    http_response_code(404);
    $notFound = true;
} else {
    $notFound = false;
}

// Pagination — 30 per page. The Quote Wall can get big on long-lived
// stories so we don't just dump everything.
$perPage = 30;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$quotes      = [];
$quoteTotal  = 0;
$totalPages  = 1;
if (!$notFound) {
    $quoteTotal = evolving_stories_ai_quote_count((int)$story['id']);
    $totalPages = max(1, (int)ceil($quoteTotal / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $quotes = evolving_stories_ai_quotes((int)$story['id'], $perPage, $offset);
}

$pageName = $notFound ? 'جدار اقتباسات غير موجود' : ('جدار الاقتباسات — ' . $story['name']);
$metaDesc = $notFound
    ? 'القصة المطلوبة غير متاحة.'
    : ('كل الاقتباسات المباشرة المستخرجة من تقارير "' . $story['name'] . '".');
$pageUrl  = SITE_URL . '/evolving-story/' . rawurlencode($slug) . '/quotes';
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title><?php echo e($pageName); ?> · <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<link rel="canonical" href="<?php echo e($pageUrl); ?>">
<meta property="og:title" content="<?php echo e($pageName); ?>">
<meta property="og:description" content="<?php echo e($metaDesc); ?>">
<meta property="og:type" content="article">
<meta property="og:url" content="<?php echo e($pageUrl); ?>">
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
    --text:#1a1a2e; --muted:#6b7280; --gold:#f59e0b;
  }
  body { font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; background:var(--bg); color:var(--text); }
  a { text-decoration:none; color:inherit; }
  .qw-container { max-width:1100px; margin:0 auto; padding:0 24px; }

  .qw-hero {
    margin:28px 0 24px; padding:38px 32px 32px;
    background:linear-gradient(135deg, var(--es-accent) 0%, #1a1a2e 120%);
    border-radius:22px; color:#fff; position:relative; overflow:hidden;
    box-shadow:0 12px 40px -18px rgba(0,0,0,.35);
  }
  .qw-hero::before {
    content:'\201C'; position:absolute;
    top:-40px; left:20px; font-size:220px; font-weight:900;
    color:rgba(255,255,255,.08); font-family:'Georgia',serif; line-height:1;
  }
  .qw-hero-eyebrow {
    display:inline-block; padding:5px 14px; border-radius:999px;
    background:rgba(255,255,255,.16); color:#fff; font-size:12px; font-weight:800;
    margin-bottom:12px;
  }
  .qw-hero-title {
    font-size:32px; font-weight:900; line-height:1.4; margin-bottom:10px;
    text-shadow:0 3px 12px rgba(0,0,0,.3);
  }
  .qw-hero-sub { font-size:15px; color:#f0fdfa; line-height:1.85; max-width:720px; }
  .qw-hero-back {
    display:inline-flex; align-items:center; gap:6px;
    margin-top:16px; padding:9px 18px; border-radius:999px;
    background:rgba(255,255,255,.18); color:#fff;
    font-size:12.5px; font-weight:800; transition:all .2s ease;
  }
  .qw-hero-back:hover { background:#fff; color:var(--text); }

  .qw-count {
    display:flex; justify-content:space-between; align-items:center;
    margin:14px 0 18px; color:var(--muted); font-size:13px; font-weight:700;
  }
  .qw-count b { color:var(--text); font-size:18px; font-weight:900; }

  .qw-grid {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr));
    gap:18px; margin-bottom:40px;
  }
  .qw-card {
    background:#fff; border:1px solid var(--border); border-radius:18px;
    padding:26px 26px 22px; position:relative;
    box-shadow:0 4px 16px -10px rgba(0,0,0,.1);
    display:flex; flex-direction:column; gap:14px;
    transition:transform .25s ease, box-shadow .25s ease;
  }
  .qw-card:hover {
    transform:translateY(-3px);
    box-shadow:0 16px 36px -18px rgba(0,0,0,.2);
  }
  .qw-card::before {
    content:'\201C'; position:absolute;
    top:-14px; right:20px; font-size:72px; font-weight:900;
    color:var(--es-accent); font-family:'Georgia',serif; line-height:1;
  }
  .qw-quote-text {
    font-size:16px; line-height:1.9; color:var(--text); font-weight:500;
    padding-top:10px;
  }
  .qw-card.is-long .qw-quote-text {
    font-size:15px; line-height:1.85;
  }
  .qw-attr {
    display:flex; align-items:center; gap:12px;
    padding-top:14px; border-top:1px dashed var(--border);
  }
  .qw-avatar {
    width:42px; height:42px; border-radius:50%;
    background:linear-gradient(135deg, var(--es-accent), var(--gold));
    color:#fff; font-weight:900; font-size:17px;
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
  }
  .qw-meta { flex:1; min-width:0; }
  .qw-speaker { font-size:14.5px; font-weight:900; color:var(--text); }
  .qw-role    { font-size:11.5px; color:var(--muted); font-weight:700; }
  .qw-context {
    margin-top:6px; font-size:12px; color:var(--muted);
    font-style:italic; line-height:1.7;
  }
  .qw-source-link {
    margin-top:12px; padding-top:10px; border-top:1px dashed var(--border);
    font-size:11.5px; color:var(--muted);
  }
  .qw-source-link a {
    color:var(--es-accent); font-weight:800;
  }

  .qw-empty {
    background:#fff; border:1px solid var(--border); border-radius:16px;
    padding:64px 24px; text-align:center; margin:24px 0 56px;
  }
  .qw-empty .icon { font-size:52px; margin-bottom:12px; }

  .qw-pager {
    display:flex; gap:8px; justify-content:center; flex-wrap:wrap;
    margin-bottom:56px;
  }
  .qw-pager a, .qw-pager span {
    padding:8px 14px; border-radius:10px;
    border:1px solid var(--border); background:#fff;
    font-size:13px; font-weight:800; color:var(--text);
    min-width:40px; text-align:center;
  }
  .qw-pager a:hover { background:var(--es-accent); color:#fff; border-color:var(--es-accent); }
  .qw-pager .current { background:var(--es-accent); color:#fff; border-color:var(--es-accent); }
  .qw-pager .disabled { opacity:.4; pointer-events:none; }

  @media(max-width:760px) {
    .qw-hero { padding:28px 22px 24px; }
    .qw-hero-title { font-size:24px; }
    .qw-grid { grid-template-columns:1fr; }
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

<div class="qw-container">

  <?php if ($notFound): ?>
    <div class="qw-empty" style="margin-top:40px;">
      <div class="icon">🔍</div>
      <h2>القصة غير موجودة</h2>
      <p style="color:var(--muted);margin-top:10px;">ربما تم حذفها أو تعطيلها.</p>
      <p style="margin-top:20px;"><a href="/evolving-stories" style="color:var(--es-accent);font-weight:800;">↩ كل القصص المتطوّرة</a></p>
    </div>
  <?php else: ?>

    <div class="qw-hero">
      <span class="qw-hero-eyebrow">💬 جدار الاقتباسات</span>
      <h1 class="qw-hero-title"><?php echo e($story['name']); ?></h1>
      <p class="qw-hero-sub">
        كل الاقتباسات المباشرة المستخرجة تلقائياً من تقارير القصة، منسوبة لأصحابها.
      </p>
      <a class="qw-hero-back" href="/evolving-story/<?php echo e((string)$story['slug']); ?>">
        ← العودة إلى القصة
      </a>
    </div>

    <div class="qw-count">
      <span>📍 الصفحة <b><?php echo number_format($page); ?></b> من <b><?php echo number_format($totalPages); ?></b></span>
      <span><b><?php echo number_format($quoteTotal); ?></b> اقتباس في الأرشيف</span>
    </div>

    <?php if (empty($quotes)): ?>
      <div class="qw-empty">
        <div class="icon">📭</div>
        <h3>لا توجد اقتباسات بعد</h3>
        <p style="color:var(--muted);margin-top:10px;max-width:420px;margin-inline:auto;">
          سيبدأ النظام قريباً في استخراج الاقتباسات المباشرة من تقارير هذه القصة.
        </p>
      </div>
    <?php else: ?>
      <div class="qw-grid">
        <?php foreach ($quotes as $q):
          $speaker = trim((string)($q['speaker'] ?? ''));
          $initial = $speaker !== '' ? mb_substr($speaker, 0, 1) : '؟';
          $textLen = mb_strlen((string)$q['quote_text']);
          $articleHref = articleUrl([
              'id'   => $q['article_id'],
              'slug' => $q['article_slug'] ?? '',
          ]);
        ?>
          <figure class="qw-card<?php echo $textLen > 220 ? ' is-long' : ''; ?>">
            <blockquote class="qw-quote-text"><?php echo e((string)$q['quote_text']); ?></blockquote>
            <figcaption class="qw-attr">
              <div class="qw-avatar"><?php echo e($initial); ?></div>
              <div class="qw-meta">
                <div class="qw-speaker"><?php echo e($speaker ?: '—'); ?></div>
                <?php if (!empty($q['speaker_role'])): ?>
                  <div class="qw-role"><?php echo e((string)$q['speaker_role']); ?></div>
                <?php endif; ?>
                <?php if (!empty($q['context'])): ?>
                  <div class="qw-context"><?php echo e((string)$q['context']); ?></div>
                <?php endif; ?>
              </div>
            </figcaption>
            <div class="qw-source-link">
              <?php if (!empty($q['source_name'])): ?>
                <span>🌐 <?php echo e((string)$q['source_name']); ?></span>
                <span>·</span>
              <?php endif; ?>
              <?php if (!empty($q['published_at'])): ?>
                <span><?php echo e(timeAgo($q['published_at'])); ?></span>
                <span>·</span>
              <?php endif; ?>
              <a href="<?php echo e($articleHref); ?>">التقرير الأصلي ←</a>
            </div>
          </figure>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <?php
          $baseUrl = '/evolving-story/' . rawurlencode((string)$story['slug']) . '/quotes';
          // Build a compact pager: first, prev, window of 5, next, last.
          $window = 2;
          $start  = max(1, $page - $window);
          $end    = min($totalPages, $page + $window);
        ?>
        <nav class="qw-pager" aria-label="ترقيم الصفحات">
          <?php if ($page > 1): ?>
            <a href="<?php echo e($baseUrl); ?>?page=<?php echo $page - 1; ?>">← السابق</a>
          <?php else: ?>
            <span class="disabled">← السابق</span>
          <?php endif; ?>

          <?php if ($start > 1): ?>
            <a href="<?php echo e($baseUrl); ?>?page=1">1</a>
            <?php if ($start > 2): ?><span>…</span><?php endif; ?>
          <?php endif; ?>

          <?php for ($i = $start; $i <= $end; $i++): ?>
            <?php if ($i === $page): ?>
              <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
              <a href="<?php echo e($baseUrl); ?>?page=<?php echo $i; ?>"><?php echo $i; ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <?php if ($end < $totalPages): ?>
            <?php if ($end < $totalPages - 1): ?><span>…</span><?php endif; ?>
            <a href="<?php echo e($baseUrl); ?>?page=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a>
          <?php endif; ?>

          <?php if ($page < $totalPages): ?>
            <a href="<?php echo e($baseUrl); ?>?page=<?php echo $page + 1; ?>">التالي →</a>
          <?php else: ?>
            <span class="disabled">التالي →</span>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>

  <?php endif; ?>

</div>

<script src="assets/js/user.min.js?v=m1" defer></script>
</body>
</html>
