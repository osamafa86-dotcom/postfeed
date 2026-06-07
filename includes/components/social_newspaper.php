<?php
/**
 * Social Platforms — Newspaper Editorial render (split layout).
 *
 * Replaces the old tabbed dark-gradient "_PlatformsBox" on the home
 * page with a 50/50 newspaper-feel split:
 *
 *   ┌───────────────────────────────────────────────────────┐
 *   │ MASTHEAD STRIPE (date + LIVE pulse)                   │
 *   ├───────────────────────────────────────────────────────┤
 *   │                  منصات السوشال                          │
 *   │           منشورات حصرية من ٤٧ مصدراً                    │
 *   │ ═══════════════════════════════════════════════════   │
 *   │ تلغرام · ٨٢   منصة X · ٤١   يوتيوب · ٢٤                │
 *   │ ───────────────────────────────────────────────────   │
 *   ├──────────────────────┬────────────────────────────────┤
 *   │  RIGHT (live feed)   │   LEFT (sidebar stats)         │
 *   │  Telegram/X/YT cards │   ▸ إجمالي الأخبار    ١٤٧      │
 *   │  in the active tab,  │   ▸ القضية الأبرز     <hd>     │
 *   │  same compact-card   │   ▸ المنصة الأكثر نشراً  تلغرام  │
 *   │  list as before.     │   ▸ ✨ موجز الساعة             │
 *   ├──────────────────────┴────────────────────────────────┤
 *   │ INK FOOTER — الأرشيف الكامل · افتح الملحق              │
 *   └───────────────────────────────────────────────────────┘
 *
 * Variables consumed (set by index.php right before include):
 *   $tgMsgs, $twMsgs, $ytMsgs (arrays of posts, newest → oldest)
 *
 * Active platform is GET-driven (?social=telegram|twitter|youtube)
 * — picking a kicker just re-renders the right-hand feed. The
 * live-update polling stays untouched on the dedicated /platforms
 * page where it actually matters.
 */

if (!function_exists('e')) return;
if (empty($tgMsgs) && empty($twMsgs) && empty($ytMsgs)) return;

// Platform metadata — labels, color accents, source keys, archive URLs.
$__platforms = [
    'telegram' => ['label' => 'تلغرام',  'count' => count($tgMsgs ?? []), 'msgs' => $tgMsgs ?? [],
                   'msgKey' => 'text',  'urlKey' => 'post_url', 'imgKey' => 'image_url',
                   'svg'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 7.24l-1.66 7.81c-.12.56-.45.7-.91.44L11.55 15.4l-1.79 1.73c-.2.2-.37.37-.76.37l.27-3.84 6.97-6.3c.3-.27-.07-.42-.47-.16L7.14 12.43l-3.71-1.16c-.8-.25-.82-.8.17-1.19l14.49-5.59c.67-.25 1.26.16 1.04 1.19z"/></svg>',
                   'badge' => '📨 تلغرام',
                   'color' => '#0EA5E9',
                   'archive' => 'telegram.php'],
    'twitter'  => ['label' => 'منصة X',  'count' => count($twMsgs ?? []), 'msgs' => $twMsgs ?? [],
                   'msgKey' => 'text',  'urlKey' => 'post_url', 'imgKey' => 'image_url',
                   'svg'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.451-6.231zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/></svg>',
                   'badge' => '𝕏 منصة X',
                   'color' => '#374151',
                   'archive' => 'twitter_feed.php'],
    'youtube'  => ['label' => 'يوتيوب', 'count' => count($ytMsgs ?? []), 'msgs' => $ytMsgs ?? [],
                   'msgKey' => 'title', 'urlKey' => 'post_url', 'imgKey' => 'thumbnail_url',
                   'svg'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
                   'badge' => '▶ يوتيوب',
                   'color' => '#DC2626',
                   'archive' => 'youtube_feed.php'],
];

$__activeSocial = $_GET['social'] ?? null;
if (!isset($__platforms[$__activeSocial]) || empty($__platforms[$__activeSocial]['msgs'])) {
    $__activeSocial = !empty($tgMsgs) ? 'telegram'
                    : (!empty($twMsgs) ? 'twitter'
                    : (!empty($ytMsgs) ? 'youtube' : null));
}
if ($__activeSocial === null) return;

$__active = $__platforms[$__activeSocial];
$__totalCount = ($__platforms['telegram']['count'] ?? 0)
              + ($__platforms['twitter']['count']  ?? 0)
              + ($__platforms['youtube']['count']  ?? 0);

// Arabic-Indic numerals — newspaper feel.
$__toArabicNum = function ($n) {
    return strtr((string)$n,
        ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
};

// Telegram channels often pre-encode entities and lead with bullet emoji,
// so decode once before e() and strip leading non-letter chars.
$__decode = fn($s) => html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$__stripLead = fn($s) => preg_replace('/^[^\p{L}\p{N}]+/u', '', $s);

// Editor's brief / hour summary (latest tg_summary).
$__editorBrief = null;
if (function_exists('tg_summary_get_latest')) {
    try { $__editorBrief = tg_summary_get_latest(); } catch (Throwable $e) {}
}

// Which platform leads the day's volume — drives the "الأكثر نشراً" stat.
$__topPlatform = null; $__topPlatformCount = 0;
foreach ($__platforms as $key => $p) {
    if (($p['count'] ?? 0) > $__topPlatformCount) {
        $__topPlatformCount = $p['count'];
        $__topPlatform      = $key;
    }
}

// Headline of the day — prefers AI-curated brief, falls back to active
// platform's freshest post.
$__topStory = '';
if ($__editorBrief && !empty($__editorBrief['headline'])) {
    $__topStory = $__decode($__editorBrief['headline']);
} elseif (!empty($__active['msgs'][0])) {
    $__topStory = $__stripLead($__decode($__active['msgs'][0][$__active['msgKey']] ?? ''));
}

// Active-tab URL helper.
$__tabUrl = function ($p) {
    $q = $_GET; $q['social'] = $p;
    return strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?' . http_build_query($q) . '#social-rail';
};

// Today in Arabic for the masthead stripe.
$__monthsAr = [1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',5=>'مايو',6=>'يونيو',7=>'يوليو',8=>'أغسطس',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
$__daysAr   = ['Saturday'=>'السبت','Sunday'=>'الأحد','Monday'=>'الإثنين','Tuesday'=>'الثلاثاء','Wednesday'=>'الأربعاء','Thursday'=>'الخميس','Friday'=>'الجمعة'];
$__today    = $__daysAr[date('l')] . ' ' . $__toArabicNum(date('j')) . ' ' . $__monthsAr[(int)date('n')] . ' ' . $__toArabicNum(date('Y'));

// How many feed cards to show in the right column. Six lines up nicely
// against the four stat cards on the left.
$__feedLimit = 6;
?>
<!-- ═════ SOCIAL NEWSPAPER — split layout ═════ -->
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

  <!-- Platform kickers (drive the right-column tab) -->
  <nav class="snp-kickers" role="tablist" aria-label="منصات">
    <?php foreach ($__platforms as $key => $p): if (empty($p['msgs'])) continue; ?>
      <a class="snp-kicker<?php echo $key === $__activeSocial ? ' is-active' : ''; ?>"
         href="<?php echo e($__tabUrl($key)); ?>"
         role="tab"
         aria-selected="<?php echo $key === $__activeSocial ? 'true' : 'false'; ?>">
        <span class="snp-kicker-svg" aria-hidden="true"><?php echo $p['svg']; ?></span>
        <span class="snp-kicker-label"><?php echo e($p['label']); ?></span>
        <span class="snp-kicker-sep">·</span>
        <span class="snp-kicker-count"><?php echo e($__toArabicNum($p['count'])); ?> منشوراً</span>
      </a>
    <?php endforeach; ?>
  </nav>

  <!-- Thin rule under kickers -->
  <div class="snp-rule snp-rule-mid"><div class="snp-rule-thin"></div></div>

  <!-- ═════ SPLIT: feed (right) + stats (left) ═════ -->
  <div class="snp-split">

    <!-- RIGHT COLUMN: Live feed cards (compact, like before) -->
    <section class="snp-feed-col">
      <div class="snp-col-head">
        <div class="snp-col-head-l">
          <span class="snp-col-head-dot" aria-hidden="true"></span>
          <span>أخبار <?php echo e($__active['label']); ?> الآن</span>
        </div>
        <a class="snp-col-head-more" href="<?php echo e($__active['archive']); ?>">عرض الكل ›</a>
      </div>

      <div class="snp-feed">
        <?php foreach (array_slice($__active['msgs'], 0, $__feedLimit) as $__m):
          $__txt = trim($__stripLead($__decode($__m[$__active['msgKey']] ?? '')));
          $__img = $__m[$__active['imgKey']] ?? '';
          $__channel = $__m['display_name'] ?? ($__m['username'] ?? '');
          $__handle  = $__m['username'] ?? ($__m['handle'] ?? '');
        ?>
          <a class="snp-feed-card"
             href="<?php echo e($__m[$__active['urlKey']] ?? '#'); ?>"
             target="_blank" rel="noopener">
            <?php if ($__img): ?>
              <div class="snp-feed-img">
                <img src="<?php echo e($__img); ?>" alt="" loading="lazy" decoding="async">
                <?php if ($__activeSocial === 'youtube'): ?>
                  <span class="snp-feed-play" aria-hidden="true">▶</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="snp-feed-body">
              <div class="snp-feed-meta">
                <span class="snp-feed-badge" style="background:<?php echo e($__active['color']); ?>"><?php echo e($__active['badge']); ?></span>
                <?php if ($__channel): ?><b><?php echo e($__channel); ?></b><?php endif; ?>
                <?php if ($__handle): ?><span class="snp-feed-dim">@<?php echo e($__handle); ?></span><?php endif; ?>
                <span class="snp-feed-dim">·</span>
                <span class="snp-feed-dim"><?php echo timeAgo($__m['posted_at']); ?></span>
              </div>
              <div class="snp-feed-text"><?php echo e(mb_substr($__txt, 0, 220)); ?><?php echo mb_strlen($__txt) > 220 ? '…' : ''; ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- LEFT COLUMN: Sidebar stats -->
    <aside class="snp-side">
      <!-- Stat 1: total posts today -->
      <div class="snp-stat snp-stat-big">
        <div class="snp-stat-kicker">إجمالي الأخبار اليوم</div>
        <div class="snp-stat-num"><?php echo e($__toArabicNum($__totalCount)); ?></div>
        <div class="snp-stat-foot">
          منشور · <?php echo e($__toArabicNum(count($__platforms))); ?> منصات
        </div>
      </div>

      <!-- Stat 2: top story / featured headline -->
      <a class="snp-stat snp-stat-link" href="<?php echo $__editorBrief ? 'telegram_summary.php?id=' . (int)$__editorBrief['id'] : ($__active['msgs'][0][$__active['urlKey']] ?? '#'); ?>"
         <?php echo $__editorBrief ? '' : 'target="_blank" rel="noopener"'; ?>>
        <div class="snp-stat-kicker">🔥 القضية الأبرز</div>
        <div class="snp-stat-headline"><?php echo e(mb_substr($__topStory, 0, 110)); ?><?php echo mb_strlen($__topStory) > 110 ? '…' : ''; ?></div>
        <div class="snp-stat-foot">
          <?php if ($__editorBrief && !empty($__editorBrief['message_count'])): ?>
            مستخرَجة من <?php echo e($__toArabicNum($__editorBrief['message_count'])); ?> منشور
          <?php else: ?>
            افتح الخبر ←
          <?php endif; ?>
        </div>
      </a>

      <!-- Stat 3: most active platform -->
      <?php if ($__topPlatform): ?>
        <a class="snp-stat snp-stat-link" href="<?php echo e($__tabUrl($__topPlatform)); ?>">
          <div class="snp-stat-kicker">📊 المنصة الأكثر نشراً</div>
          <div class="snp-stat-plat">
            <span class="snp-stat-plat-svg" style="color:<?php echo e($__platforms[$__topPlatform]['color']); ?>">
              <?php echo $__platforms[$__topPlatform]['svg']; ?>
            </span>
            <span class="snp-stat-plat-name"><?php echo e($__platforms[$__topPlatform]['label']); ?></span>
            <span class="snp-stat-plat-count"><?php echo e($__toArabicNum($__topPlatformCount)); ?> منشوراً</span>
          </div>
          <div class="snp-stat-bars">
            <?php
              $__max = max(1, $__topPlatformCount);
              foreach ($__platforms as $k => $p):
                if (empty($p['msgs'])) continue;
                $__pct = ($p['count'] / $__max) * 100;
            ?>
              <div class="snp-stat-bar-row">
                <span class="snp-stat-bar-label"><?php echo e($p['label']); ?></span>
                <span class="snp-stat-bar-track">
                  <span class="snp-stat-bar-fill" style="width:<?php echo (float)$__pct; ?>%; background:<?php echo e($p['color']); ?>"></span>
                </span>
                <span class="snp-stat-bar-num"><?php echo e($__toArabicNum($p['count'])); ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </a>
      <?php endif; ?>

      <!-- Stat 4: AI brief — موجز الساعة -->
      <?php if ($__editorBrief && !empty($__editorBrief['summary'])): ?>
        <?php $__briefBody = $__decode($__editorBrief['summary']); ?>
        <a class="snp-stat snp-stat-brief" href="telegram_summary.php?id=<?php echo (int)$__editorBrief['id']; ?>">
          <div class="snp-stat-kicker"><span class="snp-stat-spark">✨</span> موجز الساعة</div>
          <div class="snp-stat-brief-body">
            <?php echo e(mb_substr($__briefBody, 0, 200)); ?><?php echo mb_strlen($__briefBody) > 200 ? '…' : ''; ?>
          </div>
          <div class="snp-stat-foot">افتح الإيجاز الكامل ←</div>
        </a>
      <?php endif; ?>
    </aside>
  </div>

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
/* Top stripe */
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
.snp-stripe-live { display: inline-flex; align-items: center; gap: 6px; color: #FFB4B4; font-weight: 800; }
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
.snp-mast { text-align: center; padding: 24px 28px 14px; }
.snp-mast-pre { color: #877A64; font-weight: 500; font-size: 12px; letter-spacing: 1.2px; margin-bottom: 4px; }
.snp-mast-title { font-size: 36px; font-weight: 900; line-height: 1.15; letter-spacing: -0.5px; color: #14120E; margin: 0 0 6px; }
.snp-mast-sub { color: #504637; font-size: 13px; font-weight: 500; }
.snp-mast-sub b { color: #14120E; font-weight: 800; }

/* Double rule */
.snp-rule { padding: 0 28px; margin: 4px 0; }
.snp-rule-thick { height: 3px; background: #14120E; margin-bottom: 2px; }
.snp-rule-thin { height: 1px; background: #14120E; }
.snp-rule-mid { margin: 4px 0; }
.snp-rule-mid .snp-rule-thin { background: #877A64; opacity: 0.5; }

/* Platform kickers */
.snp-kickers { display: flex; flex-wrap: wrap; gap: 18px; justify-content: center; padding: 12px 28px; }
.snp-kicker { display: inline-flex; align-items: baseline; gap: 6px; text-decoration: none; color: #14120E; font-weight: 700; font-size: 12px; padding-bottom: 4px; border-bottom: 2px solid transparent; transition: color 0.15s ease, border-color 0.15s ease; }
.snp-kicker-svg { width: 13px; height: 13px; color: #877A64; display: inline-flex; align-self: center; }
.snp-kicker-svg svg { width: 100%; height: 100%; }
.snp-kicker-label { font-weight: 800; }
.snp-kicker-sep { color: #877A64; padding: 0 1px; }
.snp-kicker-count { color: #877A64; font-weight: 700; }
.snp-kicker:hover { color: #B4192E; }
.snp-kicker:hover .snp-kicker-svg { color: #B4192E; }
.snp-kicker.is-active { color: #B4192E; border-bottom-color: #B4192E; font-size: 13px; }
.snp-kicker.is-active .snp-kicker-label { font-weight: 900; }
.snp-kicker.is-active .snp-kicker-svg { color: #B4192E; }

/* ═════ SPLIT LAYOUT ═════ */
.snp-split {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0;
  padding: 8px 28px 18px;
  position: relative;
}
.snp-split::before {
  /* Vertical dividing rule between the two columns. */
  content: '';
  position: absolute;
  top: 14px; bottom: 14px;
  left: 50%;
  width: 1px;
  background: #BAA582;
  opacity: 0.6;
}

/* RIGHT (live feed) — first child but visually right in RTL */
.snp-feed-col { padding-left: 22px; }
.snp-col-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px;
}
.snp-col-head-l { display: inline-flex; align-items: center; gap: 7px; font-weight: 900; font-size: 13.5px; color: #14120E; }
.snp-col-head-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #B4192E;
  box-shadow: 0 0 6px rgba(180,25,46,0.5);
  animation: snpPulse 2s infinite;
}
.snp-col-head-more { color: #B4192E; font-weight: 800; font-size: 11.5px; text-decoration: none; }
.snp-col-head-more:hover { text-decoration: underline; }

/* Feed cards */
.snp-feed { display: flex; flex-direction: column; gap: 10px; }
.snp-feed-card {
  display: flex; gap: 10px;
  padding: 10px;
  background: rgba(255,255,255,0.55);
  border: 1px solid rgba(186,165,130,0.5);
  border-radius: 6px;
  text-decoration: none;
  color: inherit;
  transition: background 0.15s, border-color 0.15s;
}
.snp-feed-card:hover { background: #FFFFFF; border-color: #BAA582; }
.snp-feed-img {
  flex: 0 0 70px; width: 70px; height: 70px;
  border-radius: 4px; overflow: hidden;
  position: relative; background: #EEE8DB;
}
.snp-feed-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.snp-feed-play {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  color: #FFFFFF; font-size: 22px;
  background: rgba(0,0,0,0.32);
}
.snp-feed-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.snp-feed-meta { display: inline-flex; flex-wrap: wrap; gap: 5px; font-size: 10.5px; color: #504637; font-weight: 700; align-items: center; }
.snp-feed-meta b { color: #14120E; font-weight: 900; }
.snp-feed-dim { color: #877A64; font-weight: 600; }
.snp-feed-badge {
  display: inline-flex; align-items: center;
  padding: 2px 7px; border-radius: 3px;
  font-size: 9.5px; font-weight: 900;
  color: #FFFFFF;
  letter-spacing: 0.3px;
}
.snp-feed-text {
  font-size: 12.5px; line-height: 1.7; color: #14120E; font-weight: 500;
  display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
}

/* LEFT (stats) */
.snp-side {
  padding-right: 22px;
  display: flex; flex-direction: column; gap: 10px;
}
.snp-stat {
  display: block;
  padding: 12px 14px;
  background: rgba(255,255,255,0.55);
  border: 1px solid rgba(186,165,130,0.5);
  border-radius: 6px;
  text-decoration: none;
  color: inherit;
  transition: background 0.15s, border-color 0.15s;
}
.snp-stat-link:hover, .snp-stat-brief:hover { background: #FFFFFF; border-color: #BAA582; }
.snp-stat-kicker {
  color: #B4192E;
  font-weight: 900;
  font-size: 10.5px;
  letter-spacing: 0.8px;
  margin-bottom: 6px;
  display: inline-flex; align-items: center; gap: 4px;
}

/* Stat 1: big number */
.snp-stat-big { text-align: center; padding: 14px; background: #14120E; color: #FAF0E5; border-color: #14120E; }
.snp-stat-big .snp-stat-kicker { color: #FFD79C; }
.snp-stat-num {
  font-size: 42px; font-weight: 900; line-height: 1; color: #FAF0E5;
  letter-spacing: -1px;
  margin: 2px 0 4px;
}
.snp-stat-foot { font-size: 10.5px; color: #877A64; font-weight: 700; margin-top: 4px; }
.snp-stat-big .snp-stat-foot { color: rgba(250,240,229,0.65); }

/* Stat 2: headline */
.snp-stat-headline {
  font-size: 14.5px; font-weight: 800; line-height: 1.5; color: #14120E;
}

/* Stat 3: top platform */
.snp-stat-plat { display: flex; align-items: center; gap: 8px; }
.snp-stat-plat-svg { width: 22px; height: 22px; display: inline-flex; }
.snp-stat-plat-svg svg { width: 100%; height: 100%; }
.snp-stat-plat-name { font-size: 14px; font-weight: 900; color: #14120E; }
.snp-stat-plat-count { margin-right: auto; font-size: 11px; color: #504637; font-weight: 700; }
.snp-stat-bars { display: flex; flex-direction: column; gap: 5px; margin-top: 10px; }
.snp-stat-bar-row { display: flex; align-items: center; gap: 6px; font-size: 10px; }
.snp-stat-bar-label { width: 36px; color: #504637; font-weight: 700; }
.snp-stat-bar-track { flex: 1; height: 5px; background: rgba(20,18,14,0.08); border-radius: 999px; overflow: hidden; }
.snp-stat-bar-fill { display: block; height: 100%; border-radius: 999px; }
.snp-stat-bar-num { color: #877A64; font-weight: 800; width: 22px; text-align: left; }

/* Stat 4: AI brief */
.snp-stat-brief { background: #EEE8DB; border-color: #BAA582; }
.snp-stat-brief .snp-stat-kicker { color: #6B4F0B; }
.snp-stat-spark { color: #A67C1B; font-size: 12px; }
.snp-stat-brief-body { font-size: 11.5px; line-height: 1.7; color: #504637; font-weight: 500; }

/* Footer ink band */
.snp-foot {
  display: flex; justify-content: space-between; align-items: center;
  background: #14120E; color: #FAF0E5;
  padding: 14px 28px;
}
.snp-foot-h { font-weight: 900; font-size: 12.5px; color: #FAF0E5; }
.snp-foot-s { font-weight: 500; font-size: 10.5px; color: rgba(255, 250, 240, 0.6); margin-top: 1px; }
.snp-foot-cta {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 14px;
  background: #B4192E; color: #FFFFFF;
  font-weight: 900; font-size: 12px;
  text-decoration: none;
  transition: background 0.15s ease;
}
.snp-foot-cta:hover { background: #951424; }
.snp-arrow { display: inline-block; }

/* Mobile — stack the split vertically (stats first, then feed). */
@media (max-width: 820px) {
  .snp-mast-title { font-size: 28px; }
  .snp-mast { padding: 18px 18px 12px; }
  .snp-rule, .snp-kickers, .snp-foot { padding-left: 18px; padding-right: 18px; }
  .snp-kickers { gap: 14px; }
  .snp-split {
    grid-template-columns: 1fr;
    padding: 8px 18px 18px;
    gap: 12px;
  }
  .snp-split::before { display: none; }
  .snp-feed-col { padding-left: 0; order: 2; }
  .snp-side { padding-right: 0; order: 1; }
  .snp-foot { flex-direction: column; align-items: stretch; gap: 10px; }
  .snp-foot-l { text-align: right; }
  .snp-stat-num { font-size: 36px; }
}
</style>
