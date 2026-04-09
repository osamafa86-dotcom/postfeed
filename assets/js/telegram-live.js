/**
 * NewsFlow — real-time Telegram feed updater.
 *
 * Drives both:
 *   1) The `/telegram.php` dedicated page (.tg-grid)
 *   2) The homepage's Telegram section (.tg-breaking)
 *
 * Primary transport: Server-Sent Events via /telegram_stream.php.
 *   The stream stays open; the server pushes new messages as soon as
 *   they're scraped, so the page updates live with ~seconds of latency.
 *
 * Fallback: polling /telegram_feed.php every 20s if the browser is too
 * old for EventSource or the stream errors out repeatedly.
 *
 * Behavior:
 *   - New messages are prepended to the grid with a slide-in animation
 *     and the total counter bumps.
 *   - The live pill flashes "تم التحديث" for 2.5s whenever new content
 *     arrives, then returns to "مباشر".
 *   - Streaming pauses when the tab is hidden and reconnects on focus
 *     so we don't waste PHP-FPM workers on backgrounded tabs.
 */
(function(){
  'use strict';

  var MAX_ON_PAGE_1    = 24;     // cap items on page 1 of /telegram.php
  var POLL_FALLBACK_MS = 20000;  // fallback polling cadence (SSE not available)

  var grid = document.getElementById('tgGrid') || document.querySelector('.tg-breaking');
  if (!grid) return;

  var pill      = document.getElementById('tgLivePill');
  var pillLabel = document.getElementById('tgLiveLabel');
  var totalEl   = document.getElementById('tgTotalCount');

  // Latest message id known to the client — sync with server via since_id.
  function getLatestId() {
    var fromAttr = parseInt(grid.getAttribute('data-latest-id') || '0', 10);
    var maxId = isNaN(fromAttr) ? 0 : fromAttr;
    var items = grid.querySelectorAll('[data-tg-id]');
    for (var i = 0; i < items.length; i++) {
      var id = parseInt(items[i].getAttribute('data-tg-id') || '0', 10);
      if (id > maxId) maxId = id;
    }
    return maxId;
  }

  // Only update page 1 of /telegram.php. Deeper pages stay static.
  var pageNumber = parseInt(grid.getAttribute('data-page') || '1', 10);
  if (isNaN(pageNumber)) pageNumber = 1;
  var canPrepend = (pageNumber === 1);

  // Detect which layout the grid uses so we emit matching markup.
  // /telegram.php uses .tg-item; index.php uses .tg-card.
  var layout = grid.id === 'tgGrid' ? 'item' : 'card';

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function buildNode(m) {
    var a = document.createElement('a');
    a.className = (layout === 'item' ? 'tg-item is-new' : 'tg-card is-new');
    a.href = m.url;
    a.target = '_blank';
    a.rel = 'noopener';
    a.setAttribute('data-tg-id', String(m.id));

    var imgHtml = '';
    if (m.image_url) {
      imgHtml = layout === 'item'
        ? '<div class="tg-item-img"><img src="'+escapeHtml(m.image_url)+'" alt="" loading="lazy" decoding="async"></div>'
        : '<div class="tg-img"><img src="'+escapeHtml(m.image_url)+'" alt="" loading="lazy" decoding="async"></div>';
    }

    if (layout === 'item') {
      a.innerHTML =
        imgHtml +
        '<div class="tg-item-body">' +
          '<div class="tg-item-source">' +
            '<span class="tg-item-badge">📢 تيليغرام</span>' +
            '<strong>@'+escapeHtml(m.username)+'</strong>' +
            '<span class="tg-item-time" data-tg-time="'+escapeHtml(m.posted_at || '')+'">'+escapeHtml(m.time_ago || '')+'</span>' +
          '</div>' +
          '<div class="tg-item-text">'+escapeHtml(m.text || '')+'</div>' +
        '</div>';
    } else {
      a.innerHTML =
        imgHtml +
        '<div class="tg-body">' +
          '<div class="tg-source">' +
            '<span class="tg-badge">📢 تيليغرام</span>' +
            '<strong>@'+escapeHtml(m.username)+'</strong>' +
            '<span class="tg-time">'+escapeHtml(m.time_ago || '')+'</span>' +
          '</div>' +
          '<div class="tg-text">'+escapeHtml(m.text || '')+'</div>' +
        '</div>';
    }

    // Remove the is-new class after animation ends so re-hovers don't replay
    setTimeout(function(){ a.classList.remove('is-new'); }, 1200);
    return a;
  }

  function prependMessages(messages) {
    if (!messages || !messages.length) return 0;
    // Walk backwards so the newest ends up on top after prepending
    var added = 0;
    for (var i = messages.length - 1; i >= 0; i--) {
      var m = messages[i];
      // Skip if already present (safety)
      if (grid.querySelector('[data-tg-id="'+m.id+'"]')) continue;
      var node = buildNode(m);
      grid.insertBefore(node, grid.firstChild);
      added++;
    }

    // Cap items on page 1 of /telegram.php so the DOM doesn't grow unboundedly
    if (layout === 'item' && canPrepend) {
      var all = grid.querySelectorAll('.tg-item');
      for (var j = all.length - 1; j >= MAX_ON_PAGE_1; j--) {
        all[j].parentNode && all[j].parentNode.removeChild(all[j]);
      }
    }
    return added;
  }

  function bumpCounter(added) {
    if (!totalEl || !added) return;
    var n = parseInt((totalEl.textContent || '0').replace(/[^0-9]/g, ''), 10) || 0;
    totalEl.textContent = (n + added).toLocaleString('ar-EG');
  }

  var pillResetTimer = null;
  function setPillState(state) {
    if (!pill || !pillLabel) return;
    pill.classList.remove('updating');
    if (pillResetTimer) { clearTimeout(pillResetTimer); pillResetTimer = null; }
    if (state === 'updating') {
      pill.classList.add('updating');
      pillLabel.textContent = 'جاري التحديث...';
    } else if (state === 'new') {
      pillLabel.textContent = 'تم التحديث';
      pillResetTimer = setTimeout(function(){ if (pillLabel) pillLabel.textContent = 'مباشر'; }, 2500);
    } else {
      pillLabel.textContent = 'مباشر';
    }
  }

  function handleIncoming(messages) {
    if (!canPrepend || !messages || !messages.length) return;
    var added = prependMessages(messages);
    if (added > 0) {
      bumpCounter(added);
      setPillState('new');
    }
  }

  // ---------- Transport 1: Server-Sent Events ----------

  var sseSource    = null;
  var sseFailCount = 0;
  var usingFallback = false;

  function startSSE() {
    if (typeof window.EventSource !== 'function') { startPolling(); return; }
    if (sseSource) { try { sseSource.close(); } catch(e){} sseSource = null; }

    var url = 'telegram_stream.php?since_id=' + encodeURIComponent(getLatestId()) + '&_=' + Date.now();
    try {
      sseSource = new EventSource(url);
    } catch (e) {
      startPolling();
      return;
    }

    sseSource.addEventListener('open', function(){
      sseFailCount = 0;
      setPillState('idle');
    });

    sseSource.addEventListener('hello', function(){
      // Server said hi — connection is live.
      setPillState('idle');
    });

    sseSource.addEventListener('messages', function(ev){
      try {
        var payload = JSON.parse(ev.data);
        handleIncoming(payload && payload.messages);
      } catch (e) { /* ignore malformed frame */ }
    });

    sseSource.addEventListener('bye', function(){
      // Server told us it hit its max lifetime. Close and reconnect —
      // this is the normal "rotate connection" path.
      try { sseSource.close(); } catch(e){}
      sseSource = null;
      if (!document.hidden) startSSE();
    });

    sseSource.addEventListener('error', function(){
      // Network blip or server-side crash. EventSource will try to
      // auto-reconnect, but if it keeps failing we fall back to polling.
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
    setPillState('updating');
    var sinceId = getLatestId();
    var url = 'telegram_feed.php?since_id=' + encodeURIComponent(sinceId) + '&limit=20&sync=1&_=' + Date.now();
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function(r){ return r.json(); })
      .catch(function(){ return null; })
      .then(function(data){
        pollInFlight = false;
        if (!data || !data.ok) { setPillState('idle'); return; }
        if (data.messages && data.messages.length) {
          handleIncoming(data.messages);
        } else {
          setPillState('idle');
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
      // Don't hold an SSE connection open for a backgrounded tab.
      stopSSE();
      stopPolling();
    } else {
      // Coming back into focus — reconnect fresh.
      if (usingFallback) {
        stopPolling();
        pollTimer = setInterval(pollOnce, POLL_FALLBACK_MS);
        pollOnce();
      } else {
        startSSE();
      }
    }
  });

  // Kick off: wait a beat for the rest of the page to settle.
  setTimeout(startSSE, 500);
})();
