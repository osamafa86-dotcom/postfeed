<?php
/**
 * نيوز فيد - صفحة الموضوعات (Topic Clusters)
 *
 * Groups articles by an AI-extracted keyword. Reuses the
 * articles.ai_keywords column (CSV: "كلمة1, كلمة2, كلمة3")
 * that ai_helper.php already populates per article — turning
 * keyword metadata that was previously only used for SEO meta
 * tags into a real navigation surface.
 *
 * Two modes:
 *   /topic/غزة         → list articles tagged with this keyword
 *   /topic/             → browse the most popular topics on the site
 *
 * Matching is precise: we wrap the stored CSV with ", " on both
 * sides and look for ", keyword, " so e.g. searching "غزة" never
 * matches "غزةاوي".
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';

$viewer    = current_user();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();

// Slug is the URL-decoded keyword. Cap length so we never feed
// pathological input straight into SQL or page metadata.
$rawSlug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
$rawSlug = str_replace(['_', '+'], ' ', $rawSlug);
$keyword = mb_substr($rawSlug, 0, 80);

$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$db = getDB();

/**
 * Top topics across the corpus, with article counts.
 * Splits each row's ai_keywords CSV in PHP (cheaper than running
 * SUBSTRING_INDEX in SQL on a non-indexed column for a moderate
 * dataset) and aggregates the result.
 */
function topicListPopular(PDO $db, int $limit = 40, int $sampleArticles = 800): array {
    $rows = $db->query("SELECT ai_keywords FROM articles
                        WHERE status = 'published' AND ai_keywords IS NOT NULL AND ai_keywords <> ''
                        ORDER BY published_at DESC
                        LIMIT " . (int)$sampleArticles)
               ->fetchAll(PDO::FETCH_COLUMN);
    $counts = [];
    foreach ($rows as $csv) {
        foreach (explode(',', (string)$csv) as $kw) {
            $kw = trim($kw);
            if ($kw === '' || mb_strlen($kw) < 2) continue;
            // Skip purely numeric tokens — they're rarely useful as topics.
            if (ctype_digit(preg_replace('/\D+/u', '', $kw) ?? '') && preg_match('/^\d+$/u', $kw)) continue;
            $counts[$kw] = ($counts[$kw] ?? 0) + 1;
        }
    }
    arsort($counts);
    return array_slice($counts, 0, $limit, true);
}

$articles    = [];
$totalCount  = 0;
$totalPages  = 1;
$popular     = [];

if ($keyword === '') {
    // Browse mode: show the most popular topics so users have a way
    // in even without typing a search.
    $popular = topicListPopular($db, 60);
} else {
    // Precise CSV match: surround stored value with ", " on both
    // sides and the needle the same way, so we hit "غزة" but not
    // "غزةاوي" or "بقية".
    $needle = '%, ' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . ',%';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM articles
                               WHERE status = 'published'
                                 AND ai_keywords IS NOT NULL
                                 AND CONCAT(', ', ai_keywords, ',') LIKE ?");
    $countStmt->execute([$needle]);
    $totalCount = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT a.*, c.name AS cat_name, c.slug AS cat_slug, c.css_class,
                                 s.name AS source_name, s.logo_color
                            FROM articles a
                            LEFT JOIN categories c ON a.category_id = c.id
                            LEFT JOIN sources    s ON a.source_id   = s.id
                           WHERE a.status = 'published'
                             AND a.ai_keywords IS NOT NULL
                             AND CONCAT(', ', a.ai_keywords, ',') LIKE ?
                           ORDER BY a.published_at DESC
                           LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $needle, PDO::PARAM_STR);
    $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $articles = $stmt->fetchAll();

    $totalPages = max(1, (int)ceil($totalCount / $perPage));
}

function topicUrl(string $kw): string {
    return '/topic/' . rawurlencode($kw);
}
function topicPageUrl(string $kw, int $pageNum): string {
    return topicUrl($kw) . ($pageNum > 1 ? '?page=' . $pageNum : '');
}

// Pre-fetch saved bookmarks for this page's articles, mirroring
// category.php so the bookmark button reflects the right state.
$GLOBALS['__nf_saved_ids'] = [];
if ($viewerId && !empty($articles)) {
    $__ids = array_map(fn($a) => (int)$a['id'], $articles);
    $GLOBALS['__nf_saved_ids'] = array_flip(user_bookmark_ids_for($viewerId, $__ids));
}

$pageTitleText = $keyword !== '' ? '#' . $keyword : 'الموضوعات الأكثر تداولاً';
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title><?php echo e($pageTitleText); ?> — <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<?php
    require_once __DIR__ . '/includes/seo.php';
    $topicCanonical = SITE_URL . ($keyword !== '' ? '/topic/' . rawurlencode($keyword) : '/topic/');
    $topicDesc      = $keyword !== ''
        ? ('كل الأخبار المتعلقة بـ ' . $keyword . ' على ' . getSetting('site_name', SITE_NAME))
        : 'تصفّح الموضوعات الأكثر تداولاً على ' . getSetting('site_name', SITE_NAME);
    $topicImage     = !empty($articles[0]['image_url']) ? $articles[0]['image_url'] : '';
    render_list_seo($pageTitleText, $topicDesc, $topicCanonical, $topicImage, 'website');
    if ($keyword !== '') {
        render_breadcrumb([
            ['name' => getSetting('site_name', SITE_NAME), 'url' => SITE_URL . '/'],
            ['name' => 'الموضوعات', 'url' => SITE_URL . '/topic/'],
            ['name' => $keyword],
        ]);
        render_collection_ld($pageTitleText, $topicDesc, $topicCanonical, $articles);
    } else {
        render_breadcrumb([
            ['name' => getSetting('site_name', SITE_NAME), 'url' => SITE_URL . '/'],
            ['name' => 'الموضوعات'],
        ]);
    }
?>
<meta name="description" content="<?php echo e($topicDesc); ?>">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#1a5c5c">
<link rel="alternate" type="application/rss+xml" title="نيوز فيد RSS" href="/rss.xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"></noscript>
<style>
  :root {
    --bg:#faf6ec; --bg2:#fdfaf2; --bg3:#e4e6eb;
    --card:#fff; --border:#e0e3e8;
    --accent:#1a73e8; --accent2:#0d9488; --accent3:#16a34a;
    --red:#dc2626; --text:#1a1a2e; --muted:#6b7280; --muted2:#9ca3af;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; background:var(--bg); color:var(--text); line-height:1.6; }
  a { text-decoration:none; color:inherit; }
  .container { max-width:1400px; margin:0 auto; padding:0 24px; }

  .page-header { padding:32px 0 24px; }
  .page-header-inner { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px; }
  .page-title { display:flex; align-items:center; gap:12px; font-size:26px; font-weight:900; }
  .page-title .line { width:5px; height:32px; border-radius:3px; background:var(--accent2); }
  .page-title .icon { font-size:26px; }
  .page-count { font-size:13px; color:var(--muted); font-weight:600; background:var(--card); border:1px solid var(--border); padding:7px 18px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.04); }

  .topic-cloud { display:flex; flex-wrap:wrap; gap:10px; padding:8px 0 28px; }
  .topic-chip {
    display:inline-flex; align-items:center; gap:8px;
    background:var(--card); border:1px solid var(--border);
    padding:9px 16px; border-radius:999px;
    font-size:14px; font-weight:600; color:var(--text);
    transition:all .2s ease;
    box-shadow:0 1px 3px rgba(0,0,0,.04);
  }
  .topic-chip:hover { background:var(--accent2); color:#fff; border-color:var(--accent2); transform:translateY(-1px); }
  .topic-chip .count { font-size:11px; opacity:.7; font-weight:700; padding:2px 8px; border-radius:999px; background:rgba(0,0,0,.06); }
  .topic-chip:hover .count { background:rgba(255,255,255,.2); opacity:1; }

  .news-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; margin-bottom:32px; }
  .news-card { background:var(--card); border:1px solid var(--border); border-radius:12px; overflow:hidden; transition:all .3s; box-shadow:0 1px 3px rgba(0,0,0,.04); display:block; }
  .news-card:hover { transform:translateY(-5px); box-shadow:0 10px 40px rgba(0,0,0,.12); border-color:rgba(13,148,136,.25); }
  .card-img { height:175px; overflow:hidden; position:relative; background:var(--bg3); }
  .card-img img { width:100%; height:100%; object-fit:cover; transition:transform .5s ease; }
  .news-card:hover .card-img img { transform:scale(1.06); }
  .card-body { padding:16px; }
  .card-cat { font-size:10px; font-weight:700; padding:4px 10px; border-radius:6px; display:inline-block; margin-bottom:10px; letter-spacing:.3px; background:#f0fdfa; color:#0d9488; border:1px solid #99f6e4; }
  .card-title { font-size:15px; font-weight:700; line-height:1.6; margin-bottom:10px; color:var(--text); }
  .card-meta { display:flex; align-items:center; justify-content:space-between; }
  .card-source { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--muted); font-weight:500; }
  .source-dot { width:8px; height:8px; border-radius:50%; }
  .card-time { font-size:11px; color:var(--muted2); font-weight:500; }

  .empty-state { text-align:center; padding:80px 20px; color:var(--muted); }
  .empty-state .icon { font-size:56px; margin-bottom:18px; }
  .empty-state h3 { font-size:20px; margin-bottom:8px; color:var(--text); font-weight:800; }

  .pagination { display:flex; align-items:center; justify-content:center; gap:6px; padding:24px 0 48px; flex-wrap:wrap; }
  .pagination a, .pagination span { min-width:40px; height:40px; display:flex; align-items:center; justify-content:center; border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; padding:0 12px; }
  .pagination a { background:var(--card); border:1px solid var(--border); color:var(--text); box-shadow:0 1px 3px rgba(0,0,0,.04); }
  .pagination a:hover { background:rgba(13,148,136,.08); border-color:var(--accent2); color:var(--accent2); }
  .pagination .current { background:var(--accent2); color:#fff; border:1px solid var(--accent2); }

  @media(max-width:900px) { .news-grid { grid-template-columns:repeat(2,1fr); } .page-title { font-size:22px; } }
  @media(max-width:560px) { .news-grid { grid-template-columns:1fr; } .page-title { font-size:18px; } }
</style>
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<link rel="stylesheet" href="assets/css/user.min.css?v=m1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
</head>
<body>

<?php
$activeType = 'topic';
$activeSlug = $keyword;
$showTicker = false;
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="container">

  <div class="page-header">
    <div class="page-header-inner">
      <div class="page-title">
        <div class="line"></div>
        <span class="icon">🏷️</span>
        <?php echo e($pageTitleText); ?>
      </div>
      <?php if ($keyword !== ''): ?>
        <div class="page-count"><?php echo number_format($totalCount); ?> خبر</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($keyword === ''): ?>
    <!-- BROWSE MODE: popular topics cloud -->
    <?php if (empty($popular)): ?>
      <div class="empty-state">
        <div class="icon">🏷️</div>
        <h3>لا توجد موضوعات بعد</h3>
        <p>سيظهر المحتوى هنا بعد أن يلخّص الذكاء الاصطناعي عدداً كافياً من الأخبار.</p>
      </div>
    <?php else: ?>
      <div class="topic-cloud">
        <?php foreach ($popular as $kw => $cnt): ?>
          <a href="<?php echo e(topicUrl($kw)); ?>" class="topic-chip">
            <span><?php echo e($kw); ?></span>
            <span class="count"><?php echo (int)$cnt; ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif (empty($articles)): ?>
    <!-- KEYWORD MODE: nothing matched -->
    <div class="empty-state">
      <div class="icon">🔍</div>
      <h3>لا توجد أخبار لهذا الموضوع</h3>
      <p>جرّب موضوعاً آخر من <a href="/topic/" style="color:var(--accent2);font-weight:700;">قائمة الموضوعات</a>.</p>
    </div>

  <?php else: ?>
    <div class="news-grid">
      <?php foreach ($articles as $article): ?>
        <?php $__sid = (int)($article['id'] ?? 0); $__ss = !empty($GLOBALS['__nf_saved_ids']) && isset($GLOBALS['__nf_saved_ids'][$__sid]); ?>
        <a class="news-card" href="<?php echo articleUrl($article); ?>">
          <button type="button" class="nf-bookmark-btn <?php echo $__ss ? 'saved' : ''; ?>" title="<?php echo $__ss ? 'إزالة من المحفوظات' : 'حفظ'; ?>" data-save-id="<?php echo $__sid; ?>" onclick="event.preventDefault(); event.stopPropagation(); NF.toggleSave(this)">🔖</button>
          <div class="card-img">
            <?php echo responsiveImg($article['image_url'] ?? placeholderImage(400,300), $article['title'], '(max-width:600px) 100vw, 400px', [320, 400, 640]); ?>
          </div>
          <div class="card-body">
            <span class="card-cat"><?php echo e($article['cat_name'] ?? ''); ?></span>
            <div class="card-title"><?php echo e(mb_strlen($article['title']) > 80 ? mb_substr($article['title'], 0, 80) . '...' : $article['title']); ?></div>
            <div class="card-meta">
              <div class="card-source">
                <span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#0d9488'); ?>"></span>
                <?php echo e($article['source_name'] ?? ''); ?>
              </div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php
          $range = 2;
          $startPage = max(1, $page - $range);
          $endPage   = min($totalPages, $page + $range);
        ?>
        <?php if ($page > 1): ?><a href="<?php echo e(topicPageUrl($keyword, $page - 1)); ?>">→ السابق</a><?php endif; ?>
        <?php if ($startPage > 1): ?><a href="<?php echo e(topicPageUrl($keyword, 1)); ?>">1</a><?php if ($startPage > 2): ?><span>...</span><?php endif; endif; ?>
        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
          <?php if ($i === $page): ?><span class="current"><?php echo $i; ?></span><?php else: ?><a href="<?php echo e(topicPageUrl($keyword, $i)); ?>"><?php echo $i; ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($endPage < $totalPages): ?><?php if ($endPage < $totalPages - 1): ?><span>...</span><?php endif; ?><a href="<?php echo e(topicPageUrl($keyword, $totalPages)); ?>"><?php echo $totalPages; ?></a><?php endif; ?>
        <?php if ($page < $totalPages): ?><a href="<?php echo e(topicPageUrl($keyword, $page + 1)); ?>">التالي ←</a><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</div>

<div class="nf-toast" id="nfToast"></div>
<script src="assets/js/user.min.js?v=m1" defer></script>
</body>
</html>
