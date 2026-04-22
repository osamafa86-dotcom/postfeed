<?php
/**
 * /map — interactive news map.
 *
 * Leaflet + OpenStreetMap tiles (free, no token required).
 * Loads locations from map_feed.php as GeoJSON, renders with
 * marker clustering, and opens a side panel with the story
 * details on click.
 *
 * Route wired up in .htaccess as `/map`.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/news_map.php';
require_once __DIR__ . '/includes/seo.php';

$pageTheme = current_theme();
$viewer    = current_user();
$viewerId  = (int)($viewer['id'] ?? 0);
$stats     = nm_stats();
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title>🗺 خريطة الأخبار — <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="description" content="خريطة تفاعلية للأحداث في المنطقة العربية والعالم، محدّثة مباشرة مع تصفية حسب التصنيف والفترة الزمنية.">
<link rel="canonical" href="<?php echo e(SITE_URL . '/map'); ?>">
<?php include __DIR__ . '/includes/components/pwa_head.php'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" media="print" onload="this.media='all'">
<link rel="stylesheet" href="assets/css/site-header.min.css?v=m1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
<link rel="stylesheet" href="assets/css/map.css?v=1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
</head>
<body class="map-body">

<?php
$activeType = 'map';
$showTicker = false;
include __DIR__ . '/includes/components/site_header.php';
?>

<main class="map-wrap">
  <!-- TOP BAR: filters + stats -->
  <div class="map-topbar">
    <div class="map-topbar-left">
      <h1 class="map-title">🗺 خريطة الأخبار</h1>
      <span class="map-stats">
        <?php echo number_format($stats['total']); ?> موقع · آخر 24س: <?php echo number_format($stats['last_24h']); ?>
      </span>
    </div>
    <div class="map-filters">
      <label>الفترة
        <select id="mapDays">
          <option value="1">آخر 24 ساعة</option>
          <option value="3">آخر 3 أيام</option>
          <option value="7" selected>آخر أسبوع</option>
          <option value="30">آخر 30 يوم</option>
          <option value="90">آخر 3 أشهر</option>
        </select>
      </label>
      <label class="map-breaking-toggle">
        <input type="checkbox" id="mapBreaking"> عاجل فقط
      </label>
    </div>
  </div>

  <!-- MAP + SIDEBAR -->
  <div class="map-shell">
    <div id="map" class="map-canvas"></div>

    <aside class="map-side" id="mapSide">
      <div class="map-side-head">
        <span id="mapSideTitle">اختر موقعاً على الخريطة</span>
        <button type="button" class="map-side-close" onclick="closeMapSide()">×</button>
      </div>
      <div class="map-side-body" id="mapSideBody">
        <p class="map-side-hint">اضغط على أي دبّوس لعرض الأخبار في ذلك الموقع.<br>الدبابيس المجمّعة تحتوي أكثر من خبر — قرّب الخريطة لتوسعتها.</p>
      </div>
    </aside>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script>
// Detect load failures so the operator doesn't stare at a grey
// canvas without knowing the CDN is the problem.
if (typeof L === 'undefined') {
  document.getElementById('mapSideBody').innerHTML =
    '<p style="padding:20px;background:#fee;color:#991b1b;border-radius:8px;font-size:13px;line-height:1.7;">'
    + '⚠ فشل تحميل مكتبة Leaflet من CDN.<br>'
    + 'تحقّقي من الاتصال أو جدار الحماية / CSP.'
    + '</p>';
}
</script>
<script src="assets/js/map.js?v=2"></script>

</body>
</html>
