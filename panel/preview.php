<?php
/**
 * نيوز فيد — معاينة فروع Git (Branch Preview)
 *
 * Admin-only endpoint that lets us test a feature branch on the live
 * server BEFORE merging it to main. The same `git fetch + reset --hard`
 * machinery as deploy.php, but pointed at any branch of our choosing.
 *
 * Workflow:
 *   1. Admin visits /panel/preview.php
 *   2. Picks a branch (or types its name) and clicks "معاينة"
 *   3. Server hard-syncs to that branch, flushes cache, resets opcache
 *   4. Admin tests the live site in another tab
 *   5. When done, admin clicks "↻ العودة إلى main" — server hard-syncs
 *      back to origin/main
 *
 * Safety:
 *   - requireRole('admin') — only full admins can switch branches
 *   - CSRF token on every state change
 *   - Branch name validated against a safe pattern (no shell injection)
 *   - git reset --hard on a clean working tree is reversible by
 *     re-running the restore action (main always wins)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cache.php';

requireRole('admin');

$repoDir = dirname(__DIR__);
$output  = '';
$action  = '';
$targetBranch = '';

// Current HEAD — what's deployed right now
function preview_git(string $cmd, string $dir): string {
    return (string)shell_exec('cd ' . escapeshellarg($dir) . ' && ' . $cmd . ' 2>&1');
}
$currentBranch = trim(preview_git('git rev-parse --abbrev-ref HEAD', $repoDir));
$currentCommit = trim(preview_git('git rev-parse --short HEAD', $repoDir));
$currentSubject = trim(preview_git('git log -1 --pretty=%s', $repoDir));
$isOnMain = ($currentBranch === 'main');

// Handle POST actions. requireRole('admin') above already enforces
// the CSRF check for POST requests, so we don't duplicate it here.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'preview') {
        $raw = trim((string)($_POST['branch'] ?? ''));
        // Safe branch name pattern: letters, digits, slashes, dashes,
        // underscores, dots. Covers "feature/foo", "claude/xyz-123",
        // "hotfix/abc.def" but refuses anything with $, `, ;, spaces.
        if ($raw !== '' && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/\\-]{0,120}$#', $raw)) {
            $targetBranch = $raw;
            $b = escapeshellarg($raw);
            $output .= preview_git("git fetch origin $b",        $repoDir);
            $output .= preview_git("git reset --hard origin/$b", $repoDir);
            $output .= preview_git('git clean -fd',              $repoDir);
            if (function_exists('cache_flush')) { cache_flush(); $output .= "\n[cache flushed]\n"; }
            if (function_exists('opcache_reset')) { @opcache_reset(); $output .= "[opcache reset]\n"; }
            @touch($repoDir . '/index.php');
        } else {
            $output = 'اسم فرع غير صالح.';
        }
    } elseif ($action === 'restore') {
        $output .= preview_git('git fetch origin main',        $repoDir);
        $output .= preview_git('git reset --hard origin/main', $repoDir);
        $output .= preview_git('git clean -fd',                $repoDir);
        if (function_exists('cache_flush')) { cache_flush(); $output .= "\n[cache flushed]\n"; }
        if (function_exists('opcache_reset')) { @opcache_reset(); $output .= "[opcache reset]\n"; }
        @touch($repoDir . '/index.php');
    }

    // Re-read after mutation
    $currentBranch  = trim(preview_git('git rev-parse --abbrev-ref HEAD', $repoDir));
    $currentCommit  = trim(preview_git('git rev-parse --short HEAD',      $repoDir));
    $currentSubject = trim(preview_git('git log -1 --pretty=%s',          $repoDir));
    $isOnMain       = ($currentBranch === 'main');
}

// Fetch the list of remote branches so we can show a dropdown.
$branchListRaw = preview_git('git ls-remote --heads origin', $repoDir);
$branches = [];
foreach (preg_split('/\r?\n/', $branchListRaw) as $line) {
    if (preg_match('#refs/heads/(.+)$#', $line, $m)) {
        $branches[] = $m[1];
    }
}
sort($branches);

$adminName = $_SESSION['admin_name'] ?? 'المدير';
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>معاينة فرع — لوحة نيوز فيد</title>
<style>
  body {
    font-family: 'Tajawal','Segoe UI',Tahoma,Arial,sans-serif;
    background:#faf6ec; color:#1a1a2e; margin:0; padding:24px;
  }
  .wrap { max-width:860px; margin:0 auto; }
  h1 { font-size:24px; margin-bottom:4px; }
  .sub { color:#6b7280; font-size:13px; margin-bottom:22px; }
  .card {
    background:#fff; border:1px solid #e0e3e8; border-radius:16px;
    padding:22px; margin-bottom:18px;
    box-shadow:0 2px 10px -4px rgba(0,0,0,.06);
  }
  .state {
    display:flex; align-items:center; gap:14px;
    padding:18px; border-radius:14px;
    background: <?php echo $isOnMain ? '#ecfdf5' : '#fef3c7'; ?>;
    border:1px solid <?php echo $isOnMain ? '#10b981' : '#f59e0b'; ?>;
  }
  .state .icon { font-size:30px; }
  .state .label { font-weight:900; font-size:15px; }
  .state code {
    background:rgba(0,0,0,.06); padding:2px 8px; border-radius:6px;
    font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px;
  }
  label { display:block; font-weight:800; margin-bottom:6px; font-size:13px; }
  select, input[type=text] {
    width:100%; padding:11px 14px; border:1px solid #e0e3e8;
    border-radius:10px; font-size:14px; font-family:inherit;
    background:#fff;
  }
  .row { display:flex; gap:10px; align-items:stretch; }
  .row > * { flex:1; }
  button {
    padding:12px 20px; border-radius:10px; border:none;
    font-weight:900; font-size:14px; cursor:pointer;
    font-family:inherit;
  }
  button.primary { background:#0d9488; color:#fff; }
  button.primary:hover { background:#0f766e; }
  button.danger  { background:#dc2626; color:#fff; }
  button.danger:hover  { background:#b91c1c; }
  .output {
    background:#1a1a2e; color:#a7f3d0;
    padding:16px 18px; border-radius:12px;
    font-family:ui-monospace,Menlo,Consolas,monospace;
    font-size:12px; white-space:pre-wrap; line-height:1.7;
    max-height:340px; overflow:auto; direction:ltr;
  }
  .tips {
    font-size:12.5px; color:#6b7280; line-height:1.85;
    background:#f8fafc; border:1px dashed #e0e3e8; padding:14px 16px;
    border-radius:12px;
  }
  .tips b { color:#1a1a2e; }
  .bar {
    position:fixed; bottom:0; left:0; right:0;
    background:<?php echo $isOnMain ? '#10b981' : '#d97706'; ?>;
    color:#fff; padding:10px 16px; text-align:center;
    font-weight:900; font-size:13px; z-index:9999;
    box-shadow:0 -4px 16px rgba(0,0,0,.2);
  }
  .back { color:#0d9488; font-weight:800; font-size:13px; text-decoration:none; }
</style>
</head>
<body>
<div class="wrap">

  <a class="back" href="index.php">← لوحة التحكم</a>
  <h1 style="margin-top:10px;">🧪 معاينة فرع (Branch Preview)</h1>
  <p class="sub">معاينة أي فرع من GitHub على السيرفر قبل الدمج على <code>main</code>. للإدمن فقط.</p>

  <div class="card">
    <div class="state">
      <span class="icon"><?php echo $isOnMain ? '✅' : '🧪'; ?></span>
      <div>
        <div class="label">
          <?php if ($isOnMain): ?>
            الموقع يعرض الآن فرع <code>main</code> (الإنتاج)
          <?php else: ?>
            ⚠️ الموقع حالياً في وضع معاينة
          <?php endif; ?>
        </div>
        <div style="font-size:12px; color:#475569; margin-top:4px;">
          الفرع: <code><?php echo e($currentBranch); ?></code> ·
          commit: <code><?php echo e($currentCommit); ?></code>
        </div>
        <?php if ($currentSubject !== ''): ?>
          <div style="font-size:12px; color:#475569; margin-top:3px;">
            <?php echo e(mb_substr($currentSubject, 0, 100)); ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (!$isOnMain): ?>
    <div class="card">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="restore">
        <label>انتهيت من الاختبار؟</label>
        <button class="danger" type="submit" style="width:100%;">
          ↻ العودة إلى main (الإنتاج)
        </button>
      </form>
    </div>
  <?php endif; ?>

  <div class="card">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
      <input type="hidden" name="action" value="preview">

      <label>اختر فرعاً من القائمة:</label>
      <select name="branch" style="margin-bottom:12px;">
        <option value="">— اختر —</option>
        <?php foreach ($branches as $b): ?>
          <option value="<?php echo e($b); ?>"<?php echo $b === $currentBranch ? ' selected' : ''; ?>>
            <?php echo e($b); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button class="primary" type="submit" style="width:100%;">
        🧪 معاينة هذا الفرع
      </button>
    </form>
  </div>

  <?php if ($output !== ''): ?>
    <div class="card">
      <label>📜 سجل التنفيذ</label>
      <div class="output"><?php echo e($output); ?></div>
    </div>
  <?php endif; ?>

  <div class="tips">
    <b>كيف تستعمل الصفحة:</b><br>
    1. اختر الفرع اللي تريد معاينته (مثلاً <code>claude/deploy-github-update-oEYfV</code>).<br>
    2. اضغط "معاينة" — السيرفر يتحول إلى ذلك الفرع خلال ثوانٍ.<br>
    3. افتح الموقع في تبويب جديد واختبر الميّزات الجديدة.<br>
    4. لما تخلص، ارجع هنا واضغط "العودة إلى main".<br><br>
    <b>ملاحظة:</b> طالما الموقع على فرع غير <code>main</code>, الزوار يشوفون
    النسخة المعاينة. يفضّل الاختبار في أوقات قليلة الحركة.
  </div>

</div>

<?php if (!$isOnMain): ?>
  <div class="bar">
    ⚠️ وضع معاينة نشط — الموقع يعرض حالياً فرع <?php echo e($currentBranch); ?>. لا تنسَ العودة إلى main بعد الاختبار.
  </div>
<?php endif; ?>

</body>
</html>
