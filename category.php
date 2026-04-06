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
<title><?php echo e($pageIcon . ' ' . $pageTitle); ?> — <?php echo SITE_NAME; ?></title>
<style>
  :root {
    --bg: #f3ede6;
    --bg2: #faf6f1;
    --bg3: #ede6dd;
    --card: #ffffff;
    --border: #e0d6ca;
    --accent: #5a85b0;
    --accent2: #4a9b8e;
    --accent3: #6aab87;
    --red: #b05a5a;
    --text: #2c3040;
    --muted: #7a7060;
    --muted2: #a89f93;
    --gold: #a0823a;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Segoe UI',Tahoma,Arial,sans-serif; background:var(--bg); color:var(--text); overflow-x:hidden; }

  /* SCROLLBAR */
  ::-webkit-scrollbar { width:6px; }
  ::-webkit-scrollbar-track { background:var(--bg3); }
  ::-webkit-scrollbar-thumb { background:#b8c8d8; border-radius:3px; }

  /* HEADER */
  header {
    background:linear-gradient(135deg,#faf6f1 0%,#f3ede6 50%,#faf6f1 100%);
    padding:14px 24px;
    display:flex; align-items:center; justify-content:space-between;
    border-bottom:2px solid var(--border);
    position:sticky; top:0; z-index:1000;
    box-shadow:0 2px 16px rgba(0,0,0,.08);
  }
  .logo { display:flex; align-items:center; gap:12px; text-decoration:none; }
  .logo-icon {
    width:44px; height:44px; border-radius:12px;
    background:linear-gradient(135deg,#5a85b0,#3d6690);
    display:flex; align-items:center; justify-content:center;
    font-size:22px; font-weight:900; color:#fff;
    box-shadow:0 2px 12px rgba(90,133,176,.3);
  }
  .logo-text { font-size:22px; font-weight:800; color:var(--text); letter-spacing:-0.5px; }
  .logo-text span { color:var(--accent); }
  .logo-sub { font-size:10px; color:var(--muted); margin-top:-2px; }

  /* NAV */
  nav { display:flex; gap:4px; align-items:center; }
  nav a {
    padding:7px 14px; border-radius:8px; text-decoration:none;
    color:var(--muted); font-size:13px; font-weight:500;
    transition:all .2s; white-space:nowrap;
  }
  nav a:hover, nav a.active { background:rgba(90,133,176,.12); color:var(--accent); }
  nav a.breaking { background:rgba(176,90,90,.1); color:#b05a5a; animation:breakingPulse 2s infinite; }
  nav a.breaking.active { background:rgba(176,90,90,.2); color:#b05a5a; }
  @keyframes breakingPulse { 0%,100%{background:rgba(176,90,90,.08)} 50%{background:rgba(176,90,90,.16)} }

  /* CONTAINER */
  .container { max-width:1400px; margin:0 auto; padding:0 20px; }

  /* PAGE HEADER */
  .page-header {
    padding:30px 0 20px;
  }
  .page-header-inner {
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:12px;
  }
  .page-title {
    display:flex; align-items:center; gap:12px;
    font-size:24px; font-weight:800;
  }
  .page-title .line {
    width:5px; height:30px; border-radius:3px;
    background:var(--accent);
  }
  .page-title .icon { font-size:26px; }
  .page-count {
    font-size:13px; color:var(--muted);
    background:var(--card); border:1px solid var(--border);
    padding:6px 16px; border-radius:20px;
  }

  /* NEWS GRID */
  .news-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:28px; }
  .news-card {
    background:var(--card); border:1px solid var(--border);
    border-radius:14px; overflow:hidden; cursor:pointer;
    transition:transform .2s, box-shadow .2s, border-color .2s;
    text-decoration:none; color:inherit; display:block;
  }
  .news-card:hover {
    transform:translateY(-4px);
    box-shadow:0 12px 28px rgba(90,133,176,.15);
    border-color:rgba(90,133,176,.3);
  }
  .card-img { height:160px; overflow:hidden; position:relative; background:var(--bg3); }
  .card-img::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(to bottom,transparent 50%,rgba(0,0,0,.5));
  }
  .card-img img { width:100%; height:100%; object-fit:cover; transition:transform .4s; }
  .news-card:hover .card-img img { transform:scale(1.06); }
  .card-body { padding:14px; }
  .card-cat {
    font-size:10px; font-weight:700; padding:3px 10px; border-radius:20px;
    display:inline-block; margin-bottom:8px; text-transform:uppercase; letter-spacing:.5px;
  }
  .cat-political { background:#fae8e8; color:#8f4040; border:1px solid #f0cccc; }
  .cat-economic { background:#e5f3ec; color:#2e7a50; border:1px solid #c0dece; }
  .cat-sports { background:#e5eef8; color:#2d5e8a; border:1px solid #bcd0e8; }
  .cat-arts { background:#ede8f5; color:#5a3d8a; border:1px solid #d4c8ea; }
  .cat-reports { background:#f5ede0; color:#7a5520; border:1px solid #e0cca8; }
  .cat-media { background:#f5e8f5; color:#7a3d7a; border:1px solid #e0c4e0; }
  .cat-breaking { background:#fae8e8; color:#8f3030; border:1px solid #f0c0c0; }
  .card-title { font-size:14px; font-weight:600; line-height:1.5; margin-bottom:8px; }
  .card-meta { display:flex; align-items:center; justify-content:space-between; }
  .card-source { display:flex; align-items:center; gap:6px; font-size:11px; color:var(--muted); }
  .source-dot { width:8px; height:8px; border-radius:50%; }
  .card-time { font-size:11px; color:var(--muted2); }
  .card-views { font-size:11px; color:var(--muted2); }

  /* EMPTY STATE */
  .empty-state {
    text-align:center; padding:60px 20px;
    color:var(--muted);
  }
  .empty-state .icon { font-size:48px; margin-bottom:16px; }
  .empty-state h3 { font-size:18px; margin-bottom:8px; color:var(--text); }
  .empty-state p { font-size:14px; }

  /* PAGINATION */
  .pagination {
    display:flex; align-items:center; justify-content:center;
    gap:6px; padding:20px 0 40px; flex-wrap:wrap;
  }
  .pagination a, .pagination span {
    min-width:38px; height:38px; display:flex; align-items:center; justify-content:center;
    border-radius:10px; font-size:13px; font-weight:600;
    text-decoration:none; transition:all .2s;
    padding:0 10px;
  }
  .pagination a {
    background:var(--card); border:1px solid var(--border);
    color:var(--text);
  }
  .pagination a:hover {
    background:rgba(90,133,176,.12); border-color:var(--accent);
    color:var(--accent);
  }
  .pagination .current {
    background:var(--accent); color:#fff;
    border:1px solid var(--accent);
  }
  .pagination .dots {
    background:none; border:none; color:var(--muted);
    min-width:auto; padding:0 4px;
  }
  .pagination .prev-next {
    font-size:12px; gap:4px;
  }

  /* FOOTER */
  footer {
    background:#faf6f1; border-top:1px solid var(--border);
    padding:30px 24px; margin-top:30px;
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:16px;
  }
  .footer-logo { font-size:20px; font-weight:800; }
  .footer-logo span { color:var(--accent); }
  .footer-links { display:flex; gap:20px; }
  .footer-links a { font-size:12px; color:var(--muted); text-decoration:none; }
  .footer-links a:hover { color:var(--accent); }
  .footer-copy { font-size:11px; color:var(--muted2); }

  /* RESPONSIVE */
  @media(max-width:900px) {
    .news-grid { grid-template-columns:repeat(2,1fr); }
    nav { display:none; }
    .page-title { font-size:20px; }
  }
  @media(max-width:560px) {
    .news-grid { grid-template-columns:1fr; }
    header { padding:10px 14px; }
    .page-header { padding:20px 0 14px; }
    .page-title { font-size:18px; }
    footer { flex-direction:column; text-align:center; }
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
      <div class="logo-text">نيوز<span>فلو</span></div>
      <div class="logo-sub">مجمع المصادر الإخبارية</div>
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
  <div class="footer-logo">نيوز<span>فلو</span></div>
  <div class="footer-links">
    <a href="#">من نحن</a>
    <a href="#">سياسة الخصوصية</a>
    <a href="#">الشروط والأحكام</a>
    <a href="#">اتصل بنا</a>
    <a href="#">إضافة مصدر</a>
  </div>
  <div class="footer-copy">&copy; 2026 نيوزفلو — جميع الحقوق محفوظة</div>
</footer>

</body>
</html>
