<?php
/**
 * نيوزفلو - مصادر تيليغرام للأخبار العاجلة
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/telegram_fetch.php';
requireAdmin();

$db = getDB();

// Auto-migrate
try {
    $db->exec("CREATE TABLE IF NOT EXISTS telegram_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        display_name VARCHAR(150) NOT NULL,
        avatar_url VARCHAR(500) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        last_fetched_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS telegram_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_id INT NOT NULL,
        message_id INT NOT NULL,
        post_url VARCHAR(500) NOT NULL,
        text TEXT,
        image_url VARCHAR(500) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        posted_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_msg (source_id, message_id),
        INDEX idx_posted (posted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';

// Manual fetch
if ($action === 'fetch') {
    $count = tg_sync_all_sources();
    $success = "تم جلب $count تغريدة جديدة";
    $action = 'list';
}

if ($action === 'delete' && isset($_GET['id'])) {
    $db->prepare("DELETE FROM telegram_sources WHERE id = ?")->execute([(int)$_GET['id']]);
    $success = 'تم حذف المصدر';
    $action = 'list';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $username = ltrim(trim($_POST['username'] ?? ''), '@');
    $display_name = trim($_POST['display_name'] ?? '');
    $avatar = trim($_POST['avatar_url'] ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (empty($username) || empty($display_name)) {
        $error = 'اسم القناة والاسم المعروض مطلوبان';
    } else {
        try {
            if ($id) {
                $stmt = $db->prepare("UPDATE telegram_sources SET username=?, display_name=?, avatar_url=?, sort_order=?, is_active=? WHERE id=?");
                $stmt->execute([$username, $display_name, $avatar, $sort_order, $is_active, $id]);
                $success = 'تم تحديث القناة';
            } else {
                $stmt = $db->prepare("INSERT INTO telegram_sources (username, display_name, avatar_url, sort_order, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $display_name, $avatar, $sort_order, $is_active]);
                $success = 'تم إضافة القناة';
            }
            $action = 'list';
        } catch (PDOException $e) {
            $error = 'خطأ: ' . $e->getMessage();
        }
    }
}

$editSource = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM telegram_sources WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $editSource = $stmt->fetch();
}

$sourcesList = $db->query("SELECT s.*, (SELECT COUNT(*) FROM telegram_messages WHERE source_id = s.id) as msg_count FROM telegram_sources s ORDER BY s.sort_order ASC, s.display_name")->fetchAll();
$recentMsgs = $db->query("SELECT m.*, s.display_name, s.username FROM telegram_messages m JOIN telegram_sources s ON m.source_id = s.id ORDER BY m.posted_at DESC LIMIT 20")->fetchAll();

$pageTitle = 'مصادر تيليغرام - نيوزفلو';
$activePage = 'telegram';
include __DIR__ . '/includes/panel_layout_head.php';
?>
<div class="content">

  <div class="page-header">
    <div>
      <h2>📢 مصادر تيليغرام (الأخبار العاجلة)</h2>
      <p>أضف قنوات تيليغرام عامة. يتم سحب آخر التغريدات تلقائياً وعرضها في قسم العاجل.</p>
    </div>
    <div class="page-actions">
      <a href="telegram.php?action=fetch" class="btn-outline">🔄 جلب الآن</a>
      <?php if ($action === 'list'): ?>
        <a href="telegram.php?action=add" class="btn-primary">➕ إضافة قناة</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

  <?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="form-card">
      <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;"><?php echo $action === 'edit' ? '✏️ تعديل قناة' : '➕ إضافة قناة'; ?></h3>
      <form method="POST">
        <?php if ($editSource): ?><input type="hidden" name="id" value="<?php echo (int)$editSource['id']; ?>"><?php endif; ?>
        <div class="form-row">
          <div class="form-group">
            <label>اسم القناة على تيليغرام *</label>
            <input type="text" name="username" class="form-control" placeholder="aljazeera" value="<?php echo e($editSource['username'] ?? ''); ?>" required>
            <small style="color:var(--text-muted);font-size:11px;">يجب أن تكون قناة عامة (Public). بدون @</small>
          </div>
          <div class="form-group">
            <label>الاسم المعروض *</label>
            <input type="text" name="display_name" class="form-control" placeholder="قناة الجزيرة" value="<?php echo e($editSource['display_name'] ?? ''); ?>" required>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>رابط الأفاتار</label>
            <input type="url" name="avatar_url" class="form-control" value="<?php echo e($editSource['avatar_url'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="sort_order" class="form-control" value="<?php echo (int)($editSource['sort_order'] ?? 0); ?>">
          </div>
        </div>
        <div class="form-group">
          <div class="checkbox-item">
            <input type="checkbox" name="is_active" id="tg_active" <?php echo (!isset($editSource) || $editSource['is_active']) ? 'checked' : ''; ?>>
            <label for="tg_active" style="margin:0;">نشط</label>
          </div>
        </div>
        <div style="display:flex;gap:10px;">
          <button type="submit" class="btn-primary">💾 حفظ</button>
          <a href="telegram.php" class="btn-outline">إلغاء</a>
        </div>
      </form>
    </div>
  <?php else: ?>
    <div class="card" style="margin-bottom:20px;">
      <table>
        <thead>
          <tr>
            <th>القناة</th>
            <th>المستخدم</th>
            <th>عدد التغريدات</th>
            <th>آخر جلب</th>
            <th>الحالة</th>
            <th>إجراءات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sourcesList as $s): ?>
            <tr>
              <td><strong><?php echo e($s['display_name']); ?></strong></td>
              <td><a href="https://t.me/<?php echo e($s['username']); ?>" target="_blank" style="color:var(--primary);">@<?php echo e($s['username']); ?></a></td>
              <td><span class="badge badge-primary"><?php echo (int)$s['msg_count']; ?></span></td>
              <td style="color:var(--text-muted);font-size:12px;"><?php echo $s['last_fetched_at'] ? date('Y/m/d H:i', strtotime($s['last_fetched_at'])) : '—'; ?></td>
              <td><?php echo $s['is_active'] ? '<span class="badge badge-success">نشط</span>' : '<span class="badge badge-muted">معطل</span>'; ?></td>
              <td>
                <a href="telegram.php?action=edit&id=<?php echo (int)$s['id']; ?>" class="action-btn">✏️</a>
                <a href="telegram.php?action=delete&id=<?php echo (int)$s['id']; ?>" class="btn-danger" onclick="return confirm('حذف القناة؟');">🗑️</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($sourcesList)): ?>
            <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">لا توجد قنوات بعد. أضف أول قناة!</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <h3 style="margin:24px 0 12px;font-size:16px;">آخر التغريدات المسحوبة</h3>
    <div class="card">
      <table>
        <thead>
          <tr><th>القناة</th><th>النص</th><th>التاريخ</th><th>رابط</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recentMsgs as $m): ?>
            <tr>
              <td>@<?php echo e($m['username']); ?></td>
              <td style="max-width:500px;"><?php echo e(mb_substr($m['text'], 0, 120)); ?>...</td>
              <td style="font-size:12px;color:var(--text-muted);"><?php echo date('Y/m/d H:i', strtotime($m['posted_at'])); ?></td>
              <td><a href="<?php echo e($m['post_url']); ?>" target="_blank">🔗</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($recentMsgs)): ?>
            <tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text-muted);">اضغط "🔄 جلب الآن" لجلب التغريدات</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>
<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
