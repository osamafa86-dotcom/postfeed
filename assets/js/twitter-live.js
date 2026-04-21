/**
 * NewsFlow — real-time Twitter/X feed updater for the homepage.
 *
 * Transport: Server-Sent Events via /twitter_stream.php (primary) with
 * /twitter_feed.php JSON polling as a fallback after 3 SSE failures.
 *
 * Behavior is the one-to-one sibling of telegram-live.js — same
 * animation, same lifecycle, same pause-on-hidden trick. Lives on its
 * own so it can evolve independently (Twitter-specific edge cases like
 * ratelimit backoff, etc).
 */
(function(){
  'use strict';

  var POLL_FALLBACK_MS = 20000;

  var grid = document.querySelector('.tw-breaking');
  if (!grid) return;

  function getLatestId() {
    var fromAttr = parseInt(grid.getAttribute('data-latest-id') || '0', 10);
    var maxId = isNaN(fromAttr) ? 0 : fromAttr;
    var items = grid.querySelectorAll('[data-tw-id]');
    for (var i = 0; i < items.length; i++) {
      var id = parseInt(items[i].getAttribute('data-tw-id') || '0', 10);
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
    a.className = 'tw-card is-new';
    a.href = m.url;
    a.target = '_blank';
    a.rel = 'noopener';
    a.setAttribute('data-tw-id', String(m.id));

    var imgHtml = '';
    if (m.image_url) {
      imgHtml = '<div class="tw-img"><img src="'+escapeHtml(m.image_url)+'" alt="" loading="lazy" decoding="async"></div>';
    }

    a.innerHTML =
      imgHtml +
      '<div class="tw-body">' +
        '<div class="tw-source">' +
          '<span class="tw-badge">🐦 X</span>' +
          '<strong>@'+escapeHtml(m.username)+'</strong>' +
          '<span class="tw-time">'+escapeHtml(m.time_ago || '')+'</span>' +
        '</div>' +
        '<div class="tw-text">'+escapeHtml(m.text || '')+'</div>' +
      '</div>';

    setTimeout(function(){ a.classList.remove('is-new'); }, 1200);
    return a;
  }

  function prependMessages(messages) {
    if (!messages || !messages.length) return 0;
    var added = 0;
    for (var i = messages.length - 1; i >= 0; i--) {
      var m = messages[i];
      if (grid.querySelector('[data-tw-id="'+m.id+'"]')) continue;
      var node = buildNode(m);
      grid.insertBefore(node, grid.firstChild);
      added++;
    }
    return added;
  }

  function handleIncoming(messages) {
    if (!canPrepend || !messages || !messages.length) return;
    prependMessages(messages);
  }

  // ---------- Transport 1: Server-Sent Events ----------

  var sseSource    = null;
  var sseFailCount = 0;
  var usingFallback = false;

  function startSSE() {
    if (typeof window.EventSource !== 'function') { startPolling(); return; }
    if (sseSource) { try { sseSource.close(); } catch(e){} sseSource = null; }

    var url = 'twitter_stream.php?since_id=' + encodeURIComponent(getLatestId()) + '&_=' + Date.now();
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

  // ---------- Transport 2: polling fallback ----------

  var pollTimer = null;
  var pollInFlight = false;

  function pollOnce() {
    if (pollInFlight || document.hidden) return;
    pollInFlight = true;
    var sinceId = getLatestId();
    var url = 'twitter_feed.php?since_id=' + encodeURIComponent(sinceId) + '&limit=20&sync=1&_=' + Date.now();
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

  setTimeout(startSSE, 500);
})();
