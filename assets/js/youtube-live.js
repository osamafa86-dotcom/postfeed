/**
 * NewsFlow — real-time YouTube feed updater for the homepage.
 *
 * Same shape as telegram-live.js / twitter-live.js but targets
 * .yt-breaking and .yt-card. Prepends new videos with slide-in and
 * caps at MAX_ON_PAGE so the list doesn't grow unboundedly.
 *
 * Transports:
 *   1) SSE via /youtube_stream.php (primary)
 *   2) Polling /youtube_feed.php every POLL_FALLBACK_MS (fallback
 *      after 3 SSE failures)
 */
(function(){
  'use strict';

  var POLL_FALLBACK_MS = 30000; // YouTube updates slower than tg/tw
  var MAX_ON_PAGE      = 10;

  var grid = document.querySelector('.yt-breaking');
  if (!grid) return;

  function getLatestId() {
    var fromAttr = parseInt(grid.getAttribute('data-latest-id') || '0', 10);
    var maxId = isNaN(fromAttr) ? 0 : fromAttr;
    var items = grid.querySelectorAll('[data-yt-id]');
    for (var i = 0; i < items.length; i++) {
      var id = parseInt(items[i].getAttribute('data-yt-id') || '0', 10);
      if (id > maxId) maxId = id;
    }
    return maxId;
  }

  var pageNumber = parseInt(grid.getAttribute('data-page') || '1', 10);
  if (isNaN(pageNumber)) pageNumber = 1;
  var canPrepend = (pageNumber === 1);

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function buildNode(m) {
    var a = document.createElement('a');
    a.className = 'yt-card is-new';
    a.href = m.url;
    a.target = '_blank';
    a.rel = 'noopener';
    a.setAttribute('data-yt-id', String(m.id));

    var imgHtml = '';
    if (m.thumbnail_url) {
      imgHtml =
        '<div class="yt-img">' +
          '<img src="' + escapeHtml(m.thumbnail_url) + '" alt="" loading="lazy" decoding="async">' +
          '<span class="yt-play" aria-hidden="true">▶</span>' +
        '</div>';
    }

    var channelLabel = m.channel_name || (m.channel_handle ? '@' + m.channel_handle : '');

    a.innerHTML =
      imgHtml +
      '<div class="yt-body">' +
        '<div class="yt-source">' +
          '<span class="yt-badge">▶ يوتيوب</span>' +
          '<strong>' + escapeHtml(channelLabel) + '</strong>' +
          '<span class="yt-time">' + escapeHtml(m.time_ago || '') + '</span>' +
        '</div>' +
        '<div class="yt-title">' + escapeHtml(m.title || '') + '</div>' +
      '</div>';

    setTimeout(function(){ a.classList.remove('is-new'); }, 1200);
    return a;
  }

  function prependMessages(messages) {
    if (!messages || !messages.length) return 0;
    var added = 0;
    for (var i = messages.length - 1; i >= 0; i--) {
      var m = messages[i];
      if (grid.querySelector('[data-yt-id="' + m.id + '"]')) continue;
      var node = buildNode(m);
      grid.insertBefore(node, grid.firstChild);
      added++;
    }
    // Keep only the newest MAX_ON_PAGE cards.
    var all = grid.querySelectorAll('.yt-card');
    for (var j = all.length - 1; j >= MAX_ON_PAGE; j--) {
      all[j].parentNode && all[j].parentNode.removeChild(all[j]);
    }
    return added;
  }

  function handleIncoming(messages) {
    if (!canPrepend || !messages || !messages.length) return;
    prependMessages(messages);
  }

  // ---------- SSE ----------

  var sseSource    = null;
  var sseFailCount = 0;
  var usingFallback = false;

  function startSSE() {
    if (typeof window.EventSource !== 'function') { startPolling(); return; }
    if (sseSource) { try { sseSource.close(); } catch(e){} sseSource = null; }

    var url = 'youtube_stream.php?since_id=' + encodeURIComponent(getLatestId()) + '&_=' + Date.now();
    try {
      sseSource = new EventSource(url);
    } catch (e) {
      startPolling();
      return;
    }

    sseSource.addEventListener('open', function(){ sseFailCount = 0; });

    sseSource.addEventListener('messages', function(ev){
      try {
        var payload = JSON.parse(ev.data);
        handleIncoming(payload && payload.messages);
      } catch (e) {}
    });

    sseSource.addEventListener('bye', function(){
      try { sseSource.close(); } catch(e){}
      sseSource = null;
      if (!document.hidden) startSSE();
    });

    sseSource.addEventListener('error', function(){
      sseFailCount++;
      if (sseFailCount >= 3) {
        try { sseSource.close(); } catch(e){}
        sseSource = null;
        startPolling();
      }
    });
  }

  function stopSSE() {
    if (sseSource) { try { sseSource.close(); } catch(e){} sseSource = null; }
  }

  // ---------- Polling fallback ----------

  var pollTimer = null;
  var pollInFlight = false;

  function pollOnce() {
    if (pollInFlight || document.hidden) return;
    pollInFlight = true;
    var sinceId = getLatestId();
    var url = 'youtube_feed.php?since_id=' + encodeURIComponent(sinceId) + '&limit=20&sync=1&_=' + Date.now();
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function(r){ return r.json(); })
      .catch(function(){ return null; })
      .then(function(data){
        pollInFlight = false;
        if (!data || !data.ok) return;
        if (data.messages && data.messages.length) {
          handleIncoming(data.messages);
        }
      });
  }

  function startPolling() {
    if (usingFallback) return;
    usingFallback = true;
    stopSSE();
    if (pollTimer) clearInterval(pollTimer);
    pollOnce();
    pollTimer = setInterval(pollOnce, POLL_FALLBACK_MS);
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  // ---------- Lifecycle ----------

  document.addEventListener('visibilitychange', function(){
    if (document.hidden) {
      stopSSE();
      stopPolling();
    } else {
      if (usingFallback) {
        stopPolling();
        pollTimer = setInterval(pollOnce, POLL_FALLBACK_MS);
        pollOnce();
      } else {
        startSSE();
      }
    }
  });

  setTimeout(startSSE, 100);
})();
