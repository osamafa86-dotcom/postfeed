/**
 * Auto-inject "🎧 استمع" buttons onto every article / social
 * card on the page without touching the PHP templates.
 *
 * Runs once on DOMContentLoaded + re-runs when live-update
 * scripts prepend new rows (it idempotently ignores cards
 * that already got a button).
 *
 * Data source per card:
 *   - Article cards: reads the .bn-title / .nf-side-card-title /
 *     .wr-story-headline text for title + the nearest text
 *     sibling for body.
 *   - Social cards:   reads .tg-text / .tw-text / .yt-title
 *     + .*-source > strong for attribution.
 */
(function () {
  if (!window.NF_Audio || window.NF_Audio.unsupported) return;

  var BTN_CLASS = 'nf-listen-btn';
  var INJECTED_ATTR = 'data-nf-listen-mounted';

  // Card configs: selector, title-extractor, body-extractor,
  // whether this card type maps to a real article id (so we
  // can hit the cloud-TTS endpoint) or should stick with the
  // browser fallback.
  var cardTypes = [
    { sel: '.bn-card',         title: '.bn-title',              body: null,       article: true  },
    { sel: '.nf-side-card',    title: '.nf-side-card-title',    body: null,       article: true  },
    { sel: '.nf-feature-main', title: '.nf-feature-main-title', body: null,       article: true, big: true },
    { sel: '.tg-card',         title: '.tg-source strong',      body: '.tg-text', article: false },
    { sel: '.tw-card',         title: '.tw-source strong',      body: '.tw-text', article: false },
    { sel: '.yt-card',         title: '.yt-title',              body: null,       article: false },
  ];

  // Extract the article id from the card's anchor href when the
  // card type is backed by an article. Matches /article/123 and
  // /article/123/slug — the two shapes the .htaccess rewrite
  // accepts.
  function getArticleId(cardEl) {
    var a = cardEl.tagName === 'A' ? cardEl : cardEl.querySelector('a[href*="/article/"]');
    if (!a) return 0;
    var m = (a.getAttribute('href') || '').match(/\/article\/(\d+)/);
    return m ? parseInt(m[1], 10) : 0;
  }

  function ensureStyle() {
    if (document.getElementById('nf-listen-btn-style')) return;
    var st = document.createElement('style');
    st.id = 'nf-listen-btn-style';
    st.textContent = ''
      + '.' + BTN_CLASS + '{position:absolute;top:8px;left:8px;z-index:5;'
      + 'width:32px;height:32px;border-radius:50%;border:0;cursor:pointer;'
      + 'background:rgba(15,23,42,0.85);color:#fff;font-size:14px;'
      + 'display:inline-flex;align-items:center;justify-content:center;'
      + 'backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);'
      + 'opacity:0;transform:scale(.88);transition:opacity .15s,transform .15s,background .15s;'
      + 'padding:0;font-family:inherit;box-shadow:0 4px 12px rgba(0,0,0,0.25)}'
      + '.' + BTN_CLASS + '.big{width:38px;height:38px;font-size:16px;top:12px;left:12px}'
      + '.' + BTN_CLASS + ':hover{background:#f59e0b;color:#0f172a;transform:scale(1)}'
      + '.' + BTN_CLASS + '.playing{background:#f59e0b;color:#0f172a;opacity:1;transform:scale(1);'
      + 'animation:nfListenPulse 1.6s ease-in-out infinite}'
      + '@keyframes nfListenPulse{0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.7)}'
      + '50%{box-shadow:0 0 0 8px rgba(245,158,11,0)}}'
      + '.bn-card:hover .' + BTN_CLASS + ','
      + '.nf-side-card:hover .' + BTN_CLASS + ','
      + '.nf-feature-main:hover .' + BTN_CLASS + ','
      + '.tg-card:hover .' + BTN_CLASS + ','
      + '.tw-card:hover .' + BTN_CLASS + ','
      + '.yt-card:hover .' + BTN_CLASS + '{opacity:1;transform:scale(1)}'
      /* Touch devices — always visible because there\'s no hover. */
      + '@media (hover:none){.' + BTN_CLASS + '{opacity:1;transform:scale(1)}}'
      + '.bn-card,.nf-side-card,.nf-feature-main,.tg-card,.tw-card,.yt-card{position:relative}';
    document.head.appendChild(st);
  }

  function makeButton(isBig) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = BTN_CLASS + (isBig ? ' big' : '');
    b.setAttribute('aria-label', 'استمع للخبر');
    b.setAttribute('title', 'استمع');
    b.innerHTML = '🎧';
    // The card is usually wrapped in an <a> — clicking the button
    // must NOT follow the link or bubble through to whatever card
    // click handler exists.
    b.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      handlePlay(b);
    });
    return b;
  }

  function extractText(cardEl, cfg) {
    var titleEl = cardEl.querySelector(cfg.title);
    var title   = titleEl ? titleEl.textContent.trim() : '';
    var body    = '';
    if (cfg.body) {
      var bodyEl = cardEl.querySelector(cfg.body);
      if (bodyEl) body = bodyEl.textContent.trim();
    }
    return { title: title, body: body };
  }

  function handlePlay(btn) {
    var cardEl = btn.closest(cardTypes.map(function (c) { return c.sel; }).join(', '));
    if (!cardEl) return;
    var cfg = cardTypes.find(function (c) { return cardEl.matches(c.sel); });
    if (!cfg) return;
    var txt = extractText(cardEl, cfg);
    if (!txt.title) return;
    var full = txt.title + (txt.body ? '. ' + txt.body : '');

    // If this same card is already playing, treat click as "stop".
    var state = window.NF_Audio.state();
    if (state.playing && btn.classList.contains('playing')) {
      window.NF_Audio.stop();
      clearAllPlayingFlags();
      return;
    }

    clearAllPlayingFlags();
    btn.classList.add('playing');

    // Article-backed cards: try cloud TTS first (MP3 cached on
    // the server), fall back to browser Web Speech on 404 / err.
    if (cfg.article) {
      var articleId = getArticleId(cardEl);
      if (articleId) {
        window.NF_Audio.playArticle(articleId, txt.title, full);
      } else {
        window.NF_Audio.play(txt.title, full);
      }
    } else {
      // Social cards have no article id — use the browser TTS
      // with the full card text.
      window.NF_Audio.play(txt.title, full);
    }

    // Clear the flag when playback ends. Poll briefly — the
    // SpeechSynthesis API doesn't expose a clean "ended" event
    // from outside the utterance.
    var poll = setInterval(function () {
      var s = window.NF_Audio.state();
      if (!s.playing) {
        btn.classList.remove('playing');
        clearInterval(poll);
      }
    }, 500);
  }

  function clearAllPlayingFlags() {
    document.querySelectorAll('.' + BTN_CLASS + '.playing')
      .forEach(function (el) { el.classList.remove('playing'); });
  }

  function injectInto(root) {
    ensureStyle();
    cardTypes.forEach(function (cfg) {
      var cards = (root || document).querySelectorAll(cfg.sel);
      cards.forEach(function (card) {
        if (card.getAttribute(INJECTED_ATTR)) return;
        // Skip if title element is missing (rare empty-state cards).
        if (!card.querySelector(cfg.title)) return;
        card.setAttribute(INJECTED_ATTR, '1');
        var btn = makeButton(!!cfg.big);
        card.appendChild(btn);
      });
    });
  }

  // Initial pass.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { injectInto(document); });
  } else {
    injectInto(document);
  }

  // The tg/tw/yt live-update scripts prepend new cards; catch
  // them with a MutationObserver instead of polling.
  var mo = new MutationObserver(function (mutations) {
    var needed = false;
    for (var i = 0; i < mutations.length; i++) {
      if (mutations[i].addedNodes.length) { needed = true; break; }
    }
    if (needed) injectInto(document);
  });
  mo.observe(document.body, { childList: true, subtree: true });
})();
