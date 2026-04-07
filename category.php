<?php
/**
 * نيوزفلو - صفحة التصنيف
 * عرض أخبار تصنيف معين مع التصفح بالصفحات
 */

require_once __DIR__ . '/includes/functions.php';

// معالجة المعاملات
$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// خريطة التصنيفات
$categoryMap = [
    'breaking'  => ['name' => 'عاجل',   'icon' => '🔴', 'css' => 'cat-breaking'],
    'political' => ['name' => 'سياسة',  'icon' => '🏛',  'css' => 'cat-political'],
    'economy'   => ['name' => 'اقتصاد', 'icon' => '💹', 'css' => 'cat-economic'],
    'sports'    => ['name' => 'رياضة',  'icon' => '⚽', 'css' => 'cat-sports'],
    'arts'      => ['name' => 'فنون',   'icon' => '🎨', 'css' => 'cat-arts'],
    'media'     => ['name' => 'ميديا',  'icon' => '🎥', 'css' => 'cat-media'],
    'reports'   => ['name' => 'تقارير', 'icon' => '📊', 'css' => 'cat-reports'],
];

$db = getDB();
$articles = [];
$totalCount = 0;
$pageTitle = '';
$pageIcon = '';
$pageCss = '';

if ($type === 'breaking') {
    // أخبار عاجلة
    $pageTitle = 'عاجل';
    $pageIcon = '🔴';
    $pageCss = 'cat-breaking';

    $countStmt = $db->query("SELECT COUNT(*) FROM articles WHERE is_breaking = 1 AND status = 'published'");
    $totalCount = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                           s.name as source_name, s.logo_color
                           FROM articles a
                           LEFT JOIN categories c ON a.category_id = c.id
                           LEFT JOIN sources s ON a.source_id = s.id
                           WHERE a.is_breaking = 1 AND a.status = 'published'
                           ORDER BY a.published_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$perPage, $offset]);
    $articles = $stmt->fetchAll();

} elseif ($type === 'latest') {
    // آخر الأخبار
    $pageTitle = 'آخر الأخبار';
    $pageIcon = '🕐';
    $pageCss = '';

    $countStmt = $db->query("SELECT COUNT(*) FROM articles WHERE status = 'published'");
    $totalCount = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                           s.name as source_name, s.logo_color
                           FROM articles a
                           LEFT JOIN categories c ON a.category_id = c.id
                           LEFT JOIN sources s ON a.source_id = s.id
                           WHERE a.status = 'published'
                           ORDER BY a.published_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$perPage, $offset]);
    $articles = $stmt->fetchAll();

} elseif ($slug !== '') {
    // تصنيف عادي
    if (isset($categoryMap[$slug])) {
        $pageTitle = $categoryMap[$slug]['name'];
        $pageIcon = $categoryMap[$slug]['icon'];
        $pageCss = $categoryMap[$slug]['css'];
    } else {
        $pageTitle = $slug;
        $pageIcon = '📁';
        $pageCss = '';
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM articles a
                                LEFT JOIN categories c ON a.category_id = c.id
                                WHERE c.slug = ? AND a.status = 'published'");
    $countStmt->execute([$slug]);
    $totalCount = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                           s.name as source_name, s.logo_color
                           FROM articles a
                           LEFT JOIN categories c ON a.category_id = c.id
                           LEFT JOIN sources s ON a.source_id = s.id
                           WHERE c.slug = ? AND a.status = 'published'
                           ORDER BY a.published_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$slug, $perPage, $offset]);
    $articles = $stmt->fetchAll();

} else {
    // لم يتم تحديد تصنيف - إعادة التوجيه للرئيسية
    header('Location: index.php');
    exit;
}

$totalPages = max(1, ceil($totalCount / $perPage));
$categories = getCategories();

// بناء رابط الصفحة الحالية للتنقل
function buildPageUrl($pageNum) {
    $params = $_GET;
    $params['page'] = $pageNum;
    return 'category.php?' . http_build_query($params);
}

?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($pageIcon . ' ' . $pageTitle); ?> — <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="description" content="<?php echo e('أحدث الأخبار في قسم ' . $pageTitle . ' من ' . getSetting('site_name', SITE_NAME)); ?>">
<link rel="canonical" href="<?php echo e(SITE_URL . '/category.php?slug=' . urlencode($_GET['slug'] ?? '')); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #faf6ec;
    --bg2: #fdfaf2;
    --bg3: #e4e6eb;
    --card: #ffffff;
    --border: #e0e3e8;
    --accent: #1a73e8;
    --accent2: #0d9488;
    --accent3: #16a34a;
    --red: #dc2626;
    --text: #1a1a2e;
    --muted: #6b7280;
    --muted2: #9ca3af;
    --gold: #d97706;
    --header-bg: #1a1a2e;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; background:var(--bg); color:var(--text); overflow-x:hidden; line-height:1.6; }
  a { text-decoration:none; color:inherit; }

  ::-webkit-scrollbar { width:6px; }
  ::-webkit-scrollbar-track { background:transparent; }
  ::-webkit-scrollbar-thumb { background:#c1c5cc; border-radius:3px; }

  /* HEADER */
  header {
    background:var(--header-bg);
    padding:0 24px;
    display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0; z-index:1000;
    height:64px;
    box-shadow:0 4px 20px rgba(0,0,0,.15);
  }
  .logo { display:flex; align-items:center; gap:12px; text-decoration:none; }
  .logo-icon {
    width:42px; height:42px; border-radius:10px;
    background:linear-gradient(135deg,#1a73e8,#4f46e5);
    display:flex; align-items:center; justify-content:center;
    font-size:20px; font-weight:900; color:#fff;
    box-shadow:0 4px 12px rgba(26,115,232,.4);
  }
  .logo-text { font-size:24px; font-weight:900; color:#fff; letter-spacing:-0.5px; }
  .logo-text span { color:#60a5fa; }
  .logo-sub { font-size:10px; color:rgba(255,255,255,.45); margin-top:-3px; }

  nav { display:flex; gap:2px; align-items:center; height:100%; }
  nav a {
    padding:8px 16px; border-radius:8px; text-decoration:none;
    color:rgba(255,255,255,.6); font-size:13px; font-weight:500;
    transition:all .2s; white-space:nowrap;
  }
  nav a:hover { background:rgba(255,255,255,.08); color:rgba(255,255,255,.9); }
  nav a.active { background:rgba(26,115,232,.25); color:#60a5fa; font-weight:700; }
  nav a.breaking { background:rgba(220,38,38,.15); color:#fca5a5; animation:breakingPulse 2.5s ease infinite; }
  nav a.breaking.active { background:rgba(220,38,38,.25); color:#fca5a5; }
  @keyframes breakingPulse { 0%,100%{background:rgba(220,38,38,.1)} 50%{background:rgba(220,38,38,.22)} }

  .container { max-width:1400px; margin:0 auto; padding:0 24px; }

  .page-header { padding:32px 0 24px; }
  .page-header-inner {
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:14px;
  }
  .page-title {
    display:flex; align-items:center; gap:12px;
    font-size:26px; font-weight:900;
  }
  .page-title .line { width:5px; height:32px; border-radius:3px; background:var(--accent); }
  .page-title .icon { font-size:28px; }
  .page-count {
    font-size:13px; color:var(--muted); font-weight:600;
    background:var(--card); border:1px solid var(--border);
    padding:7px 18px; border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.04);
  }

  .news-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; margin-bottom:32px; }
  .news-card {
    background:var(--card); border:1px solid var(--border);
    border-radius:12px; overflow:hidden; cursor:pointer;
    transition:all .3s ease;
    text-decoration:none; color:inherit; display:block;
    box-shadow:0 1px 3px rgba(0,0,0,.04);
  }
  .news-card:hover {
    transform:translateY(-5px);
    box-shadow:0 10px 40px rgba(0,0,0,.12);
    border-color:rgba(26,115,232,.2);
  }
  .card-img { height:175px; overflow:hidden; position:relative; background:var(--bg3); }
  .card-img::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(to bottom,transparent 60%,rgba(0,0,0,.35));
  }
  .card-img img { width:100%; height:100%; object-fit:cover; transition:transform .5s ease; }
  .news-card:hover .card-img img { transform:scale(1.06); }
  .card-body { padding:16px; }
  .card-cat {
    font-size:10px; font-weight:700; padding:4px 10px; border-radius:6px;
    display:inline-block; margin-bottom:10px; letter-spacing:.3px;
  }
  .cat-political { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
  .cat-economic { background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0; }
  .cat-sports { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
  .cat-arts { background:#faf5ff; color:#7c3aed; border:1px solid #ddd6fe; }
  .cat-reports { background:#fffbeb; color:#b45309; border:1px solid #fde68a; }
  .cat-media { background:#fdf4ff; color:#a21caf; border:1px solid #f0abfc; }
  .cat-breaking { background:#fef2f2; color:#dc2626; border:1px solid #fca5a5; }
  .card-title { font-size:15px; font-weight:700; line-height:1.6; margin-bottom:10px; color:var(--text); }
  .card-meta { display:flex; align-items:center; justify-content:space-between; }
  .card-source { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--muted); font-weight:500; }
  .source-dot { width:8px; height:8px; border-radius:50%; }
  .card-time { font-size:11px; color:var(--muted2); font-weight:500; }

  .empty-state {
    text-align:center; padding:80px 20px; color:var(--muted);
  }
  .empty-state .icon { font-size:56px; margin-bottom:18px; }
  .empty-state h3 { font-size:20px; margin-bottom:8px; color:var(--text); font-weight:800; }
  .empty-state p { font-size:14px; }

  .pagination {
    display:flex; align-items:center; justify-content:center;
    gap:6px; padding:24px 0 48px; flex-wrap:wrap;
  }
  .pagination a, .pagination span {
    min-width:40px; height:40px; display:flex; align-items:center; justify-content:center;
    border-radius:10px; font-size:14px; font-weight:600;
    text-decoration:none; transition:all .2s;
    padding:0 12px;
  }
  .pagination a {
    background:var(--card); border:1px solid var(--border);
    color:var(--text); box-shadow:0 1px 3px rgba(0,0,0,.04);
  }
  .pagination a:hover {
    background:rgba(26,115,232,.08); border-color:var(--accent);
    color:var(--accent);
  }
  .pagination .current {
    background:var(--accent); color:#fff;
    border:1px solid var(--accent);
    box-shadow:0 4px 12px rgba(26,115,232,.3);
  }
  .pagination .dots { background:none; border:none; color:var(--muted); min-width:auto; padding:0 4px; }
  .pagination .prev-next { font-size:13px; gap:4px; }

  footer {
    background:var(--header-bg);
    padding:32px 24px; margin-top:40px;
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:16px; color:rgba(255,255,255,.5);
  }
  .footer-logo { font-size:22px; font-weight:900; color:#fff; }
  .footer-logo span { color:#60a5fa; }
  .footer-links { display:flex; gap:20px; }
  .footer-links a { font-size:12px; color:rgba(255,255,255,.4); text-decoration:none; transition:color .2s; }
  .footer-links a:hover { color:#60a5fa; }
  .footer-copy { font-size:11px; color:rgba(255,255,255,.3); }

  @media(max-width:900px) {
    .news-grid { grid-template-columns:repeat(2,1fr); }
    nav { display:none; }
    .page-title { font-size:22px; }
  }
  @media(max-width:560px) {
    .news-grid { grid-template-columns:1fr; }
    header { padding:0 14px; height:56px; }
    .page-header { padding:20px 0 16px; }
    .page-title { font-size:18px; }
    footer { flex-direction:column; text-align:center; padding:24px 16px; }
    .footer-links { flex-wrap:wrap; justify-content:center; }
  }
</style>
</head>
<body>

<!-- HEADER -->
<header>
  <a class="logo" href="index.php">
    <div class="logo-icon">N</div>
    <div>
      <div class="logo-text"><?php echo e(getSetting('site_name', SITE_NAME)); ?></div>
      <div class="logo-sub"><?php echo e(getSetting('site_tagline', SITE_TAGLINE)); ?></div>
    </div>
  </a>

  <nav>
    <a href="category.php?type=breaking" class="breaking<?php echo $type === 'breaking' ? ' active' : ''; ?>">🔴 عاجل</a>
    <a href="index.php">الرئيسية</a>
    <a href="category.php?type=latest"<?php echo $type === 'latest' ? ' class="active"' : ''; ?>>آخر الأخبار</a>
    <a href="category.php?slug=political"<?php echo $slug === 'political' ? ' class="active"' : ''; ?>>سياسة</a>
    <a href="category.php?slug=economy"<?php echo $slug === 'economy' ? ' class="active"' : ''; ?>>اقتصاد</a>
    <a href="category.php?slug=sports"<?php echo $slug === 'sports' ? ' class="active"' : ''; ?>>رياضة</a>
    <a href="category.php?slug=arts"<?php echo $slug === 'arts' ? ' class="active"' : ''; ?>>فنون</a>
    <a href="category.php?slug=media"<?php echo $slug === 'media' ? ' class="active"' : ''; ?>>ميديا</a>
    <a href="category.php?slug=reports"<?php echo $slug === 'reports' ? ' class="active"' : ''; ?>>تقارير</a>
  </nav>
</header>

<!-- PAGE CONTENT -->
<div class="container">

  <!-- PAGE HEADER -->
  <div class="page-header">
    <div class="page-header-inner">
      <div class="page-title">
        <div class="line"<?php if ($type === 'breaking' || $slug === 'political') echo ' style="background:var(--red)"'; elseif ($slug === 'economy') echo ' style="background:var(--accent3)"'; elseif ($slug === 'sports') echo ' style="background:var(--accent)"'; elseif ($slug === 'arts') echo ' style="background:#5a3d8a"'; elseif ($slug === 'reports') echo ' style="background:var(--gold)"'; elseif ($slug === 'media') echo ' style="background:#7a3d7a"'; ?>></div>
        <span class="icon"><?php echo $pageIcon; ?></span>
        <?php echo e($pageTitle); ?>
      </div>
      <div class="page-count"><?php echo number_format($totalCount); ?> خبر</div>
    </div>
  </div>

  <?php if (empty($articles)): ?>
    <!-- EMPTY STATE -->
    <div class="empty-state">
      <div class="icon">📭</div>
      <h3>لا توجد أخبار حالياً</h3>
      <p>لم يتم العثور على أخبار في هذا التصنيف بعد.</p>
    </div>
  <?php else: ?>
    <!-- NEWS GRID -->
    <div class="news-grid">
      <?php foreach ($articles as $article): ?>
        <a class="news-card" href="article.php?id=<?php echo (int) $article['id']; ?>">
          <div class="card-img">
            <img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/cat' . $article['id'] . '/400/300'); ?>" alt="<?php echo e($article['title']); ?>">
          </div>
          <div class="card-body">
            <span class="card-cat <?php echo e($article['css_class'] ?? $pageCss); ?>"><?php echo e($article['cat_name'] ?? $pageTitle); ?></span>
            <div class="card-title"><?php echo e(mb_strlen($article['title']) > 80 ? mb_substr($article['title'], 0, 80) . '...' : $article['title']); ?></div>
            <div class="card-meta">
              <div class="card-source">
                <span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span>
                <?php echo e($article['source_name'] ?? ''); ?>
              </div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- PAGINATION -->
    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="<?php echo e(buildPageUrl($page - 1)); ?>" class="prev-next">→ السابق</a>
        <?php endif; ?>

        <?php
        // حساب نطاق الصفحات المعروضة
        $range = 2;
        $startPage = max(1, $page - $range);
        $endPage = min($totalPages, $page + $range);

        if ($startPage > 1): ?>
          <a href="<?php echo e(buildPageUrl(1)); ?>">1</a>
          <?php if ($startPage > 2): ?>
            <span class="dots">...</span>
          <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
          <?php if ($i === $page): ?>
            <span class="current"><?php echo $i; ?></span>
          <?php else: ?>
            <a href="<?php echo e(buildPageUrl($i)); ?>"><?php echo $i; ?></a>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
          <?php if ($endPage < $totalPages - 1): ?>
            <span class="dots">...</span>
          <?php endif; ?>
          <a href="<?php echo e(buildPageUrl($totalPages)); ?>"><?php echo $totalPages; ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
          <a href="<?php echo e(buildPageUrl($page + 1)); ?>" class="prev-next">التالي ←</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</div>

<!-- FOOTER -->
<footer>
  <div class="footer-logo"><?php echo e(getSetting('site_name', SITE_NAME)); ?></div>
  <div class="footer-links">
    <a href="#">من نحن</a>
    <a href="#">سياسة الخصوصية</a>
    <a href="#">الشروط والأحكام</a>
    <a href="#">اتصل بنا</a>
  </div>
  <div class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo e(getSetting('site_name', SITE_NAME)); ?> &mdash; جميع الحقوق محفوظة</div>
</footer>

</body>
</html>
