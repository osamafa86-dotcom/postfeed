<?php
/**
 * نيوز فيد — صفحة عرض ملخص منصة اجتماعية (X / يوتيوب)
 * ==============================================================
 * Used by /summaries to give twitter / youtube social briefings a
 * stable, share-able URL. The existing /sabah and /weekly and
 * /tg-report pages already have their own viewers; this page covers
 * the social_summaries rows that previously lived only on the API.
 *
 * URL: /summary-view.php?platform=twitter&id=12
 *      /summary-view.php?platform=youtube&id=12&print=1
 *
 * Supports the same ?print=1 auto-print convention as the other
 * summary viewers so the hub's "تنزيل / طباعة" actions work uniformly.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/ai_helper.php';

$platform = (string)($_GET['platform'] ?? '');
$id       = (int)($_GET['id'] ?? 0);
$platform = in_array($platform, ['twitter', 'youtube'], true) ? $platform : '';

if ($platform === '' || $id <= 0) {
    http_response_code(400);
    header('Location: /summaries');
    exit;
}

$summary = social_summary_get_by_id($id);
if (!$summary || ($summary['platform'] ?? '') !== $platform) {
    http_response_code(404);
    header('Location: /summaries');
    exit;
}

$pageTheme = current_theme();
$viewer    = current_user();
$viewerId  = (int)($viewer['id'] ?? 0);
$userUnread = $viewerId ? getUnreadNotifCount() : 0;

$platformMeta = [
    'twitter' => ['label' => '𝕏 ملخص منصة X',     'color' => '#1d9bf0', 'archive' => '/twitter_feed.php'],
    'youtube' => ['label' => '▶ ملخص يوتيوب',     'color' => '#ff0000', 'archive' => '/youtube_feed.php'],
][$platform];

$pageTitle = ($summary['headline'] ?? $platformMeta['label']) . ' | نيوز فيد';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($pageTitle); ?></title>
<meta name="description" content="<?php echo e(mb_substr(strip_tags($summary['summary'] ?? ''), 0, 160)); ?>">
<link rel="stylesheet" href="assets/css/home.min.css?v=<?php echo filemtime(__DIR__ . '/assets/css/home.min.css'); ?>">
<link rel="stylesheet" href="assets/css/home-redesign.css?v=<?php echo filemtime(__DIR__ . '/assets/css/home-redesign.css'); ?>">
<style>
.sv-hero{background:linear-gradient(135deg,<?php echo e($platformMeta['color']); ?>,#1f3417);color:#fff;padding:32px 24px;margin-bottom:28px;border-radius:0 0 var(--radius-lg) var(--radius-lg);}
.sv-hero-inner{max-width:880px;margin:0 auto;}
.sv-eyebrow{font-size:12px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;opacity:.85;margin-bottom:8px;}
.sv-hero h1{font-size:28px;font-weight:900;line-height:1.4;margin:0 0 10px;}
.sv-hero-sub{font-size:14px;opacity:.85;line-height:1.7;margin:0;}
.sv-hero-meta{display:flex;align-items:center;gap:16px;margin-top:16px;font-size:12.5px;opacity:.85;flex-wrap:wrap;}
.sv-container{max-width:880px;margin:0 auto;padding:0 24px 60px;}
.sv-actions{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:24px;}
.sv-act{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer;border:1px solid var(--border);background:var(--surface);color:var(--text-2);font-family:inherit;}
.sv-act:hover{background:var(--accent);color:#fff;border-color:var(--accent);}
.sv-act-primary{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;border-color:var(--accent-2);}
.sv-summary{font-size:16px;line-height:1.9;color:var(--text);background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;margin-bottom:24px;box-shadow:var(--shadow-sm);}
.sv-section{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px 24px;margin-bottom:18px;box-shadow:var(--shadow-sm);}
.sv-section h3{font-size:17px;font-weight:800;margin:0 0 12px;color:var(--text);display:flex;align-items:center;gap:8px;}
.sv-section h3::before{content:"";width:4px;height:18px;border-radius:2px;background:<?php echo e($platformMeta['color']); ?>;}
.sv-section ul{margin:0;padding:0 20px;list-style:disc;}
.sv-section li{font-size:14.5px;line-height:1.8;color:var(--text-2);margin-bottom:6px;}
.sv-numbers{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;}
.sv-number{background:var(--bg-2);border:1px solid var(--border);border-radius:var(--radius);padding:14px;text-align:center;}
.sv-number b{display:block;font-size:22px;font-weight:900;color:<?php echo e($platformMeta['color']); ?>;margin-bottom:4px;}
.sv-number em{font-style:normal;font-size:11.5px;color:var(--muted);}
.sv-topics{display:flex;flex-wrap:wrap;gap:8px;}
.sv-topic{font-size:12.5px;font-weight:700;color:var(--text-2);background:var(--bg-3);padding:6px 12px;border-radius:999px;}
@media print {
  body{background:#fff !important;}
  .site-header,.site-nav,footer,.sv-actions,.nfr-topbar,.ticker-wrap{display:none !important;}
  .sv-hero{background:#fff !important;color:#000 !important;padding:0 0 20px;border-radius:0;border-bottom:2px solid #000;}
  .sv-eyebrow,.sv-hero h1,.sv-hero-sub,.sv-hero-meta{color:#000 !important;opacity:1 !important;}
  .sv-section,.sv-summary{box-shadow:none !important;border:1px solid #ddd;page-break-inside:avoid;}
}
@media (max-width:640px){.sv-hero{padding:22px 16px;}.sv-hero h1{font-size:22px;}.sv-container{padding:0 16px 40px;}}
</style>
</head>
<body class="nf-redesign">
<?php
$activeType = 'summaries';
$showTicker = false;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="sv-hero">
  <div class="sv-hero-inner">
    <div class="sv-eyebrow"><?php echo e($platformMeta['label']); ?></div>
    <h1><?php echo e($summary['headline'] ?? ''); ?></h1>
    <?php if (!empty($summary['subheadline'])): ?>
      <p class="sv-hero-sub"><?php echo e($summary['subheadline']); ?></p>
    <?php endif; ?>
    <div class="sv-hero-meta">
      <span>📅 <?php echo e($summary['generated_at'] ?? ''); ?></span>
      <?php if (!empty($summary['message_count'])): ?>
        <span>· <?php echo number_format((int)$summary['message_count']); ?> منشور</span>
      <?php endif; ?>
      <?php if (!empty($summary['window_mins'])): ?>
        <span>· نافذة <?php echo (int)$summary['window_mins']; ?> دقيقة</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="sv-container">
  <div class="sv-actions">
    <a class="sv-act sv-act-primary" href="/summaries">← كل الملخصات</a>
    <a class="sv-act" href="<?php echo e($platformMeta['archive']); ?>">📂 أرشيف هذا المصدر</a>
    <button type="button" class="sv-act" onclick="window.print()">🖨 طباعة</button>
    <button type="button" class="sv-act" onclick="window.print()">⬇ تنزيل PDF</button>
  </div>

  <?php if (!empty($summary['summary'])): ?>
    <div class="sv-summary"><?php echo nl2br(e($summary['summary'])); ?></div>
  <?php endif; ?>

  <?php if (!empty($summary['sections']) && is_array($summary['sections'])): ?>
    <?php foreach ($summary['sections'] as $sec):
      $title = is_array($sec) ? ($sec['title'] ?? '') : '';
      $bullets = is_array($sec) ? ($sec['bullets'] ?? []) : [];
      if (!$title && !$bullets) continue;
    ?>
      <section class="sv-section">
        <?php if ($title): ?><h3><?php echo e($title); ?></h3><?php endif; ?>
        <?php if (!empty($bullets) && is_array($bullets)): ?>
          <ul>
            <?php foreach ($bullets as $b): ?>
              <li><?php echo e(is_string($b) ? $b : (string)($b['text'] ?? '')); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($summary['key_numbers']) && is_array($summary['key_numbers'])): ?>
    <section class="sv-section">
      <h3>أرقام لافتة</h3>
      <div class="sv-numbers">
        <?php foreach ($summary['key_numbers'] as $kn):
          if (!is_array($kn)) continue;
          $val = $kn['value'] ?? ($kn['number'] ?? '');
          $lbl = $kn['label'] ?? ($kn['caption'] ?? '');
          if (!$val && !$lbl) continue;
        ?>
          <div class="sv-number">
            <b><?php echo e((string)$val); ?></b>
            <em><?php echo e((string)$lbl); ?></em>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (!empty($summary['topics']) && is_array($summary['topics'])): ?>
    <section class="sv-section">
      <h3>أبرز المواضيع</h3>
      <div class="sv-topics">
        <?php foreach ($summary['topics'] as $tp):
          $name = is_string($tp) ? $tp : (string)($tp['name'] ?? $tp['title'] ?? '');
          if (!$name) continue;
        ?>
          <span class="sv-topic">#<?php echo e($name); ?></span>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<footer>
  <div class="footer-bottom" style="max-width:1400px;margin:0 auto;padding:20px 24px;text-align:center;">
    <span>© <?php echo date('Y'); ?> نيوز فيد — جميع الحقوق محفوظة</span>
  </div>
</footer>

<script>
/* Auto-print when opened from /summaries with ?print=1. */
(function(){
  var qs = new URLSearchParams(window.location.search);
  if (qs.get('print') === '1') {
    window.addEventListener('load', function() {
      setTimeout(function() { window.print(); }, 600);
    });
  }
})();
</script>
</body>
</html>
