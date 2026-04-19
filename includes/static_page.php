<?php
/**
 * نيوزفلو — Shared shell for static info pages.
 *
 * About / Privacy / Contact / Editorial / Corrections all share the
 * same layout: a branded header with a link home, a readable content
 * column, and a slim footer. Keeping the shell here means each page
 * file is just its own prose — no duplicate CSS or meta-tag boilerplate.
 *
 * Pages call `static_page_open()` before printing their content and
 * `static_page_close()` after. The helpers also emit the SEO block
 * (canonical, OG, breadcrumb JSON-LD) for Google News eligibility.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/seo.php';

/**
 * Emit the <html><head>…<body><header>…<main> opening for an info page.
 * The content follows immediately after. Call static_page_close() to
 * close <main>, render the footer, and close </body></html>.
 *
 * @param string $title     Page title without site-name suffix
 * @param string $desc      Meta description (<= 160 chars ideally)
 * @param string $slug      Path slug (about, privacy, contact, …) — used for canonical
 * @param string $updated   ISO 8601 date ("last reviewed" notice shown to users)
 */
function static_page_open(string $title, string $desc, string $slug, string $updated = ''): void {
    $siteName  = getSetting('site_name', SITE_NAME);
    $canonical = rtrim(SITE_URL, '/') . '/' . ltrim($slug, '/');
    ?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($title . ' — ' . $siteName); ?></title>
<meta name="description" content="<?php echo e($desc); ?>">
<?php
    render_list_seo($title, $desc, $canonical, '', 'website');
    render_breadcrumb([
        ['name' => $siteName, 'url' => rtrim(SITE_URL, '/') . '/'],
        ['name' => $title],
    ]);
?>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #faf6ec;
    --card: #ffffff;
    --border: #e0e3e8;
    --accent: #1a73e8;
    --accent2: #0d9488;
    --text: #1a1a2e;
    --muted: #6b7280;
    --header-bg: #1a1a2e;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif; background:var(--bg); color:var(--text); line-height:1.8; }
  a { color:var(--accent); text-decoration:none; }
  a:hover { text-decoration:underline; }

  .sp-header {
    background:var(--header-bg); color:#fff;
    padding:18px 0; box-shadow:0 2px 12px rgba(0,0,0,.15);
  }
  .sp-header-inner {
    max-width:860px; margin:0 auto; padding:0 24px;
    display:flex; align-items:center; justify-content:space-between; gap:16px;
  }
  .sp-logo {
    font-size:20px; font-weight:900; color:#fff;
    display:flex; align-items:center; gap:10px;
  }
  .sp-logo:hover { text-decoration:none; opacity:.9; }
  .sp-home-link {
    color:#cbd5e1; font-size:14px; font-weight:600;
    padding:6px 14px; border-radius:8px; border:1px solid rgba(255,255,255,.15);
    transition:all .2s;
  }
  .sp-home-link:hover { background:rgba(255,255,255,.08); color:#fff; text-decoration:none; }

  main.sp-main {
    max-width:860px; margin:40px auto; padding:0 24px;
  }
  .sp-card {
    background:var(--card); border:1px solid var(--border);
    border-radius:16px; padding:40px 44px;
    box-shadow:0 2px 12px rgba(0,0,0,.04);
  }
  .sp-eyebrow {
    display:inline-block; font-size:12px; font-weight:700;
    color:var(--accent2); background:#ccfbf1; padding:4px 12px; border-radius:6px;
    margin-bottom:14px; letter-spacing:.5px;
  }
  .sp-title {
    font-size:32px; font-weight:900; margin-bottom:8px; line-height:1.3;
  }
  .sp-updated {
    font-size:13px; color:var(--muted); margin-bottom:28px;
  }
  .sp-body h2 {
    font-size:22px; font-weight:800; margin:28px 0 12px;
    padding-right:14px; border-right:4px solid var(--accent); line-height:1.4;
  }
  .sp-body h3 {
    font-size:17px; font-weight:700; margin:20px 0 8px; color:var(--text);
  }
  .sp-body p  { margin:0 0 14px; color:#374151; font-size:16px; }
  .sp-body ul, .sp-body ol { margin:8px 0 16px; padding-right:28px; color:#374151; }
  .sp-body li { margin-bottom:8px; }
  .sp-body strong { color:var(--text); font-weight:700; }
  .sp-body a { color:var(--accent); font-weight:600; }
  .sp-body hr { border:0; border-top:1px solid var(--border); margin:28px 0; }
  .sp-body .sp-callout {
    background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px;
    padding:16px 20px; margin:20px 0; color:#075985;
  }
  .sp-body .sp-contact-row {
    display:flex; flex-wrap:wrap; gap:12px; margin:16px 0;
  }
  .sp-body .sp-contact-row .sp-pill {
    display:inline-flex; align-items:center; gap:8px;
    padding:10px 16px; background:#f1f5f9; border:1px solid var(--border);
    border-radius:10px; font-weight:600; color:var(--text);
  }

  .sp-footer {
    max-width:860px; margin:0 auto; padding:24px;
    text-align:center; color:var(--muted); font-size:13px;
  }
  .sp-footer-links { margin-bottom:10px; }
  .sp-footer-links a { color:var(--muted); margin:0 10px; font-weight:600; }
  .sp-footer-links a:hover { color:var(--accent); }

  @media (max-width:640px) {
    .sp-card { padding:28px 22px; border-radius:12px; }
    .sp-title { font-size:26px; }
    .sp-body h2 { font-size:19px; }
    main.sp-main { margin:24px auto; }
  }
</style>
</head>
<body>
<header class="sp-header">
  <div class="sp-header-inner">
    <a href="/" class="sp-logo">
      <span>📰</span>
      <span><?php echo e($siteName); ?></span>
    </a>
    <a href="/" class="sp-home-link">← الصفحة الرئيسية</a>
  </div>
</header>
<main class="sp-main">
  <article class="sp-card">
    <h1 class="sp-title"><?php echo e($title); ?></h1>
    <?php if ($updated): ?>
      <div class="sp-updated">آخر تحديث: <?php echo e($updated); ?></div>
    <?php endif; ?>
    <div class="sp-body">
<?php
}

/** Close the page shell and render the footer. */
function static_page_close(): void {
    $siteName = getSetting('site_name', SITE_NAME);
    ?>
    </div>
  </article>
</main>
<div class="sp-footer">
  <div class="sp-footer-links">
    <a href="/about">من نحن</a>
    <a href="/editorial">السياسة التحريرية</a>
    <a href="/corrections">التصحيحات</a>
    <a href="/privacy">الخصوصية</a>
    <a href="/contact">اتصل بنا</a>
  </div>
  <div>&copy; <?php echo date('Y'); ?> <?php echo e($siteName); ?> — جميع الحقوق محفوظة</div>
</div>
</body>
</html>
<?php
}
