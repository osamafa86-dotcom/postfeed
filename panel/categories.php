<?php
/**
 * نيوزفلو - إدارة الأقسام
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db = getDB();
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';
$category = null;

// معالجة الحذف
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        $success = 'تم حذف القسم بنجاح';
        $action = 'list';
    } catch (PDOException $e) {
        $error = 'خطأ في حذف القسم';
    }
}

// معالجة الإضافة والتعديل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) && $_POST['id'] ? (int)$_POST['id'] : null;
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $css_class = trim($_POST['css_class'] ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (empty($name) || empty($slug)) {
        $error = 'الاسم والرابط الودود مطلوبان';
    } else {
        try {
            if ($id) {
                // تحديث
                $stmt = $db->prepare("
                    UPDATE categories SET
                    name = ?, slug = ?, icon = ?, css_class = ?,
                    sort_order = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $name, $slug, $icon, $css_class,
                    $sort_order, $is_active, $id
                ]);
                $success = 'تم تحديث القسم بنجاح';
            } else {
                // إضافة
                $stmt = $db->prepare("
                    INSERT INTO categories
                    (name, slug, icon, css_class, sort_order, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $name, $slug, $icon, $css_class,
                    $sort_order, $is_active
                ]);
                $success = 'تم إضافة القسم بنجاح';
                $id = $db->lastInsertId();
            }
            $action = 'list';
        } catch (PDOException $e) {
            $error = 'خطأ في حفظ القسم: ' . $e->getMessage();
        }
    }
}

// جلب القسم للتعديل
if (in_array($action, ['edit', 'delete']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$id]);
    $category = $stmt->fetch();
    if (!$category && $action === 'edit') {
        $error = 'القسم غير موجود';
        $action = 'list';
    }
}

// جلب قائمة الأقسام للعرض
$categories = $db->query("SELECT * FROM categories ORDER BY sort_order, name")->fetchAll();

// خيارات التصنيف CSS
$cssClassOptions = [
    'cat-political' => 'سياسة',
    'cat-economic' => 'اقتصاد',
    'cat-sports' => 'رياضة',
    'cat-arts' => 'فنون وثقافة',
    'cat-reports' => 'تقارير',
    'cat-media' => 'إعلام',
    'cat-breaking' => 'عاجل',
];

// ألوان معاينة التصنيفات
$cssClassColors = [
    'cat-political' => '#e74c3c',
    'cat-economic' => '#27ae60',
    'cat-sports' => '#2980b9',
    'cat-arts' => '#8e44ad',
    'cat-reports' => '#f39c12',
    'cat-media' => '#1abc9c',
    'cat-breaking' => '#c0392b',
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $action === 'add' ? 'إضافة قسم' : ($action === 'edit' ? 'تعديل قسم' : 'إدارة الأقسام'); ?> - نيوزفلو</title>
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
            max-width: 700px;
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

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 5px;
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

        .sources-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .sources-table thead {
            background: #f9f9f9;
            border-bottom: 2px solid #eee;
        }

        .sources-table th {
            padding: 12px;
            text-align: right;
            color: #666;
            font-weight: 600;
        }

        .sources-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        .sources-table tbody tr:hover {
            background: #f9f9f9;
        }

        .icon-preview {
            font-size: 24px;
        }

        .css-class-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: white;
        }

        .source-actions {
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

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
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

            .sources-table {
                font-size: 12px;
            }

            .sources-table th,
            .sources-table td {
                padding: 8px;
            }

            .source-actions {
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
                <li class="nav-item"><a href="articles.php" class="nav-link">الأخبار</a></li>
                <li class="nav-item"><a href="categories.php" class="nav-link active">الأقسام</a></li>
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
                    <h1><?php echo $action === 'add' ? 'إضافة قسم جديد' : 'تعديل القسم'; ?></h1>
                </div>

                <div class="form-container">
                    <form method="POST">
                        <?php if ($category): ?>
                            <input type="hidden" name="id" value="<?php echo $category['id']; ?>">
                        <?php endif; ?>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="name">اسم القسم *</label>
                                <input
                                    type="text"
                                    id="name"
                                    name="name"
                                    required
                                    value="<?php echo $category ? e($category['name']) : ''; ?>"
                                >
                            </div>
                            <div class="form-group">
                                <label for="slug">الرابط الودود (Slug) *</label>
                                <input
                                    type="text"
                                    id="slug"
                                    name="slug"
                                    required
                                    value="<?php echo $category ? e($category['slug']) : ''; ?>"
                                >
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="icon">الأيقونة (إيموجي)</label>
                                <input
                                    type="text"
                                    id="icon"
                                    name="icon"
                                    maxlength="10"
                                    value="<?php echo $category ? e($category['icon']) : ''; ?>"
                                >
                            </div>
                            <div class="form-group">
                                <label for="css_class">تصنيف CSS</label>
                                <select id="css_class" name="css_class">
                                    <option value="">-- اختر --</option>
                                    <?php foreach ($cssClassOptions as $val => $label): ?>
                                        <option value="<?php echo e($val); ?>" <?php echo ($category && $category['css_class'] === $val) ? 'selected' : ''; ?>>
                                            <?php echo e($label); ?> (<?php echo e($val); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="sort_order">ترتيب العرض</label>
                                <input
                                    type="number"
                                    id="sort_order"
                                    name="sort_order"
                                    min="0"
                                    value="<?php echo $category ? (int)$category['sort_order'] : 0; ?>"
                                >
                            </div>
                        </div>

                        <div class="form-row full">
                            <div class="form-group">
                                <label>الحالة</label>
                                <div class="checkbox-item">
                                    <input
                                        type="checkbox"
                                        id="is_active"
                                        name="is_active"
                                        <?php echo !$category || $category['is_active'] ? 'checked' : ''; ?>
                                    >
                                    <label for="is_active">مفعل</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-buttons">
                            <button type="submit" class="btn-primary">حفظ</button>
                            <a href="categories.php" class="btn-cancel">إلغاء</a>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <!-- List -->
                <div class="page-header">
                    <h1>إدارة الأقسام</h1>
                    <a href="categories.php?action=add" class="btn-primary">+ إضافة قسم</a>
                </div>

                <div class="table-container">
                    <table class="sources-table">
                        <thead>
                            <tr>
                                <th>الأيقونة</th>
                                <th>الاسم</th>
                                <th>التصنيف</th>
                                <th>الترتيب</th>
                                <th>الحالة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($categories)): ?>
                                <?php foreach ($categories as $cat): ?>
                                    <tr>
                                        <td>
                                            <span class="icon-preview"><?php echo e($cat['icon']); ?></span>
                                        </td>
                                        <td><?php echo e($cat['name']); ?></td>
                                        <td>
                                            <?php if (!empty($cat['css_class'])): ?>
                                                <span class="css-class-badge" style="background-color: <?php echo $cssClassColors[$cat['css_class']] ?? '#999'; ?>;">
                                                    <?php echo $cssClassOptions[$cat['css_class']] ?? e($cat['css_class']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #999;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo (int)$cat['sort_order']; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $cat['is_active'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $cat['is_active'] ? 'مفعل' : 'معطل'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="source-actions">
                                                <a href="categories.php?action=edit&id=<?php echo $cat['id']; ?>" class="btn-sm btn-edit">تعديل</a>
                                                <a href="categories.php?action=delete&id=<?php echo $cat['id']; ?>" class="btn-sm btn-delete" onclick="return confirm('هل تريد حذف هذا القسم؟')">حذف</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #999;">لا توجد أقسام</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
