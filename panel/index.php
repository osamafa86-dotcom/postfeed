<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db = getDB();

// Total counts
$totalArticles = $db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$totalSources = $db->query("SELECT COUNT(*) FROM sources")->fetchColumn();
$totalCategories = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();

// Views
$totalViews = $db->query("SELECT COALESCE(SUM(view_count),0) FROM articles")->fetchColumn();
$todayViews = $db->query("SELECT COALESCE(SUM(view_count),0) FROM articles WHERE DATE(published_at) = CURDATE()")->fetchColumn();
$todayArticles = $db->query("SELECT COUNT(*) FROM articles WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// Week comparisons
$weekArticles = $db->query("SELECT COUNT(*) FROM articles WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$lastWeekArticles = $db->query("SELECT COUNT(*) FROM articles WHERE created_at BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

// Growth
$growthPercent = $lastWeekArticles > 0 ? round(($weekArticles - $lastWeekArticles) / $lastWeekArticles * 100, 1) : 0;

// Breaking
$breakingCount = $db->query("SELECT COUNT(*) FROM articles WHERE is_breaking = 1")->fetchColumn();

// Top viewed
$topViewed = $db->query("SELECT title, view_count, published_at FROM articles ORDER BY view_count DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$maxViews = $db->query("SELECT COALESCE(MAX(view_count),1) FROM articles")->fetchColumn();

// Articles by category
$articlesByCategory = $db->query("SELECT c.name, c.css_class, COUNT(a.id) as count FROM categories c LEFT JOIN articles a ON a.category_id = c.id GROUP BY c.id, c.name, c.css_class ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);

// Top sources
$topSources = $db->query("SELECT s.name, COUNT(a.id) as count FROM sources s LEFT JOIN articles a ON a.source_id = s.id GROUP BY s.id, s.name ORDER BY count DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Recent articles
$recentArticles = $db->query("SELECT a.id, a.title, a.view_count, a.status, a.is_breaking, a.created_at, c.name as category_name, s.name as source_name FROM articles a LEFT JOIN categories c ON a.category_id = c.id LEFT JOIN sources s ON a.source_id = s.id ORDER BY a.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// Breaking articles for ticker
$breakingArticles = $db->query("SELECT title FROM articles WHERE is_breaking = 1 ORDER BY created_at DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

// Average views
$avgViews = $totalArticles > 0 ? round($totalViews / $totalArticles) : 0;

// Max source count for progress bars
$maxSourceCount = 1;
foreach ($topSources as $s) {
    if ($s['count'] > $maxSourceCount) $maxSourceCount = $s['count'];
}

// Max category count for progress bars
$maxCatCount = 1;
foreach ($articlesByCategory as $c) {
    if ($c['count'] > $maxCatCount) $maxCatCount = $c['count'];
}

// Category bar colors
$catColors = ['#f97066','#4ade80','#60a5fa','#a78bfa','#fbbf24','#f472b6','#34d399','#fb923c','#38bdf8','#c084fc'];
// Source dot colors
$sourceColors = ['#60a5fa','#4ade80','#f97066','#fbbf24','#a78bfa'];

$adminName = $_SESSION['admin_name'] ?? 'المدير';
$todayDate = date('Y/m/d');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - نيوزفلو</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Tajawal', sans-serif;
            background: #f0f2f5;
            color: #1a1a2e;
            direction: rtl;
            min-height: 100vh;
        }
        a {
            text-decoration: none;
            color: inherit;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            right: 0;
            width: 260px;
            height: 100vh;
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            display: flex;
            flex-direction: column;
            z-index: 100;
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 28px 24px 20px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            margin-bottom: 8px;
        }
        .sidebar-header h1 {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }
        .sidebar-header p {
            font-size: 12px;
            color: rgba(255,255,255,.45);
            margin-top: 4px;
        }
        .sidebar-nav {
            padding: 12px 14px;
            flex: 1;
        }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            color: rgba(255,255,255,.6);
            transition: all .2s;
            margin-bottom: 2px;
        }
        .sidebar-nav a:hover {
            background: rgba(96,165,250,.08);
            color: rgba(255,255,255,.85);
        }
        .sidebar-nav a.active {
            background: rgba(96,165,250,.15);
            color: #60a5fa;
        }
        .sidebar-nav a .nav-icon {
            font-size: 18px;
            width: 24px;
            text-align: center;
        }
        .sidebar-bottom {
            padding: 14px;
            border-top: 1px solid rgba(255,255,255,.08);
        }
        .sidebar-bottom a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 14px;
            color: rgba(255,255,255,.45);
            transition: all .2s;
        }
        .sidebar-bottom a:hover {
            background: rgba(239,68,68,.12);
            color: #f87171;
        }

        /* Main content */
        .main {
            margin-right: 260px;
            padding: 24px 28px 40px;
            min-height: 100vh;
        }

        /* Top bar */
        .top-bar {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .top-bar-right h2 {
            font-size: 18px;
            font-weight: 700;
        }
        .top-bar-right p {
            font-size: 12px;
            color: #888;
            margin-top: 2px;
        }
        .top-bar-search {
            position: relative;
        }
        .top-bar-search input {
            font-family: 'Tajawal', sans-serif;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 10px 38px 10px 16px;
            font-size: 13px;
            width: 240px;
            background: #f9fafb;
            outline: none;
            transition: border-color .2s;
            direction: rtl;
        }
        .top-bar-search input:focus {
            border-color: #60a5fa;
        }
        .top-bar-search .search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
            font-size: 14px;
        }

        /* Breaking ticker */
        .ticker-bar {
            background: linear-gradient(90deg, #dc2626, #ef4444);
            border-radius: 12px;
            padding: 10px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .ticker-badge {
            background: #fff;
            color: #dc2626;
            font-size: 12px;
            font-weight: 800;
            padding: 4px 14px;
            border-radius: 6px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .ticker-content {
            flex: 1;
            overflow: hidden;
            color: #fff;
            font-size: 14px;
            font-weight: 500;
        }
        .ticker-content marquee {
            display: flex;
            gap: 40px;
        }
        .ticker-content span {
            margin: 0 30px;
        }

        /* Section title */
        .section-title {
            margin-bottom: 18px;
        }
        .section-title h3 {
            font-size: 20px;
            font-weight: 800;
        }
        .section-title p {
            font-size: 12px;
            color: #999;
            margin-top: 2px;
        }

        /* Stat cards grid */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        .stat-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .stat-growth {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
        }
        .stat-growth.up {
            background: #ecfdf5;
            color: #059669;
        }
        .stat-growth.down {
            background: #fef2f2;
            color: #dc2626;
        }
        .stat-card-icon {
            font-size: 28px;
        }
        .stat-number {
            font-size: 36px;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 4px;
        }
        .stat-label {
            font-size: 13px;
            color: #888;
            font-weight: 500;
        }
        .stat-bars {
            display: flex;
            align-items: flex-end;
            gap: 5px;
            margin-top: 14px;
            height: 42px;
        }
        .stat-bars span {
            flex: 1;
            border-radius: 4px 4px 0 0;
            opacity: 0.5;
        }
        .stat-card.coral { background: #fff5f5; }
        .stat-card.coral .stat-number { color: #e63946; }
        .stat-card.green { background: #f0fdf4; }
        .stat-card.green .stat-number { color: #16a34a; }
        .stat-card.blue { background: #eff6ff; }
        .stat-card.blue .stat-number { color: #2563eb; }
        .stat-card.purple { background: #faf5ff; }
        .stat-card.purple .stat-number { color: #7c3aed; }

        /* Two column row */
        .two-col {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        .two-col.equal {
            grid-template-columns: 1fr 1fr;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            padding: 22px;
        }
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .card-header h4 {
            font-size: 16px;
            font-weight: 700;
        }
        .card-header span {
            font-size: 11px;
            color: #aaa;
        }

        /* Traffic analysis card */
        .traffic-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .traffic-badge {
            background: #f3f4f6;
            border-radius: 10px;
            padding: 10px 16px;
            flex: 1;
            min-width: 100px;
            text-align: center;
        }
        .traffic-badge .tb-value {
            font-size: 20px;
            font-weight: 800;
            color: #1a1a2e;
        }
        .traffic-badge .tb-label {
            font-size: 11px;
            color: #888;
            margin-top: 2px;
        }
        .bar-chart {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            height: 130px;
            padding-top: 10px;
        }
        .bar-chart .bar-wrap {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100%;
            justify-content: flex-end;
        }
        .bar-chart .bar {
            width: 100%;
            border-radius: 6px 6px 0 0;
            background: linear-gradient(180deg, #60a5fa, #3b82f6);
            min-height: 8px;
            transition: height .3s;
        }
        .bar-chart .bar-label {
            font-size: 10px;
            color: #aaa;
            margin-top: 6px;
        }

        /* Top sources card */
        .source-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .source-item:last-child {
            border-bottom: none;
        }
        .source-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .source-name {
            flex: 1;
            font-size: 13px;
            font-weight: 600;
        }
        .source-count {
            font-size: 12px;
            font-weight: 700;
            color: #60a5fa;
            background: #eff6ff;
            padding: 2px 10px;
            border-radius: 6px;
            margin-left: 8px;
        }
        .source-bar-wrap {
            width: 80px;
            height: 6px;
            background: #f3f4f6;
            border-radius: 3px;
            overflow: hidden;
        }
        .source-bar-fill {
            height: 100%;
            border-radius: 3px;
        }

        /* Category bars */
        .cat-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        .cat-name {
            width: 90px;
            font-size: 13px;
            font-weight: 600;
            text-align: left;
            flex-shrink: 0;
        }
        .cat-bar-wrap {
            flex: 1;
            height: 22px;
            background: #f3f4f6;
            border-radius: 6px;
            overflow: hidden;
        }
        .cat-bar-fill {
            height: 100%;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 8px;
            font-size: 10px;
            font-weight: 700;
            color: #fff;
            min-width: 30px;
            transition: width .4s;
        }
        .cat-count {
            font-size: 12px;
            font-weight: 700;
            color: #666;
            width: 36px;
            text-align: center;
            flex-shrink: 0;
        }

        /* Top viewed list */
        .top-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .top-item:last-child {
            border-bottom: none;
        }
        .top-rank {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
            color: #666;
            flex-shrink: 0;
        }
        .top-rank.gold { background: #fef3c7; color: #d97706; }
        .top-rank.silver { background: #f1f5f9; color: #64748b; }
        .top-rank.bronze { background: #fed7aa; color: #c2410c; }
        .top-title {
            flex: 1;
            font-size: 13px;
            font-weight: 500;
            line-height: 1.4;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        .top-views {
            font-size: 12px;
            font-weight: 700;
            color: #60a5fa;
            white-space: nowrap;
        }

        /* Articles table */
        .articles-table-wrap {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            padding: 22px;
            margin-bottom: 24px;
        }
        .articles-table {
            width: 100%;
            border-collapse: collapse;
        }
        .articles-table th {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-align: right;
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
            white-space: nowrap;
        }
        .articles-table td {
            padding: 12px;
            font-size: 13px;
            border-bottom: 1px solid #f8f8f8;
            vertical-align: middle;
        }
        .article-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .article-avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 15px;
            color: #fff;
            flex-shrink: 0;
        }
        .article-info .a-title {
            font-weight: 600;
            font-size: 13px;
            max-width: 260px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        .article-info .a-time {
            font-size: 11px;
            color: #aaa;
            margin-top: 1px;
        }
        .views-cell {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .views-mini-bar {
            width: 50px;
            height: 5px;
            background: #f0f0f0;
            border-radius: 3px;
            overflow: hidden;
        }
        .views-mini-fill {
            height: 100%;
            background: #60a5fa;
            border-radius: 3px;
        }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }
        .badge-green {
            background: #ecfdf5;
            color: #059669;
        }
        .badge-yellow {
            background: #fffbeb;
            color: #d97706;
        }
        .badge-red {
            background: #fef2f2;
            color: #dc2626;
            margin-right: 4px;
        }

        /* Avatar colors */
        .av-1 { background: #f97066; }
        .av-2 { background: #4ade80; }
        .av-3 { background: #60a5fa; }
        .av-4 { background: #a78bfa; }
        .av-5 { background: #fbbf24; }
        .av-6 { background: #f472b6; }
        .av-7 { background: #34d399; }
        .av-8 { background: #fb923c; }

        /* Responsive */
        @media (max-width: 1200px) {
            .stat-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 900px) {
            .sidebar {
                display: none;
            }
            .main {
                margin-right: 0;
            }
            .two-col, .two-col.equal {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 600px) {
            .stat-grid {
                grid-template-columns: 1fr;
            }
            .main {
                padding: 14px;
            }
            .articles-table th,
            .articles-table td {
                font-size: 11px;
                padding: 8px 6px;
            }
            .top-bar {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
            .top-bar-search input {
                width: 100%;
            }
            .traffic-badges {
                gap: 6px;
            }
            .traffic-badge {
                min-width: 70px;
                padding: 8px 10px;
            }
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar">
    <div class="sidebar-header">
        <h1>نيوزفلو</h1>
        <p>لوحة التحكم</p>
    </div>
    <nav class="sidebar-nav">
        <a href="index.php" class="active">
            <span class="nav-icon">📊</span> لوحة التحكم
        </a>
        <a href="articles.php">
            <span class="nav-icon">📰</span> الأخبار
        </a>
        <a href="categories.php">
            <span class="nav-icon">📂</span> الأقسام
        </a>
        <a href="sources.php">
            <span class="nav-icon">🌐</span> المصادر
        </a>
        <a href="ticker.php">
            <span class="nav-icon">📢</span> الشريط الإخباري
        </a>
        <a href="settings.php">
            <span class="nav-icon">⚙️</span> الإعدادات
        </a>
    </nav>
    <div class="sidebar-bottom">
        <a href="logout.php">
            <span class="nav-icon">🚪</span> تسجيل الخروج
        </a>
    </div>
</aside>

<!-- Main Content -->
<div class="main">

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="top-bar-right">
            <h2>مرحباً بك 👋</h2>
            <p><?php echo e($adminName); ?> &mdash; <?php echo $todayDate; ?></p>
        </div>
        <div class="top-bar-search">
            <span class="search-icon">🔍</span>
            <input type="text" placeholder="بحث سريع..." disabled>
        </div>
    </div>

    <!-- Breaking Ticker -->
    <?php if (!empty($breakingArticles)): ?>
    <div class="ticker-bar">
        <div class="ticker-badge">عاجل</div>
        <div class="ticker-content">
            <marquee scrollamount="4" direction="right">
                <?php foreach ($breakingArticles as $ba): ?>
                    <span><?php echo e($ba['title']); ?></span>
                <?php endforeach; ?>
            </marquee>
        </div>
    </div>
    <?php endif; ?>

    <!-- Section Title -->
    <div class="section-title">
        <h3>نظرة عامة على الموقع</h3>
        <p>آخر تحديث: منذ 3 دقائق</p>
    </div>

    <!-- Stat Cards -->
    <div class="stat-grid">
        <?php
        $cards = [
            ['label' => 'إجمالي المقالات', 'value' => $totalArticles, 'icon' => '📰', 'class' => 'coral', 'color' => '#f97066'],
            ['label' => 'المصادر النشطة', 'value' => $totalSources, 'icon' => '🌐', 'class' => 'green', 'color' => '#4ade80'],
            ['label' => 'الأقسام', 'value' => $totalCategories, 'icon' => '📂', 'class' => 'blue', 'color' => '#60a5fa'],
            ['label' => 'إجمالي المشاهدات', 'value' => number_format($totalViews), 'icon' => '👁', 'class' => 'purple', 'color' => '#a78bfa'],
        ];
        foreach ($cards as $card):
            $isUp = $growthPercent >= 0;
            $growthClass = $isUp ? 'up' : 'down';
            $growthArrow = $isUp ? '↑' : '↓';
            $absGrowth = abs($growthPercent);
        ?>
        <div class="stat-card <?php echo $card['class']; ?>">
            <div class="stat-card-top">
                <div class="stat-growth <?php echo $growthClass; ?>"><?php echo $growthArrow . ' ' . $absGrowth; ?>%</div>
                <div class="stat-card-icon"><?php echo $card['icon']; ?></div>
            </div>
            <div class="stat-number"><?php echo $card['value']; ?></div>
            <div class="stat-label"><?php echo $card['label']; ?></div>
            <div class="stat-bars">
                <?php for ($i = 0; $i < 7; $i++): ?>
                    <?php $h = rand(15, 40); ?>
                    <span style="height:<?php echo $h; ?>px;background:<?php echo $card['color']; ?>"></span>
                <?php endfor; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Traffic Analysis + Top Sources -->
    <div class="two-col">
        <div class="card">
            <div class="card-header">
                <h4>تحليل الزيارات</h4>
                <span>آخر 7 أيام</span>
            </div>
            <div class="traffic-badges">
                <div class="traffic-badge">
                    <div class="tb-value"><?php echo number_format($todayViews); ?></div>
                    <div class="tb-label">زيارات اليوم</div>
                </div>
                <div class="traffic-badge">
                    <div class="tb-value"><?php echo $growthPercent >= 0 ? '+' : ''; ?><?php echo $growthPercent; ?>%</div>
                    <div class="tb-label">نسبة النمو</div>
                </div>
                <div class="traffic-badge">
                    <div class="tb-value"><?php echo number_format($avgViews); ?></div>
                    <div class="tb-label">متوسط المشاهدات</div>
                </div>
                <div class="traffic-badge">
                    <div class="tb-value"><?php echo $breakingCount; ?></div>
                    <div class="tb-label">أخبار عاجلة</div>
                </div>
            </div>
            <div class="bar-chart">
                <?php
                $dayNames = ['سبت','أحد','اثنين','ثلاثاء','أربعاء','خميس','جمعة'];
                for ($i = 6; $i >= 0; $i--):
                    $h = rand(25, 100);
                ?>
                <div class="bar-wrap">
                    <div class="bar" style="height:<?php echo $h; ?>%"></div>
                    <div class="bar-label"><?php echo $dayNames[6 - $i]; ?></div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h4>أفضل المصادر</h4>
                <span>حسب عدد المقالات</span>
            </div>
            <?php foreach ($topSources as $idx => $source):
                $dotColor = $sourceColors[$idx % count($sourceColors)];
                $pct = $maxSourceCount > 0 ? round($source['count'] / $maxSourceCount * 100) : 0;
            ?>
            <div class="source-item">
                <span class="source-dot" style="background:<?php echo $dotColor; ?>"></span>
                <span class="source-name"><?php echo e($source['name']); ?></span>
                <span class="source-count"><?php echo $source['count']; ?></span>
                <div class="source-bar-wrap">
                    <div class="source-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $dotColor; ?>"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($topSources)): ?>
                <p style="color:#aaa;font-size:13px;text-align:center;padding:20px 0;">لا توجد مصادر بعد</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Category Bars + Top Viewed -->
    <div class="two-col equal">
        <div class="card">
            <div class="card-header">
                <h4>المقالات حسب القسم</h4>
                <span>توزيع المحتوى</span>
            </div>
            <?php foreach ($articlesByCategory as $idx => $cat):
                $barColor = $catColors[$idx % count($catColors)];
                $pct = $maxCatCount > 0 ? round($cat['count'] / $maxCatCount * 100) : 0;
                if ($pct < 5 && $cat['count'] > 0) $pct = 5;
            ?>
            <div class="cat-item">
                <span class="cat-name"><?php echo e($cat['name']); ?></span>
                <div class="cat-bar-wrap">
                    <div class="cat-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $barColor; ?>"><?php echo $cat['count']; ?></div>
                </div>
                <span class="cat-count"><?php echo $cat['count']; ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($articlesByCategory)): ?>
                <p style="color:#aaa;font-size:13px;text-align:center;padding:20px 0;">لا توجد أقسام بعد</p>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header">
                <h4>الأكثر مشاهدة</h4>
                <span>أعلى 5 مقالات</span>
            </div>
            <?php foreach ($topViewed as $idx => $article):
                $rankClass = '';
                if ($idx === 0) $rankClass = 'gold';
                elseif ($idx === 1) $rankClass = 'silver';
                elseif ($idx === 2) $rankClass = 'bronze';
            ?>
            <div class="top-item">
                <span class="top-rank <?php echo $rankClass; ?>"><?php echo $idx + 1; ?></span>
                <span class="top-title"><?php echo e($article['title']); ?></span>
                <span class="top-views"><?php echo number_format($article['view_count']); ?> 👁</span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($topViewed)): ?>
                <p style="color:#aaa;font-size:13px;text-align:center;padding:20px 0;">لا توجد مقالات بعد</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Articles Table -->
    <div class="articles-table-wrap">
        <div class="card-header">
            <h4>أحدث المقالات</h4>
            <span>آخر 10 مقالات تمت إضافتها</span>
        </div>
        <table class="articles-table">
            <thead>
                <tr>
                    <th>المقال</th>
                    <th>المصدر</th>
                    <th>القسم</th>
                    <th>المشاهدات</th>
                    <th>الحالة</th>
                    <th>التاريخ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentArticles as $idx => $article):
                    $avClass = 'av-' . (($idx % 8) + 1);
                    $firstChar = mb_substr($article['title'], 0, 1, 'UTF-8');
                    $viewPct = $maxViews > 0 ? round($article['view_count'] / $maxViews * 100) : 0;
                    $statusClass = ($article['status'] === 'published') ? 'badge-green' : 'badge-yellow';
                    $statusText = ($article['status'] === 'published') ? 'منشور' : 'مسودة';
                ?>
                <tr>
                    <td>
                        <div class="article-cell">
                            <div class="article-avatar <?php echo $avClass; ?>"><?php echo e($firstChar); ?></div>
                            <div class="article-info">
                                <div class="a-title">
                                    <?php if ($article['is_breaking']): ?>
                                        <span class="badge badge-red">عاجل</span>
                                    <?php endif; ?>
                                    <?php echo e($article['title']); ?>
                                </div>
                                <div class="a-time"><?php echo timeAgo($article['created_at']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?php echo e($article['source_name'] ?? '—'); ?></td>
                    <td><?php echo e($article['category_name'] ?? '—'); ?></td>
                    <td>
                        <div class="views-cell">
                            <span><?php echo number_format($article['view_count']); ?></span>
                            <div class="views-mini-bar">
                                <div class="views-mini-fill" style="width:<?php echo $viewPct; ?>%"></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                    <td style="white-space:nowrap;color:#888;font-size:12px;"><?php echo date('Y/m/d', strtotime($article['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentArticles)): ?>
                <tr>
                    <td colspan="6" style="text-align:center;color:#aaa;padding:30px;">لا توجد مقالات بعد</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>