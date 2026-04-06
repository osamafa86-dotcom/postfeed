<?php
/**
 * نيوزفلو - إدارة الأخبار
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db = getDB();
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';
$article = null;

// معالجة الحذف
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $db->prepare("DELETE FROM articles WHERE id = ?");
        $stmt->execute([$id]);
        $success = 'تم حذف الخبر بنجاح';
        $action = 'list';
    } catch (PDOException $e) {
        $error = 'خطأ في حذف الخبر';
    }
}

// معالجة الإضافة والتعديل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) && $_POST['id'] ? (int)$_POST['id'] : null;
    $title = trim($_POST['title'] ?? '');
    $excerpt = trim($_POST['excerpt'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $image_url = trim($_POST['image_url'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $source_id = !empty($_POST['source_id']) ? (int)$_POST['source_id'] : null;
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $is_breaking = isset($_POST['is_breaking']) ? 1 : 0;
    $is_hero = isset($_POST['is_hero']) ? 1 : 0;
    $status = $_POST['status'] ?? 'draft';

    if (empty($title) || empty($content)) {
        $error = 'العنوان والمحتوى مطلوبان';
    } else {
        try {
            if ($id) {
                // تحديث
                $stmt = $db->prepare("
                    UPDATE articles SET
                    title = ?, excerpt = ?, content = ?, image_url = ?,
                    category_id = ?, source_id = ?, is_featured = ?,
                    is_breaking = ?, is_hero = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $title, $excerpt, $content, $image_url,
                    $category_id, $source_id, $is_featured,
                    $is_breaking, $is_hero, $status, $id
                ]);
                $success = 'تم تحديث الخبر بنجاح';
            } else {
                // إضافة
                $slug = preg_replace('/[^a-zA-Z0-9\x{0600}-\x{06FF}\s-]/u', '', $title);
                $slug = preg_replace('/[\s]+/', '-', trim($slug));
                $slug = mb_substr($slug, 0, 200) . '-' . time();
                $stmt = $db->prepare("
                    INSERT INTO articles
                    (title, slug, excerpt, content, image_url, category_id, source_id,
                     is_featured, is_breaking, is_hero, status, published_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $title, $slug, $excerpt, $content, $image_url,
                    $category_id, $source_id, $is_featured,
                    $is_breaking, $is_hero, $status
                ]);
                $success = 'تم إضافة الخبر بنجاح';
                $id = $db->lastInsertId();
            }
            $action = 'list';
        } catch (PDOException $e) {
            $error = 'خطأ في حفظ الخبر: ' . $e->getMessage();
        }
    }
}

// جلب الأخبار للتعديل
if (in_array($action, ['edit', 'delete']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
    $stmt->execute([$id]);
    $article = $stmt->fetch();
    if (!$article && $action === 'edit') {
        $error = 'الخبر غير موجود';
        $action = 'list';
    }
}

// جلب البيانات المساعدة
$categories = $db->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY name")->fetchAll();
$sources = $db->query("SELECT * FROM sources WHERE is_active = 1 ORDER BY name")->fetchAll();

// جلب قائمة الأخبار للعرض
$page = (int)($_GET['page'] ?? 1);
$perPage = 20;
$offset = ($page - 1) * $perPage;
$articles = $db->prepare("
    SELECT a.*, c.name as cat_name, s.name as source_name
    FROM articles a
    LEFT JOIN categories c ON a.category_id = c.id
    LEFT JOIN sources s ON a.source_id = s.id
    ORDER BY a.created_at DESC LIMIT ? OFFSET ?
");
$articles->execute([$perPage, $offset]);
$articlesList = $articles->fetchAll();

$totalArticles = $db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$totalPages = ceil($totalArticles / $perPage);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $action === 'add' ? 'إضافة خبر' : ($action === 'edit' ? 'تعديل خبر' : 'إدارة الأخبار'); ?> - نيوزفلو</title>
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .page-header h1 {
            color: #333;
            font-size: 24px;
        }

        .btn-primary {
            display: inline-block;
            padding: 10px 20px;
            background: #5a85b0;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s;
            border: none;
            cursor: pointer;
        }

        .btn-primary:hover {
            background: #4a6a95;
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
        }

        .alert-success {
            background: #efe;
            border: 1px solid #cfc;
            color: #3a3;
        }

        /* Form */
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            max-width: 900px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-row.full {
            grid-template-columns: 1fr;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #5a85b0;
            box-shadow: 0 0 0 3px rgba(90, 133, 176, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .form-group.full-width textarea {
            min-height: 300px;
        }

        .checkbox-group {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-item label {
            margin: 0;
            cursor: pointer;
        }

        .form-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-start;
        }

        .btn-cancel {
            padding: 10px 20px;
            background: #999;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }

        .btn-cancel:hover {
            background: #777;
        }

        /* Table */
        .table-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
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

        .article-actions {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-edit {
            background: #3498db;
            color: white;
        }

        .btn-edit:hover {
            background: #2980b9;
        }

        .btn-delete {
            background: #e74c3c;
            color: white;
        }

        .btn-delete:hover {
            background: #c0392b;
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
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #5a85b0;
            font-size: 14px;
        }

        .pagination a:hover {
            background: #5a85b0;
            color: white;
        }

        .pagination .active {
            background: #5a85b0;
            color: white;
            border-color: #5a85b0;
        }

        .pagination .disabled {
            color: #ccc;
            cursor: not-allowed;
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

            .form-row {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
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

            .form-container {
                padding: 15px;
            }

            .articles-table {
                font-size: 12px;
            }

            .articles-table th,
            .articles-table td {
                padding: 8px;
            }

            .article-actions {
                flex-direction: column;
            }

            .btn-sm {
                width: 100%;
                text-align: center;
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
                <li class="nav-item"><a href="index.php" class="nav-link">لوحة التحكم</a></li>
                <li class="nav-item"><a href="articles.php" class="nav-link active">الأخبار</a></li>
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
            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>

            <?php if (in_array($action, ['add', 'edit'])): ?>
                <!-- Form -->
                <div class="page-header">
                    <h1><?php echo $action === 'add' ? 'إضافة خبر جديد' : 'تعديل الخبر'; ?></h1>
                </div>

                <div class="form-container">
                    <form method="POST">
                        <?php if ($article): ?>
                            <input type="hidden" name="id" value="<?php echo $article['id']; ?>">
                        <?php endif; ?>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="title">العنوان *</label>
                                <input
                                    type="text"
                                    id="title"
                                    name="title"
                                    required
                                    value="<?php echo $article ? e($article['title']) : ''; ?>"
                                >
                            </div>
                            <div class="form-group">
                                <label for="image_url">رابط الصورة</label>
                                <input
                                    type="url"
                                    id="image_url"
                                    name="image_url"
                                    value="<?php echo $article ? e($article['image_url']) : ''; ?>"
                                >
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="category_id">التصنيف</label>
                                <select id="category_id" name="category_id">
                                    <option value="">-- بدون تصنيف --</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>"
                                            <?php echo $article && $article['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="source_id">المصدر</label>
                                <select id="source_id" name="source_id">
                                    <option value="">-- بدون مصدر --</option>
                                    <?php foreach ($sources as $src): ?>
                                        <option value="<?php echo $src['id']; ?>"
                                            <?php echo $article && $article['source_id'] == $src['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($src['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="status">الحالة</label>
                                <select id="status" name="status">
                                    <option value="draft" <?php echo !$article || $article['status'] === 'draft' ? 'selected' : ''; ?>>مسودة</option>
                                    <option value="published" <?php echo $article && $article['status'] === 'published' ? 'selected' : ''; ?>>منشور</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row full">
                            <div class="form-group">
                                <label for="excerpt">الملخص</label>
                                <textarea
                                    id="excerpt"
                                    name="excerpt"
                                ><?php echo $article ? e($article['excerpt']) : ''; ?></textarea>
                            </div>
                        </div>

                        <div class="form-row full">
                            <div class="form-group full-width">
                                <label for="content">المحتوى *</label>
                                <textarea
                                    id="content"
                                    name="content"
                                    required
                                ><?php echo $article ? e($article['content']) : ''; ?></textarea>
                            </div>
                        </div>

                        <div class="form-row full">
                            <div class="form-group">
                                <label>الخيارات</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input
                                            type="checkbox"
                                            id="is_featured"
                                            name="is_featured"
                                            <?php echo $article && $article['is_featured'] ? 'checked' : ''; ?>
                                        >
                                        <label for="is_featured">اختيار المحرر</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input
                                            type="checkbox"
                                            id="is_breaking"
                                            name="is_breaking"
                                            <?php echo $article && $article['is_breaking'] ? 'checked' : ''; ?>
                                        >
                                        <label for="is_breaking">عاجل</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input
                                            type="checkbox"
                                            id="is_hero"
                                            name="is_hero"
                                            <?php echo $article && $article['is_hero'] ? 'checked' : ''; ?>
                                        >
                                        <label for="is_hero">خبر بارز</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-buttons">
                            <button type="submit" class="btn-primary">حفظ</button>
                            <a href="articles.php" class="btn-cancel">إلغاء</a>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <!-- List -->
                <div class="page-header">
                    <h1>إدارة الأخبار</h1>
                    <a href="articles.php?action=add" class="btn-primary">+ إضافة خبر</a>
                </div>

                <div class="table-container">
                    <table class="articles-table">
                        <thead>
                            <tr>
                                <th>العنوان</th>
                                <th>التصنيف</th>
                                <th>المصدر</th>
                                <th>الحالة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($articlesList)): ?>
                                <?php foreach ($articlesList as $art): ?>
                                    <tr>
                                        <td>
                                            <?php if ($art['is_breaking']): ?>
                                                <span class="breaking-badge">عاجل</span>
                                            <?php endif; ?>
                                            <?php echo e(mb_substr($art['title'], 0, 50)); ?>
                                        </td>
                                        <td><?php echo e($art['cat_name'] ?? 'بدون'); ?></td>
                                        <td><?php echo e($art['source_name'] ?? 'بدون'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $art['status']; ?>">
                                                <?php echo $art['status'] === 'published' ? 'منشور' : 'مسودة'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="article-actions">
                                                <a href="articles.php?action=edit&id=<?php echo $art['id']; ?>" class="btn-sm btn-edit">تعديل</a>
                                                <a href="articles.php?action=delete&id=<?php echo $art['id']; ?>" class="btn-sm btn-delete" onclick="return confirm('هل تريد حذف هذا الخبر؟')">حذف</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: #999;">لا توجد أخبار</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="articles.php?page=1">الأولى</a>
                                <a href="articles.php?page=<?php echo $page - 1; ?>">السابقة</a>
                            <?php else: ?>
                                <span class="disabled">الأولى</span>
                                <span class="disabled">السابقة</span>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <?php if ($i === $page): ?>
                                    <span class="active"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="articles.php?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="articles.php?page=<?php echo $page + 1; ?>">التالية</a>
                                <a href="articles.php?page=<?php echo $totalPages; ?>">الأخيرة</a>
                            <?php else: ?>
                                <span class="disabled">التالية</span>
                                <span class="disabled">الأخيرة</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
