/**
 * PWA install prompt — shows a small bottom-sheet banner that lets
 * the reader add the site to their home screen.
 *
 *   Android/Chromium: captures `beforeinstallprompt`, waits for the
 *                     user to click "تثبيت", then calls .prompt().
 *   iOS Safari:       beforeinstallprompt never fires, so we detect
 *                     iOS + non-standalone and show a brief hint
 *                     explaining the Share → "Add to Home Screen"
 *                     gesture instead.
 *
 * The banner is dismissible and won't reappear within 14 days after
 * a dismiss, or ever after a successful install. No CSS framework —
 * all styles are scoped via #nf-install-banner so it stays isolated.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'nf_install_banner_v1';
  var HIDE_DAYS   = 14;

  function isStandalone() {
    return (
      window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true
    );
  }

  // Flag the body when launched as an installed PWA so CSS can
  // adjust the header safe-area on iOS (where
  // `@media (display-mode: standalone)` doesn't always fire).
  if (isStandalone()) {
    document.documentElement.classList.add('nf-standalone');
    if (document.body) {
      document.body.classList.add('nf-standalone');
    } else {
      document.addEventListener('DOMContentLoaded', function () {
        document.body.classList.add('nf-standalone');
      });
    }
  }

  function isIOS() {
    var ua = navigator.userAgent || '';
    var isIDevice = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
    // iPadOS 13+ reports as "MacIntel" with touch — catch that too.
    var isIPadDesktop = ua.includes('Mac') && 'ontouchend' in document;
    return isIDevice || isIPadDesktop;
  }

  function isDismissed() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return false;
      var parsed = JSON.parse(raw);
      if (parsed.installed) return true;
      if (!parsed.dismissedAt) return false;
      var age = (Date.now() - parsed.dismissedAt) / 86400000;
      return age < HIDE_DAYS;
    } catch (e) { return false; }
  }

  function persist(partial) {
    try {
      var cur = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
      Object.assign(cur, partial);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(cur));
    } catch (e) {}
  }

  function buildBanner(opts) {
    var el = document.createElement('div');
    el.id = 'nf-install-banner';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'تثبيت التطبيق');
    el.innerHTML = ''
      + '<style>'
      + '#nf-install-banner{position:fixed;left:12px;right:12px;bottom:12px;z-index:10000;'
      + 'background:linear-gradient(135deg,#1a5c5c 0%,#0d9488 100%);color:#fff;'
      + 'border-radius:16px;padding:14px 16px;display:flex;align-items:center;gap:12px;'
      + 'box-shadow:0 20px 50px rgba(0,0,0,.35);font-family:inherit;direction:rtl;'
      + 'animation:nfInstallSlide .3s ease-out}'
      + '@keyframes nfInstallSlide{from{transform:translateY(120%);opacity:0}to{transform:none;opacity:1}}'
      + '#nf-install-banner .nf-inst-ico{flex:0 0 44px;width:44px;height:44px;border-radius:12px;'
      + 'background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:22px}'
      + '#nf-install-banner .nf-inst-body{flex:1;min-width:0}'
      + '#nf-install-banner .nf-inst-t{font-size:14px;font-weight:800;line-height:1.3}'
      + '#nf-install-banner .nf-inst-s{font-size:12px;opacity:.88;line-height:1.4;margin-top:2px}'
      + '#nf-install-banner button{font-family:inherit;cursor:pointer;border:0}'
      + '#nf-install-banner .nf-inst-cta{padding:10px 16px;background:#fff;color:#0f172a;'
      + 'border-radius:10px;font-size:13px;font-weight:800;white-space:nowrap}'
      + '#nf-install-banner .nf-inst-cta:active{transform:scale(.97)}'
      + '#nf-install-banner .nf-inst-x{background:transparent;color:#fff;font-size:22px;'
      + 'width:32px;height:32px;border-radius:8px;opacity:.7;line-height:1}'
      + '#nf-install-banner .nf-inst-x:hover{opacity:1;background:rgba(255,255,255,.1)}'
      + '@media(max-width:380px){'
      + '#nf-install-banner{padding:12px;gap:8px}'
      + '#nf-install-banner .nf-inst-t{font-size:13px}'
      + '#nf-install-banner .nf-inst-s{font-size:11px}'
      + '#nf-install-banner .nf-inst-cta{padding:9px 13px;font-size:12px}}'
      + '@media (max-width:520px) and (min-width:381px){'
      + '#nf-install-banner{padding:12px 14px}}'
      + '</style>'
      + '<div class="nf-inst-ico" aria-hidden="true">📲</div>'
      + '<div class="nf-inst-body">'
      +   '<div class="nf-inst-t">ثبّت نيوز فيد على شاشتك</div>'
      +   '<div class="nf-inst-s">' + opts.sub + '</div>'
      + '</div>'
      + (opts.showCta ? '<button type="button" class="nf-inst-cta">' + opts.cta + '</button>' : '')
      + '<button type="button" class="nf-inst-x" aria-label="إغلاق">×</button>';
    return el;
  }

  function dismiss(el) {
    if (el && el.parentNode) el.parentNode.removeChild(el);
    persist({ dismissedAt: Date.now() });
  }

  // ---- Chromium / Android path ---------------------------------
  var deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    if (isDismissed() || isStandalone()) return;

    var banner = buildBanner({
      showCta: true,
      cta: 'تثبيت',
      sub: 'وصول مباشر من شاشتك الرئيسية بدون متصفح'
    });
    document.body.appendChild(banner);

    banner.querySelector('.nf-inst-cta').addEventListener('click', function () {
      if (!deferredPrompt) { dismiss(banner); return; }
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(function (choice) {
        if (choice && choice.outcome === 'accepted') {
          persist({ installed: true });
        } else {
          persist({ dismissedAt: Date.now() });
        }
        deferredPrompt = null;
        if (banner.parentNode) banner.parentNode.removeChild(banner);
      });
    });

    banner.querySelector('.nf-inst-x').addEventListener('click', function () {
      dismiss(banner);
    });
  });

  window.addEventListener('appinstalled', function () {
    persist({ installed: true });
    var b = document.getElementById('nf-install-banner');
    if (b && b.parentNode) b.parentNode.removeChild(b);
  });

  // ---- iOS Safari path -----------------------------------------
  // Show the hint after a short delay so it doesn't hijack the
  // first paint or collide with the on-page feed animations.
  if (isIOS() && !isStandalone() && !isDismissed()) {
    window.addEventListener('load', function () {
      setTimeout(function () {
        if (document.getElementById('nf-install-banner')) return;
        var banner = buildBanner({
          showCta: false,
          sub: 'في سفاري: زر المشاركة ⎙ ← "إضافة إلى الشاشة الرئيسية"'
        });
        document.body.appendChild(banner);
        banner.querySelector('.nf-inst-x').addEventListener('click', function () {
          dismiss(banner);
        });
      }, 2500);
    });
  }
})();
