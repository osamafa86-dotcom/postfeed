<?php
/**
 * نيوزفلو - لوحة التحكم الرئيسية
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db = getDB();

// 1. إجمالي المقالات
$totalArticles = $db->query("SELECT COUNT(*) FROM articles")->fetchColumn();

// 2. إجمالي المصادر
$totalSources = $db->query("SELECT COUNT(*) FROM sources")->fetchColumn();

// 3. إجمالي الأقسام
$totalCategories = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();

// 4. مشاهدات اليوم
$todayViews = $db->query("SELECT COALESCE(SUM(view_count), 0) FROM articles WHERE DATE(published_at) = CURDATE()")->fetchColumn();

// 5. إجمالي المشاهدات
$totalViews = $db->query("SELECT COALESCE(SUM(view_count), 0) FROM articles")->fetchColumn();

// 6. مقالات اليوم
$todayArticles = $db->query("SELECT COUNT(*) FROM articles WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// 7. مقالات هذا الأسبوع
$weekArticles = $db->query("SELECT COUNT(*) FROM articles WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

// 8. الأخبار العاجلة
$breakingCount = $db->query("SELECT COUNT(*) FROM articles WHERE is_breaking = 1")->fetchColumn();

// 9. أكثر 5 مقالات مشاهدة
$topViewed = $db->query("SELECT title, view_count, published_at FROM articles ORDER BY view_count DESC LIMIT 5")->fetchAll();

// 10. المقالات حسب القسم
$articlesByCategory = $db->query("
    SELECT c.name, COUNT(a.id) as count
    FROM categories c
    LEFT JOIN articles a ON c.id = a.category_id
    GROUP BY c.id
    ORDER BY count DESC
")->fetchAll();

// 11. أفضل 5 مصادر
$topSources = $db->query("
    SELECT s.name, COUNT(a.id) as count
    FROM sources s
    LEFT JOIN articles a ON s.id = a.source_id
    GROUP BY s.id
    ORDER BY count DESC
    LIMIT 5
")->fetchAll();

// 12. آخر 10 مقالات
$recentArticles = $db->query("
    SELECT a.id, a.title, a.status, a.is_breaking, a.view_count, a.published_at,
           c.name as cat_name, s.name as source_name
    FROM articles a
    LEFT JOIN categories c ON a.category_id = c.id
    LEFT JOIN sources s ON a.source_id = s.id
    ORDER BY a.created_at DESC LIMIT 10
")->fetchAll();

// حساب أعلى عدد مقالات لقسم (للرسم البياني)
$maxCategoryCount = 0;
foreach ($articlesByCategory as $cat) {
    if ($cat['count'] > $maxCategoryCount) {
        $maxCategoryCount = $cat['count'];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - نيوزفلو</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }

        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 20px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 30px;
        }

        .sidebar-header h2 {
            font-size: 22px;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 12px;
            color: #bdc3c7;
        }

        .nav-menu {
            list-style: none;
        }

        .nav-item {
            margin-bottom: 10px;
        }

        .nav-link {
            display: block;
            padding: 12px 15px;
            color: #ecf0f1;
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.3s;
            font-size: 14px;
        }

        .nav-link:hover {
            background: rgba(90, 133, 176, 0.2);
            color: #5a85b0;
        }

        .nav-link.active {
            background: #5a85b0;
            color: white;
        }

        .logout-section {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .btn-logout {
            display: block;
            width: 100%;
            padding: 12px;
            background: #e74c3c;
            color: white;
            text-align: center;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            transition: background 0.3s;
        }

        .btn-logout:hover {
            background: #c0392b;
        }

        /* Main Content */
        .main-content {
            margin-right: 250px;
            width: calc(100% - 250px);
            padding: 30px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            color: #333;
            font-size: 28px;
        }

        .user-info {
            text-align: center;
        }

        .user-info p {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .user-info strong {
            color: #333;
        }

        /* Stat Cards Row */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            padding: 25px;
            border-radius: 10px;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: -20px;
            left: -20px;
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .stat-card h3 {
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 10px;
            opacity: 0.9;
        }

        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
        }

        .stat-card .sub-info {
            font-size: 12px;
            margin-top: 8px;
            opacity: 0.85;
        }

        .stat-card.gradient-blue {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stat-card.gradient-green {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }

        .stat-card.gradient-orange {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .stat-card.gradient-cyan {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        /* Two Column Row */
        .two-col-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .card-title {
            color: #333;
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }

        /* CSS Bar Chart */
        .bar-chart {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .bar-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .bar-label {
            width: 100px;
            font-size: 13px;
            color: #555;
            text-align: left;
            flex-shrink: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .bar-track {
            flex: 1;
            height: 28px;
            background: #f0f0f0;
            border-radius: 14px;
            overflow: hidden;
            position: relative;
        }

        .bar-fill {
            height: 100%;
            border-radius: 14px;
            transition: width 0.6s ease;
            min-width: 30px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 10px;
            font-size: 12px;
            color: white;
            font-weight: 600;
        }

        .bar-fill.color-1 { background: linear-gradient(90deg, #667eea, #764ba2); }
        .bar-fill.color-2 { background: linear-gradient(90deg, #f093fb, #f5576c); }
        .bar-fill.color-3 { background: linear-gradient(90deg, #4facfe, #00f2fe); }
        .bar-fill.color-4 { background: linear-gradient(90deg, #11998e, #38ef7d); }
        .bar-fill.color-5 { background: linear-gradient(90deg, #fc5c7d, #6a82fb); }
        .bar-fill.color-6 { background: linear-gradient(90deg, #f7971e, #ffd200); }
        .bar-fill.color-7 { background: linear-gradient(90deg, #00c6ff, #0072ff); }
        .bar-fill.color-8 { background: linear-gradient(90deg, #e44d26, #f16529); }

        /* Top Sources List */
        .source-list {
            list-style: none;
        }

        .source-list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .source-list-item:last-child {
            border-bottom: none;
        }

        .source-rank {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #5a85b0;
            color: white;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
            flex-shrink: 0;
        }

        .source-name {
            flex: 1;
            font-size: 14px;
            color: #333;
        }

        .source-count {
            font-size: 14px;
            font-weight: 600;
            color: #5a85b0;
            background: #eef3f8;
            padding: 4px 12px;
            border-radius: 20px;
        }

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .data-table thead {
            background: #f9f9f9;
            border-bottom: 2px solid #eee;
        }

        .data-table th {
            padding: 12px;
            text-align: right;
            color: #666;
            font-weight: 600;
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        .data-table tbody tr:hover {
            background: #f9f9f9;
        }

        .view-count-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #eef3f8;
            color: #5a85b0;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-published {
            background: #d4edda;
            color: #155724;
        }

        .status-draft {
            background: #fff3cd;
            color: #856404;
        }

        .breaking-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #dc3545;
            color: white;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 5px;
        }

        .article-title-link {
            color: #333;
            text-decoration: none;
            font-weight: 500;
        }

        .article-title-link:hover {
            color: #5a85b0;
        }

        .article-meta {
            color: #999;
            font-size: 12px;
        }

        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .quick-action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px 16px;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            text-align: center;
        }

        .quick-action-btn.btn-blue {
            background: #5a85b0;
            color: white;
        }

        .quick-action-btn.btn-blue:hover {
            background: #4a6a95;
        }

        .quick-action-btn.btn-teal {
            background: #11998e;
            color: white;
        }

        .quick-action-btn.btn-teal:hover {
            background: #0d7a71;
        }

        .quick-action-btn.btn-purple {
            background: #764ba2;
            color: white;
        }

        .quick-action-btn.btn-purple:hover {
            background: #5e3a82;
        }

        .quick-action-btn.btn-coral {
            background: #f5576c;
            color: white;
        }

        .quick-action-btn.btn-coral:hover {
            background: #d94058;
        }

        /* Info Boxes */
        .info-boxes {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }

        .info-box {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
        }

        .info-box .info-number {
            font-size: 22px;
            font-weight: bold;
            color: #333;
        }

        .info-box .info-label {
            font-size: 11px;
            color: #888;
            margin-top: 4px;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-right: 200px;
                width: calc(100% - 200px);
                padding: 15px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .two-col-row {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .sidebar {
                width: 0;
                display: none;
            }

            .main-content {
                margin-right: 0;
                width: 100%;
                padding: 10px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table th,
            .data-table td {
                padding: 8px;
            }

            .info-boxes {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>نيوزفلو</h2>
                <p>لوحة التحكم</p>
            </div>

            <ul class="nav-menu">
                <li class="nav-item"><a href="index.php" class="nav-link active">لوحة التحكم</a></li>
                <li class="nav-item"><a href="articles.php" class="nav-link">الأخبار</a></li>
                <li class="nav-item"><a href="categories.php" class="nav-link">الأقسام</a></li>
                <li class="nav-item"><a href="sources.php" class="nav-link">المصادر</a></li>
                <li class="nav-item"><a href="ticker.php" class="nav-link">الشريط الإخباري</a></li>
                <li class="nav-item"><a href="settings.php" class="nav-link">الإعدادات</a></li>
            </ul>

            <div class="logout-section">
                <a href="logout.php" class="btn-logout">تسجيل الخروج</a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>لوحة التحكم</h1>
                <div class="user-info">
                    <p>أهلا وسهلا</p>
                    <strong><?php echo e($_SESSION['admin_name'] ?? 'مسؤول'); ?></strong>
                </div>
            </div>

            <!-- Row 1: Stat Cards -->
            <div class="stats-grid">
                <div class="stat-card gradient-blue">
                    <h3>إجمالي المقالات</h3>
                    <div class="number"><?php echo number_format($totalArticles); ?></div>
                    <div class="sub-info"><?php echo number_format($weekArticles); ?> مقال هذا الأسبوع</div>
                </div>
                <div class="stat-card gradient-green">
                    <h3>المصادر</h3>
                    <div class="number"><?php echo number_format($totalSources); ?></div>
                    <div class="sub-info"><?php echo number_format($totalCategories); ?> قسم</div>
                </div>
                <div class="stat-card gradient-orange">
                    <h3>مقالات اليوم</h3>
                    <div class="number"><?php echo number_format($todayArticles); ?></div>
                    <div class="sub-info"><?php echo number_format($breakingCount); ?> خبر عاجل</div>
                </div>
                <div class="stat-card gradient-cyan">
                    <h3>إجمالي المشاهدات</h3>
                    <div class="number"><?php echo number_format($totalViews); ?></div>
                    <div class="sub-info"><?php echo number_format($todayViews); ?> مشاهدة اليوم</div>
                </div>
            </div>

            <!-- Row 2: Chart + Top Sources -->
            <div class="two-col-row">
                <!-- Articles per Category Bar Chart -->
                <div class="card">
                    <h2 class="card-title">المقالات حسب القسم</h2>
                    <div class="bar-chart">
                        <?php if (!empty($articlesByCategory)): ?>
                            <?php $colorIndex = 0; foreach ($articlesByCategory as $cat): ?>
                                <?php
                                    $percentage = $maxCategoryCount > 0 ? round(($cat['count'] / $maxCategoryCount) * 100) : 0;
                                    $colorClass = 'color-' . (($colorIndex % 8) + 1);
                                    $colorIndex++;
                                ?>
                                <div class="bar-item">
                                    <span class="bar-label"><?php echo e($cat['name']); ?></span>
                                    <div class="bar-track">
                                        <div class="bar-fill <?php echo $colorClass; ?>" style="width: <?php echo max($percentage, 5); ?>%;">
                                            <?php echo $cat['count']; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color: #999; text-align: center;">لا توجد بيانات</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Top Sources -->
                <div class="card">
                    <h2 class="card-title">أفضل المصادر</h2>
                    <ul class="source-list">
                        <?php if (!empty($topSources)): ?>
                            <?php $rank = 1; foreach ($topSources as $src): ?>
                                <li class="source-list-item">
                                    <span class="source-rank"><?php echo $rank; ?></span>
                                    <span class="source-name"><?php echo e($src['name']); ?></span>
                                    <span class="source-count"><?php echo number_format($src['count']); ?> مقال</span>
                                </li>
                            <?php $rank++; endforeach; ?>
                        <?php else: ?>
                            <li style="color: #999; text-align: center; padding: 20px;">لا توجد بيانات</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Row 3: Top Viewed + Quick Actions -->
            <div class="two-col-row">
                <!-- Top 5 Most Viewed -->
                <div class="card">
                    <h2 class="card-title">الأكثر مشاهدة</h2>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>العنوان</th>
                                <th>المشاهدات</th>
                                <th>التاريخ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($topViewed)): ?>
                                <?php $i = 1; foreach ($topViewed as $article): ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo e($article['title']); ?>">
                                            <?php echo e(mb_substr($article['title'], 0, 35)); ?>
                                        </td>
                                        <td><span class="view-count-badge"><?php echo number_format($article['view_count']); ?></span></td>
                                        <td><span class="article-meta"><?php echo $article['published_at'] ? timeAgo($article['published_at']) : '-'; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #999;">لا توجد بيانات</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <h2 class="card-title">إجراءات سريعة</h2>
                    <div class="info-boxes">
                        <div class="info-box">
                            <div class="info-number"><?php echo number_format($weekArticles); ?></div>
                            <div class="info-label">مقالات الأسبوع</div>
                        </div>
                        <div class="info-box">
                            <div class="info-number"><?php echo number_format($breakingCount); ?></div>
                            <div class="info-label">أخبار عاجلة</div>
                        </div>
                        <div class="info-box">
                            <div class="info-number"><?php echo number_format($todayViews); ?></div>
                            <div class="info-label">مشاهدات اليوم</div>
                        </div>
                        <div class="info-box">
                            <div class="info-number"><?php echo number_format($totalCategories); ?></div>
                            <div class="info-label">الأقسام</div>
                        </div>
                    </div>
                    <div class="quick-actions-grid">
                        <a href="articles.php?action=add" class="quick-action-btn btn-blue">+ إضافة مقال</a>
                        <a href="sources.php?action=add" class="quick-action-btn btn-teal">+ إضافة مصدر</a>
                        <a href="ticker.php?action=add" class="quick-action-btn btn-purple">+ شريط إخباري</a>
                        <a href="articles.php" class="quick-action-btn btn-coral">إدارة المقالات</a>
                    </div>
                </div>
            </div>

            <!-- Row 4: Recent 10 Articles -->
            <div class="card">
                <h2 class="card-title">آخر المقالات المضافة</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>العنوان</th>
                            <th>القسم</th>
                            <th>المصدر</th>
                            <th>المشاهدات</th>
                            <th>الحالة</th>
                            <th>التاريخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentArticles)): ?>
                            <?php foreach ($recentArticles as $article): ?>
                                <tr>
                                    <td>
                                        <?php if ($article['is_breaking']): ?>
                                            <span class="breaking-badge">عاجل</span>
                                        <?php endif; ?>
                                        <a href="articles.php?action=edit&id=<?php echo $article['id']; ?>"
                                           class="article-title-link" title="<?php echo e($article['title']); ?>">
                                            <?php echo e(mb_substr($article['title'], 0, 45)); ?>
                                        </a>
                                    </td>
                                    <td><span class="article-meta"><?php echo e($article['cat_name'] ?? 'بدون'); ?></span></td>
                                    <td><span class="article-meta"><?php echo e($article['source_name'] ?? 'بدون'); ?></span></td>
                                    <td><span class="view-count-badge"><?php echo number_format($article['view_count'] ?? 0); ?></span></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $article['status']; ?>">
                                            <?php echo $article['status'] === 'published' ? 'منشور' : 'مسودة'; ?>
                                        </span>
                                    </td>
                                    <td><span class="article-meta"><?php echo timeAgo($article['published_at']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #999;">لا توجد مقالات بعد</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
