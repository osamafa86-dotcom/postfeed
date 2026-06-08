<?php
/**
 * نيوز فيد - الصفحة الرئيسية
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
require_once __DIR__ . '/includes/view_tracking.php';
require_once __DIR__ . '/includes/auto_fetch.php';

record_page_view('homepage');

// Self-heal: if the cPanel cron has missed runs and the latest source
// fetch is older than 30 minutes, kick cron_rss.php in the background
// once the homepage finishes rendering. Single-flight locked so we
// don't fan out per page view.
auto_trigger_rss_fetch_if_stale(1800);

// The homepage HTML must always reflect the latest deploy/content (data is
// cached server-side via cache_remember). Without this, .htaccess sends a
// 1-day Expires on the HTML, so browsers/CDN serve a stale page and new
// deploys/articles don't show. Revalidate instead.
if (!headers_sent()) {
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

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
// "آخر الأخبار" must only contain content_type='news' so the section
// header matches what it shows — reports and opinion pieces have their
// own dedicated sections below.
$latestArticles = getArticlesByContentType('news', 40);
if (empty($latestArticles)) {
    // First deploy before backfill ran — fall back to mixed latest so
    // the homepage still has a featured row.
    $latestArticles = getLatestArticles(40);
}
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
// Bumped the per-story preview count to 12 (was 6 / display-3) so the
// cluster-dedup step in the accordion template still has enough source
// material to surface 3 *unique* headlines per file. Cache key bumped
// so we don't serve the old 6-article payload from cache.
$evolvingRail = cache_remember('home_evolving_accordion_v2', 300, function() {
    $stories = evolving_stories_with_previews(12);
    return array_slice($stories, 0, 6);
});

// Ticker pulls from the latest Palestine news stream so the "عاجل" strip
// mirrors the Palestine section headlines.
$tickerItems = array_slice($palestineNews, 0, 10);

// إحصائيات
$totalArticles = countArticles();
$totalSources = count($sources);

// Homepage section buckets — six purpose-built feeds, no raw category
// rails any more. The classifier-driven content_type column slices
// each story into the right slot (news vs report vs opinion) and the
// Palestine flag splits the news feed into two non-overlapping rails.
$palestineNewsArticles = getArticlesByPalestineNews(true,  12);  // news + Palestine
$arabIntlArticles      = getArticlesByPalestineNews(false, 12);  // news without Palestine
$reportsByType         = getArticlesByContentType('report',  12);
$articlesByType        = getArticlesByContentType('article', 12);
$healthArticles        = getArticlesByCategory('health', 12);
$varietyArticles       = getArticlesByCategorySlugs(['sports', 'arts', 'tech', 'media'], 12);

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
$palestineNews  = $dedup($palestineNews, 8);
$breakingNews   = $dedup($breakingNews, 4);
$latestArticles = $dedup($latestArticles, 12);
$palestineNewsArticles = $dedup($palestineNewsArticles, 8);
$arabIntlArticles      = $dedup($arabIntlArticles, 8);
$reportsByType         = $dedup($reportsByType, 8);
$articlesByType        = $dedup($articlesByType, 8);
$healthArticles        = $dedup($healthArticles, 8);
$varietyArticles       = $dedup($varietyArticles, 8);

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
          $palestineNewsArticles, $arabIntlArticles, $reportsByType, $articlesByType,
          $healthArticles, $varietyArticles] as $__list) {
    foreach ($__list as $__a) {
        $__allIds[] = (int)($__a['id'] ?? 0);
        $__ck = (string)($__a['cluster_key'] ?? '');
        if ($__ck !== '' && $__ck !== '-') $__allClusterKeys[] = $__ck;
    }
}
// Pull cluster keys from the evolving stories rail too, so the
// "X مصادر" badge can render on the accordion items (the rail is
// built separately, so its cluster keys aren't in the lists above).
foreach (is_array($evolvingRail ?? null) ? $evolvingRail : [] as $__st) {
    foreach (($__st['latest'] ?? []) as $__a) {
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

// Latest weekly rewind — used to show the Sunday "مراجعة الأسبوع جاهزة"
// banner just under the stats strip. Cached briefly so homepage
// traffic doesn't hammer the rewinds table.
$latestRewind = cache_remember('home_weekly_rewind', 300, function() {
    try {
        require_once __DIR__ . '/includes/weekly_rewind.php';
        return wr_get_latest();
    } catch (Throwable $e) {
        return null;
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
<?php include __DIR__ . '/includes/components/pwa_head.php'; ?>
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
<link rel="stylesheet" href="assets/css/home-redesign.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/home-redesign.css'); ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="assets/css/home-redesign.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/home-redesign.css'); ?>"></noscript>
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<script src="/assets/js/audio-player.js?v=3" defer></script>
<script src="/assets/js/audio-cards.js?v=2" defer></script>
</head>
<body class="nf-redesign">

<!-- TOP UTILITY BAR (redesign — matches Figma top strip) -->
<div class="nfr-topbar"><div class="nfr-topbar-in">
  <div class="nfr-tb-right"><span class="nfr-tb-live"><span class="d"></span>تحديث مباشر</span><span class="nfr-tb-dot">·</span><span class="nfr-tb-date"><?php $__d=['Sunday'=>'الأحد','Monday'=>'الإثنين','Tuesday'=>'الثلاثاء','Wednesday'=>'الأربعاء','Thursday'=>'الخميس','Friday'=>'الجمعة','Saturday'=>'السبت']; echo e(($__d[date('l')] ?? '') . '، ' . date('Y/m/d')); ?></span></div>
  <div class="nfr-tb-left"><a href="#newsletter-band" onclick="document.querySelector('.nfr-news input[type=email]')?.focus();return false;">✉ النشرة البريدية</a><a href="contact.php">تواصل معنا</a><a href="#weather" onclick="if(window.openWeatherModal)openWeatherModal();return false;" class="nfr-tb-weather">🌤 <span id="nfrTopWTemp">--</span>°</a></div>
</div></div>

<?php
// Shared site header (header + main nav + mobile nav + breaking ticker)
$activeType = 'home';
$activeSlug = '';
$showTicker = true;
include __DIR__ . '/includes/components/site_header.php';
?>

<!-- SECTIONS NAV (homepage anchors) — Figma-trimmed set: Home, Daily
     Digest, Platforms, Latest, Palestine. Older slots kept hidden so
     the existing JS targets (foryou / arab-intl / etc.) keep working
     for direct anchor links. Sits directly under the main site nav
     (above the stats strip) so the section pills live in the same
     visual band as the global header. -->
<div class="sections-nav">
  <div class="sections-nav-inner">
    <button type="button" class="sec-pill active" data-sec="all" onclick="scrollToHomeSection(this,'all')"><span class="sec-pill-ico">📰</span>الرئيسية</button>
    <a class="sec-pill" href="/sabah"><span class="sec-pill-ico">☕</span>ملخصات</a>
    <a class="sec-pill" href="/platforms"><span class="sec-pill-ico">📡</span>المنصات</a>
    <button type="button" class="sec-pill" data-sec="latest" onclick="scrollToHomeSection(this,'latest')"><span class="sec-pill-ico">⏱</span>آخر الأخبار</button>
    <button type="button" class="sec-pill" data-sec="palestine" onclick="scrollToHomeSection(this,'palestine')"><span class="sec-pill-ico">🇵🇸</span>أخبار فلسطين</button>
    <?php if ($viewerId && ($personalFeed || $personalShowOnboarding)): ?>
    <button type="button" class="sec-pill sec-pill-foryou sec-pill-secondary" data-sec="foryou" onclick="scrollToHomeSection(this,'foryou')"><span class="sec-pill-ico">✨</span>خاص بك</button>
    <?php endif; ?>
    <a class="sec-pill sec-pill-ask sec-pill-secondary" href="ask.php"><span class="sec-pill-ico">🤖</span>اسأل الأخبار</a>
    <button type="button" class="sec-pill sec-pill-secondary" data-sec="breaking" onclick="scrollToHomeSection(this,'breaking')"><span class="sec-pill-ico">🔴</span>عاجل</button>
    <button type="button" class="sec-pill sec-pill-secondary" data-sec="arab-intl" onclick="scrollToHomeSection(this,'arab-intl')"><span class="sec-pill-ico">🌍</span>عربي ودولي</button>
    <button type="button" class="sec-pill sec-pill-secondary" data-sec="reports" onclick="scrollToHomeSection(this,'reports')"><span class="sec-pill-ico">📑</span>تقارير</button>
    <button type="button" class="sec-pill sec-pill-secondary" data-sec="articles" onclick="scrollToHomeSection(this,'articles')"><span class="sec-pill-ico">✍️</span>مقالات رأي</button>
    <button type="button" class="sec-pill sec-pill-secondary" data-sec="variety" onclick="scrollToHomeSection(this,'variety')"><span class="sec-pill-ico">🎯</span>منوعات</button>
    <button type="button" class="sec-pill sec-pill-secondary" data-sec="health" onclick="scrollToHomeSection(this,'health')"><span class="sec-pill-ico">🏥</span>صحة</button>
    <button type="button" class="sec-pill sec-pill-secondary" data-sec="trending" onclick="scrollToHomeSection(this,'trending')"><span class="sec-pill-ico">🔥</span>الأكثر تداولاً</button>
    <button type="button" class="sec-pill sec-pill-secondary" data-sec="reels" onclick="scrollToHomeSection(this,'reels')"><span class="sec-pill-ico">🎬</span>ريلز</button>
  </div>
</div>

<!-- STATS STRIP (Figma 4-chip layout: sources / today / cadence / trends) -->
<?php
// Count today's articles for the "خبراً اليوم" chip. Cached lightly so
// the homepage doesn't run an extra COUNT() per request.
$todayCount = cache_remember('home_today_articles_count', 120, function() {
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT COUNT(*) FROM articles WHERE DATE(published_at) = CURDATE()");
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
});
$trendsCount = is_array($trends) ? count($trends) : 0;
?>
<div class="stats-strip">
  <div class="stats-strip-inner">
    <span class="stat-chip stat-chip-blue"><span class="stat-chip-ico">📑</span><b><?php echo number_format($todayCount); ?></b><em>خبراً اليوم</em></span>
    <span class="stat-chip stat-chip-teal"><span class="stat-chip-ico">🗂</span><b><?php echo number_format($totalSources); ?></b><em>مصدراً موثوقاً</em></span>
    <span class="stat-chip stat-chip-purple"><span class="stat-chip-ico">⏱</span><b>كل 5 دقائق</b><em>تحديث مباشر</em></span>
    <?php if ($trendsCount > 0): ?>
    <span class="stat-chip stat-chip-orange"><span class="stat-chip-ico">📈</span><b><?php echo $trendsCount; ?></b><em>مواضيع رائجة</em></span>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($latestRewind) && !empty($latestRewind['year_week'])):
    // Banner is dismissible per-rewind; client-side JS checks localStorage
    // keyed by year_week so the user only has to dismiss each week once.
    $__wrYw = e($latestRewind['year_week']);
    $__wrTitle = e($latestRewind['cover_title'] ?: 'مراجعة الأسبوع جاهزة');
    $__wrSub   = e($latestRewind['cover_subtitle'] ?: 'ملخص أسبوعي لأبرز ما جرى، بقلم هيئة تحرير الذكاء الاصطناعي.');
?>
<style>
  .wr-banner { position: relative; max-width: 1400px; margin: 12px auto 0; padding: 0 20px; }
  .wr-banner[hidden] { display: none !important; }
  .wr-banner-link { display: flex; align-items: center; gap: 14px; padding: 14px 18px;
    background: linear-gradient(135deg, #1E1F18 0%, #282A20 100%); color: #fff;
    border-radius: 14px; text-decoration: none; box-shadow: 0 10px 28px -12px rgba(13, 148, 136, 0.6);
    border: 1px solid rgba(245, 158, 11, 0.35); }
  .wr-banner-ico { flex: 0 0 48px; width: 48px; height: 48px; display: inline-flex;
    align-items: center; justify-content: center; font-size: 26px; background: rgba(245, 158, 11, 0.95);
    color: #2C2416; border-radius: 12px; }
  .wr-banner-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
  .wr-banner-body strong { font-size: 15px; font-weight: 800; line-height: 1.35;
    color: #fff; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .wr-banner-body em { font-size: 13px; font-style: normal; color: rgba(255,255,255,0.82);
    line-height: 1.55; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .wr-banner-cta { flex: 0 0 auto; font-size: 13px; font-weight: 800;
    background: #C99624; color: #2C2416; padding: 8px 16px; border-radius: 8px; white-space: nowrap; }
  .wr-banner-x { position: absolute; top: 4px; left: 4px; background: transparent; color: rgba(255,255,255,0.7);
    border: 0; font-size: 22px; width: 32px; height: 32px; cursor: pointer; line-height: 1; border-radius: 8px; }
  .wr-banner-x:hover { background: rgba(255,255,255,0.1); color: #fff; }
  @media (max-width: 640px) {
    .wr-banner { padding: 0 12px; }
    .wr-banner-link { padding: 12px 14px; gap: 10px; }
    .wr-banner-ico { flex: 0 0 40px; width: 40px; height: 40px; font-size: 22px; }
    .wr-banner-body strong { font-size: 13.5px; }
    .wr-banner-body em { font-size: 12px; }
    .wr-banner-cta { display: none; }
  }
  /* Homepage content-type sections (تقارير / مقالات / صحة / منوعات) — */
  /* a 4-column grid of compact horizontal cards reusing nf-side-card. */
  .ct-section { max-width: 1400px; margin: 36px auto 0; padding: 0 20px; scroll-margin-top: 90px; }
  .ct-section > .section-header { display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid #eaeae0; }
  .ct-section > .section-header .section-title { font-size: 20px; font-weight: 800;
    color: #1B2517; display: flex; align-items: center; gap: 10px; }
  .ct-section > .section-header .section-title .line { width: 4px; height: 22px; border-radius: 2px; }
  .ct-section > .section-header .see-all { font-size: 13px; color: #5a6a3e; text-decoration: none;
    font-weight: 700; padding: 6px 10px; border-radius: 8px; transition: background 0.15s; }
  .ct-section > .section-header .see-all:hover { background: #f3f0e6; }
  .ct-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
  @media (max-width: 1100px) { .ct-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 560px)  { .ct-grid { grid-template-columns: 1fr; } }
</style>
<div id="wrBanner" class="wr-banner" data-yw="<?php echo $__wrYw; ?>" hidden>
  <a class="wr-banner-link" href="/weekly/<?php echo $__wrYw; ?>">
    <span class="wr-banner-ico">📅</span>
    <span class="wr-banner-body">
      <strong>مراجعة الأسبوع: <?php echo $__wrTitle; ?></strong>
      <em><?php echo $__wrSub; ?></em>
    </span>
    <span class="wr-banner-cta">اقرأ ←</span>
  </a>
  <button type="button" class="wr-banner-x" aria-label="إغلاق" onclick="wrBannerDismiss()">×</button>
</div>
<script>
(function(){
  var el = document.getElementById('wrBanner');
  if (!el) return;
  var yw = el.getAttribute('data-yw');
  var key = 'wr_dismissed_' + yw;
  try { if (localStorage.getItem(key)) return; } catch(e){}
  el.hidden = false;
  window.wrBannerDismiss = function(){
    try { localStorage.setItem(key, String(Date.now())); } catch(e){}
    el.hidden = true;
  };
})();
</script>
<?php endif; ?>


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
                <span class="src-dot" style="background:<?php echo e($pf['logo_color'] ?? '#3D5A28'); ?>"><?php echo e(mb_substr($pf['source_name'], 0, 1)); ?></span>
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

<!-- LATEST NEWS (Featured 1 main + 2 side layout) — full-width above main-layout -->
<?php
// Figma layout: 1 hero (visual-left) + 2 stacked side cards (visual-right).
// In RTL the DOM-first column renders on the right, so we list the side
// cards before the main feature. Remainder spills into the main grid below.
$__featMain  = $latestArticles[0] ?? null;
$__featSide  = array_slice($latestArticles, 1, 2);
$__featGrid  = array_slice($latestArticles, 3, 6);
?>
<?php if ($__featMain): ?>
<div class="nf-feature-container">
  <div class="nf-feature-wrap">
    <!-- Side cards (2 stacked) — DOM-first → renders on the right in RTL -->
    <div class="nf-feature-side">
      <?php foreach ($__featSide as $article): ?>
        <?php include __DIR__ . '/includes/components/home_feature_side.php'; ?>
      <?php endforeach; ?>
    </div>
    <!-- Center featured -->
    <div id="latest" class="nf-feature-main">
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
  </div>
</div>
<?php endif; ?>

<?php if (!empty($__featGrid)): ?>
<!-- آخر الأخبار 3×2 grid (matches Figma) -->
<section class="nf-latest-section">
  <div class="section-header">
    <div class="section-title blue"><div class="line"></div>⏱ آخر الأخبار</div>
    <a class="see-all" href="category.php?type=latest">عرض الكل ›</a>
  </div>
  <div class="nf-latest-grid">
    <?php foreach ($__featGrid as $article): ?>
      <?php include __DIR__ . '/includes/components/home_latest_card.php'; ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php
// Reusable content-type section: anchor id, title, accent color, icon,
// articles array, and the "see all" target. Each section reuses the
// nf-side-card layout that already powers the featured 3-column area.
$__renderCtSection = function(string $id, string $title, string $color, string $ico, array $articles, string $seeAll) {
    if (empty($articles)) return;
    ?>
    <section class="ct-section" id="<?php echo e($id); ?>">
      <div class="section-header">
        <div class="section-title"><span class="line" style="background:<?php echo e($color); ?>"></span><?php echo $ico; ?> <?php echo e($title); ?></div>
        <a class="see-all" href="<?php echo e($seeAll); ?>">عرض الكل ›</a>
      </div>
      <div class="ct-grid">
        <?php foreach (array_slice($articles, 0, 8) as $article): ?>
          <?php include __DIR__ . '/includes/components/home_feature_side.php'; ?>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
};

$__renderCtSection('palestine-news', 'أخبار فلسطين', '#1B7A3D', '🇵🇸', $palestineNewsArticles, 'category.php?type=palestine');
$__renderCtSection('arab-intl',      'عربي ودولي',   '#3c5f8a', '🌍', $arabIntlArticles,      'category.php?type=arab-intl');
$__renderCtSection('reports',        'تقارير',        '#9c5d3b', '📑', $reportsByType,         'category.php?type=report');
$__renderCtSection('articles',       'مقالات رأي',    '#6b4f8f', '✍️', $articlesByType,        'category.php?type=article');
$__renderCtSection('variety',        'منوعات',        '#c9a23e', '🎯', $varietyArticles,       'category.php?type=variety');
$__renderCtSection('health',         'صحة',           '#3b8a6e', '🏥', $healthArticles,        categoryUrl('health'));
?>

<!-- MAIN CONTENT -->
<div class="main-layout">
  <div class="main-col">

    <!-- PALESTINE NEWS (Figma: 2 horizontal cards, image on the right) -->
    <div id="palestine" class="section-header">
      <div class="section-title"><div class="line" style="background:#1B7A3D"></div>🇵🇸 أحدث الأخبار الفلسطينية</div>
      <a class="see-all" href="category.php?type=palestine">عرض الكل ›</a>
    </div>
    <?php if (!empty($palestineNews)): ?>
      <div class="nf-ps-grid">
        <?php foreach (array_slice($palestineNews, 0, 8) as $article): ?>
          <a class="nf-ps-card" href="<?php echo articleUrl($article); ?>">
            <div class="nf-ps-card-body">
              <?php if (!empty($article['cat_name'])): ?>
                <span class="nf-ps-card-cat"><?php echo e($article['cat_name']); ?></span>
              <?php endif; ?>
              <h3 class="nf-ps-card-title"><?php echo e($article['title']); ?></h3>
              <div class="nf-ps-card-badges">
                <?php echo renderClusterBadge($article); ?>
                <?php if (function_exists('renderTimelineBadge')) echo renderTimelineBadge($article); ?>
              </div>
              <div class="nf-ps-card-foot">
                <?php if (!empty($article['source_name'])): ?>
                  <span class="nf-ps-card-source">
                    <span class="src-dot" style="background:<?php echo e($article['logo_color'] ?? '#1B7A3D'); ?>"></span>
                    <?php echo e($article['source_name']); ?>
                  </span>
                <?php endif; ?>
                <span class="nf-ps-card-time"><?php echo timeAgo($article['published_at']); ?></span>
              </div>
            </div>
            <div class="nf-ps-card-img">
              <img src="<?php echo e($article['image_url'] ?? placeholderImage(400, 300)); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async">
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- EVOLVING STORIES RAIL — admin-curated persistent topics -->
    <?php if (!empty($evolvingRail)): ?>
      <div id="evolving-rail" class="section-header">
        <div class="section-title"><div class="line" style="background:#B8860B"></div>📅 قصص متطوّرة — متابعة دائمة</div>
        <a class="see-all" href="/evolving-stories">عرض الكل ›</a>
      </div>
      <div class="ev-acc" data-ev-acc>
        <?php foreach ($evolvingRail as $__i => $st):
          $sUrl   = evolving_story_url($st);
          $color  = $st['accent_color'] ?: '#3D5A28';
          $sCover = !empty($st['cover_image']) ? $st['cover_image'] : placeholderImage(400, 260);
          $__open = ($__i === 0);
          $__live = (!empty($st['last_matched_at']) && strtotime($st['last_matched_at']) > (time() - 7200));
        ?>
          <div class="ev-item<?php echo $__open ? ' open' : ''; ?>" data-ev-item>
            <button type="button" class="ev-head" data-ev-toggle aria-expanded="<?php echo $__open ? 'true' : 'false'; ?>">
              <span class="ev-head-right">
                <span class="ev-diamond" style="background:<?php echo e($color); ?>"></span>
                <span class="ev-name"><?php echo e($st['name']); ?></span>
              </span>
              <span class="ev-head-left">
                <span class="ev-count"><span class="ev-cdot" style="background:<?php echo e($color); ?>"></span><b><?php echo number_format($st['article_count']); ?></b> تقرير</span>
                <span class="ev-chev" aria-hidden="true">&#9662;</span>
              </span>
            </button>
            <div class="ev-panel">
              <div class="ev-panel-inner">
                <div class="ev-thumb" style="background-image:url('<?php echo e($sCover); ?>');">
                  <?php if ($__live): ?><span class="ev-live"><span class="dot"></span>مباشر</span><?php endif; ?>
                  <span class="ev-thumb-name"><?php echo e($st['name']); ?></span>
                </div>
                <div class="ev-content">
                  <div class="ev-readlabel">نقرأ في هذا الملف:</div>
                  <?php if (!empty($st['latest'])): ?>
                  <?php
                  // Two-layer dedupe so the same story doesn't appear
                  // twice in a file:
                  //
                  //   1) By cluster_key — collapses articles the
                  //      clustering pipeline already grouped.
                  //   2) By a normalized title prefix — catches the
                  //      "نفس الحدث برواية مختلفة" case where two
                  //      outlets phrased the headline slightly
                  //      differently and the clusterer didn't merge
                  //      them (the screenshot the user sent — two
                  //      "مستوطنون يهاجمون منازل" headlines from
                  //      neighboring villages).
                  //
                  // First occurrence wins; the badge below tells the
                  // user how many sources are folded into it.
                  $__seenCluster = [];
                  $__seenTitle   = [];
                  $__evLinks = [];
                  foreach ($st['latest'] as $__la) {
                      $__ck = isset($__la['cluster_key']) ? (string)$__la['cluster_key'] : '';
                      $__key = ($__ck !== '' && $__ck !== '-') ? 'c:' . $__ck : 'a:' . ($__la['id'] ?? '');
                      if (isset($__seenCluster[$__key])) continue;
                      // Normalized title prefix: strip punctuation +
                      // diacritics, collapse whitespace, take first
                      // 35 chars. Catches "يهاجمون" vs "يُهاجمون".
                      $__norm = (string)($__la['title'] ?? '');
                      $__norm = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $__norm); // tashkeel
                      $__norm = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $__norm);
                      $__norm = trim(preg_replace('/\s+/u', ' ', $__norm));
                      $__titleKey = mb_substr($__norm, 0, 35);
                      if ($__titleKey !== '' && isset($__seenTitle[$__titleKey])) continue;

                      $__seenCluster[$__key] = true;
                      if ($__titleKey !== '') $__seenTitle[$__titleKey] = true;
                      $__evLinks[] = $__la;
                      if (count($__evLinks) >= 3) break;
                  }
                  ?>
                  <ul class="ev-links">
                    <?php foreach ($__evLinks as $la):
                      $__laUrl = !empty($la['id']) ? articleUrl($la) : $sUrl;
                      $__laCK = isset($la['cluster_key']) ? (string)$la['cluster_key'] : '';
                      $__laCnt = ($__laCK !== '' && isset($GLOBALS['__nf_cluster_counts'][$__laCK]))
                                 ? (int)$GLOBALS['__nf_cluster_counts'][$__laCK] : 0;
                      ?>
                      <li>
                        <a href="<?php echo e($__laUrl); ?>">
                          <span class="b" style="background:<?php echo e($color); ?>"></span>
                          <span class="t"><?php echo e(mb_substr((string)$la['title'], 0, 90)); ?></span>
                          <?php if ($__laCnt >= 2): ?>
                            <span class="ev-sources" title="هذا الخبر ورد في <?php echo (int)$__laCnt; ?> مصادر">📰 <?php echo (int)$__laCnt; ?> مصادر</span>
                          <?php endif; ?>
                        </a>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                  <?php endif; ?>
                  <div class="ev-foot">
                    <a class="ev-followbtn" href="<?php echo e($sUrl); ?>">تابع هذا الملف &#8592;</a>
                    <?php if (!empty($st['last_matched_at']) && $st['last_matched_at'] !== '0000-00-00 00:00:00'): ?>
                      <span class="ev-updated">&#8635; آخر تحديث <?php echo e(timeAgo($st['last_matched_at'])); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <script>
      (function(){var acc=document.querySelector('[data-ev-acc]');if(!acc)return;acc.addEventListener('click',function(e){var btn=e.target.closest('[data-ev-toggle]');if(!btn)return;var item=btn.closest('[data-ev-item]');if(!item)return;var was=item.classList.contains('open');acc.querySelectorAll('[data-ev-item].open').forEach(function(x){if(x!==item){x.classList.remove('open');var b=x.querySelector('[data-ev-toggle]');if(b)b.setAttribute('aria-expanded','false');}});item.classList.toggle('open',!was);btn.setAttribute('aria-expanded',String(!was));});})();
      </script>
    <?php endif; ?>

    <!-- BREAKING NEWS -->
    <div id="breaking" class="section-header">
      <div class="section-title"><div class="line" style="background:var(--red)"></div>🔴 أخبار عاجلة <span class="bn-updated" id="bnUpdated" aria-live="polite">منذ لحظات</span></div>
      <div class="bn-actions">
        <button type="button" class="bn-refresh" onclick="nfRefreshBreaking(this)" title="تحديث">🔄 تحديث</button>
        <a class="see-all" href="category.php?type=breaking">عرض الكل ›</a>
      </div>
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
    // Breaking social feed — Telegram + Twitter/X + YouTube in one tabbed
    // section. All lists stay in the DOM (inactive ones are `hidden`) so
    // their live-update scripts keep prepending new rows even while the
    // user is viewing a different tab.
    $tgMsgs = [];
    $twMsgs = [];
    $ytMsgs = [];
    try {
        $socialDb = getDB();
        $tgMsgs = $socialDb->query("SELECT m.*, s.display_name, s.username, s.avatar_url FROM telegram_messages m JOIN telegram_sources s ON m.source_id = s.id WHERE m.is_active=1 AND s.is_active=1 ORDER BY m.posted_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { error_log('tg read: ' . $e->getMessage()); }
    try {
        $socialDb = $socialDb ?? getDB();
        $twMsgs = $socialDb->query("SELECT m.*, s.display_name, s.username, s.avatar_url FROM twitter_messages m JOIN twitter_sources s ON m.source_id = s.id WHERE m.is_active=1 AND s.is_active=1 ORDER BY m.posted_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { error_log('tw read: ' . $e->getMessage()); }
    try {
        $socialDb = $socialDb ?? getDB();
        $ytMsgs = $socialDb->query("SELECT v.*, s.display_name, s.handle, s.avatar_url FROM youtube_videos v JOIN youtube_sources s ON v.source_id = s.id WHERE v.is_active=1 AND s.is_active=1 ORDER BY v.posted_at DESC, v.id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { error_log('yt read: ' . $e->getMessage()); }

    $tgLatestId = 0;
    foreach ($tgMsgs as $__m) { if ((int)$__m['id'] > $tgLatestId) $tgLatestId = (int)$__m['id']; }
    $twLatestId = 0;
    foreach ($twMsgs as $__m) { if ((int)$__m['id'] > $twLatestId) $twLatestId = (int)$__m['id']; }
    $ytLatestId = 0;
    foreach ($ytMsgs as $__m) { if ((int)$__m['id'] > $ytLatestId) $ytLatestId = (int)$__m['id']; }

    // Default tab priority: Telegram > Twitter > YouTube, skipping any
    // that have no content yet (fresh install, no sources, etc.).
    $activeFeed = !empty($tgMsgs) ? 'telegram'
                : (!empty($twMsgs) ? 'twitter'
                : (!empty($ytMsgs) ? 'youtube' : null));
    ?>
    <?php if ($activeFeed !== null): ?>
    <!-- SOCIAL BREAKING (Telegram + Twitter tabs) -->
    <div class="feed-tabs-wrap" data-active="<?php echo $activeFeed; ?>" style="margin-bottom:28px">
      <div class="section-header feed-tabs-header">
        <div class="feed-tabs" role="tablist">
          <?php if (!empty($tgMsgs)): ?>
            <button type="button" class="feed-tab<?php echo $activeFeed==='telegram'?' active':''; ?>" data-tab="telegram" role="tab" aria-selected="<?php echo $activeFeed==='telegram'?'true':'false'; ?>">
              <span class="feed-tab-ico feed-tab-ico-tg" aria-hidden="true">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 7.24l-1.66 7.81c-.12.56-.45.7-.91.44L11.55 15.4l-1.79 1.73c-.2.2-.37.37-.76.37l.27-3.84 6.97-6.3c.3-.27-.07-.42-.47-.16L7.14 12.43l-3.71-1.16c-.8-.25-.82-.8.17-1.19l14.49-5.59c.67-.25 1.26.16 1.04 1.19z"/></svg>
              </span>
              <span class="feed-tab-label"><span class="feed-tab-pre">أخبار </span>تلغرام</span>
            </button>
          <?php endif; ?>
          <?php if (!empty($twMsgs)): ?>
            <button type="button" class="feed-tab<?php echo $activeFeed==='twitter'?' active':''; ?>" data-tab="twitter" role="tab" aria-selected="<?php echo $activeFeed==='twitter'?'true':'false'; ?>">
              <span class="feed-tab-ico feed-tab-ico-x" aria-hidden="true">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.451-6.231zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/></svg>
              </span>
              <span class="feed-tab-label"><span class="feed-tab-pre">أخبار منصة </span>اكس</span>
            </button>
          <?php endif; ?>
          <?php if (!empty($ytMsgs)): ?>
            <button type="button" class="feed-tab<?php echo $activeFeed==='youtube'?' active':''; ?>" data-tab="youtube" role="tab" aria-selected="<?php echo $activeFeed==='youtube'?'true':'false'; ?>">
              <span class="feed-tab-ico feed-tab-ico-yt" aria-hidden="true">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
              </span>
              <span class="feed-tab-label"><span class="feed-tab-pre">أخبار </span>يوتيوب</span>
            </button>
          <?php endif; ?>
        </div>
        <a class="see-all" href="telegram.php" data-see-all<?php echo $activeFeed!=='telegram'?' hidden':''; ?>>عرض الكل ›</a>
      </div>

      <?php if (!empty($tgMsgs)): ?>
      <div class="tg-breaking feed-panel" data-feed-panel="telegram" data-latest-id="<?php echo (int)$tgLatestId; ?>" data-page="1"<?php echo $activeFeed!=='telegram'?' hidden':''; ?>>
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

      <?php if (!empty($twMsgs)): ?>
      <div class="tw-breaking feed-panel" data-feed-panel="twitter" data-latest-id="<?php echo (int)$twLatestId; ?>" data-page="1"<?php echo $activeFeed!=='twitter'?' hidden':''; ?>>
        <?php foreach ($twMsgs as $m): ?>
          <a href="<?php echo e($m['post_url']); ?>" target="_blank" rel="noopener" class="tw-card" data-tw-id="<?php echo (int)$m['id']; ?>">
            <?php if (!empty($m['image_url'])): ?>
              <div class="tw-img"><img src="<?php echo e($m['image_url']); ?>" alt="<?php echo e($m['text'] ?? ''); ?>" loading="lazy" decoding="async"></div>
            <?php endif; ?>
            <div class="tw-body">
              <div class="tw-source">
                <span class="tw-badge">🐦 X</span>
                <strong>@<?php echo e($m['username']); ?></strong>
                <span class="tw-time"><?php echo timeAgo($m['posted_at']); ?></span>
              </div>
              <div class="tw-text"><?php echo nl2br(e(mb_substr($m['text'] ?? '', 0, 280))); ?><?php echo mb_strlen($m['text'] ?? '')>280?'...':''; ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($ytMsgs)): ?>
      <div class="yt-breaking feed-panel" data-feed-panel="youtube" data-latest-id="<?php echo (int)$ytLatestId; ?>" data-page="1"<?php echo $activeFeed!=='youtube'?' hidden':''; ?>>
        <?php foreach ($ytMsgs as $v): ?>
          <a href="<?php echo e($v['post_url']); ?>" target="_blank" rel="noopener" class="yt-card" data-yt-id="<?php echo (int)$v['id']; ?>">
            <?php if (!empty($v['thumbnail_url'])): ?>
              <div class="yt-img">
                <img src="<?php echo e($v['thumbnail_url']); ?>" alt="<?php echo e($v['title']); ?>" loading="lazy" decoding="async">
                <span class="yt-play" aria-hidden="true">▶</span>
              </div>
            <?php endif; ?>
            <div class="yt-body">
              <div class="yt-source">
                <span class="yt-badge">▶ يوتيوب</span>
                <strong><?php echo e($v['display_name']); ?></strong>
                <span class="yt-time"><?php echo timeAgo($v['posted_at']); ?></span>
              </div>
              <div class="yt-title"><?php echo e(mb_substr($v['title'], 0, 160)); ?><?php echo mb_strlen($v['title']) > 160 ? '...' : ''; ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Topical category rails (سياسة/اقتصاد/رياضة/فنون/تقارير) were
         retired here. They've been replaced by the six purpose-built
         sections rendered above the main-layout block via the
         __renderCtSection helper. The /category/<slug> pages stay
         live for inbound search traffic. -->

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
        تم اختيار مواضيع «نيوز فيد» الأكثر قراءة بناءً على إجمالي عدد المشاهدات اليومية. اقرأ المواضيع الأكثر شعبية كل يوم من هنا.
      </div>
      <div class="mr2-desc" data-mr2-desc="trend" hidden>
        أكثر المواضيع تداولاً على منصات التواصل خلال الساعات الأخيرة.
      </div>
      <?php if (!empty($trendingNow)): ?>
      <div class="mr2-desc" data-mr2-desc="velocity" hidden>
        أخبار ترتفع قراءاتها بسرعة <b>الآن</b> — مرتبة بدرجة السرعة (آخر ساعة × 4 + آخر 6 ساعات).
        <?php if ($trendingReaders > 0): ?>
          · <b style="color:#CE1126;"><?php echo number_format($trendingReaders); ?></b> يقرأ الآن
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
                <span class="mr2-views" style="color:#CE1126;font-weight:800;">⚡ <?php echo number_format($__velocity); ?></span>
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

  <!-- SIDEBAR (redesign) -->
  <aside class="nfr-aside" aria-label="الشريط الجانبي">
    <?php if (!empty($mostRead)): ?>
    <div class="nfr-w">
      <div class="nfr-w-head"><span class="nfr-bar" style="background:#CE1126"></span><h3>الأكثر قراءة</h3><span class="nfr-w-tag">آخر ٢٤ ساعة</span></div>
      <div class="nfr-w-body">
        <?php foreach (array_slice($mostRead, 0, 5) as $__ri => $mr):
          $__rank = $__ri + 1;
          $__rurl = !empty($mr['article_id']) ? ('article/' . (int)$mr['article_id']) : 'search.php?q=' . urlencode((string)$mr['title']);
          $__rcol = $__rank === 1 ? '#CE1126' : ($__rank === 2 ? '#B8860B' : ($__rank === 3 ? '#C99A2E' : '#C9BFAD'));
        ?>
        <a class="nfr-read" href="<?php echo e($__rurl); ?>">
          <span class="nfr-read-rank" style="color:<?php echo $__rcol; ?>"><?php echo $__rank; ?></span>
          <span class="nfr-read-body">
            <span class="nfr-read-title"><?php echo e($mr['title']); ?></span>
            <span class="nfr-read-meta"><?php echo number_format((int)($mr['view_count'] ?? 0)); ?> قراءة</span>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="nfr-weather" id="nfrWeather">
      <div class="nfr-weather-head"><span class="nfr-bar" style="background:#E9D9A8"></span><h3>الطقس</h3><span class="nfr-w-tag light">تحديث مباشر</span></div>
      <div class="nfr-weather-cities" id="nfrWCities">
        <button type="button" class="nfr-city active" data-city="Jerusalem" data-name="القدس">القدس</button>
        <button type="button" class="nfr-city" data-city="Ramallah" data-name="رام الله">رام الله</button>
        <button type="button" class="nfr-city" data-city="Gaza" data-name="غزة">غزة</button>
        <button type="button" class="nfr-city" data-city="Hebron" data-name="الخليل">الخليل</button>
      </div>
      <div class="nfr-weather-main"><div class="nfr-weather-temp"><span id="nfrWTemp">--</span>°</div><div class="nfr-weather-sun" id="nfrWIcon">☀️</div></div>
      <div class="nfr-weather-desc" id="nfrWDesc">القدس · يحدّث الآن…</div>
      <a class="nfr-weather-more" href="#" onclick="if(window.openWeatherModal)openWeatherModal();return false;">عرض التفاصيل والتوقعات ›</a>
    </div>

    <?php if (!empty($sources)): ?>
    <div class="nfr-w">
      <div class="nfr-w-head"><span class="nfr-bar" style="background:#5B7F3B"></span><h3>مصادر مميّزة</h3></div>
      <div class="nfr-w-body">
        <?php foreach (array_slice($sources, 0, 5) as $__si => $src): ?>
        <a class="nfr-src" href="source/<?php echo (int)($src['id'] ?? 0); ?>" data-source-id="<?php echo (int)($src['id'] ?? 0); ?>">
          <span class="nfr-src-logo" style="background:<?php echo e($src['logo_bg'] ?? $src['logo_color'] ?? '#3D5A28'); ?>"><?php echo e($src['logo_letter'] ?? mb_substr((string)($src['name'] ?? '؟'), 0, 1)); ?></span>
          <span class="nfr-src-name"><?php echo e($src['name'] ?? ''); ?></span>
          <span class="nfr-toggle" role="switch" aria-label="متابعة <?php echo e($src['name'] ?? ''); ?>"><span class="nfr-knob"></span></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php
    // Build a *real* trends list from live data.
    //
    // 1) Distinct categories that have view momentum right now
    //    (uses $trendingNow, which is view-event–based).
    // 2) Cluster headlines from source-velocity trending — stories
    //    multiple outlets are publishing simultaneously in the last
    //    6 hours. These are the actual "موجة" topics.
    // 3) Final fallback: distinct cats from the homepage pools we
    //    already loaded, so the widget is never empty.
    //
    // The legacy $trends table is ignored entirely now — the user
    // reported it carries dead editorial slots (e.g. "قمة مجلس
    // التعاون") that no longer have any matching coverage.
    $cleanTrends = [];
    $__seen = [];
    foreach (is_array($trendingNow ?? null) ? $trendingNow : [] as $__a) {
        $cat = isset($__a['cat_name']) ? trim((string)$__a['cat_name']) : '';
        if ($cat === '' || isset($__seen[$cat])) continue;
        $cleanTrends[] = ['title' => $cat, 'href' => 'search.php?q=' . urlencode($cat)];
        $__seen[$cat] = true;
        if (count($cleanTrends) >= 6) break;
    }
    if (count($cleanTrends) < 6 && function_exists('trending_by_source_velocity')) {
        try {
            $__vel = trending_by_source_velocity(8);
            foreach ($__vel as $__cluster) {
                $__art = $__cluster['article'] ?? null;
                if (!$__art) continue;
                $__title = isset($__art['title']) ? trim((string)$__art['title']) : '';
                if ($__title === '') continue;
                // Compact "topic" — first 3-4 words of the headline.
                $__words = preg_split('/\s+/u', $__title);
                $__topic = implode(' ', array_slice($__words, 0, 4));
                if (mb_strlen($__topic) > 32) $__topic = mb_substr($__topic, 0, 32) . '…';
                if (isset($__seen[$__topic])) continue;
                $__ck = (string)($__cluster['cluster_key'] ?? '');
                $__href = $__ck !== '' ? '/cluster/' . $__ck : 'search.php?q=' . urlencode($__topic);
                $cleanTrends[] = ['title' => $__topic, 'href' => $__href];
                $__seen[$__topic] = true;
                if (count($cleanTrends) >= 6) break;
            }
        } catch (Throwable $__e) { /* widget keeps whatever it has */ }
    }
    if (count($cleanTrends) < 3) {
        $__pool = array_merge(
            is_array($breakingNews ?? null) ? $breakingNews : [],
            is_array($latestArticles ?? null) ? $latestArticles : []
        );
        $__catCounts = [];
        foreach ($__pool as $__a) {
            $cat = isset($__a['cat_name']) ? trim((string)$__a['cat_name']) : '';
            if ($cat === '') continue;
            $__catCounts[$cat] = (isset($__catCounts[$cat]) ? $__catCounts[$cat] : 0) + 1;
        }
        arsort($__catCounts);
        foreach (array_keys($__catCounts) as $cat) {
            if (isset($__seen[$cat])) continue;
            $cleanTrends[] = ['title' => $cat, 'href' => 'search.php?q=' . urlencode($cat)];
            $__seen[$cat] = true;
            if (count($cleanTrends) >= 6) break;
        }
    }
    ?>
    <?php if (!empty($cleanTrends)): ?>
    <div class="nfr-w">
      <div class="nfr-w-head"><span class="nfr-bar" style="background:#B8860B"></span><h3>ترند الآن</h3><span class="nfr-w-tag live"><span class="d"></span>مباشر</span></div>
      <div class="nfr-w-body nfr-tags">
        <?php foreach ($cleanTrends as $__ti => $tr): ?>
          <a class="nfr-tag <?php echo $__ti < 3 ? 'hot' : ''; ?>" href="<?php echo e($tr['href']); ?>">#<?php echo e($tr['title']); ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="nfr-news">
      <div class="nfr-news-eyebrow">✉ النشرة البريدية</div>
      <h3 class="nfr-news-title">ابدأ صباحك بموجز ذكي لأهم الأخبار</h3>
      <p class="nfr-news-desc">ملخّص يومي يصلك في السابعة صباحاً — أبرز ما عليك معرفته في دقيقتين.</p>
      <form class="nfr-news-form" id="nfrNewsletter" onsubmit="return nfrSubscribe(event)">
        <input type="email" name="email" id="nfrNewsEmail" placeholder="بريدك الإلكتروني" required dir="ltr">
        <input type="hidden" name="_csrf" value="<?php echo csrf_token(); ?>">
        <button type="submit">اشتراك</button>
      </form>
      <div class="nfr-news-msg" id="nfrNewsMsg" role="status" aria-live="polite"></div>
    </div>
  </aside>
  <script>
  function nfrSubscribe(e){e.preventDefault();var f=document.getElementById('nfrNewsletter'),m=document.getElementById('nfrNewsMsg');if(!f)return false;var fd=new FormData(f);if(m)m.textContent='…جاري الاشتراك';fetch('api/newsletter_subscribe.php',{method:'POST',body:fd}).then(function(r){return r.json().catch(function(){return{};});}).then(function(d){if(m)m.textContent=(d&&d.message)?d.message:'تم الاشتراك بنجاح ✓';if(!d||d.success!==false)f.reset();}).catch(function(){if(m)m.textContent='تعذّر الاشتراك، حاول لاحقاً';});return false;}
  /* Breaking news refresh: re-fetch the current page and swap just the
     #breaking section + its grid in place. Keeps scroll position. */
  function nfRefreshBreaking(btn){
    if(btn){btn.disabled=true;btn.dataset.label=btn.textContent;btn.textContent='⏳ يحدّث…';}
    fetch(window.location.pathname+'?_r='+Date.now(),{credentials:'same-origin'})
      .then(function(r){return r.text();})
      .then(function(html){
        var tmp=document.createElement('div'); tmp.innerHTML=html;
        var newGrid=tmp.querySelector('.bn-grid');
        var curGrid=document.querySelector('.bn-grid');
        if(newGrid&&curGrid){ curGrid.innerHTML=newGrid.innerHTML; }
        var u=document.getElementById('bnUpdated'); if(u) u.textContent='حُدّث الآن';
      })
      .catch(function(){})
      .finally(function(){ if(btn){btn.disabled=false; btn.textContent=btn.dataset.label||'🔄 تحديث';} });
  }
  /* Auto-refresh breaking news quietly every 90 seconds so the section
     stays fresh without forcing a full page reload. Pauses when the
     tab is hidden so we don't burn quota in background tabs. */
  (function(){
    var iv=null;
    function start(){ if(iv) return; iv=setInterval(function(){ if(!document.hidden) nfRefreshBreaking(null); }, 90000); }
    function stop(){ if(iv){clearInterval(iv); iv=null;} }
    document.addEventListener('visibilitychange', function(){ document.hidden?stop():start(); });
    start();
  })();
  (function(){function sync(){var s=document.querySelector('#topWeather span');if(!s)return;var t=(s.textContent||'').replace(/[^0-9-]/g,'');if(!t)return;var d=document.getElementById('nfrWTemp');if(d&&d.textContent==='--')d.textContent=t;var b=document.getElementById('nfrTopWTemp');if(b)b.textContent=t;}sync();setTimeout(sync,1500);setTimeout(sync,4000);})();
  /* Aside weather: city tabs swap the temperature/icon/description in
     place without opening the modal. Uses the open-meteo endpoint
     directly so we don't need a backend round-trip. */
  (function(){
    var WCODES={0:['☀️','صافي'],1:['🌤','صافي غالباً'],2:['⛅','غائم جزئياً'],3:['☁️','غائم'],45:['🌫','ضبابي'],48:['🌫','ضبابي'],51:['🌦','رذاذ خفيف'],53:['🌦','رذاذ'],55:['🌧','رذاذ كثيف'],61:['🌧','مطر خفيف'],63:['🌧','مطر'],65:['🌧','مطر غزير'],71:['🌨','ثلوج خفيفة'],73:['🌨','ثلوج'],75:['❄️','ثلوج كثيفة'],80:['🌦','أمطار متفرقة'],81:['🌧','أمطار'],82:['⛈','أمطار غزيرة'],95:['⛈','عواصف رعدية'],96:['⛈','عواصف مع برد'],99:['⛈','عواصف شديدة']};
    var COORDS={Jerusalem:[31.7683,35.2137],Ramallah:[31.9038,35.2034],Gaza:[31.5017,34.4668],Hebron:[31.5326,35.0998],Nablus:[32.2211,35.2544],Jenin:[32.4607,35.2953]};
    function loadCity(city,name){
      var c=COORDS[city]; if(!c) return;
      var url='https://api.open-meteo.com/v1/forecast?latitude='+c[0]+'&longitude='+c[1]+'&current=temperature_2m,weather_code&timezone=Asia/Jerusalem';
      fetch(url).then(function(r){return r.json();}).then(function(d){
        if(!d||!d.current) return;
        var t=Math.round(d.current.temperature_2m), code=d.current.weather_code, info=WCODES[code]||['🌤','—'];
        var T=document.getElementById('nfrWTemp'); if(T) T.textContent=t;
        var I=document.getElementById('nfrWIcon'); if(I) I.textContent=info[0];
        var D=document.getElementById('nfrWDesc'); if(D) D.textContent=name+' · '+info[1];
      }).catch(function(){});
    }
    var box=document.getElementById('nfrWCities');
    if(box){
      box.addEventListener('click',function(e){
        var b=e.target.closest('.nfr-city'); if(!b) return;
        box.querySelectorAll('.nfr-city').forEach(function(x){x.classList.remove('active');});
        b.classList.add('active');
        loadCity(b.dataset.city, b.dataset.name||b.textContent.trim());
      });
      loadCity('Jerusalem','القدس');
    }
  })();
  /* Sources widget: real follow/unfollow via /follow_source.php (cookie-
     backed). Reads the existing cookie on load so toggles render in the
     correct state, then POSTs on each click. */
  (function(){
    var followed=(function(){
      var m=document.cookie.match(/(?:^|; )followed_sources=([^;]*)/);
      if(!m) return {};
      var out={}, parts=decodeURIComponent(m[1]).split(',');
      parts.forEach(function(id){ if(id) out[id]=1; });
      return out;
    })();
    document.querySelectorAll('.nfr-src').forEach(function(a){
      var id=a.getAttribute('data-source-id'); if(!id) return;
      var tog=a.querySelector('.nfr-toggle');
      if(tog) tog.classList.toggle('on', !!followed[id]);
      a.addEventListener('click', function(e){
        var hitToggle=e.target.closest('.nfr-toggle');
        if(!hitToggle) return; // let logo/name click follow the link
        e.preventDefault(); e.stopPropagation();
        var on=tog.classList.toggle('on');
        var action=on?'follow':'unfollow';
        fetch('/follow_source.php?id='+encodeURIComponent(id)+'&action='+action,{credentials:'same-origin'}).catch(function(){
          tog.classList.toggle('on'); // revert on failure
        });
      });
    });
  })();
  </script>
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
      <a href="/about">من نحن</a>
      <a href="/editorial">السياسة التحريرية</a>
      <a href="/corrections">سياسة التصحيح</a>
      <a href="/privacy">سياسة الخصوصية</a>
      <a href="/contact">اتصل بنا</a>
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
      this.style.background = 'linear-gradient(135deg,#3D5A28,#2D4520)';
      setTimeout(() => {
        this.textContent = 'حفظ التفضيلات';
        this.style.background = 'linear-gradient(135deg,#5B7F3B,#3D5A28)';
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
<script src="assets/js/telegram-live.min.js?v=m1" defer></script>
<script src="assets/js/twitter-live.min.js?v=m2" defer></script>
<script src="assets/js/youtube-live.min.js?v=m1" defer></script>
<script>
// Social feed tabs (Telegram / X) — show one panel at a time while both
// stay in the DOM so their live-update scripts keep working.
(function(){
  var wrap = document.querySelector('.feed-tabs-wrap');
  if (!wrap) return;
  var tabs   = wrap.querySelectorAll('.feed-tab');
  var panels = wrap.querySelectorAll('[data-feed-panel]');
  var seeAll = wrap.querySelector('[data-see-all]');
  var links  = { telegram: 'telegram.php', twitter: null, youtube: null };
  function activate(name) {
    tabs.forEach(function(t){
      var on = t.dataset.tab === name;
      t.classList.toggle('active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panels.forEach(function(p){
      if (p.dataset.feedPanel === name) p.removeAttribute('hidden');
      else p.setAttribute('hidden', '');
    });
    wrap.setAttribute('data-active', name);
    if (seeAll) {
      var href = links[name];
      if (href) { seeAll.href = href; seeAll.removeAttribute('hidden'); }
      else      { seeAll.setAttribute('hidden', ''); }
    }
  }
  tabs.forEach(function(t){
    t.addEventListener('click', function(){ activate(t.dataset.tab); });
  });
})();
</script>
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
