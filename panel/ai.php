<?php
/**
 * AI settings + bulk summarization
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai_helper.php';
requireAdmin();

$db = getDB();

// Auto-migrate
try {
    $cols = $db->query("SHOW COLUMNS FROM articles LIKE 'ai_summary'")->fetch();
    if (!$cols) {
        $db->exec("ALTER TABLE articles
            ADD COLUMN ai_summary TEXT,
            ADD COLUMN ai_key_points TEXT,
            ADD COLUMN ai_keywords VARCHAR(500),
            ADD COLUMN ai_processed_at TIMESTAMP NULL");
    }
} catch (Exception $e) {}

$success = '';
$error = '';

// Save API key
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_key'])) {
    $key = trim($_POST['api_key']);
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('anthropic_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $key]);
    // getSetting() caches the whole settings table under 'settings_all'
    // for an hour. Without busting that key here, the panel would keep
    // handing the OLD invalid key to ai_helper.php until the cache expires.
    cache_forget('settings_all');
    $success = 'تم حفظ المفتاح';
}

// Generate / rotate the cron key used by cron_tg_summary.php and friends
// so the admin doesn't have to dig into the DB to find (or create) it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_cron_key'])) {
    $newKey = bin2hex(random_bytes(16));
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('cron_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$newKey, $newKey]);
    cache_forget('settings_all');
    $success = 'تم توليد مفتاح cron جديد';
}

// Manual "generate now" trigger. Useful when the cron is broken or the
// admin wants to verify the Claude key works. Bypasses the 30-min dedup
// guard in cron_tg_summary.php so the admin can see immediate results.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tg_generate_now'])) {
    try {
        $msgs = tg_summary_collect_messages(60, 250);
        if (count($msgs) < 3) {
            $error = 'لا توجد رسائل كافية في آخر 60 دقيقة (' . count($msgs) . ' فقط، يلزم 3 على الأقل).';
        } else {
            $t0  = microtime(true);
            $res = ai_summarize_telegram($msgs);
            $dt  = round(microtime(true) - $t0, 2);
            if (empty($res['ok'])) {
                $error = 'فشل التوليد: ' . ($res['error'] ?? 'سبب غير معروف') . ' (' . $dt . 'ث)';
            } else {
                $id = tg_summary_save($res, count($msgs), 60);
                if ($id) {
                    if (function_exists('tg_summary_prune')) tg_summary_prune(72);
                    $success = 'تم توليد ملخص جديد #' . $id . ' من ' . count($msgs) . ' رسالة خلال ' . $dt . 'ث.';
                } else {
                    $error = 'تم التوليد لكن فشل الحفظ في قاعدة البيانات.';
                }
            }
        }
    } catch (Throwable $e) {
        $error = 'خطأ: ' . $e->getMessage();
    }
}

// Bulk summarize
if (($_GET['action'] ?? '') === 'bulk') {
    @set_time_limit(120);
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 5)));
    $stmt = $db->prepare("SELECT id, title, content FROM articles WHERE ai_summary IS NULL ORDER BY created_at DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $articles = $stmt->fetchAll();
    $done = 0; $fail = 0; $errors = [];
    foreach ($articles as $a) {
        $r = ai_summarize_article($a['title'], $a['content']);
        if ($r['ok']) { ai_save_summary($a['id'], $r); $done++; }
        else { $fail++; $errors[] = $r['error']; }
    }
    $success = "تم تلخيص $done خبر، فشل $fail" . ($errors ? '<br><small>' . e(implode(' | ', array_slice($errors, 0, 3))) . '</small>' : '');
}

$apiKey = getSetting('anthropic_api_key', '');
$totalArticles = (int)$db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$summarized = (int)$db->query("SELECT COUNT(*) FROM articles WHERE ai_summary IS NOT NULL")->fetchColumn();
$pending = $totalArticles - $summarized;
$recent = $db->query("SELECT id, title, ai_summary, ai_processed_at FROM articles WHERE ai_summary IS NOT NULL ORDER BY ai_processed_at DESC LIMIT 10")->fetchAll();

$pageTitle = 'الذكاء الاصطناعي - نيوزفلو';
$activePage = 'ai';
include __DIR__ . '/includes/panel_layout_head.php';
?>
<div class="content">
  <div class="page-header">
    <div>
      <h2>🤖 الذكاء الاصطناعي</h2>
      <p>تلخيص الأخبار تلقائياً عبر Claude AI</p>
    </div>
    <div class="page-actions">
      <a href="ai.php?action=bulk&limit=5" class="btn-primary">⚡ لخّص 5 أخبار</a>
      <a href="ai.php?action=bulk&limit=20" class="btn-outline">⚡ لخّص 20 خبر</a>
    </div>
  </div>

  <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

  <div class="form-card">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">⚙️ إعدادات API</h3>
    <form method="POST">
                <?php echo csrf_field(); ?>
      <div class="form-group">
        <label>Anthropic API Key</label>
        <input type="password" name="api_key" class="form-control" value="<?php echo e($apiKey); ?>" placeholder="sk-ant-api03-...">
        <small style="color:var(--text-muted);font-size:11px;">احصل عليه من <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a></small>
      </div>
      <button type="submit" class="btn-primary">💾 حفظ</button>
    </form>
  </div>

  <?php
    // Cron key card — exposes the shared cron_key and a ready-made
    // URL so the admin can paste it straight into cPanel without
    // hunting through phpMyAdmin.
    $cronKey  = trim((string)getSetting('cron_key', ''));
    $siteUrl  = rtrim((string)getSetting('site_url', ''), '/');
    if ($siteUrl === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $siteUrl = $scheme . '://' . $host;
    }
    $cronUrl  = $cronKey ? ($siteUrl . '/cron_tg_summary.php?key=' . $cronKey) : '';
    $cronLine = $cronUrl ? ('0 * * * * curl -fsS "' . $cronUrl . '" > /dev/null 2>&1') : '';
  ?>
  <div class="form-card" style="margin-top:18px;">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">⏰ جدولة ملخصات تيليغرام (كل ساعة)</h3>

    <?php if (!$cronKey): ?>
      <div class="alert alert-warning" style="margin-bottom:14px;">
        ⚠️ لم يُعدّ بعد <code>cron_key</code>. اضغط الزر أدناه لتوليد مفتاح جديد.
      </div>
      <form method="POST" style="margin-bottom:10px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="generate_cron_key" value="1">
        <button type="submit" class="btn-primary">🔑 توليد مفتاح cron جديد</button>
      </form>
    <?php else: ?>
      <div class="form-group">
        <label>مفتاح cron الحالي</label>
        <div style="display:flex;gap:8px;">
          <input type="text" id="cronKeyField" class="form-control" value="<?php echo e($cronKey); ?>" readonly>
          <button type="button" class="btn-outline" onclick="navigator.clipboard.writeText(document.getElementById('cronKeyField').value);this.textContent='✓ نُسخ';setTimeout(()=>this.textContent='نسخ',1500);">نسخ</button>
        </div>
      </div>

      <div class="form-group">
        <label>رابط التشغيل المباشر (اختبار يدوي في المتصفح)</label>
        <div style="display:flex;gap:8px;">
          <input type="text" id="cronUrlField" class="form-control" value="<?php echo e($cronUrl); ?>" readonly>
          <button type="button" class="btn-outline" onclick="navigator.clipboard.writeText(document.getElementById('cronUrlField').value);this.textContent='✓ نُسخ';setTimeout(()=>this.textContent='نسخ',1500);">نسخ</button>
          <a href="<?php echo e($cronUrl); ?>" target="_blank" class="btn-outline">فتح</a>
        </div>
        <small style="color:var(--text-muted);font-size:11px;">يجب أن ترى <code>ok: saved briefing #N</code> عند النجاح.</small>
      </div>

      <div class="form-group">
        <label>السطر الكامل لجدولة cPanel / crontab</label>
        <div style="display:flex;gap:8px;">
          <input type="text" id="cronLineField" class="form-control" value="<?php echo e($cronLine); ?>" readonly style="font-family:monospace;font-size:12px;">
          <button type="button" class="btn-outline" onclick="navigator.clipboard.writeText(document.getElementById('cronLineField').value);this.textContent='✓ نُسخ';setTimeout(()=>this.textContent='نسخ',1500);">نسخ</button>
        </div>
        <small style="color:var(--text-muted);font-size:11px;">
          الصق هذا السطر في <strong>cPanel → Cron Jobs → Add New Cron Job → Command</strong> (واختر "Once Per Hour").
        </small>
      </div>

      <form method="POST" onsubmit="return confirm('توليد مفتاح جديد سيُبطل السطر المُعدّ في cPanel حالياً. متأكد؟');">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="generate_cron_key" value="1">
        <button type="submit" class="btn-outline" style="font-size:12px;">🔄 توليد مفتاح جديد (يُبطل القديم)</button>
      </form>
    <?php endif; ?>

    <hr style="margin:18px 0;border:0;border-top:1px solid var(--border);">

    <div class="form-group" style="margin-bottom:10px;">
      <label style="font-weight:700;">🚀 توليد ملخص يدوياً الآن</label>
      <small style="color:var(--text-muted);font-size:11px;display:block;margin-bottom:8px;">
        يتجاوز حد الـ 30 دقيقة ويولّد ملخصاً فورياً من رسائل الساعة الماضية. استخدمه للاختبار أو عندما لا يعمل cron تلقائياً.
      </small>
      <form method="POST" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='⏳ يتم التوليد…';">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="tg_generate_now" value="1">
        <button type="submit" class="btn-primary">🚀 توليد ملخص الآن</button>
      </form>
    </div>

    <?php
      // Latest briefing preview (so the admin can see whether it's stale
      // or fresh without leaving the panel).
      try {
          $tgLatest = tg_summary_get_latest();
      } catch (Throwable $e) { $tgLatest = null; }
    ?>
    <?php if ($tgLatest): ?>
      <div class="form-group" style="margin:14px 0 0;padding:12px 14px;background:rgba(13,148,136,.06);border:1px solid rgba(13,148,136,.2);border-radius:8px;">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">آخر ملخص مُخزّن</div>
        <div style="font-weight:700;font-size:13px;">
          <?php
            $age = time() - strtotime((string)$tgLatest['generated_at']);
            $ageMin = (int)round($age / 60);
            $ageLabel = $ageMin < 60 ? "منذ {$ageMin} دقيقة" : 'منذ ' . (int)round($ageMin / 60) . ' ساعة';
            $stale = $ageMin > 65;
          ?>
          #<?php echo (int)$tgLatest['id']; ?> — <?php echo e($ageLabel); ?>
          <?php if ($stale): ?>
            <span style="color:#dc2626;font-weight:700;">⚠️ قديم (أكثر من ساعة)</span>
          <?php else: ?>
            <span style="color:#16a34a;font-weight:700;">✓ حديث</span>
          <?php endif; ?>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
          <?php echo e(mb_substr((string)$tgLatest['headline'], 0, 80)); ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:24px 0;">
    <div class="card" style="padding:20px;text-align:center;">
      <div style="font-size:32px;font-weight:900;color:var(--primary);"><?php echo $totalArticles; ?></div>
      <div style="color:var(--text-muted);font-size:13px;">إجمالي الأخبار</div>
    </div>
    <div class="card" style="padding:20px;text-align:center;">
      <div style="font-size:32px;font-weight:900;color:#16a34a;"><?php echo $summarized; ?></div>
      <div style="color:var(--text-muted);font-size:13px;">ملخّصة</div>
    </div>
    <div class="card" style="padding:20px;text-align:center;">
      <div style="font-size:32px;font-weight:900;color:#ea580c;"><?php echo $pending; ?></div>
      <div style="color:var(--text-muted);font-size:13px;">في الانتظار</div>
    </div>
  </div>

  <h3 style="margin:24px 0 12px;font-size:16px;">آخر التلخيصات</h3>
  <div class="card">
    <table>
      <thead><tr><th>العنوان</th><th>الملخص</th><th>التاريخ</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td><a href="../article.php?id=<?php echo (int)$r['id']; ?>" target="_blank" style="color:var(--primary);"><?php echo e(mb_substr($r['title'], 0, 70)); ?></a></td>
            <td style="max-width:500px;font-size:12px;"><?php echo e(mb_substr($r['ai_summary'], 0, 200)); ?>...</td>
            <td style="font-size:11px;color:var(--text-muted);"><?php echo date('Y/m/d H:i', strtotime($r['ai_processed_at'])); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($recent)): ?>
          <tr><td colspan="3" style="text-align:center;padding:30px;color:var(--text-muted);">لا توجد تلخيصات بعد</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
