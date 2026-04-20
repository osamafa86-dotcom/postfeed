<?php
/**
 * Analytics dashboard — detailed traffic stats.
 *
 * KPIs with period comparison, time-series charts, top content,
 * traffic sources, category/source breakdown, and engagement.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/view_tracking.php';
requireRole('viewer');

$db = getDB();
view_tracking_ensure_tables($db);

function nf_growth_pct(int $current, int $previous): float {
    if ($previous <= 0) return $current > 0 ? 100.0 : 0.0;
    return round((($current - $previous) / $previous) * 100, 1);
}

function nf_fetch_views_in_range(PDO $db, string $from, string $to): int {
    try {
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(total_views),0) FROM daily_view_stats
             WHERE stat_date BETWEEN ? AND ?"
        );
        $stmt->execute([$from, $to]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekFrom  = date('Y-m-d', strtotime('-6 days'));
$prevWeekFrom = date('Y-m-d', strtotime('-13 days'));
$prevWeekTo   = date('Y-m-d', strtotime('-7 days'));
$monthFrom = date('Y-m-d', strtotime('-29 days'));
$prevMonthFrom = date('Y-m-d', strtotime('-59 days'));
$prevMonthTo   = date('Y-m-d', strtotime('-30 days'));

$viewsToday     = nf_fetch_views_in_range($db, $today, $today);
$viewsYesterday = nf_fetch_views_in_range($db, $yesterday, $yesterday);
$viewsWeek      = nf_fetch_views_in_range($db, $weekFrom, $today);
$viewsPrevWeek  = nf_fetch_views_in_range($db, $prevWeekFrom, $prevWeekTo);
$viewsMonth     = nf_fetch_views_in_range($db, $monthFrom, $today);
$viewsPrevMonth = nf_fetch_views_in_range($db, $prevMonthFrom, $prevMonthTo);
$viewsAllTime   = (int)$db->query("SELECT COALESCE(SUM(view_count),0) FROM articles")->fetchColumn();

$growthToday = nf_growth_pct($viewsToday, $viewsYesterday);
$growthWeek  = nf_growth_pct($viewsWeek, $viewsPrevWeek);
$growthMonth = nf_growth_pct($viewsMonth, $viewsPrevMonth);

$dailyViews30 = get_daily_views(30);
$chartDays    = []; $chartViews = [];
foreach ($dailyViews30 as $d => $v) {
    $chartDays[]  = date('m/d', strtotime($d));
    $chartViews[] = (int)$v;
}

// Last 24h hourly from article_view_events (48h retention)
$hourlyLabels = []; $hourlyData = [];
try {
    $hourlyMap = [];
    $stmt = $db->query(
        "SELECT DATE_FORMAT(viewed_at, '%Y-%m-%d %H:00') AS h, COUNT(*) AS c
         FROM article_view_events
         WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         GROUP BY h"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $hourlyMap[$r['h']] = (int)$r['c'];
    }
    for ($i = 23; $i >= 0; $i--) {
        $t = strtotime("-{$i} hours");
        $k = date('Y-m-d H:00', $t);
        $hourlyLabels[] = date('H:00', $t);
        $hourlyData[]   = $hourlyMap[$k] ?? 0;
    }
} catch (Throwable $e) {
    for ($i = 23; $i >= 0; $i--) {
        $hourlyLabels[] = date('H:00', strtotime("-{$i} hours"));
        $hourlyData[]   = 0;
    }
}

// Day-of-week pattern (last 30 days)
$dowLabels = ['الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
$dowData   = [0,0,0,0,0,0,0];
foreach ($dailyViews30 as $d => $v) {
    $dow = (int)date('w', strtotime($d));
    $dowData[$dow] += (int)$v;
}

$activeReaders = 0;
try {
    $activeReaders = (int)$db->query(
        "SELECT COUNT(DISTINCT article_id) FROM article_view_events
         WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    )->fetchColumn();
} catch (Throwable $e) {}

// Top articles by period
function nf_top_articles_by_view_events(PDO $db, string $interval, int $limit = 10): array {
    try {
        $stmt = $db->prepare(
            "SELECT a.id, a.title, a.view_count, c.name AS cat_name, c.css_class, COUNT(e.id) AS period_views
             FROM article_view_events e
             JOIN articles a ON a.id = e.article_id
             LEFT JOIN categories c ON c.id = a.category_id
             WHERE e.viewed_at >= DATE_SUB(NOW(), INTERVAL {$interval})
             GROUP BY a.id, a.title, a.view_count, c.name, c.css_class
             ORDER BY period_views DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

$topToday = nf_top_articles_by_view_events($db, '24 HOUR', 10);
$topWeek  = nf_top_articles_by_view_events($db, '7 DAY', 10);
$topAllTime = $db->query(
    "SELECT a.id, a.title, a.view_count, c.name AS cat_name, c.css_class
     FROM articles a LEFT JOIN categories c ON c.id = a.category_id
     WHERE a.status='published'
     ORDER BY a.view_count DESC LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);

// Traffic sources (last 7 days)
$sourcesTotals = [];
$topDomains = [];
try {
    $stmt = $db->query(
        "SELECT source_type, SUM(views) AS v FROM referrer_stats
         WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
         GROUP BY source_type ORDER BY v DESC"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sourcesTotals[$r['source_type']] = (int)$r['v'];
    }
    $stmt = $db->query(
        "SELECT source_domain, SUM(views) AS v FROM referrer_stats
         WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
           AND source_type NOT IN ('direct','internal')
           AND source_domain <> ''
         GROUP BY source_domain ORDER BY v DESC LIMIT 10"
    );
    $topDomains = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$sourceLabels = [
    'search'   => '🔍 محركات البحث',
    'social'   => '📱 شبكات اجتماعية',
    'direct'   => '↳ مباشر',
    'internal' => '🔗 داخلي',
    'other'    => '🌐 أخرى',
];

// Content breakdowns
$viewsByCategory = $db->query(
    "SELECT c.name, c.css_class, COALESCE(SUM(a.view_count),0) AS v
     FROM categories c LEFT JOIN articles a ON a.category_id = c.id
     GROUP BY c.id, c.name, c.css_class ORDER BY v DESC LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);

$viewsBySource = $db->query(
    "SELECT s.name, COALESCE(SUM(a.view_count),0) AS v
     FROM sources s LEFT JOIN articles a ON a.source_id = s.id
     GROUP BY s.id, s.name ORDER BY v DESC LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);

// Engagement
$totalUsers = 0; $newUsersWeek = 0;
$totalSubs = 0; $newSubsWeek = 0;
try { $totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(); } catch (Throwable $e) {}
try { $newUsersWeek = (int)$db->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(); } catch (Throwable $e) {}
try { $totalSubs = (int)$db->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status='active'")->fetchColumn(); } catch (Throwable $e) {}
try { $newSubsWeek = (int)$db->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(); } catch (Throwable $e) {}

$mostBookmarked = [];
try {
    $mostBookmarked = $db->query(
        "SELECT a.id, a.title, COUNT(b.id) AS saves
         FROM bookmarks b JOIN articles a ON a.id = b.article_id
         GROUP BY a.id, a.title ORDER BY saves DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$mostCommented = [];
try {
    $mostCommented = $db->query(
        "SELECT a.id, a.title, COUNT(c.id) AS cnt
         FROM article_comments c JOIN articles a ON a.id = c.article_id
         WHERE c.status='approved'
         GROUP BY a.id, a.title ORDER BY cnt DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$pageTitle  = 'التحليلات - نيوز فيد';
$activePage = 'analytics';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<style>
  .kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:22px; }
  @media (max-width:1100px) { .kpi-grid { grid-template-columns:repeat(2,1fr); } }
  @media (max-width:560px)  { .kpi-grid { grid-template-columns:1fr; } }
  .kpi-card {
    background:var(--bg-card); border:1.5px solid var(--border);
    border-radius:16px; padding:18px; box-shadow:var(--shadow);
    position:relative; overflow:hidden;
  }
  .kpi-card::after {
    content:''; position:absolute; inset:0 0 auto 0; height:4px;
    background:linear-gradient(90deg,var(--primary),#8ab8f0);
  }
  .kpi-label { font-size:11px; color:var(--text-muted); font-weight:700; letter-spacing:.5px; }
  .kpi-value { font-size:26px; font-weight:800; color:var(--text-primary); margin-top:6px; }
  .kpi-sub   { font-size:11px; color:var(--text-muted); margin-top:4px; display:flex; align-items:center; gap:6px; }
  .kpi-trend { display:inline-block; padding:2px 8px; border-radius:10px; font-weight:700; font-size:11px; }
  .kpi-trend.up   { background:var(--success-light); color:var(--success); }
  .kpi-trend.down { background:var(--danger-light);  color:var(--danger); }
  .kpi-trend.flat { background:var(--border-light);  color:var(--text-muted); }

  .analytics-row { display:grid; grid-template-columns:2fr 1fr; gap:18px; margin-bottom:22px; }
  @media (max-width:1000px) { .analytics-row { grid-template-columns:1fr; } }

  .tabs { display:flex; gap:6px; padding:12px 20px; border-bottom:1px solid var(--border-light); flex-wrap:wrap; }
  .tab-btn {
    background:var(--bg-input); color:var(--text-secondary);
    border:1.5px solid var(--border); padding:6px 14px; border-radius:10px;
    cursor:pointer; font-family:'Tajawal',sans-serif; font-size:12px; font-weight:600;
    transition:var(--transition);
  }
  .tab-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
  .tab-pane { display:none; }
  .tab-pane.active { display:block; }

  .top-list { list-style:none; padding:8px 0; }
  .top-list li { display:flex; align-items:center; gap:12px; padding:10px 20px; border-bottom:1px solid var(--border-light); }
  .top-list li:last-child { border-bottom:none; }
  .top-rank { width:28px; height:28px; border-radius:8px; background:var(--primary-soft); color:var(--primary); font-weight:800; display:flex; align-items:center; justify-content:center; font-size:13px; }
  .top-list li:nth-child(-n+3) .top-rank { background:var(--warning-light); color:var(--warning); }
  .top-body { flex:1; min-width:0; }
  .top-title { font-size:13px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .top-meta  { font-size:11px; color:var(--text-muted); margin-top:2px; display:flex; gap:10px; }
  .top-metric { font-weight:800; color:var(--primary); font-size:14px; white-space:nowrap; }

  .bar-row { display:flex; align-items:center; gap:12px; padding:9px 20px; border-bottom:1px solid var(--border-light); }
  .bar-row:last-child { border-bottom:none; }
  .bar-name { flex:0 0 34%; font-size:12px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .bar-track { flex:1; height:8px; background:var(--bg-input); border-radius:4px; overflow:hidden; }
  .bar-fill  { height:100%; background:linear-gradient(90deg,var(--primary),#8ab8f0); border-radius:4px; transition:width .6s ease; }
  .bar-value { flex:0 0 auto; font-size:12px; font-weight:700; color:var(--text-secondary); min-width:50px; text-align:left; }

  .source-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; padding:18px 20px; }
  @media (max-width:900px) { .source-grid { grid-template-columns:repeat(2,1fr); } }
  .source-tile {
    background:var(--bg-input); border:1.5px solid var(--border);
    border-radius:12px; padding:14px; text-align:center;
  }
  .source-tile .lbl { font-size:11px; color:var(--text-muted); font-weight:700; }
  .source-tile .val { font-size:20px; font-weight:800; color:var(--text-primary); margin-top:4px; }

  .live-pulse { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; background:#fde8e5; color:#c0392b; border-radius:20px; font-size:11px; font-weight:700; }
  .live-pulse .dot { width:6px; height:6px; background:#c0392b; border-radius:50%; animation:pulse 1s infinite; }
</style>

<div class="content">
  <div class="page-header">
    <div>
      <h2>📈 التحليلات</h2>
      <p>إحصاءات تفصيلية عن الزيارات، المصادر، والمحتوى الأفضل أداءً</p>
    </div>
    <?php if ($activeReaders > 0): ?>
      <div class="live-pulse"><span class="dot"></span> <?php echo (int)$activeReaders; ?> مقال نشط الآن</div>
    <?php endif; ?>
  </div>

  <!-- KPI CARDS -->
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-label">اليوم</div>
      <div class="kpi-value"><?php echo number_format($viewsToday); ?></div>
      <div class="kpi-sub">
        <span class="kpi-trend <?php echo $growthToday>0?'up':($growthToday<0?'down':'flat'); ?>">
          <?php echo $growthToday>0?'▲':($growthToday<0?'▼':'•'); ?> <?php echo abs($growthToday); ?>%
        </span>
        مقابل أمس (<?php echo number_format($viewsYesterday); ?>)
      </div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">هذا الأسبوع</div>
      <div class="kpi-value"><?php echo number_format($viewsWeek); ?></div>
      <div class="kpi-sub">
        <span class="kpi-trend <?php echo $growthWeek>0?'up':($growthWeek<0?'down':'flat'); ?>">
          <?php echo $growthWeek>0?'▲':($growthWeek<0?'▼':'•'); ?> <?php echo abs($growthWeek); ?>%
        </span>
        مقابل الأسبوع الماضي
      </div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">آخر 30 يوم</div>
      <div class="kpi-value"><?php echo number_format($viewsMonth); ?></div>
      <div class="kpi-sub">
        <span class="kpi-trend <?php echo $growthMonth>0?'up':($growthMonth<0?'down':'flat'); ?>">
          <?php echo $growthMonth>0?'▲':($growthMonth<0?'▼':'•'); ?> <?php echo abs($growthMonth); ?>%
        </span>
        مقابل الـ 30 يوم السابقة
      </div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">إجمالي كل الوقت</div>
      <div class="kpi-value"><?php echo number_format($viewsAllTime); ?></div>
      <div class="kpi-sub">مجموع مشاهدات كل المقالات</div>
    </div>
  </div>

  <!-- TIME CHARTS -->
  <div class="analytics-row">
    <div class="card">
      <div class="card-header"><div class="card-title">📊 مشاهدات آخر 30 يوم</div></div>
      <div class="card-body"><div style="position:relative;height:260px;"><canvas id="dailyChart"></canvas></div></div>
    </div>
    <div class="card">
      <div class="card-header"><div class="card-title">⏰ آخر 24 ساعة (بالساعة)</div></div>
      <div class="card-body"><div style="position:relative;height:260px;"><canvas id="hourlyChart"></canvas></div></div>
    </div>
  </div>

  <div class="analytics-row">
    <div class="card">
      <div class="card-header"><div class="card-title">📅 نمط أيام الأسبوع</div></div>
      <div class="card-body"><div style="position:relative;height:240px;"><canvas id="dowChart"></canvas></div></div>
    </div>
    <div class="card">
      <div class="card-header"><div class="card-title">🌐 مصادر الزيارات (آخر 7 أيام)</div></div>
      <?php if (array_sum($sourcesTotals) > 0): ?>
        <div class="source-grid">
          <?php foreach (['search','social','direct','internal','other'] as $st): ?>
            <div class="source-tile">
              <div class="lbl"><?php echo $sourceLabels[$st]; ?></div>
              <div class="val"><?php echo number_format((int)($sourcesTotals[$st] ?? 0)); ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="card-body" style="text-align:center; color:var(--text-muted); padding:40px 20px;">
          لا توجد بيانات مصادر بعد — تبدأ بالظهور تدريجياً مع تراكم الزيارات.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- TOP ARTICLES WITH TABS -->
  <div class="card" style="margin-bottom:22px;">
    <div class="card-header"><div class="card-title">🔥 الأعلى زيارة</div></div>
    <div class="tabs">
      <button class="tab-btn active" data-tab="top-today">اليوم</button>
      <button class="tab-btn" data-tab="top-week">هذا الأسبوع</button>
      <button class="tab-btn" data-tab="top-all">كل الوقت</button>
    </div>

    <div class="tab-pane active" id="top-today">
      <?php if ($topToday): ?>
        <ul class="top-list">
          <?php foreach ($topToday as $i => $a): ?>
            <li>
              <div class="top-rank"><?php echo $i+1; ?></div>
              <div class="top-body">
                <div class="top-title"><a href="articles.php?edit=<?php echo (int)$a['id']; ?>" style="color:inherit;text-decoration:none;"><?php echo e($a['title']); ?></a></div>
                <div class="top-meta">
                  <?php if (!empty($a['cat_name'])): ?><span><?php echo e($a['cat_name']); ?></span><?php endif; ?>
                  <span>إجمالي: <?php echo number_format((int)$a['view_count']); ?></span>
                </div>
              </div>
              <div class="top-metric"><?php echo number_format((int)$a['period_views']); ?> 👁</div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div style="padding:30px; text-align:center; color:var(--text-muted);">لا توجد مشاهدات في آخر 24 ساعة.</div>
      <?php endif; ?>
    </div>

    <div class="tab-pane" id="top-week">
      <?php if ($topWeek): ?>
        <ul class="top-list">
          <?php foreach ($topWeek as $i => $a): ?>
            <li>
              <div class="top-rank"><?php echo $i+1; ?></div>
              <div class="top-body">
                <div class="top-title"><a href="articles.php?edit=<?php echo (int)$a['id']; ?>" style="color:inherit;text-decoration:none;"><?php echo e($a['title']); ?></a></div>
                <div class="top-meta">
                  <?php if (!empty($a['cat_name'])): ?><span><?php echo e($a['cat_name']); ?></span><?php endif; ?>
                  <span>إجمالي: <?php echo number_format((int)$a['view_count']); ?></span>
                </div>
              </div>
              <div class="top-metric"><?php echo number_format((int)$a['period_views']); ?> 👁</div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div style="padding:30px; text-align:center; color:var(--text-muted);">لا توجد بيانات أسبوعية بعد.</div>
      <?php endif; ?>
    </div>

    <div class="tab-pane" id="top-all">
      <ul class="top-list">
        <?php foreach ($topAllTime as $i => $a): ?>
          <li>
            <div class="top-rank"><?php echo $i+1; ?></div>
            <div class="top-body">
              <div class="top-title"><a href="articles.php?edit=<?php echo (int)$a['id']; ?>" style="color:inherit;text-decoration:none;"><?php echo e($a['title']); ?></a></div>
              <div class="top-meta"><?php if (!empty($a['cat_name'])): ?><span><?php echo e($a['cat_name']); ?></span><?php endif; ?></div>
            </div>
            <div class="top-metric"><?php echo number_format((int)$a['view_count']); ?> 👁</div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <!-- CATEGORIES / SOURCES / TOP DOMAINS -->
  <div class="analytics-row" style="grid-template-columns:1fr 1fr;">
    <div class="card">
      <div class="card-header"><div class="card-title">📂 مشاهدات حسب القسم</div></div>
      <div style="padding:10px 0;">
        <?php
        $maxCatV = 1; foreach ($viewsByCategory as $c) { if ((int)$c['v'] > $maxCatV) $maxCatV = (int)$c['v']; }
        foreach ($viewsByCategory as $c):
            $pct = $maxCatV > 0 ? round(((int)$c['v'] / $maxCatV) * 100) : 0;
        ?>
          <div class="bar-row">
            <div class="bar-name"><?php echo e($c['name']); ?></div>
            <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%;"></div></div>
            <div class="bar-value"><?php echo number_format((int)$c['v']); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">🌐 مشاهدات حسب المصدر</div></div>
      <div style="padding:10px 0;">
        <?php
        $maxSrcV = 1; foreach ($viewsBySource as $s) { if ((int)$s['v'] > $maxSrcV) $maxSrcV = (int)$s['v']; }
        foreach ($viewsBySource as $s):
            $pct = $maxSrcV > 0 ? round(((int)$s['v'] / $maxSrcV) * 100) : 0;
        ?>
          <div class="bar-row">
            <div class="bar-name"><?php echo e($s['name']); ?></div>
            <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%; background:linear-gradient(90deg,var(--success),#86d5ae);"></div></div>
            <div class="bar-value"><?php echo number_format((int)$s['v']); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if ($topDomains): ?>
  <div class="card" style="margin-bottom:22px;">
    <div class="card-header"><div class="card-title">🔗 أعلى النطاقات المحيلة (آخر 7 أيام)</div></div>
    <div style="padding:10px 0;">
      <?php
      $maxDom = 1; foreach ($topDomains as $d) { if ((int)$d['v'] > $maxDom) $maxDom = (int)$d['v']; }
      foreach ($topDomains as $d):
          $pct = $maxDom > 0 ? round(((int)$d['v'] / $maxDom) * 100) : 0;
      ?>
        <div class="bar-row">
          <div class="bar-name" dir="ltr" style="direction:ltr;text-align:right;"><?php echo e($d['source_domain']); ?></div>
          <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%; background:linear-gradient(90deg,var(--purple),#b29ce0);"></div></div>
          <div class="bar-value"><?php echo number_format((int)$d['v']); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ENGAGEMENT -->
  <div class="analytics-row" style="grid-template-columns:1fr 1fr 1fr;">
    <div class="kpi-card">
      <div class="kpi-label">👥 المستخدمون المسجلون</div>
      <div class="kpi-value"><?php echo number_format($totalUsers); ?></div>
      <div class="kpi-sub"><span class="kpi-trend up">+<?php echo (int)$newUsersWeek; ?></span> هذا الأسبوع</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">📬 مشتركو النشرة</div>
      <div class="kpi-value"><?php echo number_format($totalSubs); ?></div>
      <div class="kpi-sub"><span class="kpi-trend up">+<?php echo (int)$newSubsWeek; ?></span> هذا الأسبوع</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">📖 القراء النشطون</div>
      <div class="kpi-value"><?php echo number_format($activeReaders); ?></div>
      <div class="kpi-sub">مقال مختلف في آخر 5 دقائق</div>
    </div>
  </div>

  <div class="analytics-row" style="grid-template-columns:1fr 1fr;">
    <div class="card">
      <div class="card-header"><div class="card-title">⭐ الأكثر حفظاً</div></div>
      <?php if ($mostBookmarked): ?>
        <ul class="top-list">
          <?php foreach ($mostBookmarked as $i => $a): ?>
            <li>
              <div class="top-rank"><?php echo $i+1; ?></div>
              <div class="top-body"><div class="top-title"><?php echo e($a['title']); ?></div></div>
              <div class="top-metric"><?php echo number_format((int)$a['saves']); ?> 🔖</div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div style="padding:30px; text-align:center; color:var(--text-muted);">لا توجد مقالات محفوظة بعد.</div>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="card-header"><div class="card-title">💬 الأكثر تعليقاً</div></div>
      <?php if ($mostCommented): ?>
        <ul class="top-list">
          <?php foreach ($mostCommented as $i => $a): ?>
            <li>
              <div class="top-rank"><?php echo $i+1; ?></div>
              <div class="top-body"><div class="top-title"><?php echo e($a['title']); ?></div></div>
              <div class="top-metric"><?php echo number_format((int)$a['cnt']); ?> 💬</div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div style="padding:30px; text-align:center; color:var(--text-muted);">لا توجد تعليقات بعد.</div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function(){
  const fontFamily = "'Tajawal', sans-serif";
  Chart.defaults.font.family = fontFamily;
  Chart.defaults.color = '#6b7a95';

  // Daily views (last 30 days)
  new Chart(document.getElementById('dailyChart'), {
    type: 'line',
    data: {
      labels: <?php echo json_encode($chartDays, JSON_UNESCAPED_UNICODE); ?>,
      datasets: [{
        label: 'مشاهدات',
        data: <?php echo json_encode($chartViews); ?>,
        borderColor: '#4a7fcb', backgroundColor: 'rgba(74,127,203,0.15)',
        tension: 0.35, fill: true, pointRadius: 2, pointHoverRadius: 5, borderWidth: 2.5
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, grid: { color: '#edf1fa' } }, x: { grid: { display: false } } }
    }
  });

  // Hourly last 24h
  new Chart(document.getElementById('hourlyChart'), {
    type: 'bar',
    data: {
      labels: <?php echo json_encode($hourlyLabels); ?>,
      datasets: [{ label: 'مشاهدات', data: <?php echo json_encode($hourlyData); ?>, backgroundColor: '#52b788', borderRadius: 4 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, grid: { color: '#edf1fa' } }, x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } } }
    }
  });

  // Day-of-week pattern
  new Chart(document.getElementById('dowChart'), {
    type: 'bar',
    data: {
      labels: <?php echo json_encode($dowLabels, JSON_UNESCAPED_UNICODE); ?>,
      datasets: [{ label: 'مشاهدات', data: <?php echo json_encode($dowData); ?>, backgroundColor: '#8b6fcb', borderRadius: 6 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, grid: { color: '#edf1fa' } }, x: { grid: { display: false } } }
    }
  });

  // Tabs
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.getAttribute('data-tab');
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b === btn));
      document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === target));
    });
  });
})();
</script>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
