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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        foreach ($settingSections as $section) {
            foreach ($section['fields'] as $key => $field) {
                if ($field['type'] === 'checkbox') {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } else {
                    $value = trim($_POST[$key] ?? $field['default']);
                }

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

$pageTitle = 'الإعدادات - نيوزفلو';
$activePage = 'settings';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<div class="content">
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

    <div class="page-header">
        <div>
            <h2>الإعدادات</h2>
            <p>إدارة إعدادات الموقع العامة</p>
        </div>
    </div>

    <form method="POST">
        <?php foreach ($settingSections as $sectionKey => $section): ?>
            <div class="form-card">
                <h3 style="font-size:15px; font-weight:700; color:var(--text-primary); margin-bottom:18px; padding-bottom:10px; border-bottom:1.5px solid var(--border);"><?php echo $section['title']; ?></h3>

                <?php
                $fields = $section['fields'];
                $fieldKeys = array_keys($fields);
                $i = 0;
                $count = count($fieldKeys);

                while ($i < $count):
                    $key1 = $fieldKeys[$i];
                    $field1 = $fields[$key1];
                    $val1 = getSetting($key1, $field1['default']);

                    $key2 = isset($fieldKeys[$i + 1]) ? $fieldKeys[$i + 1] : null;
                    $field2 = $key2 ? $fields[$key2] : null;

                    if ($field1['type'] === 'checkbox'):
                ?>
                    <div class="form-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="<?php echo $key1; ?>" name="<?php echo $key1; ?>" <?php echo $val1 === '1' ? 'checked' : ''; ?>>
                            <label for="<?php echo $key1; ?>"><?php echo $field1['label']; ?></label>
                        </div>
                    </div>
                    <?php $i++; ?>
                <?php elseif ($key2 && $field2['type'] !== 'checkbox'):
                    $val2 = getSetting($key2, $field2['default']);
                ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="<?php echo $key1; ?>"><?php echo $field1['label']; ?></label>
                            <input type="<?php echo $field1['type']; ?>" id="<?php echo $key1; ?>" name="<?php echo $key1; ?>" class="form-control" value="<?php echo e($val1); ?>" <?php echo $field1['type'] === 'number' ? 'min="1"' : ''; ?>>
                        </div>
                        <div class="form-group">
                            <label for="<?php echo $key2; ?>"><?php echo $field2['label']; ?></label>
                            <input type="<?php echo $field2['type']; ?>" id="<?php echo $key2; ?>" name="<?php echo $key2; ?>" class="form-control" value="<?php echo e($val2); ?>" <?php echo $field2['type'] === 'number' ? 'min="1"' : ''; ?>>
                        </div>
                    </div>
                    <?php $i += 2; ?>
                <?php else: ?>
                    <div class="form-group">
                        <label for="<?php echo $key1; ?>"><?php echo $field1['label']; ?></label>
                        <input type="<?php echo $field1['type']; ?>" id="<?php echo $key1; ?>" name="<?php echo $key1; ?>" class="form-control" value="<?php echo e($val1); ?>" <?php echo $field1['type'] === 'number' ? 'min="1"' : ''; ?>>
                    </div>
                    <?php $i++; ?>
                <?php endif; ?>
                <?php endwhile; ?>
            </div>
        <?php endforeach; ?>

        <div class="page-actions">
            <button type="submit" class="btn-primary">حفظ الإعدادات</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
