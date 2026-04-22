<?php
/**
 * Shared PWA <head> fragment.
 *
 * Emits everything a browser/iOS needs to recognise the site as an
 * installable progressive web app: viewport, theme-color, manifest
 * link, apple-touch-icon + iOS meta, and the service-worker
 * registration script. Include once from every page's <head>.
 *
 * Variables consumed (optional):
 *   $pwa_theme_color  string  hex colour for <meta name="theme-color">
 *                             defaults to the brand teal
 */

$pwa_theme_color = $pwa_theme_color ?? '#1a5c5c';
?>
<link rel="stylesheet" href="/assets/css/pwa-mobile.css?v=3">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="<?php echo htmlspecialchars($pwa_theme_color, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="color-scheme" content="light dark">
<meta name="mobile-web-app-capable" content="yes">

<!-- iOS "Add to Home Screen" support -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="نيوز فيد">
<meta name="format-detection" content="telephone=no">
<link rel="apple-touch-icon" href="/icon.php?size=180">
<link rel="apple-touch-icon" sizes="152x152" href="/icon.php?size=152">
<link rel="apple-touch-icon" sizes="167x167" href="/icon.php?size=167">
<link rel="apple-touch-icon" sizes="180x180" href="/icon.php?size=180">
<link rel="icon" type="image/png" sizes="192x192" href="/icon.php?size=192">
<link rel="icon" type="image/png" sizes="512x512" href="/icon.php?size=512">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="mask-icon" href="/assets/favicon.svg" color="<?php echo htmlspecialchars($pwa_theme_color, ENT_QUOTES, 'UTF-8'); ?>">

<script>
// Register the service worker so return visitors can install the
// site to their home screen and get the offline shell. The wrapper
// keeps registration off the critical path and silently no-ops on
// browsers without SW support.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    try { navigator.serviceWorker.register('/sw.js', { scope: '/' }); } catch (e) {}
  });
}
</script>
<script src="/assets/js/install-prompt.js" defer></script>
