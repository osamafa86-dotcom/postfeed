<?php
/**
 * نيوز فيد — نسخة قابلة للطباعة (كتاب) لقصة متطوّرة
 * (Evolving Stories Phase 3 #9 — PDF / Book Export)
 *
 * Deliberately does NOT use dompdf/mpdf. On shared hosting without
 * composer access every PHP-side PDF library means vendoring several
 * megabytes of fragile code with spotty Arabic/RTL support. Instead
 * we render a single-page print-optimized HTML document and let the
 * browser's native "Save as PDF" do the heavy lifting — that path
 * gives us perfect Tajawal + Arabic shaping for free, and gives the
 * user a native save dialog they already know.
 *
 * The page auto-launches window.print() on load when ?print=1 is set
 * so the export link on the main story page feels instant.
 *
 * URL: /evolving-story/{slug}/book  (rewritten by .htaccess)
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';
require_once __DIR__ . '/includes/evolving_stories.php';
require_once __DIR__ . '/includes/story_timeline.php';
require_once __DIR__ . '/includes/cache.php';

$slug  = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
$story = $slug !== '' ? evolving_story_get_by_slug($slug) : null;

if (!$story || !$story['is_active']) {
    http_response_code(404);
    echo '<h1>القصة غير موجودة</h1>';
    exit;
}

// Pull up to 80 articles for the book — enough to feel like a full
// archive without making the print job absurdly long on stories with
// hundreds of matches. The user can always export the web page if
// they need everything.
$articles    = evolving_story_articles((int)$story['id'], 80, 0, null);
$articlesAsc = array_reverse($articles);

// Reuse the same AI narrative the main story page already generates.
// Cached in the same slot so we don't pay for a second Claude call.
$aiTimeline = null;
if (count($articlesAsc) >= STORY_TIMELINE_MIN_ARTICLES) {
    $cacheKey = 'evolving_story_timeline_' . (int)$story['id'];
    $aiTimeline = cache_get($cacheKey);
    if (!$aiTimeline || !is_array($aiTimeline)) {
        $res = story_timeline_generate($articlesAsc);
        if (!empty($res['ok'])) {
            $aiTimeline = $res;
            cache_set($cacheKey, $aiTimeline, 6 * 3600);
        }
    }
}

// Source list + span for the cover page stats.
$seen = [];
foreach ($articles as $a) {
    $sk = $a['source_name'] ?? '';
    if ($sk !== '') $seen[$sk] = true;
}
$sourceCount = count($seen);

$dateRange = evolving_story_date_range((int)$story['id']);
$daysSpan  = 0;
if (!empty($dateRange['first']) && !empty($dateRange['last'])) {
    $d1 = strtotime($dateRange['first']);
    $d2 = strtotime($dateRange['last']);
    if ($d1 && $d2) $daysSpan = max(1, (int)ceil(($d2 - $d1) / 86400));
}

$autoPrint   = isset($_GET['print']) && $_GET['print'] == '1';
$siteName    = getSetting('site_name', SITE_NAME);
$generatedAt = date('j F Y');
$pageTitle   = $story['name'] . ' — كتاب القصة · ' . $siteName;
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="/">
<title><?php echo e($pageTitle); ?></title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap">
<style>
  :root {
    --es-accent: <?php echo e($story['accent_color']); ?>;
    --ink:#1a1a2e; --ink2:#3a3a52; --muted:#6b7280;
    --paper:#ffffff; --cream:#faf6ec; --border:#d5d8de; --gold:#f59e0b;
  }
  * { box-sizing:border-box; }
  html, body { margin:0; padding:0; background:#e5e7eb; color:var(--ink); }
  body { font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; font-size:13pt; line-height:1.85; }
  a { color:var(--es-accent); text-decoration:none; }

  /* Screen-only toolbar with the Print button. Hidden at print time. */
  .book-toolbar {
    position:sticky; top:0; z-index:10;
    background:#1a1a2e; color:#fff;
    padding:14px 24px;
    display:flex; justify-content:space-between; align-items:center;
    gap:16px; flex-wrap:wrap;
    box-shadow:0 4px 14px rgba(0,0,0,.2);
  }
  .book-toolbar .hint { font-size:12px; color:#cbd5e1; }
  .book-toolbar .hint b { color:#fff; }
  .book-toolbar .actions { display:flex; gap:10px; }
  .book-toolbar button, .book-toolbar a.btn {
    background:var(--es-accent); color:#fff; border:0;
    padding:10px 20px; border-radius:10px;
    font-family:inherit; font-weight:800; font-size:13px;
    cursor:pointer; transition:transform .15s ease;
  }
  .book-toolbar a.btn-ghost {
    background:transparent; border:1px solid rgba(255,255,255,.35);
  }
  .book-toolbar button:hover, .book-toolbar a.btn:hover { transform:translateY(-1px); }

  /* The "paper" metaphor — each section is an A4 page on screen so the
     user previews what they're about to print before they hit Save. */
  .book {
    max-width:210mm; margin:24px auto; padding:0;
  }
  .page {
    background:var(--paper);
    padding:24mm 22mm;
    margin:0 auto 18px;
    box-shadow:0 6px 30px -14px rgba(0,0,0,.25);
    border-radius:2px;
    min-height:297mm;
    page-break-after:always;
  }
  .page.no-break { page-break-after:avoid; }

  /* ============== COVER PAGE ============== */
  .cover {
    position:relative;
    background:linear-gradient(160deg, var(--es-accent) 0%, #1a1a2e 130%);
    color:#fff;
    padding:42mm 24mm;
    min-height:297mm;
    display:flex; flex-direction:column; justify-content:space-between;
    overflow:hidden;
  }
  .cover::before {
    content:''; position:absolute; inset:0;
    background:radial-gradient(circle at 80% 10%, rgba(255,255,255,.18), transparent 55%);
    pointer-events:none;
  }
  .cover-brand {
    font-size:14pt; font-weight:800; letter-spacing:2px;
    text-transform:uppercase; color:rgba(255,255,255,.7);
  }
  .cover-brand b { color:#fff; }
  .cover-main { flex:1; display:flex; flex-direction:column; justify-content:center; }
  .cover-icon {
    width:90px; height:90px; border-radius:22px;
    background:rgba(255,255,255,.95); color:#1a1a2e;
    display:flex; align-items:center; justify-content:center;
    font-size:48pt; margin-bottom:22px;
    box-shadow:0 10px 30px rgba(0,0,0,.3);
  }
  .cover-eyebrow {
    display:inline-block; padding:6px 16px; border-radius:999px;
    background:rgba(255,255,255,.16); color:#fff;
    font-size:10pt; font-weight:800; margin-bottom:14px;
  }
  .cover-title {
    font-size:38pt; font-weight:900; line-height:1.25; margin:0 0 16px;
    color:#fff; text-shadow:0 3px 14px rgba(0,0,0,.35);
  }
  .cover-sub {
    font-size:14pt; line-height:1.9; color:#e5e7eb; max-width:150mm;
  }
  .cover-stats {
    display:grid; grid-template-columns:repeat(4,1fr);
    gap:10px; margin-top:30px;
  }
  .cover-stat {
    background:rgba(255,255,255,.1);
    border:1px solid rgba(255,255,255,.18);
    border-radius:12px; padding:12px 14px;
  }
  .cover-stat .n { font-size:22pt; font-weight:900; color:#fff; line-height:1; font-variant-numeric:tabular-nums; }
  .cover-stat .l { font-size:9pt; color:#cbd5e1; font-weight:700; margin-top:6px; }
  .cover-footer {
    display:flex; justify-content:space-between; align-items:end;
    color:rgba(255,255,255,.75); font-size:10pt;
    border-top:1px solid rgba(255,255,255,.2);
    padding-top:18px;
  }

  /* ============== CONTENT PAGES ============== */
  .section-title {
    font-size:22pt; font-weight:900; color:var(--ink);
    margin:0 0 22px; padding-bottom:14px;
    border-bottom:3px solid var(--es-accent);
    display:flex; align-items:center; gap:12px;
  }
  .section-title .ico {
    display:inline-flex; align-items:center; justify-content:center;
    width:44px; height:44px; border-radius:12px;
    background:var(--es-accent); color:#fff;
    font-size:20pt;
  }
  .section-intro {
    font-size:13pt; line-height:1.95; color:var(--ink2);
    margin-bottom:22px;
  }

  /* AI narrative */
  .narrative-headline {
    font-size:20pt; font-weight:900; color:var(--ink);
    line-height:1.4; margin:14px 0 16px;
  }
  .narrative-intro {
    font-size:13pt; line-height:2; color:var(--ink2);
    padding-right:14px; border-right:4px solid var(--es-accent);
    margin-bottom:18px;
  }

  /* Timeline */
  .tl-event {
    padding:14px 18px; margin-bottom:14px;
    background:var(--cream); border:1px solid var(--border);
    border-right:4px solid var(--es-accent);
    border-radius:10px;
    page-break-inside:avoid;
  }
  .tl-head { display:flex; gap:10px; align-items:center; margin-bottom:8px; flex-wrap:wrap; }
  .tl-date {
    background:#fef3c7; color:#92400e;
    padding:3px 11px; border-radius:999px;
    font-size:9pt; font-weight:800;
    border:1px solid #fcd34d;
  }
  .tl-icon { font-size:13pt; }
  .tl-title { font-size:13pt; font-weight:800; line-height:1.55; margin:0 0 6px; }
  .tl-summary { font-size:11.5pt; line-height:1.9; color:var(--ink2); margin:0; }
  .tl-sources { margin-top:8px; font-size:9.5pt; color:var(--muted); }

  /* Articles list */
  .article {
    padding:12px 0 14px;
    border-bottom:1px solid var(--border);
    page-break-inside:avoid;
  }
  .article:last-child { border-bottom:0; }
  .article-title { font-size:12.5pt; font-weight:800; line-height:1.55; margin:0 0 6px; color:var(--ink); }
  .article-excerpt { font-size:10.5pt; line-height:1.85; color:var(--ink2); margin:0 0 6px; }
  .article-meta { font-size:9pt; color:var(--muted); font-weight:600; }
  .article-meta .sep { margin:0 6px; color:#cbd5e1; }

  /* ============ @media print ============ */
  @page {
    size: A4;
    margin: 15mm 14mm 18mm;
  }
  @media print {
    html, body { background:#fff; }
    .book-toolbar { display:none !important; }
    .book { max-width:none; margin:0; padding:0; }
    .page {
      box-shadow:none; border-radius:0;
      margin:0; padding:0;
      min-height:auto;
    }
    /* Cover keeps its background — browsers need this hint to print it. */
    .cover {
      -webkit-print-color-adjust:exact;
      print-color-adjust:exact;
      padding:30mm 18mm;
    }
    .section-title, .tl-event, .cover-stat, .narrative-intro {
      -webkit-print-color-adjust:exact;
      print-color-adjust:exact;
    }
    a { color:var(--ink); text-decoration:none; }
  }
</style>
</head>
<body>

<!-- Screen-only toolbar -->
<div class="book-toolbar">
  <div class="hint">
    📖 نسخة كتاب قابلة للطباعة — <b>Ctrl + P</b> أو اضغط الزر لحفظها كـ PDF.
  </div>
  <div class="actions">
    <button type="button" onclick="window.print();">🖨️ احفظ كـ PDF</button>
    <a class="btn btn-ghost" href="/evolving-story/<?php echo e((string)$story['slug']); ?>">← رجوع</a>
  </div>
</div>

<div class="book">

  <!-- ========== COVER ========== -->
  <section class="page cover">
    <div class="cover-brand">نيوز فيد · <b>News Feed</b></div>

    <div class="cover-main">
      <div class="cover-icon"><?php echo e($story['icon'] ?: '📰'); ?></div>
      <span class="cover-eyebrow">قصة متطوّرة</span>
      <h1 class="cover-title"><?php echo e((string)$story['name']); ?></h1>
      <?php if (!empty($story['description'])): ?>
        <p class="cover-sub"><?php echo e((string)$story['description']); ?></p>
      <?php endif; ?>

      <div class="cover-stats">
        <div class="cover-stat">
          <div class="n"><?php echo number_format((int)$story['article_count']); ?></div>
          <div class="l">📰 تقرير</div>
        </div>
        <div class="cover-stat">
          <div class="n"><?php echo number_format($sourceCount); ?></div>
          <div class="l">🌐 مصدر</div>
        </div>
        <?php if ($daysSpan > 0): ?>
          <div class="cover-stat">
            <div class="n"><?php echo number_format($daysSpan); ?></div>
            <div class="l">📅 يوم</div>
          </div>
        <?php endif; ?>
        <?php if ($aiTimeline && !empty($aiTimeline['events'])): ?>
          <div class="cover-stat">
            <div class="n"><?php echo number_format(count($aiTimeline['events'])); ?></div>
            <div class="l">📍 حدث</div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="cover-footer">
      <div>تم التوليد في <?php echo e($generatedAt); ?></div>
      <div><?php echo e($siteName); ?></div>
    </div>
  </section>

  <!-- ========== AI NARRATIVE + TIMELINE ========== -->
  <?php if ($aiTimeline && !empty($aiTimeline['headline'])): ?>
    <section class="page">
      <h2 class="section-title">
        <span class="ico">🤖</span>
        <span>السرد الذكي</span>
      </h2>

      <h3 class="narrative-headline"><?php echo e((string)$aiTimeline['headline']); ?></h3>

      <?php if (!empty($aiTimeline['intro'])): ?>
        <p class="narrative-intro"><?php echo e((string)$aiTimeline['intro']); ?></p>
      <?php endif; ?>

      <?php if (!empty($aiTimeline['events']) && is_array($aiTimeline['events'])): ?>
        <h3 style="font-size:15pt;font-weight:900;margin:24px 0 14px;">📍 خط الأحداث</h3>
        <?php foreach ($aiTimeline['events'] as $ev): ?>
          <div class="tl-event">
            <div class="tl-head">
              <?php if (!empty($ev['icon'])): ?>
                <span class="tl-icon"><?php echo e((string)$ev['icon']); ?></span>
              <?php endif; ?>
              <?php if (!empty($ev['date'])): ?>
                <span class="tl-date"><?php echo e((string)$ev['date']); ?></span>
              <?php endif; ?>
            </div>
            <?php if (!empty($ev['title'])): ?>
              <h4 class="tl-title"><?php echo e((string)$ev['title']); ?></h4>
            <?php endif; ?>
            <?php if (!empty($ev['summary'])): ?>
              <p class="tl-summary"><?php echo e((string)$ev['summary']); ?></p>
            <?php endif; ?>
            <?php if (!empty($ev['sources']) && is_array($ev['sources'])): ?>
              <div class="tl-sources">
                المصادر:
                <?php
                  $srcNames = [];
                  foreach ($ev['sources'] as $label) {
                    $idx = (int)preg_replace('/[^0-9]/', '', (string)$label);
                    if ($idx > 0 && $idx <= count($articlesAsc)) {
                      $srcNames[] = (string)($articlesAsc[$idx - 1]['source_name'] ?? $label);
                    }
                  }
                  echo e(implode(' · ', $srcNames));
                ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <!-- ========== RAW ARTICLES ========== -->
  <?php if (!empty($articles)): ?>
    <section class="page">
      <h2 class="section-title">
        <span class="ico">📋</span>
        <span>كل التقارير (<?php echo number_format(count($articles)); ?>)</span>
      </h2>
      <p class="section-intro">
        أحدث <?php echo count($articles); ?> تقريراً مُرتبطاً بهذه القصة، مرتّبة من الأجدد إلى الأقدم.
      </p>

      <?php foreach ($articles as $a): ?>
        <article class="article">
          <h4 class="article-title"><?php echo e(mb_substr((string)$a['title'], 0, 180)); ?></h4>
          <?php if (!empty($a['excerpt'])): ?>
            <p class="article-excerpt">
              <?php echo e(mb_substr(strip_tags((string)$a['excerpt']), 0, 260)); ?>
            </p>
          <?php endif; ?>
          <div class="article-meta">
            <?php if (!empty($a['source_name'])): ?>
              <span>🌐 <?php echo e((string)$a['source_name']); ?></span>
              <span class="sep">·</span>
            <?php endif; ?>
            <?php if (!empty($a['published_at'])): ?>
              <span>📅 <?php echo e(date('j F Y', strtotime((string)$a['published_at']))); ?></span>
            <?php endif; ?>
            <?php if (!empty($a['cat_name'])): ?>
              <span class="sep">·</span>
              <span>🏷 <?php echo e((string)$a['cat_name']); ?></span>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

</div>

<script>
  // Auto-launch the print dialog when the page arrives with ?print=1
  // so the "Export PDF" button on the main story page feels instant.
  // We wait for fonts to settle so the generated PDF isn't missing
  // glyphs on the first paint.
  <?php if ($autoPrint): ?>
  window.addEventListener('load', function() {
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(function() { setTimeout(function(){ window.print(); }, 400); });
    } else {
      setTimeout(function(){ window.print(); }, 800);
    }
  });
  <?php endif; ?>
</script>
</body>
</html>
