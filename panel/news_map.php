<?php
/**
 * News Map admin.
 *
 * Lets the operator:
 *   - Trigger a backfill run (gazetteer / AI / custom window)
 *   - See aggregate stats (total, last 24h, by country, by method)
 *   - Drill into recent locations and delete bad matches
 *   - Preview the public /map in an iframe
 *
 * Admin role only because regenerate + delete have side effects.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/news_map.php';
require_once __DIR__ . '/../includes/news_map_extract.php';
requireRole('admin');

ini_set('display_errors', '1');
error_reporting(E_ALL);

nm_ensure_table();
$db = getDB();
$activePage = 'news_map';

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? '')) {
        http_response_code(403);
        exit('CSRF token mismatch');
    }
    @set_time_limit(240);
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'run_backfill') {
            $days   = max(1, min(180, (int)($_POST['days']  ?? 14)));
            $limit  = max(1, min(500, (int)($_POST['limit'] ?? 200)));
            $useAi  = !empty($_POST['use_ai']);

            $stmt = $db->prepare(
                "SELECT a.id, a.title, a.excerpt
                   FROM articles a
              LEFT JOIN article_locations l ON l.article_id = a.id
                  WHERE a.status = 'published'
                    AND a.published_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                    AND l.article_id IS NULL
               ORDER BY a.published_at DESC
                  LIMIT :lim"
            );
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->bindValue(':lim',  $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $hits = 0; $byGaz = 0; $byAi = 0; $missed = 0;
            foreach ($rows as $r) {
                $text = trim($r['title'] . ' ' . strip_tags((string)$r['excerpt']));
                $loc = nm_extract_location($text, $useAi);
                if ($loc) {
                    nm_save_location((int)$r['id'], $loc);
                    $hits++;
                    if (($loc['by'] ?? '') === 'ai') $byAi++; else $byGaz++;
                } else {
                    $missed++;
                }
            }

            $flash = [
                'type' => 'ok',
                'msg'  => "✓ مسح {$hits} موقع (gazetteer: {$byGaz}, AI: {$byAi}) من أصل " . count($rows) . " مقال.",
            ];
        } elseif ($action === 'delete_one') {
            $articleId = (int)($_POST['article_id'] ?? 0);
            if ($articleId > 0) {
                nm_delete_location($articleId);
                $flash = ['type' => 'ok', 'msg' => "تم حذف موقع المقال #{$articleId}"];
            }
        } elseif ($action === 'purge_ai') {
            // Purge AI-generated locations so they get re-extracted by
            // the gazetteer on the next cron (useful if the gazetteer
            // was just expanded and we want the cheaper path back).
            $n = $db->exec("DELETE FROM article_locations WHERE extracted_by='ai'");
            $flash = ['type' => 'ok', 'msg' => "حُذف {$n} موقع مُولَّد بالـ AI — سيُعاد مسحها بالـ gazetteer."];
        }
    } catch (Throwable $e) {
        $flash = [
            'type' => 'err',
            'msg'  => '✗ استثناء: ' . $e->getMessage(),
        ];
    }

    $_SESSION['nm_flash_type'] = $flash['type'] ?? '';
    $_SESSION['nm_flash_msg']  = $flash['msg']  ?? '';
    header('Location: news_map.php');
    exit;
}

$flashType = $_SESSION['nm_flash_type'] ?? '';
$flashMsg  = $_SESSION['nm_flash_msg']  ?? '';
unset($_SESSION['nm_flash_type'], $_SESSION['nm_flash_msg']);

$stats = nm_stats();
$recent = $db->query(
    "SELECT l.*, a.title, a.slug, a.published_at
       FROM article_locations l
       JOIN articles a ON a.id = l.article_id
      ORDER BY a.published_at DESC
      LIMIT 40"
)->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/includes/panel_layout_head.php';
?>
<style>
  .nm-wrap { padding: 20px 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
  .nm-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px; }
  .nm-card h2 { margin:0 0 14px; font-size:17px; }
  .nm-stat-grid { display:grid; grid-template-columns: repeat(2, 1fr); gap:10px; margin-bottom:14px; }
  .nm-stat { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:12px; text-align:center; }
  .nm-stat-v { font-size:24px; font-weight:900; color:#0d9488; }
  .nm-stat-l { font-size:11px; color:#6b7280; }
  .nm-chip-row { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
  .nm-chip { font-size:12px; padding:4px 10px; border-radius:999px; background:#eef2ff; color:#3730a3; font-weight:700; }
  .nm-chip-count { background:#fef3c7; color:#92400e; }
  .nm-flash { padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:13.5px; grid-column: 1/-1; }
  .nm-flash.ok  { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; }
  .nm-flash.err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
  .nm-form { display:flex; flex-direction:column; gap:10px; }
  .nm-form-row { display:flex; gap:10px; align-items:center; }
  .nm-form label { font-size:13px; font-weight:700; color:#374151; }
  .nm-form input[type=number] { padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-family:inherit; font-size:13px; width:80px; }
  .nm-form input[type=checkbox] { accent-color:#0d9488; width:16px; height:16px; }
  .nm-btn { padding:9px 18px; border:0; border-radius:8px; font-weight:700; cursor:pointer; font-size:13px; font-family:inherit; }
  .nm-btn-primary { background:#0d9488; color:#fff; }
  .nm-btn-warn    { background:#f59e0b; color:#fff; }
  .nm-btn-danger  { background:#ef4444; color:#fff; }
  .nm-recent { max-height: 520px; overflow-y: auto; }
  .nm-recent-row { display:flex; gap:10px; padding:8px 0; border-bottom:1px solid #f3f4f6; font-size:12.5px; }
  .nm-recent-row:last-child { border-bottom:0; }
  .nm-recent-place { flex:0 0 120px; font-weight:700; color:#0d9488; }
  .nm-recent-title { flex:1; min-width:0; line-height:1.5; }
  .nm-recent-title a { color:#1a1a2e; text-decoration:none; }
  .nm-recent-meta { font-size:11px; color:#6b7280; margin-top:2px; }
  .nm-recent-actions { flex:0 0 auto; }
  .nm-recent-actions button { background:transparent; border:0; color:#ef4444; cursor:pointer; font-size:16px; padding:4px 8px; border-radius:4px; }
  .nm-recent-actions button:hover { background:#fee2e2; }
  .nm-preview { grid-column: 1/-1; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px; }
  .nm-iframe { width:100%; height:600px; border:1px solid #e5e7eb; border-radius:8px; }
  @media (max-width: 900px) { .nm-wrap { grid-template-columns: 1fr; } }
</style>

<div class="nm-wrap">
  <?php if ($flashMsg): ?>
    <div class="nm-flash <?php echo $flashType === 'err' ? 'err' : 'ok'; ?>"><?php echo e($flashMsg); ?></div>
  <?php endif; ?>

  <!-- STATS -->
  <section class="nm-card">
    <h2>📊 إحصائيات الخريطة</h2>
    <div class="nm-stat-grid">
      <div class="nm-stat">
        <div class="nm-stat-v"><?php echo number_format($stats['total']); ?></div>
        <div class="nm-stat-l">إجمالي المواقع</div>
      </div>
      <div class="nm-stat">
        <div class="nm-stat-v"><?php echo number_format($stats['last_24h']); ?></div>
        <div class="nm-stat-l">آخر 24 ساعة</div>
      </div>
    </div>

    <?php if ($stats['by_country']): ?>
      <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">حسب الدولة:</div>
      <div class="nm-chip-row">
        <?php foreach ($stats['by_country'] as $c): ?>
          <span class="nm-chip"><?php echo e($c['country_code']); ?> <span class="nm-chip-count"><?php echo (int)$c['n']; ?></span></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($stats['by_method']): ?>
      <div style="font-size:12px;color:#6b7280;margin:14px 0 4px;">طريقة الاستخراج:</div>
      <div class="nm-chip-row">
        <?php foreach ($stats['by_method'] as $m): ?>
          <span class="nm-chip"><?php echo e($m['extracted_by']); ?>: <?php echo (int)$m['n']; ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <a href="<?php echo e(SITE_URL); ?>/map" target="_blank" style="display:inline-block;margin-top:14px;color:#0d9488;font-weight:700;text-decoration:none;">🌐 فتح الخريطة على الموقع ←</a>
  </section>

  <!-- BACKFILL ACTIONS -->
  <section class="nm-card">
    <h2>🔄 تشغيل مسح المواقع</h2>
    <p style="font-size:13px;color:#6b7280;margin:0 0 12px;">يمسح المقالات اللي ما لها موقع بعد. الوضع الافتراضي: gazetteer فقط (مجاني).</p>

    <form method="POST" class="nm-form">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="run_backfill">

      <div class="nm-form-row">
        <label>عدد الأيام:</label>
        <input type="number" name="days" value="14" min="1" max="180">
        <label>الحد الأقصى:</label>
        <input type="number" name="limit" value="200" min="1" max="500">
      </div>

      <label style="display:flex;align-items:center;gap:8px;padding:10px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;">
        <input type="checkbox" name="use_ai">
        <span style="font-size:13px;">استخدم الـ AI للمقالات اللي ما لقاها الـ gazetteer (مكلف)</span>
      </label>

      <button type="submit" class="nm-btn nm-btn-primary">تشغيل المسح</button>
    </form>

    <form method="POST" style="margin-top:14px;padding-top:14px;border-top:1px solid #f3f4f6;">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="purge_ai">
      <button type="submit" class="nm-btn nm-btn-warn"
              onclick="return confirm('حذف كل المواقع المُولَّدة بالـ AI؟ سيُعاد توليدها بالـ gazetteer على الـ cron القادم.');">
        🧹 حذف مواقع الـ AI (يُعاد مسحها)
      </button>
    </form>
  </section>

  <!-- RECENT -->
  <section class="nm-card" style="grid-column: 1/-1;">
    <h2>📍 آخر 40 موقعاً</h2>
    <div class="nm-recent">
      <?php if (!$recent): ?>
        <p style="color:#6b7280;">لا توجد مواقع بعد. شغّل المسح أعلاه.</p>
      <?php else: ?>
        <?php foreach ($recent as $r): ?>
          <div class="nm-recent-row">
            <div class="nm-recent-place"><?php echo e($r['place_name_ar'] ?: '—'); ?></div>
            <div class="nm-recent-title">
              <a href="<?php echo e(SITE_URL . '/' . articleUrl(['id' => (int)$r['article_id'], 'slug' => $r['slug']])); ?>" target="_blank"><?php echo e(mb_substr($r['title'], 0, 100)); ?></a>
              <div class="nm-recent-meta">
                <?php echo e($r['extracted_by']); ?> · <?php echo e(number_format((float)$r['latitude'], 3) . ',' . number_format((float)$r['longitude'], 3)); ?> · <?php echo e(timeAgo($r['published_at'])); ?>
              </div>
            </div>
            <div class="nm-recent-actions">
              <form method="POST" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete_one">
                <input type="hidden" name="article_id" value="<?php echo (int)$r['article_id']; ?>">
                <button type="submit" onclick="return confirm('حذف هذا الموقع؟ يمكن إعادة استخراجه لاحقاً.');">🗑</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <!-- PREVIEW -->
  <section class="nm-preview">
    <h2 style="margin:0 0 10px;font-size:17px;">🌐 معاينة الخريطة العامة</h2>
    <iframe class="nm-iframe" src="<?php echo e(SITE_URL); ?>/map" loading="lazy"></iframe>
  </section>
</div>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
