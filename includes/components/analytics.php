<?php
/**
 * Google Analytics 4 (gtag.js) snippet.
 *
 * Rendered near the top of <body> via the shared site header. Emits
 * nothing at all if GA4 isn't configured or is toggled off in the admin
 * panel, so the page stays free of third-party requests for site owners
 * who haven't opted in.
 *
 * Optional pre-injected context (set by the including page before this
 * file is rendered) is forwarded to GA4 as event parameters so we can
 * segment by content type:
 *
 *   $nfAnalyticsContext = [
 *       'content_type'  => 'article' | 'cluster' | 'category' | …
 *       'article_id'    => 123,
 *       'category_slug' => 'political',
 *       'source_id'     => 45,
 *       'cluster_key'   => 'ab12…',
 *   ];
 */

$ga4_id = trim((string) getSetting('ga4_measurement_id', ''));
$ga4_on = getSetting('analytics_enabled', '0') === '1';

// Guard: only emit if both configured AND enabled, and the ID looks right.
if (!$ga4_on || $ga4_id === '' || !preg_match('/^G-[A-Z0-9]{6,}$/i', $ga4_id)) {
    return;
}

$ctx = isset($nfAnalyticsContext) && is_array($nfAnalyticsContext) ? $nfAnalyticsContext : [];

// Only pass through a known-good whitelist of context keys so a page
// can't accidentally leak PII into GA4.
$allowed = ['content_type', 'article_id', 'category_slug', 'source_id', 'cluster_key', 'page_variant'];
$clean = [];
foreach ($allowed as $k) {
    if (isset($ctx[$k]) && $ctx[$k] !== '' && $ctx[$k] !== null) {
        $clean[$k] = is_scalar($ctx[$k]) ? (string)$ctx[$k] : '';
    }
}
$ctxJson = $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
?>
<!-- Google Analytics 4 -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo e($ga4_id); ?>"></script>
<script>
(function () {
  window.dataLayer = window.dataLayer || [];
  function gtag(){ dataLayer.push(arguments); }
  window.gtag = gtag;
  gtag('js', new Date());

  var ctx = <?php echo $ctxJson; ?>;
  var cfg = { anonymize_ip: true, transport_type: 'beacon' };
  // Forward context as default event parameters on every event.
  Object.keys(ctx).forEach(function (k) { cfg[k] = ctx[k]; });

  gtag('config', <?php echo json_encode($ga4_id); ?>, cfg);

  // Expose a small helper so the rest of the site can track custom
  // events without repeating boilerplate. Swallows errors silently
  // if GA4 failed to load (ad-blocker, offline, etc).
  window.nfTrack = function (name, params) {
    try { gtag('event', name, params || {}); } catch (e) {}
  };
})();
</script>
<script defer src="/assets/js/analytics.js?v=1"></script>
