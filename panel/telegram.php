<?php
/**
 * نيوز فيد - مصادر تيليغرام للأخبار العاجلة
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/telegram_fetch.php';
requireRole('editor');

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

$pageTitle = 'مصادر تيليغرام - نيوز فيد';
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
    <?php if ($action === 'add'): ?>
    <style>
      .tg-add-tabs { display:flex; gap:6px; margin-bottom:18px; border-bottom:2px solid var(--border,#e0e3e8); }
      .tg-add-tab {
        padding:10px 18px; font-weight:700; font-size:14px; font-family:inherit;
        background:none; border:0; cursor:pointer; color:var(--text-muted,#6b7280);
        border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .2s;
      }
      .tg-add-tab:hover { color:var(--text,#1a1a2e); }
      .tg-add-tab.active { color:var(--primary,#1a73e8); border-bottom-color:var(--primary,#1a73e8); }
      .tg-add-pane { display:none; }
      .tg-add-pane.active { display:block; animation:tgFadeIn .2s ease; }
      @keyframes tgFadeIn { from{opacity:0; transform:translateY(4px);} to{opacity:1; transform:translateY(0);} }
      .tg-lookup-row { display:flex; gap:8px; align-items:flex-start; }
      .tg-lookup-row .form-control { flex:1; }
      .tg-lookup-row .btn-primary { white-space:nowrap; height:42px; }
      .tg-hint { color:var(--text-muted,#6b7280); font-size:12px; margin-top:6px; display:block; }
      .tg-preview {
        margin-top:14px; padding:14px; border-radius:12px;
        background:var(--bg2,#fafafa); border:1px solid var(--border,#e0e3e8);
        display:none; gap:14px; align-items:center;
      }
      .tg-preview.show { display:flex; }
      .tg-preview.error { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
      .tg-preview-avatar {
        width:64px; height:64px; border-radius:50%; flex-shrink:0;
        background:linear-gradient(135deg,#1a73e8,#4f46e5);
        display:flex; align-items:center; justify-content:center;
        color:#fff; font-size:24px; font-weight:900; overflow:hidden;
      }
      .tg-preview-avatar img { width:100%; height:100%; object-fit:cover; }
      .tg-preview-info { flex:1; min-width:0; }
      .tg-preview-name { font-size:15px; font-weight:800; margin-bottom:4px; color:var(--text,#1a1a2e); }
      .tg-preview-user { font-size:12px; color:var(--primary,#1a73e8); font-weight:600; }
      .tg-preview-desc {
        font-size:12.5px; color:var(--text-muted,#6b7280); margin-top:6px;
        display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
      }
      .tg-preview-meta { font-size:11px; color:var(--text-muted,#6b7280); margin-top:4px; }
      .tg-loading { color:var(--text-muted,#6b7280); font-size:13px; padding:10px 0; display:none; }
      .tg-loading.show { display:block; }
      .tg-confirm-row { margin-top:14px; display:none; gap:10px; align-items:center; }
      .tg-confirm-row.show { display:flex; }
      .tg-confirm-row .tg-edit-fields { flex:1; display:flex; gap:10px; }
      .tg-confirm-row .tg-edit-fields input { flex:1; }
      .tg-results { margin-top:14px; display:none; flex-direction:column; gap:8px; }
      .tg-results.show { display:flex; }
      .tg-result-item {
        display:flex; align-items:center; gap:12px; padding:10px 12px;
        border:1px solid var(--border,#e0e3e8); border-radius:10px;
        background:var(--bg,#fff); cursor:pointer; transition:all .15s;
        text-align:right;
      }
      .tg-result-item:hover { border-color:var(--primary,#1a73e8); background:var(--bg2,#f5f8ff); transform:translateX(-2px); }
      .tg-result-item .tg-result-icon {
        width:36px; height:36px; border-radius:50%; flex-shrink:0;
        background:linear-gradient(135deg,#0ea5e9,#1a73e8); color:#fff;
        display:flex; align-items:center; justify-content:center;
        font-size:14px; font-weight:900;
      }
      .tg-result-item .tg-result-info { flex:1; min-width:0; }
      .tg-result-item .tg-result-user { font-size:13px; font-weight:700; color:var(--text,#1a1a2e); }
      .tg-result-item .tg-result-hint { font-size:11px; color:var(--text-muted,#6b7280); margin-top:2px; }
      .tg-results-empty { padding:14px; text-align:center; color:var(--text-muted,#6b7280); font-size:13px; }
    </style>
    <div class="form-card">
      <h3 style="font-size:16px;font-weight:700;margin-bottom:10px;">➕ إضافة قناة تيليغرام</h3>
      <p style="color:var(--text-muted,#6b7280);font-size:13px;margin-bottom:16px;">اختر طريقة الإضافة المناسبة لك — إما من خلال لصق الرابط/اليوزرنيم، أو البحث باسم القناة.</p>

      <div class="tg-add-tabs" role="tablist">
        <button type="button" class="tg-add-tab active" data-tab="link">🔗 رابط أو يوزرنيم</button>
        <button type="button" class="tg-add-tab" data-tab="search">🔎 بحث تلقائي</button>
      </div>

      <!-- TAB 1: Link / Username -->
      <div class="tg-add-pane active" data-pane="link">
        <label style="display:block;font-weight:600;margin-bottom:6px;">الصق رابط القناة أو اليوزرنيم</label>
        <div class="tg-lookup-row">
          <input type="text" id="tgLinkInput" class="form-control" placeholder="https://t.me/aljazeera  أو  @aljazeera  أو  aljazeera">
          <button type="button" class="btn-primary" id="tgLinkVerify">✓ تحقق</button>
        </div>
        <small class="tg-hint">يجب أن تكون قناة عامة (Public). تقبل الصيغ: <code>@name</code>, <code>t.me/name</code>, <code>https://t.me/name</code></small>
        <div class="tg-loading" data-loading="link">⏳ جاري التحقق من القناة...</div>
        <div class="tg-preview" data-preview="link"></div>
      </div>

      <!-- TAB 2: Search by name -->
      <div class="tg-add-pane" data-pane="search">
        <label style="display:block;font-weight:600;margin-bottom:6px;">ابحث عن القناة بالاسم</label>
        <input type="text" id="tgSearchInput" class="form-control" placeholder="اكتب اسم القناة عربي أو إنجليزي، مثل: الجزيرة عاجل">
        <small class="tg-hint">نبحث في محرّك بحث عام عن روابط قنوات تيليغرام المطابقة، ثم تختار القناة من القائمة.</small>
        <div class="tg-loading" data-loading="search">⏳ جاري البحث...</div>
        <div class="tg-results" id="tgSearchResults"></div>
        <div class="tg-preview" data-preview="search"></div>
      </div>

      <!-- Shared: confirmation form (revealed after a successful lookup) -->
      <form method="POST" id="tgAddForm" style="margin-top:20px;display:none;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="username" id="tgFormUsername">
        <input type="hidden" name="avatar_url" id="tgFormAvatar">
        <div class="form-row">
          <div class="form-group">
            <label>الاسم المعروض *</label>
            <input type="text" name="display_name" id="tgFormDisplayName" class="form-control" required>
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="sort_order" class="form-control" value="0">
          </div>
        </div>
        <div class="form-group">
          <div class="checkbox-item">
            <input type="checkbox" name="is_active" id="tg_active_new" checked>
            <label for="tg_active_new" style="margin:0;">نشط</label>
          </div>
        </div>
        <div style="display:flex;gap:10px;">
          <button type="submit" class="btn-primary">💾 حفظ القناة</button>
          <a href="telegram.php" class="btn-outline">إلغاء</a>
        </div>
      </form>

      <?php if (!($action === 'add')): ?>
        <a href="telegram.php" class="btn-outline" style="margin-top:14px;display:inline-block;">إلغاء</a>
      <?php endif; ?>
    </div>

    <script>
    (function(){
      var tabs = document.querySelectorAll('.tg-add-tab');
      var panes = document.querySelectorAll('.tg-add-pane');
      tabs.forEach(function(t){
        t.addEventListener('click', function(){
          tabs.forEach(function(x){ x.classList.remove('active'); });
          panes.forEach(function(x){ x.classList.remove('active'); });
          t.classList.add('active');
          document.querySelector('[data-pane="'+t.dataset.tab+'"]').classList.add('active');
        });
      });

      var form = document.getElementById('tgAddForm');
      var elUsername = document.getElementById('tgFormUsername');
      var elAvatar   = document.getElementById('tgFormAvatar');
      var elDisplay  = document.getElementById('tgFormDisplayName');

      function escapeHtml(s){
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
          return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
      }

      function renderPreview(pane, data) {
        var target = document.querySelector('[data-preview="'+pane+'"]');
        target.classList.remove('error');
        if (!data || !data.ok) {
          target.classList.add('show','error');
          target.innerHTML = '<div>⚠ ' + escapeHtml((data && data.error) || 'لم يتم العثور على القناة') + '</div>';
          form.style.display = 'none';
          return;
        }
        var initial = (data.display_name || data.username || '?').trim().charAt(0).toUpperCase();
        var avatar = data.avatar_url
          ? '<img src="'+escapeHtml(data.avatar_url)+'" alt="">'
          : escapeHtml(initial);
        var subs = data.subscribers ? '👥 ' + escapeHtml(data.subscribers) + ' مشترك' : '';
        target.classList.add('show');
        target.innerHTML =
          '<div class="tg-preview-avatar">'+avatar+'</div>' +
          '<div class="tg-preview-info">' +
            '<div class="tg-preview-name">'+escapeHtml(data.display_name || data.username)+'</div>' +
            '<div class="tg-preview-user"><a href="'+escapeHtml(data.channel_url)+'" target="_blank">@'+escapeHtml(data.username)+'</a></div>' +
            (data.description ? '<div class="tg-preview-desc">'+escapeHtml(data.description)+'</div>' : '') +
            (subs ? '<div class="tg-preview-meta">'+subs+'</div>' : '') +
          '</div>';

        // populate the hidden/confirmation form
        elUsername.value = data.username || '';
        elAvatar.value   = data.avatar_url || '';
        elDisplay.value  = data.display_name || data.username || '';
        form.style.display = 'block';
      }

      function lookup(pane, query) {
        var target = document.querySelector('[data-preview="'+pane+'"]');
        var loading = document.querySelector('[data-loading="'+pane+'"]');
        target.classList.remove('show','error');
        target.innerHTML = '';
        form.style.display = 'none';
        if (!query || query.length < 3) return;
        loading.classList.add('show');
        fetch('telegram_lookup.php?q=' + encodeURIComponent(query), { credentials:'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(data){ loading.classList.remove('show'); renderPreview(pane, data); })
          .catch(function(){ loading.classList.remove('show'); renderPreview(pane, {ok:false, error:'خطأ في الاتصال'}); });
      }

      // Tab 1: button click + Enter
      var linkInput = document.getElementById('tgLinkInput');
      document.getElementById('tgLinkVerify').addEventListener('click', function(){
        lookup('link', linkInput.value.trim());
      });
      linkInput.addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); lookup('link', linkInput.value.trim()); }
      });

      // Tab 2: real-name search via DuckDuckGo + click-to-verify
      var searchInput = document.getElementById('tgSearchInput');
      var searchResults = document.getElementById('tgSearchResults');
      var searchLoading = document.querySelector('[data-loading="search"]');
      var searchPreview = document.querySelector('[data-preview="search"]');
      var searchTimer = null;

      function clearSearchUI() {
        searchResults.classList.remove('show');
        searchResults.innerHTML = '';
        searchPreview.classList.remove('show','error');
        searchPreview.innerHTML = '';
        form.style.display = 'none';
      }

      function renderSearchResults(data) {
        searchResults.innerHTML = '';
        if (!data || !data.ok) {
          searchResults.classList.add('show');
          searchResults.innerHTML = '<div class="tg-results-empty">⚠ ' + escapeHtml((data && data.error) || 'تعذّر البحث') + '</div>';
          return;
        }
        if (!data.results || !data.results.length) {
          searchResults.classList.add('show');
          searchResults.innerHTML = '<div class="tg-results-empty">لا توجد نتائج — جرّب كلمة مختلفة أو الصق الرابط في التبويب الآخر.</div>';
          return;
        }
        data.results.forEach(function(r){
          var div = document.createElement('div');
          div.className = 'tg-result-item';
          var initial = (r.username || '?').trim().charAt(0).toUpperCase();
          div.innerHTML =
            '<div class="tg-result-icon">' + escapeHtml(initial) + '</div>' +
            '<div class="tg-result-info">' +
              '<div class="tg-result-user">@' + escapeHtml(r.username) + '</div>' +
              '<div class="tg-result-hint">اضغط للتحقق وعرض تفاصيل القناة</div>' +
            '</div>';
          div.addEventListener('click', function(){
            // Verify the picked username via the lookup endpoint, then render preview.
            lookup('search', r.username);
          });
          searchResults.appendChild(div);
        });
        searchResults.classList.add('show');
      }

      function runSearch(query) {
        clearSearchUI();
        if (!query || query.length < 2) return;
        searchLoading.classList.add('show');
        fetch('telegram_search.php?q=' + encodeURIComponent(query), { credentials:'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(data){
            searchLoading.classList.remove('show');
            renderSearchResults(data);
          })
          .catch(function(){
            searchLoading.classList.remove('show');
            renderSearchResults({ok:false, error:'خطأ في الاتصال بمحرك البحث'});
          });
      }

      searchInput.addEventListener('input', function(){
        clearTimeout(searchTimer);
        var v = searchInput.value.trim();
        if (v.length < 2) { clearSearchUI(); return; }
        searchTimer = setTimeout(function(){ runSearch(v); }, 600);
      });
      searchInput.addEventListener('keydown', function(e){
        if (e.key === 'Enter') {
          e.preventDefault();
          clearTimeout(searchTimer);
          runSearch(searchInput.value.trim());
        }
      });
    })();
    </script>
    <?php else: /* edit mode — keep original simple form */ ?>
    <div class="form-card">
      <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;">✏️ تعديل قناة</h3>
      <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int)$editSource['id']; ?>">
        <div class="form-row">
          <div class="form-group">
            <label>اسم القناة على تيليغرام *</label>
            <input type="text" name="username" class="form-control" value="<?php echo e($editSource['username']); ?>" required>
            <small style="color:var(--text-muted);font-size:11px;">بدون @</small>
          </div>
          <div class="form-group">
            <label>الاسم المعروض *</label>
            <input type="text" name="display_name" class="form-control" value="<?php echo e($editSource['display_name']); ?>" required>
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
            <input type="checkbox" name="is_active" id="tg_active" <?php echo $editSource['is_active'] ? 'checked' : ''; ?>>
            <label for="tg_active" style="margin:0;">نشط</label>
          </div>
        </div>
        <div style="display:flex;gap:10px;">
          <button type="submit" class="btn-primary">💾 حفظ</button>
          <a href="telegram.php" class="btn-outline">إلغاء</a>
        </div>
      </form>
    </div>
    <?php endif; ?>
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
