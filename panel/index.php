<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/view_tracking.php';
requireRole('viewer');
$db = getDB();

// ---- KPI snapshot -----------------------------------------------------
$totalArticles   = (int)$db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$totalSources    = (int)$db->query("SELECT COUNT(*) FROM sources WHERE is_active=1")->fetchColumn();
$totalCategories = (int)$db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$totalViews      = (int)$db->query("SELECT COALESCE(SUM(view_count),0) FROM articles")->fetchColumn();
$todayViews      = get_today_views();
$yesterdayViews  = 0;
try {
    $stmt = $db->prepare("SELECT COALESCE(total_views,0) FROM daily_view_stats WHERE stat_date = ?");
    $stmt->execute([date('Y-m-d', strtotime('-1 day'))]);
    $yesterdayViews = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}
$viewsGrowth = $yesterdayViews > 0
    ? round((($todayViews - $yesterdayViews) / $yesterdayViews) * 100, 1)
    : ($todayViews > 0 ? 100.0 : 0.0);

$weekViews = 0;
try {
    $weekViews = (int)$db->query(
        "SELECT COALESCE(SUM(total_views),0) FROM daily_view_stats
         WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
    )->fetchColumn();
} catch (Throwable $e) {}

$todayArticles = (int)$db->query("SELECT COUNT(*) FROM articles WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$weekArticles  = (int)$db->query("SELECT COUNT(*) FROM articles WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$breakingCount = (int)$db->query("SELECT COUNT(*) FROM articles WHERE is_breaking=1")->fetchColumn();

$avgViewsPerArticle = $totalArticles > 0 ? round($totalViews / $totalArticles) : 0;

// ---- Attention queue --------------------------------------------------
$noImageCount      = (int)$db->query("SELECT COUNT(*) FROM articles WHERE (image_url IS NULL OR image_url='') AND status='published'")->fetchColumn();
$noAiSummaryCount  = 0;
try {
    $noAiSummaryCount = (int)$db->query(
        "SELECT COUNT(*) FROM articles
         WHERE status='published' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           AND (ai_processed_at IS NULL OR ai_processed_at = '0000-00-00 00:00:00')"
    )->fetchColumn();
} catch (Throwable $e) {}
$staleSourcesCount = 0;
try {
    $staleSourcesCount = (int)$db->query(
        "SELECT COUNT(*) FROM sources
         WHERE is_active=1 AND rss_url IS NOT NULL AND rss_url<>''
           AND (last_fetched_at IS NULL OR last_fetched_at < DATE_SUB(NOW(), INTERVAL 3 HOUR))"
    )->fetchColumn();
} catch (Throwable $e) {}
$hiddenCommentsCount = 0;
try {
    $hiddenCommentsCount = (int)$db->query("SELECT COUNT(*) FROM article_comments WHERE is_hidden=1")->fetchColumn();
} catch (Throwable $e) {}
$draftsCount = (int)$db->query("SELECT COUNT(*) FROM articles WHERE status='draft'")->fetchColumn();

// ---- System health ----------------------------------------------------
$lastRssFetch = null;
try { $lastRssFetch = $db->query("SELECT MAX(last_fetched_at) FROM sources WHERE last_fetched_at IS NOT NULL")->fetchColumn(); } catch (Throwable $e) {}
$lastAiRun = null;
try { $lastAiRun = $db->query("SELECT MAX(ai_processed_at) FROM articles WHERE ai_processed_at IS NOT NULL")->fetchColumn(); } catch (Throwable $e) {}
$lastArticleAdded = $db->query("SELECT MAX(created_at) FROM articles")->fetchColumn();
$lastNewsletter = null;
try { $lastNewsletter = $db->query("SELECT MAX(sent_at) FROM newsletter_sends")->fetchColumn(); } catch (Throwable $e) {}
$newsletterSubs = 0;
try { $newsletterSubs = (int)$db->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status='active'")->fetchColumn(); } catch (Throwable $e) {}
$activeReaders = 0;
try {
    $activeReaders = (int)$db->query(
        "SELECT COUNT(DISTINCT article_id) FROM article_view_events WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    )->fetchColumn();
} catch (Throwable $e) {}

// ---- Trends (30d) -----------------------------------------------------
$dailyViewsMap = get_daily_views(30);
$dailyArticlesRaw = $db->query(
    "SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM articles
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY DATE(created_at)"
)->fetchAll(PDO::FETCH_ASSOC);
$articlesByDayMap = array_column($dailyArticlesRaw, 'cnt', 'day');
$chartDays = []; $chartArticleSeries = []; $chartViewSeries = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartDays[]          = date('m/d', strtotime($d));
    $chartArticleSeries[] = (int)($articlesByDayMap[$d] ?? 0);
    $chartViewSeries[]    = (int)($dailyViewsMap[$d] ?? 0);
}

// ---- Top content / category breakdown ---------------------------------
$articlesByCategory = $db->query(
    "SELECT c.name, COUNT(a.id) AS count FROM categories c
     LEFT JOIN articles a ON a.category_id=c.id
     GROUP BY c.id, c.name ORDER BY count DESC LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

$topArticlesWeek = [];
try {
    $topArticlesWeek = $db->query(
        "SELECT a.id, a.title, a.view_count, c.name AS cat_name
         FROM articles a LEFT JOIN categories c ON c.id=a.category_id
         WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND a.status='published'
         ORDER BY a.view_count DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$topWeekMax = 0;
foreach ($topArticlesWeek as $r) { $topWeekMax = max($topWeekMax, (int)$r['view_count']); }

$recentArticles = $db->query(
    "SELECT a.id, a.title, a.view_count, a.status, a.is_breaking, a.image_url,
            a.created_at, c.name AS cat_name, c.css_class, s.name AS source_name, s.logo_letter
     FROM articles a
     LEFT JOIN categories c ON a.category_id=c.id
     LEFT JOIN sources s ON a.source_id=s.id
     ORDER BY a.created_at DESC LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

// ---- Live activity ----------------------------------------------------
$activityFeed = [];
try {
    $rows = $db->query(
        "SELECT 'article' AS kind, a.id AS ref_id, a.title AS text, a.created_at AS at, s.name AS who
         FROM articles a LEFT JOIN sources s ON s.id=a.source_id
         WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
         ORDER BY a.created_at DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);
    $activityFeed = array_merge($activityFeed, $rows);
} catch (Throwable $e) {}
try {
    $rows = $db->query(
        "SELECT 'comment' AS kind, c.article_id AS ref_id, a.title AS text, c.created_at AS at, u.username AS who
         FROM article_comments c
         JOIN articles a ON a.id=c.article_id
         LEFT JOIN users u ON u.id=c.user_id
         WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND c.is_hidden=0
         ORDER BY c.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
    $activityFeed = array_merge($activityFeed, $rows);
} catch (Throwable $e) {}
usort($activityFeed, fn($a, $b) => strcmp($b['at'], $a['at']));
$activityFeed = array_slice($activityFeed, 0, 8);

function nf_time_since(?string $ts): string {
    if (!$ts) return '—';
    $t = strtotime($ts);
    $d = time() - $t;
    if ($d < 60)     return 'قبل لحظات';
    if ($d < 3600)   return 'قبل ' . (int)($d/60) . ' د';
    if ($d < 86400)  return 'قبل ' . (int)($d/3600) . ' س';
    if ($d < 604800) return 'قبل ' . (int)($d/86400) . ' يوم';
    return date('j M', $t);
}
function nf_health_status(?string $ts, int $warnHours = 3, int $dangerHours = 12): string {
    if (!$ts) return 'danger';
    $d = (time() - strtotime($ts)) / 3600;
    if ($d > $dangerHours) return 'danger';
    if ($d > $warnHours)   return 'warn';
    return 'ok';
}
function nf_greeting(): string {
    $h = (int)date('H');
    if ($h < 5)  return 'ليلة هادئة';
    if ($h < 12) return 'صباح الخير';
    if ($h < 17) return 'مساء الخير';
    if ($h < 21) return 'مساء الخير';
    return 'مساء الخير';
}

$pageTitle  = 'لوحة التحكم - نيوز فيد';
$activePage = 'index';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<style>
  /* ===== Dashboard v1.12 — Slate & Indigo ===== */
  .dash-hero {
    position:relative; overflow:hidden;
    background: linear-gradient(135deg, #1e293b 0%, #312e81 55%, #4338ca 100%);
    color:#fff; padding:26px 28px; border-radius:var(--radius-lg);
    margin-bottom:22px;
    box-shadow: 0 10px 30px rgba(30,41,59,0.25);
  }
  .dash-hero::before {
    content:''; position:absolute; top:-60px; left:-60px;
    width:200px; height:200px; border-radius:50%;
    background: radial-gradient(circle, rgba(129,140,248,0.35), transparent 70%);
  }
  .dash-hero::after {
    content:''; position:absolute; bottom:-80px; right:-40px;
    width:260px; height:260px; border-radius:50%;
    background: radial-gradient(circle, rgba(236,72,153,0.18), transparent 70%);
  }
  .dash-hero-inner { position:relative; z-index:2; display:flex; justify-content:space-between; align-items:center; gap:20px; flex-wrap:wrap; }
  .dash-hero h2 { font-size:24px; font-weight:800; margin-bottom:6px; letter-spacing:-0.5px; }
  .dash-hero .sub { font-size:13px; opacity:0.8; }
  .dash-hero-stats { display:flex; gap:28px; margin-top:4px; }
  .dash-hero-stats .s { display:flex; flex-direction:column; }
  .dash-hero-stats .s-val { font-size:20px; font-weight:800; }
  .dash-hero-stats .s-lbl { font-size:11px; opacity:0.7; font-weight:600; }
  .dash-hero-actions { display:flex; gap:10px; flex-wrap:wrap; }
  .hero-btn {
    background:rgba(255,255,255,0.12); color:#fff; border:1px solid rgba(255,255,255,0.18);
    padding:10px 16px; border-radius:10px; font-family:'Tajawal',sans-serif;
    font-size:13px; font-weight:700; text-decoration:none; backdrop-filter:blur(8px);
    transition:var(--transition); display:inline-flex; align-items:center; gap:7px;
  }
  .hero-btn:hover { background:rgba(255,255,255,0.22); transform:translateY(-2px); }
  .hero-btn.primary { background:#fff; color:#4338ca; }
  .hero-btn.primary:hover { background:#eef2ff; }

  /* KPI cards — modern with colored accent stripe */
  .kpi-strip { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:22px; }
  @media (max-width:1200px) { .kpi-strip { grid-template-columns:repeat(3,1fr); } }
  @media (max-width:640px)  { .kpi-strip { grid-template-columns:repeat(2,1fr); } }
  .kpi-box {
    position:relative;
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-lg);
    padding:18px; box-shadow:var(--shadow);
    transition:var(--transition);
  }
  .kpi-box:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
  .kpi-box::before {
    content:''; position:absolute; top:0; right:0; left:0; height:3px;
    background:var(--accent-color, var(--primary));
    border-radius:var(--radius-lg) var(--radius-lg) 0 0;
  }
  .kpi-box .row1 { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
  .kpi-box .ico {
    width:40px; height:40px; border-radius:11px;
    display:flex; align-items:center; justify-content:center; font-size:18px;
  }
  .kpi-box .trend {
    display:inline-flex; align-items:center; gap:3px;
    font-weight:700; font-size:11px; padding:3px 9px; border-radius:7px;
  }
  .kpi-box .trend.up   { background:var(--success-light); color:var(--success); }
  .kpi-box .trend.down { background:var(--danger-light);  color:var(--danger); }
  .kpi-box .trend.flat { background:var(--border-light);  color:var(--text-muted); }
  .kpi-box .lbl { font-size:12px; color:var(--text-muted); font-weight:600; margin-bottom:4px; }
  .kpi-box .val { font-size:26px; font-weight:800; color:var(--text-primary); letter-spacing:-0.5px; }
  .kpi-box .sub { font-size:11px; color:var(--text-muted); margin-top:5px; }

  /* Quick actions */
  .quick-actions-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:12px; margin-bottom:22px; }
  @media (max-width:1100px) { .quick-actions-grid { grid-template-columns:repeat(3,1fr); } }
  @media (max-width:560px)  { .quick-actions-grid { grid-template-columns:repeat(2,1fr); } }
  .qa-btn {
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
    padding:16px 10px; display:flex; flex-direction:column; align-items:center; gap:9px;
    text-decoration:none; transition:var(--transition); text-align:center;
    box-shadow:var(--shadow);
  }
  .qa-btn:hover {
    transform:translateY(-3px); border-color:var(--primary);
    box-shadow:var(--shadow-md);
  }
  .qa-btn .ico {
    width:44px; height:44px; border-radius:12px;
    display:flex; align-items:center; justify-content:center; font-size:20px;
  }
  .qa-btn .nm { font-size:12.5px; font-weight:700; color:var(--text-primary); }

  /* Rows */
  .dash-row { display:grid; gap:18px; margin-bottom:22px; }
  .dash-row.two  { grid-template-columns:1fr 1fr; }
  .dash-row.tt   { grid-template-columns:2fr 1fr; }
  .dash-row.ttt  { grid-template-columns:1.3fr 1fr 1fr; }
  @media (max-width:1100px) {
    .dash-row.two, .dash-row.tt, .dash-row.ttt { grid-template-columns:1fr; }
  }

  /* Lists */
  .task-list, .health-list, .activity-list { list-style:none; padding:4px 0; }
  .task-list li, .health-list li, .activity-list li {
    display:flex; align-items:center; gap:12px;
    padding:12px 20px; border-bottom:1px solid var(--border-light);
  }
  .task-list li:last-child, .health-list li:last-child, .activity-list li:last-child { border-bottom:none; }
  .task-list a { color:var(--text-primary); text-decoration:none; display:flex; align-items:center; gap:12px; flex:1; }
  .task-list a:hover .task-title { color:var(--primary); }
  .task-ico, .activity-ico {
    width:36px; height:36px; border-radius:10px;
    display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0;
  }
  .task-title { font-size:13px; font-weight:700; color:var(--text-primary); }
  .task-sub   { font-size:11px; color:var(--text-muted); margin-top:2px; }
  .task-count {
    padding:3px 10px; border-radius:8px;
    font-weight:800; font-size:12px; min-width:32px; text-align:center;
  }
  .task-count.danger { background:var(--danger-light); color:var(--danger); }
  .task-count.warn   { background:var(--warning-light); color:var(--warning); }
  .task-count.ok     { background:var(--success-light); color:var(--success); }

  .health-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
  .health-dot.ok     { background:var(--success); box-shadow:0 0 0 4px var(--success-light); }
  .health-dot.warn   { background:var(--warning); box-shadow:0 0 0 4px var(--warning-light); }
  .health-dot.danger { background:var(--danger);  box-shadow:0 0 0 4px var(--danger-light); }
  .health-item-body { flex:1; }
  .health-name  { font-size:13px; font-weight:700; color:var(--text-primary); }
  .health-value { font-size:11px; color:var(--text-muted); margin-top:2px; }

  .activity-ico.article { background:var(--primary-light);   color:var(--primary); }
  .activity-ico.comment { background:var(--success-light);   color:var(--success); }
  .activity-body { flex:1; min-width:0; }
  .activity-text { font-size:13px; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .activity-meta { font-size:11px; color:var(--text-muted); margin-top:2px; }

  .empty-state { text-align:center; color:var(--text-muted); padding:34px 16px; font-size:13px; }

  /* Top articles with progress bars */
  .top-list { padding:6px 0; }
  .top-row {
    padding:12px 20px; border-bottom:1px solid var(--border-light);
    display:flex; align-items:center; gap:12px;
  }
  .top-row:last-child { border:none; }
  .top-rank {
    width:26px; height:26px; border-radius:8px;
    background:var(--primary-soft); color:var(--primary);
    display:flex; align-items:center; justify-content:center;
    font-weight:800; font-size:12px; flex-shrink:0;
  }
  .top-rank.gold   { background:#fef3c7; color:#b45309; }
  .top-rank.silver { background:#f1f5f9; color:#475569; }
  .top-rank.bronze { background:#ffedd5; color:#c2410c; }
  .top-body { flex:1; min-width:0; }
  .top-title {
    font-size:13px; font-weight:700; color:var(--text-primary);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    display:block; text-decoration:none; margin-bottom:5px;
  }
  .top-title:hover { color:var(--primary); }
  .top-bar {
    height:5px; background:var(--border-light); border-radius:4px; overflow:hidden;
    position:relative;
  }
  .top-bar-fill {
    position:absolute; top:0; right:0; bottom:0;
    background:linear-gradient(90deg, var(--primary), #818cf8);
    border-radius:4px;
  }
  .top-meta { font-size:11px; color:var(--text-muted); margin-top:4px; display:flex; justify-content:space-between; }
  .top-views { font-weight:700; color:var(--primary); }

  /* Recent article row */
  .recent-article-row {
    display:flex; align-items:center; gap:12px;
    padding:12px 20px; border-bottom:1px solid var(--border-light); transition:var(--transition);
  }
  .recent-article-row:hover { background:var(--bg-hover); }
  .recent-article-row:last-child { border-bottom:none; }
  .ra-thumb {
    width:46px; height:46px; border-radius:10px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    background:linear-gradient(135deg,var(--primary),#818cf8); color:#fff;
    font-weight:700; font-size:15px; overflow:hidden;
  }
  .ra-thumb img { width:100%; height:100%; object-fit:cover; }
  .ra-body { flex:1; min-width:0; }
  .ra-title { font-size:13px; font-weight:700; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .ra-meta  { font-size:11px; color:var(--text-muted); margin-top:3px; display:flex; gap:10px; flex-wrap:wrap; }
  .ra-views { font-size:12px; font-weight:700; color:var(--primary); white-space:nowrap; }

  /* Category bars inside card body */
  .cat-list { padding:6px 0; }
  .cat-row { padding:10px 20px; }
  .cat-row + .cat-row { border-top:1px solid var(--border-light); }
  .cat-row-head { display:flex; justify-content:space-between; font-size:12.5px; font-weight:700; color:var(--text-primary); margin-bottom:6px; }
  .cat-row-head .cnt { color:var(--primary); }
  .cat-bar { height:6px; background:var(--border-light); border-radius:4px; overflow:hidden; position:relative; }
  .cat-bar-fill { position:absolute; top:0; right:0; bottom:0; background:linear-gradient(90deg, #6366f1, #a78bfa); border-radius:4px; }
</style>

<div class="content">

  <!-- HERO -->
  <div class="dash-hero">
    <div class="dash-hero-inner">
      <div>
        <h2><?php echo nf_greeting(); ?>، <?php echo e($adminName); ?> 👋</h2>
        <p class="sub"><?php echo date('l · j F Y'); ?></p>
        <div class="dash-hero-stats">
          <div class="s"><span class="s-val"><?php echo number_format($todayViews); ?></span><span class="s-lbl">مشاهدات اليوم</span></div>
          <div class="s"><span class="s-val"><?php echo number_format($todayArticles); ?></span><span class="s-lbl">مقال اليوم</span></div>
          <div class="s"><span class="s-val"><?php echo number_format($activeReaders); ?></span><span class="s-lbl">قراء الآن</span></div>
        </div>
      </div>
      <div class="dash-hero-actions">
        <a href="articles.php?action=add" class="hero-btn primary">✏️ مقال جديد</a>
        <a href="ticker.php?action=add" class="hero-btn">🔥 عاجل</a>
        <a href="analytics.php" class="hero-btn">📈 التحليلات</a>
      </div>
    </div>
  </div>

  <!-- KPI STRIP -->
  <div class="kpi-strip">
    <div class="kpi-box" style="--accent-color:#6366f1;">
      <div class="row1">
        <div class="ico" style="background:var(--primary-light); color:var(--primary);">👁</div>
        <span class="trend <?php echo $viewsGrowth>0?'up':($viewsGrowth<0?'down':'flat'); ?>">
          <?php echo $viewsGrowth>0?'▲':($viewsGrowth<0?'▼':'•'); ?> <?php echo abs($viewsGrowth); ?>%
        </span>
      </div>
      <div class="lbl">مشاهدات اليوم</div>
      <div class="val"><?php echo number_format($todayViews); ?></div>
      <div class="sub">أمس: <?php echo number_format($yesterdayViews); ?></div>
    </div>
    <div class="kpi-box" style="--accent-color:#10b981;">
      <div class="row1">
        <div class="ico" style="background:var(--success-light); color:var(--success);">📝</div>
        <span class="trend up">هذا الأسبوع</span>
      </div>
      <div class="lbl">مقالات اليوم</div>
      <div class="val"><?php echo number_format($todayArticles); ?></div>
      <div class="sub">الأسبوع: <?php echo number_format($weekArticles); ?> مقال</div>
    </div>
    <div class="kpi-box" style="--accent-color:#f59e0b;">
      <div class="row1">
        <div class="ico" style="background:var(--warning-light); color:var(--warning);">🔥</div>
        <span class="trend <?php echo $breakingCount>0?'up':'flat'; ?>">نشط</span>
      </div>
      <div class="lbl">أخبار عاجلة</div>
      <div class="val"><?php echo number_format($breakingCount); ?></div>
      <div class="sub">معروضة حالياً</div>
    </div>
    <div class="kpi-box" style="--accent-color:#8b5cf6;">
      <div class="row1">
        <div class="ico" style="background:var(--purple-light); color:var(--purple);">🌐</div>
        <span class="trend <?php echo $staleSourcesCount>0?'down':'up'; ?>">
          <?php echo $staleSourcesCount>0 ? $staleSourcesCount.' متأخر' : 'كلها تعمل'; ?>
        </span>
      </div>
      <div class="lbl">المصادر النشطة</div>
      <div class="val"><?php echo number_format($totalSources); ?></div>
      <div class="sub"><?php echo number_format($totalCategories); ?> قسم</div>
    </div>
    <div class="kpi-box" style="--accent-color:#ef4444;">
      <div class="row1">
        <div class="ico" style="background:var(--danger-light); color:var(--danger);">📖</div>
        <span class="trend <?php echo $activeReaders>0?'up':'flat'; ?>">مباشر</span>
      </div>
      <div class="lbl">قراء نشطون</div>
      <div class="val"><?php echo number_format($activeReaders); ?></div>
      <div class="sub">آخر 5 دقائق</div>
    </div>
  </div>

  <!-- QUICK ACTIONS -->
  <div class="quick-actions-grid">
    <a href="articles.php?action=add" class="qa-btn">
      <div class="ico" style="background:var(--primary-light); color:var(--primary);">✏️</div>
      <div class="nm">مقال جديد</div>
    </a>
    <a href="ticker.php?action=add" class="qa-btn">
      <div class="ico" style="background:var(--danger-light); color:var(--danger);">🔥</div>
      <div class="nm">خبر عاجل</div>
    </a>
    <a href="sources.php" class="qa-btn">
      <div class="ico" style="background:var(--success-light); color:var(--success);">🌐</div>
      <div class="nm">المصادر</div>
    </a>
    <a href="categories.php" class="qa-btn">
      <div class="ico" style="background:var(--warning-light); color:var(--warning);">📂</div>
      <div class="nm">الأقسام</div>
    </a>
    <a href="newsletter.php" class="qa-btn">
      <div class="ico" style="background:var(--purple-light); color:var(--purple);">📬</div>
      <div class="nm">النشرة</div>
    </a>
    <a href="ai.php" class="qa-btn">
      <div class="ico" style="background:var(--teal-light); color:var(--teal);">🤖</div>
      <div class="nm">الذكاء الاصطناعي</div>
    </a>
  </div>

  <!-- TASKS + HEALTH + TOP WEEK -->
  <div class="dash-row ttt">
    <div class="card">
      <div class="card-header">
        <div class="card-title">⚡ مهام تحتاج إجراء</div>
      </div>
      <?php
      $tasks = [];
      if ($hiddenCommentsCount > 0) $tasks[] = ['ico'=>'🚫', 'bg'=>'var(--danger-light)', 'color'=>'var(--danger)', 'title'=>'تعليقات مخفية', 'sub'=>'مراجعة يدوية', 'count'=>$hiddenCommentsCount, 'level'=>'danger', 'href'=>'articles.php'];
      if ($draftsCount > 0) $tasks[] = ['ico'=>'📝', 'bg'=>'var(--warning-light)', 'color'=>'var(--warning)', 'title'=>'مقالات مسودة', 'sub'=>'لم تُنشر بعد', 'count'=>$draftsCount, 'level'=>'warn', 'href'=>'articles.php?status=draft'];
      if ($noImageCount > 0) $tasks[] = ['ico'=>'🖼', 'bg'=>'var(--primary-light)', 'color'=>'var(--primary)', 'title'=>'مقالات بدون صورة', 'sub'=>'قد تؤثر على SEO', 'count'=>$noImageCount, 'level'=>'warn', 'href'=>'articles.php'];
      if ($noAiSummaryCount > 0) $tasks[] = ['ico'=>'🤖', 'bg'=>'var(--purple-light)', 'color'=>'var(--purple)', 'title'=>'بدون ملخص AI', 'sub'=>'من آخر 7 أيام', 'count'=>$noAiSummaryCount, 'level'=>'warn', 'href'=>'ai.php'];
      if ($staleSourcesCount > 0) $tasks[] = ['ico'=>'⏰', 'bg'=>'var(--warning-light)', 'color'=>'var(--warning)', 'title'=>'مصادر متأخرة', 'sub'=>'أكثر من 3 ساعات', 'count'=>$staleSourcesCount, 'level'=>'warn', 'href'=>'sources.php'];
      ?>
      <?php if ($tasks): ?>
        <ul class="task-list">
          <?php foreach (array_slice($tasks, 0, 5) as $t): ?>
            <li>
              <a href="<?php echo e($t['href']); ?>">
                <div class="task-ico" style="background:<?php echo $t['bg']; ?>; color:<?php echo $t['color']; ?>;"><?php echo $t['ico']; ?></div>
                <div style="flex:1;">
                  <div class="task-title"><?php echo e($t['title']); ?></div>
                  <div class="task-sub"><?php echo e($t['sub']); ?></div>
                </div>
                <span class="task-count <?php echo $t['level']; ?>"><?php echo number_format($t['count']); ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="empty-state">✨ كل شيء تمام — لا توجد مهام معلقة</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">🩺 صحة النظام</div>
      </div>
      <ul class="health-list">
        <li>
          <div class="health-dot <?php echo nf_health_status($lastRssFetch, 3, 12); ?>"></div>
          <div class="health-item-body">
            <div class="health-name">جلب RSS</div>
            <div class="health-value"><?php echo nf_time_since($lastRssFetch); ?></div>
          </div>
        </li>
        <li>
          <div class="health-dot <?php echo nf_health_status($lastAiRun, 6, 24); ?>"></div>
          <div class="health-item-body">
            <div class="health-name">تشغيل AI</div>
            <div class="health-value"><?php echo nf_time_since($lastAiRun); ?></div>
          </div>
        </li>
        <li>
          <div class="health-dot <?php echo nf_health_status($lastArticleAdded, 6, 24); ?>"></div>
          <div class="health-item-body">
            <div class="health-name">آخر مقال</div>
            <div class="health-value"><?php echo nf_time_since($lastArticleAdded); ?></div>
          </div>
        </li>
        <li>
          <div class="health-dot <?php echo $newsletterSubs > 0 ? 'ok' : 'warn'; ?>"></div>
          <div class="health-item-body">
            <div class="health-name">النشرة</div>
            <div class="health-value"><?php echo number_format($newsletterSubs); ?> مشترك</div>
          </div>
        </li>
      </ul>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">🏆 الأعلى هذا الأسبوع</div>
      </div>
      <?php if ($topArticlesWeek): ?>
        <div class="top-list">
          <?php foreach ($topArticlesWeek as $i => $a):
            $rank = $i+1;
            $rankCls = $rank===1?'gold':($rank===2?'silver':($rank===3?'bronze':''));
            $pct = $topWeekMax > 0 ? min(100, ((int)$a['view_count'] / $topWeekMax) * 100) : 0;
          ?>
            <div class="top-row">
              <div class="top-rank <?php echo $rankCls; ?>"><?php echo $rank; ?></div>
              <div class="top-body">
                <a class="top-title" href="articles.php?action=edit&id=<?php echo (int)$a['id']; ?>" title="<?php echo e($a['title']); ?>"><?php echo e($a['title']); ?></a>
                <div class="top-bar"><div class="top-bar-fill" style="width:<?php echo (float)$pct; ?>%"></div></div>
                <div class="top-meta">
                  <span><?php echo e($a['cat_name'] ?? '—'); ?></span>
                  <span class="top-views">👁 <?php echo number_format((int)$a['view_count']); ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state">لا توجد بيانات بعد</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- TRENDS + ACTIVITY -->
  <div class="dash-row tt">
    <div class="card">
      <div class="card-header">
        <div class="card-title">📊 اتجاه آخر 30 يوم</div>
        <a href="analytics.php" class="btn-outline">تفاصيل أكثر</a>
      </div>
      <div class="card-body"><div style="position:relative;height:270px;"><canvas id="trendChart"></canvas></div></div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">📡 النشاط الأخير</div>
      </div>
      <?php if ($activityFeed): ?>
        <ul class="activity-list">
          <?php foreach ($activityFeed as $ev): ?>
            <li>
              <div class="activity-ico <?php echo e($ev['kind']); ?>">
                <?php echo $ev['kind']==='article' ? '📝' : '💬'; ?>
              </div>
              <div class="activity-body">
                <div class="activity-text">
                  <?php if ($ev['kind']==='article'): ?>
                    <a href="articles.php?action=edit&id=<?php echo (int)$ev['ref_id']; ?>" style="color:inherit;text-decoration:none;"><?php echo e($ev['text']); ?></a>
                  <?php else: ?>
                    تعليق من <strong><?php echo e($ev['who'] ?: 'زائر'); ?></strong>
                  <?php endif; ?>
                </div>
                <div class="activity-meta">
                  <?php if ($ev['kind']==='article' && !empty($ev['who'])): ?><?php echo e($ev['who']); ?> · <?php endif; ?>
                  <?php echo nf_time_since($ev['at']); ?>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="empty-state">لا يوجد نشاط حديث</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- CATEGORIES + RECENT -->
  <div class="dash-row tt">
    <div class="card">
      <div class="card-header">
        <div class="card-title">📰 أحدث المقالات</div>
        <a href="articles.php" class="btn-outline">عرض الكل</a>
      </div>
      <div>
        <?php foreach ($recentArticles as $a): ?>
          <div class="recent-article-row">
            <div class="ra-thumb">
              <?php if (!empty($a['image_url'])): ?>
                <img src="<?php echo e($a['image_url']); ?>" alt="" loading="lazy">
              <?php else: ?>
                <?php echo e($a['logo_letter'] ?? mb_substr($a['title'] ?? '?', 0, 1)); ?>
              <?php endif; ?>
            </div>
            <div class="ra-body">
              <div class="ra-title">
                <?php if ($a['is_breaking']): ?><span class="badge" style="background:var(--danger);color:#fff;padding:1px 7px;border-radius:6px;font-size:10px;margin-left:6px;">عاجل</span><?php endif; ?>
                <a href="articles.php?action=edit&id=<?php echo (int)$a['id']; ?>" style="color:inherit;text-decoration:none;"><?php echo e($a['title']); ?></a>
              </div>
              <div class="ra-meta">
                <?php if (!empty($a['source_name'])): ?><span><?php echo e($a['source_name']); ?></span><?php endif; ?>
                <?php if (!empty($a['cat_name'])): ?><span>· <?php echo e($a['cat_name']); ?></span><?php endif; ?>
                <span>· <?php echo nf_time_since($a['created_at']); ?></span>
                <?php if ($a['status'] !== 'published'): ?><span style="color:var(--warning);">· مسودة</span><?php endif; ?>
              </div>
            </div>
            <div class="ra-views">👁 <?php echo number_format((int)$a['view_count']); ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($recentArticles)): ?>
          <div class="empty-state">لا توجد مقالات بعد</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">📂 توزيع الأقسام</div>
      </div>
      <?php
      $catMax = 0;
      foreach ($articlesByCategory as $c) $catMax = max($catMax, (int)$c['count']);
      ?>
      <?php if ($articlesByCategory): ?>
        <div class="cat-list">
          <?php foreach ($articlesByCategory as $c):
            $pct = $catMax > 0 ? min(100, ((int)$c['count'] / $catMax) * 100) : 0;
          ?>
            <div class="cat-row">
              <div class="cat-row-head">
                <span><?php echo e($c['name']); ?></span>
                <span class="cnt"><?php echo number_format((int)$c['count']); ?></span>
              </div>
              <div class="cat-bar"><div class="cat-bar-fill" style="width:<?php echo (float)$pct; ?>%"></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state">لا توجد أقسام بعد</div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function(){
  Chart.defaults.font.family = "'Tajawal', sans-serif";
  Chart.defaults.color = '#64748b';

  const el = document.getElementById('trendChart');
  if (!el) return;
  const ctx = el.getContext('2d');
  const grad = ctx.createLinearGradient(0, 0, 0, 270);
  grad.addColorStop(0, 'rgba(99,102,241,0.30)');
  grad.addColorStop(1, 'rgba(99,102,241,0.00)');
  new Chart(el, {
    type: 'line',
    data: {
      labels: <?php echo json_encode($chartDays, JSON_UNESCAPED_UNICODE); ?>,
      datasets: [
        {
          label: 'مشاهدات',
          data: <?php echo json_encode($chartViewSeries); ?>,
          borderColor: '#6366f1', backgroundColor: grad,
          tension: 0.4, fill: true, pointRadius: 0, pointHoverRadius: 5, borderWidth: 2.5,
          yAxisID: 'y'
        },
        {
          label: 'مقالات',
          data: <?php echo json_encode($chartArticleSeries); ?>,
          borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.08)',
          tension: 0.4, fill: false, pointRadius: 0, pointHoverRadius: 5, borderWidth: 2,
          borderDash: [4,4],
          yAxisID: 'y1'
        }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 }, padding: 14 } },
        tooltip: { backgroundColor: '#0f172a', titleColor: '#fff', bodyColor: '#cbd5e1', cornerRadius: 8, padding: 10 }
      },
      scales: {
        y:  { beginAtZero: true, position: 'right', grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 } } },
        y1: { beginAtZero: true, position: 'left',  grid: { display: false }, ticks: { font: { size: 11 } } },
        x:  { grid: { display: false }, ticks: { maxTicksLimit: 10, font: { size: 11 } } }
      }
    }
  });
})();
</script>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
