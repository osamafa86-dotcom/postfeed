<?php
/**
 * نيوزفلو - الصفحة الرئيسية
 * موقع تجميع الأخبار من مصادر متعددة
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';
require_once __DIR__ . '/includes/article_cluster.php';
require_once __DIR__ . '/includes/trending.php';
require_once __DIR__ . '/includes/personalize.php';
require_once __DIR__ . '/includes/story_timeline.php';
require_once __DIR__ . '/includes/evolving_stories.php';

// Viewer context for save buttons / theme / user menu
$viewer = current_user();
$viewerId = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;

// Personalized "For You" rail — only computed for logged-in users. Empty for
// guests, and also empty for brand-new users with no follows/reads yet, in
// which case we show an onboarding CTA instead.
$personalFeed       = [];
$personalShowOnboarding = false;
if ($viewerId) {
    $personalFeed = personalize_feed_for($viewerId, 6, 72);
    if (!$personalFeed) {
        // No score-able candidates → either truly quiet or a brand-new user.
        // Show onboarding if the user hasn't picked any follows yet.
        if (!user_followed_category_ids($viewerId) && !user_followed_source_ids($viewerId)) {
            $personalShowOnboarding = true;
        }
    }
}

// جلب البيانات من قاعدة البيانات
$heroArticles = getHeroArticles();
// Fetch generous pools so dedup (by id + fuzzy title) still leaves enough visible items.
$palestineNews = getPalestineNews(20);
$breakingNews = getBreakingNews(20);
$latestArticles = getLatestArticles(40);
$categories = getCategories();
$notifications = getNotifications(6);
$unreadCount = getUnreadNotifCount();
$poll = getActivePoll();
$trends = getTrends();
$sources = getActiveSources();
$mostRead = getMostRead(6);
// Trending now: velocity-scored top stories. Returns up to 8 distinct
// clusters; empty on first deploy until article_view_events fills up.
$trendingNow      = trending_get_top(8);
$trendingReaders  = trending_active_readers();

// Evolving stories rail — admin-defined persistent topics
// (أخبار الأقصى، غزة، الأسرى…). Only the single freshest story
// is featured on the homepage; the rest live on /evolving-stories.
// Cached 5 min; sorts by freshness inside evolving_stories_with_previews.
$evolvingRail = cache_remember('home_evolving_rail_v2', 300, function() {
    $stories = evolving_stories_with_previews(5);
    return array_slice($stories, 0, 1);
});

// Ticker pulls from the latest Palestine news stream so the "عاجل" strip
// mirrors the Palestine section headlines.
$tickerItems = array_slice($palestineNews, 0, 10);

// إحصائيات
$totalArticles = countArticles();
$totalSources = count($sources);

// جلب أخبار التصنيفات (نطلب عدد أكبر لتعويض ما يتم حذفه عند منع التكرار)
$politicalNews = getArticlesByCategory('political', 40);
$economyNews   = getArticlesByCategory('economy', 40);
$sportsNews    = getArticlesByCategory('sports', 40);
$artsNews      = getArticlesByCategory('arts', 40);
$reportsNews   = getArticlesByCategory('reports', 40);

// ============================================================
// منع تكرار الخبر عبر أكثر من قسم في الصفحة الرئيسية
// Dedup by article ID *and* by fuzzy title match, so the same
// story republished by several sources only appears once.
// ============================================================
$nf_title_tokens = function(string $title): array {
    // Strip Arabic diacritics (harakat + tatweel).
    $t = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $title);
    // Normalize Arabic letter variants.
    $t = strtr($t, [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه',
        'ى' => 'ي', 'ئ' => 'ي',
        'ؤ' => 'و',
    ]);
    // Replace punctuation / symbols with spaces.
    $t = preg_replace('/[\p{P}\p{S}«»"\'"""‚„]/u', ' ', $t);
    $t = preg_replace('/\s+/u', ' ', trim($t));
    $t = mb_strtolower($t);
    $tokens = preg_split('/\s+/u', $t) ?: [];
    // Arabic stop words (articles, prepositions, common fillers).
    $stop = ['في','من','على','الى','إلى','عن','مع','بعد','قبل','هذا','هذه','ذلك','تلك','التي','الذي','بين','كل','او','أو','ما','ان','أن','إن','قد','هو','هي','هم','لم','لن','لا','وقد','الف','هذي'];
    $out = [];
    // Multi-char prefixes first (longest match wins), then single-letter
    // conjunctions/prepositions. Keeps a floor of 3 chars after stripping.
    $multi  = ['وال','فال','بال','كال','لل'];
    $single = ['و','ف','ب','ل','ك','س'];
    foreach ($tokens as $tok) {
        if (mb_strlen($tok) < 3) continue;
        if (in_array($tok, $stop, true)) continue;
        foreach ($multi as $p) {
            $pl = mb_strlen($p);
            if (mb_strlen($tok) >= $pl + 3 && mb_substr($tok, 0, $pl) === $p) {
                $tok = mb_substr($tok, $pl);
                break;
            }
        }
        if (mb_strlen($tok) >= 5 && mb_substr($tok, 0, 2) === 'ال') {
            $tok = mb_substr($tok, 2);
        }
        if (mb_strlen($tok) >= 5) {
            $first = mb_substr($tok, 0, 1);
            if (in_array($first, $single, true)) {
                $tok = mb_substr($tok, 1);
            }
        }
        if (mb_strlen($tok) < 3) continue;
        $out[$tok] = true;
    }
    return array_keys($out);
};
$usedIds = [];
$usedTitleTokens = []; // array of token-arrays for already-used articles
// Pre-seed with hero articles so hero stories never reappear below.
foreach ($heroArticles as $h) {
    if (!empty($h['id'])) $usedIds[(int)$h['id']] = true;
    if (!empty($h['title'])) $usedTitleTokens[] = $nf_title_tokens($h['title']);
}
// Pre-seed with the personalized rail so those stories don't repeat below.
foreach ($personalFeed as $pf) {
    if (!empty($pf['id'])) $usedIds[(int)$pf['id']] = true;
    if (!empty($pf['title'])) $usedTitleTokens[] = $nf_title_tokens($pf['title']);
}
$dedup = function(array $list, int $keep) use (&$usedIds, &$usedTitleTokens, $nf_title_tokens): array {
    $out = [];
    foreach ($list as $a) {
        $id = (int)($a['id'] ?? 0);
        if ($id && isset($usedIds[$id])) continue;
        $tokens = $nf_title_tokens((string)($a['title'] ?? ''));
        // Jaccard similarity vs every already-used title. Threshold 0.55
        // catches "حشد تدين اغتيال الصحفي وشاح" vs "حشد تدين اغتيال
        // الصحفي محمد وشاح في غزة" while still allowing genuinely
        // different stories that happen to share a couple of words.
        $isDup = false;
        if ($tokens) {
            foreach ($usedTitleTokens as $used) {
                if (!$used) continue;
                $inter = array_intersect($used, $tokens);
                if (!$inter) continue;
                $union = array_unique(array_merge($used, $tokens));
                if (!$union) continue;
                if (count($inter) / count($union) >= 0.55) { $isDup = true; break; }
            }
        }
        if ($isDup) continue;
        if ($id) $usedIds[$id] = true;
        $usedTitleTokens[] = $tokens;
        $out[] = $a;
        if (count($out) >= $keep) break;
    }
    return $out;
};
// Order matters: palestine first so it keeps its featured stories; latest
// then fills in around them without repeating palestine items.
$palestineNews  = $dedup($palestineNews, 4);
$breakingNews   = $dedup($breakingNews, 4);
$latestArticles = $dedup($latestArticles, 12);
$politicalNews  = $dedup($politicalNews, 4);
$economyNews    = $dedup($economyNews, 4);
$sportsNews     = $dedup($sportsNews, 4);
$artsNews       = $dedup($artsNews, 4);
$reportsNews    = $dedup($reportsNews, 4);

// Pre-fetch which of the articles on this page are already bookmarked for the viewer,
// plus reaction counts and the viewer's own reactions.
$GLOBALS['__nf_saved_ids']       = [];
$GLOBALS['__nf_reaction_counts'] = [];
$GLOBALS['__nf_user_reactions']  = [];
$GLOBALS['__nf_cluster_counts']  = [];
$GLOBALS['__nf_timeline_keys']   = [];
$__allIds = [];
$__allClusterKeys = [];
foreach ([$heroArticles, $personalFeed, $palestineNews, $breakingNews, $latestArticles,
          $politicalNews, $economyNews, $sportsNews, $artsNews, $reportsNews] as $__list) {
    foreach ($__list as $__a) {
        $__allIds[] = (int)($__a['id'] ?? 0);
        $__ck = (string)($__a['cluster_key'] ?? '');
        if ($__ck !== '' && $__ck !== '-') $__allClusterKeys[] = $__ck;
    }
}
if ($__allIds) {
    $GLOBALS['__nf_reaction_counts'] = article_reactions_counts_for($__allIds);
    if ($viewerId) {
        $GLOBALS['__nf_saved_ids'] = array_flip(user_bookmark_ids_for($viewerId, $__allIds));
        $GLOBALS['__nf_user_reactions'] = user_article_reactions_for($viewerId, $__allIds);
    }
}
// One round-trip groups every visible article's cluster and tells us
// which keys actually have multiple sources (≥ 2 rows). Single-row
// clusters are dropped server-side so the badge stays meaningful.
if ($__allClusterKeys) {
    $GLOBALS['__nf_cluster_counts'] = cluster_counts_for($__allClusterKeys);
    // Second lookup: which of those clusters already have a stored
    // smart timeline. Used by renderTimelineBadge on each card.
    $GLOBALS['__nf_timeline_keys']  = story_timeline_keys_for($__allClusterKeys);
}

// جلب الريلز للعرض في الصفحة الرئيسية
$homeReels = cache_remember('home_reels_8', HOMEPAGE_CACHE_TTL, function() {
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT r.*, s.username, s.display_name, s.avatar_url
                             FROM reels r
                             LEFT JOIN reels_sources s ON r.source_id = s.id
                             WHERE r.is_active = 1
                             ORDER BY r.sort_order DESC, r.created_at DESC
                             LIMIT 8");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {
        return [];
    }
});

?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e(getSetting('site_name', SITE_NAME)); ?> - <?php echo e(getSetting('site_tagline', SITE_TAGLINE)); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php /*
  Async-load Tajawal without the rel=preload+swap trick, which triggers
  a "preloaded but not used" warning in Chrome because the CSS parser
  doesn't claim the preloaded resource until it finishes swapping the
  link's rel. The media=print → media=all pattern gets the same
  non-render-blocking behaviour without the warning.
*/ ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"></noscript>
<meta name="description" content="مجمع الأخبار العربية الأول - أحدث الأخبار من مصادر موثوقة في السياسة، الاقتصاد، الرياضة، والتكنولوجيا">
<link rel="alternate" type="application/rss+xml" title="<?php echo e(getSetting('site_name', SITE_NAME)); ?> RSS" href="/rss.xml">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#1a5c5c">
<?php
// SEO block: canonical + OG + Twitter + WebSite/Organization JSON-LD.
// Kept in one helper (includes/seo.php) so the tag shape stays
// consistent with article.php and future list pages.
require_once __DIR__ . '/includes/seo.php';
render_home_seo();
?>
<?php /*
  The featured image is rendered as an <img fetchpriority="high"> down
  in the .nf-feature-main block (was a CSS background-image, switched
  so the preload scanner picks it up naturally). No rel=preload needed
  any more — and Chrome no longer complains that the preload wasn't
  claimed in time.
*/ ?>
<?php /*
  Critical CSS pattern:
    - site-header.min.css + critical-home.min.css are inlined so the
      above-the-fold paint (header + hero feature block) doesn't wait
      on the ~90KB of full bundles below.
    - home / home-index / user bundles are loaded with media=print →
      media=all so they're non-render-blocking but still applied by
      the time the user scrolls past the fold.
    - <noscript> fallback keeps them render-blocking for the rare
      JS-disabled visitor, which is fine because they won't have a
      worse experience than before.
*/ ?>
<style><?php readfile(__DIR__ . '/assets/css/site-header.min.css'); readfile(__DIR__ . '/assets/css/critical-home.min.css'); ?></style>
<link rel="stylesheet" href="assets/css/home.min.css?v=m5" media="print" onload="this.media='all'">
<link rel="stylesheet" href="assets/css/home-index.min.css?v=m3" media="print" onload="this.media='all'">
<link rel="stylesheet" href="assets/css/user.min.css?v=m2" media="print" onload="this.media='all'">
<noscript>
  <link rel="stylesheet" href="assets/css/home.min.css?v=m5">
  <link rel="stylesheet" href="assets/css/home-index.min.css?v=m3">
  <link rel="stylesheet" href="assets/css/user.min.css?v=m2">
</noscript>
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<script>
// Register the service worker for the PWA shell. Wrapped in a load
// listener so it never blocks first paint, and a try/catch so an
// older browser without SW support is a no-op instead of an error.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    try { navigator.serviceWorker.register('/sw.js', { scope: '/' }); } catch (e) {}
  });
}
</script>
</head>
<body>

<?php
// Shared site header (header + main nav + mobile nav + breaking ticker)
$activeType = 'home';
$activeSlug = '';
$showTicker = true;
include __DIR__ . '/includes/components/site_header.php';
?>

<!-- STATS STRIP (compact) -->
<div class="stats-strip">
  <div class="stats-strip-inner">
    <span class="stat-chip stat-chip-blue"><span class="stat-chip-ico">📰</span><b><?php echo number_format($totalArticles); ?></b><em>خبر</em></span>
    <span class="stat-chip stat-chip-teal"><span class="stat-chip-ico">🌐</span><b><?php echo $totalSources; ?></b><em>مصدر نشط</em></span>
    <span class="stat-chip stat-chip-purple"><span class="stat-chip-ico">👁</span><b>3.2M</b><em>مشاهدة اليوم</em></span>
    <span class="stat-chip stat-chip-orange"><span class="stat-chip-ico">🔥</span><b>سياسة</b><em>الأكثر تداولاً</em></span>
    <span class="stat-chip stat-chip-red"><span class="stat-chip-ico">⏱</span><b>منذ 2 دق</b><em>آخر تحديث</em></span>
  </div>
</div>

<!-- SECTIONS NAV (homepage anchors) -->
<div class="sections-nav">
  <div class="sections-nav-inner">
    <button type="button" class="sec-pill active" data-sec="all" onclick="scrollToHomeSection(this,'all')"><span class="sec-pill-ico">📰</span>الكل</button>
    <?php if ($viewerId && ($personalFeed || $personalShowOnboarding)): ?>
    <button type="button" class="sec-pill sec-pill-foryou" data-sec="foryou" onclick="scrollToHomeSection(this,'foryou')"><span class="sec-pill-ico">✨</span>خاص بك</button>
    <?php endif; ?>
    <a class="sec-pill sec-pill-ask" href="ask.php"><span class="sec-pill-ico">🤖</span>اسأل الأخبار</a>
    <button type="button" class="sec-pill" data-sec="breaking" onclick="scrollToHomeSection(this,'breaking')"><span class="sec-pill-ico">🔴</span>عاجل</button>
    <button type="button" class="sec-pill" data-sec="latest" onclick="scrollToHomeSection(this,'latest')"><span class="sec-pill-ico">⏱</span>آخر الأخبار</button>
    <button type="button" class="sec-pill" data-sec="palestine" onclick="scrollToHomeSection(this,'palestine')"><span class="sec-pill-ico">🇵🇸</span>فلسطين</button>
    <button type="button" class="sec-pill" data-sec="trending" onclick="scrollToHomeSection(this,'trending')"><span class="sec-pill-ico">🔥</span>الأكثر تداولاً</button>
    <button type="button" class="sec-pill" data-sec="reels" onclick="scrollToHomeSection(this,'reels')"><span class="sec-pill-ico">🎬</span>ريلز</button>
  </div>
</div>

<?php if ($viewerId && $personalFeed): ?>
<!-- ✨ FOR YOU (personalized rail) — logged-in users only -->
<div id="foryou" class="foryou-section">
  <div class="foryou-head">
    <div class="foryou-head-title">
      <span class="foryou-ico">✨</span>
      <div>
        <h2>خاص بك</h2>
        <p class="foryou-sub">مختارة حسب اهتماماتك<?php if (!empty($viewer['name'])): ?> يا <?php echo e($viewer['name']); ?><?php endif; ?></p>
      </div>
    </div>
    <a class="foryou-manage" href="me/following.php" title="عدّل اهتماماتك">⚙️ تعديل</a>
  </div>
  <div class="foryou-grid">
    <?php foreach ($personalFeed as $pf):
      $pfSaved = isset($GLOBALS['__nf_saved_ids'][(int)$pf['id']]);
      $pfImg = $pf['image_url'] ?? placeholderImage(400, 300);
    ?>
      <a class="foryou-card" href="<?php echo e(articleUrl($pf)); ?>" data-article-id="<?php echo (int)$pf['id']; ?>">
        <button type="button"
                class="nf-bookmark-btn <?php echo $pfSaved ? 'saved' : ''; ?>"
                title="<?php echo $pfSaved ? 'إزالة من المحفوظات' : 'حفظ'; ?>"
                data-save-id="<?php echo (int)$pf['id']; ?>"
                onclick="event.preventDefault(); event.stopPropagation(); NF.toggleSave(this)">🔖</button>
        <div class="foryou-card-img" style="background-image:url('<?php echo e($pfImg); ?>');">
          <?php if (!empty($pf['cat_name'])): ?>
            <span class="foryou-card-cat"><?php echo e($pf['cat_name']); ?></span>
          <?php endif; ?>
        </div>
        <div class="foryou-card-body">
          <h3 class="foryou-card-title"><?php echo e($pf['title']); ?></h3>
          <div class="foryou-card-reason">
            <span class="foryou-reason-dot">•</span>
            <span><?php echo e($pf['_reason'] ?? 'مُقترح لك'); ?></span>
          </div>
          <div class="foryou-card-meta">
            <?php if (!empty($pf['source_name'])): ?>
              <span class="foryou-source">
                <span class="src-dot" style="background:<?php echo e($pf['logo_color'] ?? '#0d9488'); ?>"><?php echo e(mb_substr($pf['source_name'], 0, 1)); ?></span>
                <?php echo e($pf['source_name']); ?>
              </span>
              <span class="sep">·</span>
            <?php endif; ?>
            <span><?php echo timeAgo($pf['published_at']); ?></span>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php elseif ($viewerId && $personalShowOnboarding): ?>
<!-- ✨ FOR YOU (onboarding CTA) — logged-in user with no follows yet -->
<div id="foryou" class="foryou-section">
  <div class="foryou-onboard">
    <div class="foryou-onboard-ico">✨</div>
    <h2>خصّص صفحتك الرئيسية</h2>
    <p>اختر اهتماماتك والمصادر المفضلة لديك لنعرض لك أخباراً مختارة خصيصاً — في كل زيارة.</p>
    <a class="foryou-onboard-btn" href="me/following.php">اختر اهتماماتك ›</a>
  </div>
</div>
<?php endif; ?>

<!-- LATEST NEWS (Featured 3-column layout) — full-width above main-layout -->
<?php
// Split: 1 center + 3 left + 3 right (7 items total), remainder spills into main news-grid
$__featMain  = $latestArticles[0] ?? null;
$__featSide  = array_slice($latestArticles, 1, 6);
$__featLeft  = array_slice($__featSide, 0, 3);
$__featRight = array_slice($__featSide, 3, 3);
$__featRest  = array_slice($latestArticles, 7);
?>
<?php if ($__featMain): ?>
<div class="nf-feature-container">
  <div id="latest" class="section-header">
    <div class="section-title blue"><div class="line"></div>⏱ آخر الأخبار</div>
    <a class="see-all" href="category.php?type=latest">عرض الكل ›</a>
  </div>
  <div class="nf-feature-wrap">
    <!-- Left column (side cards) -->
    <div class="nf-feature-side">
      <?php foreach ($__featLeft as $article): ?>
        <?php include __DIR__ . '/includes/components/home_feature_side.php'; ?>
      <?php endforeach; ?>
    </div>
    <!-- Center featured -->
    <div class="nf-feature-main">
      <a class="nf-feature-main-link" href="<?php echo articleUrl($__featMain); ?>">
        <?php /*
          Rendered as <img> (not a CSS background) so the browser's
          preload scanner discovers it at HTML parse time and the LCP
          asset is fetched without a separate rel=preload. The CSS for
          .nf-feature-main-img works on either tag via attribute
          selectors / :is().
        */ ?>
        <?php /*
          Routed through /api/img.php (see responsiveImg helper) so the
          browser gets a right-sized WebP (Accept: image/webp → webp
          automatically), which is ~35% lighter than the origin JPEG.
          sizes="640px" on desktop because the feature card is ~1/3 of
          a 1400px container; 100vw on narrower viewports.
        */ ?>
        <?php echo responsiveImg(
          $__featMain['image_url'] ?? placeholderImage(1200, 800),
          $__featMain['title'] ?? '',
          '(max-width:1024px) 100vw, 640px',
          [480, 800, 1200],
          'nf-feature-main-img',
          'eager',
          'fetchpriority="high"'
        ); ?>
        <div class="nf-feature-main-body">
          <?php echo renderClusterBadge($__featMain); if (function_exists('renderTimelineBadge')) echo renderTimelineBadge($__featMain); ?>
          <h3 class="nf-feature-main-title"><?php echo e($__featMain['title']); ?></h3>
          <div class="nf-feature-main-meta">
            <?php if (!empty($__featMain['source_name'])): ?>
              <span class="nf-feature-main-source">
                <span class="src-dot" style="background:<?php echo e($__featMain['logo_color'] ?? '#6b9fd4'); ?>"><?php echo e(mb_substr($__featMain['source_name'], 0, 1)); ?></span>
                <?php echo e($__featMain['source_name']); ?>
              </span>
              <span class="sep">·</span>
            <?php endif; ?>
            <span><?php echo timeAgo($__featMain['published_at']); ?></span>
            <span class="sep">|</span>
            <span><?php echo e($__featMain['cat_name'] ?? ''); ?></span>
          </div>
        </div>
      </a>
      <?php $article = $__featMain; include __DIR__ . '/includes/components/action_bar.php'; ?>
    </div>
    <!-- Right column (side cards) -->
    <div class="nf-feature-side">
      <?php foreach ($__featRight as $article): ?>
        <?php include __DIR__ . '/includes/components/home_feature_side.php'; ?>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MAIN CONTENT -->
<div class="main-layout">
  <div class="main-col">

    <!-- PALESTINE NEWS -->
    <div id="palestine" class="section-header">
      <div class="section-title"><div class="line" style="background:#16a34a"></div>🇵🇸 أحدث الأخبار الفلسطينية</div>
    </div>
    <?php if (!empty($palestineNews)): ?>
      <?php $psFirst = $palestineNews[0]; ?>
      <div class="ps-hero">
        <a class="ps-hero-link" href="<?php echo articleUrl($psFirst); ?>">
          <div class="ps-hero-text">
            <?php echo renderClusterBadge($psFirst); if (function_exists('renderTimelineBadge')) echo renderTimelineBadge($psFirst); ?>
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
            <?php echo responsiveImg(
              $psFirst['image_url'] ?? placeholderImage(800, 500),
              $psFirst['title'] ?? '',
              '(max-width:768px) 100vw, 480px',
              [320, 480, 800],
              '',
              'lazy'
            ); ?>
          </div>
        </a>
        <?php $article = $psFirst; include __DIR__ . '/includes/components/action_bar.php'; ?>
      </div>

      <div class="palestine-grid">
        <?php for ($pIdx = 1; $pIdx < count($palestineNews); $pIdx++): $article = $palestineNews[$pIdx]; ?>
          <div class="ps-card">
            <a class="ps-card-link" href="<?php echo articleUrl($article); ?>">
              <div class="img-wrap">
                <img src="<?php echo e($article['image_url'] ?? placeholderImage(400,300)); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async">
                <div class="img-date"><?php echo timeAgo($article['published_at']); ?></div>
              </div>
              <div class="ps-card-body">
                <?php echo renderClusterBadge($article); if (function_exists('renderTimelineBadge')) echo renderTimelineBadge($article); ?>
                <h3><?php echo e($article['title']); ?></h3>
                <div class="ps-card-footer">
                  <span class="source-dot"><?php echo e(mb_substr($article['source_name'], 0, 1)); ?></span>
                  <span><?php echo e($article['source_name']); ?></span>
                </div>
              </div>
            </a>
            <?php include __DIR__ . '/includes/components/action_bar.php'; ?>
          </div>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

    <!-- EVOLVING STORIES RAIL — admin-curated persistent topics -->
    <?php if (!empty($evolvingRail)): ?>
      <div id="evolving-rail" class="section-header">
        <div class="section-title"><div class="line" style="background:#d97706"></div>📅 قصص متطوّرة — متابعة دائمة</div>
        <a class="see-all" href="/evolving-stories">عرض الكل ›</a>
      </div>
      <div class="evrail-grid">
        <?php foreach ($evolvingRail as $st):
          $sUrl   = evolving_story_url($st);
          $sCover = !empty($st['cover_image']) ? $st['cover_image'] : placeholderImage(500, 280);
          $color  = $st['accent_color'] ?: '#0d9488';
        ?>
          <a class="evrail-card" href="<?php echo e($sUrl); ?>">
            <div class="evrail-cover" style="background-image:url('<?php echo e($sCover); ?>');">
              <span class="evrail-accent" style="background:<?php echo e($color); ?>;"></span>
              <?php if (!empty($st['last_matched_at']) && strtotime($st['last_matched_at']) > (time() - 7200)): ?>
                <span class="evrail-live"><span class="dot"></span>مباشر</span>
              <?php endif; ?>
              <div class="evrail-head">
                <div class="evrail-icon"><?php echo e($st['icon'] ?: '📅'); ?></div>
                <div class="evrail-name"><?php echo e($st['name']); ?></div>
              </div>
            </div>
            <div class="evrail-body">
              <?php if (!empty($st['latest'])): ?>
                <ul class="evrail-latest">
                  <?php foreach (array_slice($st['latest'], 0, 5) as $la): ?>
                    <li>
                      <span class="bullet" style="background:<?php echo e($color); ?>;"></span>
                      <span class="txt"><?php echo e(mb_substr((string)$la['title'], 0, 75)); ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <div class="evrail-foot">
                <span>📰 <b><?php echo number_format($st['article_count']); ?></b> تقرير</span>
                <?php if (!empty($st['last_matched_at']) && $st['last_matched_at'] !== '0000-00-00 00:00:00'): ?>
                  <span>↻ <?php echo e(timeAgo($st['last_matched_at'])); ?></span>
                <?php endif; ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <style>
        /* Single featured card on the homepage: horizontal layout,
           cover on one side, latest headlines on the other. The
           /evolving-stories page still uses the grid version. */
        .evrail-grid {
          display:grid; grid-template-columns:1fr;
          gap:16px; margin-bottom:32px;
        }
        .evrail-card {
          background:#fff; border:1px solid #e0e3e8; border-radius:16px;
          overflow:hidden; text-decoration:none; color:inherit;
          display:grid; grid-template-columns:minmax(260px, 38%) 1fr;
          transition:transform .2s ease, box-shadow .2s ease, border-color .2s ease;
          box-shadow:0 2px 8px -3px rgba(0,0,0,.06);
        }
        .evrail-card:hover {
          transform:translateY(-3px);
          box-shadow:0 14px 30px -16px rgba(13,148,136,.26);
          border-color:rgba(217,119,6,.3);
        }
        .evrail-cover {
          min-height:220px; background-size:cover; background-position:center;
          position:relative; background-color:#e5e7eb;
        }
        .evrail-cover::after {
          content:''; position:absolute; inset:0;
          background:linear-gradient(180deg, rgba(0,0,0,0) 40%, rgba(0,0,0,.82) 100%);
        }
        .evrail-accent { position:absolute; top:0; left:0; right:0; height:4px; z-index:2; }
        .evrail-live {
          position:absolute; top:10px; right:10px; z-index:3;
          background:#dc2626; color:#fff; padding:4px 10px; border-radius:999px;
          font-size:10.5px; font-weight:800; display:flex; align-items:center; gap:5px;
          box-shadow:0 2px 8px rgba(220,38,38,.4);
        }
        .evrail-live .dot {
          width:6px; height:6px; border-radius:50%; background:#fff;
          animation:evrail-pulse 2s infinite;
        }
        @keyframes evrail-pulse {
          0% { box-shadow:0 0 0 0 rgba(255,255,255,.7); }
          70% { box-shadow:0 0 0 8px rgba(255,255,255,0); }
          100% { box-shadow:0 0 0 0 rgba(255,255,255,0); }
        }
        .evrail-head {
          position:absolute; bottom:12px; right:12px; left:12px; z-index:2;
          display:flex; align-items:center; gap:10px; color:#fff;
        }
        .evrail-icon {
          width:38px; height:38px; border-radius:10px;
          background:rgba(255,255,255,.96); color:#1a1a2e;
          display:flex; align-items:center; justify-content:center;
          font-size:20px; flex-shrink:0;
          box-shadow:0 3px 10px rgba(0,0,0,.3);
        }
        .evrail-name {
          font-size:16px; font-weight:900; line-height:1.3;
          text-shadow:0 2px 5px rgba(0,0,0,.4);
        }
        .evrail-body { padding:14px 14px 12px; flex:1; display:flex; flex-direction:column; }
        .evrail-latest { list-style:none; padding:0; margin:0 0 10px; flex:1; }
        .evrail-latest li {
          display:flex; gap:8px; align-items:flex-start;
          padding:8px 0; border-bottom:1px dashed rgba(0,0,0,.07);
          font-size:13px; line-height:1.55;
        }
        .evrail-latest li:last-child { border-bottom:none; }
        .evrail-latest .bullet {
          width:6px; height:6px; border-radius:50%; margin-top:7px; flex-shrink:0;
        }
        .evrail-latest .txt { flex:1; color:#1a1a2e; font-weight:600; }
        .evrail-foot {
          margin-top:auto; padding-top:10px; border-top:1px solid #e0e3e8;
          display:flex; align-items:center; justify-content:space-between;
          font-size:11.5px; color:#6b7280;
        }
        .evrail-foot b { color:#1a1a2e; font-weight:800; }
        @media(max-width:640px) {
          .evrail-card { grid-template-columns:1fr; }
          .evrail-cover { min-height:160px; }
        }
      </style>
    <?php endif; ?>

    <!-- BREAKING NEWS -->
    <div id="breaking" class="section-header">
      <div class="section-title"><div class="line" style="background:var(--red)"></div>🔴 أخبار عاجلة</div>
      <a class="see-all" href="category.php?type=breaking">عرض الكل ›</a>
    </div>
    <div class="bn-grid">
      <?php foreach ($breakingNews as $article): ?>
        <a class="bn-card" href="<?php echo articleUrl($article); ?>">
          <div class="bn-thumb">
            <img src="<?php echo e($article['image_url'] ?? placeholderImage(200,150)); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async">
          </div>
          <div class="bn-body">
            <span class="bn-badge"><span class="bn-dot"></span>عاجل</span>
            <?php echo renderClusterBadge($article); if (function_exists('renderTimelineBadge')) echo renderTimelineBadge($article); ?>
            <div class="bn-title"><?php echo e($article['title']); ?></div>
            <div class="bn-meta">
              <span class="bn-source"><?php echo e($article['source_name']); ?></span>
              <span class="bn-sep"></span>
              <span><?php echo timeAgo($article['published_at']); ?></span>
            </div>
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
    <!-- TELEGRAM BREAKING NEWS -->
    <div class="section-header">
      <div class="section-title"><div class="line" style="background:#229ED9"></div>📢 أخبار من تيليغرام</div>
      <a class="see-all" href="telegram.php">عرض الكل ›</a>
    </div>
    <?php
    $tgLatestId = 0;
    foreach ($tgMsgs as $__m) { if ((int)$__m['id'] > $tgLatestId) $tgLatestId = (int)$__m['id']; }
    ?>
    <div class="tg-breaking" style="margin-bottom:28px" data-latest-id="<?php echo (int)$tgLatestId; ?>" data-page="1">
      <?php foreach ($tgMsgs as $m): ?>
        <a href="<?php echo e($m['post_url']); ?>" target="_blank" rel="noopener" class="tg-card" data-tg-id="<?php echo (int)$m['id']; ?>">
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

    <!-- POLITICAL NEWS -->
    <div id="political" class="section-header">
      <div class="section-title"><div class="line" style="background:#b05a5a"></div>🏛 أخبار سياسية</div>
      <a class="see-all" href="category/political">عرض الكل ›</a>
    </div>
    <div class="news-rows" style="margin-bottom:28px">
      <?php foreach ($politicalNews as $article): ?>
        <div class="news-card">
          <a class="news-card-link" href="<?php echo articleUrl($article); ?>">
            <div class="card-img"><img src="<?php echo e($article['image_url'] ?? placeholderImage(400,300)); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
            <div class="card-body">
              <span class="card-cat cat-political">سياسة</span>
              <?php echo renderClusterBadge($article); if (function_exists('renderTimelineBadge')) echo renderTimelineBadge($article); ?>
              <div class="card-title"><?php echo e($article['title']); ?></div>
              <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
              <div class="card-meta">
                <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span><?php echo e($article['source_name']); ?></div>
                <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
              </div>
            </div>
          </a>
          <?php include __DIR__ . '/includes/components/action_bar.php'; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- ECONOMY -->
    <div id="economy" class="section-header">
      <div class="section-title green"><div class="line"></div>💹 أخبار اقتصادية</div>
      <a class="see-all" href="category/economy">عرض الكل ›</a>
    </div>
    <div class="news-rows" style="margin-bottom:28px">
      <?php foreach ($economyNews as $article): ?>
        <div class="news-card">
          <a class="news-card-link" href="<?php echo articleUrl($article); ?>">
            <div class="card-img"><img src="<?php echo e($article['image_url'] ?? placeholderImage(400,300)); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
            <div class="card-body">
              <span class="card-cat cat-economic">اقتصاد</span>
              <?php echo renderClusterBadge($article); if (function_exists('renderTimelineBadge')) echo renderTimelineBadge($article); ?>
              <div class="card-title"><?php echo e($article['title']); ?></div>
              <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
              <div class="card-meta">
                <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#85c1a3'); ?>"></span><?php echo e($article['source_name']); ?></div>
                <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
              </div>
            </div>
          </a>
          <?php include __DIR__ . '/includes/components/action_bar.php'; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- SPORTS -->
    <div id="sports" class="section-header">
      <div class="section-title"><div class="line" style="background:#5a85b0"></div>⚽ رياضة</div>
      <a class="see-all" href="category/sports">عرض الكل ›</a>
    </div>
    <div class="news-rows" style="margin-bottom:28px">
      <?php foreach ($sportsNews as $article): ?>
        <div class="news-card">
          <a class="news-card-link" href="<?php echo articleUrl($article); ?>">
            <div class="card-img"><img src="<?php echo e($article['image_url'] ?? placeholderImage(400,300)); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
            <div class="card-body">
              <span class="card-cat cat-sports">رياضة</span>
              <?php echo renderClusterBadge($article); if (function_exists('renderTimelineBadge')) echo renderTimelineBadge($article); ?>
              <div class="card-title"><?php echo e($article['title']); ?></div>
              <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
              <div class="card-meta">
                <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span><?php echo e($article['source_name']); ?></div>
                <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
              </div>
            </div>
          </a>
          <?php include __DIR__ . '/includes/components/action_bar.php'; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- ARTS -->
    <div id="arts" class="section-header">
      <div class="section-title"><div class="line" style="background:#7a5a9a"></div>🎨 فنون وثقافة</div>
      <a class="see-all" href="category/arts">عرض الكل ›</a>
    </div>
    <div class="news-rows" style="margin-bottom:28px">
      <?php foreach ($artsNews as $article): ?>
        <div class="news-card">
          <a class="news-card-link" href="<?php echo articleUrl($article); ?>">
            <div class="card-img"><img src="<?php echo e($article['image_url'] ?? placeholderImage(400,300)); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
            <div class="card-body">
              <span class="card-cat cat-arts">فنون</span>
              <?php echo renderClusterBadge($article); if (function_exists('renderTimelineBadge')) echo renderTimelineBadge($article); ?>
              <div class="card-title"><?php echo e($article['title']); ?></div>
              <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
              <div class="card-meta">
                <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#a08cc8'); ?>"></span><?php echo e($article['source_name']); ?></div>
                <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
              </div>
            </div>
          </a>
          <?php include __DIR__ . '/includes/components/action_bar.php'; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- REPORTS -->
    <div id="reports" class="section-header">
      <div class="section-title gold"><div class="line"></div>📊 التقارير</div>
      <a class="see-all" href="category/reports">عرض الكل ›</a>
    </div>
    <div class="news-rows" style="margin-bottom:28px">
      <?php foreach ($reportsNews as $article): ?>
        <div class="news-card">
          <a class="news-card-link" href="<?php echo articleUrl($article); ?>">
            <div class="card-img"><img src="<?php echo e($article['image_url'] ?? placeholderImage(400,300)); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
            <div class="card-body">
              <span class="card-cat cat-reports">تقرير</span>
              <?php echo renderClusterBadge($article); if (function_exists('renderTimelineBadge')) echo renderTimelineBadge($article); ?>
              <div class="card-title"><?php echo e($article['title']); ?></div>
              <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
              <div class="card-meta">
                <div class="card-source"><span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#c9ab6e'); ?>"></span><?php echo e($article['source_name']); ?></div>
                <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
              </div>
            </div>
          </a>
          <?php include __DIR__ . '/includes/components/action_bar.php'; ?>
        </div>
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
          <?php
            // Click-to-load reel: we only ship a lightweight thumbnail
            // + play button in the initial HTML. The Instagram iframe
            // is injected on click so the ~8 embeds below the fold
            // don't each fetch Instagram's embed SDK on page load.
            $reelThumb = $reel['thumbnail_url'] ?? '';
            if (!$reelThumb) $reelThumb = placeholderImage(300, 533);
          ?>
          <div class="reel-card reel-card-lazy"
               data-reel-shortcode="<?php echo e($reel['shortcode']); ?>"
               style="width:300px!important;min-width:300px!important;max-width:300px!important;height:533px!important;min-height:533px!important;max-height:533px!important;overflow:hidden!important;position:relative!important;background:#000;border-radius:18px;flex:0 0 300px;align-self:flex-start;cursor:pointer;"
               title="<?php echo e($reel['caption'] ?? ''); ?>">
            <img src="<?php echo e($reelThumb); ?>" alt="<?php echo e($reel['caption'] ?? ''); ?>" loading="lazy" decoding="async"
                 style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
            <div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,0) 0%,rgba(0,0,0,0) 55%,rgba(0,0,0,.75) 100%);pointer-events:none;"></div>
            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:64px;height:64px;border-radius:50%;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;backdrop-filter:blur(4px);">▶</div>
            <div style="position:absolute;left:12px;right:12px;bottom:10px;color:#fff;font-size:12px;font-weight:700;text-shadow:0 1px 3px rgba(0,0,0,.8);display:flex;align-items:center;gap:6px;">
              <span style="background:linear-gradient(135deg,#fd1d1d,#833ab4);padding:2px 6px;border-radius:4px;">Reel</span>
              <?php if (!empty($reel['display_name'])): ?><span>@<?php echo e($reel['username'] ?? ''); ?></span><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- MOST READ / TRENDING TOPICS / HOTTEST RIGHT NOW — tabbed -->
    <?php if (!empty($mostRead) || !empty($trends) || !empty($trendingNow)): ?>
    <div id="trending" class="mr2-section">
      <div class="mr2-head">
        <div class="mr2-tabs">
          <button type="button" class="mr2-tab active" data-mr2-tab="read">
            <span class="mr2-tab-icon">👁</span> الأكثر قراءة
          </button>
          <button type="button" class="mr2-tab" data-mr2-tab="trend">
            <span class="mr2-tab-icon">🔥</span> مواضيع شائعة
          </button>
          <?php if (!empty($trendingNow)): ?>
          <button type="button" class="mr2-tab mr2-tab-velocity" data-mr2-tab="velocity">
            <span class="mr2-tab-icon">⚡</span> الأكثر تداولاً الآن
            <span class="mr2-live-dot" aria-hidden="true"></span>
          </button>
          <?php endif; ?>
        </div>
        <div class="mr2-range" data-mr2-range>
          <label class="mr2-range-opt active"><input type="radio" name="mr2range" value="day" checked><span>اليوم</span></label>
          <label class="mr2-range-opt"><input type="radio" name="mr2range" value="week"><span>الأسبوع</span></label>
          <label class="mr2-range-opt"><input type="radio" name="mr2range" value="month"><span>الشهر</span></label>
        </div>
      </div>
      <div class="mr2-desc" data-mr2-desc="read">
        تم اختيار مواضيع «نيوزفلو» الأكثر قراءة بناءً على إجمالي عدد المشاهدات اليومية. اقرأ المواضيع الأكثر شعبية كل يوم من هنا.
      </div>
      <div class="mr2-desc" data-mr2-desc="trend" hidden>
        أكثر المواضيع تداولاً على منصات التواصل خلال الساعات الأخيرة.
      </div>
      <?php if (!empty($trendingNow)): ?>
      <div class="mr2-desc" data-mr2-desc="velocity" hidden>
        أخبار ترتفع قراءاتها بسرعة <b>الآن</b> — مرتبة بدرجة السرعة (آخر ساعة × 4 + آخر 6 ساعات).
        <?php if ($trendingReaders > 0): ?>
          · <b style="color:#dc2626;"><?php echo number_format($trendingReaders); ?></b> يقرأ الآن
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($mostRead)): ?>
      <div class="mr2-grid" data-mr2-panel="read">
        <?php $rankNum = 1; foreach (array_slice($mostRead, 0, 6) as $article): ?>
          <a class="mr2-item" href="<?php echo articleUrl($article); ?>">
            <div class="mr2-rank"><?php echo $rankNum; ?></div>
            <div class="mr2-body">
              <div class="mr2-title"><?php echo e($article['title']); ?></div>
              <div class="mr2-meta">
                <?php if (!empty($article['cat_name'] ?? $article['source_name'] ?? '')): ?>
                  <span class="mr2-cat"><?php echo e($article['cat_name'] ?? $article['source_name']); ?></span>
                <?php endif; ?>
                <span class="mr2-views">👁 <?php echo number_format((int)$article['view_count']); ?> مشاهدة</span>
              </div>
            </div>
            <?php if (!empty($article['image_url'])): ?>
              <div class="mr2-thumb"><img src="<?php echo e($article['image_url']); ?>" alt="" loading="lazy" decoding="async"></div>
            <?php else: ?>
              <div class="mr2-thumb mr2-thumb-ph"></div>
            <?php endif; ?>
          </a>
          <?php $rankNum++; endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($trends)): ?>
      <div class="mr2-grid" data-mr2-panel="trend" hidden>
        <?php $trendNum = 1; foreach (array_slice($trends, 0, 6) as $trend): ?>
          <div class="mr2-item mr2-item-trend">
            <div class="mr2-rank"><?php echo $trendNum; ?></div>
            <div class="mr2-body">
              <div class="mr2-title"><?php echo e($trend['title']); ?></div>
              <div class="mr2-meta">
                <span class="mr2-cat">رائج</span>
                <span class="mr2-views">🔥 <?php echo number_format((int)$trend['tweet_count']); ?> تغريدة</span>
              </div>
            </div>
            <div class="mr2-thumb mr2-thumb-trend">🔥</div>
          </div>
          <?php $trendNum++; endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($trendingNow)): ?>
      <div class="mr2-grid" data-mr2-panel="velocity" hidden>
        <?php $vNum = 1; foreach (array_slice($trendingNow, 0, 6) as $__t):
            $__ck = (string)($__t['cluster_key'] ?? '');
            $__hasCluster = ($__ck !== '' && $__ck !== '-' && (int)$__t['cluster_size'] > 1);
            $__href = $__hasCluster ? ('cluster.php?key=' . urlencode($__ck)) : articleUrl($__t);
            $__velocity = (int)$__t['velocity_score'];
            $__vh       = (int)$__t['views_last_hour'];
        ?>
          <a class="mr2-item mr2-item-velocity" href="<?php echo e($__href); ?>">
            <div class="mr2-rank mr2-rank-hot"><?php echo $vNum; ?></div>
            <div class="mr2-body">
              <div class="mr2-title"><?php echo e($__t['title']); ?></div>
              <div class="mr2-meta">
                <?php if (!empty($__t['cat_name'])): ?>
                  <span class="mr2-cat"><?php echo e($__t['cat_name']); ?></span>
                <?php endif; ?>
                <span class="mr2-views" style="color:#dc2626;font-weight:800;">⚡ <?php echo number_format($__velocity); ?></span>
                <?php if ($__vh > 0): ?>
                  <span class="mr2-views">⏱ <?php echo number_format($__vh); ?>/ساعة</span>
                <?php endif; ?>
                <?php if ($__hasCluster): ?>
                  <span class="mr2-views">📰 <?php echo (int)$__t['cluster_size']; ?> مصادر</span>
                <?php endif; ?>
              </div>
            </div>
            <?php if (!empty($__t['image_url'])): ?>
              <div class="mr2-thumb"><img src="<?php echo e($__t['image_url']); ?>" alt="" loading="lazy" decoding="async"></div>
            <?php else: ?>
              <div class="mr2-thumb mr2-thumb-trend">⚡</div>
            <?php endif; ?>
          </a>
          <?php $vNum++; endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div><!-- /main-col -->
</div><!-- /main-layout -->

<!-- FOOTER -->
<footer>
  <!-- NEWSLETTER SIGNUP -->
  <div class="newsletter-band">
    <div class="newsletter-inner">
      <div class="newsletter-text">
        <div class="newsletter-eyebrow">📬 نشرة يومية بالبريد</div>
        <h3 class="newsletter-title">أهم الأخبار في صندوقك كل صباح</h3>
        <p class="newsletter-desc">ملخّص ذكي لأبرز الأخبار والتحليلات يصلك يوميًا — مجاني، وبدون إزعاج.</p>
      </div>
      <form class="newsletter-form" id="newsletterForm" onsubmit="return nfSubscribeNewsletter(event)">
        <input type="email" name="email" id="newsletterEmail" placeholder="بريدك الإلكتروني" required dir="ltr">
        <input type="hidden" name="_csrf" value="<?php echo csrf_token(); ?>">
        <button type="submit" id="newsletterBtn">اشترك الآن</button>
      </form>
      <div class="newsletter-msg" id="newsletterMsg" role="status" aria-live="polite"></div>
    </div>
  </div>
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

  function scrollToSection(id) {
    const el = document.getElementById(id);
    if(el) el.scrollIntoView({ behavior:'smooth', block:'start' });
  }

  // Homepage section pills
  function scrollToHomeSection(pill, id) {
    document.querySelectorAll('.sec-pill').forEach(p => p.classList.toggle('active', p === pill));
    // Clear any prior filter mode whenever any pill is clicked. Only the
    // "خاص بك" pill re-enables it below; every other pill (including "الكل")
    // should show the full homepage.
    document.body.classList.remove('home-filter-foryou');

    if (id === 'all') {
      window.scrollTo({ top:0, behavior:'smooth' });
      return;
    }
    if (id === 'foryou') {
      // "For You" acts as a real filter: hide the rest of the page so the
      // reader can focus on their personalized picks. Clicking any other
      // pill (or "الكل") restores the full homepage.
      document.body.classList.add('home-filter-foryou');
      window.scrollTo({ top:0, behavior:'smooth' });
      return;
    }
    const el = document.getElementById(id);
    if (el) {
      const y = el.getBoundingClientRect().top + window.pageYOffset - 80;
      window.scrollTo({ top:y, behavior:'smooth' });
    }
  }

  // LIVE CLOCK (24h)
  setInterval(() => {
    const now = new Date();
    const h = String(now.getHours()).padStart(2,'0');
    const m = String(now.getMinutes()).padStart(2,'0');
    const el = document.getElementById('liveTime');
    if(el) el.textContent = h + ':' + m;
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
    // Guests (or zero-count state) have no .notif-badge in the DOM —
    // bail early instead of crashing with a null deref.
    const badge = document.querySelector('.notif-badge');
    if (!badge) return;
    if (Math.random() > 0.7) {
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

<!-- WEATHER MODAL -->
<div class="modal-overlay" id="weatherModal">
  <div class="modal-box">
    <div class="modal-header">
      <h2>☀️ الطقس الآن</h2>
      <button class="modal-close" onclick="closeWeatherModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="weather-widget weather-widget-modal">
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
    </div>
  </div>
</div>

<!-- SOURCES MODAL -->
<div class="modal-overlay" id="sourcesModal">
  <div class="modal-box">
    <div class="modal-header">
      <h2>🌐 المصادر النشطة</h2>
      <button class="modal-close" onclick="closeSourcesModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="modal-sources-list">
        <?php foreach (array_slice($sources, 0, 12) as $source): ?>
          <div class="source-item">
            <div class="source-logo" style="background:<?php echo e($source['logo_bg']); ?>;color:<?php echo e($source['logo_color']); ?>"><?php echo e($source['logo_letter']); ?></div>
            <div class="source-info">
              <div class="source-name"><?php echo e($source['name']); ?></div>
              <div class="source-count"><?php echo rand(50, 300); ?> خبر اليوم</div>
            </div>
            <div class="source-toggle" onclick="this.classList.toggle('off')"></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($viewerId): ?>
        <a href="me/sources.php" class="modal-sources-all">إدارة المصادر ›</a>
      <?php else: ?>
        <a href="account/register.php" class="modal-sources-all">سجّل لإدارة مصادرك ›</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="nf-toast" id="nfToast"></div>
<script src="assets/js/home.min.js?v=m2" defer></script>
<script src="assets/js/user.min.js?v=m1" defer></script>
<script>
// Footer newsletter signup — simple fetch + status feedback.
function nfSubscribeNewsletter(e) {
  e.preventDefault();
  var form = document.getElementById('newsletterForm');
  var btn  = document.getElementById('newsletterBtn');
  var msg  = document.getElementById('newsletterMsg');
  var fd   = new FormData(form);
  msg.textContent = ''; msg.className = 'newsletter-msg';
  btn.disabled = true; btn.textContent = 'جاري الإرسال...';
  fetch('api/newsletter_subscribe.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function(r){ return r.json().catch(function(){ return { ok:false, error:'bad_response' }; }); })
    .then(function(j){
      btn.disabled = false; btn.textContent = 'اشترك الآن';
      if (j && j.ok) {
        if (j.already) {
          msg.textContent = '✅ هذا البريد مشترك بالفعل في النشرة';
          msg.className = 'newsletter-msg ok';
        } else {
          msg.textContent = '🎉 أرسلنا لك رسالة تأكيد. تفقّد بريدك (وربما مجلد الرسائل غير المرغوبة).';
          msg.className = 'newsletter-msg ok';
          form.reset();
        }
      } else {
        var err = (j && j.error) || 'error';
        var label = err === 'invalid_email' ? '⚠️ البريد الإلكتروني غير صحيح'
                  : err === 'rate_limited' ? '⏱️ محاولات كثيرة — أعد المحاولة بعد قليل'
                  : err === 'csrf' ? '🔒 جلسة منتهية — حدّث الصفحة وحاول مجددًا'
                  : '😔 تعذّر تسجيل اشتراكك مؤقتًا';
        msg.textContent = label;
        msg.className = 'newsletter-msg err';
      }
    })
    .catch(function(){
      btn.disabled = false; btn.textContent = 'اشترك الآن';
      msg.textContent = '⚠️ خطأ في الاتصال — حاول مرة أخرى';
      msg.className = 'newsletter-msg err';
    });
  return false;
}
</script>

</body>
</html>
