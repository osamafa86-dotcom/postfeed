<?php
/**
 * نيوزفلو - الإعدادات
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db = getDB();
$error = '';
$success = '';

// الإعدادات المتاحة
$settingSections = [
    'site' => [
        'title' => 'إعدادات الموقع',
        'fields' => [
            'site_name' => ['label' => 'اسم الموقع', 'type' => 'text', 'default' => ''],
            'site_tagline' => ['label' => 'شعار الموقع', 'type' => 'text', 'default' => ''],
            'site_url' => ['label' => 'رابط الموقع', 'type' => 'url', 'default' => ''],
        ]
    ],
    'display' => [
        'title' => 'إعدادات العرض',
        'fields' => [
            'articles_per_page' => ['label' => 'عدد المقالات في الصفحة', 'type' => 'number', 'default' => '10'],
            'show_weather' => ['label' => 'عرض الطقس', 'type' => 'checkbox', 'default' => '1'],
            'show_polls' => ['label' => 'عرض الاستطلاعات', 'type' => 'checkbox', 'default' => '1'],
        ]
    ],
    'rss' => [
        'title' => 'إعدادات RSS',
        'fields' => [
            'rss_fetch_interval' => ['label' => 'فترة جلب RSS (بالدقائق)', 'type' => 'number', 'default' => '30'],
            'auto_categorize' => ['label' => 'تصنيف تلقائي', 'type' => 'checkbox', 'default' => '0'],
        ]
    ],
];

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        foreach ($settingSections as $section) {
            foreach ($section['fields'] as $key => $field) {
                if ($field['type'] === 'checkbox') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } else {
                    $value = trim($_POST[$key] ?? $field['default']);
                }

                // التحقق من وجود الإعداد
                $stmt = $db->prepare("SELECT id FROM settings WHERE setting_key = ?");
                $stmt->execute([$key]);

                if ($stmt->fetch()) {
                    $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                    $stmt->execute([$value, $key]);
                } else {
                    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
                    $stmt->execute([$key, $value]);
                }
            }
        }
        $success = 'تم حفظ الإعدادات بنجاح';
    } catch (PDOException $e) {
        $error = 'خطأ في حفظ الإعدادات: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإعدادات - نيوزفلو</title>
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

        /* Settings Sections */
        .settings-section {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .settings-section h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
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
            margin-top: 10px;
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

            .settings-section {
                padding: 15px;
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
                    <a href="index.php" class="nav-link">لوحة التحكم</a>
                </li>
                <li class="nav-item">
                    <a href="articles.php" class="nav-link">الأخبار</a>
                </li>
                <li class="nav-item">
                    <a href="categories.php" class="nav-link">الأقسام</a>
                </li>
                <li class="nav-item">
                    <a href="sources.php" class="nav-link">المصادر</a>
                </li>
                <li class="nav-item">
                    <a href="ticker.php" class="nav-link">الشريط الإخباري</a>
                </li>
                <li class="nav-item">
                    <a href="settings.php" class="nav-link active">الإعدادات</a>
                </li>
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

            <div class="page-header">
                <h1>الإعدادات</h1>
            </div>

            <form method="POST">
                <?php foreach ($settingSections as $sectionKey => $section): ?>
                    <div class="settings-section">
                        <h2><?php echo $section['title']; ?></h2>

                        <?php
                        $fields = $section['fields'];
                        $fieldKeys = array_keys($fields);
                        $i = 0;
                        $count = count($fieldKeys);

                        while ($i < $count):
                            $key1 = $fieldKeys[$i];
                            $field1 = $fields[$key1];
                            $val1 = getSetting($key1, $field1['default']);

                            // Check if next field exists and both are not checkboxes for pairing
                            $key2 = isset($fieldKeys[$i + 1]) ? $fieldKeys[$i + 1] : null;
                            $field2 = $key2 ? $fields[$key2] : null;

                            if ($field1['type'] === 'checkbox'):
                        ?>
                            <div class="form-row full">
                                <div class="form-group">
                                    <div class="checkbox-item">
                                        <input
                                            type="checkbox"
                                            id="<?php echo $key1; ?>"
                                            name="<?php echo $key1; ?>"
                                            <?php echo $val1 === '1' ? 'checked' : ''; ?>
                                        >
                                        <label for="<?php echo $key1; ?>"><?php echo $field1['label']; ?></label>
                                    </div>
                                </div>
                            </div>
                            <?php $i++; ?>
                        <?php
                            elseif ($key2 && $field2['type'] !== 'checkbox'):
                                $val2 = getSetting($key2, $field2['default']);
                        ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="<?php echo $key1; ?>"><?php echo $field1['label']; ?></label>
                                    <input
                                        type="<?php echo $field1['type']; ?>"
                                        id="<?php echo $key1; ?>"
                                        name="<?php echo $key1; ?>"
                                        value="<?php echo e($val1); ?>"
                                        <?php echo $field1['type'] === 'number' ? 'min="1"' : ''; ?>
                                    >
                                </div>
                                <div class="form-group">
                                    <label for="<?php echo $key2; ?>"><?php echo $field2['label']; ?></label>
                                    <input
                                        type="<?php echo $field2['type']; ?>"
                                        id="<?php echo $key2; ?>"
                                        name="<?php echo $key2; ?>"
                                        value="<?php echo e($val2); ?>"
                                        <?php echo $field2['type'] === 'number' ? 'min="1"' : ''; ?>
                                    >
                                </div>
                            </div>
                            <?php $i += 2; ?>
                        <?php else: ?>
                            <div class="form-row full">
                                <div class="form-group">
                                    <label for="<?php echo $key1; ?>"><?php echo $field1['label']; ?></label>
                                    <input
                                        type="<?php echo $field1['type']; ?>"
                                        id="<?php echo $key1; ?>"
                                        name="<?php echo $key1; ?>"
                                        value="<?php echo e($val1); ?>"
                                        <?php echo $field1['type'] === 'number' ? 'min="1"' : ''; ?>
                                    >
                                </div>
                            </div>
                            <?php $i++; ?>
                        <?php endif; ?>
                        <?php endwhile; ?>
                    </div>
                <?php endforeach; ?>

                <div class="form-buttons">
                    <button type="submit" class="btn-primary">حفظ الإعدادات</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
