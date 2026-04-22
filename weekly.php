<?php
/**
 * /weekly/            → latest weekly rewind
 * /weekly/YYYY-WW     → specific week
 * /weekly/archive     → list of past rewinds
 *
 * Magazine-style editorial presentation of the AI-curated
 * digest saved by cron_weekly_rewind.php. The page is
 * indexable + shareable — each week has a stable URL.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/weekly_rewind.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/seo.php';

// During the feature's first week we surface PHP errors so any blank
// 500 has a real message attached. Remove once the page has shipped
// at least one rewind cleanly.
ini_set('display_errors', '1');
error_reporting(E_ALL);

$pageTheme = current_theme();
$viewer    = current_user();
$viewerId  = (int)($viewer['id'] ?? 0);

$mode = 'single';
$yw   = (string)($_GET['week'] ?? '');

if ($yw === 'archive') {
    $mode = 'archive';
    $list = wr_list(50);
} elseif ($yw && preg_match('/^\d{4}-\d{1,2}$/', $yw)) {
    $rewind = wr_get_by_week($yw);
} else {
    $rewind = wr_get_latest();
}

if ($mode === 'single' && !empty($rewind['id'])) {
    wr_bump_views((int)$rewind['id']);
}

// Helpers -----------------------------------------------------------------
function wr_format_date_range(string $start, string $end): string {
    $months = [1=>'يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
    $s = strtotime($start); $e = strtotime($end);
    if ($s === false || $e === false) return '';
    $sd = (int)date('j', $s); $sm = (int)date('n', $s);
    $ed = (int)date('j', $e); $em = (int)date('n', $e); $ey = (int)date('Y', $e);
    if ($sm === $em) return "{$sd} – {$ed} " . ($months[$em] ?? '') . " {$ey}";
    return "{$sd} " . ($months[$sm] ?? '') . " – {$ed} " . ($months[$em] ?? '') . " {$ey}";
}

$pageUrl   = SITE_URL . '/weekly' . ($yw ? '/' . $yw : '');
$pageTitle = 'مراجعة الأسبوع — ' . e(getSetting('site_name', SITE_NAME));
$metaDesc  = 'مراجعة أسبوعية لأبرز الأحداث العربية والعالمية — قصص مختارة، أرقام لافتة، وما ينتظرنا الأسبوع القادم.';
if ($mode === 'single' && !empty($rewind)) {
    $metaDesc  = mb_substr((string)($rewind['cover_subtitle'] ?: $rewind['intro_text']), 0, 160);
    $pageTitle = e($rewind['cover_title']) . ' — مراجعة الأسبوع';
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title><?php echo $pageTitle; ?></title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<link rel="canonical" href="<?php echo e($pageUrl); ?>">
<meta property="og:title" content="<?php echo $pageTitle; ?>">
<meta property="og:description" content="<?php echo e($metaDesc); ?>">
<meta property="og:url" content="<?php echo e($pageUrl); ?>">
<meta property="og:type" content="article">
<?php if ($mode === 'single' && !empty($rewind['cover_image_url'])): ?>
<meta property="og:image" content="<?php echo e($rewind['cover_image_url']); ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<?php include __DIR__ . '/includes/components/pwa_head.php'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" media="print" onload="this.media='all'">
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<link rel="stylesheet" href="assets/css/user.min.css?v=m2" media="print" onload="this.media='all'">
<link rel="stylesheet" href="assets/css/weekly.css?v=1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
</head>
<body>

<?php
$activeType = 'weekly';
$showTicker = false;
include __DIR__ . '/includes/components/site_header.php';
?>

<?php if ($mode === 'archive'): ?>

<main class="wr-archive-wrap">
  <header class="wr-archive-head">
    <a href="/weekly" class="wr-back">← العودة لمراجعة الأسبوع</a>
    <h1>أرشيف مراجعات الأسبوع</h1>
    <p>كل ما نشرناه من مراجعات أسبوعية. انقر أي أسبوع للاطلاع على تغطيته.</p>
  </header>

  <?php if (!$list): ?>
    <div class="wr-empty">لا توجد مراجعات بعد. عد السبت القادم.</div>
  <?php else: ?>
    <div class="wr-archive-grid">
      <?php foreach ($list as $r): ?>
        <a class="wr-archive-card" href="/weekly/<?php echo e($r['year_week']); ?>">
          <?php if (!empty($r['cover_image_url'])): ?>
            <div class="wr-archive-img"><img src="<?php echo e($r['cover_image_url']); ?>" alt="" loading="lazy"></div>
          <?php else: ?>
            <div class="wr-archive-img wr-archive-img-placeholder">📰</div>
          <?php endif; ?>
          <div class="wr-archive-body">
            <div class="wr-archive-meta"><?php echo e(wr_format_date_range($r['start_date'], $r['end_date'])); ?></div>
            <h3><?php echo e($r['cover_title'] ?: 'مراجعة الأسبوع ' . $r['year_week']); ?></h3>
            <?php if (!empty($r['cover_subtitle'])): ?>
              <p><?php echo e($r['cover_subtitle']); ?></p>
            <?php endif; ?>
            <div class="wr-archive-stats">
              <?php $ns = count($r['content']['stories'] ?? []); ?>
              <span>📰 <?php echo $ns; ?> قصة</span>
              <span>👁 <?php echo number_format($r['view_count']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<?php elseif (empty($rewind)): ?>

<main class="wr-wrap wr-empty-state">
  <div class="wr-empty-card">
    <div class="wr-empty-ico">📰</div>
    <h1>لم تصدر مراجعة هذا الأسبوع بعد</h1>
    <p>نُصدر مراجعة الأسبوع صباح كل أحد. عد غداً أو استعرض المراجعات السابقة.</p>
    <a href="/weekly/archive" class="wr-btn-primary">استعرض الأرشيف ←</a>
    <a href="/" class="wr-btn-ghost">العودة للرئيسية</a>
  </div>
</main>

<?php else: ?>

<article class="wr-wrap" itemscope itemtype="https://schema.org/Article">
  <!-- HERO COVER -->
  <header class="wr-hero"<?php echo !empty($rewind['cover_image_url']) ? ' style="--wr-cover:url(' . e($rewind['cover_image_url']) . ')"' : ''; ?>>
    <div class="wr-hero-overlay"></div>
    <div class="wr-hero-content">
      <div class="wr-hero-kicker">
        <span class="wr-hero-badge">📅 مراجعة الأسبوع</span>
        <span class="wr-hero-date"><?php echo e(wr_format_date_range($rewind['start_date'], $rewind['end_date'])); ?></span>
      </div>
      <h1 class="wr-hero-title" itemprop="headline"><?php echo e($rewind['cover_title']); ?></h1>
      <?php if (!empty($rewind['cover_subtitle'])): ?>
        <p class="wr-hero-subtitle"><?php echo e($rewind['cover_subtitle']); ?></p>
      <?php endif; ?>
      <div class="wr-hero-stats">
        <?php $st = $rewind['stats'] ?: []; ?>
        <span>📰 <?php echo (int)($st['stories_picked'] ?? count($rewind['content']['stories'] ?? [])); ?> قصة</span>
        <?php if (!empty($st['candidates_reviewed'])): ?>
          <span>🔍 <?php echo (int)$st['candidates_reviewed']; ?> خبر مرشّح</span>
        <?php endif; ?>
        <?php if (!empty($st['articles_cited'])): ?>
          <span>📎 <?php echo (int)$st['articles_cited']; ?> مصدر</span>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <!-- INTRO -->
  <?php if (!empty($rewind['intro_text'])): ?>
    <section class="wr-intro">
      <div class="wr-intro-mark">—</div>
      <p><?php echo nl2br(e($rewind['intro_text'])); ?></p>
    </section>
  <?php endif; ?>

  <!-- NUMBERS STRIP -->
  <?php $numbers = $rewind['content']['numbers'] ?? []; ?>
  <?php if ($numbers): ?>
    <section class="wr-numbers">
      <h2 class="wr-section-h">أرقام هذا الأسبوع</h2>
      <div class="wr-numbers-grid">
        <?php foreach ($numbers as $n): ?>
          <div class="wr-number-card">
            <div class="wr-number-value"><?php echo e($n['value']); ?></div>
            <div class="wr-number-label"><?php echo e($n['label']); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- STORIES -->
  <section class="wr-stories">
    <h2 class="wr-section-h">القصص المختارة</h2>
    <?php foreach (($rewind['content']['stories'] ?? []) as $i => $story): ?>
      <article class="wr-story">
        <div class="wr-story-num">
          <span class="wr-story-ico"><?php echo e($story['icon'] ?? '📰'); ?></span>
          <span class="wr-story-idx"><?php echo str_pad((string)($i+1), 2, '0', STR_PAD_LEFT); ?></span>
        </div>
        <div class="wr-story-body">
          <?php if (!empty($story['category'])): ?>
            <div class="wr-story-cat"><?php echo e($story['category']); ?></div>
          <?php endif; ?>
          <h3 class="wr-story-headline"><?php echo e($story['headline']); ?></h3>
          <p class="wr-story-summary"><?php echo nl2br(e($story['summary'])); ?></p>
          <?php if (!empty($story['why_it_matters'])): ?>
            <div class="wr-story-why">
              <span class="wr-story-why-tag">لماذا يهم؟</span>
              <span><?php echo e($story['why_it_matters']); ?></span>
            </div>
          <?php endif; ?>
          <?php if (!empty($story['articles'])): ?>
            <div class="wr-story-sources">
              <div class="wr-story-sources-label">المصادر:</div>
              <ul>
                <?php foreach ($story['articles'] as $a): ?>
                  <li>
                    <a href="<?php echo e(articleUrl(['id' => $a['id'], 'slug' => $a['slug']])); ?>">
                      <span class="wr-src-name"><?php echo e($a['source_name'] ?: '—'); ?></span>
                      <span class="wr-src-title"><?php echo e($a['title']); ?></span>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <!-- WATCHING NEXT -->
  <?php $watching = $rewind['content']['watching_next'] ?? []; ?>
  <?php if ($watching): ?>
    <section class="wr-watching">
      <h2 class="wr-section-h">ما ننتظره الأسبوع القادم</h2>
      <ul class="wr-watching-list">
        <?php foreach ($watching as $w): ?>
          <li>
            <span class="wr-watching-ico"><?php echo e($w['icon'] ?? '📅'); ?></span>
            <div>
              <strong><?php echo e($w['title']); ?></strong>
              <?php if (!empty($w['note'])): ?>
                <span><?php echo e($w['note']); ?></span>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <!-- FOOTER: share + archive -->
  <footer class="wr-footer">
    <div class="wr-share">
      <span class="wr-share-label">شارك المراجعة:</span>
      <a class="wr-share-btn wr-share-wa" href="https://wa.me/?text=<?php echo urlencode($rewind['cover_title'] . ' — ' . $pageUrl); ?>" target="_blank" rel="noopener">واتساب</a>
      <a class="wr-share-btn wr-share-tw" href="https://twitter.com/intent/tweet?text=<?php echo urlencode($rewind['cover_title']); ?>&url=<?php echo urlencode($pageUrl); ?>" target="_blank" rel="noopener">X / تويتر</a>
      <button type="button" class="wr-share-btn wr-share-copy" onclick="navigator.clipboard.writeText('<?php echo e($pageUrl); ?>').then(()=>{this.textContent='نسخ ✓';setTimeout(()=>this.textContent='نسخ الرابط',1600);})">نسخ الرابط</button>
    </div>
    <a href="/weekly/archive" class="wr-archive-link">📚 جميع مراجعات الأسبوع ←</a>
  </footer>
</article>

<?php endif; ?>

</body>
</html>
