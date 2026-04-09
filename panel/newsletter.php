<?php
/**
 * Newsletter admin — list subscribers, view stats, trigger a manual
 * digest send, and copy the cron URL.
 *
 * Admin role required so we don't leak the subscriber list to editors.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db = getDB();

// Auto-create tables on first visit so a fresh deploy doesn't 500.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        confirmed TINYINT(1) NOT NULL DEFAULT 0,
        confirm_token VARCHAR(64) NOT NULL,
        unsubscribe_token VARCHAR(64) NOT NULL,
        subscribed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        confirmed_at TIMESTAMP NULL,
        last_sent_at TIMESTAMP NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        INDEX idx_confirmed (confirmed)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS newsletter_sends (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        subject VARCHAR(255) NOT NULL,
        article_count INT NOT NULL DEFAULT 0,
        recipient_count INT NOT NULL DEFAULT 0,
        success_count INT NOT NULL DEFAULT 0,
        fail_count INT NOT NULL DEFAULT 0,
        INDEX idx_sent (sent_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$success = '';
$error   = '';

// Save sender identity (from-name / from-email).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_from'])) {
    $fromEmail = trim((string)($_POST['mail_from_email'] ?? ''));
    $fromName  = trim((string)($_POST['mail_from_name'] ?? ''));
    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'البريد المرسِل غير صحيح';
    } else {
        $up = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $up->execute(['mail_from_email', $fromEmail]);
        $up->execute(['mail_from_name',  $fromName]);
        cache_forget('settings_all');
        $success = 'تم حفظ إعدادات المرسِل';
    }
}

// Delete a subscriber.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $stmt = $db->prepare("DELETE FROM newsletter_subscribers WHERE id = ?");
    $stmt->execute([(int)$_POST['delete_id']]);
    $success = 'تم حذف المشترك';
}

// Stats
$totalSubs     = (int)$db->query("SELECT COUNT(*) FROM newsletter_subscribers")->fetchColumn();
$confirmedSubs = (int)$db->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE confirmed = 1")->fetchColumn();
$pendingSubs   = $totalSubs - $confirmedSubs;
$last24h       = (int)$db->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE subscribed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

$subscribers = $db->query("SELECT * FROM newsletter_subscribers ORDER BY subscribed_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$recentSends = $db->query("SELECT * FROM newsletter_sends ORDER BY sent_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$cronKey  = trim((string)getSetting('cron_key', ''));
$cronUrl  = $cronKey ? (SITE_URL . '/cron_newsletter.php?key=' . $cronKey) : '';
$forceUrl = $cronKey ? ($cronUrl . '&force=1') : '';
$cronLine = $cronUrl ? ('0 7 * * * curl -fsS "' . $cronUrl . '" > /dev/null 2>&1') : '';

$mailFromEmail = (string)getSetting('mail_from_email', '');
$mailFromName  = (string)getSetting('mail_from_name', '');

$pageTitle = 'النشرة البريدية - نيوزفلو';
$activePage = 'newsletter';
include __DIR__ . '/includes/panel_layout_head.php';
?>
<div class="content">
  <div class="page-header">
    <div>
      <h2>📬 النشرة البريدية اليومية</h2>
      <p>إدارة المشتركين وإرسال ملخّص الأخبار اليومي</p>
    </div>
    <?php if ($forceUrl): ?>
    <div class="page-actions">
      <a href="<?php echo e($forceUrl); ?>" target="_blank" class="btn-primary" onclick="return confirm('سيتم إرسال النشرة فورًا لجميع المشتركين المؤكّدين. متابعة؟');">⚡ إرسال نشرة الآن</a>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

  <!-- STATS -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:22px;">
    <div class="form-card" style="text-align:center;">
      <div style="font-size:32px;font-weight:800;color:var(--primary);"><?php echo number_format($confirmedSubs); ?></div>
      <div style="font-size:13px;color:var(--text-muted);font-weight:600;">مشترك مؤكّد</div>
    </div>
    <div class="form-card" style="text-align:center;">
      <div style="font-size:32px;font-weight:800;color:var(--warning);"><?php echo number_format($pendingSubs); ?></div>
      <div style="font-size:13px;color:var(--text-muted);font-weight:600;">في انتظار التأكيد</div>
    </div>
    <div class="form-card" style="text-align:center;">
      <div style="font-size:32px;font-weight:800;color:var(--success);"><?php echo number_format($last24h); ?></div>
      <div style="font-size:13px;color:var(--text-muted);font-weight:600;">اشتراك آخر 24 ساعة</div>
    </div>
    <div class="form-card" style="text-align:center;">
      <div style="font-size:32px;font-weight:800;color:var(--purple);"><?php echo number_format($totalSubs); ?></div>
      <div style="font-size:13px;color:var(--text-muted);font-weight:600;">المجموع</div>
    </div>
  </div>

  <!-- SENDER SETTINGS -->
  <div class="form-card" style="margin-bottom:22px;">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">✉️ إعدادات المرسِل</h3>
    <form method="POST">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="save_from" value="1">
      <div class="form-group">
        <label>اسم المرسِل (يظهر في صندوق الوارد)</label>
        <input type="text" name="mail_from_name" class="form-control" value="<?php echo e($mailFromName); ?>" placeholder="نيوزفلو">
      </div>
      <div class="form-group">
        <label>البريد الإلكتروني للمرسِل</label>
        <input type="email" name="mail_from_email" class="form-control" value="<?php echo e($mailFromEmail); ?>" placeholder="newsletter@your-domain.org">
        <small style="color:var(--text-muted);font-size:11px;">يجب أن يكون عنوانًا حقيقيًا على نطاقك حتى لا تُعتبر الرسائل مزعجة.</small>
      </div>
      <button type="submit" class="btn-primary">💾 حفظ</button>
    </form>
  </div>

  <!-- CRON -->
  <div class="form-card" style="margin-bottom:22px;">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">⏰ الجدولة التلقائية (يومياً 7 صباحاً)</h3>
    <?php if (!$cronKey): ?>
      <p style="color:var(--danger);font-size:13px;">لم يتم توليد <code>cron_key</code> بعد. اذهب إلى صفحة الذكاء الاصطناعي وولّد المفتاح أولاً.</p>
    <?php else: ?>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:8px;">أضف هذا السطر في cPanel → Cron Jobs:</p>
      <pre style="background:#0f172a;color:#86efac;padding:14px;border-radius:8px;font-size:12px;direction:ltr;text-align:left;overflow-x:auto;"><?php echo e($cronLine); ?></pre>
      <p style="font-size:12px;color:var(--text-muted);margin-top:10px;">
        أو شغّل يدوياً:
        <a href="<?php echo e($cronUrl); ?>" target="_blank" style="color:var(--primary);">رابط التشغيل العادي</a> ·
        <a href="<?php echo e($forceUrl); ?>" target="_blank" style="color:var(--danger);">إجبار الإرسال (تجاوز التحقق)</a>
      </p>
    <?php endif; ?>
  </div>

  <!-- RECENT SENDS -->
  <?php if ($recentSends): ?>
  <div class="form-card" style="margin-bottom:22px;">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">📤 آخر الإرسالات</h3>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="border-bottom:2px solid var(--bg-page);text-align:right;">
          <th style="padding:10px 8px;">التاريخ</th>
          <th style="padding:10px 8px;">العنوان</th>
          <th style="padding:10px 8px;">الأخبار</th>
          <th style="padding:10px 8px;">المستلمون</th>
          <th style="padding:10px 8px;">نجح</th>
          <th style="padding:10px 8px;">فشل</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentSends as $s): ?>
        <tr style="border-bottom:1px solid var(--bg-page);">
          <td style="padding:10px 8px;color:var(--text-muted);"><?php echo e($s['sent_at']); ?></td>
          <td style="padding:10px 8px;"><?php echo e($s['subject']); ?></td>
          <td style="padding:10px 8px;text-align:center;"><?php echo (int)$s['article_count']; ?></td>
          <td style="padding:10px 8px;text-align:center;"><?php echo (int)$s['recipient_count']; ?></td>
          <td style="padding:10px 8px;text-align:center;color:var(--success);font-weight:700;"><?php echo (int)$s['success_count']; ?></td>
          <td style="padding:10px 8px;text-align:center;color:var(--danger);font-weight:700;"><?php echo (int)$s['fail_count']; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- SUBSCRIBERS LIST -->
  <div class="form-card">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">👥 المشتركون <span style="color:var(--text-muted);font-weight:500;font-size:13px;">(آخر 200)</span></h3>
    <?php if (!$subscribers): ?>
      <p style="color:var(--text-muted);text-align:center;padding:30px 0;">لا يوجد مشتركون بعد. شارك رابط الموقع لجذب القراء!</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="border-bottom:2px solid var(--bg-page);text-align:right;">
            <th style="padding:10px 8px;">البريد</th>
            <th style="padding:10px 8px;">الحالة</th>
            <th style="padding:10px 8px;">اشترك في</th>
            <th style="padding:10px 8px;">آخر إرسال</th>
            <th style="padding:10px 8px;"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($subscribers as $sub): ?>
          <tr style="border-bottom:1px solid var(--bg-page);">
            <td style="padding:10px 8px;direction:ltr;text-align:left;font-family:monospace;font-size:12px;"><?php echo e($sub['email']); ?></td>
            <td style="padding:10px 8px;">
              <?php if ((int)$sub['confirmed'] === 1): ?>
                <span style="background:var(--success-light);color:var(--success);padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">✓ مؤكّد</span>
              <?php else: ?>
                <span style="background:var(--warning-light);color:var(--warning);padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">⏳ بانتظار</span>
              <?php endif; ?>
            </td>
            <td style="padding:10px 8px;color:var(--text-muted);font-size:12px;"><?php echo e($sub['subscribed_at']); ?></td>
            <td style="padding:10px 8px;color:var(--text-muted);font-size:12px;"><?php echo $sub['last_sent_at'] ? e($sub['last_sent_at']) : '—'; ?></td>
            <td style="padding:10px 8px;text-align:left;">
              <form method="POST" style="display:inline;" onsubmit="return confirm('حذف هذا المشترك نهائياً؟');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="delete_id" value="<?php echo (int)$sub['id']; ?>">
                <button type="submit" style="background:transparent;border:0;color:var(--danger);cursor:pointer;font-size:16px;" title="حذف">🗑️</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
