<?php
/**
 * نيوز فيد — هاب الملخصات
 * ========================
 * صفحة مركزية تعرض كل ملخصات الموقع في مكان واحد:
 *   - موجز الصباح (sabah)
 *   - مراجعة الأسبوع (weekly rewind)
 *   - ملخصات تلغرام (tg_summaries)
 *   - ملخصات تويتر/X (social_summaries: twitter)
 *   - ملخصات يوتيوب (social_summaries: youtube)
 *
 * لكل ملخص أربعة إجراءات:
 *   - قراءة كاملة (يفتح الصفحة الكاملة للموجز)
 *   - معاينة PDF  (يفتح الصفحة الكاملة + print dialog)
 *   - تنزيل      (نفس print dialog — "Save as PDF" خيار قياسي للمتصفح)
 *   - طباعة      (نفس print dialog)
 *
 * الـ rewrite: /summaries → summaries.php (في .htaccess)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/sabah.php';
require_once __DIR__ . '/includes/weekly_rewind.php';
require_once __DIR__ . '/includes/ai_helper.php';

$pageTheme = current_theme();
$viewer    = current_user();
$viewerId  = (int)($viewer['id'] ?? 0);
$userUnread = $viewerId ? getUnreadNotifCount() : 0;

// Pull each surface in a try/catch so a single broken table can't take
// the hub down. Most users won't have all of them populated, so empty
// arrays are the normal case and the template handles them gracefully.
$briefings = [
    'sabah'    => function_exists('sabah_list')           ? sabah_list(8)                  : [],
    'weekly'   => function_exists('wr_list')              ? wr_list(8)                     : [],
    'telegram' => function_exists('tg_summary_list')      ? tg_summary_list(10)            : [],
    'twitter'  => function_exists('social_summary_list')  ? social_summary_list('twitter', 10)  : [],
    'youtube'  => function_exists('social_summary_list')  ? social_summary_list('youtube', 10)  : [],
];

// Shared meta used by every action — keeps the cards uniform regardless
// of which surface the row came from. Each entry needs:
//   title      — what we show in bold on the card
//   subtitle   — small descriptor under the title (date, source, etc.)
//   url_read   — link to the full reading view
//   url_print  — link to the same view + auto-print on load
//   archive_url — bottom-row "more from this category" link
$surfaces = [
    'sabah' => [
        'label'       => '☕ موجز الصباح',
        'color'       => '#B8860B',
        'archive_url' => '/sabah',
        'item'        => function($row) {
            $date = $row['briefing_date'] ?? '';
            return [
                'title'    => $row['headline'] ?? ('موجز ' . $date),
                'subtitle' => $date ? date('l، j F Y', strtotime($date)) : '',
                'url_read'  => '/sabah/' . rawurlencode($date),
                'url_print' => '/sabah/' . rawurlencode($date) . '?print=1',
            ];
        },
    ],
    'weekly' => [
        'label'       => '📅 مراجعة الأسبوع',
        'color'       => '#3D5A28',
        'archive_url' => '/weekly/archive',
        'item'        => function($row) {
            $yw = $row['year_week'] ?? '';
            return [
                'title'    => $row['headline'] ?? ('أسبوع ' . $yw),
                'subtitle' => $yw ? ('أسبوع ' . $yw) : '',
                'url_read'  => '/weekly/' . rawurlencode($yw),
                'url_print' => '/weekly/' . rawurlencode($yw) . '?print=1',
            ];
        },
    ],
    'telegram' => [
        'label'       => '📢 ملخصات تلغرام',
        'color'       => '#0088cc',
        'archive_url' => '/telegram.php',
        'item'        => function($row) {
            return [
                'title'    => $row['headline'] ?? ('موجز تلغرام #' . $row['id']),
                'subtitle' => $row['generated_at'] ?? '',
                'url_read'  => '/tg-report/' . (int)$row['id'],
                'url_print' => '/tg-report/' . (int)$row['id'] . '?print=1',
            ];
        },
    ],
    'twitter' => [
        'label'       => '𝕏 ملخصات منصة X',
        'color'       => '#1d9bf0',
        'archive_url' => '/twitter_feed.php',
        'item'        => function($row) {
            return [
                'title'    => $row['headline'] ?? ('موجز X #' . $row['id']),
                'subtitle' => $row['generated_at'] ?? '',
                'url_read'  => '/summary-view.php?platform=twitter&id=' . (int)$row['id'],
                'url_print' => '/summary-view.php?platform=twitter&id=' . (int)$row['id'] . '&print=1',
            ];
        },
    ],
    'youtube' => [
        'label'       => '▶ ملخصات يوتيوب',
        'color'       => '#ff0000',
        'archive_url' => '/youtube_feed.php',
        'item'        => function($row) {
            return [
                'title'    => $row['headline'] ?? ('موجز يوتيوب #' . $row['id']),
                'subtitle' => $row['generated_at'] ?? '',
                'url_read'  => '/summary-view.php?platform=youtube&id=' . (int)$row['id'],
                'url_print' => '/summary-view.php?platform=youtube&id=' . (int)$row['id'] . '&print=1',
            ];
        },
    ],
];

$pageTitle = 'الملخصات | نيوز فيد';
$pageDescription = 'كل ملخصات الموقع في مكان واحد: موجز الصباح، مراجعة الأسبوع، ملخصات تلغرام وتويتر ويوتيوب — مع إمكانية القراءة الكاملة، المعاينة، التنزيل والطباعة.';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($pageTitle); ?></title>
<meta name="description" content="<?php echo e($pageDescription); ?>">
<link rel="stylesheet" href="assets/css/home.min.css?v=<?php echo filemtime(__DIR__ . '/assets/css/home.min.css'); ?>">
<link rel="stylesheet" href="assets/css/home-redesign.css?v=<?php echo filemtime(__DIR__ . '/assets/css/home-redesign.css'); ?>">
<style>
.sum-hero{background:linear-gradient(135deg,#3D5A28,#1f3417);color:#fff;padding:36px 24px;margin-bottom:32px;border-radius:0 0 var(--radius-lg) var(--radius-lg);}
.sum-hero-inner{max-width:1200px;margin:0 auto;}
.sum-hero h1{font-size:28px;font-weight:900;margin:0 0 10px;}
.sum-hero p{font-size:14px;line-height:1.7;opacity:.85;margin:0;max-width:680px;}
.sum-container{max-width:1200px;margin:0 auto;padding:0 24px 60px;}
.sum-section{margin-bottom:44px;}
.sum-section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--border);}
.sum-section-title{display:flex;align-items:center;gap:12px;font-size:20px;font-weight:800;color:var(--text);}
.sum-section-title .bar{width:5px;height:24px;border-radius:3px;}
.sum-section-archive{font-size:13px;color:var(--muted);text-decoration:none;font-weight:700;padding:6px 12px;border-radius:8px;}
.sum-section-archive:hover{background:var(--bg-3);color:var(--text);}
.sum-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:18px;}
.sum-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px;display:flex;flex-direction:column;gap:12px;box-shadow:var(--shadow-sm);transition:box-shadow .15s,transform .15s;}
.sum-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
.sum-card-meta{display:flex;align-items:center;gap:8px;font-size:11.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;}
.sum-card-meta .dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.sum-card-title{font-size:16px;font-weight:800;line-height:1.55;color:var(--text);margin:0;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}
.sum-card-sub{font-size:12.5px;color:var(--muted);}
.sum-card-actions{display:flex;flex-wrap:wrap;gap:6px;margin-top:auto;padding-top:12px;border-top:1px solid var(--bg-3);}
.sum-act{flex:1;min-width:0;display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:8px 10px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;cursor:pointer;border:1px solid var(--border);background:var(--surface);color:var(--text-2);transition:background .15s,color .15s,border-color .15s;font-family:inherit;}
.sum-act:hover{background:var(--accent);color:#fff;border-color:var(--accent);}
.sum-act-primary{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;border-color:var(--accent-2);}
.sum-act-primary:hover{filter:brightness(1.05);}
.sum-empty{padding:28px;text-align:center;color:var(--muted);font-size:13px;background:var(--bg-2);border:1px dashed var(--border);border-radius:var(--radius);}
@media (max-width:640px){
.sum-hero{padding:24px 16px;margin-bottom:22px;}
.sum-hero h1{font-size:22px;}
.sum-container{padding:0 16px 40px;}
.sum-grid{grid-template-columns:1fr;gap:14px;}
}
</style>
</head>
<body class="nf-redesign">
<?php
$activeType = 'summaries';
$showTicker = false;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="sum-hero">
  <div class="sum-hero-inner">
    <h1>☕ ملخصات نيوز فيد</h1>
    <p>كل الملخصات اليومية والأسبوعية في مكان واحد: موجز الصباح، مراجعة الأسبوع، وأبرز ما دار على تلغرام وX ويوتيوب. اضغط على <b>قراءة</b> لفتح الموجز كاملاً، أو حمّل نسخة PDF للقراءة لاحقاً.</p>
  </div>
</div>

<div class="sum-container">
  <?php foreach ($surfaces as $key => $surface):
    $rows = $briefings[$key] ?? [];
  ?>
    <section class="sum-section">
      <div class="sum-section-head">
        <div class="sum-section-title">
          <span class="bar" style="background:<?php echo e($surface['color']); ?>"></span>
          <?php echo e($surface['label']); ?>
        </div>
        <a class="sum-section-archive" href="<?php echo e($surface['archive_url']); ?>">عرض الأرشيف ›</a>
      </div>

      <?php if (empty($rows)): ?>
        <div class="sum-empty">لا توجد ملخصات منشورة في هذا القسم بعد.</div>
      <?php else: ?>
        <div class="sum-grid">
          <?php foreach ($rows as $row):
            $card = $surface['item']($row);
          ?>
            <article class="sum-card">
              <div class="sum-card-meta">
                <span class="dot" style="background:<?php echo e($surface['color']); ?>"></span>
                <?php echo e($surface['label']); ?>
              </div>
              <h3 class="sum-card-title"><?php echo e($card['title']); ?></h3>
              <?php if (!empty($card['subtitle'])): ?>
                <div class="sum-card-sub"><?php echo e($card['subtitle']); ?></div>
              <?php endif; ?>
              <div class="sum-card-actions">
                <a class="sum-act sum-act-primary" href="<?php echo e($card['url_read']); ?>">📖 قراءة</a>
                <a class="sum-act" href="<?php echo e($card['url_print']); ?>" target="_blank" rel="noopener" title="فتح صفحة الموجز للمعاينة">👁 معاينة</a>
                <a class="sum-act" href="<?php echo e($card['url_print']); ?>" target="_blank" rel="noopener" title="افتح ثم اختر &laquo;Save as PDF&raquo; من نافذة الطباعة">⬇ تنزيل</a>
                <a class="sum-act" href="<?php echo e($card['url_print']); ?>" target="_blank" rel="noopener" title="فتح صفحة الطباعة">🖨 طباعة</a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>

<footer>
  <div class="footer-bottom" style="max-width:1400px;margin:0 auto;padding:20px 24px;text-align:center;">
    <span>© <?php echo date('Y'); ?> نيوز فيد — جميع الحقوق محفوظة</span>
  </div>
</footer>
</body>
</html>
