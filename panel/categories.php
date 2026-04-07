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
                $stmt = $db->prepare("
                    UPDATE categories SET
                    name = ?, slug = ?, icon = ?, css_class = ?,
                    sort_order = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $slug, $icon, $css_class, $sort_order, $is_active, $id]);
                $success = 'تم تحديث القسم بنجاح';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO categories
                    (name, slug, icon, css_class, sort_order, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $slug, $icon, $css_class, $sort_order, $is_active]);
                $success = 'تم إضافة القسم بنجاح';
                $id = $db->lastInsertId();
            }
            $action = 'list';
        } catch (PDOException $e) {
            $error = 'خطأ في حفظ القسم: ' . $e->getMessage();
        }
    }
}

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

$categories = $db->query("SELECT * FROM categories ORDER BY sort_order, name")->fetchAll();

$cssClassOptions = [
    'cat-political' => 'سياسة',
    'cat-economic' => 'اقتصاد',
    'cat-sports' => 'رياضة',
    'cat-arts' => 'فنون وثقافة',
    'cat-reports' => 'تقارير',
    'cat-media' => 'إعلام',
    'cat-breaking' => 'عاجل',
];

$cssClassColors = [
    'cat-political' => '#e74c3c',
    'cat-economic' => '#27ae60',
    'cat-sports' => '#2980b9',
    'cat-arts' => '#8e44ad',
    'cat-reports' => '#f39c12',
    'cat-media' => '#1abc9c',
    'cat-breaking' => '#c0392b',
];

$pageTitle = ($action === 'add' ? 'إضافة قسم' : ($action === 'edit' ? 'تعديل قسم' : 'إدارة الأقسام')) . ' - نيوزفلو';
$activePage = 'categories';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<div class="content">
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

    <?php if (in_array($action, ['add', 'edit'])): ?>
        <div class="page-header">
            <div>
                <h2><?php echo $action === 'add' ? 'إضافة قسم جديد' : 'تعديل القسم'; ?></h2>
                <p>أدخل بيانات القسم</p>
            </div>
            <div class="page-actions">
                <a href="categories.php" class="btn-outline">رجوع</a>
            </div>
        </div>

        <div class="form-card">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <?php if ($category): ?>
                    <input type="hidden" name="id" value="<?php echo $category['id']; ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="name">اسم القسم *</label>
                        <input type="text" id="name" name="name" class="form-control" required value="<?php echo $category ? e($category['name']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="slug">الرابط الودود (Slug) *</label>
                        <input type="text" id="slug" name="slug" class="form-control" required value="<?php echo $category ? e($category['slug']) : ''; ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="icon">الأيقونة (إيموجي)</label>
                        <input type="text" id="icon" name="icon" class="form-control" maxlength="10" value="<?php echo $category ? e($category['icon']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="css_class">تصنيف CSS</label>
                        <select id="css_class" name="css_class" class="form-control">
                            <option value="">-- اختر --</option>
                            <?php foreach ($cssClassOptions as $val => $label): ?>
                                <option value="<?php echo e($val); ?>" <?php echo ($category && $category['css_class'] === $val) ? 'selected' : ''; ?>>
                                    <?php echo e($label); ?> (<?php echo e($val); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="sort_order">ترتيب العرض</label>
                    <input type="number" id="sort_order" name="sort_order" class="form-control" min="0" value="<?php echo $category ? (int)$category['sort_order'] : 0; ?>">
                </div>

                <div class="form-group">
                    <label>الحالة</label>
                    <div class="checkbox-item">
                        <input type="checkbox" id="is_active" name="is_active" <?php echo !$category || $category['is_active'] ? 'checked' : ''; ?>>
                        <label for="is_active">مفعل</label>
                    </div>
                </div>

                <div class="page-actions">
                    <button type="submit" class="btn-primary">حفظ</button>
                    <a href="categories.php" class="btn-outline">إلغاء</a>
                </div>
            </form>
        </div>

    <?php else: ?>
        <div class="page-header">
            <div>
                <h2>إدارة الأقسام</h2>
                <p>تصنيفات الأخبار</p>
            </div>
            <div class="page-actions">
                <a href="categories.php?action=add" class="btn-primary">+ إضافة قسم</a>
            </div>
        </div>

        <div class="card">
            <table>
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
                                <td><span style="font-size:22px;"><?php echo e($cat['icon']); ?></span></td>
                                <td><?php echo e($cat['name']); ?></td>
                                <td>
                                    <?php if (!empty($cat['css_class'])): ?>
                                        <span class="badge" style="background-color: <?php echo $cssClassColors[$cat['css_class']] ?? '#999'; ?>; color:#fff;">
                                            <?php echo $cssClassOptions[$cat['css_class']] ?? e($cat['css_class']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$cat['sort_order']; ?></td>
                                <td>
                                    <?php if ($cat['is_active']): ?>
                                        <span class="badge badge-success">مفعل</span>
                                    <?php else: ?>
                                        <span class="badge badge-muted">معطل</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="categories.php?action=edit&id=<?php echo $cat['id']; ?>" class="action-btn">تعديل</a>
                                    <a href="categories.php?action=delete&id=<?php echo $cat['id']; ?>" class="btn-danger" onclick="return confirm('هل تريد حذف هذا القسم؟')">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align:center; color:var(--text-muted);">لا توجد أقسام</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
