<?php
/**
 * Shared site header component
 *
 * Expected variables (all optional, defaults shown):
 *   $activeType   string  'home' | 'breaking' | 'latest' (default: 'home')
 *   $activeSlug   string  category slug like 'political' (default: '')
 *   $showTicker   bool    whether to render the breaking ticker (default: false)
 *   $tickerItems  array   array of articles for the ticker (default: [])
 *   $viewer       array|null  current user record (default: null)
 *   $viewerId     int     current user id (default: 0)
 *   $userUnread   int     unread notifications count (default: 0)
 */

$activeType  = $activeType  ?? 'home';
$activeSlug  = $activeSlug  ?? '';
$showTicker  = $showTicker  ?? false;
$tickerItems = $tickerItems ?? [];
$viewer      = $viewer      ?? null;
$viewerId    = $viewerId    ?? 0;
$userUnread  = $userUnread  ?? 0;

$nf_nav_link_class = function (string $type, string $slug = '') use ($activeType, $activeSlug): string {
    $classes = ['nav-link'];
    if ($type === 'breaking') $classes[] = 'breaking';
    if ($type === 'reels')    $classes[] = 'nav-reels';
    if ($type === 'telegram') $classes[] = 'nav-telegram';
    if ($type === $activeType && $slug === $activeSlug) $classes[] = 'active';
    return implode(' ', $classes);
};
?>
<!-- HEADER -->
<header class="site-header">
  <div class="site-header-inner">
    <a class="logo" href="index.php">
      <div class="logo-icon">N</div>
      <div class="logo-text-wrap">
        <h1 class="logo-text"><?php echo e(getSetting('site_name', SITE_NAME)); ?></h1>
        <div class="logo-sub"><?php echo e(getSetting('site_tagline', SITE_TAGLINE)); ?></div>
      </div>
    </a>

    <div class="header-center">
      <div class="search-box">
        <span class="search-icon">&#x1F50D;</span>
        <input type="text" placeholder="ابحث عن خبر، مصدر، أو موضوع...">
      </div>
    </div>

    <div class="header-actions">
      <span class="live-pill" title="<?php echo date('l, j F Y H:i'); ?>">
        <span class="live-dot"></span>
        <span class="live-pill-label">مباشر</span>
        <span class="live-pill-time" id="liveTime"><?php echo date('H:i'); ?></span>
      </span>
      <button type="button" class="icon-btn icon-btn-wide" id="topWeather" onclick="if(window.openWeatherModal)openWeatherModal()" title="الطقس">🌤 <span>--°</span></button>
      <button type="button" class="icon-btn" onclick="if(window.openCurrencyModal)openCurrencyModal()" title="أسعار العملات" aria-label="أسعار العملات">💱</button>
      <button type="button" class="icon-btn" onclick="if(window.openSourcesModal)openSourcesModal()" title="المصادر النشطة" aria-label="المصادر">🌐</button>
      <button type="button" class="icon-btn" onclick="if(window.NF&&NF.cycleTheme)NF.cycleTheme()" title="تبديل الثيم" aria-label="الثيم">🌓</button>
      <?php if ($viewerId): ?>
        <button type="button" class="icon-btn" data-nf-notif-btn onclick="if(window.NF&&NF.toggleNotifDropdown)NF.toggleNotifDropdown(this)" title="الإشعارات" aria-label="الإشعارات">
          🔔
          <?php if ($userUnread > 0): ?><span class="notif-badge" data-notif-badge><?php echo (int)$userUnread; ?></span><?php endif; ?>
        </button>
        <a href="me/" class="avatar" title="لوحتي"><?php echo e(mb_substr($viewer['name'] ?? '?', 0, 1)); ?></a>
      <?php else: ?>
        <a href="account/login.php" class="icon-btn" title="دخول" aria-label="تسجيل الدخول">🔑</a>
        <a href="account/register.php" class="btn-join">انضم مجاناً</a>
      <?php endif; ?>
      <button class="menu-toggle" onclick="toggleMobileNav()" aria-label="القائمة">☰</button>
    </div>
  </div>

  <nav class="site-nav">
    <div class="site-nav-inner">
      <a href="category.php?type=breaking" class="<?php echo $nf_nav_link_class('breaking'); ?>"><span class="nav-dot"></span>عاجل</a>
      <a href="index.php" class="<?php echo $nf_nav_link_class('home'); ?>">الرئيسية</a>
      <a href="category.php?type=latest" class="<?php echo $nf_nav_link_class('latest'); ?>">آخر الأخبار</a>
      <a href="category/political" class="<?php echo $nf_nav_link_class('category', 'political'); ?>">سياسة</a>
      <a href="category/economy" class="<?php echo $nf_nav_link_class('category', 'economy'); ?>">اقتصاد</a>
      <a href="category/sports" class="<?php echo $nf_nav_link_class('category', 'sports'); ?>">رياضة</a>
      <a href="category/arts" class="<?php echo $nf_nav_link_class('category', 'arts'); ?>">فنون</a>
      <a href="category/media" class="<?php echo $nf_nav_link_class('category', 'media'); ?>">ميديا</a>
      <a href="category/reports" class="<?php echo $nf_nav_link_class('category', 'reports'); ?>">تقارير</a>
      <a href="telegram.php" class="<?php echo $nf_nav_link_class('telegram'); ?>">📢 تلغرام</a>
      <a href="reels.php" class="<?php echo $nf_nav_link_class('reels'); ?>">🎬 ريلز</a>
    </div>
  </nav>
</header>

<!-- MOBILE NAV -->
<div class="mobile-nav-overlay" onclick="toggleMobileNav()"></div>
<nav class="mobile-nav" id="mobileNav">
  <button class="mobile-nav-close" onclick="toggleMobileNav()">×</button>
  <a href="index.php"<?php echo $activeType === 'home' ? ' class="active"' : ''; ?>>🏠 الرئيسية</a>
  <a href="category.php?type=breaking"<?php echo $activeType === 'breaking' ? ' class="active"' : ''; ?>>🔴 عاجل</a>
  <a href="category.php?type=latest"<?php echo $activeType === 'latest' ? ' class="active"' : ''; ?>>⏱ آخر الأخبار</a>
  <a href="category/political"<?php echo $activeSlug === 'political' ? ' class="active"' : ''; ?>>🏛 سياسة</a>
  <a href="category/economy"<?php echo $activeSlug === 'economy' ? ' class="active"' : ''; ?>>💹 اقتصاد</a>
  <a href="category/sports"<?php echo $activeSlug === 'sports' ? ' class="active"' : ''; ?>>⚽ رياضة</a>
  <a href="category/arts"<?php echo $activeSlug === 'arts' ? ' class="active"' : ''; ?>>🎨 فنون</a>
  <a href="category/media"<?php echo $activeSlug === 'media' ? ' class="active"' : ''; ?>>🎥 ميديا</a>
  <a href="category/reports"<?php echo $activeSlug === 'reports' ? ' class="active"' : ''; ?>>📊 تقارير</a>
  <a href="telegram.php"<?php echo $activeType === 'telegram' ? ' class="active"' : ''; ?>>📢 تلغرام</a>
  <a href="reels.php">🎬 ريلز</a>
</nav>

<?php if ($showTicker && !empty($tickerItems)): ?>
<!-- TICKER -->
<div class="ticker-wrap">
  <div class="ticker-label"><span class="ticker-label-dot"></span>عاجل</div>
  <div class="ticker-content">
    <?php foreach ($tickerItems as $item): ?>
      <a class="ticker-item" href="<?php echo articleUrl($item); ?>"><?php echo e($item['title']); ?></a>
    <?php endforeach; ?>
    <?php foreach ($tickerItems as $item): ?>
      <a class="ticker-item" href="<?php echo articleUrl($item); ?>"><?php echo e($item['title']); ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
/* Mobile nav toggle — shared so any page including this partial gets it */
if (typeof window.toggleMobileNav !== 'function') {
  window.toggleMobileNav = function() {
    document.getElementById('mobileNav')?.classList.toggle('open');
    document.querySelector('.mobile-nav-overlay')?.classList.toggle('open');
  };
}
</script>
