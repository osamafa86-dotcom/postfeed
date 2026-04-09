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
