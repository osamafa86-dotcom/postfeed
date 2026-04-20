<?php
/**
 * نيوز فيد - التقويم التحريري v1.0
 * Content Calendar with weekly/monthly views
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('editor');

$db = getDB();

$view = $_GET['view'] ?? 'month';
$year  = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('n'));
$week  = (int)($_GET['w'] ?? date('W'));

if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$firstDay = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int)date('t', $firstDay);
$startWeekday = (int)date('N', $firstDay); // 1=Mon .. 7=Sun

// Fetch articles for this month
$startDate = date('Y-m-01', $firstDay);
$endDate   = date('Y-m-t', $firstDay);

$stmt = $db->prepare("
    SELECT a.id, a.title, a.status, a.is_breaking, a.is_featured, a.is_hero,
           a.published_at, a.created_at, a.image_url,
           c.name as cat_name
    FROM articles a
    LEFT JOIN categories c ON a.category_id = c.id
    WHERE DATE(COALESCE(a.published_at, a.created_at)) BETWEEN ? AND ?
    ORDER BY COALESCE(a.published_at, a.created_at) ASC
");
$stmt->execute([$startDate, $endDate]);
$articles = $stmt->fetchAll();

// Group articles by day
$articlesByDay = [];
foreach ($articles as $art) {
    $d = (int)date('j', strtotime($art['published_at'] ?? $art['created_at']));
    $articlesByDay[$d][] = $art;
}

// Stats for this month
$totalMonth = count($articles);
$publishedMonth = count(array_filter($articles, fn($a) => $a['status'] === 'published'));
$draftMonth = $totalMonth - $publishedMonth;

// Daily average
$avgPerDay = $daysInMonth > 0 ? round($totalMonth / $daysInMonth, 1) : 0;

// Get category distribution for this month
$catStmt = $db->prepare("
    SELECT c.name, COUNT(*) as cnt
    FROM articles a
    LEFT JOIN categories c ON a.category_id = c.id
    WHERE DATE(COALESCE(a.published_at, a.created_at)) BETWEEN ? AND ?
    AND c.name IS NOT NULL
    GROUP BY c.id, c.name
    ORDER BY cnt DESC LIMIT 5
");
$catStmt->execute([$startDate, $endDate]);
$topCats = $catStmt->fetchAll();

$arabicMonths = ['', 'يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
$arabicDays = ['الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت','الأحد'];

$pageTitle = 'التقويم التحريري - نيوز فيد';
$activePage = 'calendar';
include __DIR__ . '/includes/panel_layout_head.php';
?>
<style>
  /* ===== Calendar Styles ===== */
  .cal-header {
    display:flex; justify-content:space-between; align-items:center;
    margin-bottom:20px; flex-wrap:wrap; gap:12px;
  }
  .cal-nav {
    display:flex; align-items:center; gap:8px;
  }
  .cal-nav-btn {
    width:36px; height:36px; border-radius:10px;
    background:var(--bg-card); border:1.5px solid var(--border);
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:var(--transition); font-size:14px;
    color:var(--text-secondary); text-decoration:none;
  }
  .cal-nav-btn:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-soft); }
  .cal-month-label {
    font-size:20px; font-weight:800; color:var(--text-primary);
    min-width:180px; text-align:center;
  }
  .cal-view-toggle {
    display:flex; background:var(--bg-card); border:1.5px solid var(--border);
    border-radius:10px; overflow:hidden;
  }
  .cal-view-btn {
    padding:8px 16px; font-size:12px; font-weight:600;
    font-family:'Tajawal',sans-serif; color:var(--text-secondary);
    background:none; border:none; cursor:pointer;
    transition:var(--transition); text-decoration:none;
    display:inline-block;
  }
  .cal-view-btn:hover { color:var(--primary); }
  .cal-view-btn.active {
    background:var(--primary); color:#fff;
    box-shadow:0 2px 6px rgba(99,102,241,0.3);
  }

  /* Stats strip */
  .cal-stats {
    display:grid; grid-template-columns:repeat(4,1fr); gap:12px;
    margin-bottom:20px;
  }
  @media(max-width:800px) { .cal-stats { grid-template-columns:repeat(2,1fr); } }
  .cal-stat {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--radius); padding:16px 18px;
    display:flex; align-items:center; gap:12px;
    box-shadow:var(--shadow);
  }
  .cal-stat-icon {
    width:42px; height:42px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:18px; flex-shrink:0;
  }
  .cal-stat-info h4 { font-size:22px; font-weight:800; color:var(--text-primary); }
  .cal-stat-info p { font-size:11px; color:var(--text-muted); font-weight:600; }

  /* Month grid */
  .cal-grid {
    display:grid; grid-template-columns:repeat(7,1fr);
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--radius-lg); overflow:hidden;
    box-shadow:var(--shadow);
  }
  .cal-day-header {
    padding:12px 8px; text-align:center;
    font-size:12px; font-weight:700; color:var(--text-muted);
    background:var(--bg-page); border-bottom:1px solid var(--border);
  }
  .cal-cell {
    min-height:120px; border-left:1px solid var(--border-light);
    border-bottom:1px solid var(--border-light);
    padding:6px; position:relative;
    transition:background 0.15s;
  }
  .cal-cell:hover { background:var(--bg-hover); }
  .cal-cell.empty { background:var(--bg-page); opacity:0.5; }
  .cal-cell.today { background:#eef2ff; }
  .cal-cell.today .cal-date { color:var(--primary); font-weight:800; }

  .cal-date {
    font-size:13px; font-weight:700; color:var(--text-secondary);
    padding:4px 8px; display:inline-block;
  }
  .cal-date-today {
    background:var(--primary); color:#fff !important;
    border-radius:8px; padding:3px 9px;
  }

  .cal-items { display:flex; flex-direction:column; gap:3px; margin-top:2px; }

  .cal-item {
    display:flex; align-items:center; gap:5px;
    padding:4px 7px; border-radius:6px;
    font-size:11px; font-weight:600;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    cursor:pointer; transition:var(--transition);
    text-decoration:none; color:inherit;
  }
  .cal-item:hover { transform:translateX(-2px); }
  .cal-item.published {
    background:var(--success-light); color:#065f46;
    border-right:3px solid var(--success);
  }
  .cal-item.draft {
    background:var(--warning-light); color:#92400e;
    border-right:3px solid var(--warning);
  }
  .cal-item.breaking {
    background:var(--danger-light); color:#991b1b;
    border-right:3px solid var(--danger);
  }

  .cal-more {
    font-size:10px; color:var(--primary); font-weight:700;
    padding:2px 7px; cursor:pointer; text-align:center;
  }
  .cal-more:hover { text-decoration:underline; }

  /* Day detail popup */
  .cal-popup {
    position:fixed; inset:0; background:rgba(15,23,42,0.5);
    backdrop-filter:blur(4px); z-index:1000; display:none;
    align-items:center; justify-content:center;
  }
  .cal-popup.open { display:flex; }
  .cal-popup-panel {
    background:#fff; border-radius:16px; width:480px; max-width:92vw;
    max-height:80vh; overflow-y:auto;
    box-shadow:0 20px 60px rgba(0,0,0,0.25);
    animation:cmdSlideIn 0.2s cubic-bezier(0.4,0,0.2,1);
  }
  .cal-popup-header {
    padding:18px 22px; border-bottom:1px solid var(--border-light);
    display:flex; align-items:center; justify-content:space-between;
  }
  .cal-popup-header h3 { font-size:16px; font-weight:800; }
  .cal-popup-close {
    width:32px; height:32px; border-radius:8px;
    background:var(--bg-page); border:1px solid var(--border);
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; font-size:14px; color:var(--text-muted);
    transition:var(--transition);
  }
  .cal-popup-close:hover { background:var(--danger-light); color:var(--danger); border-color:var(--danger); }
  .cal-popup-body { padding:14px 22px 22px; }
  .cal-popup-item {
    display:flex; align-items:center; gap:12px;
    padding:12px 14px; border-radius:10px;
    border:1px solid var(--border-light); margin-bottom:8px;
    transition:var(--transition); text-decoration:none; color:inherit;
  }
  .cal-popup-item:hover { background:var(--bg-hover); border-color:var(--primary); }
  .cal-popup-dot {
    width:10px; height:10px; border-radius:50%; flex-shrink:0;
  }
  .cal-popup-dot.published { background:var(--success); }
  .cal-popup-dot.draft { background:var(--warning); }
  .cal-popup-dot.breaking { background:var(--danger); }
  .cal-popup-item-body { flex:1; min-width:0; }
  .cal-popup-item-title {
    font-size:13px; font-weight:700;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
  }
  .cal-popup-item-meta { font-size:11px; color:var(--text-muted); margin-top:2px; }

  /* Week view */
  .cal-week-grid {
    display:grid; grid-template-columns:repeat(7,1fr); gap:12px;
  }
  @media(max-width:900px) { .cal-week-grid { grid-template-columns:1fr; } }
  .cal-week-day {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--radius-lg); overflow:hidden;
    box-shadow:var(--shadow); min-height:200px;
  }
  .cal-week-day.today { border-color:var(--primary); box-shadow:0 0 0 2px var(--primary-light); }
  .cal-week-day-header {
    padding:12px 14px; background:var(--bg-page);
    border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
  }
  .cal-week-day-header .day-name { font-size:12px; font-weight:700; color:var(--text-muted); }
  .cal-week-day-header .day-num { font-size:16px; font-weight:800; color:var(--text-primary); }
  .cal-week-day-header .day-num.today-num {
    background:var(--primary); color:#fff; border-radius:8px; padding:2px 10px;
  }
  .cal-week-body { padding:10px; display:flex; flex-direction:column; gap:6px; }
  .cal-week-item {
    display:block; padding:10px 12px; border-radius:8px;
    font-size:12px; font-weight:600; line-height:1.5;
    text-decoration:none; color:inherit;
    transition:var(--transition);
  }
  .cal-week-item:hover { transform:translateY(-1px); box-shadow:var(--shadow-md); }
  .cal-week-item.published { background:var(--success-light); border-right:3px solid var(--success); }
  .cal-week-item.draft { background:var(--warning-light); border-right:3px solid var(--warning); }
  .cal-week-item.breaking { background:var(--danger-light); border-right:3px solid var(--danger); }
  .cal-week-item-title { margin-bottom:4px; }
  .cal-week-item-meta { font-size:10px; color:var(--text-muted); display:flex; gap:8px; }
  .cal-week-empty { padding:30px 10px; text-align:center; color:var(--text-muted); font-size:12px; }

  /* Sidebar categories */
  .cal-sidebar { display:flex; flex-direction:column; gap:14px; }
  .cal-layout { display:grid; grid-template-columns:1fr 280px; gap:20px; align-items:start; }
  @media(max-width:1100px) { .cal-layout { grid-template-columns:1fr; } }

  .cat-bar {
    display:flex; align-items:center; gap:8px; margin-bottom:8px;
  }
  .cat-bar-name { font-size:12px; font-weight:600; color:var(--text-secondary); min-width:80px; }
  .cat-bar-fill {
    flex:1; height:8px; background:var(--bg-page); border-radius:4px; overflow:hidden;
  }
  .cat-bar-fill-inner { height:100%; border-radius:4px; transition:width 0.6s ease; }
  .cat-bar-count { font-size:11px; font-weight:700; color:var(--text-muted); min-width:24px; text-align:left; }
</style>
<div class="content">
  <div class="page-header">
    <div>
      <h2>📅 التقويم التحريري</h2>
      <p>خطّط ونظّم محتواك بصريّاً</p>
    </div>
    <div class="page-actions">
      <a href="articles.php?action=add" class="btn-primary">✏️ إضافة خبر</a>
    </div>
  </div>

  <!-- Stats -->
  <div class="cal-stats">
    <div class="cal-stat">
      <div class="cal-stat-icon" style="background:var(--primary-light);color:var(--primary);">📰</div>
      <div class="cal-stat-info">
        <h4><?php echo $totalMonth; ?></h4>
        <p>إجمالي الأخبار</p>
      </div>
    </div>
    <div class="cal-stat">
      <div class="cal-stat-icon" style="background:var(--success-light);color:var(--success);">✅</div>
      <div class="cal-stat-info">
        <h4><?php echo $publishedMonth; ?></h4>
        <p>منشور</p>
      </div>
    </div>
    <div class="cal-stat">
      <div class="cal-stat-icon" style="background:var(--warning-light);color:var(--warning);">📝</div>
      <div class="cal-stat-info">
        <h4><?php echo $draftMonth; ?></h4>
        <p>مسودات</p>
      </div>
    </div>
    <div class="cal-stat">
      <div class="cal-stat-icon" style="background:var(--cyan-light);color:var(--cyan);">📊</div>
      <div class="cal-stat-info">
        <h4><?php echo $avgPerDay; ?></h4>
        <p>معدل يومي</p>
      </div>
    </div>
  </div>

  <!-- Navigation -->
  <div class="cal-header">
    <div class="cal-nav">
      <?php
        $prevM = $month - 1; $prevY = $year;
        if ($prevM < 1) { $prevM = 12; $prevY--; }
        $nextM = $month + 1; $nextY = $year;
        if ($nextM > 12) { $nextM = 1; $nextY++; }
      ?>
      <a href="?view=<?php echo $view; ?>&y=<?php echo $prevY; ?>&m=<?php echo $prevM; ?>" class="cal-nav-btn">→</a>
      <span class="cal-month-label"><?php echo $arabicMonths[$month] . ' ' . $year; ?></span>
      <a href="?view=<?php echo $view; ?>&y=<?php echo $nextY; ?>&m=<?php echo $nextM; ?>" class="cal-nav-btn">←</a>
      <a href="?view=<?php echo $view; ?>" class="btn-outline" style="margin-right:10px;">اليوم</a>
    </div>
    <div class="cal-view-toggle">
      <a href="?view=month&y=<?php echo $year; ?>&m=<?php echo $month; ?>" class="cal-view-btn <?php echo $view === 'month' ? 'active' : ''; ?>">شهري</a>
      <a href="?view=week&y=<?php echo $year; ?>&m=<?php echo $month; ?>" class="cal-view-btn <?php echo $view === 'week' ? 'active' : ''; ?>">أسبوعي</a>
    </div>
  </div>

  <div class="cal-layout">
    <div>
    <?php if ($view === 'month'): ?>
      <!-- MONTH VIEW -->
      <div class="cal-grid">
        <?php foreach ($arabicDays as $dayName): ?>
          <div class="cal-day-header"><?php echo $dayName; ?></div>
        <?php endforeach; ?>

        <?php
        // Empty cells before first day
        for ($i = 1; $i < $startWeekday; $i++):
        ?>
          <div class="cal-cell empty"></div>
        <?php endfor; ?>

        <?php
        $today = (int)date('j');
        $todayMonth = (int)date('n');
        $todayYear  = (int)date('Y');

        for ($day = 1; $day <= $daysInMonth; $day++):
          $isToday = ($day === $today && $month === $todayMonth && $year === $todayYear);
          $dayArticles = $articlesByDay[$day] ?? [];
          $showMax = 3;
        ?>
          <div class="cal-cell <?php echo $isToday ? 'today' : ''; ?>" data-day="<?php echo $day; ?>">
            <span class="cal-date <?php echo $isToday ? 'cal-date-today' : ''; ?>"><?php echo $day; ?></span>
            <div class="cal-items">
              <?php foreach (array_slice($dayArticles, 0, $showMax) as $art):
                $cls = $art['is_breaking'] ? 'breaking' : ($art['status'] === 'published' ? 'published' : 'draft');
              ?>
                <a class="cal-item <?php echo $cls; ?>" href="articles.php?action=edit&id=<?php echo $art['id']; ?>" title="<?php echo htmlspecialchars($art['title'], ENT_QUOTES, 'UTF-8'); ?>">
                  <?php echo htmlspecialchars(mb_substr($art['title'], 0, 25, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?><?php echo mb_strlen($art['title']) > 25 ? '…' : ''; ?>
                </a>
              <?php endforeach; ?>
              <?php if (count($dayArticles) > $showMax): ?>
                <div class="cal-more" onclick="openDayPopup(<?php echo $day; ?>)">+<?php echo count($dayArticles) - $showMax; ?> المزيد</div>
              <?php endif; ?>
            </div>
          </div>
        <?php endfor; ?>

        <?php
        // Empty cells after last day
        $endWeekday = (int)date('N', mktime(0,0,0,$month,$daysInMonth,$year));
        for ($i = $endWeekday + 1; $i <= 7; $i++):
        ?>
          <div class="cal-cell empty"></div>
        <?php endfor; ?>
      </div>

    <?php else: ?>
      <!-- WEEK VIEW -->
      <?php
        // Get current week dates
        $todayTs = mktime(0,0,0,$month,min((int)date('j'), $daysInMonth),$year);
        $dayOfWeek = (int)date('N', $todayTs);
        $mondayTs = strtotime('-'.($dayOfWeek - 1).' days', $todayTs);

        // Fetch articles for this week
        $weekStart = date('Y-m-d', $mondayTs);
        $weekEnd   = date('Y-m-d', strtotime('+6 days', $mondayTs));
        $wStmt = $db->prepare("
            SELECT a.id, a.title, a.status, a.is_breaking, a.is_featured,
                   a.published_at, a.created_at, a.image_url,
                   c.name as cat_name
            FROM articles a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE DATE(COALESCE(a.published_at, a.created_at)) BETWEEN ? AND ?
            ORDER BY COALESCE(a.published_at, a.created_at) ASC
        ");
        $wStmt->execute([$weekStart, $weekEnd]);
        $weekArticles = $wStmt->fetchAll();

        $weekByDay = [];
        foreach ($weekArticles as $wa) {
            $wd = (int)date('N', strtotime($wa['published_at'] ?? $wa['created_at']));
            $weekByDay[$wd][] = $wa;
        }
      ?>
      <div class="cal-week-grid">
        <?php for ($wd = 1; $wd <= 7; $wd++):
          $dayTs = strtotime('+'.($wd-1).' days', $mondayTs);
          $dayNum = (int)date('j', $dayTs);
          $dayMonthNum = (int)date('n', $dayTs);
          $dayYearNum = (int)date('Y', $dayTs);
          $isToday = ($dayNum === (int)date('j') && $dayMonthNum === (int)date('n') && $dayYearNum === (int)date('Y'));
          $dayItems = $weekByDay[$wd] ?? [];
        ?>
          <div class="cal-week-day <?php echo $isToday ? 'today' : ''; ?>">
            <div class="cal-week-day-header">
              <span class="day-name"><?php echo $arabicDays[$wd - 1]; ?></span>
              <span class="day-num <?php echo $isToday ? 'today-num' : ''; ?>"><?php echo $dayNum; ?></span>
            </div>
            <div class="cal-week-body">
              <?php if (empty($dayItems)): ?>
                <div class="cal-week-empty">لا توجد أخبار</div>
              <?php else: ?>
                <?php foreach ($dayItems as $wa):
                  $cls = $wa['is_breaking'] ? 'breaking' : ($wa['status'] === 'published' ? 'published' : 'draft');
                ?>
                  <a class="cal-week-item <?php echo $cls; ?>" href="articles.php?action=edit&id=<?php echo $wa['id']; ?>">
                    <div class="cal-week-item-title"><?php echo htmlspecialchars(mb_substr($wa['title'], 0, 60, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="cal-week-item-meta">
                      <?php if ($wa['cat_name']): ?><span>📂 <?php echo htmlspecialchars($wa['cat_name']); ?></span><?php endif; ?>
                      <span><?php echo date('H:i', strtotime($wa['published_at'] ?? $wa['created_at'])); ?></span>
                    </div>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
    </div>

    <!-- Sidebar -->
    <div class="cal-sidebar">
      <!-- Category distribution -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">📂 توزيع الأقسام</div>
        </div>
        <div class="card-body">
          <?php if (!empty($topCats)):
            $maxCnt = $topCats[0]['cnt'];
            $colors = ['#6366f1','#10b981','#f59e0b','#ef4444','#8b5cf6'];
            foreach ($topCats as $ci => $cat):
              $pct = $maxCnt > 0 ? round(($cat['cnt'] / $maxCnt) * 100) : 0;
          ?>
            <div class="cat-bar">
              <span class="cat-bar-name"><?php echo htmlspecialchars($cat['name']); ?></span>
              <div class="cat-bar-fill">
                <div class="cat-bar-fill-inner" style="width:<?php echo $pct; ?>%;background:<?php echo $colors[$ci % 5]; ?>;"></div>
              </div>
              <span class="cat-bar-count"><?php echo $cat['cnt']; ?></span>
            </div>
          <?php endforeach; else: ?>
            <p style="font-size:12px;color:var(--text-muted);text-align:center;padding:20px 0;">لا توجد بيانات</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Legend -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">📋 دليل الألوان</div>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="width:12px;height:12px;border-radius:50%;background:var(--success);flex-shrink:0;"></span>
            <span style="font-size:12px;font-weight:600;">منشور</span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="width:12px;height:12px;border-radius:50%;background:var(--warning);flex-shrink:0;"></span>
            <span style="font-size:12px;font-weight:600;">مسودة</span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="width:12px;height:12px;border-radius:50%;background:var(--danger);flex-shrink:0;"></span>
            <span style="font-size:12px;font-weight:600;">عاجل</span>
          </div>
        </div>
      </div>

      <!-- Quick add -->
      <a href="articles.php?action=add" class="btn-primary" style="width:100%;justify-content:center;padding:14px;">
        ✏️ إضافة خبر جديد
      </a>
    </div>
  </div>
</div>

<!-- Day detail popup -->
<div class="cal-popup" id="dayPopup" onclick="if(event.target===this)closeDayPopup()">
  <div class="cal-popup-panel">
    <div class="cal-popup-header">
      <h3 id="popupTitle">أخبار اليوم</h3>
      <div class="cal-popup-close" onclick="closeDayPopup()">✕</div>
    </div>
    <div class="cal-popup-body" id="popupBody"></div>
  </div>
</div>

<script>
var articlesByDay = <?php echo json_encode($articlesByDay, JSON_UNESCAPED_UNICODE); ?>;
var arabicMonths = <?php echo json_encode($arabicMonths, JSON_UNESCAPED_UNICODE); ?>;

function openDayPopup(day) {
  var popup = document.getElementById('dayPopup');
  var title = document.getElementById('popupTitle');
  var body  = document.getElementById('popupBody');
  var arts = articlesByDay[day] || [];

  title.textContent = day + ' ' + arabicMonths[<?php echo $month; ?>] + ' — ' + arts.length + ' خبر';
  var html = '';
  arts.forEach(function(a) {
    var cls = a.is_breaking == 1 ? 'breaking' : (a.status === 'published' ? 'published' : 'draft');
    var time = (a.published_at || a.created_at || '').slice(11, 16);
    html += '<a class="cal-popup-item" href="articles.php?action=edit&id=' + a.id + '">'
          + '<div class="cal-popup-dot ' + cls + '"></div>'
          + '<div class="cal-popup-item-body">'
          + '<div class="cal-popup-item-title">' + escHtml(a.title) + '</div>'
          + '<div class="cal-popup-item-meta">'
          + (a.cat_name ? '<span>📂 ' + escHtml(a.cat_name) + '</span>' : '')
          + '<span>🕐 ' + time + '</span>'
          + '</div></div></a>';
  });
  if (!html) html = '<p style="text-align:center;color:var(--text-muted);padding:20px;">لا توجد أخبار</p>';
  body.innerHTML = html;
  popup.classList.add('open');
}

function closeDayPopup() {
  document.getElementById('dayPopup').classList.remove('open');
}

function escHtml(s) {
  return (s||'').replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeDayPopup();
});
</script>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
