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

$todayArticles = (int)$db->query("SELECT COUNT(*) FROM articles WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$weekArticles  = (int)$db->query("SELECT COUNT(*) FROM articles WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$breakingCount = (int)$db->query("SELECT COUNT(*) FROM articles WHERE is_breaking=1")->fetchColumn();

// ---- Attention queue (things admin might want to handle) -------------
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
try {
    $lastRssFetch = $db->query("SELECT MAX(last_fetched_at) FROM sources WHERE last_fetched_at IS NOT NULL")->fetchColumn();
} catch (Throwable $e) {}
$lastAiRun = null;
try {
    $lastAiRun = $db->query("SELECT MAX(ai_processed_at) FROM articles WHERE ai_processed_at IS NOT NULL")->fetchColumn();
} catch (Throwable $e) {}
$lastArticleAdded = $db->query("SELECT MAX(created_at) FROM articles")->fetchColumn();
$lastNewsletter = null;
try {
    $lastNewsletter = $db->query("SELECT MAX(sent_at) FROM newsletter_sends")->fetchColumn();
} catch (Throwable $e) {}
$newsletterSubs = 0;
try {
    $newsletterSubs = (int)$db->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status='active'")->fetchColumn();
} catch (Throwable $e) {}
$activeReaders = 0;
try {
    $activeReaders = (int)$db->query(
        "SELECT COUNT(DISTINCT article_id) FROM article_view_events WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    )->fetchColumn();
} catch (Throwable $e) {}

// ---- Trends chart (30-day views + articles) --------------------------
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

// ---- Top lists --------------------------------------------------------
$articlesByCategory = $db->query(
    "SELECT c.name, COUNT(a.id) AS count FROM categories c
     LEFT JOIN articles a ON a.category_id=c.id
     GROUP BY c.id, c.name ORDER BY count DESC LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC);
$recentArticles = $db->query(
    "SELECT a.id, a.title, a.view_count, a.status, a.is_breaking, a.image_url,
            a.created_at, c.name AS cat_name, c.css_class, s.name AS source_name, s.logo_letter
     FROM articles a
     LEFT JOIN categories c ON a.category_id=c.id
     LEFT JOIN sources s ON a.source_id=s.id
     ORDER BY a.created_at DESC LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC);

// ---- Live activity feed (articles + recent comments) -----------------
$activityFeed = [];
try {
    $rows = $db->query(
        "SELECT 'article' AS kind, a.id AS ref_id, a.title AS text, a.created_at AS at,
                s.name AS who
         FROM articles a LEFT JOIN sources s ON s.id=a.source_id
         WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
         ORDER BY a.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
    $activityFeed = array_merge($activityFeed, $rows);
} catch (Throwable $e) {}
try {
    $rows = $db->query(
        "SELECT 'comment' AS kind, c.article_id AS ref_id, a.title AS text, c.created_at AS at,
                u.username AS who
         FROM article_comments c
         JOIN articles a ON a.id=c.article_id
         LEFT JOIN users u ON u.id=c.user_id
         WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND c.is_hidden=0
         ORDER BY c.created_at DESC LIMIT 6"
    )->fetchAll(PDO::FETCH_ASSOC);
    $activityFeed = array_merge($activityFeed, $rows);
} catch (Throwable $e) {}
usort($activityFeed, fn($a, $b) => strcmp($b['at'], $a['at']));
$activityFeed = array_slice($activityFeed, 0, 10);

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

$pageTitle  = 'لوحة التحكم - نيوز فيد';
$activePage = 'index';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<style>
  /* Dashboard-specific styles */
  .dash-hero {
    background: linear-gradient(135deg, #4a7fcb 0%, #6b9fe8 100%);
    color:#fff; padding:22px 26px; border-radius:18px;
    margin-bottom:22px; display:flex; justify-content:space-between; align-items:center; gap:16px;
    box-shadow: 0 8px 28px rgba(74,127,203,0.22);
  }
  .dash-hero h2 { font-size:22px; font-weight:800; margin-bottom:6px; }
  .dash-hero p  { font-size:13px; opacity:0.85; }
  .dash-hero-actions { display:flex; gap:10px; flex-wrap:wrap; }
  .hero-btn {
    background:rgba(255,255,255,0.18); color:#fff; border:1.5px solid rgba(255,255,255,0.30);
    padding:9px 16px; border-radius:11px; font-family:'Tajawal',sans-serif;
    font-size:13px; font-weight:700; text-decoration:none; backdrop-filter:blur(6px);
    transition:var(--transition); display:inline-flex; align-items:center; gap:8px;
  }
  .hero-btn:hover { background:rgba(255,255,255,0.32); transform:translateY(-1px); }

  .kpi-strip { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:22px; }
  @media (max-width:1100px) { .kpi-strip { grid-template-columns:repeat(3,1fr); } }
  @media (max-width:640px)  { .kpi-strip { grid-template-columns:repeat(2,1fr); } }
  .kpi-box {
    background:var(--bg-card); border:1.5px solid var(--border); border-radius:14px;
    padding:14px 16px; display:flex; flex-direction:column; gap:4px;
    box-shadow:var(--shadow);
  }
  .kpi-box .ico {
    width:34px; height:34px; border-radius:10px;
    display:flex; align-items:center; justify-content:center; font-size:16px;
    margin-bottom:4px;
  }
  .kpi-box .lbl { font-size:11px; color:var(--text-muted); font-weight:700; letter-spacing:.3px; }
  .kpi-box .val { font-size:22px; font-weight:800; color:var(--text-primary); }
  .kpi-box .sub { font-size:11px; color:var(--text-muted); }
  .kpi-box .trend { display:inline-block; font-weight:700; font-size:11px; padding:1px 7px; border-radius:6px; }
  .kpi-box .trend.up   { background:var(--success-light); color:var(--success); }
  .kpi-box .trend.down { background:var(--danger-light);  color:var(--danger); }
  .kpi-box .trend.flat { background:var(--border-light);  color:var(--text-muted); }

  .dash-row { display:grid; gap:18px; margin-bottom:22px; }
  .dash-row.two  { grid-template-columns:1fr 1fr; }
  .dash-row.tt   { grid-template-columns:2fr 1fr; }
  @media (max-width:900px) {
    .dash-row.two, .dash-row.tt { grid-template-columns:1fr; }
  }

  .task-list, .health-list, .activity-list { list-style:none; padding:6px 0; }
  .task-list li, .health-list li, .activity-list li {
    display:flex; align-items:center; gap:12px;
    padding:11px 20px; border-bottom:1px solid var(--border-light);
  }
  .task-list li:last-child, .health-list li:last-child, .activity-list li:last-child { border-bottom:none; }
  .task-list a { color:var(--text-primary); text-decoration:none; display:flex; align-items:center; gap:12px; flex:1; }
  .task-list a:hover .task-title { color:var(--primary); }
  .task-ico, .activity-ico, .health-dot-lg {
    width:34px; height:34px; border-radius:10px;
    display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0;
  }
  .task-title { font-size:13px; font-weight:700; color:var(--text-primary); }
  .task-sub   { font-size:11px; color:var(--text-muted); margin-top:2px; }
  .task-count {
    background:var(--danger); color:#fff; padding:3px 10px; border-radius:12px;
    font-weight:800; font-size:12px; min-width:32px; text-align:center;
  }
  .task-count.warn { background:var(--warning); color:#fff; }
  .task-count.ok   { background:var(--success); color:#fff; }

  .health-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
  .health-dot.ok     { background:var(--success); box-shadow:0 0 0 3px var(--success-light); }
  .health-dot.warn   { background:var(--warning); box-shadow:0 0 0 3px var(--warning-light); }
  .health-dot.danger { background:var(--danger);  box-shadow:0 0 0 3px var(--danger-light); }
  .health-item-body { flex:1; }
  .health-name  { font-size:13px; font-weight:700; color:var(--text-primary); }
  .health-value { font-size:11px; color:var(--text-muted); margin-top:2px; }

  .activity-ico.article { background:var(--primary-light);   color:var(--primary); }
  .activity-ico.comment { background:var(--success-light);   color:var(--success); }
  .activity-body { flex:1; min-width:0; }
  .activity-text { font-size:13px; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .activity-meta { font-size:11px; color:var(--text-muted); margin-top:2px; }

  .quick-actions-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:12px; margin-bottom:22px; }
  @media (max-width:1100px) { .quick-actions-grid { grid-template-columns:repeat(3,1fr); } }
  @media (max-width:560px)  { .quick-actions-grid { grid-template-columns:repeat(2,1fr); } }
  .qa-btn {
    background:var(--bg-card); border:1.5px solid var(--border); border-radius:14px;
    padding:14px 12px; display:flex; flex-direction:column; align-items:center; gap:8px;
    text-decoration:none; transition:var(--transition); text-align:center;
  }
  .qa-btn:hover {
    transform:translateY(-2px); border-color:var(--primary);
    box-shadow:var(--shadow-md); background:var(--primary-soft);
  }
  .qa-btn .ico {
    width:42px; height:42px; border-radius:12px;
    display:flex; align-items:center; justify-content:center; font-size:19px;
    background:var(--primary-light);
  }
  .qa-btn .nm { font-size:12.5px; font-weight:700; color:var(--text-primary); }

  .empty-state { text-align:center; color:var(--text-muted); padding:30px 16px; font-size:13px; }

  .recent-article-row {
    display:flex; align-items:center; gap:12px;
    padding:11px 20px; border-bottom:1px solid var(--border-light); transition:var(--transition);
  }
  .recent-article-row:hover { background:var(--bg-input); }
  .recent-article-row:last-child { border-bottom:none; }
  .ra-thumb {
    width:44px; height:44px; border-radius:10px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    background:linear-gradient(135deg,var(--primary),#8ab8f0); color:#fff;
    font-weight:700; font-size:15px; overflow:hidden;
  }
  .ra-thumb img { width:100%; height:100%; object-fit:cover; }
  .ra-body { flex:1; min-width:0; }
  .ra-title { font-size:13px; font-weight:700; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .ra-meta  { font-size:11px; color:var(--text-muted); margin-top:3px; display:flex; gap:10px; flex-wrap:wrap; }
  .ra-views { font-size:12px; font-weight:700; color:var(--text-secondary); }
</style>

<div class="content">

  <!-- HERO -->
  <div class="dash-hero">
    <div>
      <h2>مرحباً، <?php echo e($adminName); ?> 👋</h2>
      <p>نظرة سريعة على الموقع — <?php echo date('l j F Y'); ?></p>
    </div>
    <div class="dash-hero-actions">
      <a href="articles.php?action=add" class="hero-btn">✏️ مقال جديد</a>
      <a href="ticker.php?action=add" class="hero-btn">🔥 خبر عاجل</a>
      <a href="analytics.php" class="hero-btn">📈 التحليلات</a>
    </div>
  </div>

  <!-- KPI STRIP -->
  <div class="kpi-strip">
    <div class="kpi-box">
      <div class="ico" style="background:var(--primary-light); color:var(--primary);">👁</div>
      <div class="lbl">مشاهدات اليوم</div>
      <div class="val"><?php echo number_format($todayViews); ?></div>
      <div class="sub">
        <span class="trend <?php echo $viewsGrowth>0?'up':($viewsGrowth<0?'down':'flat'); ?>">
          <?php echo $viewsGrowth>0?'▲':($viewsGrowth<0?'▼':'•'); ?> <?php echo abs($viewsGrowth); ?>%
        </span>
        مقابل أمس
      </div>
    </div>
    <div class="kpi-box">
      <div class="ico" style="background:var(--success-light); color:var(--success);">📝</div>
      <div class="lbl">مقالات اليوم</div>
      <div class="val"><?php echo number_format($todayArticles); ?></div>
      <div class="sub">هذا الأسبوع: <?php echo number_format($weekArticles); ?></div>
    </div>
    <div class="kpi-box">
      <div class="ico" style="background:var(--warning-light); color:var(--warning);">🔥</div>
      <div class="lbl">أخبار عاجلة</div>
      <div class="val"><?php echo number_format($breakingCount); ?></div>
      <div class="sub">نشطة حالياً</div>
    </div>
    <div class="kpi-box">
      <div class="ico" style="background:var(--purple-light); color:var(--purple);">🌐</div>
      <div class="lbl">المصادر النشطة</div>
      <div class="val"><?php echo number_format($totalSources); ?></div>
      <div class="sub"><?php echo number_format($totalCategories); ?> قسم</div>
    </div>
    <div class="kpi-box">
      <div class="ico" style="background:var(--danger-light); color:var(--danger);">📖</div>
      <div class="lbl">قراء نشطون الآن</div>
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

  <!-- TASKS + HEALTH -->
  <div class="dash-row two">
    <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="card-title-dot dot-red" style="width:8px;height:8px;background:var(--danger);border-radius:50%;display:inline-block;"></span>مهام تحتاج إجراء</div>
      </div>
      <?php
      $tasks = [];
      if ($hiddenCommentsCount > 0) {
          $tasks[] = ['ico'=>'🚫', 'bg'=>'var(--danger-light)', 'color'=>'var(--danger)',
                      'title'=>'تعليقات مخفية', 'sub'=>'مراجعة يدوية',
                      'count'=>$hiddenCommentsCount, 'level'=>'danger', 'href'=>'articles.php'];
      }
      if ($draftsCount > 0) {
          $tasks[] = ['ico'=>'📝', 'bg'=>'var(--warning-light)', 'color'=>'var(--warning)',
                      'title'=>'مقالات مسودة', 'sub'=>'لم تُنشر بعد',
                      'count'=>$draftsCount, 'level'=>'warn', 'href'=>'articles.php?status=draft'];
      }
      if ($noImageCount > 0) {
          $tasks[] = ['ico'=>'🖼', 'bg'=>'var(--primary-light)', 'color'=>'var(--primary)',
                      'title'=>'مقالات بدون صورة', 'sub'=>'قد تؤثر على الظهور في البحث',
                      'count'=>$noImageCount, 'level'=>'warn', 'href'=>'articles.php'];
      }
      if ($noAiSummaryCount > 0) {
          $tasks[] = ['ico'=>'🤖', 'bg'=>'var(--purple-light)', 'color'=>'var(--purple)',
                      'title'=>'مقالات بدون ملخص AI', 'sub'=>'من آخر 7 أيام',
                      'count'=>$noAiSummaryCount, 'level'=>'warn', 'href'=>'ai.php'];
      }
      if ($staleSourcesCount > 0) {
          $tasks[] = ['ico'=>'⏰', 'bg'=>'var(--warning-light)', 'color'=>'var(--warning)',
                      'title'=>'مصادر لم تُحدَّث', 'sub'=>'أكثر من 3 ساعات',
                      'count'=>$staleSourcesCount, 'level'=>'warn', 'href'=>'sources.php'];
      }
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
        <div class="card-title"><span class="card-title-dot" style="width:8px;height:8px;background:var(--success);border-radius:50%;display:inline-block;"></span>صحة النظام</div>
      </div>
      <ul class="health-list">
        <li>
          <div class="health-dot <?php echo nf_health_status($lastRssFetch, 3, 12); ?>"></div>
          <div class="health-item-body">
            <div class="health-name">آخر جلب RSS</div>
            <div class="health-value"><?php echo nf_time_since($lastRssFetch); ?></div>
          </div>
        </li>
        <li>
          <div class="health-dot <?php echo nf_health_status($lastAiRun, 6, 24); ?>"></div>
          <div class="health-item-body">
            <div class="health-name">آخر تشغيل AI</div>
            <div class="health-value"><?php echo nf_time_since($lastAiRun); ?></div>
          </div>
        </li>
        <li>
          <div class="health-dot <?php echo nf_health_status($lastArticleAdded, 6, 24); ?>"></div>
          <div class="health-item-body">
            <div class="health-name">آخر مقال مضاف</div>
            <div class="health-value"><?php echo nf_time_since($lastArticleAdded); ?></div>
          </div>
        </li>
        <li>
          <div class="health-dot <?php echo $newsletterSubs > 0 ? 'ok' : 'warn'; ?>"></div>
          <div class="health-item-body">
            <div class="health-name">مشتركو النشرة</div>
            <div class="health-value"><?php echo number_format($newsletterSubs); ?> مشترك<?php echo $lastNewsletter ? ' · آخر إرسال ' . nf_time_since($lastNewsletter) : ''; ?></div>
          </div>
        </li>
        <li>
          <div class="health-dot ok"></div>
          <div class="health-item-body">
            <div class="health-name">إجمالي المقالات</div>
            <div class="health-value"><?php echo number_format($totalArticles); ?> مقال · <?php echo number_format($totalViews); ?> مشاهدة إجمالية</div>
          </div>
        </li>
      </ul>
    </div>
  </div>

  <!-- TRENDS + ACTIVITY -->
  <div class="dash-row tt">
    <div class="card">
      <div class="card-header">
        <div class="card-title">📊 اتجاه آخر 30 يوم</div>
        <a href="analytics.php" class="btn-outline">تفاصيل أكثر</a>
      </div>
      <div class="card-body"><div style="position:relative;height:260px;"><canvas id="trendChart"></canvas></div></div>
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
                    مقال جديد: <a href="articles.php?action=edit&id=<?php echo (int)$ev['ref_id']; ?>" style="color:inherit;"><?php echo e($ev['text']); ?></a>
                  <?php else: ?>
                    تعليق من <strong><?php echo e($ev['who'] ?: 'زائر'); ?></strong> على "<?php echo e(mb_substr($ev['text'],0,40)); ?>"
                  <?php endif; ?>
                </div>
                <div class="activity-meta">
                  <?php if ($ev['kind']==='article' && !empty($ev['who'])): ?>
                    <?php echo e($ev['who']); ?> ·
                  <?php endif; ?>
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

  <!-- RECENT ARTICLES -->
  <div class="card" style="margin-bottom:22px;">
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
              <?php if ($a['is_breaking']): ?><span class="badge badge-danger" style="background:var(--danger);color:#fff;padding:1px 7px;border-radius:6px;font-size:10px;margin-left:6px;">عاجل</span><?php endif; ?>
              <a href="articles.php?action=edit&id=<?php echo (int)$a['id']; ?>" style="color:inherit;text-decoration:none;"><?php echo e($a['title']); ?></a>
            </div>
            <div class="ra-meta">
              <?php if (!empty($a['source_name'])): ?><span><?php echo e($a['source_name']); ?></span><?php endif; ?>
              <?php if (!empty($a['cat_name'])): ?><span>· <?php echo e($a['cat_name']); ?></span><?php endif; ?>
              <span>· <?php echo nf_time_since($a['created_at']); ?></span>
              <?php if ($a['status'] !== 'published'): ?>
                <span style="color:var(--warning);">· مسودة</span>
              <?php endif; ?>
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

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function(){
  Chart.defaults.font.family = "'Tajawal', sans-serif";
  Chart.defaults.color = '#6b7a95';

  const el = document.getElementById('trendChart');
  if (!el) return;
  new Chart(el, {
    type: 'line',
    data: {
      labels: <?php echo json_encode($chartDays, JSON_UNESCAPED_UNICODE); ?>,
      datasets: [
        {
          label: 'مشاهدات',
          data: <?php echo json_encode($chartViewSeries); ?>,
          borderColor: '#4a7fcb', backgroundColor: 'rgba(74,127,203,0.15)',
          tension: 0.35, fill: true, pointRadius: 2, pointHoverRadius: 5, borderWidth: 2.5,
          yAxisID: 'y'
        },
        {
          label: 'مقالات',
          data: <?php echo json_encode($chartArticleSeries); ?>,
          borderColor: '#52b788', backgroundColor: 'rgba(82,183,136,0.12)',
          tension: 0.35, fill: false, pointRadius: 2, pointHoverRadius: 5, borderWidth: 2,
          borderDash: [4,4],
          yAxisID: 'y1'
        }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
      scales: {
        y:  { beginAtZero: true, position: 'right', grid: { color: '#edf1fa' } },
        y1: { beginAtZero: true, position: 'left',  grid: { display: false } },
        x:  { grid: { display: false }, ticks: { maxTicksLimit: 10 } }
      }
    }
  });
})();
</script>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
