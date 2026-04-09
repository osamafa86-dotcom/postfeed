<?php
/**
 * نيوزفلو - اسأل الأخبار (AI Q&A page)
 * =====================================
 * Lets a reader type a natural-language question and get a grounded
 * answer that only cites articles from our own archive. All the
 * heavy lifting (retrieval, Claude call, caching, whitelisting of
 * hallucinated article IDs) happens in includes/ai_qa.php.
 *
 * URL: /ask  (rewritten by .htaccess — see the trailing block there).
 *
 * This page ships with a small suggested-question bar so first-time
 * visitors know what kinds of questions actually work against a news
 * archive (as opposed to e.g. "what's the weather in Amman").
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';

$viewer    = current_user();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$pageTheme = current_theme();

$pageUrl  = SITE_URL . '/ask';
$metaDesc = 'اسأل نيوزفلو — مساعد ذكي يجيب على أسئلتك عن آخر الأخبار بالاستشهاد من أرشيف الموقع الرسمي.';

// Pre-seeded suggestions shown above the input. Clicking one fires a
// full question — they're intentionally broad so they work on any day.
$suggestions = [
    'ما أهم أخبار غزة اليوم؟',
    'ما آخر تطورات الضفة الغربية؟',
    'ماذا حصل في الاقتصاد هذا الأسبوع؟',
    'ما أبرز الأخبار الرياضية مؤخراً؟',
    'من هم الضيوف في آخر الأخبار السياسية؟',
];

?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title>🤖 اسأل الأخبار — <?php echo e(getSetting('site_name', SITE_NAME)); ?></title>
<meta name="description" content="<?php echo e($metaDesc); ?>">
<link rel="canonical" href="<?php echo e($pageUrl); ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="🤖 اسأل الأخبار — <?php echo e(getSetting('site_name', SITE_NAME)); ?>">
<meta property="og:description" content="<?php echo e($metaDesc); ?>">
<meta property="og:url" content="<?php echo e($pageUrl); ?>">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#1a5c5c">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap"></noscript>
<link rel="stylesheet" href="assets/css/site-header.css?v=1">
<link rel="stylesheet" href="assets/css/home.css?v=21">
<link rel="stylesheet" href="assets/css/user.css?v=17">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<style>
  /* --- Ask page (scoped) -------------------------------------- */
  .ask-page {
    max-width: 920px;
    margin: 24px auto 60px;
    padding: 0 18px;
  }
  .ask-hero {
    background: linear-gradient(135deg, #0f766e 0%, #1a5c5c 100%);
    color: #fff;
    border-radius: 22px;
    padding: 32px 28px;
    text-align: center;
    box-shadow: 0 18px 50px -24px rgba(15,118,110,.55);
  }
  .ask-hero h1 {
    margin: 0 0 6px;
    font-size: 30px;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
  }
  .ask-hero p {
    margin: 0 auto;
    max-width: 620px;
    color: rgba(255,255,255,.86);
    font-size: 14px;
    line-height: 1.7;
  }
  .ask-beta {
    display: inline-block;
    background: rgba(255,255,255,.18);
    color: #fff;
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .04em;
    margin-bottom: 10px;
  }
  .ask-form {
    display: flex;
    gap: 10px;
    margin-top: 22px;
    background: #fff;
    border-radius: 16px;
    padding: 8px;
    box-shadow: 0 10px 32px -14px rgba(0,0,0,.3);
  }
  .ask-form input[type=text] {
    flex: 1;
    border: none;
    outline: none;
    background: transparent;
    font-family: inherit;
    font-size: 16px;
    font-weight: 500;
    color: #0f172a;
    padding: 12px 16px;
    text-align: right;
    direction: rtl;
  }
  .ask-form button {
    background: linear-gradient(135deg,#f97316,#dc2626);
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 12px 22px;
    font-family: inherit;
    font-size: 15px;
    font-weight: 800;
    cursor: pointer;
    transition: transform .15s, box-shadow .2s;
  }
  .ask-form button:hover { transform: translateY(-1px); box-shadow: 0 10px 24px -10px rgba(220,38,38,.6); }
  .ask-form button:disabled { opacity: .6; cursor: not-allowed; transform: none; }

  .ask-suggest {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
    margin-top: 18px;
  }
  .ask-suggest-chip {
    background: rgba(255,255,255,.14);
    color: #fff;
    border: 1px solid rgba(255,255,255,.22);
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: background .2s, transform .15s;
  }
  .ask-suggest-chip:hover { background: rgba(255,255,255,.26); transform: translateY(-1px); }

  .ask-result {
    margin-top: 28px;
    background: var(--card, #fff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 20px;
    padding: 26px 28px;
    box-shadow: 0 8px 24px -18px rgba(0,0,0,.25);
    display: none;
  }
  .ask-result.show { display: block; }
  .ask-result-head {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #0f766e;
    font-weight: 800;
    font-size: 13px;
    margin-bottom: 14px;
    letter-spacing: .01em;
  }
  .ask-result-head::before {
    content: '';
    display: inline-block;
    width: 26px;
    height: 26px;
    background: linear-gradient(135deg,#0d9488,#0f766e);
    border-radius: 50%;
    flex-shrink: 0;
  }
  .ask-question {
    font-size: 18px;
    font-weight: 800;
    color: var(--dark, #0f172a);
    margin: 0 0 18px;
    line-height: 1.5;
  }
  .ask-answer {
    font-size: 16px;
    line-height: 1.95;
    color: var(--dark, #1e293b);
    white-space: pre-wrap;
    word-wrap: break-word;
  }
  .ask-answer .cite {
    display: inline-block;
    background: rgba(13,148,136,.12);
    color: #0f766e;
    border-radius: 6px;
    padding: 0 6px;
    margin: 0 2px;
    font-size: 12px;
    font-weight: 800;
    text-decoration: none;
    vertical-align: middle;
    transition: background .15s;
  }
  .ask-answer .cite:hover { background: #0f766e; color: #fff; }

  .ask-sources {
    margin-top: 26px;
    padding-top: 20px;
    border-top: 1px dashed var(--border, #e2e8f0);
  }
  .ask-sources h3 {
    font-size: 14px;
    color: var(--text-muted, #64748b);
    margin: 0 0 14px;
    font-weight: 800;
    letter-spacing: .02em;
  }
  .ask-sources-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
  }
  .ask-source-card {
    display: block;
    background: var(--bg, #f8fafc);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 12px;
    padding: 12px;
    text-decoration: none;
    color: inherit;
    transition: transform .15s, box-shadow .2s, border-color .2s;
  }
  .ask-source-card:hover {
    transform: translateY(-2px);
    border-color: #0f766e;
    box-shadow: 0 10px 22px -14px rgba(15,118,110,.4);
  }
  .ask-source-thumb {
    aspect-ratio: 16/9;
    background-size: cover;
    background-position: center;
    background-color: #e2e8f0;
    border-radius: 8px;
    margin-bottom: 10px;
  }
  .ask-source-id {
    display: inline-block;
    background: rgba(13,148,136,.12);
    color: #0f766e;
    border-radius: 6px;
    padding: 1px 7px;
    font-size: 11px;
    font-weight: 800;
    margin-bottom: 6px;
  }
  .ask-source-title {
    font-size: 13.5px;
    font-weight: 800;
    line-height: 1.5;
    color: var(--dark, #0f172a);
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .ask-source-meta {
    margin-top: 8px;
    font-size: 11px;
    color: var(--text-muted, #64748b);
    display: flex;
    gap: 6px;
    align-items: center;
  }

  .ask-followups {
    margin-top: 22px;
    padding-top: 18px;
    border-top: 1px dashed var(--border, #e2e8f0);
  }
  .ask-followups h3 {
    font-size: 13px;
    color: var(--text-muted, #64748b);
    margin: 0 0 10px;
    font-weight: 800;
  }
  .ask-followups-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .ask-followup-chip {
    background: var(--bg, #f1f5f9);
    color: var(--dark, #0f172a);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 10px;
    padding: 8px 14px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-align: right;
    transition: border-color .2s, background .2s;
  }
  .ask-followup-chip:hover { border-color: #0f766e; background: rgba(13,148,136,.08); }

  .ask-loading {
    margin-top: 28px;
    text-align: center;
    padding: 40px 20px;
    background: var(--card, #fff);
    border: 1px dashed var(--border, #e2e8f0);
    border-radius: 20px;
    display: none;
  }
  .ask-loading.show { display: block; }
  .ask-loading-dots {
    display: inline-flex;
    gap: 6px;
    margin-bottom: 12px;
  }
  .ask-loading-dots span {
    width: 10px; height: 10px;
    background: #0f766e;
    border-radius: 50%;
    animation: askDot 1.2s infinite ease-in-out;
  }
  .ask-loading-dots span:nth-child(2) { animation-delay: .15s; }
  .ask-loading-dots span:nth-child(3) { animation-delay: .3s; }
  @keyframes askDot {
    0%, 80%, 100% { transform: scale(.6); opacity: .4; }
    40%           { transform: scale(1);  opacity: 1;  }
  }

  .ask-error {
    margin-top: 28px;
    padding: 18px 22px;
    border-radius: 14px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    font-weight: 600;
    display: none;
  }
  .ask-error.show { display: block; }

  .ask-disclaimer {
    margin-top: 18px;
    text-align: center;
    font-size: 12px;
    color: var(--text-muted, #64748b);
    line-height: 1.7;
  }

  [data-theme="dark"] .ask-form { background: #1e293b; }
  [data-theme="dark"] .ask-form input[type=text] { color: #f1f5f9; }
  [data-theme="dark"] .ask-loading { background: #1e293b; }
  [data-theme="dark"] .ask-error { background: #3f1111; border-color: #7f1d1d; color: #fecaca; }

  @media (max-width: 640px) {
    .ask-hero { padding: 26px 18px; }
    .ask-hero h1 { font-size: 23px; }
    .ask-form { flex-direction: column; padding: 10px; }
    .ask-form button { width: 100%; }
    .ask-result { padding: 20px 18px; }
    .ask-question { font-size: 16px; }
    .ask-answer { font-size: 15px; }
  }
</style>
</head>
<body>

<?php
$activeType = 'ask';
$activeSlug = '';
$showTicker = false;
$userUnread = $viewerId ? user_unread_notifications_count($viewerId) : 0;
include __DIR__ . '/includes/components/site_header.php';
?>

<main class="ask-page">
  <section class="ask-hero">
    <span class="ask-beta">🧪 تجريبي</span>
    <h1>🤖 اسأل الأخبار</h1>
    <p>
      اكتب سؤالك بالعربية وسيجيبك المساعد بناءً على آخر المقالات المنشورة على نيوزفلو فقط —
      بدون اختراع معلومات، ومع الاستشهاد بالمصادر.
    </p>

    <form class="ask-form" id="askForm" autocomplete="off">
      <input type="text" id="askInput" name="q" placeholder="اسأل أي شيء… مثال: ما آخر تطورات غزة؟" maxlength="500" required>
      <button type="submit" id="askSubmit">إرسال ›</button>
    </form>

    <div class="ask-suggest">
      <?php foreach ($suggestions as $s): ?>
        <button type="button" class="ask-suggest-chip" data-suggest="<?php echo e($s); ?>"><?php echo e($s); ?></button>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="ask-loading" id="askLoading">
    <div class="ask-loading-dots"><span></span><span></span><span></span></div>
    <div style="font-size:14px;color:var(--text-muted,#64748b);">يبحث المساعد في أرشيف نيوزفلو…</div>
  </div>

  <div class="ask-error" id="askError"></div>

  <article class="ask-result" id="askResult">
    <div class="ask-result-head">إجابة نيوزفلو</div>
    <h2 class="ask-question" id="askQuestionOut"></h2>
    <div class="ask-answer" id="askAnswerOut"></div>

    <div class="ask-sources">
      <h3>📰 مقالات استشهد بها المساعد</h3>
      <div class="ask-sources-grid" id="askSourcesGrid"></div>
    </div>

    <div class="ask-followups" id="askFollowupsWrap" style="display:none;">
      <h3>💡 أسئلة متابعة مقترحة</h3>
      <div class="ask-followups-list" id="askFollowupsList"></div>
    </div>
  </article>

  <p class="ask-disclaimer">
    الإجابات مُولَّدة بواسطة نموذج ذكاء اصطناعي اعتماداً على مقالات الموقع. قد تحتوي على أخطاء —
    تحقّق دائماً من المقالات المصدرية قبل النقل.
  </p>
</main>

<footer>
  <div class="footer-logo"><?php echo e(getSetting('site_name', SITE_NAME)); ?></div>
  <div class="footer-links">
    <a href="index.php">الرئيسية</a>
    <a href="trending.php">الأكثر تداولاً</a>
    <a href="ask.php">اسأل الأخبار</a>
  </div>
  <div class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo e(getSetting('site_name', SITE_NAME)); ?> &mdash; جميع الحقوق محفوظة</div>
</footer>

<div class="nf-toast" id="nfToast"></div>
<script src="assets/js/user.js?v=4" defer></script>
<script>
(function(){
  const form        = document.getElementById('askForm');
  const input       = document.getElementById('askInput');
  const submitBtn   = document.getElementById('askSubmit');
  const loadingBox  = document.getElementById('askLoading');
  const errorBox    = document.getElementById('askError');
  const resultBox   = document.getElementById('askResult');
  const qOut        = document.getElementById('askQuestionOut');
  const aOut        = document.getElementById('askAnswerOut');
  const srcGrid     = document.getElementById('askSourcesGrid');
  const fuWrap      = document.getElementById('askFollowupsWrap');
  const fuList      = document.getElementById('askFollowupsList');

  // Escape untrusted text before we put it in innerHTML.
  const esc = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  // Rewrite "[#123]" citations into clickable chips linking to that
  // article card in the sources grid.
  const renderAnswerWithCites = (text, idToUrl) => {
    const escaped = esc(text);
    return escaped.replace(/\[#(\d+)\]/g, (m, id) => {
      const url = idToUrl[id];
      if (!url) return m;
      return '<a class="cite" href="' + esc(url) + '" target="_blank" rel="noopener">#' + id + '</a>';
    });
  };

  const showLoading = (on) => { loadingBox.classList.toggle('show', on); if (on) { resultBox.classList.remove('show'); errorBox.classList.remove('show'); } };
  const showError   = (msg) => { errorBox.textContent = msg; errorBox.classList.add('show'); resultBox.classList.remove('show'); loadingBox.classList.remove('show'); };

  const ask = async (q) => {
    if (!q || q.trim().length < 4) { showError('اكتب سؤالاً أوضح (٤ أحرف على الأقل).'); return; }
    submitBtn.disabled = true;
    showLoading(true);
    try {
      const r = await fetch('/api/ask.php?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
      const data = await r.json();
      if (!data || !data.ok) {
        showError(data && data.error ? data.error : 'حدث خطأ أثناء معالجة السؤال.');
        return;
      }

      // Build id->url map so inline citations become clickable.
      const idToUrl = {};
      (data.articles || []).forEach(a => { idToUrl[String(a.id)] = a.url; });

      qOut.textContent = data.question || q;
      aOut.innerHTML   = renderAnswerWithCites(data.answer || '', idToUrl);

      // Source cards
      srcGrid.innerHTML = '';
      (data.articles || []).forEach(a => {
        const card = document.createElement('a');
        card.className = 'ask-source-card';
        card.href = a.url;
        card.target = '_blank';
        card.rel = 'noopener';
        card.innerHTML =
          '<div class="ask-source-thumb" style="background-image:url(\'' + esc(a.image_url || '') + '\')"></div>'
        + '<span class="ask-source-id">#' + a.id + '</span>'
        + '<div class="ask-source-title">' + esc(a.title) + '</div>'
        + '<div class="ask-source-meta">'
        + (a.source ? '<span>' + esc(a.source) + '</span><span>·</span>' : '')
        + (a.category ? '<span>' + esc(a.category) + '</span>' : '')
        + '</div>';
        srcGrid.appendChild(card);
      });

      // Follow-up suggestions
      fuList.innerHTML = '';
      if (data.follow_ups && data.follow_ups.length) {
        data.follow_ups.forEach(fu => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'ask-followup-chip';
          btn.textContent = fu;
          btn.addEventListener('click', () => {
            input.value = fu;
            ask(fu);
            window.scrollTo({ top: 0, behavior: 'smooth' });
          });
          fuList.appendChild(btn);
        });
        fuWrap.style.display = '';
      } else {
        fuWrap.style.display = 'none';
      }

      resultBox.classList.add('show');
      errorBox.classList.remove('show');
      // Scroll the answer into view so the user sees it even on long pages.
      setTimeout(() => resultBox.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
    } catch (err) {
      showError('تعذّر الاتصال بالخادم. تحقّق من الإنترنت ثم حاول مجدداً.');
    } finally {
      submitBtn.disabled = false;
      showLoading(false);
    }
  };

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    ask(input.value);
  });

  // Suggested-question chips → pre-fill + auto-ask.
  document.querySelectorAll('.ask-suggest-chip').forEach(btn => {
    btn.addEventListener('click', () => {
      const q = btn.dataset.suggest || btn.textContent.trim();
      input.value = q;
      ask(q);
    });
  });

  // Deep-linkable ?q=... so you can share a specific question.
  const url = new URL(window.location.href);
  const prefill = url.searchParams.get('q');
  if (prefill) {
    input.value = prefill;
    ask(prefill);
  }
})();
</script>
</body>
</html>
