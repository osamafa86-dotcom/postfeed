/**
 * News Map — Leaflet renderer.
 *
 * Loads GeoJSON from /map_feed.php, clusters markers with
 * leaflet.markercluster, and drives the right-hand sidebar
 * when the user clicks a marker / cluster. Filters (days,
 * breaking) trigger a fresh fetch + re-render.
 */
(function () {
  'use strict';

  // Middle East focus on first paint.
  var map = L.map('map', { zoomControl: true })
    .setView([27.5, 38.0], 4);

  // Free tiles — OSM standard. Switch to a paid provider later
  // if we want label styling closer to our brand.
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 18,
  }).addTo(map);

  var cluster = L.markerClusterGroup({
    showCoverageOnHover: false,
    maxClusterRadius: 50,
  });
  map.addLayer(cluster);

  var sideTitle = document.getElementById('mapSideTitle');
  var sideBody  = document.getElementById('mapSideBody');
  var daysSel   = document.getElementById('mapDays');
  var breakingT = document.getElementById('mapBreaking');

  function storyCardHtml(p) {
    var thumb = p.image_url
      ? '<img src="' + escapeHtml(p.image_url) + '" alt="" loading="lazy">'
      : '';
    var cat = p.cat_name ? '<span class="cat">' + escapeHtml(p.cat_name) + '</span>' : '';
    var breakingTag = p.is_breaking ? '<span class="breaking-tag">عاجل</span>' : '';
    var cls = 'map-story-card' + (p.is_breaking ? ' is-breaking' : '');
    return ''
      + '<a class="' + cls + '" href="' + escapeHtml(p.url) + '">'
      + '<div class="map-story-thumb">' + thumb + '</div>'
      + '<div class="map-story-body">'
      + '<div class="map-story-meta">'
      + breakingTag + cat
      + '<span>' + escapeHtml(p.source_name || '') + '</span>'
      + '<span>·</span><span>' + escapeHtml(p.time_ago || '') + '</span>'
      + '</div>'
      + '<div class="map-story-title">' + escapeHtml(p.title) + '</div>'
      + '</div>'
      + '</a>';
  }

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function showLocation(label, stories) {
    sideTitle.textContent = label + ' (' + stories.length + ')';
    if (!stories.length) {
      sideBody.innerHTML = '<p class="map-side-hint">لا توجد أخبار في هذا الموقع.</p>';
      return;
    }
    sideBody.innerHTML = stories.map(storyCardHtml).join('');
    sideBody.scrollTop = 0;
  }

  // Group markers by (lat,lng) so clicking one shows every
  // story at that spot — a lot of cities get multiple stories.
  var pointIndex = {};

  function load() {
    var params = new URLSearchParams({ days: daysSel.value });
    if (breakingT.checked) params.set('breaking', '1');

    cluster.clearLayers();
    pointIndex = {};
    sideTitle.textContent = 'تحميل...';
    sideBody.innerHTML = '<p class="map-side-hint">...</p>';

    fetch('/map_feed.php?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(fc) {
        if (!fc || !Array.isArray(fc.features)) return;
        fc.features.forEach(function(f) {
          if (!f.geometry || !f.geometry.coordinates) return;
          var lng = f.geometry.coordinates[0];
          var lat = f.geometry.coordinates[1];
          var key = lat.toFixed(3) + ',' + lng.toFixed(3);
          (pointIndex[key] = pointIndex[key] || { label: f.properties.place_ar, stories: [] })
            .stories.push(f.properties);

          var icon = f.properties.is_breaking
            ? L.divIcon({ className: '', html: '<div class="map-marker-breaking"></div>', iconSize: [14,14] })
            : L.icon({
                iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
                iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
                shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
                iconSize: [25,41], iconAnchor: [12,41], popupAnchor: [1,-34], shadowSize: [41,41],
              });
          var marker = L.marker([lat, lng], { icon: icon, title: f.properties.title });
          marker.on('click', function() {
            var bucket = pointIndex[key];
            showLocation(bucket.label, bucket.stories);
          });
          cluster.addLayer(marker);
        });

        if (fc.features.length > 0) {
          sideTitle.textContent = 'اختر موقعاً على الخريطة';
          sideBody.innerHTML = '<p class="map-side-hint">تم تحميل <b>' + fc.features.length + '</b> خبر موزّعة جغرافياً. اضغط أي دبّوس أو كلاستر.</p>';
        } else {
          sideBody.innerHTML = '<p class="map-side-hint">لا توجد أخبار محدّدة الموقع في هذه الفترة.</p>';
        }
      })
      .catch(function(err) {
        sideBody.innerHTML = '<p class="map-side-hint">فشل التحميل. حدّث الصفحة.</p>';
        console.error('[map]', err);
      });
  }

  daysSel.addEventListener('change', load);
  breakingT.addEventListener('change', load);
  load();

  // Close sidebar (mobile)
  window.closeMapSide = function () {
    var side = document.getElementById('mapSide');
    if (side) side.classList.toggle('closed');
  };
})();
