<?php
/**
 * Daily Podcast admin.
 *
 *   - List all episodes with status + play count
 *   - Preview / audio inline
 *   - "Generate now" button (runs today's cron in-process)
 *   - "Script only" button (AI-generates without TTS —
 *     useful when TTS provider is down but you want
 *     to inspect tomorrow's plan)
 *   - Delete button per episode
 *   - Crontab snippet
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/podcast.php';
require_once __DIR__ . '/../includes/podcast_script.php';
require_once __DIR__ . '/../includes/podcast_tts.php';
require_once __DIR__ . '/../cron_podcast.php';   // pulls pod_run_generate_day
requireRole('admin');

ini_set('display_errors', '1');
error_reporting(E_ALL);

pod_ensure_table();
$db = getDB();
$activePage = 'podcast';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? '')) {
        http_response_code(403);
        exit('CSRF token mismatch');
    }
    @set_time_limit(600);
    $action = (string)($_POST['action'] ?? '');
    $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['date'] ?? ''))
            ? (string)$_POST['date']
            : date('Y-m-d');

    try {
        if ($action === 'generate') {
            $r = pod_run_generate_day($date, true, false);
            $type = $r['ok'] ? 'ok' : 'err';
            $_SESSION['pod_flash'] = [
                'type' => $type,
                'msg'  => ($r['ok'] ? '✓ تم توليد الحلقة لـ ' : '✗ فشل التوليد لـ ') . $date,
                'log'  => $r['log'],
            ];
        } elseif ($action === 'script_only') {
            $r = pod_run_generate_day($date, true, true);
            $type = $r['ok'] ? 'ok' : 'err';
            $_SESSION['pod_flash'] = [
                'type' => $type,
                'msg'  => ($r['ok'] ? '✓ تم توليد السكربت فقط لـ ' : '✗ فشل السكربت لـ ') . $date,
                'log'  => $r['log'],
            ];
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $ep = $db->prepare("SELECT audio_path FROM podcast_episodes WHERE id = ?");
                $ep->execute([$id]);
                $row = $ep->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['audio_path']) {
                    $abs = __DIR__ . '/../' . ltrim($row['audio_path'], '/');
                    if (is_file($abs)) @unlink($abs);
                }
                $db->prepare("DELETE FROM podcast_episodes WHERE id = ?")->execute([$id]);
                $_SESSION['pod_flash'] = ['type' => 'ok', 'msg' => 'تم حذف الحلقة.'];
            }
        }
    } catch (Throwable $e) {
        $_SESSION['pod_flash'] = [
            'type' => 'err',
            'msg'  => '✗ استثناء: ' . $e->getMessage(),
            'log'  => $e->getMessage() . "\n\n" . $e->getTraceAsString(),
        ];
    }

    header('Location: podcast.php');
    exit;
}

$flash = $_SESSION['pod_flash'] ?? null;
unset($_SESSION['pod_flash']);

$episodes = $db->query("SELECT * FROM podcast_episodes ORDER BY episode_date DESC LIMIT 40")
               ->fetchAll(PDO::FETCH_ASSOC);
$cronKey  = getSetting('cron_key', '');
$cronUrl  = SITE_URL . '/cron_podcast.php?key=' . ($cronKey ?: 'YOUR_CRON_KEY');

include __DIR__ . '/includes/panel_layout_head.php';
?>

<style>
  .pod-admin { padding: 20px 24px; display: grid; grid-template-columns: 1fr 320px; gap: 20px; }
  .pod-admin-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px; }
  .pod-admin h2 { margin:0 0 12px; font-size:17px; }
  .pod-actions { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
  .pod-actions form { display:inline; }
  .pod-actions button { padding:9px 16px; border:0; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; }
  .pod-actions .primary { background:#0d9488; color:#fff; }
  .pod-actions .warn    { background:#f59e0b; color:#fff; }
  .pod-actions .ghost   { background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; }
  .pod-flash { padding:12px 14px; border-radius:8px; margin-bottom:14px; font-size:13.5px; grid-column:1/-1; }
  .pod-flash.ok  { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; }
  .pod-flash.err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
  .pod-log { background:#0f172a; color:#e2e8f0; border-radius:8px; padding:12px; font-family:Menlo,Consolas,monospace; font-size:12px; white-space:pre-wrap; max-height:260px; overflow-y:auto; margin-top:10px; }
  .pod-cron { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px; font-family:Menlo,Consolas,monospace; font-size:11px; word-break:break-all; color:#374151; margin-bottom:8px; }
  .pod-ep { padding: 12px 0; border-bottom: 1px solid #f3f4f6; display:grid; grid-template-columns: auto 1fr auto; gap:14px; align-items:center; }
  .pod-ep:last-child { border-bottom:0; }
  .pod-ep-ico { font-size:26px; }
  .pod-ep-date { font-size:12px; color:#6b7280; font-weight:700; }
  .pod-ep-title { font-size:14px; font-weight:700; margin-top:2px; }
  .pod-ep-meta { font-size:11.5px; color:#6b7280; margin-top:3px; display:flex; gap:10px; flex-wrap:wrap; }
  .pod-ep-meta .sent  { color:#059669; font-weight:700; }
  .pod-ep-meta .draft { color:#b45309; font-weight:700; }
  .pod-ep audio { width: 220px; height: 30px; }
  .pod-ep-actions { display:flex; gap:6px; }
  .pod-ep-actions button { background:transparent; border:0; cursor:pointer; padding:4px 8px; border-radius:4px; font-size:14px; }
  .pod-ep-actions button:hover { background:#fee2e2; color:#991b1b; }
  @media (max-width: 900px) { .pod-admin { grid-template-columns: 1fr; } .pod-ep audio { width: 160px; } }
</style>

<div class="pod-admin">
  <?php if (!empty($flash['msg'])): ?>
    <div class="pod-flash <?php echo ($flash['type'] ?? '') === 'err' ? 'err' : 'ok'; ?>">
      <?php echo e($flash['msg']); ?>
      <?php if (!empty($flash['log'])): ?>
        <div class="pod-log"><?php echo e($flash['log']); ?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <section class="pod-admin-card">
    <h2>📻 حلقات البودكاست</h2>

    <div class="pod-actions">
      <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="generate">
        <input type="hidden" name="date"   value="<?php echo e(date('Y-m-d')); ?>">
        <button type="submit" class="primary"
                onclick="return confirm('سيتم استدعاء الـ AI وتوليد MP3. متابعة؟');">
          🎙 توليد حلقة اليوم الآن
        </button>
      </form>
      <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="script_only">
        <input type="hidden" name="date"   value="<?php echo e(date('Y-m-d')); ?>">
        <button type="submit" class="warn">📝 سكربت فقط (بدون صوت)</button>
      </form>
    </div>

    <?php if (!$episodes): ?>
      <p style="color:#6b7280;">لا توجد حلقات بعد. شغّلي الأزرار أعلاه لأول حلقة.</p>
    <?php else: ?>
      <?php foreach ($episodes as $ep): ?>
        <div class="pod-ep">
          <div class="pod-ep-ico">📻</div>
          <div>
            <div class="pod-ep-date"><?php echo e($ep['episode_date']); ?></div>
            <div class="pod-ep-title"><?php echo e(mb_substr($ep['title'], 0, 90)); ?></div>
            <div class="pod-ep-meta">
              <?php if ($ep['audio_path']): ?>
                <span class="sent">✓ منشور</span>
                <span>⏱ <?php echo floor($ep['duration_seconds'] / 60) . ':' . str_pad((string)($ep['duration_seconds'] % 60), 2, '0', STR_PAD_LEFT); ?></span>
                <span>🗣 <?php echo e($ep['tts_provider']); ?></span>
              <?php else: ?>
                <span class="draft">مسودّة — بدون صوت</span>
              <?php endif; ?>
              <span>▶ <?php echo number_format((int)$ep['play_count']); ?></span>
              <a href="<?php echo e(SITE_URL); ?>/podcast/<?php echo e($ep['episode_date']); ?>" target="_blank" style="color:#0d9488;">🌐 فتح</a>
            </div>
          </div>
          <div class="pod-ep-actions">
            <?php if ($ep['audio_path']): ?>
              <audio controls preload="none" src="<?php echo e('/' . ltrim($ep['audio_path'], '/')); ?>"></audio>
            <?php endif; ?>
            <form method="POST" style="display:inline;">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id"     value="<?php echo (int)$ep['id']; ?>">
              <button type="submit" onclick="return confirm('حذف نهائي؟');">🗑</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <aside class="pod-admin-card">
    <h2>⏰ الجدولة</h2>
    <p style="font-size:13px;color:#6b7280;margin:0 0 10px;">
      شغّلي حلقة جديدة كل صباح ٦:٠٠:
    </p>
    <div class="pod-cron">0 6 * * * curl -fsS "<?php echo e($cronUrl); ?>" > /dev/null 2>&1</div>
    <p style="font-size:12px;color:#6b7280;margin:14px 0 6px;">مسار الـ RSS:</p>
    <div class="pod-cron"><?php echo e(SITE_URL); ?>/podcast.xml</div>
    <p style="font-size:12px;color:#6b7280;margin:10px 0;">
      أرسلي هذا الرابط لـ:<br>
      <a href="https://podcasters.apple.com" target="_blank" style="color:#A43DCC;">🎧 Apple Podcasts Connect</a><br>
      <a href="https://podcasters.spotify.com" target="_blank" style="color:#1DB954;">🎧 Spotify for Creators</a>
    </p>
  </aside>
</div>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
