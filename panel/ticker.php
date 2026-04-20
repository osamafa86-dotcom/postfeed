<?php
/**
 * نيوز فيد - إدارة الشريط الإخباري
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('editor');

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

$pageTitle = ($action === 'add' ? 'إضافة عنصر' : ($action === 'edit' ? 'تعديل عنصر' : 'الشريط الإخباري')) . ' - نيوز فيد';
$activePage = 'ticker';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<style>
  .ticker-form-layout { display:grid; grid-template-columns:1fr 280px; gap:18px; align-items:start; }
  @media(max-width:900px) { .ticker-form-layout { grid-template-columns:1fr; } }
  .ticker-card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-lg); padding:20px 22px; box-shadow:var(--shadow); }
  .ticker-item {
    display:flex; align-items:center; gap:14px;
    padding:16px 20px; border-bottom:1px solid var(--border-light);
    transition:var(--transition);
  }
  .ticker-item:hover { background:var(--bg-hover); }
  .ticker-item:last-child { border:none; }
  .ticker-ico { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
  .ticker-ico.active { background:var(--success-light); color:var(--success); }
  .ticker-ico.inactive { background:var(--bg-page); color:var(--text-muted); }
  .ticker-body { flex:1; min-width:0; }
  .ticker-text { font-size:14px; font-weight:600; color:var(--text-primary); line-height:1.6; }
  .ticker-meta { font-size:11px; color:var(--text-muted); margin-top:4px; display:flex; gap:10px; align-items:center; }
  .ticker-actions { display:flex; gap:6px; flex-shrink:0; }
  .toggle-pill {
    display:inline-flex; align-items:center; gap:5px;
    padding:5px 12px; border-radius:8px; font-size:12px; font-weight:600;
    cursor:pointer; transition:var(--transition); text-decoration:none; border:1.5px solid;
  }
  .toggle-pill.on { background:var(--success-light); color:var(--success); border-color:var(--success); }
  .toggle-pill.on:hover { background:var(--success); color:#fff; }
  .toggle-pill.off { background:var(--bg-page); color:var(--text-muted); border-color:var(--border); }
  .toggle-pill.off:hover { background:var(--primary-light); color:var(--primary); border-color:var(--primary); }
</style>

<div class="content">
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

    <?php if (in_array($action, ['add', 'edit'])): ?>
        <div class="page-header">
            <div>
                <h2><?php echo $action === 'add' ? '📢 إضافة عنصر جديد' : '📝 تعديل العنصر'; ?></h2>
                <p>عناصر الشريط الإخباري المتحرك</p>
            </div>
            <div class="page-actions">
                <a href="ticker.php" class="btn-outline">↩ رجوع</a>
            </div>
        </div>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <?php if ($ticker): ?>
                <input type="hidden" name="id" value="<?php echo $ticker['id']; ?>">
            <?php endif; ?>
            <div class="ticker-form-layout">
                <div class="ticker-card">
                    <div class="form-group">
                        <label for="text">نص الخبر العاجل *</label>
                        <textarea id="text" name="text" class="form-control" required style="min-height:120px;font-size:16px;line-height:1.8;"><?php echo $ticker ? e($ticker['text']) : ''; ?></textarea>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:14px;">
                    <div class="ticker-card">
                        <div class="form-group">
                            <label for="sort_order">ترتيب العرض</label>
                            <input type="number" id="sort_order" name="sort_order" class="form-control" min="0" value="<?php echo $ticker ? $ticker['sort_order'] : '0'; ?>">
                        </div>
                        <div class="form-group" style="margin-top:14px;">
                            <label>الحالة</label>
                            <div class="checkbox-item" style="margin-top:6px;">
                                <input type="checkbox" id="is_active" name="is_active" <?php echo !$ticker || $ticker['is_active'] ? 'checked' : ''; ?>>
                                <label for="is_active">مفعّل ويظهر في الشريط</label>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary" style="width:100%;padding:12px;font-size:14px;justify-content:center;">💾 حفظ</button>
                    <a href="ticker.php" class="btn-outline" style="text-align:center;">إلغاء</a>
                </div>
            </div>
        </form>

    <?php else: ?>
        <div class="page-header">
            <div>
                <h2>📢 الشريط الإخباري</h2>
                <p><?php echo count($tickers); ?> عنصر · <?php echo count(array_filter($tickers, fn($t)=>$t['is_active'])); ?> مفعّل</p>
            </div>
            <div class="page-actions">
                <a href="ticker.php?action=add" class="btn-primary">📢 إضافة عنصر</a>
            </div>
        </div>

        <div class="card">
            <?php if (!empty($tickers)): ?>
                <?php foreach ($tickers as $tick): ?>
                    <div class="ticker-item">
                        <div class="ticker-ico <?php echo $tick['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $tick['is_active'] ? '📢' : '🔇'; ?>
                        </div>
                        <div class="ticker-body">
                            <div class="ticker-text"><?php echo e($tick['text']); ?></div>
                            <div class="ticker-meta">
                                <span>ترتيب: <?php echo (int)$tick['sort_order']; ?></span>
                                <span><?php echo date('Y/m/d', strtotime($tick['created_at'])); ?></span>
                            </div>
                        </div>
                        <div class="ticker-actions">
                            <a href="ticker.php?toggle_id=<?php echo $tick['id']; ?>" class="toggle-pill <?php echo $tick['is_active'] ? 'on' : 'off'; ?>">
                                <?php echo $tick['is_active'] ? '✓ مفعّل' : 'معطّل'; ?>
                            </a>
                            <a href="ticker.php?action=edit&id=<?php echo $tick['id']; ?>" class="action-btn">تعديل</a>
                            <a href="ticker.php?action=delete&id=<?php echo $tick['id']; ?>" class="btn-danger" onclick="return confirm('هل تريد حذف هذا العنصر؟')">حذف</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state" style="padding:50px;">📢 لا توجد عناصر في الشريط الإخباري بعد</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
