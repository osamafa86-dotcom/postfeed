<?php
/**
 * نيوزفلو - إدارة الريلز والمصادر
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db = getDB();

// Ensure tables exist (auto-migrate)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS reels_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        display_name VARCHAR(150) NOT NULL,
        avatar_url VARCHAR(500) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS reels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_id INT DEFAULT NULL,
        instagram_url VARCHAR(500) NOT NULL,
        shortcode VARCHAR(100) NOT NULL,
        caption TEXT DEFAULT NULL,
        thumbnail_url VARCHAR(500) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_shortcode (shortcode),
        INDEX idx_source (source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

$tab = $_GET['tab'] ?? 'reels';
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';

// Extract shortcode from instagram URL
function extractShortcode($url) {
    if (preg_match('#instagram\.com/(?:reel|p|tv)/([A-Za-z0-9_-]+)#', $url, $m)) {
        return $m[1];
    }
    return '';
}

// -------- REELS CRUD --------
if ($tab === 'reels') {
    if ($action === 'delete' && isset($_GET['id'])) {
        $stmt = $db->prepare("DELETE FROM reels WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $success = 'تم حذف الريل بنجاح';
        $action = 'list';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'reel') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $url = trim($_POST['instagram_url'] ?? '');
        $source_id = !empty($_POST['source_id']) ? (int)$_POST['source_id'] : null;
        $caption = trim($_POST['caption'] ?? '');
        $thumbnail = trim($_POST['thumbnail_url'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $shortcode = extractShortcode($url);

        if (empty($url) || empty($shortcode)) {
            $error = 'يرجى إدخال رابط انستغرام صحيح (reel/p/tv)';
        } else {
            try {
                if ($id) {
                    $stmt = $db->prepare("UPDATE reels SET source_id=?, instagram_url=?, shortcode=?, caption=?, thumbnail_url=?, sort_order=?, is_active=? WHERE id=?");
                    $stmt->execute([$source_id, $url, $shortcode, $caption, $thumbnail, $sort_order, $is_active, $id]);
                    $success = 'تم تحديث الريل';
                } else {
                    $stmt = $db->prepare("INSERT INTO reels (source_id, instagram_url, shortcode, caption, thumbnail_url, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$source_id, $url, $shortcode, $caption, $thumbnail, $sort_order, $is_active]);
                    $success = 'تم إضافة الريل';
                }
                $action = 'list';
            } catch (PDOException $e) {
                $error = 'خطأ: ' . $e->getMessage();
            }
        }
    }

    $editReel = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = $db->prepare("SELECT * FROM reels WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $editReel = $stmt->fetch();
    }

    $reelsList = $db->query("SELECT r.*, s.display_name as source_name, s.username FROM reels r LEFT JOIN reels_sources s ON r.source_id = s.id ORDER BY r.sort_order ASC, r.created_at DESC")->fetchAll();
}

// -------- SOURCES CRUD --------
if ($tab === 'sources') {
    if ($action === 'delete' && isset($_GET['id'])) {
        $stmt = $db->prepare("DELETE FROM reels_sources WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $success = 'تم حذف المصدر';
        $action = 'list';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'source') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $username = trim($_POST['username'] ?? '');
        $username = ltrim($username, '@');
        $display_name = trim($_POST['display_name'] ?? '');
        $avatar = trim($_POST['avatar_url'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($username) || empty($display_name)) {
            $error = 'اسم المستخدم والاسم المعروض مطلوبان';
        } else {
            try {
                if ($id) {
                    $stmt = $db->prepare("UPDATE reels_sources SET username=?, display_name=?, avatar_url=?, sort_order=?, is_active=? WHERE id=?");
                    $stmt->execute([$username, $display_name, $avatar, $sort_order, $is_active, $id]);
                    $success = 'تم تحديث المصدر';
                } else {
                    $stmt = $db->prepare("INSERT INTO reels_sources (username, display_name, avatar_url, sort_order, is_active) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $display_name, $avatar, $sort_order, $is_active]);
                    $success = 'تم إضافة المصدر';
                }
                $action = 'list';
            } catch (PDOException $e) {
                $error = 'خطأ: ' . $e->getMessage();
            }
        }
    }

    $editSource = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = $db->prepare("SELECT * FROM reels_sources WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $editSource = $stmt->fetch();
    }

    $sourcesList = $db->query("SELECT s.*, (SELECT COUNT(*) FROM reels WHERE source_id = s.id) as reels_count FROM reels_sources s ORDER BY s.sort_order ASC, s.display_name")->fetchAll();
}

// Always load active sources for dropdowns
$activeSources = $db->query("SELECT id, username, display_name FROM reels_sources WHERE is_active = 1 ORDER BY display_name")->fetchAll();

$pageTitle = 'إدارة الريلز - نيوزفلو';
$activePage = 'reels';
include __DIR__ . '/includes/panel_layout_head.php';
?>
<div class="content">

  <div class="page-header">
    <div>
      <h2>🎬 إدارة الريلز</h2>
      <p>إضافة وإدارة فيديوهات انستغرام ريلز من المصادر</p>
    </div>
    <div class="page-actions">
      <?php if ($tab === 'reels' && $action === 'list'): ?>
        <a href="reels.php?tab=reels&action=add" class="btn-primary">➕ إضافة ريل</a>
      <?php elseif ($tab === 'sources' && $action === 'list'): ?>
        <a href="reels.php?tab=sources&action=add" class="btn-primary">➕ إضافة مصدر</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tabs -->
  <div style="display:flex;gap:8px;margin-bottom:20px;border-bottom:1.5px solid var(--border);padding-bottom:0;">
    <a href="reels.php?tab=reels" style="padding:10px 20px;font-weight:700;font-size:14px;border-bottom:3px solid <?php echo $tab==='reels'?'var(--primary)':'transparent'; ?>;color:<?php echo $tab==='reels'?'var(--primary)':'var(--text-muted)'; ?>;text-decoration:none;margin-bottom:-1.5px;">🎬 الريلز</a>
    <a href="reels.php?tab=sources" style="padding:10px 20px;font-weight:700;font-size:14px;border-bottom:3px solid <?php echo $tab==='sources'?'var(--primary)':'transparent'; ?>;color:<?php echo $tab==='sources'?'var(--primary)':'var(--text-muted)'; ?>;text-decoration:none;margin-bottom:-1.5px;">👥 المصادر</a>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

  <?php if ($tab === 'reels'): ?>
    <?php if ($action === 'add' || $action === 'edit'): ?>
      <div class="form-card">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;"><?php echo $action === 'edit' ? '✏️ تعديل ريل' : '➕ إضافة ريل جديد'; ?></h3>
        <form method="POST">
                <?php echo csrf_field(); ?>
          <input type="hidden" name="form_type" value="reel">
          <?php if ($editReel): ?><input type="hidden" name="id" value="<?php echo (int)$editReel['id']; ?>"><?php endif; ?>

          <div class="form-group">
            <label>رابط الريل من انستغرام *</label>
            <input type="url" name="instagram_url" class="form-control" placeholder="https://www.instagram.com/reel/ABC123..." value="<?php echo e($editReel['instagram_url'] ?? ''); ?>" required>
            <small style="color:var(--text-muted);font-size:11px;">انسخ الرابط من تطبيق انستغرام (reel, p, أو tv)</small>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>المصدر</label>
              <select name="source_id" class="form-control">
                <option value="">— بدون مصدر —</option>
                <?php foreach ($activeSources as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>" <?php echo (isset($editReel['source_id']) && $editReel['source_id']==$s['id'])?'selected':''; ?>>
                    <?php echo e($s['display_name']); ?> (@<?php echo e($s['username']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>الترتيب</label>
              <input type="number" name="sort_order" class="form-control" value="<?php echo (int)($editReel['sort_order'] ?? 0); ?>">
            </div>
          </div>

          <div class="form-group">
            <label>الوصف (اختياري)</label>
            <textarea name="caption" class="form-control" rows="3"><?php echo e($editReel['caption'] ?? ''); ?></textarea>
          </div>

          <div class="form-group">
            <label>رابط الصورة المصغرة (اختياري)</label>
            <input type="url" name="thumbnail_url" class="form-control" value="<?php echo e($editReel['thumbnail_url'] ?? ''); ?>">
          </div>

          <div class="form-group">
            <div class="checkbox-item">
              <input type="checkbox" name="is_active" id="is_active" <?php echo (!isset($editReel) || $editReel['is_active']) ? 'checked' : ''; ?>>
              <label for="is_active" style="margin:0;">نشط (معروض في الموقع)</label>
            </div>
          </div>

          <div style="display:flex;gap:10px;">
            <button type="submit" class="btn-primary">💾 حفظ</button>
            <a href="reels.php?tab=reels" class="btn-outline">إلغاء</a>
          </div>
        </form>
      </div>
    <?php else: ?>
      <div class="card">
        <table>
          <thead>
            <tr>
              <th>الريل</th>
              <th>المصدر</th>
              <th>الوصف</th>
              <th>الحالة</th>
              <th>التاريخ</th>
              <th>إجراءات</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reelsList as $reel): ?>
              <tr>
                <td>
                  <a href="<?php echo e($reel['instagram_url']); ?>" target="_blank" style="color:var(--primary);font-weight:600;">
                    🎬 <?php echo e($reel['shortcode']); ?>
                  </a>
                </td>
                <td><?php echo e($reel['source_name'] ?? '—'); ?></td>
                <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo e(mb_substr($reel['caption'] ?? '', 0, 60)); ?></td>
                <td>
                  <?php if ($reel['is_active']): ?>
                    <span class="badge badge-success">نشط</span>
                  <?php else: ?>
                    <span class="badge badge-muted">معطل</span>
                  <?php endif; ?>
                </td>
                <td style="color:var(--text-muted);font-size:12px;"><?php echo date('Y/m/d', strtotime($reel['created_at'])); ?></td>
                <td>
                  <a href="reels.php?tab=reels&action=edit&id=<?php echo (int)$reel['id']; ?>" class="action-btn">✏️</a>
                  <a href="reels.php?tab=reels&action=delete&id=<?php echo (int)$reel['id']; ?>" class="btn-danger" onclick="return confirm('حذف الريل؟');">🗑️</a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($reelsList)): ?>
              <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">لا توجد ريلز بعد. ابدأ بإضافة أول ريل!</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'sources'): ?>
    <?php if ($action === 'add' || $action === 'edit'): ?>
      <div class="form-card">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;"><?php echo $action === 'edit' ? '✏️ تعديل مصدر' : '➕ إضافة مصدر'; ?></h3>
        <form method="POST">
                <?php echo csrf_field(); ?>
          <input type="hidden" name="form_type" value="source">
          <?php if ($editSource): ?><input type="hidden" name="id" value="<?php echo (int)$editSource['id']; ?>"><?php endif; ?>

          <div class="form-row">
            <div class="form-group">
              <label>اسم المستخدم على انستغرام *</label>
              <input type="text" name="username" class="form-control" placeholder="aljazeera" value="<?php echo e($editSource['username'] ?? ''); ?>" required>
              <small style="color:var(--text-muted);font-size:11px;">بدون @</small>
            </div>
            <div class="form-group">
              <label>الاسم المعروض *</label>
              <input type="text" name="display_name" class="form-control" placeholder="قناة الجزيرة" value="<?php echo e($editSource['display_name'] ?? ''); ?>" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>رابط الصورة الرمزية</label>
              <input type="url" name="avatar_url" class="form-control" value="<?php echo e($editSource['avatar_url'] ?? ''); ?>">
            </div>
            <div class="form-group">
              <label>الترتيب</label>
              <input type="number" name="sort_order" class="form-control" value="<?php echo (int)($editSource['sort_order'] ?? 0); ?>">
            </div>
          </div>

          <div class="form-group">
            <div class="checkbox-item">
              <input type="checkbox" name="is_active" id="src_active" <?php echo (!isset($editSource) || $editSource['is_active']) ? 'checked' : ''; ?>>
              <label for="src_active" style="margin:0;">نشط</label>
            </div>
          </div>

          <div style="display:flex;gap:10px;">
            <button type="submit" class="btn-primary">💾 حفظ</button>
            <a href="reels.php?tab=sources" class="btn-outline">إلغاء</a>
          </div>
        </form>
      </div>
    <?php else: ?>
      <div class="card">
        <table>
          <thead>
            <tr>
              <th>المصدر</th>
              <th>المستخدم</th>
              <th>عدد الريلز</th>
              <th>الحالة</th>
              <th>إجراءات</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sourcesList as $s): ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:10px;">
                    <?php if ($s['avatar_url']): ?>
                      <img src="<?php echo e($s['avatar_url']); ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
                    <?php else: ?>
                      <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#833ab4,#fd1d1d,#fcb045);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;">📷</div>
                    <?php endif; ?>
                    <strong><?php echo e($s['display_name']); ?></strong>
                  </div>
                </td>
                <td>
                  <a href="https://www.instagram.com/<?php echo e($s['username']); ?>/" target="_blank" style="color:var(--primary);">@<?php echo e($s['username']); ?></a>
                </td>
                <td><span class="badge badge-primary"><?php echo (int)$s['reels_count']; ?></span></td>
                <td>
                  <?php if ($s['is_active']): ?>
                    <span class="badge badge-success">نشط</span>
                  <?php else: ?>
                    <span class="badge badge-muted">معطل</span>
                  <?php endif; ?>
                </td>
                <td>
                  <a href="reels.php?tab=sources&action=edit&id=<?php echo (int)$s['id']; ?>" class="action-btn">✏️</a>
                  <a href="reels.php?tab=sources&action=delete&id=<?php echo (int)$s['id']; ?>" class="btn-danger" onclick="return confirm('حذف المصدر؟');">🗑️</a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($sourcesList)): ?>
              <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted);">لا توجد مصادر بعد. ابدأ بإضافة أول مصدر!</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</div>
<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
