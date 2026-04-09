/**
 * NewsFlow — near-real-time Telegram feed updater.
 *
 * Drives both:
 *   1) The `/telegram.php` dedicated page (.tg-grid)
 *   2) The homepage's Telegram section (.tg-breaking)
 *
 * Behavior:
 *   - On load, starts a poll loop that hits /telegram_feed.php?since_id=N
 *     every POLL_INTERVAL_MS (30s by default).
 *   - First poll of each cycle also asks the server to sync-if-stale
 *     (&sync=1), which triggers tg_sync_all_sources under a file lock so
 *     visitors effectively drive updates without waiting for cron.
 *   - When new messages arrive, they're prepended to the grid with a
 *     slide-in animation and the total counter bumps.
 *   - Polling pauses when the tab is hidden, resumes on focus.
 *   - Escalates to a faster interval (15s) for the first 2 minutes after
 *     the page loads, then settles at the normal 30s cadence.
 */
(function(){
  'use strict';

  var POLL_INTERVAL_MS      = 30000;  // normal cadence
  var POLL_INTERVAL_FAST_MS = 15000;  // initial faster cadence
  var FAST_WINDOW_MS        = 120000; // fast cadence lasts this long after load
  var MAX_ON_PAGE_1         = 24;     // cap on /telegram.php page 1

  var grid = document.getElementById('tgGrid') || document.querySelector('.tg-breaking');
  if (!grid) return;

  var pill       = document.getElementById('tgLivePill');
  var pillLabel  = document.getElementById('tgLiveLabel');
  var totalEl    = document.getElementById('tgTotalCount');

  // Latest message id in the current DOM (highest wins)
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

  // Only auto-update page 1 on /telegram.php. On deeper pages, leave the grid alone.
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
    if (!messages || !messages.length) return;
    // Walk backwards so the newest ends up on top after prepending
    for (var i = messages.length - 1; i >= 0; i--) {
      var m = messages[i];
      // Skip if already present (safety)
      if (grid.querySelector('[data-tg-id="'+m.id+'"]')) continue;
      var node = buildNode(m);
      grid.insertBefore(node, grid.firstChild);
    }

    // Cap items on page 1 of /telegram.php so the DOM doesn't grow unboundedly
    if (layout === 'item' && canPrepend) {
      var all = grid.querySelectorAll('.tg-item');
      for (var j = all.length - 1; j >= MAX_ON_PAGE_1; j--) {
        all[j].parentNode && all[j].parentNode.removeChild(all[j]);
      }
    }
  }

  function bumpCounter(added) {
    if (!totalEl) return;
    var n = parseInt((totalEl.textContent || '0').replace(/[^0-9]/g, ''), 10) || 0;
    totalEl.textContent = (n + added).toLocaleString('ar-EG');
  }

  function setPillState(state) {
    if (!pill || !pillLabel) return;
    pill.classList.remove('updating');
    if (state === 'updating') {
      pill.classList.add('updating');
      pillLabel.textContent = 'جاري التحديث...';
    } else if (state === 'new') {
      pillLabel.textContent = 'تم التحديث';
      setTimeout(function(){ if (pillLabel) pillLabel.textContent = 'مباشر'; }, 2500);
    } else {
      pillLabel.textContent = 'مباشر';
    }
  }

  var loadedAt = Date.now();
  var timerId  = null;
  var inFlight = false;

  function poll() {
    if (inFlight) return;
    if (document.hidden) return;
    inFlight = true;
    setPillState('updating');

    var sinceId = getLatestId();
    var url = 'telegram_feed.php?since_id=' + encodeURIComponent(sinceId) + '&limit=20&sync=1&_=' + Date.now();

    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function(r){ return r.json(); })
      .catch(function(){ return null; })
      .then(function(data){
        inFlight = false;
        if (!data || !data.ok) {
          setPillState('idle');
          return;
        }
        if (canPrepend && data.messages && data.messages.length) {
          prependMessages(data.messages);
          bumpCounter(data.messages.length);
          setPillState('new');
        } else {
          setPillState('idle');
        }
      });
  }

  function schedule() {
    if (timerId) clearTimeout(timerId);
    var interval = (Date.now() - loadedAt < FAST_WINDOW_MS) ? POLL_INTERVAL_FAST_MS : POLL_INTERVAL_MS;
    timerId = setTimeout(function(){ poll(); schedule(); }, interval);
  }

  document.addEventListener('visibilitychange', function(){
    if (!document.hidden) {
      poll();     // catch up immediately on focus
      schedule(); // resume timer
    }
  });

  // Kick off: first poll after 2s, then normal cadence.
  setTimeout(function(){ poll(); schedule(); }, 2000);
})();
