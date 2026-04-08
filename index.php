<?php
/**
 * نيوزفلو - الصفحة الرئيسية
 * موقع تجميع الأخبار من مصادر متعددة
 */

require_once __DIR__ . '/includes/functions.php';

// جلب البيانات من قاعدة البيانات
$heroArticles = getHeroArticles();
$palestineNews = getPalestineNews(5);
$breakingNews = getBreakingNews();
$latestArticles = getLatestArticles(12);
$categories = getCategories();
$notifications = getNotifications(6);
$unreadCount = getUnreadNotifCount();
$poll = getActivePoll();
$trends = getTrends();
$sources = getActiveSources();
$mostRead = getMostRead();
$mediaItems = getMediaItems(4);
$tickerItems = getTickerItems();

// إحصائيات
$totalArticles = countArticles();
$totalSources = count($sources);

// جلب أخبار التصنيفات (نطلب عدد أكبر لتعويض ما يتم حذفه عند منع التكرار)
$politicalNews = getArticlesByCategory('political', 40);
$economyNews   = getArticlesByCategory('economy', 40);
$sportsNews    = getArticlesByCategory('sports', 40);
$artsNews      = getArticlesByCategory('arts', 40);
$reportsNews   = getArticlesByCategory('reports', 40);

// منع تكرار الخبر عبر أكثر من قسم في الصفحة الرئيسية
$usedIds = [];
$dedup = function(array $list, int $keep) use (&$usedIds): array {
    $out = [];
    foreach ($list as $a) {
        $id = (int)($a['id'] ?? 0);
        if ($id && isset($usedIds[$id])) continue;
        $usedIds[$id] = true;
        $out[] = $a;
        if (count($out) >= $keep) break;
    }
    return $out;
};
$breakingNews   = $dedup($breakingNews, 5);
$latestArticles = $dedup($latestArticles, 6);
$politicalNews  = $dedup($politicalNews, 4);
$economyNews    = $dedup($economyNews, 4);
$sportsNews     = $dedup($sportsNews, 4);
$artsNews       = $dedup($artsNews, 4);
$reportsNews    = $dedup($reportsNews, 4);

// جلب الريلز للعرض في الصفحة الرئيسية
$homeReels = [];
try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT r.*, s.username, s.display_name, s.avatar_url
                         FROM reels r
                         LEFT JOIN reels_sources s ON r.source_id = s.id
                         WHERE r.is_active = 1
                         ORDER BY r.sort_order DESC, r.created_at DESC
                         LIMIT 8");
    $homeReels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    $homeReels = [];
}

?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e(getSetting('site_name', SITE_NAME)); ?> - <?php echo e(getSetting('site_tagline', SITE_TAGLINE)); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<meta name="description" content="مجمع الأخبار العربية الأول - أحدث الأخبار من مصادر موثوقة في السياسة، الاقتصاد، الرياضة، والتكنولوجيا">
<link rel="stylesheet" href="assets/css/home.css?v=1">
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <div class="topbar-left">
    <span><span class="live-dot"></span> مباشر الآن</span>
    <span><?php echo date('l, j F Y'); ?></span>
    <span id="liveTime"><?php echo date('h:i A'); ?></span>
  </div>
  <div class="topbar-right">
    <span class="weather-badge" id="topWeather">☀ القدس --°</span>
    <span>USD: 0.71 JD</span>
    <span>EUR: 0.78 JD</span>
  </div>
</div>

<!-- HEADER -->
<header>
  <a class="logo" href="index.php">
    <div class="logo-icon">N</div>
    <div>
      <h1 class="logo-text" style="font:inherit;margin:0;"><?php echo e(getSetting('site_name', SITE_NAME)); ?></h1>
      <div class="logo-sub"><?php echo e(getSetting('site_tagline', SITE_TAGLINE)); ?></div>
    </div>
  </a>

  <nav>
    <a href="category.php?type=breaking" class="breaking">🔴 عاجل</a>
    <a href="index.php" class="active">الرئيسية</a>
    <a href="category.php?type=latest">آخر الأخبار</a>
    <a href="category/political">سياسة</a>
    <a href="category/economy">اقتصاد</a>
    <a href="category/sports">رياضة</a>
    <a href="category/arts">فنون</a>
    <a href="category/media">ميديا</a>
    <a href="category/reports">تقارير</a>
    <a href="reels.php">🎬 ريلز</a>
  </nav>

  <div class="header-actions">
    <button class="menu-toggle" onclick="toggleMobileNav()" aria-label="القائمة">☰</button>
    <div class="search-box">
      <span class="search-icon">&#x1F50D;</span>
      <input type="text" placeholder="ابحث عن خبر...">
    </div>
    <div class="icon-btn" onclick="toggleNotif()">
      🔔
      <span class="notif-badge"><?php echo e($unreadCount); ?></span>
    </div>
    <div class="icon-btn" onclick="openAddSource()">➕</div>
    <div class="avatar" onclick="openUserPanel()">أ</div>
  </div>
</header>

<!-- MOBILE NAV -->
<div class="mobile-nav-overlay" onclick="toggleMobileNav()"></div>
<nav class="mobile-nav" id="mobileNav">
  <button class="mobile-nav-close" onclick="toggleMobileNav()">×</button>
  <a href="index.php" class="active">🏠 الرئيسية</a>
  <a href="category.php?type=breaking">🔴 عاجل</a>
  <a href="category.php?type=latest">⏱ آخر الأخبار</a>
  <a href="category/political">🏛 سياسة</a>
  <a href="category/economy">💹 اقتصاد</a>
  <a href="category/sports">⚽ رياضة</a>
  <a href="category/arts">🎨 فنون</a>
  <a href="category/media">🎥 ميديا</a>
  <a href="category/reports">📊 تقارير</a>
  <a href="reels.php">🎬 ريلز</a>
</nav>

<!-- SECTIONS NAV -->
<div class="sections-nav">
  <div class="sec-btn active" onclick="filterSection(this,'all')">📰 الكل</div>
  <div class="sec-btn" onclick="filterSection(this,'breaking')">🔴 عاجل</div>
  <div class="sec-btn" onclick="filterSection(this,'latest')">⏱ آخر الأخبار</div>
  <div class="sec-btn" onclick="filterSection(this,'political')">🏛 سياسة</div>
  <div class="sec-btn" onclick="filterSection(this,'economy')">💹 اقتصاد</div>
  <div class="sec-btn" onclick="filterSection(this,'sports')">⚽ رياضة</div>
  <div class="sec-btn" onclick="filterSection(this,'arts')">🎨 فنون</div>
  <div class="sec-btn" onclick="filterSection(this,'media')">🎥 ميديا</div>
  <div class="sec-btn" onclick="filterSection(this,'reports')">📊 تقارير</div>
</div>

<!-- TICKER -->
<div class="ticker-wrap">
  <div class="ticker-label">عاجل</div>
  <div class="ticker-content">
    <?php foreach ($tickerItems as $item): ?>
      <div class="ticker-item"><?php echo e($item['text']); ?></div>
    <?php endforeach; ?>
    <?php foreach ($tickerItems as $item): ?>
      <div class="ticker-item"><?php echo e($item['text']); ?></div>
    <?php endforeach; ?>
  </div>
</div>

<!-- STATS BAR -->
<div class="stats-bar">
  <div class="stat-item">
    <span class="stat-icon">📰</span>
    <div>
      <div class="stat-val"><?php echo number_format($totalArticles); ?></div>
      <div class="stat-label">خبر</div>
    </div>
  </div>
  <div class="stat-item">
    <span class="stat-icon">🌐</span>
    <div>
      <div class="stat-val"><?php echo $totalSources; ?></div>
      <div class="stat-label">مصدر نشط</div>
    </div>
  </div>
  <div class="stat-item">
    <span class="stat-icon">👁</span>
    <div>
      <div class="stat-val">3.2M</div>
      <div class="stat-label">مشاهدة اليوم</div>
    </div>
  </div>
  <div class="stat-item">
    <span class="stat-icon">🔥</span>
    <div>
      <div class="stat-val">سياسة</div>
      <div class="stat-label">الأكثر تداولاً</div>
    </div>
  </div>
  <div class="stat-item">
    <span class="stat-icon">⏱</span>
    <div>
      <div class="stat-val">منذ 2 دق</div>
      <div class="stat-label">آخر تحديث</div>
    </div>
  </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-layout">
  <div class="main-col">

    <!-- PALESTINE NEWS -->
    <div class="section-header">
      <div class="section-title"><div class="line" style="background:#16a34a"></div>🇵🇸 أحدث الأخبار الفلسطينية</div>
    </div>
    <?php if (!empty($palestineNews)): ?>
      <?php $psFirst = $palestineNews[0]; ?>
      <a class="ps-hero" href="<?php echo articleUrl($psFirst); ?>">
        <div class="ps-hero-text">
          <h3><?php echo e($psFirst['title']); ?></h3>
          <div class="ps-hero-excerpt"><?php echo e(mb_substr(strip_tags($psFirst['content'] ?? $psFirst['excerpt'] ?? ''), 0, 200)); ?></div>
          <div class="ps-hero-meta">
            <span class="source-icon"><?php echo e(mb_substr($psFirst['source_name'], 0, 1)); ?></span>
            <div class="meta-text">
              <span><?php echo e($psFirst['source_name']); ?></span>
              <span class="meta-dot"></span>
              <span><?php echo timeAgo($psFirst['published_at']); ?></span>
            </div>
          </div>
        </div>
        <div class="ps-hero-img">
          <img src="<?php echo e($psFirst['image_url'] ?? 'https://picsum.photos/seed/ps0/800/500'); ?>" alt="<?php echo e($psFirst['title'] ?? ''); ?>" loading="lazy" decoding="async">
        </div>
      </a>

      <div class="palestine-grid">
        <?php for ($pIdx = 1; $pIdx < count($palestineNews); $pIdx++): $article = $palestineNews[$pIdx]; ?>
          <a class="ps-card" href="<?php echo articleUrl($article); ?>">
            <div class="img-wrap">
              <img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/ps' . $pIdx . '/400/300'); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async">
              <div class="img-date"><?php echo timeAgo($article['published_at']); ?></div>
            </div>
            <div class="ps-card-body">
              <h3><?php echo e($article['title']); ?></h3>
              <div class="ps-card-footer">
                <span class="source-dot"><?php echo e(mb_substr($article['source_name'], 0, 1)); ?></span>
                <span><?php echo e($article['source_name']); ?></span>
              </div>
            </div>
          </a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

    <!-- BREAKING NEWS -->
    <div id="breaking" class="section-header">
      <div class="section-title"><div class="line" style="background:var(--red)"></div>🔴 أخبار عاجلة</div>
      <a class="see-all" href="category.php?type=breaking">عرض الكل ›</a>
    </div>
    <div class="news-list" style="margin-bottom:28px">
      <?php foreach ($breakingNews as $article): ?>
        <a class="list-item" href="<?php echo articleUrl($article); ?>">
          <div class="list-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/brk' . rand(1,10) . '/200/150'); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
          <div class="list-body">
            <div class="card-cat cat-breaking">عاجل</div>
            <div class="list-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt" style="margin-bottom:6px"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 120)); ?></div>
            <div class="list-meta"><span>🌐 <?php echo e($article['source_name']); ?></span><span>·</span><span><?php echo timeAgo($article['published_at']); ?></span><span>·</span><span>👁 <?php echo formatViews($article['view_count']); ?></span></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php
    // Telegram breaking messages — read only (sync moved to cron_telegram.php)
    $tgMsgs = [];
    try {
        $tgDb = getDB();
        $tgMsgs = $tgDb->query("SELECT m.*, s.display_name, s.username, s.avatar_url FROM telegram_messages m JOIN telegram_sources s ON m.source_id = s.id WHERE m.is_active=1 AND s.is_active=1 ORDER BY m.posted_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { error_log('tg read: ' . $e->getMessage()); }
    ?>
    <?php if (!empty($tgMsgs)): ?>
    <div class="tg-breaking" style="margin-bottom:28px">
      <?php foreach ($tgMsgs as $m): ?>
        <a href="<?php echo e($m['post_url']); ?>" target="_blank" class="tg-card">
          <?php if (!empty($m['image_url'])): ?>
            <div class="tg-img"><img src="<?php echo e($m['image_url']); ?>" alt="<?php echo e($m['text'] ?? ''); ?>" loading="lazy" decoding="async"></div>
          <?php endif; ?>
          <div class="tg-body">
            <div class="tg-source">
              <span class="tg-badge">📢 تيليغرام</span>
              <strong>@<?php echo e($m['username']); ?></strong>
              <span class="tg-time"><?php echo timeAgo($m['posted_at']); ?></span>
            </div>
            <div class="tg-text"><?php echo nl2br(e(mb_substr($m['text'], 0, 280))); ?><?php echo mb_strlen($m['text'])>280?'...':''; ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- LATEST NEWS -->
    <div id="latest" class="section-header">
      <div class="section-title blue"><div class="line"></div>⏱ آخر الأخبار</div>
      <a class="see-all" href="category.php?type=latest">عرض الكل ›</a>
    </div>
    <div class="news-grid" style="margin-bottom:28px">
      <?php foreach ($latestArticles as $article): ?>
        <a class="news-card" href="<?php echo articleUrl($article); ?>">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/lat' . rand(1,10) . '/400/300'); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
          <div class="card-body">
            <span class="card-cat <?php echo $article['css_class'] ?? 'cat-political'; ?>"><?php echo e($article['cat_name']); ?></span>
            <div class="card-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- POLITICAL NEWS -->
    <div id="political" class="section-header">
      <div class="section-title"><div class="line" style="background:#b05a5a"></div>🏛 أخبار سياسية</div>
      <a class="see-all" href="category/political">عرض الكل ›</a>
    </div>
    <div class="news-rows" style="margin-bottom:28px">
      <?php foreach ($politicalNews as $article): ?>
        <a class="news-card" href="<?php echo articleUrl($article); ?>">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/pol' . rand(1,10) . '/400/300'); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
          <div class="card-body">
            <span class="card-cat cat-political">سياسة</span>
            <div class="card-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- ECONOMY -->
    <div id="economy" class="section-header">
      <div class="section-title green"><div class="line"></div>💹 أخبار اقتصادية</div>
      <a class="see-all" href="category/economy">عرض الكل ›</a>
    </div>
    <div class="news-rows" style="margin-bottom:28px">
      <?php foreach ($economyNews as $article): ?>
        <a class="news-card" href="<?php echo articleUrl($article); ?>">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/eco' . rand(1,10) . '/400/300'); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
          <div class="card-body">
            <span class="card-cat cat-economic">اقتصاد</span>
            <div class="card-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#85c1a3'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- SPORTS -->
    <div id="sports" class="section-header">
      <div class="section-title"><div class="line" style="background:#5a85b0"></div>⚽ رياضة</div>
      <a class="see-all" href="category/sports">عرض الكل ›</a>
    </div>
    <div class="news-rows" style="margin-bottom:28px">
      <?php foreach ($sportsNews as $article): ?>
        <a class="news-card" href="<?php echo articleUrl($article); ?>">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/sp' . rand(1,10) . '/400/300'); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
          <div class="card-body">
            <span class="card-cat cat-sports">رياضة</span>
            <div class="card-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- ARTS -->
    <div id="arts" class="section-header">
      <div class="section-title"><div class="line" style="background:#7a5a9a"></div>🎨 فنون وثقافة</div>
      <a class="see-all" href="category/arts">عرض الكل ›</a>
    </div>
    <div class="news-rows" style="margin-bottom:28px">
      <?php foreach ($artsNews as $article): ?>
        <a class="news-card" href="<?php echo articleUrl($article); ?>">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/art' . rand(1,10) . '/400/300'); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
          <div class="card-body">
            <span class="card-cat cat-arts">فنون</span>
            <div class="card-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#a08cc8'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- MEDIA SECTION (Video reels style) -->
    <div id="media" class="section-header">
      <div class="section-title"><div class="line" style="background:#1a73e8"></div>🎥 الأخبار بالفيديو</div>
      <a class="see-all" href="category/media">عرض الكل ›</a>
    </div>
    <div class="video-reels">
      <?php foreach ($mediaItems as $i => $media): ?>
        <a class="vreel" href="<?php echo articleUrl($media); ?>">
          <div class="vreel-thumb">
            <img src="<?php echo e($media['image_url'] ?? 'https://picsum.photos/seed/vid' . $i . '/400/600'); ?>" alt="<?php echo e($media['title'] ?? ''); ?>" loading="lazy" decoding="async">
            <div class="vreel-play">▶</div>
          </div>
          <div class="vreel-title"><?php echo e($media['title']); ?></div>
          <div class="vreel-meta">▶ 1 دق</div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- REPORTS -->
    <div id="reports" class="section-header">
      <div class="section-title gold"><div class="line"></div>📊 التقارير</div>
      <a class="see-all" href="category/reports">عرض الكل ›</a>
    </div>
    <div class="news-rows" style="margin-bottom:28px">
      <?php foreach ($reportsNews as $article): ?>
        <a class="news-card" href="<?php echo articleUrl($article); ?>">
          <div class="card-img"><img src="<?php echo e($article['image_url'] ?? 'https://picsum.photos/seed/rep' . rand(1,10) . '/400/300'); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
          <div class="card-body">
            <span class="card-cat cat-reports">تقرير</span>
            <div class="card-title"><?php echo e($article['title']); ?></div>
            <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
            <div class="card-meta">
              <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#c9ab6e'); ?>"></span><?php echo e($article['source_name']); ?></div>
              <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- REELS -->
    <?php if (!empty($homeReels)): ?>
    <div id="reels" class="reels-wrap">
      <div class="section-header">
        <div class="section-title"><div class="line" style="background:#fd1d1d"></div>🎬 ريلز</div>
        <a class="see-all" href="reels.php">عرض الكل ›</a>
      </div>
      <div class="reels-scroll">
        <?php foreach ($homeReels as $reel): ?>
          <div class="reel-card" style="width:300px!important;min-width:300px!important;max-width:300px!important;height:533px!important;min-height:533px!important;max-height:533px!important;overflow:hidden!important;position:relative!important;background:#000;border-radius:18px;flex:0 0 300px;align-self:flex-start;" title="<?php echo e($reel['caption'] ?? ''); ?>">
            <iframe style="position:absolute!important;top:-54px!important;left:0!important;width:300px!important;height:800px!important;border:0!important;" src="https://www.instagram.com/reel/<?php echo e($reel['shortcode']); ?>/embed/" scrolling="no" allowtransparency="true" allow="autoplay; encrypted-media" allowfullscreen loading="lazy" decoding="async"></iframe>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /main-col -->

  <!-- SIDEBAR -->
  <div class="sidebar">

    <!-- WEATHER -->
    <div class="weather-widget">
      <div class="section-title" style="margin-bottom:14px;font-size:14px"><div class="line" style="background:var(--accent2)"></div>☀️ الطقس الآن</div>
      <div class="weather-cities">
        <button class="weather-city-btn active" data-city="Jerusalem" data-name="القدس">القدس</button>
        <button class="weather-city-btn" data-city="Gaza" data-name="غزة">غزة</button>
        <button class="weather-city-btn" data-city="Ramallah" data-name="رام الله">رام الله</button>
        <button class="weather-city-btn" data-city="Nablus" data-name="نابلس">نابلس</button>
        <button class="weather-city-btn" data-city="Hebron" data-name="الخليل">الخليل</button>
        <button class="weather-city-btn" data-city="Jenin" data-name="جنين">جنين</button>
      </div>
      <div class="weather-main">
        <div>
          <div class="weather-temp" id="wTemp">--°</div>
          <div class="weather-city" id="wCity">القدس، فلسطين</div>
          <div class="weather-desc" id="wDesc">جارٍ التحميل...</div>
        </div>
        <div class="weather-icon" id="wIcon">🌤</div>
      </div>
      <div class="weather-days" id="wForecast">
        <div class="weather-day"><div class="day">--</div><div>🌤</div><div class="temp">--°</div></div>
        <div class="weather-day"><div class="day">--</div><div>🌤</div><div class="temp">--°</div></div>
        <div class="weather-day"><div class="day">--</div><div>🌤</div><div class="temp">--°</div></div>
        <div class="weather-day"><div class="day">--</div><div>🌤</div><div class="temp">--°</div></div>
      </div>
    </div>

    <!-- CURRENCY -->
    <div class="currency-widget" onclick="openCurrencyModal()">
      <h4>💱 أسعار الصرف</h4>
      <div class="currency-row">
        <div style="display:flex;align-items:center"><span class="currency-flag">🇺🇸</span><span class="currency-name">دولار أمريكي</span></div>
        <div><span class="currency-rate" id="cUSD">--</span> <span class="currency-change" id="cUSDc"></span></div>
      </div>
      <div class="currency-row">
        <div style="display:flex;align-items:center"><span class="currency-flag">🇮🇱</span><span class="currency-name">شيقل</span></div>
        <div><span class="currency-rate" id="cILS">--</span> <span class="currency-change" id="cILSc"></span></div>
      </div>
      <div class="currency-row">
        <div style="display:flex;align-items:center"><span class="currency-flag">🇯🇴</span><span class="currency-name">دينار أردني</span></div>
        <div><span class="currency-rate" id="cJOD">--</span> <span class="currency-change" id="cJODc"></span></div>
      </div>
      <div class="currency-foot">اضغط لعرض التفاصيل ›</div>
    </div>

    <!-- TRENDING -->
    <div class="sidebar-widget">
      <div class="widget-header"><span class="icon">🔥</span>الأكثر تداولاً</div>
      <div class="widget-body" style="padding:8px 16px">
        <?php $trendNum = 1; ?>
        <?php foreach ($trends as $trend): ?>
          <div class="trend-item">
            <div class="trend-num"><?php echo $trendNum; ?></div>
            <div>
              <div class="trend-title"><?php echo e($trend['title']); ?></div>
              <div class="trend-heat">🔥 <?php echo number_format($trend['tweet_count']); ?> تغريدة</div>
            </div>
          </div>
          <?php $trendNum++; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- SOURCES -->
    <div class="sidebar-widget">
      <div class="widget-header"><span class="icon">🌐</span>مصادرك النشطة</div>
      <div class="widget-body" style="padding:6px 16px">
        <?php foreach (array_slice($sources, 0, 5) as $source): ?>
          <div class="source-item">
            <div class="source-logo" style="background:<?php echo e($source['logo_bg']); ?>;color:<?php echo e($source['logo_color']); ?>"><?php echo e($source['logo_letter']); ?></div>
            <div class="source-info">
              <div class="source-name"><?php echo e($source['name']); ?></div>
              <div class="source-count"><?php echo rand(50, 300); ?> خبر اليوم</div>
            </div>
            <div class="source-toggle" onclick="this.classList.toggle('off')"></div>
          </div>
        <?php endforeach; ?>
        <div class="add-source-card" style="margin-top:12px;margin-bottom:0;padding:16px" onclick="openAddSource()">
          <div class="add-source-icon">➕</div>
          <div class="add-source-text" style="font-size:13px">إضافة مصدر جديد</div>
        </div>
      </div>
    </div>

    <!-- POLL -->
    <?php if ($poll): ?>
      <div class="sidebar-widget">
        <div class="widget-header"><span class="icon">📊</span>استطلاع الرأي</div>
        <div class="widget-body">
          <div style="font-size:13px;font-weight:600;margin-bottom:14px;line-height:1.5"><?php echo e($poll['question']); ?></div>
          <?php $totalVotes = array_sum(array_column($poll['options'], 'votes')); ?>
          <?php foreach ($poll['options'] as $option): ?>
            <?php $percentage = $totalVotes > 0 ? round(($option['votes'] / $totalVotes) * 100) : 0; ?>
            <div class="poll-option">
              <div class="poll-label"><span><?php echo e($option['text']); ?></span><span style="color:var(--accent);font-weight:700"><?php echo $percentage; ?>%</span></div>
              <div class="poll-bar"><div class="poll-fill" style="width:<?php echo $percentage; ?>%;background:var(--accent)"></div></div>
            </div>
          <?php endforeach; ?>
          <div style="font-size:11px;color:var(--muted);margin-top:10px;text-align:center"><?php echo number_format($totalVotes); ?> مصوّت · ينتهي خلال 2 يوم</div>
        </div>
      </div>
    <?php endif; ?>

    <!-- MOST READ -->
    <div class="sidebar-widget">
      <div class="widget-header"><span class="icon">👁</span>الأكثر قراءة</div>
      <div class="widget-body" style="padding:8px 16px">
        <?php $rankNum = 1; ?>
        <?php foreach (array_slice($mostRead, 0, 3) as $article): ?>
          <a class="list-item" href="<?php echo articleUrl($article); ?>" style="padding:8px 0;background:none;border:none;<?php echo $rankNum < 3 ? 'border-bottom:1px solid var(--border);' : ''; ?>">
            <div class="rank-num"><?php echo $rankNum; ?></div>
            <div class="list-body">
              <div class="list-title" style="font-size:12px"><?php echo e(substr($article['title'], 0, 40) . '...'); ?></div>
              <div class="list-meta"><span>👁 <?php echo formatViews($article['view_count']); ?></span></div>
            </div>
          </a>
          <?php $rankNum++; ?>
        <?php endforeach; ?>
      </div>
    </div>

  </div><!-- /sidebar -->
</div><!-- /main-layout -->

<!-- FOOTER -->
<footer>
  <div class="footer-inner">
    <div class="footer-brand">
      <div class="footer-logo"><?php echo e(getSetting('site_name', SITE_NAME)); ?></div>
      <p class="footer-desc">منصتك الشاملة لتجميع الأخبار من مصادر متعددة وموثوقة. نوفر لك تجربة إخبارية متكاملة بأحدث التقنيات.</p>
    </div>
    <div class="footer-col">
      <div class="footer-col-title">الأقسام</div>
      <a href="category/political">سياسة</a>
      <a href="category/economy">اقتصاد</a>
      <a href="category/sports">رياضة</a>
      <a href="category/arts">فنون وثقافة</a>
      <a href="category/tech">تكنولوجيا</a>
    </div>
    <div class="footer-col">
      <div class="footer-col-title">المزيد</div>
      <a href="category/reports">تقارير</a>
      <a href="category/media">ميديا</a>
      <a href="category.php?type=breaking">أخبار عاجلة</a>
      <a href="category.php?type=latest">آخر الأخبار</a>
    </div>
    <div class="footer-col">
      <div class="footer-col-title">روابط مهمة</div>
      <a href="#">من نحن</a>
      <a href="#">سياسة الخصوصية</a>
      <a href="#">الشروط والأحكام</a>
      <a href="#">اتصل بنا</a>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo e(getSetting('site_name', SITE_NAME)); ?> &mdash; جميع الحقوق محفوظة</div>
    <div class="footer-social">
      <a href="#" title="Twitter">&#x1D54F;</a>
      <a href="#" title="Facebook">f</a>
      <a href="#" title="Instagram">&#x1D540;</a>
      <a href="#" title="YouTube">&#x25B6;</a>
    </div>
  </div>
</footer>

<!-- NOTIFICATION PANEL -->
<div class="notif-panel" id="notifPanel">
  <div class="notif-header">
    <div class="notif-title">🔔 الإشعارات <span style="background:var(--red);color:#fff;padding:2px 8px;border-radius:20px;font-size:11px"><?php echo $unreadCount; ?></span></div>
    <a href="#" style="font-size:12px;color:var(--muted)">تعليم الكل كمقروء</a>
  </div>
  <div class="notif-list">
    <?php foreach ($notifications as $notification): ?>
      <div class="notif-item <?php echo !$notification['is_read'] ? 'unread' : ''; ?>">
        <div class="notif-icon" style="background:#fae8e8">🔔</div>
        <div class="notif-body">
          <div class="notif-text"><?php echo e($notification['message']); ?></div>
          <div class="notif-time"><?php echo timeAgo($notification['created_at']); ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="padding:12px 16px;border-top:1px solid var(--border);text-align:center">
    <a href="#" style="font-size:12px;color:var(--accent)">عرض جميع الإشعارات</a>
  </div>
</div>

<!-- USER PANEL OVERLAY -->
<div class="overlay" id="overlay" onclick="closeAll()"></div>

<!-- USER PANEL -->
<div class="user-panel" id="userPanel">
  <div class="user-panel-header">
    <div style="font-size:16px;font-weight:700">لوحة التحكم</div>
    <button class="close-btn" onclick="closeAll()">×</button>
  </div>
  <div class="user-panel-body">
    <div class="user-profile-card">
      <div class="profile-avatar">أ</div>
      <div class="profile-name">أسامة المعايضة</div>
      <div style="font-size:12px;color:var(--muted)">osama.fa.mayadmeh@gmail.com</div>
      <div class="profile-plan">⭐ Premium</div>
      <div style="display:flex;gap:20px;justify-content:center;margin-top:14px">
        <div style="text-align:center"><div style="font-size:18px;font-weight:700">284</div><div style="font-size:11px;color:var(--muted)">مقالة محفوظة</div></div>
        <div style="text-align:center"><div style="font-size:18px;font-weight:700">12</div><div style="font-size:11px;color:var(--muted)">مصدر نشط</div></div>
        <div style="text-align:center"><div style="font-size:18px;font-weight:700">47</div><div style="font-size:11px;color:var(--muted)">يوم متواصل</div></div>
      </div>
    </div>

    <div class="pref-section">
      <div class="pref-title">تفضيلات الأخبار</div>
      <div class="pref-grid">
        <div class="pref-item selected" onclick="this.classList.toggle('selected')"><span class="check">✓</span>🏛 سياسة</div>
        <div class="pref-item selected" onclick="this.classList.toggle('selected')"><span class="check">✓</span>💹 اقتصاد</div>
        <div class="pref-item selected" onclick="this.classList.toggle('selected')"><span class="check">✓</span>⚽ رياضة</div>
        <div class="pref-item" onclick="this.classList.toggle('selected')"><span class="check"></span>🎨 فنون</div>
        <div class="pref-item selected" onclick="this.classList.toggle('selected')"><span class="check">✓</span>💻 تكنولوجيا</div>
        <div class="pref-item" onclick="this.classList.toggle('selected')"><span class="check"></span>🏥 صحة</div>
        <div class="pref-item" onclick="this.classList.toggle('selected')"><span class="check"></span>🔬 علوم</div>
        <div class="pref-item" onclick="this.classList.toggle('selected')"><span class="check"></span>🌍 بيئة</div>
      </div>
    </div>

    <div class="pref-section">
      <div class="pref-title">إعدادات الإشعارات</div>
      <div class="notif-pref">
        <span>🔴 أخبار عاجلة</span>
        <div class="toggle-sw" onclick="this.classList.toggle('off')"></div>
      </div>
      <div class="notif-pref">
        <span>⚽ نتائج الرياضة</span>
        <div class="toggle-sw" onclick="this.classList.toggle('off')"></div>
      </div>
      <div class="notif-pref">
        <span>💹 تحركات السوق</span>
        <div class="toggle-sw off" onclick="this.classList.toggle('off')"></div>
      </div>
      <div class="notif-pref">
        <span>📊 تقارير جديدة</span>
        <div class="toggle-sw" onclick="this.classList.toggle('off')"></div>
      </div>
      <div class="notif-pref">
        <span>🌙 وضع عدم الإزعاج</span>
        <div class="toggle-sw off" onclick="this.classList.toggle('off')"></div>
      </div>
    </div>

    <div class="pref-section">
      <div class="pref-title">المظهر واللغة</div>
      <div style="display:flex;gap:8px;margin-bottom:12px">
        <div style="flex:1;padding:10px;border-radius:10px;background:var(--bg);border:2px solid var(--accent);text-align:center;font-size:12px;cursor:pointer">🌙 داكن</div>
        <div style="flex:1;padding:10px;border-radius:10px;background:var(--bg3);border:1px solid var(--border);text-align:center;font-size:12px;cursor:pointer;color:var(--muted)">☀️ فاتح</div>
        <div style="flex:1;padding:10px;border-radius:10px;background:var(--bg3);border:1px solid var(--border);text-align:center;font-size:12px;cursor:pointer;color:var(--muted)">🌓 تلقائي</div>
      </div>
    </div>

    <button class="save-btn">💾 حفظ التفضيلات</button>
  </div>
</div>

<!-- ADD SOURCE MODAL -->
<div class="modal" id="addSourceModal">
  <div class="overlay show" onclick="closeAddSource()" style="position:fixed"></div>
  <div class="modal-box" style="position:relative;z-index:1">
    <div class="modal-title">➕ إضافة مصدر إخباري</div>
    <div class="modal-sub">أضف موقعاً إخبارياً جديداً لمتابعة أخباره</div>

    <div class="form-group">
      <label class="form-label">رابط الموقع (RSS أو URL)</label>
      <input class="form-input" type="text" placeholder="https://example-news.com/rss">
    </div>
    <div class="form-group">
      <label class="form-label">اسم المصدر</label>
      <input class="form-input" type="text" placeholder="مثال: صحيفة الغد">
    </div>
    <div class="form-group">
      <label class="form-label">التصنيف</label>
      <div class="tag-row">
        <div class="tag active" onclick="this.classList.toggle('active')">🏛 سياسة</div>
        <div class="tag" onclick="this.classList.toggle('active')">💹 اقتصاد</div>
        <div class="tag" onclick="this.classList.toggle('active')">⚽ رياضة</div>
        <div class="tag" onclick="this.classList.toggle('active')">🎨 فنون</div>
        <div class="tag" onclick="this.classList.toggle('active')">💻 تقنية</div>
        <div class="tag" onclick="this.classList.toggle('active')">🌍 عام</div>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">تفعيل الإشعارات من هذا المصدر</label>
      <div style="display:flex;align-items:center;gap:10px;font-size:13px">
        <div class="toggle-sw" onclick="this.classList.toggle('off')"></div>
        <span style="color:var(--muted)">إرسال إشعار لكل خبر جديد</span>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeAddSource()">إلغاء</button>
      <button class="btn-primary" onclick="closeAddSource()">✔ إضافة المصدر</button>
    </div>
  </div>
</div>

<script>
  // NOTIFICATION PANEL
  function toggleMobileNav() {
    document.getElementById('mobileNav').classList.toggle('open');
    document.querySelector('.mobile-nav-overlay').classList.toggle('open');
  }
  function toggleNotif() {
    const p = document.getElementById('notifPanel');
    const ov = document.getElementById('overlay');
    const isOpen = p.classList.contains('show');
    if(isOpen) { p.classList.remove('show'); ov.classList.remove('show'); }
    else { p.classList.add('show'); ov.classList.add('show'); }
  }

  // USER PANEL
  function openUserPanel() {
    document.getElementById('userPanel').classList.add('open');
    document.getElementById('overlay').classList.add('show');
  }

  function closeAll() {
    document.getElementById('userPanel').classList.remove('open');
    document.getElementById('notifPanel').classList.remove('show');
    document.getElementById('overlay').classList.remove('show');
  }

  // ADD SOURCE MODAL
  function openAddSource() {
    document.getElementById('addSourceModal').classList.add('show');
  }
  function closeAddSource() {
    document.getElementById('addSourceModal').classList.remove('show');
  }

  // SECTIONS NAV
  function filterSection(el, section) {
    document.querySelectorAll('.sec-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    if(section !== 'all') {
      const target = document.getElementById(section);
      if(target) target.scrollIntoView({ behavior:'smooth', block:'start' });
    }
  }

  function scrollToSection(id) {
    const el = document.getElementById(id);
    if(el) el.scrollIntoView({ behavior:'smooth', block:'start' });
  }

  // LIVE CLOCK
  setInterval(() => {
    const now = new Date();
    const h = now.getHours() % 12 || 12;
    const m = String(now.getMinutes()).padStart(2,'0');
    const ampm = now.getHours() >= 12 ? 'PM' : 'AM';
    const el = document.getElementById('liveTime');
    if(el) el.textContent = h + ':' + m + ' ' + ampm;
  }, 1000);

  // ANIMATED COUNTERS ON LOAD
  window.addEventListener('load', () => {
    // Animate poll bars
    setTimeout(() => {
      document.querySelectorAll('.poll-fill').forEach(b => {
        const w = b.style.width;
        b.style.width = '0%';
        setTimeout(() => { b.style.width = w; }, 100);
      });
    }, 300);

    // Fade in cards on scroll
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if(entry.isIntersecting) {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
          observer.unobserve(entry.target);
        }
      });
    }, { threshold:0.1 });
    document.querySelectorAll('.news-card, .list-item, .media-card').forEach(el => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(20px)';
      el.style.transition = 'opacity .5s ease, transform .5s ease';
      observer.observe(el);
    });
  });

  // SAVE PREFS FEEDBACK
  document.querySelectorAll('.save-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      this.textContent = 'تم الحفظ بنجاح!';
      this.style.background = 'linear-gradient(135deg,#0d9488,#0f766e)';
      setTimeout(() => {
        this.textContent = 'حفظ التفضيلات';
        this.style.background = 'linear-gradient(135deg,#1a73e8,#4f46e5)';
      }, 2000);
    });
  });

  // SIMULATE NEW NOTIFICATION BADGE
  let notifCount = <?php echo $unreadCount; ?>;
  setInterval(() => {
    const badge = document.querySelector('.notif-badge');
    if(Math.random() > 0.7) {
      notifCount++;
      badge.textContent = notifCount;
      badge.style.transform = 'scale(1.4)';
      setTimeout(() => badge.style.transform = '', 300);
    }
  }, 15000);
</script>
<!-- CURRENCY MODAL -->
<div class="modal-overlay" id="currencyModal">
  <div class="modal-box">
    <div class="modal-header">
      <h2>💱 أسعار صرف العملات</h2>
      <button class="modal-close" onclick="closeCurrencyModal()">&times;</button>
    </div>
    <div class="modal-body" id="currencyModalBody">
      <div style="text-align:center;padding:30px;color:#999">جارٍ تحميل الأسعار...</div>
    </div>
    <div style="padding:0 24px 16px;text-align:center;font-size:11px;color:#bbb">
      الأسعار تقريبية وقد تختلف عن أسعار السوق الفعلية
    </div>
  </div>
</div>

<script src="assets/js/home.js?v=1" defer></script>

</body>
</html>
