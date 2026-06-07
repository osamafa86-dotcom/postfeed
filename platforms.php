<?php
/**
 * نيوز فيد — صفحة منصات السوشال (الداخلية).
 *
 * Bento-dashboard expansion of the homepage rail. Merges Telegram +
 * X + YouTube into a single chronological live feed with:
 *
 *   - top app bar with platform-aware breadcrumb
 *   - search bar across all three platforms
 *   - 4 platform tabs (الكل + 3 platforms) with live counts
 *   - stats ribbon (today's posts + sources + last update)
 *   - HERO featured post (top trending of active platform)
 *   - expanded AI brief card with bullets + CTA → telegram_summary.php
 *   - "الأكثر نشاطاً اليوم" horizontal-scrolling source chip strip
 *   - filters bar (الكل / عاجل / الأكثر تفاعلاً / بالأرقام / اقتباسات)
 *   - 🔴 live feed list (chronological, mixed platforms when "الكل")
 *   - Load more pagination
 *
 * URL: /platforms.php  (also exposed as /platforms via .htaccess)
 * Query parameters:
 *   ?p=all|telegram|twitter|youtube   active platform (default: all)
 *   ?q=<search>                        keyword search
 *   ?filter=trending|latest|featured   sort/filter the feed
 *   ?page=N                            pagination
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';
require_once __DIR__ . '/includes/ai_helper.php';

$viewer    = current_user();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;

$activePlat   = $_GET['p']      ?? 'all';
$searchQuery  = trim((string)($_GET['q'] ?? ''));
$activeFilter = $_GET['filter'] ?? 'latest';
$page         = max(1, intval($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$db = getDB();

// ─── Stats: how many posts today, distinct sources, last update ──
$todayStart = date('Y-m-d 00:00:00');
$platStats = [
    'telegram' => ['count' => 0, 'last' => null, 'sources' => 0],
    'twitter'  => ['count' => 0, 'last' => null, 'sources' => 0],
    'youtube'  => ['count' => 0, 'last' => null, 'sources' => 0],
];
try {
    $r = $db->prepare("SELECT COUNT(*) AS c, MAX(posted_at) AS last, COUNT(DISTINCT source_id) AS sources
                         FROM telegram_messages m
                        WHERE m.is_active = 1 AND m.posted_at >= ?");
    $r->execute([$todayStart]); $row = $r->fetch(PDO::FETCH_ASSOC);
    if ($row) $platStats['telegram'] = ['count' => (int)$row['c'], 'last' => $row['last'], 'sources' => (int)$row['sources']];

    $r = $db->prepare("SELECT COUNT(*) AS c, MAX(posted_at) AS last, COUNT(DISTINCT source_id) AS sources
                         FROM twitter_messages WHERE is_active = 1 AND posted_at >= ?");
    $r->execute([$todayStart]); $row = $r->fetch(PDO::FETCH_ASSOC);
    if ($row) $platStats['twitter'] = ['count' => (int)$row['c'], 'last' => $row['last'], 'sources' => (int)$row['sources']];

    $r = $db->prepare("SELECT COUNT(*) AS c, MAX(posted_at) AS last, COUNT(DISTINCT source_id) AS sources
                         FROM youtube_videos WHERE is_active = 1 AND posted_at >= ?");
    $r->execute([$todayStart]); $row = $r->fetch(PDO::FETCH_ASSOC);
    if ($row) $platStats['youtube'] = ['count' => (int)$row['c'], 'last' => $row['last'], 'sources' => (int)$row['sources']];
} catch (Throwable $e) {}

$totalToday   = $platStats['telegram']['count'] + $platStats['twitter']['count'] + $platStats['youtube']['count'];
$totalSources = $platStats['telegram']['sources'] + $platStats['twitter']['sources'] + $platStats['youtube']['sources'];
$lastUpdate   = max(
    $platStats['telegram']['last'] ?? '0000-00-00',
    $platStats['twitter']['last']  ?? '0000-00-00',
    $platStats['youtube']['last']  ?? '0000-00-00'
);

// ─── Top sources strip: who's most active today, across all platforms ──
$topSources = [];
try {
    $tg = $db->prepare("SELECT s.display_name AS name, s.username AS handle, COUNT(*) AS c, 'telegram' AS plat
                          FROM telegram_messages m JOIN telegram_sources s ON s.id = m.source_id
                         WHERE m.is_active=1 AND m.posted_at >= ?
                      GROUP BY m.source_id ORDER BY c DESC LIMIT 4");
    $tg->execute([$todayStart]);
    foreach ($tg->fetchAll(PDO::FETCH_ASSOC) as $r) $topSources[] = $r;

    $tw = $db->prepare("SELECT s.display_name AS name, s.username AS handle, COUNT(*) AS c, 'twitter' AS plat
                          FROM twitter_messages m JOIN twitter_sources s ON s.id = m.source_id
                         WHERE m.is_active=1 AND m.posted_at >= ?
                      GROUP BY m.source_id ORDER BY c DESC LIMIT 3");
    $tw->execute([$todayStart]);
    foreach ($tw->fetchAll(PDO::FETCH_ASSOC) as $r) $topSources[] = $r;

    $yt = $db->prepare("SELECT s.display_name AS name, s.handle AS handle, COUNT(*) AS c, 'youtube' AS plat
                          FROM youtube_videos v JOIN youtube_sources s ON s.id = v.source_id
                         WHERE v.is_active=1 AND v.posted_at >= ?
                      GROUP BY v.source_id ORDER BY c DESC LIMIT 3");
    $yt->execute([$todayStart]);
    foreach ($yt->fetchAll(PDO::FETCH_ASSOC) as $r) $topSources[] = $r;

    usort($topSources, fn($a, $b) => (int)$b['c'] <=> (int)$a['c']);
    $topSources = array_slice($topSources, 0, 8);
} catch (Throwable $e) {}

// ─── Editor's AI brief (latest tg_summary) ──
$aiBrief = null;
try { $aiBrief = tg_summary_get_latest(); } catch (Throwable $e) {}

// ─── Fetch posts for the active platform(s) ──
$posts = [];
$fetchTg = ($activePlat === 'all' || $activePlat === 'telegram');
$fetchTw = ($activePlat === 'all' || $activePlat === 'twitter');
$fetchYt = ($activePlat === 'all' || $activePlat === 'youtube');
$searchLike = $searchQuery !== '' ? '%' . $searchQuery . '%' : null;

try {
    if ($fetchTg) {
        $sql = "SELECT m.id, m.text AS body, m.text AS title, m.image_url, m.post_url, m.posted_at,
                       s.display_name AS source_name, s.username AS source_handle, s.avatar_url,
                       'telegram' AS plat
                  FROM telegram_messages m JOIN telegram_sources s ON s.id = m.source_id
                 WHERE m.is_active = 1 AND s.is_active = 1";
        $params = [];
        if ($searchLike) { $sql .= " AND m.text LIKE ?"; $params[] = $searchLike; }
        $sql .= " ORDER BY m.posted_at DESC LIMIT 60";
        $st = $db->prepare($sql); $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $posts[] = $r;
    }
    if ($fetchTw) {
        $sql = "SELECT m.id, m.text AS body, m.text AS title, m.image_url, m.post_url, m.posted_at,
                       s.display_name AS source_name, s.username AS source_handle, s.avatar_url,
                       'twitter' AS plat
                  FROM twitter_messages m JOIN twitter_sources s ON s.id = m.source_id
                 WHERE m.is_active = 1 AND s.is_active = 1";
        $params = [];
        if ($searchLike) { $sql .= " AND m.text LIKE ?"; $params[] = $searchLike; }
        $sql .= " ORDER BY m.posted_at DESC LIMIT 60";
        $st = $db->prepare($sql); $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $posts[] = $r;
    }
    if ($fetchYt) {
        $sql = "SELECT v.id, v.title AS body, v.title AS title, v.thumbnail_url AS image_url, v.post_url, v.posted_at,
                       s.display_name AS source_name, s.handle AS source_handle, s.avatar_url,
                       'youtube' AS plat
                  FROM youtube_videos v JOIN youtube_sources s ON s.id = v.source_id
                 WHERE v.is_active = 1 AND s.is_active = 1";
        $params = [];
        if ($searchLike) { $sql .= " AND v.title LIKE ?"; $params[] = $searchLike; }
        $sql .= " ORDER BY v.posted_at DESC LIMIT 60";
        $st = $db->prepare($sql); $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $posts[] = $r;
    }
} catch (Throwable $e) {
    error_log('[platforms] fetch: ' . $e->getMessage());
}

// Merge & sort the three streams by published time, then paginate.
usort($posts, fn($a, $b) => strcmp($b['posted_at'] ?? '', $a['posted_at'] ?? ''));
$totalPosts = count($posts);
$posts = array_slice($posts, $offset, $perPage);

// The hero is the first post; the rest are the live feed (skip the hero).
$hero = null;
if ($page === 1 && !empty($posts)) {
    $hero  = $posts[0];
    $posts = array_slice($posts, 1);
}

// Helper to convert ASCII digits to Arabic-Indic digits.
$toArNum = fn($n) => strtr((string)$n,
    ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);

// Telegram channels and our older RSS ingest sometimes store text with
// HTML entities already applied (&quot; &amp;). Decode once before
// e() re-escapes, otherwise the reader sees literal &quot; in headlines.
$decode = fn($s) => html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
// Strip leading emoji / bullets / pipes so the first visible char is a
// real letter — the channel's "🔵 خبر عاجل: …" gets the leading marker
// removed for clean cards.
$stripLead = fn($s) => preg_replace('/^[^\p{L}\p{N}]+/u', '', $s);

// Active-tab URL helper (preserves search + filter).
$tabUrl = function ($p) {
    $q = $_GET; $q['p'] = $p; unset($q['page']);
    return 'platforms.php?' . http_build_query($q);
};
$filterUrl = function ($f) {
    $q = $_GET; $q['filter'] = $f; unset($q['page']);
    return 'platforms.php?' . http_build_query($q);
};

// Platform metadata.
$plats = [
    'telegram' => ['label' => 'تلغرام',  'short' => 'تلغرام', 'color' => '#0ea5e9', 'count' => $platStats['telegram']['count']],
    'twitter'  => ['label' => 'منصة X', 'short' => 'X',     'color' => '#374151', 'count' => $platStats['twitter']['count']],
    'youtube'  => ['label' => 'يوتيوب', 'short' => 'يوتيوب', 'color' => '#dc2626', 'count' => $platStats['youtube']['count']],
];

$pageTitle = 'منصات السوشال — نيوز فيد';
$metaDesc  = 'تابع منشورات تلغرام و X و يوتيوب من ' . $totalSources . ' مصدراً موثوقاً في صفحة واحدة، مع إيجاز ذكي وفلترة حسب المنصة.';

$hasMore = ($offset + $perPage) < $totalPosts;
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title><?php echo e($pageTitle); ?></title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"></noscript>
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<link rel="stylesheet" href="assets/css/user.min.css?v=m1">
<?php include __DIR__ . '/includes/components/pwa_head.php'; ?>
<style>
/* ─────────── BENTO PLATFORMS DASHBOARD ─────────── */
:root {
  --bp-bg:        #0F0E0C;
  --bp-tile:      rgba(255,255,255,0.04);
  --bp-tile-hi:   rgba(255,255,255,0.06);
  --bp-stroke:    rgba(255,255,255,0.08);
  --bp-text:      #FFFFFF;
  --bp-muted:     rgba(255,255,255,0.55);
  --bp-faint:     rgba(255,255,255,0.4);
  --bp-tg:        #0EA5E9;
  --bp-tg2:       #0284C7;
  --bp-x:         #1C1F26;
  --bp-yt:        #DC2626;
  --bp-yt2:       #991B1B;
  --bp-gold:      #C99624;
  --bp-gold-bg:   #F5EBCE;
  --bp-gold-text: #6B4F0B;
  --bp-green:     #50DC78;
  --bp-red:       #CE1126;
}
body { background: var(--bp-bg); color: var(--bp-text); font-family: 'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; margin: 0; }
.bp-container { max-width: 1200px; margin: 0 auto; padding: 0 24px; }
a { text-decoration: none; color: inherit; }

/* ─── TOP HEADER ─── */
.bp-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 22px 0 18px;
  border-bottom: 1px solid var(--bp-stroke);
  margin-bottom: 24px;
}
.bp-top-back {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--bp-tile);
  border: 1px solid var(--bp-stroke);
  color: var(--bp-text);
  padding: 9px 14px;
  border-radius: 999px;
  font-weight: 800;
  font-size: 13px;
  transition: background 0.15s;
}
.bp-top-back:hover { background: var(--bp-tile-hi); }
.bp-top-title { display: flex; flex-direction: column; align-items: center; }
.bp-top-title h1 { font-size: 22px; font-weight: 900; margin: 0; color: var(--bp-text); }
.bp-top-title .live {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 700; color: var(--bp-muted);
  margin-top: 2px;
}
.bp-top-title .dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--bp-green);
  box-shadow: 0 0 6px var(--bp-green);
  animation: bpPulse 2s infinite;
}
@keyframes bpPulse {
  0%   { box-shadow: 0 0 0 0 rgba(80,220,120, 0.7); }
  70%  { box-shadow: 0 0 0 8px rgba(80,220,120, 0); }
  100% { box-shadow: 0 0 0 0 rgba(80,220,120, 0); }
}
.bp-top-right { font-size: 12px; color: var(--bp-muted); font-weight: 700; }

/* ─── SEARCH ─── */
.bp-search-form { display: block; margin-bottom: 14px; }
.bp-search {
  display: flex; align-items: center;
  background: var(--bp-tile);
  border: 1px solid var(--bp-stroke);
  border-radius: 14px;
  padding: 12px 16px;
  gap: 10px;
}
.bp-search:focus-within { border-color: var(--bp-tg); background: var(--bp-tile-hi); }
.bp-search-icon { color: var(--bp-faint); font-size: 14px; }
.bp-search input {
  flex: 1; background: transparent; border: 0; color: var(--bp-text);
  font-family: inherit; font-size: 14px; font-weight: 500;
  outline: none;
}
.bp-search input::placeholder { color: var(--bp-faint); }
.bp-search-clear {
  color: var(--bp-faint); font-size: 12px; font-weight: 700;
  text-decoration: none; padding: 4px 8px;
  border-radius: 6px; transition: background 0.15s;
}
.bp-search-clear:hover { background: var(--bp-tile-hi); color: var(--bp-text); }

/* ─── PLATFORM TABS ─── */
.bp-tabs {
  display: flex; gap: 6px;
  background: var(--bp-tile);
  border-radius: 14px;
  padding: 5px;
  margin-bottom: 14px;
}
.bp-tab {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  flex: 1 1 0;
  padding: 11px 14px;
  border-radius: 10px;
  color: var(--bp-muted);
  font-weight: 700;
  font-size: 13px;
  transition: background 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
}
.bp-tab:hover { color: var(--bp-text); }
.bp-tab .bp-tab-c {
  background: rgba(255,255,255,0.1);
  color: var(--bp-muted);
  font-size: 11px; font-weight: 900;
  padding: 2px 8px;
  border-radius: 999px;
}
.bp-tab.is-active {
  background: var(--bp-tab-color, var(--bp-green));
  color: #fff;
  box-shadow: 0 4px 14px -4px var(--bp-tab-color, var(--bp-green));
}
.bp-tab.is-active .bp-tab-c { background: rgba(0,0,0,0.25); color: #fff; }
.bp-tab[data-p="all"].is-active     { --bp-tab-color: #50DC78; }
.bp-tab[data-p="telegram"].is-active { --bp-tab-color: #0EA5E9; }
.bp-tab[data-p="twitter"].is-active  { --bp-tab-color: #4B5563; }
.bp-tab[data-p="youtube"].is-active  { --bp-tab-color: #DC2626; }

/* ─── STATS RIBBON ─── */
.bp-stats {
  background: var(--bp-tile);
  border-radius: 12px;
  padding: 12px 16px;
  display: flex;
  gap: 16px;
  align-items: center;
  justify-content: space-around;
  margin-bottom: 14px;
}
.bp-stat { display: inline-flex; align-items: center; gap: 8px; }
.bp-stat-ico { font-size: 16px; }
.bp-stat-val { font-size: 15px; font-weight: 900; color: var(--bp-text); line-height: 1.1; }
.bp-stat-lab { font-size: 10.5px; color: var(--bp-faint); font-weight: 500; }
.bp-stat-text { display: flex; flex-direction: column; gap: 0; }
.bp-stat-sep { width: 1px; height: 22px; background: var(--bp-stroke); }

/* ─── BENTO GRID ─── */
.bp-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 10px;
  margin-bottom: 14px;
}
.bp-tile { border-radius: 18px; overflow: hidden; position: relative; }

/* HERO tile */
.bp-hero {
  grid-column: 1 / -1;
  background: linear-gradient(135deg, #0EA5E9 0%, #7C3AED 100%);
  padding: 18px;
  display: flex; flex-direction: column; gap: 12px;
  color: #fff;
  transition: transform 0.2s;
}
.bp-hero:hover { transform: translateY(-2px); }
.bp-hero-top { display: flex; justify-content: space-between; align-items: center; }
.bp-hero-trend {
  display: inline-flex; align-items: center; gap: 5px;
  background: rgba(0,0,0,0.35); padding: 4px 11px;
  border-radius: 999px; font-size: 11px; font-weight: 800;
}
.bp-hero-plat { font-size: 11.5px; font-weight: 700; opacity: 0.85; }
.bp-hero-title { font-size: 19px; font-weight: 900; line-height: 1.45; margin: 0; max-width: 720px; }
.bp-hero-meta { display: flex; justify-content: space-between; align-items: center; font-size: 12px; }
.bp-hero-meta .l { display: inline-flex; align-items: center; gap: 8px; }
.bp-hero-av {
  width: 24px; height: 24px; border-radius: 50%;
  background: rgba(255,255,255,0.95);
  color: var(--bp-tg); display: inline-flex;
  align-items: center; justify-content: center;
  font-weight: 900; font-size: 11px;
}
.bp-hero-meta .sep { opacity: 0.5; }

/* AI BRIEF tile */
.bp-ai {
  background: var(--bp-gold-bg);
  color: var(--bp-gold-text);
  padding: 14px 16px;
  display: flex; flex-direction: column; gap: 10px;
}
.bp-ai-head { display: flex; justify-content: space-between; align-items: center; }
.bp-ai-head-l { display: inline-flex; align-items: center; gap: 9px; }
.bp-ai-spark {
  width: 30px; height: 30px;
  background: var(--bp-gold);
  color: #fff;
  border-radius: 8px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 15px;
  box-shadow: 0 4px 10px rgba(201,150,36,0.4);
}
.bp-ai-title { font-weight: 900; font-size: 13px; line-height: 1.1; }
.bp-ai-sub { font-size: 10.5px; opacity: 0.7; font-weight: 500; margin-top: 1px; }
.bp-ai-refresh {
  background: rgba(255,255,255,0.5);
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 10.5px; font-weight: 900;
  color: var(--bp-gold-text);
  display: inline-flex; align-items: center; gap: 4px;
}
.bp-ai-bullets { display: flex; flex-direction: column; gap: 8px; }
.bp-ai-bullet { display: flex; gap: 8px; align-items: flex-start; }
.bp-ai-ico {
  width: 22px; height: 22px; flex: 0 0 22px;
  background: rgba(255,255,255,0.55);
  border-radius: 6px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 11px;
}
.bp-ai-txt {
  font-size: 12px; font-weight: 700;
  color: #2C2416; line-height: 1.55;
}
.bp-ai-cta {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  background: #1C1812; color: #F5EBCE;
  padding: 10px 14px; border-radius: 10px;
  font-size: 12px; font-weight: 900;
  margin-top: 4px;
  transition: background 0.15s;
}
.bp-ai-cta:hover { background: #2A2418; }

/* ─── TOP SOURCES STRIP ─── */
.bp-sect {
  display: flex; justify-content: space-between; align-items: center;
  margin: 8px 0 10px;
}
.bp-sect-l { display: inline-flex; align-items: center; gap: 8px; font-weight: 900; font-size: 14px; }
.bp-sect-r { font-size: 12px; color: var(--bp-muted); font-weight: 700; }
.bp-srcs {
  display: flex; gap: 8px;
  overflow-x: auto;
  padding: 0 0 12px;
  margin: 0 -24px 14px;
  padding-left: 24px; padding-right: 24px;
  scrollbar-width: none;
}
.bp-srcs::-webkit-scrollbar { display: none; }
.bp-src {
  flex: 0 0 auto;
  background: var(--bp-tile);
  border: 1px solid var(--bp-stroke);
  border-radius: 999px;
  padding: 7px 13px 7px 6px;
  display: inline-flex; align-items: center; gap: 8px;
  color: var(--bp-text);
  transition: background 0.15s;
}
.bp-src:hover { background: var(--bp-tile-hi); }
.bp-src-av {
  width: 26px; height: 26px; border-radius: 50%;
  color: #fff; font-weight: 900; font-size: 11px;
  display: inline-flex; align-items: center; justify-content: center;
}
.bp-src-name { font-size: 11px; font-weight: 900; }
.bp-src-c { font-size: 9.5px; color: var(--bp-faint); display: block; }

/* ─── FILTERS BAR ─── */
.bp-filts {
  display: flex; gap: 6px; flex-wrap: wrap;
  margin: 10px 0;
}
.bp-filt {
  background: transparent;
  border: 1px solid var(--bp-stroke);
  padding: 6px 12px;
  border-radius: 999px;
  font-size: 11.5px; font-weight: 700;
  color: var(--bp-muted);
  display: inline-flex; align-items: center; gap: 5px;
  transition: all 0.15s;
}
.bp-filt:hover { background: var(--bp-tile-hi); color: var(--bp-text); }
.bp-filt.is-active {
  background: #fff; border-color: #fff; color: #1C1812; font-weight: 900;
}

/* ─── LIVE FEED ─── */
.bp-feed-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 14px 0 8px;
}
.bp-feed-head-l { display: inline-flex; align-items: center; gap: 8px; font-weight: 900; font-size: 15px; }
.bp-feed-dot {
  width: 9px; height: 9px; border-radius: 50%;
  background: #FF5A6E; box-shadow: 0 0 8px #FF5A6E;
  animation: bpPulse 2s infinite;
}
.bp-feed-sort { font-size: 12px; color: var(--bp-muted); font-weight: 700; }

.bp-feed { display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; }
.bp-card {
  background: var(--bp-tile);
  border: 1px solid var(--bp-stroke);
  border-radius: 16px;
  padding: 14px 16px;
  display: flex; flex-direction: column; gap: 12px;
  transition: background 0.15s, transform 0.15s;
}
.bp-card:hover { background: var(--bp-tile-hi); transform: translateY(-1px); }
.bp-card-top { display: flex; justify-content: space-between; align-items: center; }
.bp-card-top-l { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bp-pill {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 9px; border-radius: 6px;
  font-size: 10px; font-weight: 900; color: #fff;
  letter-spacing: 0.3px;
}
.bp-pill-telegram { background: var(--bp-tg); }
.bp-pill-twitter  { background: var(--bp-x); }
.bp-pill-youtube  { background: var(--bp-yt); }
.bp-card-av {
  width: 20px; height: 20px; border-radius: 50%;
  background: var(--bp-stroke);
  display: inline-flex; align-items: center; justify-content: center;
  font-weight: 900; font-size: 10px; color: #fff;
}
.bp-card-channel { font-size: 12px; font-weight: 900; color: var(--bp-text); }
.bp-card-time { font-size: 11px; color: var(--bp-faint); font-weight: 700; }
.bp-card-content { display: flex; gap: 12px; }
.bp-card-thumb {
  flex: 0 0 80px; width: 80px; height: 80px;
  border-radius: 10px; overflow: hidden;
  background: #2C2416;
  position: relative;
}
.bp-card-thumb img { width: 100%; height: 100%; object-fit: cover; }
.bp-card-thumb .play {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; color: #fff;
  background: rgba(0,0,0,0.3);
}
.bp-card-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.bp-card-title { font-size: 13.5px; font-weight: 700; color: var(--bp-text); line-height: 1.55; }
.bp-card-summary { font-size: 11.5px; color: var(--bp-muted); line-height: 1.55; }

/* ─── LOAD MORE ─── */
.bp-more {
  display: block; text-align: center;
  background: var(--bp-tile);
  border: 1px solid var(--bp-stroke);
  border-radius: 12px;
  padding: 14px;
  font-size: 13px; font-weight: 900;
  color: var(--bp-text);
  margin-bottom: 28px;
  transition: background 0.15s;
}
.bp-more:hover { background: var(--bp-tile-hi); }

/* ─── EMPTY STATE ─── */
.bp-empty {
  background: var(--bp-tile);
  border: 1px solid var(--bp-stroke);
  border-radius: 16px;
  padding: 60px 24px;
  text-align: center;
}
.bp-empty .ico { font-size: 50px; margin-bottom: 14px; }
.bp-empty h3 { font-size: 18px; color: var(--bp-text); margin: 0 0 8px; }
.bp-empty p { font-size: 13px; color: var(--bp-muted); margin: 0 auto; max-width: 320px; line-height: 1.7; }

/* ─── RESPONSIVE ─── */
@media (max-width: 760px) {
  .bp-container { padding: 0 16px; }
  .bp-grid { grid-template-columns: 1fr; }
  .bp-stats { gap: 8px; }
  .bp-stat-sep { display: none; }
  .bp-tabs { flex-wrap: wrap; }
  .bp-tab { flex: 1 1 calc(50% - 6px); }
  .bp-top-title h1 { font-size: 18px; }
  .bp-hero-title { font-size: 16px; }
  .bp-card-thumb { flex: 0 0 64px; width: 64px; height: 64px; }
}
</style>
</head>
<body>

<?php
$activeType = 'platforms';
$activeSlug = '';
$showTicker = false;
include __DIR__ . '/includes/components/site_header.php';
?>

<div class="bp-container">

  <!-- TOP HEADER -->
  <div class="bp-top">
    <a class="bp-top-back" href="/">
      <span>→</span><span>الرئيسية</span>
    </a>
    <div class="bp-top-title">
      <h1>منصات السوشال</h1>
      <span class="live">
        <span class="dot"></span>
        لحظي · <?php echo e($toArNum($totalSources)); ?> مصدر
      </span>
    </div>
    <div class="bp-top-right">
      <?php if ($lastUpdate && $lastUpdate !== '0000-00-00'): ?>
        ⏱ <?php echo e(timeAgo($lastUpdate)); ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- SEARCH BAR -->
  <form class="bp-search-form" method="get" action="platforms.php">
    <?php if ($activePlat !== 'all'): ?>
      <input type="hidden" name="p" value="<?php echo e($activePlat); ?>">
    <?php endif; ?>
    <?php if ($activeFilter !== 'latest'): ?>
      <input type="hidden" name="filter" value="<?php echo e($activeFilter); ?>">
    <?php endif; ?>
    <div class="bp-search">
      <span class="bp-search-icon">🔍</span>
      <input type="search" name="q"
             value="<?php echo e($searchQuery); ?>"
             placeholder="ابحث في <?php echo e($toArNum($totalToday)); ?> منشوراً من اليوم…"
             aria-label="بحث">
      <?php if ($searchQuery !== ''): ?>
        <a class="bp-search-clear" href="<?php echo e($tabUrl($activePlat)); ?>">إلغاء</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- PLATFORM TABS -->
  <nav class="bp-tabs" role="tablist">
    <a class="bp-tab<?php echo $activePlat==='all'?' is-active':''; ?>" data-p="all"
       href="<?php echo e($tabUrl('all')); ?>">
      <span>الكل</span>
      <span class="bp-tab-c"><?php echo e($toArNum($totalToday)); ?></span>
    </a>
    <?php foreach ($plats as $key => $p): ?>
      <a class="bp-tab<?php echo $activePlat===$key?' is-active':''; ?>" data-p="<?php echo e($key); ?>"
         href="<?php echo e($tabUrl($key)); ?>">
        <span><?php echo e($p['label']); ?></span>
        <span class="bp-tab-c"><?php echo e($toArNum($p['count'])); ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <!-- STATS RIBBON -->
  <div class="bp-stats">
    <div class="bp-stat">
      <span class="bp-stat-ico">📊</span>
      <div class="bp-stat-text">
        <span class="bp-stat-val"><?php echo e($toArNum($totalToday)); ?></span>
        <span class="bp-stat-lab">منشور اليوم</span>
      </div>
    </div>
    <span class="bp-stat-sep"></span>
    <div class="bp-stat">
      <span class="bp-stat-ico">🌐</span>
      <div class="bp-stat-text">
        <span class="bp-stat-val"><?php echo e($toArNum($totalSources)); ?></span>
        <span class="bp-stat-lab">مصدر</span>
      </div>
    </div>
    <span class="bp-stat-sep"></span>
    <div class="bp-stat">
      <span class="bp-stat-ico">↻</span>
      <div class="bp-stat-text">
        <span class="bp-stat-val"><?php echo $lastUpdate && $lastUpdate !== '0000-00-00' ? e(timeAgo($lastUpdate)) : '—'; ?></span>
        <span class="bp-stat-lab">آخر تحديث</span>
      </div>
    </div>
  </div>

  <!-- BENTO GRID: Hero + AI brief -->
  <div class="bp-grid">
    <?php if ($hero): ?>
      <?php
        $heroChannel = $hero['source_name'] ?? '—';
        $heroBody    = trim($stripLead($decode($hero['body'] ?? '')));
        $heroInit    = mb_substr($heroChannel, 0, 1) ?: '?';
        $heroPlat    = $hero['plat'];
      ?>
      <a class="bp-tile bp-hero" href="<?php echo e($hero['post_url'] ?? '#'); ?>" target="_blank" rel="noopener">
        <div class="bp-hero-top">
          <span class="bp-hero-trend">🔥 الأكثر نشاطاً الآن</span>
          <span class="bp-hero-plat">
            <?php echo $heroPlat === 'telegram' ? '📨 تلغرام' : ($heroPlat === 'twitter' ? '𝕏 منصة X' : '▶ يوتيوب'); ?>
          </span>
        </div>
        <h2 class="bp-hero-title"><?php echo e(mb_substr($heroBody, 0, 180)); ?><?php echo mb_strlen($heroBody) > 180 ? '…' : ''; ?></h2>
        <div class="bp-hero-meta">
          <span class="l">
            <span class="bp-hero-av"><?php echo e($heroInit); ?></span>
            <b><?php echo e($heroChannel); ?></b>
            <span class="sep">·</span>
            <span><?php echo timeAgo($hero['posted_at']); ?></span>
          </span>
          <span class="bp-hero-plat">→ افتح المنشور</span>
        </div>
      </a>
    <?php endif; ?>

    <?php if ($aiBrief): ?>
      <div class="bp-tile bp-ai">
        <div class="bp-ai-head">
          <div class="bp-ai-head-l">
            <span class="bp-ai-spark">✨</span>
            <div>
              <div class="bp-ai-title">الإيجاز الذكي</div>
              <div class="bp-ai-sub">تحديث آلي كل ٥ دقائق</div>
            </div>
          </div>
          <span class="bp-ai-refresh">↻ تحديث</span>
        </div>
        <div class="bp-ai-bullets">
          <?php
            // Up to 3 bullets pulled from the brief's topics + sections.
            $bullets = [];
            if (!empty($aiBrief['subheadline'])) {
              $bullets[] = ['ico' => '📍', 'txt' => $aiBrief['subheadline']];
            }
            foreach (($aiBrief['topics'] ?? []) as $t) {
              if (count($bullets) >= 3) break;
              $tx = is_array($t) ? ($t['title'] ?? '') : (string)$t;
              if ($tx === '') continue;
              $bullets[] = ['ico' => '🔁', 'txt' => $tx];
            }
            if (empty($bullets) && !empty($aiBrief['summary'])) {
              $bullets[] = ['ico' => '✨', 'txt' => mb_substr($aiBrief['summary'], 0, 140) . '…'];
            }
          ?>
          <?php foreach (array_slice($bullets, 0, 3) as $b): ?>
            <div class="bp-ai-bullet">
              <span class="bp-ai-ico"><?php echo e($b['ico']); ?></span>
              <span class="bp-ai-txt"><?php echo e($b['txt']); ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <a class="bp-ai-cta" href="telegram_summary.php?id=<?php echo (int)$aiBrief['id']; ?>">
          افتح الإيجاز الكامل <span>→</span>
        </a>
      </div>
    <?php endif; ?>
  </div>

  <!-- TOP SOURCES STRIP -->
  <?php if (!empty($topSources)): ?>
    <div class="bp-sect">
      <div class="bp-sect-l">📊 الأكثر نشاطاً اليوم</div>
      <div class="bp-sect-r">المصادر ›</div>
    </div>
    <div class="bp-srcs">
      <?php foreach ($topSources as $s):
        $platColor = $s['plat'] === 'telegram' ? '#0EA5E9'
                   : ($s['plat'] === 'twitter' ? '#4B5563' : '#DC2626');
        $platUrl   = $s['plat'] === 'telegram' ? 'telegram.php'
                   : ($s['plat'] === 'twitter' ? 'twitter_feed.php' : 'youtube_feed.php');
      ?>
        <a class="bp-src" href="<?php echo e($platUrl); ?>">
          <span class="bp-src-av" style="background:<?php echo e($platColor); ?>;">
            <?php echo e(mb_substr($s['name'], 0, 1)); ?>
          </span>
          <span>
            <span class="bp-src-name"><?php echo e($s['name']); ?></span>
            <span class="bp-src-c"><?php echo e($toArNum($s['c'])); ?> منشور</span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- FILTERS -->
  <div class="bp-filts">
    <a class="bp-filt<?php echo $activeFilter==='latest'?' is-active':''; ?>" href="<?php echo e($filterUrl('latest')); ?>">
      <?php if ($activeFilter==='latest'): ?><span>✓</span><?php endif; ?>
      الكل
    </a>
    <a class="bp-filt<?php echo $activeFilter==='breaking'?' is-active':''; ?>" href="<?php echo e($filterUrl('breaking')); ?>">عاجل 🔴</a>
    <a class="bp-filt<?php echo $activeFilter==='trending'?' is-active':''; ?>" href="<?php echo e($filterUrl('trending')); ?>">الأكثر تفاعلاً</a>
    <a class="bp-filt<?php echo $activeFilter==='numbers'?' is-active':''; ?>" href="<?php echo e($filterUrl('numbers')); ?>">بالأرقام</a>
    <a class="bp-filt<?php echo $activeFilter==='quotes'?' is-active':''; ?>" href="<?php echo e($filterUrl('quotes')); ?>">اقتباسات</a>
  </div>

  <!-- LIVE FEED -->
  <div class="bp-feed-head">
    <div class="bp-feed-head-l">
      <span class="bp-feed-dot"></span>
      البث المباشر
    </div>
    <div class="bp-feed-sort">الترتيب: الأحدث ⌄</div>
  </div>

  <?php if (empty($posts) && !$hero): ?>
    <div class="bp-empty">
      <div class="ico">📭</div>
      <h3>لا توجد منشورات مطابقة</h3>
      <p>
        <?php if ($searchQuery !== ''): ?>
          لم نجد منشوراً يطابق «<?php echo e($searchQuery); ?>».
          <a href="<?php echo e($tabUrl($activePlat)); ?>" style="color:#0EA5E9">إلغاء البحث</a>
        <?php else: ?>
          ستظهر المنشورات هنا فور وصولها من المنصات.
        <?php endif; ?>
      </p>
    </div>
  <?php else: ?>
    <div class="bp-feed">
      <?php foreach ($posts as $p):
        $plat        = $p['plat'];
        $platLabel   = $plat === 'telegram' ? 'تلغرام'
                     : ($plat === 'twitter'  ? 'X'
                     : 'بث');
        $platIcon    = $plat === 'telegram' ? '📨'
                     : ($plat === 'twitter'  ? '𝕏'
                     : '▶');
        $channelInit = mb_substr($p['source_name'] ?? '?', 0, 1);
        $body        = trim($stripLead($decode($p['body'] ?? '')));
        $titleTxt    = mb_substr($body, 0, 160);
        $bodyHasMore = mb_strlen($body) > 160;
        $summary     = $bodyHasMore ? mb_substr($body, 160, 130) . '…' : '';
      ?>
        <a class="bp-card" href="<?php echo e($p['post_url'] ?? '#'); ?>" target="_blank" rel="noopener">
          <div class="bp-card-top">
            <div class="bp-card-top-l">
              <span class="bp-pill bp-pill-<?php echo e($plat); ?>">
                <span><?php echo $platIcon; ?></span>
                <span><?php echo e($platLabel); ?></span>
              </span>
              <span class="bp-card-av" style="background:<?php echo $plat==='telegram'?'#0EA5E9':($plat==='twitter'?'#4B5563':'#DC2626'); ?>;">
                <?php echo e($channelInit); ?>
              </span>
              <span class="bp-card-channel"><?php echo e($p['source_name']); ?></span>
            </div>
            <span class="bp-card-time"><?php echo timeAgo($p['posted_at']); ?></span>
          </div>
          <div class="bp-card-content">
            <?php if (!empty($p['image_url'])): ?>
              <div class="bp-card-thumb">
                <img src="<?php echo e($p['image_url']); ?>" alt="" loading="lazy" decoding="async">
                <?php if ($plat === 'youtube'): ?>
                  <span class="play">▶</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="bp-card-body">
              <div class="bp-card-title"><?php echo e($titleTxt); ?><?php echo $bodyHasMore && !$summary ? '…' : ''; ?></div>
              <?php if ($summary !== ''): ?>
                <div class="bp-card-summary"><?php echo e($summary); ?></div>
              <?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($hasMore): ?>
      <?php $q = $_GET; $q['page'] = $page + 1; ?>
      <a class="bp-more" href="platforms.php?<?php echo e(http_build_query($q)); ?>">
        تحميل المزيد ↓
      </a>
    <?php endif; ?>
  <?php endif; ?>

</div>

<script src="assets/js/user.min.js?v=m1" defer></script>
</body>
</html>
