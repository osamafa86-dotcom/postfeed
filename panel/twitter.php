<?php
/**
 * نيوز فيد — مصادر تويتر/X
 * Manage the list of Twitter handles whose tweets get scraped into the
 * homepage "Twitter breaking" section.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/twitter_fetch.php';
requireRole('editor');

$db = getDB();

// Auto-migrate — mirrors the Telegram pattern so first-visit after a
// deploy doesn't blow up if migrate.php hasn't been run.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS twitter_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        display_name VARCHAR(150) NOT NULL,
        avatar_url VARCHAR(500) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        last_fetched_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS twitter_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_id INT NOT NULL,
        tweet_id VARCHAR(32) NOT NULL,
        post_url VARCHAR(500) NOT NULL,
        text TEXT,
        image_url VARCHAR(500) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        posted_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_tweet (source_id, tweet_id),
        INDEX idx_posted (posted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

$action  = $_GET['action'] ?? 'list';
$error   = '';
$success = '';

if ($action === 'fetch') {
    // $force=true bypasses the per-source freshness floor so the admin
    // button always re-hits Twitter — otherwise clicking "🔄 جلب الآن"
    // 30 seconds after the SSE scraper just ran would no-op silently.
    $count = tw_sync_all_sources(true);
    $success = "تم جلب $count تغريدة جديدة";
    $action = 'list';
}

$debugReport = null;
if ($action === 'debug' && isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM twitter_sources WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $dbgSrc = $stmt->fetch();
    if ($dbgSrc) {
        $debugReport = tw_debug_fetch_source($dbgSrc['username']);
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    $db->prepare("DELETE FROM twitter_sources WHERE id = ?")->execute([(int)$_GET['id']]);
    $success = 'تم حذف المصدر';
    $action = 'list';
}

// Save the authenticated X session cookies (separate form from source
// CRUD). These power the GraphQL transport — the only reliable path in
// 2026 now that anonymous scraping is IP-blocked.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_session'])) {
    $authTok = trim($_POST['twitter_auth_token'] ?? '');
    $ct0     = trim($_POST['twitter_ct0'] ?? '');
    // Tolerate a pasted "name=value; name=value" cookie string — pull
    // the two we need out of it so the admin can paste the whole thing.
    if ($authTok === '' || $ct0 === '') {
        $blob = $_POST['twitter_auth_token'] ?? '';
        if (preg_match('/auth_token=([0-9a-f]+)/i', $blob, $m)) $authTok = $m[1];
        if (preg_match('/ct0=([0-9a-z]+)/i', $blob, $m))        $ct0     = $m[1];
    }
    $up = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $up->execute(['twitter_auth_token', $authTok]);
    $up->execute(['twitter_ct0', $ct0]);
    if (function_exists('cache_forget')) cache_forget('settings_all');
    $success = ($authTok !== '' && $ct0 !== '')
        ? 'تم حفظ كوكيز جلسة X ✓ — اضغط "🔄 جلب الآن" ثم 🩺 للتأكد'
        : 'تم مسح كوكيز جلسة X';
    $action = 'list';
}

// Save the TwitterAPI.io key (separate form). This is the preferred 2026
// transport — residential proxies on the gateway side mean the
// datacenter IP block doesn't bite us, and there's no account-ban risk.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_apiio'])) {
    $apiKey = trim($_POST['twitterapi_io_key'] ?? '');
    $up = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $up->execute(['twitterapi_io_key', $apiKey]);
    if (function_exists('cache_forget')) cache_forget('settings_all');
    $success = $apiKey !== ''
        ? 'تم حفظ مفتاح TwitterAPI.io ✓ — اضغط "🔄 جلب الآن" ثم 🩺 للتأكد'
        : 'تم مسح مفتاح TwitterAPI.io';
    $action = 'list';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['save_session']) && !isset($_POST['save_apiio'])) {
    $id           = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $username     = ltrim(trim($_POST['username'] ?? ''), '@');
    $display_name = trim($_POST['display_name'] ?? '');
    $avatar       = trim($_POST['avatar_url'] ?? '');
    $sort_order   = (int)($_POST['sort_order'] ?? 0);
    $is_active    = isset($_POST['is_active']) ? 1 : 0;
    $fallback_rss = trim($_POST['fallback_rss_url'] ?? '');

    // Accept a pasted x.com / twitter.com URL and extract the handle.
    if (preg_match('#(?:twitter\.com|x\.com)/([A-Za-z0-9_]{1,15})#', $username, $mm)) {
        $username = $mm[1];
    }

    if ($fallback_rss !== '' && !preg_match('#^https?://#i', $fallback_rss)) {
        $error = 'رابط RSS الاحتياطي يجب أن يبدأ بـ http:// أو https://';
    } elseif ($username === '' || $display_name === '') {
        $error = 'اسم المستخدم والاسم المعروض مطلوبان';
    } elseif (!preg_match('/^[A-Za-z0-9_]{1,15}$/', $username)) {
        $error = 'اسم المستخدم يجب أن يكون حروف/أرقام إنجليزية (حتى 15 حرف)';
    } else {
        // Make sure the new columns exist before we try to write to them.
        try {
            $db->exec("ALTER TABLE twitter_sources
                        ADD COLUMN last_error VARCHAR(500) DEFAULT NULL,
                        ADD COLUMN last_new_count INT DEFAULT 0,
                        ADD COLUMN consecutive_failures INT DEFAULT 0,
                        ADD COLUMN fallback_rss_url VARCHAR(500) DEFAULT NULL");
        } catch (Throwable $e) {}
        try {
            $db->exec("ALTER TABLE twitter_sources ADD COLUMN fallback_rss_url VARCHAR(500) DEFAULT NULL");
        } catch (Throwable $e) {}

        try {
            if ($id) {
                $stmt = $db->prepare("UPDATE twitter_sources SET username=?, display_name=?, avatar_url=?, sort_order=?, is_active=?, fallback_rss_url=? WHERE id=?");
                $stmt->execute([$username, $display_name, $avatar, $sort_order, $is_active, $fallback_rss ?: null, $id]);
                $success = 'تم تحديث الحساب';
            } else {
                $stmt = $db->prepare("INSERT INTO twitter_sources (username, display_name, avatar_url, sort_order, is_active, fallback_rss_url) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $display_name, $avatar, $sort_order, $is_active, $fallback_rss ?: null]);
                $success = 'تم إضافة الحساب';
            }
            $action = 'list';
        } catch (PDOException $e) {
            $error = 'خطأ: ' . $e->getMessage();
        }
    }
}

$editSource = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM twitter_sources WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $editSource = $stmt->fetch();
}

$sourcesList = $db->query("SELECT s.*, (SELECT COUNT(*) FROM twitter_messages WHERE source_id = s.id) AS msg_count
                           FROM twitter_sources s
                           ORDER BY s.sort_order ASC, s.display_name")->fetchAll();

$recentMsgs = $db->query("SELECT m.*, s.display_name, s.username
                          FROM twitter_messages m
                          JOIN twitter_sources s ON m.source_id = s.id
                          ORDER BY m.posted_at DESC LIMIT 20")->fetchAll();

// Current X session state (for the cookie card). We never re-display the
// stored cookie values — just whether they're set — so a shoulder-surfer
// or screenshot can't lift the session.
$twAuthSet = trim((string)getSetting('twitter_auth_token', '')) !== '';
$twCt0Set  = trim((string)getSetting('twitter_ct0', '')) !== '';
$twSessionReady = $twAuthSet && $twCt0Set;
$twApiKeySet   = trim((string)getSetting('twitterapi_io_key', '')) !== '';

$pageTitle  = 'مصادر تويتر - نيوز فيد';
$activePage = 'twitter';
include __DIR__ . '/includes/panel_layout_head.php';
?>
<div class="content">

  <div class="page-header">
    <div>
      <h2>🐦 مصادر تويتر / X (الأخبار العاجلة)</h2>
      <p>أضف حسابات تويتر عامة. يتم سحب آخر التغريدات تلقائياً وعرضها في قسم الصفحة الرئيسية مع التحديث المباشر.</p>
    </div>
    <div class="page-actions">
      <a href="twitter.php?action=fetch" class="btn-outline">🔄 جلب الآن</a>
      <?php if ($action === 'list'): ?>
        <a href="twitter.php?action=add" class="btn-primary">➕ إضافة حساب</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($error):   ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

  <?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="form-card">
      <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;">
        <?php echo $action === 'edit' ? '✏️ تعديل حساب' : '➕ إضافة حساب تويتر'; ?>
      </h3>
      <form method="POST">
        <?php echo csrf_field(); ?>
        <?php if ($editSource): ?>
          <input type="hidden" name="id" value="<?php echo (int)$editSource['id']; ?>">
        <?php endif; ?>
        <div class="form-row">
          <div class="form-group">
            <label>اسم المستخدم *</label>
            <input type="text" name="username" class="form-control"
                   value="<?php echo e($editSource['username'] ?? ''); ?>"
                   placeholder="@elonmusk  أو  https://x.com/elonmusk" required>
            <small style="color:var(--text-muted);font-size:11px;">بدون @، أو الصق الرابط الكامل</small>
          </div>
          <div class="form-group">
            <label>الاسم المعروض *</label>
            <input type="text" name="display_name" class="form-control"
                   value="<?php echo e($editSource['display_name'] ?? ''); ?>" required>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>رابط الأفاتار</label>
            <input type="url" name="avatar_url" class="form-control"
                   value="<?php echo e($editSource['avatar_url'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="sort_order" class="form-control"
                   value="<?php echo (int)($editSource['sort_order'] ?? 0); ?>">
          </div>
        </div>
        <div class="form-group">
          <label>🔄 رابط RSS احتياطي <small style="color:var(--text-muted);font-weight:normal;">(اختياري)</small></label>
          <input type="url" name="fallback_rss_url" class="form-control" dir="ltr"
                 value="<?php echo e($editSource['fallback_rss_url'] ?? ''); ?>"
                 placeholder="https://example.com/feed.xml">
          <small style="color:var(--text-muted);font-size:11.5px;line-height:1.6;display:block;margin-top:6px;">
            إذا فشل سحب التغريدات من تويتر (Nitter محجوب لهذا الحساب)، سنسحب الأخبار من هذا الرابط بدلاً عنه.
            <br>أمثلة: <code dir="ltr">https://aljazeera.net/feeds/news.xml</code> أو <code dir="ltr">https://rsshub.app/twitter/user/USERNAME</code>
          </small>
        </div>
        <div class="form-group">
          <div class="checkbox-item">
            <input type="checkbox" name="is_active" id="tw_active"
                   <?php echo (!$editSource || $editSource['is_active']) ? 'checked' : ''; ?>>
            <label for="tw_active" style="margin:0;">نشط</label>
          </div>
        </div>
        <div style="display:flex;gap:10px;">
          <button type="submit" class="btn-primary">💾 حفظ</button>
          <a href="twitter.php" class="btn-outline">إلغاء</a>
        </div>
      </form>
    </div>

  <?php else: ?>

    <!-- ── TwitterAPI.io key card (preferred transport) ────────────── -->
    <div class="card" style="margin-bottom:20px;padding:18px;border:1px solid <?php echo $twApiKeySet ? '#16a34a33' : '#3b82f655'; ?>;background:<?php echo $twApiKeySet ? '#f0fdf4' : '#eff6ff'; ?>;">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
        <h3 style="margin:0;font-size:15px;">🚀 TwitterAPI.io (الأكثر موثوقية — موصى به)</h3>
        <?php if ($twApiKeySet): ?>
          <span class="badge badge-success">مُفعّل ✓</span>
        <?php else: ?>
          <span class="badge" style="background:#dbeafe;color:#1e40af;">غير مُفعّل</span>
        <?php endif; ?>
      </div>
      <p style="color:var(--text-muted);font-size:12.5px;line-height:1.9;margin:0 0 14px;">
        خدمة طرف-ثالث على proxies حقيقية — تتجاوز حجب IP السيرفر تلقائياً، صفر صيانة، صفر خطر حظر حسابك.
        السعر: <strong>$0.15 لكل 1000 تغريدة</strong> (~$1-3/شهر لـ 10 حسابات). تسجيل عبر Google يعطيك <strong>$1 رصيد مجاني</strong> بدون بطاقة.
        <br><strong>كيف؟</strong>
        ادخل <a href="https://twitterapi.io" target="_blank" rel="noopener noreferrer" style="color:#2563eb;">twitterapi.io</a>
        ← سجّل بـ Google ← اذهب لتبويب Dashboard/API Keys ← انسخ المفتاح ← الصقه هون.
      </p>
      <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="save_apiio" value="1">
        <div class="form-group">
          <label>مفتاح TwitterAPI.io <small style="color:var(--text-muted);font-weight:normal;">(يبدأ غالباً بأحرف وأرقام، طوله ~32-64 خانة)</small></label>
          <input type="text" name="twitterapi_io_key" class="form-control" dir="ltr" autocomplete="off"
                 placeholder="<?php echo $twApiKeySet ? '•••••••• (محفوظ — اترك فارغاً للإبقاء عليه أو ألصق جديداً)' : 'مثال: abc123def456...'; ?>">
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <button type="submit" class="btn-primary">💾 حفظ المفتاح</button>
          <small style="color:var(--text-muted);font-size:11.5px;">
            للتحكّم بالتكلفة: ارفع <code dir="ltr">settings.twitter_refetch_floor_secs</code> من 75 إلى 300+ ثانية (تقليل التحديث) إذا فاتورتك صارت أعلى من المتوقّع.
          </small>
        </div>
      </form>
    </div>

    <!-- ── X session cookies card (free fallback) ────────────────── -->
    <div class="card" style="margin-bottom:20px;padding:18px;border:1px solid <?php echo $twSessionReady ? '#16a34a33' : '#f59e0b55'; ?>;background:<?php echo $twSessionReady ? '#f0fdf4' : '#fffbeb'; ?>;">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
        <h3 style="margin:0;font-size:15px;">🔑 جلسة X (بديل مجاني — يحتاج صيانة)</h3>
        <?php if ($twSessionReady): ?>
          <span class="badge badge-success">مُفعّلة ✓</span>
        <?php else: ?>
          <span class="badge" style="background:#fef3c7;color:#a16207;">غير مُفعّلة</span>
        <?php endif; ?>
      </div>
      <p style="color:var(--text-muted);font-size:12.5px;line-height:1.9;margin:0 0 14px;">
        تويتر يحجب جلب التغريدات المجهول من أي سيرفر (2026). الحل: حساب X (يُفضّل احتياطي)،
        سجّل دخوله من متصفّح، ثم انسخ قيمتي الكوكي <code dir="ltr">auth_token</code> و <code dir="ltr">ct0</code>
        وألصقهما هنا. السيرفر بيستخدم الـ API الرسمي بهالجلسة = جلب موثوق.
        <br><strong>كيف أجيب الكوكيز؟</strong>
        افتح <code dir="ltr">x.com</code> وأنت مسجّل دخول ← F12 ← Application/Storage ← Cookies ← <code dir="ltr">https://x.com</code>
        ← انسخ قيمة <code dir="ltr">auth_token</code> وقيمة <code dir="ltr">ct0</code>.
      </p>
      <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="save_session" value="1">
        <div class="form-row">
          <div class="form-group">
            <label>auth_token <small style="color:var(--text-muted);font-weight:normal;">(40 خانة hex، أو ألصق سلسلة الكوكي كاملة)</small></label>
            <input type="text" name="twitter_auth_token" class="form-control" dir="ltr" autocomplete="off"
                   placeholder="<?php echo $twAuthSet ? '•••••••• (محفوظ — اترك فارغاً للإبقاء عليه أو ألصق جديداً)' : 'مثال: 1a2b3c...'; ?>">
          </div>
          <div class="form-group">
            <label>ct0 <small style="color:var(--text-muted);font-weight:normal;">(CSRF token)</small></label>
            <input type="text" name="twitter_ct0" class="form-control" dir="ltr" autocomplete="off"
                   placeholder="<?php echo $twCt0Set ? '•••••••• (محفوظ)' : 'مثال: 9f8e7d...'; ?>">
          </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <button type="submit" class="btn-primary">💾 حفظ الجلسة</button>
          <small style="color:var(--text-muted);font-size:11.5px;">
            تُخزَّن بقاعدة البيانات (وصول الأدمن فقط). إذا توقّف الجلب لاحقاً غالباً انتهت صلاحية الكوكي — كرّر الخطوة.
          </small>
        </div>
      </form>
    </div>

    <?php if ($debugReport): ?>
      <div class="card" style="margin-bottom:20px;padding:18px;">
        <h3 style="margin:0 0 10px;font-size:15px;">🩺 تشخيص جلب <code>@<?php echo e($debugReport['username']); ?></code></h3>
        <p style="color:var(--text-muted);font-size:12.5px;margin:0 0 14px;">
          بيعرض شو السيرفر شاف فعلاً من كل نقطة جلب. اذا كل الـ HTTP codes 200 بس parsed_count = 0 فهاد يعني Twitter غيّر شكل الـ response، علّمني بالمعطيات هون.
          اذا HTTP code = 403/429/5xx فالسيرفر محجوب أو معدّل.
        </p>
        <?php foreach ($debugReport['transports'] as $t): ?>
          <div style="border:1px solid var(--border,#e0e3e8);border-radius:10px;padding:12px;margin-bottom:12px;background:var(--bg2,#fafafa);">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
              <strong><?php echo e($t['label']); ?></strong>
              <span class="badge <?php echo ($t['http_code']>=200 && $t['http_code']<300) ? 'badge-success' : 'badge-danger'; ?>">HTTP <?php echo (int)$t['http_code']; ?></span>
              <span class="badge badge-muted">حجم <?php echo (int)$t['size']; ?> بايت</span>
              <span class="badge badge-muted">وقت <?php echo e($t['total_time']); ?>ث</span>
              <span class="badge <?php echo (int)$t['parsed_count'] > 0 ? 'badge-success' : 'badge-muted'; ?>">تغريدات مستخلصة: <?php echo (int)$t['parsed_count']; ?></span>
              <?php if (!empty($t['curl_error'])): ?>
                <span class="badge badge-danger">curl: <?php echo e($t['curl_error']); ?></span>
              <?php endif; ?>
              <?php if (!empty($t['parse_error'])): ?>
                <span class="badge badge-danger"><?php echo e($t['parse_error']); ?></span>
              <?php endif; ?>
            </div>
            <details style="font-size:12px;">
              <summary style="cursor:pointer;color:var(--text-muted);">URL + أول 500 حرف من الرد</summary>
              <div style="margin-top:8px;">
                <code style="display:block;word-break:break-all;background:#fff;padding:6px 8px;border-radius:6px;border:1px solid var(--border,#e0e3e8);font-size:11px;"><?php echo e($t['url']); ?></code>
                <pre style="margin-top:8px;padding:10px;background:#fff;border:1px solid var(--border,#e0e3e8);border-radius:6px;max-height:220px;overflow:auto;font-size:11px;white-space:pre-wrap;"><?php echo e((string)($t['body_snippet'] ?? '(empty)')); ?></pre>
              </div>
            </details>
          </div>
        <?php endforeach; ?>
        <a href="twitter.php" class="btn-outline" style="margin-top:4px;">↩︎ رجوع</a>
      </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:20px;">
      <table>
        <thead>
          <tr>
            <th>الاسم</th>
            <th>المستخدم</th>
            <th>عدد التغريدات</th>
            <th>آخر جلب</th>
            <th>الحالة + آخر خطأ</th>
            <th>إجراءات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sourcesList as $s):
            // Status badge: success / RSS-fallback / consecutive failures.
            $errTxt = trim((string)($s['last_error'] ?? ''));
            $failN  = (int)($s['consecutive_failures'] ?? 0);
            $lastN  = (int)($s['last_new_count'] ?? 0);
            $isFallback = stripos($errTxt, 'RSS fallback') !== false;
            if ($errTxt === '' || $errTxt === 'ok') {
                $statusHtml = '<span class="badge badge-success">شغّال</span>';
                if ($lastN > 0) $statusHtml .= ' <small style="color:var(--text-muted);">(+' . $lastN . ' جديد)</small>';
            } elseif ($isFallback) {
                $statusHtml = '<span class="badge badge-warning" style="background:#fff7e6;color:#a16207;">RSS احتياطي ✓</span>';
                if ($lastN > 0) $statusHtml .= ' <small style="color:var(--text-muted);">(+' . $lastN . ')</small>';
            } else {
                $statusHtml = '<span class="badge" style="background:#fee2e2;color:#b91c1c;">فشل';
                if ($failN > 1) $statusHtml .= ' ×' . $failN;
                $statusHtml .= '</span><br><small style="color:#b91c1c;font-size:11px;line-height:1.5;">' . e(mb_substr($errTxt, 0, 130)) . '</small>';
            }
            $hasFallback = !empty($s['fallback_rss_url']);
          ?>
            <tr>
              <td>
                <strong><?php echo e($s['display_name']); ?></strong>
                <?php if ($hasFallback): ?>
                  <span title="RSS احتياطي مُعدّ" style="color:#0891b2;font-size:11px;">🔄</span>
                <?php endif; ?>
              </td>
              <td><a href="https://x.com/<?php echo e($s['username']); ?>" target="_blank" style="color:var(--primary);">@<?php echo e($s['username']); ?></a></td>
              <td><span class="badge badge-primary"><?php echo (int)$s['msg_count']; ?></span></td>
              <td style="color:var(--text-muted);font-size:12px;"><?php echo $s['last_fetched_at'] ? date('Y/m/d H:i', strtotime($s['last_fetched_at'])) : '—'; ?></td>
              <td style="max-width:280px;"><?php echo $statusHtml; ?></td>
              <td>
                <a href="twitter.php?action=edit&id=<?php echo (int)$s['id']; ?>" class="action-btn" title="تعديل">✏️</a>
                <a href="twitter.php?action=debug&id=<?php echo (int)$s['id']; ?>" class="action-btn" title="تشخيص الجلب">🩺</a>
                <a href="twitter.php?action=delete&id=<?php echo (int)$s['id']; ?>" class="btn-danger" onclick="return confirm('حذف الحساب؟');">🗑️</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($sourcesList)): ?>
            <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">لا توجد حسابات بعد. أضف أول حساب!</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <h3 style="margin:24px 0 12px;font-size:16px;">آخر التغريدات المسحوبة</h3>
    <div class="card">
      <table>
        <thead>
          <tr><th>الحساب</th><th>النص</th><th>التاريخ</th><th>رابط</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recentMsgs as $m): ?>
            <tr>
              <td>@<?php echo e($m['username']); ?></td>
              <td style="max-width:500px;"><?php echo e(mb_substr($m['text'] ?? '', 0, 120)); ?><?php echo mb_strlen($m['text'] ?? '') > 120 ? '...' : ''; ?></td>
              <td style="font-size:12px;color:var(--text-muted);"><?php echo $m['posted_at'] ? date('Y/m/d H:i', strtotime($m['posted_at'])) : '—'; ?></td>
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
