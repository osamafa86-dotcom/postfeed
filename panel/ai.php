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

// Save AI provider settings: both keys + the active provider dropdown.
// Stored in the same `settings` table so rotations from here take
// effect immediately after the settings_all cache bust below.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ai_settings'])) {
    $saveStmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

    $anthKey  = trim((string)($_POST['anthropic_api_key'] ?? ''));
    $geminiKey = trim((string)($_POST['gemini_api_key']   ?? ''));
    $provider = strtolower(trim((string)($_POST['ai_provider'] ?? 'gemini')));
    if (!in_array($provider, ['anthropic', 'gemini'], true)) $provider = 'gemini';

    $saveStmt->execute(['anthropic_api_key', $anthKey]);
    $saveStmt->execute(['gemini_api_key',    $geminiKey]);
    $saveStmt->execute(['ai_provider',       $provider]);

    // getSetting() caches the whole settings table under 'settings_all'
    // for an hour. Without busting that key here, the panel would keep
    // handing the OLD values to ai_provider.php until the cache expires.
    cache_forget('settings_all');
    $success = 'تم حفظ إعدادات الذكاء الاصطناعي';
}

// Toggle the per-cron AI kill switches. Persisted in `settings`,
// read by cron_ai.php / cron_evolving_ai.php on each run. Letting
// the operator flip these from the UI avoids touching crontab when
// they want to stay under a free-tier quota.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ai_switches'])) {
    foreach (['cron_ai_enabled', 'cron_evolving_ai_enabled'] as $key) {
        $val = !empty($_POST[$key]) ? '1' : '0';
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$key, $val, $val]);
    }
    cache_forget('settings_all');
    $success = 'تم حفظ مفاتيح إيقاف الذكاء الاصطناعي';
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

$anthKey    = (string)getSetting('anthropic_api_key', '');
$geminiKey  = (string)getSetting('gemini_api_key', '');
$aiProvider = strtolower((string)getSetting('ai_provider', 'gemini'));
if (!in_array($aiProvider, ['anthropic', 'gemini'], true)) $aiProvider = 'gemini';
$totalArticles = (int)$db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$summarized = (int)$db->query("SELECT COUNT(*) FROM articles WHERE ai_summary IS NOT NULL")->fetchColumn();
$pending = $totalArticles - $summarized;
$recent = $db->query("SELECT id, title, ai_summary, ai_processed_at FROM articles WHERE ai_summary IS NOT NULL ORDER BY ai_processed_at DESC LIMIT 10")->fetchAll();

$pageTitle = 'الذكاء الاصطناعي - نيوز فيد';
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
    <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">⚙️ إعدادات الذكاء الاصطناعي</h3>
    <form method="POST">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="save_ai_settings" value="1">

      <div class="form-group">
        <label>المزوّد النشط (AI Provider)</label>
        <select name="ai_provider" class="form-control">
          <option value="gemini"    <?php echo $aiProvider === 'gemini'    ? 'selected' : ''; ?>>Google Gemini 2.5 Flash — مجاني</option>
          <option value="anthropic" <?php echo $aiProvider === 'anthropic' ? 'selected' : ''; ?>>Anthropic Claude Haiku 4.5 — مدفوع</option>
        </select>
        <small style="color:var(--text-muted);font-size:11px;">
          التبديل فوري. Gemini 2.5 Flash مجاني ضمن ~15 طلب/دقيقة و ~1500 طلب/يوم — يكفي لكل ميزات نيوز فيد.
        </small>
      </div>

      <div class="form-group">
        <label>🟢 Google Gemini API Key</label>
        <input type="password" name="gemini_api_key" class="form-control" value="<?php echo e($geminiKey); ?>" placeholder="AIza...">
        <small style="color:var(--text-muted);font-size:11px;">
          احصل عليه مجاناً من <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com/app/apikey</a>
        </small>
      </div>

      <div class="form-group">
        <label>🟠 Anthropic API Key (احتياطي)</label>
        <input type="password" name="anthropic_api_key" class="form-control" value="<?php echo e($anthKey); ?>" placeholder="sk-ant-api03-...">
        <small style="color:var(--text-muted);font-size:11px;">
          يُستخدم فقط إذا كان المزوّد المحدّد أعلاه هو Anthropic. احصل عليه من <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>
        </small>
      </div>

      <button type="submit" class="btn-primary">💾 حفظ الإعدادات</button>
    </form>
  </div>

  <?php
    $aiEnabled        = (string)getSetting('cron_ai_enabled',          '1') === '1';
    $evolvingEnabled  = (string)getSetting('cron_evolving_ai_enabled', '1') === '1';
  ?>
  <div class="card" style="margin-top:18px;">
    <h2 style="margin:0 0 8px;">⏸ مفاتيح إيقاف توليد المحتوى</h2>
    <p style="font-size:13px;color:#64748b;margin:0 0 14px;">
      أوقف العمليات الكثيفة على الذكاء الاصطناعي لتقليل التكاليف.
      الموجزات اليومية والأسبوعية تستمر بشكل منفصل.
    </p>
    <form method="POST">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="save_ai_switches" value="1">

      <label style="display:flex;align-items:center;gap:10px;padding:14px;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:10px;cursor:pointer;background:<?php echo $aiEnabled ? '#f0fdf4' : '#fef2f2'; ?>;">
        <input type="checkbox" name="cron_ai_enabled" value="1"<?php echo $aiEnabled ? ' checked' : ''; ?> style="width:18px;height:18px;">
        <div style="flex:1;">
          <div style="font-weight:700;font-size:14px;">تلخيص المقالات الفردية (cron_ai.php)</div>
          <div style="font-size:12px;color:#64748b;">يلخّص كل خبر جديد على حدة. <strong>الأكثر استهلاكاً للحصة</strong> — أوقفه لتوفير ٨٠٪ من الاستدعاءات.</div>
        </div>
        <span style="font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;background:<?php echo $aiEnabled ? '#16a34a' : '#dc2626'; ?>;color:#fff;">
          <?php echo $aiEnabled ? '● مُفعّل' : '○ موقف'; ?>
        </span>
      </label>

      <label style="display:flex;align-items:center;gap:10px;padding:14px;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:14px;cursor:pointer;background:<?php echo $evolvingEnabled ? '#f0fdf4' : '#fef2f2'; ?>;">
        <input type="checkbox" name="cron_evolving_ai_enabled" value="1"<?php echo $evolvingEnabled ? ' checked' : ''; ?> style="width:18px;height:18px;">
        <div style="flex:1;">
          <div style="font-weight:700;font-size:14px;">استخراج كيانات القصص المتطوّرة (cron_evolving_ai.php)</div>
          <div style="font-size:12px;color:#64748b;">يستخرج الأشخاص والاقتباسات من القصص المتطوّرة. ٨ مكالمات/قصة/ليلة. أوقفه إذا الميزة غير مستخدمة.</div>
        </div>
        <span style="font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;background:<?php echo $evolvingEnabled ? '#16a34a' : '#dc2626'; ?>;color:#fff;">
          <?php echo $evolvingEnabled ? '● مُفعّل' : '○ موقف'; ?>
        </span>
      </label>

      <button type="submit" class="btn-primary" style="padding:10px 24px;background:#1a5c5c;color:#fff;border:0;border-radius:8px;font-weight:700;cursor:pointer;">حفظ</button>
    </form>

    <div style="margin-top:18px;padding:14px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;">
      <h3 style="margin:0 0 8px;font-size:14px;color:#92400e;">📅 الجدول الموصى به للـ cron (٣ مكالمات/يوم + ٢ أسبوعياً)</h3>
      <pre style="margin:0;font-size:11.5px;font-family:Menlo,Consolas,monospace;color:#451a03;white-space:pre-wrap;line-height:1.7;">
# موجز التلغرام الشامل (مرة واحدة، ١١ مساءً)
0 23 * * * curl -fsS "<?php echo e($siteUrl); ?>/cron_tg_summary.php?key=<?php echo e($cronKey ?: 'KEY'); ?>&mode=daily" > /dev/null

# موجز أخبار الموقع (مرة واحدة، ١١ مساءً)
5 23 * * * curl -fsS "<?php echo e($siteUrl); ?>/cron_sabah.php?key=<?php echo e($cronKey ?: 'KEY'); ?>" > /dev/null

# مراجعة الأسبوع (سبت ٨ مساءً + إرسال أحد ٧ صباحاً)
0 20 * * 6 curl -fsS "<?php echo e($siteUrl); ?>/cron_weekly_rewind.php?key=<?php echo e($cronKey ?: 'KEY'); ?>" > /dev/null
0 7  * * 0 curl -fsS "<?php echo e($siteUrl); ?>/cron_weekly_rewind_send.php?key=<?php echo e($cronKey ?: 'KEY'); ?>" > /dev/null
</pre>
      <p style="margin:8px 0 0;font-size:12px;color:#92400e;">
        ⚠ بعد ضبط الجدول الجديد في cPanel، احذف أي cron قديم لـ <code>cron_ai.php</code> و <code>cron_evolving_ai.php</code> و الـ <code>cron_tg_summary.php</code> كل ٤ ساعات.
      </p>
    </div>
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
