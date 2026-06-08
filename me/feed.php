<?php
$pageTitle = 'صحيفتي';
$pageSlug  = 'feed';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/user_source_ingest.php';

$userId    = (int)$me['id'];
$srcCount  = user_sources_count($userId, true);
$plat      = $_GET['p'] ?? '';
$validPlat = in_array($plat, ['rss', 'website', 'telegram', 'x', 'youtube'], true) ? $plat : null;
$items     = user_feed($userId, 48, null, $validPlat);
$counts    = user_feed_type_counts($userId);
$total     = array_sum($counts);
?>
<style>
.usf-sub { color:var(--muted); font-size:13px; margin-top:4px; }
.usf-chips { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; }
.usf-chip { display:inline-flex; align-items:center; gap:7px; padding:8px 14px; border-radius:999px; background:var(--surface); border:1px solid var(--border); font-size:13px; font-weight:700; color:var(--text-2,#4A4030); text-decoration:none; transition:border-color .15s; }
.usf-chip:hover { border-color:var(--accent); }
.usf-chip.on { background:var(--accent); border-color:var(--accent); color:#fff; }
.usf-chip .dot { width:8px; height:8px; border-radius:50%; }
.usf-chip .n { font-size:11px; opacity:.7; }
.usf-plat { display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:800; }
.usf-plat .dot { width:7px; height:7px; border-radius:50%; }
.usf-sum { font-size:12.5px; color:var(--muted); line-height:1.6; margin-top:6px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.usf-note { color:var(--muted); font-size:13px; padding:24px; text-align:center; }
.usf-empty { text-align:center; padding:50px 20px; }
.usf-empty .ico { font-size:46px; }
.usf-empty h3 { margin:14px 0 6px; font-size:18px; color:var(--text); }
.usf-empty p { color:var(--muted); font-size:14px; margin:0 0 16px; line-height:1.7; }
</style>

<div class="dash-topbar">
  <div>
    <h1>📰 صحيفتي</h1>
    <p class="usf-sub"><?= $srcCount ? ('خلاصتك من ' . $srcCount . ' مصدراً نشطاً — مجمّعة من مصادرك') : 'صحيفتك تُبنى من مصادرك الخاصة' ?></p>
  </div>
  <a href="sources.php" class="btn">📡 مصادري</a>
</div>

<?php if ($srcCount === 0): ?>
  <div class="panel-card"><div class="usf-empty">
    <div class="ico">📡</div>
    <h3>ابنِ صحيفتك</h3>
    <p>أضف مصادرك الخاصة (مواقع، RSS، تلغرام، إكس، يوتيوب) لتظهر أخبارها هنا — مجمّعة وملخّصة.</p>
    <a href="sources.php" class="btn primary">أضف مصادرك</a>
  </div></div>
<?php elseif (!$items && !$validPlat): ?>
  <div class="panel-card"><div class="usf-empty">
    <div class="ico">⏳</div>
    <h3>جارٍ تجهيز صحيفتك</h3>
    <p>أضفت مصادر — نجلب أحدث أخبارها الآن. حدّث الصفحة بعد قليل.</p>
    <a href="feed.php" class="btn">تحديث</a>
  </div></div>
<?php else: ?>
  <div class="usf-chips">
    <a class="usf-chip <?= !$validPlat ? 'on' : '' ?>" href="feed.php">الكل <span class="n"><?= (int)$total ?></span></a>
    <?php foreach (['telegram' => 'تلغرام', 'x' => 'إكس', 'youtube' => 'يوتيوب', 'rss' => 'RSS', 'website' => 'مواقع'] as $t => $lab):
      if (empty($counts[$t])) continue;
      $cm = user_source_meta($t);
    ?>
      <a class="usf-chip <?= $validPlat === $t ? 'on' : '' ?>" href="feed.php?p=<?= $t ?>"><span class="dot" style="background:<?= e($cm['color']) ?>"></span><?= e($lab) ?> <span class="n"><?= (int)$counts[$t] ?></span></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$items): ?>
    <div class="usf-note">لا عناصر لهذا الفلتر بعد.</div>
  <?php else: ?>
    <div class="u-grid">
      <?php foreach ($items as $a):
        $cm  = user_source_meta($a['source_type']);
        $img = $a['image_url'] ?: placeholderImage(400, 300);
      ?>
        <a class="u-card news-card" href="<?= e($a['url']) ?>" target="_blank" rel="noopener">
          <div class="u-img"><img src="<?= e($img) ?>" alt="<?= e($a['title']) ?>" loading="lazy" decoding="async"></div>
          <div class="u-body">
            <span class="usf-plat" style="color:<?= e($cm['color']) ?>"><span class="dot" style="background:<?= e($cm['color']) ?>"></span><?= e($cm['label']) ?> · <?= e($a['source_name']) ?></span>
            <div class="u-title"><?= e($a['title']) ?></div>
            <?php if (!empty($a['excerpt'])): ?><div class="usf-sum">✦ <?= e(mb_substr($a['excerpt'], 0, 160)) ?></div><?php endif; ?>
            <div class="u-meta"><span><?= timeAgo($a['published_at']) ?></span><span>↗ المصدر</span></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/_layout_foot.php'; ?>
