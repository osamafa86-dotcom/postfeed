<?php
/**
 * نيوزفلو - إدارة الشريط الإخباري
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db = getDB();
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';
$ticker = null;

if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $db->prepare("DELETE FROM ticker_items WHERE id = ?");
        $stmt->execute([$id]);
        $success = 'تم حذف العنصر بنجاح';
        $action = 'list';
    } catch (PDOException $e) {
        $error = 'خطأ في حذف العنصر';
    }
}

if (isset($_GET['toggle_id'])) {
    $id = (int)$_GET['toggle_id'];
    try {
        $stmt = $db->prepare("UPDATE ticker_items SET is_active = !is_active WHERE id = ?");
        $stmt->execute([$id]);
        $success = 'تم تحديث الحالة بنجاح';
    } catch (PDOException $e) {
        $error = 'خطأ في تحديث الحالة';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) && $_POST['id'] ? (int)$_POST['id'] : null;
    $text = trim($_POST['text'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $sort_order = !empty($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;

    if (empty($text)) {
        $error = 'نص الشريط مطلوب';
    } else {
        try {
            if ($id) {
                $stmt = $db->prepare("
                    UPDATE ticker_items SET
                    text = ?, is_active = ?, sort_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([$text, $is_active, $sort_order, $id]);
                $success = 'تم تحديث العنصر بنجاح';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO ticker_items
                    (text, is_active, sort_order, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$text, $is_active, $sort_order]);
                $success = 'تم إضافة العنصر بنجاح';
            }
            $action = 'list';
        } catch (PDOException $e) {
            $error = 'خطأ في حفظ العنصر: ' . $e->getMessage();
        }
    }
}

if (in_array($action, ['edit', 'delete']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT * FROM ticker_items WHERE id = ?");
    $stmt->execute([$id]);
    $ticker = $stmt->fetch();
    if (!$ticker && $action === 'edit') {
        $error = 'العنصر غير موجود';
        $action = 'list';
    }
}

$tickers = $db->query("SELECT * FROM ticker_items ORDER BY sort_order, created_at DESC")->fetchAll();

$pageTitle = ($action === 'add' ? 'إضافة عنصر' : ($action === 'edit' ? 'تعديل عنصر' : 'الشريط الإخباري')) . ' - نيوزفلو';
$activePage = 'ticker';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<div class="content">
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

    <?php if (in_array($action, ['add', 'edit'])): ?>
        <div class="page-header">
            <div>
                <h2><?php echo $action === 'add' ? 'إضافة عنصر جديد' : 'تعديل العنصر'; ?></h2>
                <p>عناصر الشريط الإخباري</p>
            </div>
            <div class="page-actions">
                <a href="ticker.php" class="btn-outline">رجوع</a>
            </div>
        </div>

        <div class="form-card">
            <form method="POST">
                <?php if ($ticker): ?>
                    <input type="hidden" name="id" value="<?php echo $ticker['id']; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="text">نص الشريط *</label>
                    <textarea id="text" name="text" class="form-control" required><?php echo $ticker ? e($ticker['text']) : ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label for="sort_order">ترتيب العرض</label>
                    <input type="number" id="sort_order" name="sort_order" class="form-control" min="0" value="<?php echo $ticker ? $ticker['sort_order'] : '0'; ?>">
                </div>

                <div class="form-group">
                    <label>الحالة</label>
                    <div class="checkbox-item">
                        <input type="checkbox" id="is_active" name="is_active" <?php echo !$ticker || $ticker['is_active'] ? 'checked' : ''; ?>>
                        <label for="is_active">مفعل</label>
                    </div>
                </div>

                <div class="page-actions">
                    <button type="submit" class="btn-primary">حفظ</button>
                    <a href="ticker.php" class="btn-outline">إلغاء</a>
                </div>
            </form>
        </div>

    <?php else: ?>
        <div class="page-header">
            <div>
                <h2>الشريط الإخباري</h2>
                <p>إدارة عناصر الشريط الإخباري</p>
            </div>
            <div class="page-actions">
                <a href="ticker.php?action=add" class="btn-primary">+ إضافة عنصر</a>
            </div>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th style="width:50%">النص</th>
                        <th>الترتيب</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($tickers)): ?>
                        <?php foreach ($tickers as $tick): ?>
                            <tr>
                                <td><?php echo e($tick['text']); ?></td>
                                <td><?php echo $tick['sort_order']; ?></td>
                                <td>
                                    <?php if ($tick['is_active']): ?>
                                        <span class="badge badge-success">مفعل</span>
                                    <?php else: ?>
                                        <span class="badge badge-muted">معطل</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="ticker.php?action=edit&id=<?php echo $tick['id']; ?>" class="action-btn">تعديل</a>
                                    <a href="ticker.php?toggle_id=<?php echo $tick['id']; ?>" class="action-btn"><?php echo $tick['is_active'] ? 'تعطيل' : 'تفعيل'; ?></a>
                                    <a href="ticker.php?action=delete&id=<?php echo $tick['id']; ?>" class="btn-danger" onclick="return confirm('هل تريد حذف هذا العنصر؟')">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align:center; color:var(--text-muted);">لا توجد عناصر</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
