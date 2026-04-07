<?php
/**
 * نيوزفلو - إدارة المصادر
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db = getDB();
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';
$source = null;

// Auto-migrate tracking columns
try {
    $cols = $db->query("SHOW COLUMNS FROM sources LIKE 'last_fetched_at'")->fetch();
    if (!$cols) {
        $db->exec("ALTER TABLE sources
            ADD COLUMN last_fetched_at TIMESTAMP NULL DEFAULT NULL,
            ADD COLUMN last_error VARCHAR(500) DEFAULT NULL,
            ADD COLUMN last_new_count INT DEFAULT 0,
            ADD COLUMN total_articles INT DEFAULT 0");
    }
} catch (Exception $e) {}

// Manual fetch trigger
if ($action === 'fetch') {
    @set_time_limit(120);
    ob_start();
    require __DIR__ . '/../cron_rss.php';
    $output = ob_get_clean();
    $success = 'تم تشغيل سحب RSS:<br><pre style="font-size:11px;direction:ltr;text-align:left;background:#f8f9fa;padding:8px;border-radius:6px;max-height:200px;overflow:auto;">' . e($output) . '</pre>';
    $action = 'list';
}

if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $db->prepare("DELETE FROM sources WHERE id = ?");
        $stmt->execute([$id]);
        $success = 'تم حذف المصدر بنجاح';
        $action = 'list';
    } catch (PDOException $e) {
        $error = 'خطأ في حذف المصدر';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) && $_POST['id'] ? (int)$_POST['id'] : null;
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $logo_letter = trim($_POST['logo_letter'] ?? '');
    $logo_color = trim($_POST['logo_color'] ?? '');
    $logo_bg = trim($_POST['logo_bg'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $rss_url = trim($_POST['rss_url'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (empty($name) || empty($slug)) {
        $error = 'الاسم والرابط الودود مطلوبان';
    } else {
        try {
            if ($id) {
                $stmt = $db->prepare("
                    UPDATE sources SET
                    name = ?, slug = ?, logo_letter = ?, logo_color = ?,
                    logo_bg = ?, url = ?, rss_url = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $slug, $logo_letter, $logo_color, $logo_bg, $url, $rss_url, $is_active, $id]);
                $success = 'تم تحديث المصدر بنجاح';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO sources
                    (name, slug, logo_letter, logo_color, logo_bg, url, rss_url, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$name, $slug, $logo_letter, $logo_color, $logo_bg, $url, $rss_url, $is_active]);
                $success = 'تم إضافة المصدر بنجاح';
                $id = $db->lastInsertId();
            }
            $action = 'list';
        } catch (PDOException $e) {
            $error = 'خطأ في حفظ المصدر: ' . $e->getMessage();
        }
    }
}

if (in_array($action, ['edit', 'delete']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT * FROM sources WHERE id = ?");
    $stmt->execute([$id]);
    $source = $stmt->fetch();
    if (!$source && $action === 'edit') {
        $error = 'المصدر غير موجود';
        $action = 'list';
    }
}

$sources = $db->query("SELECT * FROM sources ORDER BY name")->fetchAll();

$pageTitle = ($action === 'add' ? 'إضافة مصدر' : ($action === 'edit' ? 'تعديل مصدر' : 'إدارة المصادر')) . ' - نيوزفلو';
$activePage = 'sources';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<div class="content">
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

    <?php if (in_array($action, ['add', 'edit'])): ?>
        <div class="page-header">
            <div>
                <h2><?php echo $action === 'add' ? 'إضافة مصدر جديد' : 'تعديل المصدر'; ?></h2>
                <p>أدخل بيانات المصدر</p>
            </div>
            <div class="page-actions">
                <a href="sources.php" class="btn-outline">رجوع</a>
            </div>
        </div>

        <div class="form-card">
            <form method="POST">
                <?php if ($source): ?>
                    <input type="hidden" name="id" value="<?php echo $source['id']; ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="name">اسم المصدر *</label>
                        <input type="text" id="name" name="name" class="form-control" required value="<?php echo $source ? e($source['name']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="slug">الرابط الودود (Slug) *</label>
                        <input type="text" id="slug" name="slug" class="form-control" required value="<?php echo $source ? e($source['slug']) : ''; ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="logo_letter">حرف اللوجو</label>
                        <input type="text" id="logo_letter" name="logo_letter" class="form-control" maxlength="2" value="<?php echo $source ? e($source['logo_letter']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="logo_color">لون النص</label>
                        <input type="color" id="logo_color" name="logo_color" class="form-control" style="height:44px; padding:4px;" value="<?php echo $source ? e($source['logo_color']) : '#ffffff'; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="logo_bg">لون الخلفية</label>
                    <input type="color" id="logo_bg" name="logo_bg" class="form-control" style="height:44px; padding:4px;" value="<?php echo $source ? e($source['logo_bg']) : '#5a85b0'; ?>">
                </div>

                <div class="form-group">
                    <label for="url">رابط المصدر</label>
                    <input type="url" id="url" name="url" class="form-control" value="<?php echo $source ? e($source['url']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="rss_url">رابط RSS</label>
                    <input type="url" id="rss_url" name="rss_url" class="form-control" value="<?php echo $source ? e($source['rss_url']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>الحالة</label>
                    <div class="checkbox-item">
                        <input type="checkbox" id="is_active" name="is_active" <?php echo !$source || $source['is_active'] ? 'checked' : ''; ?>>
                        <label for="is_active">مفعل</label>
                    </div>
                </div>

                <div class="page-actions">
                    <button type="submit" class="btn-primary">حفظ</button>
                    <a href="sources.php" class="btn-outline">إلغاء</a>
                </div>
            </form>
        </div>

    <?php else: ?>
        <div class="page-header">
            <div>
                <h2>إدارة المصادر</h2>
                <p>مصادر الأخبار</p>
            </div>
            <div class="page-actions">
                <a href="sources.php?action=fetch" class="btn-outline" onclick="this.innerHTML='⏳ جاري السحب...'">🔄 جلب RSS الآن</a>
                <a href="sources.php?action=add" class="btn-primary">+ إضافة مصدر</a>
            </div>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>اللوجو</th>
                        <th>الاسم</th>
                        <th>المقالات</th>
                        <th>آخر سحب</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sources)): ?>
                        <?php foreach ($sources as $src): ?>
                            <tr>
                                <td>
                                    <div style="display:inline-flex; align-items:center; justify-content:center; width:38px; height:38px; border-radius:10px; font-weight:bold; font-size:16px; background-color: <?php echo e($src['logo_bg']); ?>; color: <?php echo e($src['logo_color']); ?>;">
                                        <?php echo e($src['logo_letter']); ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo e($src['name']); ?></strong>
                                    <?php if (!empty($src['last_error'])): ?>
                                        <div style="font-size:11px;color:#dc3545;margin-top:3px;" title="<?php echo e($src['last_error']); ?>">⚠ <?php echo e(mb_substr($src['last_error'], 0, 60)); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-primary"><?php echo (int)($src['total_articles'] ?? 0); ?></span></td>
                                <td style="font-size:12px;color:var(--text-muted);">
                                    <?php if (!empty($src['last_fetched_at'])): ?>
                                        <?php echo date('Y/m/d H:i', strtotime($src['last_fetched_at'])); ?>
                                        <?php if (!empty($src['last_new_count'])): ?>
                                            <div style="color:#16a34a;font-weight:600;">+<?php echo (int)$src['last_new_count']; ?> جديد</div>
                                        <?php endif; ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($src['is_active']): ?>
                                        <span class="badge badge-success">مفعل</span>
                                    <?php else: ?>
                                        <span class="badge badge-muted">معطل</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="sources.php?action=edit&id=<?php echo $src['id']; ?>" class="action-btn">تعديل</a>
                                    <a href="sources.php?action=delete&id=<?php echo $src['id']; ?>" class="btn-danger" onclick="return confirm('هل تريد حذف هذا المصدر؟')">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align:center; color:var(--text-muted);">لا توجد مصادر</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
