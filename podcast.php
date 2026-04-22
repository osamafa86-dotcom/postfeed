<?php
/**
 * /podcast            → latest episode + archive strip
 * /podcast/YYYY-MM-DD → specific episode
 *
 * Main player is the browser's native <audio> so it works with
 * Media Session API, background playback, and Bluetooth keys
 * out of the box. Chapter list on the right jumps to the right
 * second on click; transcript below the fold is the full
 * AI-written script (also serves as accessibility alt).
 *
 * Subscribe CTAs (Apple Podcasts / Spotify / RSS) point at
 * /podcast.xml (next chunk).
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/podcast.php';

$pageTheme = current_theme();
$viewer    = current_user();
$viewerId  = (int)($viewer['id'] ?? 0);

$dateParam = (string)($_GET['date'] ?? '');
$isArchive = $dateParam === 'archive';

if ($isArchive) {
    $episodes = pod_list_recent(60);
    $episode  = null;
} elseif ($dateParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $episode = pod_get_by_date($dateParam);
    if ($episode && $episode['audio_path']) pod_bump_plays($episode['id']);
    $episodes = pod_list_recent(10);
} else {
    $episode = pod_get_latest();
    if ($episode) pod_bump_plays($episode['id']);
    $episodes = pod_list_recent(10);
}

$pageUrl   = SITE_URL . '/podcast' . ($dateParam ? '/' . $dateParam : '');
$pageTitle = '📻 البودكاست اليومي — ' . e(getSetting('site_name', SITE_NAME));
$metaDesc  = 'موجز إخباري يومي بصوت احترافي، 5-8 دقائق تغطّي أهم ما جرى في آخر 24 ساعة.';
if (!$isArchive && $episode) {
    $pageTitle = e($episode['title']) . ' — بودكاست نيوز فيد';
    $metaDesc  = e($episode['subtitle'] ?: mb_substr($episode['intro'], 0, 160));
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
<meta property="og:type" content="website">
<?php include __DIR__ . '/includes/components/pwa_head.php'; ?>
<link rel="alternate" type="application/rss+xml" title="نيوز فيد — البودكاست اليومي" href="/podcast.xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" media="print" onload="this.media='all'">
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<link rel="stylesheet" href="assets/css/user.min.css?v=m2" media="print" onload="this.media='all'">
<link rel="stylesheet" href="assets/css/podcast.css?v=1">
</head>
<body>

<?php
$activeType = 'podcast';
$showTicker = false;
include __DIR__ . '/includes/components/site_header.php';
?>

<?php if ($isArchive): ?>

<main class="pod-archive-wrap">
  <header class="pod-archive-head">
    <a href="/podcast" class="pod-back">← العودة لأحدث حلقة</a>
    <h1>📻 جميع حلقات البودكاست</h1>
    <p>كل الحلقات السابقة، مرتّبة من الأحدث للأقدم.</p>
  </header>
  <?php if (!$episodes): ?>
    <div class="pod-empty">لا توجد حلقات بعد — الحلقة الأولى غداً صباحاً.</div>
  <?php else: ?>
    <div class="pod-archive-grid">
      <?php foreach ($episodes as $ep): ?>
        <a class="pod-archive-card" href="/podcast/<?php echo e($ep['episode_date']); ?>">
          <div class="pod-archive-ico">📻</div>
          <div class="pod-archive-body">
            <div class="pod-archive-date"><?php echo e($ep['episode_date']); ?></div>
            <h3><?php echo e($ep['title']); ?></h3>
            <?php if (!empty($ep['subtitle'])): ?>
              <p><?php echo e($ep['subtitle']); ?></p>
            <?php endif; ?>
            <div class="pod-archive-meta">
              ⏱ <?php echo floor($ep['duration_seconds'] / 60) . ':' . str_pad((string)($ep['duration_seconds'] % 60), 2, '0', STR_PAD_LEFT); ?>
              · ▶ <?php echo number_format($ep['play_count']); ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<?php elseif (!$episode): ?>

<main class="pod-wrap pod-empty-state">
  <div class="pod-empty-card">
    <div class="pod-empty-ico">📻</div>
    <h1>البودكاست لم يُصدر حلقته الأولى بعد</h1>
    <p>نُصدر حلقة جديدة كل صباح في السادسة. عد غداً أو استعرض الأرشيف.</p>
    <a href="/podcast/archive" class="pod-btn-primary">استعرض الأرشيف ←</a>
    <a href="/" class="pod-btn-ghost">العودة للرئيسية</a>
  </div>
</main>

<?php else: ?>

<article class="pod-wrap">
  <!-- HERO + PLAYER -->
  <header class="pod-hero">
    <div class="pod-hero-badge">📻 الحلقة اليوم</div>
    <h1 class="pod-hero-title"><?php echo e($episode['title']); ?></h1>
    <?php if (!empty($episode['subtitle'])): ?>
      <p class="pod-hero-subtitle"><?php echo e($episode['subtitle']); ?></p>
    <?php endif; ?>
    <div class="pod-hero-meta">
      <span>⏱ <?php echo floor($episode['duration_seconds'] / 60) . ':' . str_pad((string)($episode['duration_seconds'] % 60), 2, '0', STR_PAD_LEFT); ?></span>
      <span>📅 <?php echo e($episode['episode_date']); ?></span>
      <?php if (!empty($episode['chapters'])): ?>
        <span>📚 <?php echo count($episode['chapters']); ?> أقسام</span>
      <?php endif; ?>
      <?php if (!empty($episode['tts_provider'])): ?>
        <span>🗣 <?php echo e($episode['tts_provider']); ?></span>
      <?php endif; ?>
    </div>

    <?php if (!empty($episode['audio_url'])): ?>
      <audio id="podcastAudio" preload="metadata" controls src="<?php echo e($episode['audio_url']); ?>"></audio>
      <div class="pod-speed-row">
        <span>السرعة:</span>
        <button type="button" class="pod-speed" data-rate="0.85">0.85×</button>
        <button type="button" class="pod-speed active" data-rate="1">1×</button>
        <button type="button" class="pod-speed" data-rate="1.25">1.25×</button>
        <button type="button" class="pod-speed" data-rate="1.5">1.5×</button>
        <button type="button" class="pod-speed" data-rate="2">2×</button>
      </div>
    <?php else: ?>
      <div class="pod-no-audio">
        <div>⚠ الملف الصوتي لم يُولَّد لهذه الحلقة بعد — يمكنك قراءة النص المكتوب أدناه.</div>
      </div>
    <?php endif; ?>
  </header>

  <!-- CONTENT: Chapters + Transcript -->
  <div class="pod-content">
    <!-- CHAPTERS -->
    <?php if (!empty($episode['chapters'])): ?>
      <aside class="pod-chapters">
        <h2 class="pod-section-h">الأقسام</h2>
        <ol>
          <?php foreach ($episode['chapters'] as $i => $ch): ?>
            <li>
              <button type="button" class="pod-chapter" data-time="<?php echo (int)$ch['time']; ?>">
                <span class="pod-chapter-time"><?php echo floor($ch['time'] / 60) . ':' . str_pad((string)($ch['time'] % 60), 2, '0', STR_PAD_LEFT); ?></span>
                <span class="pod-chapter-title"><?php echo e($ch['title']); ?></span>
              </button>
            </li>
          <?php endforeach; ?>
        </ol>
      </aside>
    <?php endif; ?>

    <!-- TRANSCRIPT + INTRO -->
    <section class="pod-transcript">
      <?php if (!empty($episode['intro'])): ?>
        <div class="pod-intro">"<?php echo e($episode['intro']); ?>"</div>
      <?php endif; ?>
      <h2 class="pod-section-h">نص الحلقة الكامل</h2>
      <div class="pod-script">
        <?php echo nl2br(e($episode['script_text'])); ?>
      </div>
    </section>
  </div>

  <!-- SUBSCRIBE CTA -->
  <footer class="pod-subscribe">
    <h2 class="pod-section-h">استمع على منصّتك المفضّلة</h2>
    <div class="pod-sub-row">
      <a class="pod-sub pod-sub-apple" href="https://podcasts.apple.com/search?term=<?php echo urlencode(getSetting('site_name', SITE_NAME)); ?>" target="_blank" rel="noopener">
        <span class="pod-sub-ico">🎧</span>
        <div><span class="pod-sub-lbl">استمع على</span><strong>Apple Podcasts</strong></div>
      </a>
      <a class="pod-sub pod-sub-spotify" href="https://open.spotify.com/search/<?php echo rawurlencode(getSetting('site_name', SITE_NAME)); ?>" target="_blank" rel="noopener">
        <span class="pod-sub-ico">🎧</span>
        <div><span class="pod-sub-lbl">استمع على</span><strong>Spotify</strong></div>
      </a>
      <a class="pod-sub pod-sub-rss" href="/podcast.xml" target="_blank">
        <span class="pod-sub-ico">📡</span>
        <div><span class="pod-sub-lbl">اشترك عبر</span><strong>RSS</strong></div>
      </a>
      <a class="pod-sub pod-sub-archive" href="/podcast/archive">
        <span class="pod-sub-ico">📚</span>
        <div><span class="pod-sub-lbl">جميع الحلقات</span><strong>الأرشيف</strong></div>
      </a>
    </div>
  </footer>
</article>

<script>
(function() {
  var audio = document.getElementById('podcastAudio');
  if (!audio) return;

  // Speed controls
  document.querySelectorAll('.pod-speed').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('.pod-speed').forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      audio.playbackRate = parseFloat(btn.getAttribute('data-rate'));
    });
  });

  // Chapter jump
  document.querySelectorAll('.pod-chapter').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var t = parseInt(btn.getAttribute('data-time'), 10) || 0;
      audio.currentTime = t;
      audio.play();
    });
  });

  // Media Session API for lock-screen controls
  if ('mediaSession' in navigator) {
    try {
      navigator.mediaSession.metadata = new MediaMetadata({
        title:  <?php echo json_encode($episode['title'], JSON_UNESCAPED_UNICODE); ?>,
        artist: 'نيوز فيد',
        album:  'البودكاست اليومي',
        artwork: [
          { src: '/icon.php?size=512', sizes: '512x512', type: 'image/png' },
          { src: '/icon.php?size=192', sizes: '192x192', type: 'image/png' },
        ],
      });
      navigator.mediaSession.setActionHandler('play',  function() { audio.play(); });
      navigator.mediaSession.setActionHandler('pause', function() { audio.pause(); });
      navigator.mediaSession.setActionHandler('seekbackward',  function(d) { audio.currentTime = Math.max(0, audio.currentTime - (d.seekOffset || 10)); });
      navigator.mediaSession.setActionHandler('seekforward',   function(d) { audio.currentTime = Math.min(audio.duration || Infinity, audio.currentTime + (d.seekOffset || 10)); });
    } catch (e) {}
  }
})();
</script>

<?php endif; ?>

</body>
</html>
