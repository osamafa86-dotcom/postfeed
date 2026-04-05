<?php
/**
 * نيوزفلو - لوحة التحكم الرئيسية
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db = getDB();

// إحصائيات
$totalArticles = $db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$totalSources = $db->query("SELECT COUNT(*) FROM sources")->fetchColumn();
$totalCategories = $db->query("SELECT COUNT(*) FROM categories WHERE is_active = 1")->fetchColumn();
$todayViews = $db->query("SELECT COALESCE(SUM(view_count), 0) FROM articles WHERE DATE(published_at) = CURDATE()")->fetchColumn();

// آخر الأخبار
$recentArticles = $db->query("
    SELECT a.id, a.title, a.status, a.is_breaking, a.published_at,
           c.name as cat_name, s.name as source_name
    FROM articles a
    LEFT JOIN categories c ON a.category_id = c.id
    LEFT JOIN sources s ON a.source_id = s.id
    ORDER BY a.created_at DESC LIMIT 10
")->fetchAll();
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-right: 4px solid #5a85b0;
        }

        .stat-card h3 {
            color: #999;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
        }

        .stat-card .number {
            font-size: 32px;
            color: #333;
            font-weight: bold;
        }

        /* Quick Links */
        .quick-links {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .quick-links h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 20px;
        }

        .links-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .quick-link-btn {
            display: inline-block;
            padding: 12px 20px;
            background: #5a85b0;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            text-align: center;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s;
        }

        .quick-link-btn:hover {
            background: #4a6a95;
        }

        /* Recent Articles */
        .recent-articles {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .recent-articles h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 20px;
        }

        .articles-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .articles-table thead {
            background: #f9f9f9;
            border-bottom: 2px solid #eee;
        }

        .articles-table th {
            padding: 12px;
            text-align: right;
            color: #666;
            font-weight: 600;
        }

        .articles-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        .articles-table tbody tr:hover {
            background: #f9f9f9;
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

        .article-title {
            color: #333;
            font-weight: 500;
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .article-meta {
            color: #999;
            font-size: 12px;
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

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }

        @media (max-width: 576px) {
            .sidebar {
                width: 0;
                margin-right: -250px;
            }

            .main-content {
                margin-right: 0;
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .articles-table {
                font-size: 12px;
            }

            .articles-table th,
            .articles-table td {
                padding: 8px;
            }

            .article-title {
                max-width: 150px;
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
                <li class="nav-item">
                    <a href="index.php" class="nav-link active">لوحة التحكم</a>
                </li>
                <li class="nav-item">
                    <a href="articles.php" class="nav-link">الأخبار</a>
                </li>
                <li class="nav-item">
                    <a href="sources.php" class="nav-link">المصادر</a>
                </li>
                <li class="nav-item">
                    <a href="ticker.php" class="nav-link">الشريط الإخباري</a>
                </li>
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

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>إجمالي الأخبار</h3>
                    <div class="number"><?php echo $totalArticles; ?></div>
                </div>
                <div class="stat-card">
                    <h3>عدد المصادر</h3>
                    <div class="number"><?php echo count($totalSources); ?></div>
                </div>
                <div class="stat-card">
                    <h3>التصنيفات</h3>
                    <div class="number"><?php echo $totalCategories; ?></div>
                </div>
                <div class="stat-card">
                    <h3>مشاهدات اليوم</h3>
                    <div class="number"><?php echo number_format($todayViews); ?></div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="quick-links">
                <h2>الروابط السريعة</h2>
                <div class="links-grid">
                    <a href="articles.php?action=add" class="quick-link-btn">+ إضافة خبر</a>
                    <a href="sources.php?action=add" class="quick-link-btn">+ إضافة مصدر</a>
                    <a href="ticker.php?action=add" class="quick-link-btn">+ شريط إخباري</a>
                    <a href="articles.php" class="quick-link-btn">إدارة الأخبار</a>
                </div>
            </div>

            <!-- Recent Articles -->
            <div class="recent-articles">
                <h2>آخر الأخبار المضافة</h2>
                <table class="articles-table">
                    <thead>
                        <tr>
                            <th>العنوان</th>
                            <th>التصنيف</th>
                            <th>المصدر</th>
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
                                           class="article-title" title="<?php echo e($article['title']); ?>">
                                            <?php echo e(mb_substr($article['title'], 0, 40)); ?>
                                        </a>
                                    </td>
                                    <td><span class="article-meta"><?php echo e($article['cat_name'] ?? 'بدون'); ?></span></td>
                                    <td><span class="article-meta"><?php echo e($article['source_name'] ?? 'بدون'); ?></span></td>
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
                                <td colspan="5" style="text-align: center; color: #999;">لا توجد أخبار بعد</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
