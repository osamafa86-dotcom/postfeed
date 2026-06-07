<?php
/**
 * Social Platforms — Newspaper Editorial render.
 *
 * Replaces the old tabbed dark-gradient "_PlatformsBox" on the home
 * page (index.php). Renders the same Telegram / X / YouTube data in
 * the print-newspaper layout from the Figma spec:
 *
 *   ┌─────────────────────────────────────────────┐
 *   │ MASTHEAD STRIPE (date + LIVE pulse)         │
 *   ├─────────────────────────────────────────────┤
 *   │           منصات السوشال                       │
 *   │     منشورات حصرية من ٤٧ مصدراً                 │
 *   │ ═════════════════════════════════════════   │
 *   │ تلغرام · ٨٢   منصة X · ٤١   يوتيوب · ٢٤      │
 *   │ ─────────────────────────────────────────   │
 *   │ 🔥 الخبر الرائج                              │
 *   │ <BIG headline of active platform's top post> │
 *   │ بقلم/ القناة · قبل ٤ د · ١٢.٤ك               │
 *   │ A <big drop cap> + lead paragraph            │
 *   │                                              │
 *   │ في الصفحات الداخلية ─────────────  ص.٢       │
 *   │ KICKER     │  KICKER                         │
 *   │ secondary  │  secondary                      │
 *   │            │                                 │
 *   │ ✦ ملاحظة المحرر — الإيجاز الذكي               │
 *   │   (latest tg_summary if present)             │
 *   │                                              │
 *   │ INK FOOTER — الأرشيف الكامل · افتح الملحق    │
 *   └─────────────────────────────────────────────┘
 *
 * Variables consumed (set by index.php right before include):
 *   $tgMsgs, $twMsgs, $ytMsgs (arrays of posts, oldest→newest)
 *
 * The three platform tabs are GET-driven (?social=telegram|twitter|youtube)
 * so the lead headline + drop cap belong to whichever feed the reader
 * picked. No JavaScript is required for the basic switch — the page
 * just re-renders. The live-update polling stays untouched on the
 * dedicated /platforms page where it actually matters.
 */

if (!function_exists('e')) return;
if (empty($tgMsgs) && empty($twMsgs) && empty($ytMsgs)) return;

// Active platform (from query string, defaulting to whichever feed has data)
$__platforms = [
    'telegram' => ['label' => 'تلغرام',  'count' => count($tgMsgs ?? []), 'msgs' => $tgMsgs ?? [],
                   'msgKey' => 'text',  'urlKey' => 'post_url', 'imgKey' => 'image_url',
                   'icon'  => '📨',
                   'svg'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 7.24l-1.66 7.81c-.12.56-.45.7-.91.44L11.55 15.4l-1.79 1.73c-.2.2-.37.37-.76.37l.27-3.84 6.97-6.3c.3-.27-.07-.42-.47-.16L7.14 12.43l-3.71-1.16c-.8-.25-.82-.8.17-1.19l14.49-5.59c.67-.25 1.26.16 1.04 1.19z"/></svg>',
                   'archive' => 'telegram.php'],
    'twitter'  => ['label' => 'منصة X',  'count' => count($twMsgs ?? []), 'msgs' => $twMsgs ?? [],
                   'msgKey' => 'text',  'urlKey' => 'post_url', 'imgKey' => 'image_url',
                   'icon'  => '𝕏',
                   'svg'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.451-6.231zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/></svg>',
                   'archive' => 'twitter_feed.php'],
    'youtube'  => ['label' => 'يوتيوب', 'count' => count($ytMsgs ?? []), 'msgs' => $ytMsgs ?? [],
                   'msgKey' => 'title', 'urlKey' => 'post_url', 'imgKey' => 'thumbnail_url',
                   'icon'  => '▶',
                   'svg'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
                   'archive' => 'youtube_feed.php'],
];

$__activeSocial = $_GET['social'] ?? null;
if (!isset($__platforms[$__activeSocial]) || empty($__platforms[$__activeSocial]['msgs'])) {
    // Fall back to the freshest non-empty platform.
    $__activeSocial = !empty($tgMsgs) ? 'telegram'
                    : (!empty($twMsgs) ? 'twitter'
                    : (!empty($ytMsgs) ? 'youtube' : null));
}
if ($__activeSocial === null) return;

$__active = $__platforms[$__activeSocial];
$__totalCount = ($__platforms['telegram']['count'] ?? 0)
              + ($__platforms['twitter']['count']  ?? 0)
              + ($__platforms['youtube']['count']  ?? 0);

// Pretty Arabic-Indic numerals for the newspaper feel.
$__toArabicNum = function ($n) {
    return strtr((string)$n,
        ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
};

// Lead post = the active feed's most recent item; secondaries are 2 + 3.
$__lead = $__active['msgs'][0] ?? null;
$__sec1 = $__active['msgs'][1] ?? null;
$__sec2 = $__active['msgs'][2] ?? null;

// Editor's brief (pulled from telegram_summaries, generated by cron_tg_summary.php).
$__editorBrief = null;
if (function_exists('tg_summary_get_latest')) {
    try { $__editorBrief = tg_summary_get_latest(); } catch (Throwable $e) {}
}

// Active-tab URL helper that preserves the rest of the query string.
$__tabUrl = function ($p) {
    $q = $_GET; $q['social'] = $p;
    return strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?' . http_build_query($q) . '#social-rail';
};

// Text helpers.
// Telegram channels often pre-encode quotes/ampersands (&quot; &amp;) and
// prefix posts with emoji/bullets. Decode the entities (so e() doesn't
// double-escape them) then strip leading non-letter chars so the drop
// cap lands on an actual Arabic/Latin letter — not a 🔵 rendered at
// 54px.
$__decode = fn($s) => html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$__stripLead = fn($s) => preg_replace('/^[^\p{L}\p{N}]+/u', '', $s);

$__leadTitle = $__lead ? trim($__decode($__lead[$__active['msgKey']] ?? '')) : '';
$__leadBody  = $__leadTitle !== '' ? $__stripLead($__leadTitle) : '';
$__leadFirstChar = $__leadBody !== '' ? mb_substr($__leadBody, 0, 1) : 'أ';
$__leadRest      = $__leadBody !== '' ? mb_substr($__leadBody, 1) : '';
// Excerpt for the lead paragraph
$__leadExcerpt = $__leadRest !== '' ? mb_substr($__leadRest, 0, 220) : '';
if (mb_strlen($__leadRest) > 220) $__leadExcerpt .= '…';

$__leadChannel = $__lead['display_name'] ?? ($__lead['username'] ?? '');
$__leadHandle  = $__lead['username'] ?? ($__lead['handle'] ?? '');
$__leadDate    = $__lead['posted_at'] ?? null;

// Today's date in Arabic for the masthead stripe.
$__monthsAr = [1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',5=>'مايو',6=>'يونيو',7=>'يوليو',8=>'أغسطس',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
$__daysAr   = ['Saturday'=>'السبت','Sunday'=>'الأحد','Monday'=>'الإثنين','Tuesday'=>'الثلاثاء','Wednesday'=>'الأربعاء','Thursday'=>'الخميس','Friday'=>'الجمعة'];
$__today    = $__daysAr[date('l')] . ' ' . $__toArabicNum(date('j')) . ' ' . $__monthsAr[(int)date('n')] . ' ' . $__toArabicNum(date('Y'));
?>
<!-- ═════ SOCIAL NEWSPAPER — مَلْحَق منصات السوشال ═════ -->
<div id="social-rail" class="snp" data-active="<?php echo e($__activeSocial); ?>">
  <!-- Top stripe -->
  <div class="snp-stripe">
    <span class="snp-stripe-date">العدد ٣ — <?php echo e($__today); ?></span>
    <span class="snp-stripe-live">
      <span class="snp-stripe-dot" aria-hidden="true"></span>
      نشرة لحظية
    </span>
  </div>

  <!-- Masthead -->
  <div class="snp-mast">
    <div class="snp-mast-pre">صحيفة نيوز فيد · ملحق</div>
    <h2 class="snp-mast-title">منصات السوشال</h2>
    <div class="snp-mast-sub">منشورات حصرية من <b><?php echo e($__toArabicNum($__totalCount)); ?></b> منشوراً اليوم — تلغرام، منصة X، يوتيوب</div>
  </div>

  <!-- Double rule -->
  <div class="snp-rule">
    <div class="snp-rule-thick"></div>
    <div class="snp-rule-thin"></div>
  </div>

  <!-- Platform kickers (act as tabs) -->
  <nav class="snp-kickers" role="tablist" aria-label="منصات">
    <?php foreach ($__platforms as $key => $p): if (empty($p['msgs'])) continue; ?>
      <a class="snp-kicker<?php echo $key === $__activeSocial ? ' is-active' : ''; ?>"
         href="<?php echo e($__tabUrl($key)); ?>"
         role="tab"
         aria-selected="<?php echo $key === $__activeSocial ? 'true' : 'false'; ?>">
        <span class="snp-kicker-svg" aria-hidden="true"><?php echo $p['svg']; /* trusted inline SVG */ ?></span>
        <span class="snp-kicker-label"><?php echo e($p['label']); ?></span>
        <span class="snp-kicker-sep">·</span>
        <span class="snp-kicker-count"><?php echo e($__toArabicNum($p['count'])); ?> منشوراً</span>
      </a>
    <?php endforeach; ?>
  </nav>

  <!-- Thin rule under kickers -->
  <div class="snp-rule snp-rule-mid"><div class="snp-rule-thin"></div></div>

  <!-- LEAD STORY -->
  <?php if ($__lead): ?>
    <article class="snp-lead">
      <div class="snp-lead-kicker">🔥 الخبر الرائج</div>
      <h3 class="snp-lead-title"><?php echo e($__leadBody !== '' ? $__leadBody : $__leadTitle); ?></h3>
      <div class="snp-byline">
        <?php if ($__leadChannel): ?>
          <span>بقلم/<b><?php echo e($__leadChannel); ?></b></span>
          <span class="snp-byline-sep">·</span>
        <?php endif; ?>
        <?php if ($__leadDate): ?>
          <span><?php echo timeAgo($__leadDate); ?></span>
        <?php endif; ?>
        <?php if ($__leadHandle): ?>
          <span class="snp-byline-sep">·</span>
          <span>@<?php echo e($__leadHandle); ?></span>
        <?php endif; ?>
      </div>
      <?php if ($__leadExcerpt !== ''): ?>
        <div class="snp-lead-para">
          <span class="snp-dropcap" aria-hidden="true"><?php echo e($__leadFirstChar); ?></span>
          <p><?php echo e($__leadFirstChar . $__leadExcerpt); ?></p>
        </div>
      <?php endif; ?>
      <a class="snp-lead-more" href="<?php echo e($__lead[$__active['urlKey']] ?? '#'); ?>" target="_blank" rel="noopener">
        تابع القراءة على <?php echo e($__active['label']); ?>
        <span class="snp-arrow">←</span>
      </a>
    </article>
  <?php endif; ?>

  <!-- Mid rule -->
  <div class="snp-rule snp-rule-mid"><div class="snp-rule-thin"></div></div>

  <!-- Section header (newspaper "ص.٢" feel) -->
  <div class="snp-sec-head">
    <span class="snp-sec-name">في الصفحات الداخلية</span>
    <span class="snp-sec-page">ص. ٢</span>
  </div>

  <!-- Two-column secondary -->
  <?php if ($__sec1 || $__sec2): ?>
    <div class="snp-cols">
      <?php foreach ([$__sec1, $__sec2] as $__i => $__sec): if (!$__sec) continue;
        $__secBody = trim($__stripLead($__decode($__sec[$__active['msgKey']] ?? '')));
      ?>
        <a class="snp-col" href="<?php echo e($__sec[$__active['urlKey']] ?? '#'); ?>" target="_blank" rel="noopener">
          <div class="snp-col-kicker"><?php echo $__i === 0 ? 'متابعة' : 'تحديث'; ?></div>
          <div class="snp-col-title">
            <?php echo e(mb_substr($__secBody, 0, 110)); ?>
          </div>
          <div class="snp-col-meta">
            <?php if (!empty($__sec['display_name'])): ?>
              <b><?php echo e($__sec['display_name']); ?></b>
              <span>·</span>
            <?php endif; ?>
            <?php if (!empty($__sec['posted_at'])): ?>
              <span><?php echo timeAgo($__sec['posted_at']); ?></span>
            <?php endif; ?>
          </div>
        </a>
        <?php if ($__i === 0 && $__sec2): ?>
          <div class="snp-col-rule" aria-hidden="true"></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Editor's brief (Smart Summary) -->
  <?php if ($__editorBrief && !empty($__editorBrief['summary'])): ?>
    <a class="snp-brief" href="telegram_summary.php?id=<?php echo (int)$__editorBrief['id']; ?>">
      <div class="snp-brief-head">
        <span class="snp-brief-star" aria-hidden="true">✦</span>
        <span class="snp-brief-label">ملاحظة المحرّر — الإيجاز الذكي</span>
      </div>
      <?php $__briefBody = $__decode($__editorBrief['summary']); ?>
      <p class="snp-brief-body">
        <?php echo e(mb_substr($__briefBody, 0, 240)); ?><?php echo mb_strlen($__briefBody) > 240 ? '…' : ''; ?>
      </p>
    </a>
  <?php endif; ?>

  <!-- Ink footer -->
  <div class="snp-foot">
    <div class="snp-foot-l">
      <div class="snp-foot-h">الأرشيف الكامل</div>
      <div class="snp-foot-s"><?php echo e($__toArabicNum($__totalCount)); ?> منشوراً اليوم</div>
    </div>
    <a class="snp-foot-cta" href="platforms.php">
      افتح الملحق
      <span class="snp-arrow">←</span>
    </a>
  </div>
</div>

<style>
/* ─────────── SOCIAL NEWSPAPER (snp-) ─────────── */
.snp {
  background: #F8F4EC;
  border: 1.5px solid #BAA582;
  border-radius: 4px;
  margin: 0 0 32px;
  box-shadow: 0 10px 24px -8px rgba(20, 18, 14, 0.12);
  font-family: 'Tajawal', 'Segoe UI', Tahoma, Arial, sans-serif;
  color: #14120E;
  overflow: hidden;
  position: relative;
}
/* Top stripe — black band with date + LIVE pulse */
.snp-stripe {
  background: #14120E;
  color: #FAF0E5;
  padding: 7px 22px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.5px;
}
.snp-stripe-date { color: rgba(255, 250, 240, 0.85); }
.snp-stripe-live { display: inline-flex; align-items: center; gap: 6px; color: #FFB4B4; font-weight: 800; letter-spacing: 0.5px; }
.snp-stripe-dot {
  width: 6px; height: 6px; border-radius: 50%; background: #FF6470;
  box-shadow: 0 0 0 0 rgba(255, 100, 110, 0.5);
  animation: snpPulse 2s infinite;
}
@keyframes snpPulse {
  0%   { box-shadow: 0 0 0 0 rgba(255, 100, 110, 0.7); }
  70%  { box-shadow: 0 0 0 8px rgba(255, 100, 110, 0); }
  100% { box-shadow: 0 0 0 0 rgba(255, 100, 110, 0); }
}

/* Masthead */
.snp-mast {
  text-align: center;
  padding: 24px 28px 14px;
}
.snp-mast-pre {
  color: #877A64;
  font-weight: 500;
  font-size: 12px;
  letter-spacing: 1.2px;
  margin-bottom: 4px;
}
.snp-mast-title {
  font-size: 36px;
  font-weight: 900;
  line-height: 1.15;
  letter-spacing: -0.5px;
  color: #14120E;
  margin: 0 0 6px;
}
.snp-mast-sub {
  color: #504637;
  font-size: 13px;
  font-weight: 500;
}
.snp-mast-sub b { color: #14120E; font-weight: 800; }

/* Double rule */
.snp-rule {
  padding: 0 28px;
  margin: 4px 0;
}
.snp-rule-thick {
  height: 3px;
  background: #14120E;
  margin-bottom: 2px;
}
.snp-rule-thin {
  height: 1px;
  background: #14120E;
}
.snp-rule-mid {
  margin: 4px 0;
}
.snp-rule-mid .snp-rule-thin {
  background: #877A64;
  opacity: 0.5;
}

/* Platform kickers */
.snp-kickers {
  display: flex;
  flex-wrap: wrap;
  gap: 18px;
  justify-content: center;
  padding: 12px 28px;
}
.snp-kicker {
  display: inline-flex;
  align-items: baseline;
  gap: 6px;
  text-decoration: none;
  color: #14120E;
  font-weight: 700;
  font-size: 12px;
  padding-bottom: 4px;
  border-bottom: 2px solid transparent;
  transition: color 0.15s ease, border-color 0.15s ease;
}
.snp-kicker-svg {
  width: 13px; height: 13px;
  color: #877A64;
  display: inline-flex; align-self: center;
}
.snp-kicker-svg svg { width: 100%; height: 100%; }
.snp-kicker-label { font-weight: 800; }
.snp-kicker-sep { color: #877A64; padding: 0 1px; }
.snp-kicker-count { color: #877A64; font-weight: 700; }
.snp-kicker:hover { color: #B4192E; }
.snp-kicker:hover .snp-kicker-svg { color: #B4192E; }
.snp-kicker.is-active {
  color: #B4192E;
  border-bottom-color: #B4192E;
  font-size: 13px;
}
.snp-kicker.is-active .snp-kicker-label { font-weight: 900; }
.snp-kicker.is-active .snp-kicker-svg { color: #B4192E; }

/* Lead story */
.snp-lead {
  padding: 16px 28px 14px;
}
.snp-lead-kicker {
  color: #B4192E;
  font-weight: 900;
  font-size: 11px;
  letter-spacing: 1.6px;
  margin-bottom: 8px;
}
.snp-lead-title {
  font-size: 22px;
  font-weight: 900;
  line-height: 1.4;
  color: #14120E;
  margin: 0 0 10px;
}
.snp-byline {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 5px;
  font-size: 11.5px;
  color: #504637;
  font-weight: 600;
  margin-bottom: 14px;
}
.snp-byline b { color: #14120E; font-weight: 800; }
.snp-byline-sep { color: #877A64; }
.snp-lead-para {
  position: relative;
  padding-right: 0;
}
.snp-dropcap {
  float: right;
  font-size: 54px;
  font-weight: 900;
  color: #14120E;
  line-height: 0.95;
  margin: 4px 0 0 8px;
  padding: 0;
}
.snp-lead-para p {
  font-size: 13.5px;
  line-height: 1.85;
  color: #504637;
  margin: 0;
  text-align: justify;
}
.snp-lead-para p::first-letter { visibility: hidden; margin-right: -1ch; }
.snp-lead-more {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-top: 10px;
  color: #B4192E;
  font-weight: 900;
  font-size: 11.5px;
  text-decoration: none;
  letter-spacing: 0.4px;
}
.snp-lead-more:hover { text-decoration: underline; }
.snp-arrow { display: inline-block; }

/* Section header */
.snp-sec-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  padding: 4px 28px 8px;
}
.snp-sec-name {
  color: #14120E;
  font-weight: 900;
  font-size: 12px;
  letter-spacing: 0.5px;
}
.snp-sec-page {
  color: #877A64;
  font-weight: 700;
  font-size: 11px;
  letter-spacing: 0.5px;
}

/* Two-column secondary */
.snp-cols {
  display: grid;
  grid-template-columns: 1fr 1px 1fr;
  gap: 14px;
  padding: 0 28px 16px;
}
.snp-col {
  display: flex;
  flex-direction: column;
  gap: 6px;
  text-decoration: none;
  color: inherit;
}
.snp-col:hover .snp-col-title { color: #B4192E; }
.snp-col-kicker {
  color: #B4192E;
  font-weight: 900;
  font-size: 9.5px;
  letter-spacing: 1.4px;
}
.snp-col-title {
  font-size: 14px;
  font-weight: 900;
  line-height: 1.45;
  color: #14120E;
  transition: color 0.15s ease;
}
.snp-col-meta {
  display: inline-flex;
  gap: 5px;
  font-size: 10.5px;
  color: #877A64;
  font-weight: 600;
}
.snp-col-meta b { color: #504637; font-weight: 800; }
.snp-col-rule {
  background: #877A64;
  opacity: 0.4;
  width: 1px;
}

/* Editor's brief */
.snp-brief {
  display: block;
  margin: 0 28px 14px;
  padding: 12px 14px;
  background: #EEE8DB;
  border: 1px solid #BAA582;
  text-decoration: none;
  color: inherit;
  transition: background 0.15s ease;
}
.snp-brief:hover { background: #E8E0CC; }
.snp-brief-head {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}
.snp-brief-star { color: #A67C1B; font-weight: 900; font-size: 13px; }
.snp-brief-label {
  color: #6B4F0B;
  font-weight: 900;
  font-size: 11px;
  letter-spacing: 0.5px;
}
.snp-brief-body {
  font-size: 11.5px;
  line-height: 1.7;
  color: #504637;
  font-weight: 500;
  margin: 0;
}

/* Footer ink band */
.snp-foot {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #14120E;
  color: #FAF0E5;
  padding: 14px 28px;
}
.snp-foot-h {
  font-weight: 900;
  font-size: 12.5px;
  color: #FAF0E5;
}
.snp-foot-s {
  font-weight: 500;
  font-size: 10.5px;
  color: rgba(255, 250, 240, 0.6);
  margin-top: 1px;
}
.snp-foot-cta {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  background: #B4192E;
  color: #FFFFFF;
  font-weight: 900;
  font-size: 12px;
  text-decoration: none;
  transition: background 0.15s ease;
}
.snp-foot-cta:hover { background: #951424; }

/* Mobile */
@media (max-width: 640px) {
  .snp-mast-title { font-size: 28px; }
  .snp-mast { padding: 18px 18px 12px; }
  .snp-lead { padding: 12px 18px 12px; }
  .snp-lead-title { font-size: 18px; }
  .snp-dropcap { font-size: 44px; }
  .snp-rule, .snp-kickers, .snp-sec-head, .snp-cols, .snp-foot { padding-left: 18px; padding-right: 18px; }
  .snp-brief { margin-left: 18px; margin-right: 18px; }
  .snp-cols { grid-template-columns: 1fr; gap: 12px; }
  .snp-col-rule { display: none; }
  .snp-kickers { gap: 14px; }
  .snp-foot { flex-direction: column; align-items: stretch; gap: 10px; }
  .snp-foot-l { text-align: right; }
}
</style>
