<?php
/**
 * نيوز فيد — قنوات يوتيوب
 * Manage the list of YouTube channels whose latest videos get pulled
 * into the homepage YouTube breaking section.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/youtube_fetch.php';
requireRole('editor');

$db = getDB();

// Auto-migrate on first panel visit.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS youtube_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        channel_id VARCHAR(40) NOT NULL UNIQUE,
        handle VARCHAR(100) DEFAULT NULL,
        display_name VARCHAR(150) NOT NULL,
        avatar_url VARCHAR(500) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        last_fetched_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS youtube_videos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_id INT NOT NULL,
        video_id VARCHAR(32) NOT NULL,
        post_url VARCHAR(500) NOT NULL,
        title VARCHAR(500) NOT NULL,
        description TEXT,
        thumbnail_url VARCHAR(500) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        posted_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_video (source_id, video_id),
        INDEX idx_posted (posted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

$action  = $_GET['action'] ?? 'list';
$error   = '';
$success = '';

if ($action === 'fetch') {
    $count = yt_sync_all_sources();
    $success = "تم جلب $count فيديو جديد";
    $action = 'list';
}

$debugReport = null;
$debugDbRows = null;
$debugParsed = null;
if ($action === 'debug' && isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM youtube_sources WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $dbgSrc = $stmt->fetch();
    if ($dbgSrc) {
        $debugReport = yt_debug_fetch_channel($dbgSrc['channel_id']);
        $debugReport['display_name'] = $dbgSrc['display_name'];

        // What's actually in the DB for this source right now?
        $rowsStmt = $db->prepare("SELECT video_id, title, posted_at, created_at FROM youtube_videos WHERE source_id = ? ORDER BY posted_at DESC, id DESC LIMIT 5");
        $rowsStmt->execute([(int)$dbgSrc['id']]);
        $debugDbRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        // What does the live parser produce RIGHT NOW for this channel?
        // Shows us whether the extraction-to-insert chain is doing the
        // right thing even when jalb actually runs. First 5 only.
        $debugParsed = array_slice(yt_fetch_channel_videos($dbgSrc['channel_id'], 5), 0, 5);
    }
}

if ($action === 'resync') {
    // Wipe all fetched video rows and re-pull — useful after upgrading
    // the parser so stale rows with fetch-time timestamps get replaced
    // by rows with real YouTube publish timestamps.
    try {
        $wiped = $db->exec("DELETE FROM youtube_videos");
    } catch (Throwable $e) { $wiped = 0; }
    $count = yt_sync_all_sources();
    $success = "تم حذف {$wiped} فيديو قديم وإعادة جلب {$count} فيديو بالتواريخ الصحيحة";
    $action = 'list';
}

if ($action === 'delete' && isset($_GET['id'])) {
    $db->prepare("DELETE FROM youtube_sources WHERE id = ?")->execute([(int)$_GET['id']]);
    $success = 'تم حذف القناة';
    $action = 'list';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id           = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $input        = trim($_POST['channel_input'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $avatar       = trim($_POST['avatar_url'] ?? '');
    $sort_order   = (int)($_POST['sort_order'] ?? 0);
    $is_active    = isset($_POST['is_active']) ? 1 : 0;

    $channel_id = yt_resolve_channel_id($input);
    if (!$channel_id) {
        $error = 'تعذّر التعرّف على القناة من المدخل. ألصق رابط قناة يوتيوب أو @handle أو UCxxx.';
    } else {
        // Auto-fill display name + avatar from YouTube if the admin
        // didn't provide them — one less step in the add flow.
        if ($display_name === '' || $avatar === '') {
            $meta = yt_fetch_channel_meta($channel_id);
            if ($display_name === '') $display_name = $meta['display_name'];
            if ($avatar === '')       $avatar       = $meta['avatar_url'];
        }
        if ($display_name === '') {
            $error = 'الاسم المعروض مطلوب (ما قدرنا نستخرجه تلقائياً)';
        } else {
            // Extract handle for storage if the input was a URL/@handle.
            $handle = null;
            if (preg_match('#youtube\.com/@([A-Za-z0-9._-]+)#i', $input, $m)) $handle = $m[1];
            elseif (preg_match('#^@?([A-Za-z0-9._-]+)$#', $input, $m) && !preg_match('#^UC[A-Za-z0-9_-]{22}$#', $input)) $handle = $m[1];

            try {
                if ($id) {
                    $stmt = $db->prepare("UPDATE youtube_sources SET channel_id=?, handle=?, display_name=?, avatar_url=?, sort_order=?, is_active=? WHERE id=?");
                    $stmt->execute([$channel_id, $handle, $display_name, $avatar, $sort_order, $is_active, $id]);
                    $success = 'تم تحديث القناة';
                } else {
                    $stmt = $db->prepare("INSERT INTO youtube_sources (channel_id, handle, display_name, avatar_url, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$channel_id, $handle, $display_name, $avatar, $sort_order, $is_active]);
                    $success = 'تم إضافة القناة';
                }
                $action = 'list';
            } catch (PDOException $e) {
                $error = 'خطأ: ' . $e->getMessage();
            }
        }
    }
}

$editSource = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM youtube_sources WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $editSource = $stmt->fetch();
}

$sourcesList = $db->query("SELECT s.*, (SELECT COUNT(*) FROM youtube_videos WHERE source_id = s.id) AS vid_count
                           FROM youtube_sources s
                           ORDER BY s.sort_order ASC, s.display_name")->fetchAll();

$recentVideos = $db->query("SELECT v.*, s.display_name
                            FROM youtube_videos v
                            JOIN youtube_sources s ON v.source_id = s.id
                            ORDER BY v.posted_at DESC LIMIT 20")->fetchAll();

$pageTitle  = 'قنوات يوتيوب - نيوز فيد';
$activePage = 'youtube';
include __DIR__ . '/includes/panel_layout_head.php';
?>
<div class="content">

  <div class="page-header">
    <div>
      <h2>▶️ قنوات يوتيوب</h2>
      <p>أضف قنوات يوتيوب. يتم سحب آخر الفيديوهات تلقائياً عبر الـ RSS المدمج من يوتيوب وعرضها في قسم الصفحة الرئيسية مع التحديث المباشر.</p>
    </div>
    <div class="page-actions">
      <a href="youtube.php?action=fetch" class="btn-outline">🔄 جلب الآن</a>
      <a href="youtube.php?action=resync" class="btn-outline"
         onclick="return confirm('سيتم حذف كل الفيديوهات المخزّنة وإعادة جلبها. متابعة؟');"
         title="امسح كل الفيديوهات وأعد جلبها بالتواريخ الأصلية من يوتيوب">♻️ إعادة جلب كاملة</a>
      <?php if ($action === 'list'): ?>
        <a href="youtube.php?action=add" class="btn-primary">➕ إضافة قناة</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($error):   ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

  <?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="form-card">
      <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;">
        <?php echo $action === 'edit' ? '✏️ تعديل قناة' : '➕ إضافة قناة يوتيوب'; ?>
      </h3>
      <form method="POST">
        <?php echo csrf_field(); ?>
        <?php if ($editSource): ?>
          <input type="hidden" name="id" value="<?php echo (int)$editSource['id']; ?>">
        <?php endif; ?>
        <div class="form-group">
          <label>القناة *</label>
          <input type="text" name="channel_input" class="form-control"
                 value="<?php echo e($editSource['channel_id'] ?? ''); ?>"
                 placeholder="https://youtube.com/@AJArabic  أو  @AJArabic  أو  UCxxx..." required>
          <small style="color:var(--text-muted);font-size:11px;">بنقبل رابط كامل أو @handle أو channel_id (UCxxx). اسم القناة وأفاتارها بنحاول نملّاهم تلقائياً إذا تركتهم فاضيين.</small>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>الاسم المعروض</label>
            <input type="text" name="display_name" class="form-control"
                   value="<?php echo e($editSource['display_name'] ?? ''); ?>"
                   placeholder="يُملأ تلقائياً من يوتيوب إذا فارغ">
          </div>
          <div class="form-group">
            <label>رابط الأفاتار</label>
            <input type="url" name="avatar_url" class="form-control"
                   value="<?php echo e($editSource['avatar_url'] ?? ''); ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="sort_order" class="form-control"
                   value="<?php echo (int)($editSource['sort_order'] ?? 0); ?>">
          </div>
          <div class="form-group">
            <div class="checkbox-item" style="margin-top:28px;">
              <input type="checkbox" name="is_active" id="yt_active"
                     <?php echo (!$editSource || $editSource['is_active']) ? 'checked' : ''; ?>>
              <label for="yt_active" style="margin:0;">نشط</label>
            </div>
          </div>
        </div>
        <div style="display:flex;gap:10px;">
          <button type="submit" class="btn-primary">💾 حفظ</button>
          <a href="youtube.php" class="btn-outline">إلغاء</a>
        </div>
      </form>
    </div>

  <?php else: ?>

    <?php if ($debugReport): ?>
      <div class="card" style="margin-bottom:20px;padding:18px;">
        <h3 style="margin:0 0 10px;font-size:15px;">🩺 تشخيص جلب <code><?php echo e($debugReport['display_name'] ?? ''); ?></code></h3>
        <p style="color:var(--text-muted);font-size:12.5px;margin:0 0 14px;">
          بيعرض شو الـ Atom feed بيرجّع وأي حقل تاريخ بالضبط بيوصلنا. اذا كل الـ <code>dates_seen</code> فارغة فـ YouTube غيّر شكل الـ feed. اذا فيها قيم بس <code>posted_at</code> بالـ DB مختلف فالمشكلة بمكان تاني.
        </p>

        <div style="border:1px solid var(--border,#e0e3e8);border-radius:10px;padding:12px;margin-bottom:12px;background:var(--bg2,#fafafa);">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
            <strong>HTTP</strong>
            <span class="badge <?php echo ($debugReport['http']['code']>=200 && $debugReport['http']['code']<300) ? 'badge-success' : 'badge-danger'; ?>">HTTP <?php echo (int)$debugReport['http']['code']; ?></span>
            <span class="badge badge-muted">حجم <?php echo (int)$debugReport['http']['size']; ?> بايت</span>
            <span class="badge badge-muted">وقت <?php echo e($debugReport['http']['total_time']); ?>ث</span>
            <?php if (!empty($debugReport['http']['curl_error'])): ?>
              <span class="badge badge-danger">curl: <?php echo e($debugReport['http']['curl_error']); ?></span>
            <?php endif; ?>
          </div>
          <code style="display:block;word-break:break-all;background:#fff;padding:6px 8px;border-radius:6px;border:1px solid var(--border,#e0e3e8);font-size:11px;"><?php echo e($debugReport['http']['url']); ?></code>
        </div>

        <?php if (!empty($debugReport['error'])): ?>
          <div class="alert alert-danger">❌ <?php echo e($debugReport['error']); ?></div>
        <?php endif; ?>

        <?php if (!empty($debugReport['entries'])): ?>
          <h4 style="margin:16px 0 8px;font-size:13px;">أول 5 فيديوهات بحسب الـ feed:</h4>
          <?php foreach ($debugReport['entries'] as $idx => $ent): ?>
            <div style="border:1px solid var(--border,#e0e3e8);border-radius:8px;padding:10px;margin-bottom:8px;background:#fff;font-size:12px;">
              <div style="margin-bottom:6px;"><strong><?php echo (int)$idx + 1; ?>.</strong> <code><?php echo e($ent['video_id']); ?></code> — <?php echo e(mb_substr($ent['title'], 0, 100)); ?></div>
              <table style="width:100%;font-size:11.5px;">
                <?php foreach ($ent['dates_seen'] as $k => $v): ?>
                  <tr>
                    <td style="padding:3px 6px;color:var(--text-muted);width:180px;"><code><?php echo e($k); ?></code></td>
                    <td style="padding:3px 6px;<?php echo $v === '' ? 'color:#b91c1c;font-style:italic;' : 'color:#16a34a;font-weight:600;'; ?>">
                      <?php echo $v === '' ? '(فارغ)' : e($v); ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </table>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($debugReport['first_entry_raw'])): ?>
          <details style="margin-top:12px;">
            <summary style="cursor:pointer;color:var(--text-muted);font-size:12px;">الـ XML الخام لأول &lt;entry&gt;</summary>
            <pre style="margin-top:8px;padding:10px;background:#fff;border:1px solid var(--border,#e0e3e8);border-radius:6px;max-height:280px;overflow:auto;font-size:11px;white-space:pre-wrap;"><?php echo e($debugReport['first_entry_raw']); ?></pre>
          </details>
        <?php endif; ?>

        <?php if ($debugParsed !== null): ?>
          <h4 style="margin:18px 0 8px;font-size:13px;">نتيجة الـ parser الحية (شو بيرجّع <code>yt_fetch_channel_videos</code> الآن):</h4>
          <?php if (empty($debugParsed)): ?>
            <div class="alert alert-danger">❌ الـ parser رجّع قائمة فاضية — هاد السبب!</div>
          <?php else: ?>
            <div style="font-size:12px;background:#fff;border:1px solid var(--border,#e0e3e8);border-radius:8px;padding:10px;">
              <?php foreach ($debugParsed as $idx => $p): ?>
                <div style="padding:6px 0;<?php echo $idx > 0 ? 'border-top:1px dashed var(--border,#e0e3e8);' : ''; ?>">
                  <strong><?php echo (int)$idx + 1; ?>.</strong>
                  <code><?php echo e($p['video_id']); ?></code>
                  — <span style="color:#16a34a;font-weight:600;"><?php echo e($p['posted_at']); ?></span>
                  — <?php echo e(mb_substr($p['title'], 0, 80)); ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($debugDbRows !== null): ?>
          <h4 style="margin:18px 0 8px;font-size:13px;">المخزّن فعلاً بالـ DB لهاي القناة (أحدث 5 rows):</h4>
          <?php if (empty($debugDbRows)): ?>
            <div class="alert alert-muted">لا توجد rows مخزّنة بعد — اضغط "🔄 جلب الآن" أو "♻️ إعادة جلب كاملة".</div>
          <?php else: ?>
            <div style="font-size:12px;background:#fff;border:1px solid var(--border,#e0e3e8);border-radius:8px;padding:10px;">
              <table style="width:100%;font-size:12px;">
                <tr style="color:var(--text-muted);font-size:11px;">
                  <th style="text-align:right;padding:4px;">video_id</th>
                  <th style="text-align:right;padding:4px;">posted_at (من YouTube)</th>
                  <th style="text-align:right;padding:4px;">created_at (وقت الجلب)</th>
                  <th style="text-align:right;padding:4px;">العنوان</th>
                </tr>
                <?php foreach ($debugDbRows as $r): ?>
                  <tr style="border-top:1px dashed var(--border,#e0e3e8);">
                    <td style="padding:6px 4px;"><code><?php echo e($r['video_id']); ?></code></td>
                    <td style="padding:6px 4px;color:#16a34a;font-weight:600;"><?php echo e($r['posted_at'] ?? '—'); ?></td>
                    <td style="padding:6px 4px;color:var(--text-muted);"><?php echo e($r['created_at'] ?? '—'); ?></td>
                    <td style="padding:6px 4px;"><?php echo e(mb_substr($r['title'], 0, 60)); ?></td>
                  </tr>
                <?php endforeach; ?>
              </table>
              <p style="margin-top:10px;color:var(--text-muted);font-size:11.5px;">
                ⚠︎ إذا <code>posted_at</code> في الـ DB == <code>created_at</code> لكل الـ rows، فالـ parser مش بيحفظ التاريخ بشكل صحيح رغم إنه في الـ debug بيطلّعه صح — يعني مشكلة بالـ INSERT.
              </p>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <a href="youtube.php" class="btn-outline" style="margin-top:12px;display:inline-block;">↩︎ رجوع</a>
      </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:20px;">
      <table>
        <thead>
          <tr>
            <th>الاسم</th>
            <th>القناة</th>
            <th>عدد الفيديوهات</th>
            <th>آخر جلب</th>
            <th>الحالة</th>
            <th>إجراءات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sourcesList as $s): ?>
            <tr>
              <td><strong><?php echo e($s['display_name']); ?></strong></td>
              <td>
                <a href="https://youtube.com/channel/<?php echo e($s['channel_id']); ?>" target="_blank" style="color:var(--primary);">
                  <?php echo e($s['handle'] ? '@' . $s['handle'] : $s['channel_id']); ?>
                </a>
              </td>
              <td><span class="badge badge-primary"><?php echo (int)$s['vid_count']; ?></span></td>
              <td style="color:var(--text-muted);font-size:12px;"><?php echo $s['last_fetched_at'] ? date('Y/m/d H:i', strtotime($s['last_fetched_at'])) : '—'; ?></td>
              <td><?php echo $s['is_active'] ? '<span class="badge badge-success">نشط</span>' : '<span class="badge badge-muted">معطل</span>'; ?></td>
              <td>
                <a href="youtube.php?action=edit&id=<?php echo (int)$s['id']; ?>" class="action-btn" title="تعديل">✏️</a>
                <a href="youtube.php?action=debug&id=<?php echo (int)$s['id']; ?>" class="action-btn" title="تشخيص الجلب">🩺</a>
                <a href="youtube.php?action=delete&id=<?php echo (int)$s['id']; ?>" class="btn-danger" onclick="return confirm('حذف القناة؟');">🗑️</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($sourcesList)): ?>
            <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">لا توجد قنوات بعد. أضف أول قناة!</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <h3 style="margin:24px 0 12px;font-size:16px;">آخر الفيديوهات المسحوبة</h3>
    <div class="card">
      <table>
        <thead>
          <tr><th>القناة</th><th>العنوان</th><th>التاريخ</th><th>رابط</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recentVideos as $v): ?>
            <tr>
              <td><?php echo e($v['display_name']); ?></td>
              <td style="max-width:500px;"><?php echo e(mb_substr($v['title'], 0, 120)); ?><?php echo mb_strlen($v['title']) > 120 ? '...' : ''; ?></td>
              <td style="font-size:12px;color:var(--text-muted);"><?php echo $v['posted_at'] ? date('Y/m/d H:i', strtotime($v['posted_at'])) : '—'; ?></td>
              <td><a href="<?php echo e($v['post_url']); ?>" target="_blank">🔗</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($recentVideos)): ?>
            <tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text-muted);">اضغط "🔄 جلب الآن" لجلب الفيديوهات</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  <?php endif; ?>

</div>
<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
