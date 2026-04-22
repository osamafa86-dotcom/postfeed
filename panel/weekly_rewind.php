<?php
/**
 * Weekly Rewind admin.
 *
 *   - List all past rewinds with key stats
 *   - Preview a specific week in an iframe
 *   - Regenerate (re-run the AI curator) — destructive for content
 *   - Send email to confirmed subscribers
 *   - Copy CLI/curl commands for the two cron jobs
 *
 * Admin role required because regenerate + send both have cost / delivery
 * side effects.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/weekly_rewind.php';
requireRole('admin');

wr_ensure_table();
$db = getDB();
$activePage = 'weekly_rewind';

$flash = null;

/* ------------------------------------------------------------------
 * Actions (CSRF-protected POST).
 * All three actions shell out to the same scripts the cron uses,
 * so the panel stays thin and the scheduled runs can't diverge
 * from manual runs.
 * ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    @set_time_limit(600);
    $action = (string)($_POST['action'] ?? '');
    $week   = preg_match('/^\d{4}-\d{1,2}$/', (string)($_POST['week'] ?? ''))
            ? (string)$_POST['week']
            : wr_year_week_for(time());

    if ($action === 'generate') {
        // In-process — never shell_exec (disabled on most shared hosts).
        $r = wr_run_generate($week, true);
        $flash = [
            'type' => $r['ok'] ? 'ok' : 'err',
            'msg'  => ($r['ok'] ? '✓ تم توليد المراجعة للأسبوع ' : '✗ فشل التوليد للأسبوع ') . $week,
            'log'  => $r['log'],
        ];
    } elseif ($action === 'send') {
        $force = !empty($_POST['force']);
        $r = wr_run_send($week, $force, false);
        $flash = [
            'type' => $r['ok'] ? 'ok' : 'err',
            'msg'  => ($r['ok'] ? '✓ تم الإرسال للأسبوع ' : '✗ فشل الإرسال للأسبوع ') . $week,
            'log'  => $r['log'],
        ];
    } elseif ($action === 'backfill') {
        $weeks = max(1, min(12, (int)($_POST['weeks'] ?? 4)));
        $r = wr_run_backfill($weeks);
        $flash = [
            'type' => $r['ok'] ? 'ok' : 'err',
            'msg'  => ($r['ok'] ? '✓ اكتمل backfill لـ ' : '✗ فشل backfill لـ ') . $weeks . ' أسابيع',
            'log'  => $r['log'],
        ];
    } elseif ($action === 'send_test') {
        require_once __DIR__ . '/../includes/mailer.php';
        $email = filter_var(trim((string)($_POST['test_email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $rewind = wr_get_by_week($week) ?: wr_get_latest();
        if (!$email) {
            $flash = ['type' => 'err', 'msg' => 'بريد غير صالح.'];
        } elseif (!$rewind) {
            $flash = ['type' => 'err', 'msg' => 'لا توجد مراجعة للأسبوع المطلوب.'];
        } else {
            $unsub = SITE_URL . '/newsletter/unsubscribe/test';
            $html  = wr_email_html($rewind, $unsub, SITE_URL . '/weekly/' . $rewind['year_week']);
            $ok    = mailer_send($email, 'اختبار: مراجعة الأسبوع — ' . $rewind['cover_title'], $html);
            $flash = $ok
                ? ['type' => 'ok',  'msg' => 'أُرسلت نسخة اختبارية إلى ' . $email]
                : ['type' => 'err', 'msg' => 'فشل الإرسال: ' . mailer_last_error()];
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("DELETE FROM weekly_rewind_deliveries WHERE rewind_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM weekly_rewinds WHERE id = ?")->execute([$id]);
            $flash = ['type' => 'ok', 'msg' => 'تم الحذف.'];
        }
    }
    // PRG
    $goto = 'weekly_rewind.php' . ($flash ? '?_m=' . urlencode(substr($flash['msg'] ?? '', 0, 120)) : '');
    if (!empty($flash['log'])) {
        $_SESSION['wr_last_log'] = $flash['log'];
        $_SESSION['wr_last_msg'] = $flash['msg'];
        $_SESSION['wr_last_type'] = $flash['type'];
    } else {
        $_SESSION['wr_last_msg']  = $flash['msg']  ?? '';
        $_SESSION['wr_last_type'] = $flash['type'] ?? '';
    }
    header('Location: ' . $goto);
    exit;
}

// Pull the flash back from session (PRG pattern).
$lastMsg  = $_SESSION['wr_last_msg']  ?? '';
$lastType = $_SESSION['wr_last_type'] ?? '';
$lastLog  = $_SESSION['wr_last_log']  ?? '';
unset($_SESSION['wr_last_msg'], $_SESSION['wr_last_type'], $_SESSION['wr_last_log']);

$rewinds = wr_list(30);
$selectedWeek = (string)($_GET['week'] ?? ($rewinds[0]['year_week'] ?? wr_year_week_for(time())));
$selected = wr_get_by_week($selectedWeek);

$cronKey = getSetting('cron_key', '');
$cronUrl = SITE_URL . '/cron_weekly_rewind.php?key=' . ($cronKey ?: 'YOUR_CRON_KEY');
$sendUrl = SITE_URL . '/cron_weekly_rewind_send.php?key=' . ($cronKey ?: 'YOUR_CRON_KEY');

include __DIR__ . '/includes/panel_layout_head.php';
?>

<style>
  .wr-panel { display: grid; grid-template-columns: 340px 1fr; gap: 20px; padding: 20px 24px; }
  .wr-panel-side { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; }
  .wr-panel-main { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; }
  .wr-panel h2 { margin: 0 0 14px; font-size: 18px; }
  .wr-panel h3 { font-size: 14px; margin: 0 0 10px; color: #374151; }
  .wr-list { display: flex; flex-direction: column; gap: 6px; max-height: 380px; overflow-y: auto; }
  .wr-list a { display: flex; justify-content: space-between; gap: 8px; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; text-decoration: none; color: #1a1a2e; font-size: 13px; }
  .wr-list a.active { background: #eef2ff; border-color: #6366f1; color: #3730a3; font-weight: 700; }
  .wr-list-wk { font-weight: 700; }
  .wr-list-tag { font-size: 11px; padding: 2px 6px; border-radius: 4px; background: #f3f4f6; color: #6b7280; }
  .wr-list-tag.sent { background: #d1fae5; color: #065f46; }
  .wr-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
  .wr-actions button, .wr-actions .btn { padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; border: 0; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
  .wr-btn-primary { background: #6366f1; color: #fff; }
  .wr-btn-warn    { background: #f59e0b; color: #fff; }
  .wr-btn-danger  { background: #ef4444; color: #fff; }
  .wr-btn-ghost   { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
  .wr-section { padding: 14px 0; border-top: 1px solid #f3f4f6; }
  .wr-stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 12px; }
  .wr-stat { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; text-align: center; }
  .wr-stat-v { font-size: 22px; font-weight: 900; color: #1a1a2e; }
  .wr-stat-l { font-size: 11px; color: #6b7280; }
  .wr-cronbox { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; font-family: Menlo,Consolas,monospace; font-size: 11.5px; word-break: break-all; margin-bottom: 8px; color: #374151; }
  .wr-preview-frame { width: 100%; height: 1400px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; }
  .wr-flash { padding: 12px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 13.5px; }
  .wr-flash.ok  { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
  .wr-flash.err { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  .wr-log { background: #0f172a; color: #e2e8f0; border-radius: 8px; padding: 12px; font-family: Menlo,Consolas,monospace; font-size: 12px; white-space: pre-wrap; max-height: 200px; overflow-y: auto; margin-top: 10px; }
  .wr-gen-form { display: flex; gap: 8px; margin: 12px 0; align-items: center; flex-wrap: wrap; }
  .wr-gen-form input[type=text], .wr-gen-form input[type=email] { padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; font-family: inherit; }
  @media (max-width: 900px) { .wr-panel { grid-template-columns: 1fr; } }
</style>

<div class="wr-panel">

  <!-- SIDE: list + cron info -->
  <aside class="wr-panel-side">
    <h2>📅 مراجعات الأسبوع</h2>

    <?php if ($rewinds): ?>
      <div class="wr-list">
        <?php foreach ($rewinds as $r): ?>
          <a href="weekly_rewind.php?week=<?php echo e($r['year_week']); ?>"
             class="<?php echo $r['year_week'] === $selectedWeek ? 'active' : ''; ?>">
            <span class="wr-list-wk"><?php echo e($r['year_week']); ?></span>
            <span class="wr-list-tag <?php echo !empty($r['emailed_at']) ? 'sent' : ''; ?>">
              <?php echo !empty($r['emailed_at']) ? '✓ مُرسلة' : 'مسودة'; ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="font-size:13px;color:#6b7280;">لا توجد مراجعات بعد. ابدأ بتوليد واحدة.</p>
    <?php endif; ?>

    <div class="wr-section">
      <h3>⏰ أوامر الـ cron</h3>
      <p style="font-size:12px;color:#6b7280;margin:0 0 6px;">توليد (السبت ٢٠:٠٠):</p>
      <div class="wr-cronbox">0 20 * * 6 curl -fsS "<?php echo e($cronUrl); ?>"</div>
      <p style="font-size:12px;color:#6b7280;margin:0 0 6px;">إرسال (الأحد ٠٧:٠٠):</p>
      <div class="wr-cronbox">0 7 * * 0 curl -fsS "<?php echo e($sendUrl); ?>"</div>
    </div>
  </aside>

  <!-- MAIN: selected rewind -->
  <section class="wr-panel-main">
    <?php if ($lastMsg): ?>
      <div class="wr-flash <?php echo $lastType === 'err' ? 'err' : 'ok'; ?>">
        <?php echo e($lastMsg); ?>
      </div>
      <?php if ($lastLog): ?>
        <div class="wr-log"><?php echo e($lastLog); ?></div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($selected): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
        <div>
          <h2 style="margin:0;"><?php echo e($selected['cover_title']); ?></h2>
          <div style="font-size:13px;color:#6b7280;margin-top:4px;">
            <?php echo e($selected['year_week']); ?> ·
            <?php echo e($selected['start_date']); ?> → <?php echo e($selected['end_date']); ?>
            <?php if (!empty($selected['emailed_at'])): ?>
              · <span style="color:#059669;font-weight:700;">أُرسلت <?php echo e($selected['emailed_at']); ?></span>
            <?php else: ?>
              · <span style="color:#b45309;font-weight:700;">مسودة</span>
            <?php endif; ?>
          </div>
        </div>
        <a href="<?php echo e(SITE_URL); ?>/weekly/<?php echo e($selected['year_week']); ?>" target="_blank" class="wr-btn-ghost btn">🌐 فتح على الموقع</a>
      </div>

      <div class="wr-stat-grid">
        <div class="wr-stat">
          <div class="wr-stat-v"><?php echo count($selected['content']['stories'] ?? []); ?></div>
          <div class="wr-stat-l">قصة</div>
        </div>
        <div class="wr-stat">
          <div class="wr-stat-v"><?php echo (int)($selected['stats']['candidates_reviewed'] ?? 0); ?></div>
          <div class="wr-stat-l">مرشّح راجعه AI</div>
        </div>
        <div class="wr-stat">
          <div class="wr-stat-v"><?php echo number_format($selected['view_count']); ?></div>
          <div class="wr-stat-l">مشاهدة</div>
        </div>
      </div>

      <div class="wr-actions">
        <form method="POST" style="display:inline;">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="generate">
          <input type="hidden" name="week" value="<?php echo e($selected['year_week']); ?>">
          <button type="submit" class="wr-btn-warn"
                  onclick="return confirm('ستعيد توليد المحتوى بالـ AI. المحتوى الحالي سيُستبدل. متابعة؟');">
            🔄 إعادة توليد
          </button>
        </form>
        <form method="POST" style="display:inline;">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="send">
          <input type="hidden" name="week" value="<?php echo e($selected['year_week']); ?>">
          <button type="submit" class="wr-btn-primary"
                  onclick="return confirm('إرسال لجميع المشتركين المؤكّدين؟');">
            📧 إرسال لكل المشتركين
          </button>
        </form>
        <?php if (!empty($selected['emailed_at'])): ?>
          <form method="POST" style="display:inline;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="week" value="<?php echo e($selected['year_week']); ?>">
            <input type="hidden" name="force" value="1">
            <button type="submit" class="wr-btn-ghost"
                    onclick="return confirm('إرسال مرّة ثانية رغم أنه أُرسل سابقاً؟ (قد يصل للمشترك مرّتين)');">
              ♻ إعادة إرسال (force)
            </button>
          </form>
        <?php endif; ?>
        <form method="POST" style="display:inline;">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?php echo (int)$selected['id']; ?>">
          <button type="submit" class="wr-btn-danger"
                  onclick="return confirm('حذف هذه المراجعة نهائياً؟');">
            🗑 حذف
          </button>
        </form>
      </div>

      <form method="POST" class="wr-gen-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="send_test">
        <input type="hidden" name="week" value="<?php echo e($selected['year_week']); ?>">
        <label style="font-size:13px;color:#374151;font-weight:700;">اختبار بريد:</label>
        <input type="email" name="test_email" placeholder="your@email.com" required>
        <button type="submit" class="wr-btn-ghost">📨 إرسال اختبار</button>
      </form>

      <h3 style="margin-top:18px;">معاينة الصفحة العامة</h3>
      <iframe class="wr-preview-frame"
              src="<?php echo e(SITE_URL); ?>/weekly/<?php echo e($selected['year_week']); ?>"
              loading="lazy"></iframe>

    <?php else: ?>
      <h2>لا توجد مراجعة محفوظة</h2>
      <p style="color:#6b7280;">ابدأ بتوليد مراجعة للأسبوع الحالي، أو املأ الأرشيف بالأسابيع السابقة دفعة واحدة.</p>

      <form method="POST" class="wr-gen-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="generate">
        <label style="font-size:13px;color:#374151;font-weight:700;">توليد أسبوع واحد:</label>
        <input type="text" name="week" value="<?php echo e(wr_year_week_for(time())); ?>" pattern="\d{4}-\d{1,2}" required>
        <button type="submit" class="wr-btn-primary">🤖 توليد بالـ AI</button>
      </form>

      <form method="POST" class="wr-gen-form" style="margin-top:16px;padding-top:14px;border-top:1px solid #f3f4f6;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="backfill">
        <label style="font-size:13px;color:#374151;font-weight:700;">أو املأ الأرشيف بأخر:</label>
        <input type="text" name="weeks" value="4" pattern="\d{1,2}" required style="width:60px;text-align:center;">
        <span style="font-size:13px;color:#374151;">أسابيع</span>
        <button type="submit" class="wr-btn-warn"
                onclick="return confirm('سيقوم بعدة استدعاءات للـ AI (واحدة لكل أسبوع). قد يستغرق دقيقتين. متابعة؟');">
          📚 توليد عدة أسابيع
        </button>
      </form>
    <?php endif; ?>
  </section>
</div>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
