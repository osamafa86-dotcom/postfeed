/**
 * Thin wrapper around GA4 (gtag) that auto-wires a handful of
 * high-value custom events without requiring per-page JS.
 *
 * Events emitted:
 *   - share          { method, content_type, article_id?, url }
 *   - bookmark       { action: 'add'|'remove', article_id }
 *   - search         { search_term }
 *   - newsletter_signup { status: 'submit' }
 *   - outbound_click { url }
 *
 * Pages can also emit events directly via window.nfTrack(name, params).
 * We intentionally avoid hard-coding a content_type on the click handler
 * — the server-side analytics.php component already passes that as a
 * default parameter on the config call.
 */
(function () {
  'use strict';
  if (typeof window.nfTrack !== 'function') return;

  var track = window.nfTrack;

  // --- Share buttons ---------------------------------------------------
  // The site uses a few different share UIs (native share sheet, per-
  // network anchor, copy-link). They all expose a data-share attribute
  // in their root element; we read the value for the network name.
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-share]');
    if (!el) return;
    var method = el.getAttribute('data-share') || 'unknown';
    var articleId = el.getAttribute('data-article-id') || el.closest('[data-article-id]')?.getAttribute('data-article-id') || '';
    track('share', {
      method: method,
      article_id: articleId,
      url: location.pathname + location.search
    });
  }, { passive: true });

  // --- Bookmark / save -------------------------------------------------
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-bookmark]');
    if (!el) return;
    var action = el.getAttribute('data-bookmark') || 'toggle';
    var articleId = el.getAttribute('data-article-id') || el.closest('[data-article-id]')?.getAttribute('data-article-id') || '';
    track('bookmark', { action: action, article_id: articleId });
  }, { passive: true });

  // --- Search (header input) -------------------------------------------
  // Debounced; only emit when the user stops typing for 800ms AND the
  // query is non-trivial. Avoids firing per-keystroke.
  var searchInput = document.getElementById('nfSearchInput');
  if (searchInput) {
    var lastFired = '';
    var timer = null;
    searchInput.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var q = (searchInput.value || '').trim();
        if (q.length < 3 || q === lastFired) return;
        lastFired = q;
        track('search', { search_term: q });
      }, 800);
    });
  }

  // --- Newsletter form submission --------------------------------------
  var nlForm = document.querySelector('form[data-newsletter-form], form#newsletter-form');
  if (nlForm) {
    nlForm.addEventListener('submit', function () {
      track('newsletter_signup', { status: 'submit' });
    });
  }

  // --- Outbound link clicks --------------------------------------------
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (!/^https?:/i.test(href)) return;
    try {
      var u = new URL(href, location.href);
      if (u.hostname && u.hostname !== location.hostname) {
        track('outbound_click', { url: u.href, host: u.hostname });
      }
    } catch (_) { /* bad href — ignore */ }
  }, { passive: true, capture: true });
})();
