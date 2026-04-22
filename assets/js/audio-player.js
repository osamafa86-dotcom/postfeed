/**
 * نيوز فيد — Browser-native TTS audio player.
 *
 * No server calls, no API keys, no quota. Uses
 * window.speechSynthesis which is available on every modern
 * browser (iOS 14+, Chrome, Firefox, Edge, Samsung Internet).
 *
 * Public surface (window.NF_Audio):
 *   play(title, text, opts)  — start speaking, mount mini-player
 *   pause()                   — pause current playback
 *   resume()                  — resume paused playback
 *   stop()                    — stop + unmount mini-player
 *   setRate(r)                — 0.5 .. 2.0 (persisted)
 *   state()                   — {playing, paused, title, rate}
 *
 * Also mounts a persistent floating mini-player with play/pause,
 * 1x/1.25x/1.5x speed buttons, and a close button.
 *
 * Keeps two quality knobs:
 *   - Voice preference: ar-SA > ar-EG > ar-* > any (many Chrome
 *     installs ship only one Arabic voice; whichever we get, we
 *     pick the most premium tier they expose).
 *   - Chunking: SpeechSynthesis silently chops utterances longer
 *     than ~200 chars on some platforms. We split on sentence
 *     boundaries and queue them so long reports read to the end.
 */
(function () {
  'use strict';

  if (!('speechSynthesis' in window)) {
    window.NF_Audio = { unsupported: true };
    return;
  }

  var RATE_KEY = 'nf_audio_rate_v1';
  var state = {
    playing: false,
    paused:  false,
    title:   '',
    rate:    parseFloat(localStorage.getItem(RATE_KEY) || '1') || 1,
    queue:   [],
    cursor:  0,
    current: null,
  };

  // ---- VOICE SELECTION --------------------------------------
  // voices populate asynchronously on some browsers; cache the
  // best-match once and refresh on voiceschanged.
  var preferredVoice = null;
  function pickVoice() {
    var voices = speechSynthesis.getVoices();
    if (!voices || !voices.length) { preferredVoice = null; return; }
    // Score: ar-SA = 5, ar-EG = 4, other ar = 3, default arabic-speaking = 2, any = 1
    var scored = voices.map(function (v) {
      var lang = (v.lang || '').toLowerCase();
      var score = 0;
      if (lang === 'ar-sa' || lang === 'ar_sa') score = 5;
      else if (lang === 'ar-eg') score = 4;
      else if (lang.indexOf('ar') === 0) score = 3;
      else if (v.lang && v.lang.toLowerCase().indexOf('ar') !== -1) score = 2;
      // Microsoft "Natural" / Google "wavenet" voices are higher quality.
      if (/natural|wavenet|neural/i.test(v.name)) score += 0.5;
      return { v: v, score: score };
    }).sort(function (a, b) { return b.score - a.score; });
    preferredVoice = scored[0] && scored[0].score > 0 ? scored[0].v : null;
  }
  pickVoice();
  speechSynthesis.addEventListener('voiceschanged', pickVoice);

  // ---- CHUNKING ---------------------------------------------
  // Split at Arabic + Latin sentence terminators; keep chunks
  // under 200 chars so Chromium doesn't cut them off.
  function chunkText(text) {
    var normalized = String(text || '')
      .replace(/\s+/g, ' ')
      .replace(/[<>]/g, ' ')
      .trim();
    if (!normalized) return [];
    var pieces = normalized.split(/(?<=[.!?؟۔])\s+/);
    var out = [];
    pieces.forEach(function (p) {
      while (p.length > 200) {
        var cut = p.lastIndexOf(' ', 200);
        if (cut < 80) cut = 200;
        out.push(p.slice(0, cut).trim());
        p = p.slice(cut).trim();
      }
      if (p) out.push(p);
    });
    return out;
  }

  // ---- MINI-PLAYER DOM --------------------------------------
  var playerEl = null;
  function mountPlayer() {
    if (playerEl) return playerEl;
    playerEl = document.createElement('div');
    playerEl.id = 'nf-audio-player';
    playerEl.setAttribute('role', 'region');
    playerEl.setAttribute('aria-label', 'مشغّل الصوت');
    playerEl.innerHTML = ''
      + '<style>'
      + '#nf-audio-player{position:fixed;left:12px;right:12px;bottom:12px;z-index:10001;'
      + 'background:linear-gradient(135deg,#0f172a 0%,#1a5c5c 100%);color:#fff;'
      + 'border-radius:14px;padding:10px 14px;display:flex;align-items:center;gap:12px;'
      + 'box-shadow:0 20px 50px rgba(0,0,0,.35);font-family:inherit;direction:rtl;'
      + 'max-width:640px;margin:0 auto;animation:nfAudioSlide .3s ease-out}'
      + '@keyframes nfAudioSlide{from{transform:translateY(120%);opacity:0}to{transform:none;opacity:1}}'
      + '#nf-audio-player .nf-a-ico{flex:0 0 36px;width:36px;height:36px;border-radius:10px;'
      + 'background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:18px}'
      + '#nf-audio-player .nf-a-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}'
      + '#nf-audio-player .nf-a-t{font-size:13px;font-weight:700;line-height:1.35;'
      + 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
      + '#nf-audio-player .nf-a-s{font-size:11px;opacity:.75;line-height:1.3}'
      + '#nf-audio-player button{font-family:inherit;cursor:pointer;border:0;background:transparent;'
      + 'color:#fff;padding:6px 8px;border-radius:6px;font-size:13px;font-weight:700}'
      + '#nf-audio-player button:hover{background:rgba(255,255,255,.1)}'
      + '#nf-audio-player .nf-a-pp{background:#fff;color:#0f172a;width:34px;height:34px;padding:0;'
      + 'border-radius:50%;font-size:15px;display:inline-flex;align-items:center;justify-content:center}'
      + '#nf-audio-player .nf-a-pp:hover{background:#fff;transform:scale(1.05)}'
      + '#nf-audio-player .nf-a-rate{background:rgba(255,255,255,.12);font-size:11px;padding:4px 8px}'
      + '#nf-audio-player .nf-a-rate.active{background:#f59e0b;color:#0f172a}'
      + '#nf-audio-player .nf-a-x{opacity:.7;font-size:18px;line-height:1}'
      + '#nf-audio-player .nf-a-x:hover{opacity:1}'
      + '@media(max-width:520px){#nf-audio-player{padding:8px 10px;gap:8px}'
      + '#nf-audio-player .nf-a-t{font-size:12.5px}'
      + '#nf-audio-player .nf-a-rate{display:none}}'
      + '</style>'
      + '<div class="nf-a-ico" aria-hidden="true">🎧</div>'
      + '<div class="nf-a-body">'
      +   '<div class="nf-a-t" data-t>—</div>'
      +   '<div class="nf-a-s" data-s>يتم التشغيل عبر صوت المتصفح</div>'
      + '</div>'
      + '<button type="button" class="nf-a-rate" data-rate="1">1x</button>'
      + '<button type="button" class="nf-a-rate" data-rate="1.25">1.25x</button>'
      + '<button type="button" class="nf-a-rate" data-rate="1.5">1.5x</button>'
      + '<button type="button" class="nf-a-pp" data-pp aria-label="تشغيل/إيقاف">⏸</button>'
      + '<button type="button" class="nf-a-x" data-close aria-label="إغلاق">×</button>';
    document.body.appendChild(playerEl);

    playerEl.querySelector('[data-pp]').addEventListener('click', togglePause);
    playerEl.querySelector('[data-close]').addEventListener('click', stop);
    playerEl.querySelectorAll('[data-rate]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setRate(parseFloat(btn.getAttribute('data-rate')));
      });
    });
    return playerEl;
  }

  function syncPlayerUI() {
    if (!playerEl) return;
    var pp = playerEl.querySelector('[data-pp]');
    if (pp) pp.textContent = state.paused ? '▶' : '⏸';
    var title = playerEl.querySelector('[data-t]');
    if (title) title.textContent = state.title || '—';
    playerEl.querySelectorAll('[data-rate]').forEach(function (b) {
      b.classList.toggle('active', parseFloat(b.getAttribute('data-rate')) === state.rate);
    });
  }

  function unmountPlayer() {
    if (playerEl && playerEl.parentNode) playerEl.parentNode.removeChild(playerEl);
    playerEl = null;
  }

  // ---- PLAYBACK ---------------------------------------------
  function playNextChunk() {
    if (state.cursor >= state.queue.length) {
      state.playing = false; state.paused = false; state.current = null;
      syncPlayerUI();
      unmountPlayer();
      return;
    }
    var u = new SpeechSynthesisUtterance(state.queue[state.cursor]);
    u.rate = state.rate;
    u.pitch = 1.0;
    u.volume = 1.0;
    u.lang = preferredVoice ? preferredVoice.lang : 'ar-SA';
    if (preferredVoice) u.voice = preferredVoice;
    u.onend = function () { state.cursor++; playNextChunk(); };
    u.onerror = function () { state.cursor++; playNextChunk(); };
    state.current = u;
    speechSynthesis.speak(u);
  }

  function play(title, text, opts) {
    stop();  // cancel anything in flight
    var chunks = chunkText(text);
    if (!chunks.length) return false;
    state.title   = title || '';
    state.queue   = chunks;
    state.cursor  = 0;
    state.playing = true;
    state.paused  = false;
    mountPlayer();
    syncPlayerUI();
    playNextChunk();
    return true;
  }

  function pause() {
    if (!state.playing || state.paused) return;
    speechSynthesis.pause();
    state.paused = true;
    syncPlayerUI();
  }

  function resume() {
    if (!state.playing || !state.paused) return;
    speechSynthesis.resume();
    state.paused = false;
    syncPlayerUI();
  }

  function togglePause() { state.paused ? resume() : pause(); }

  function stop() {
    try { speechSynthesis.cancel(); } catch (e) {}
    state.playing = false; state.paused = false;
    state.queue = []; state.cursor = 0; state.current = null;
    state.title = '';
    unmountPlayer();
  }

  function setRate(r) {
    r = Math.max(0.5, Math.min(2, r));
    state.rate = r;
    try { localStorage.setItem(RATE_KEY, String(r)); } catch (e) {}
    // Apply to the currently-speaking utterance by cancelling and
    // re-starting from the same cursor — SpeechSynthesis has no
    // live-rate change.
    if (state.playing) {
      try { speechSynthesis.cancel(); } catch (e) {}
      playNextChunk();
    }
    syncPlayerUI();
  }

  // Browsers often cut off long utterances when the tab is in the
  // background. A 14s heartbeat pauses+resumes the queue which
  // keeps Chromium happy on long reads.
  setInterval(function () {
    if (state.playing && !state.paused && speechSynthesis.speaking) {
      try { speechSynthesis.pause(); speechSynthesis.resume(); } catch (e) {}
    }
  }, 14000);

  window.NF_Audio = {
    play: play,
    pause: pause,
    resume: resume,
    stop: stop,
    toggle: togglePause,
    setRate: setRate,
    state: function () {
      return { playing: state.playing, paused: state.paused, title: state.title, rate: state.rate };
    },
    unsupported: false,
  };
})();
