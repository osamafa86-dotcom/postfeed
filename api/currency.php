<?php
/**
 * Currency rates proxy — fetches FX rates server-side and caches them
 * so the browser isn't exposed to the upstream's CORS / redirect quirks.
 *
 * Frankfurter bounced domains recently (.app → .dev) with a 301 whose
 * CORS headers aren't set on the redirect response, which killed the
 * direct fetch from the client. Serving from our own origin sidesteps
 * the whole problem and also gives us a 1-hour cache for free — FX
 * rates aren't high-frequency data.
 *
 * Response shape matches the original Frankfurter payload so the
 * frontend code (home.js) doesn't need restructuring:
 *   {
 *     "base": "USD",
 *     "date": "2026-04-14",
 *     "rates": { "ILS": 3.7, "JOD": 0.71, … }
 *   }
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

$cacheKey = 'currency_rates_usd_v2';
$cached = cache_get($cacheKey);
if ($cached) {
    echo $cached;
    exit;
}

// Short negative cache — if every upstream was unreachable recently
// (see the 5-min TTL set below) just serve the stub payload so we
// don't force every visitor to eat a ~12-second triple-timeout.
$failCached = cache_get($cacheKey . ':fail');
if ($failCached) {
    http_response_code(502);
    echo $failCached;
    exit;
}

// Try multiple upstreams in order. cURL is preferred because it lets
// us cap the timeout tightly; if it's not available we fall back to
// file_get_contents with a stream context. Each provider returns a
// slightly different shape, so we normalise after fetching.
$wanted = ['ILS','JOD','EUR','GBP','SAR','EGP','TRY','AED','KWD'];
$symbols = implode(',', $wanted);

$providers = [
    // Frankfurter (primary). Bounces between .dev and .app — both are
    // listed so if one domain's DNS/cert is flaky we have a sibling.
    [
        'url' => 'https://api.frankfurter.dev/v1/latest?base=USD&symbols=' . $symbols,
        'extract' => function(array $d) { return $d['rates'] ?? null; },
        'date'    => function(array $d) { return $d['date'] ?? null; },
    ],
    [
        'url' => 'https://api.frankfurter.app/latest?from=USD&to=' . $symbols,
        'extract' => function(array $d) { return $d['rates'] ?? null; },
        'date'    => function(array $d) { return $d['date'] ?? null; },
    ],
    // open.er-api.com (fallback). Returns all currencies in one shot —
    // we filter down to the subset we actually render.
    [
        'url' => 'https://open.er-api.com/v6/latest/USD',
        'extract' => function(array $d) use ($wanted) {
            if (empty($d['rates']) || !is_array($d['rates'])) return null;
            $out = [];
            foreach ($wanted as $code) {
                if (isset($d['rates'][$code])) $out[$code] = $d['rates'][$code];
            }
            return $out ?: null;
        },
        'date' => function(array $d) {
            // time_last_update_utc is an RFC-822 date; convert or fall
            // back to today if the format ever drifts.
            if (!empty($d['time_last_update_utc'])) {
                $ts = strtotime($d['time_last_update_utc']);
                if ($ts) return date('Y-m-d', $ts);
            }
            return null;
        },
    ],
];

$rates = null;
$date  = null;
$errors = [];
foreach ($providers as $p) {
    $body = fx_fetch($p['url'], 4);
    if ($body === null) { $errors[] = 'fetch_fail:' . parse_url($p['url'], PHP_URL_HOST); continue; }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) { $errors[] = 'bad_json:' . parse_url($p['url'], PHP_URL_HOST); continue; }
    $r = ($p['extract'])($decoded);
    if (!$r) { $errors[] = 'no_rates:' . parse_url($p['url'], PHP_URL_HOST); continue; }
    $rates = $r;
    $date  = ($p['date'])($decoded) ?: date('Y-m-d');
    break;
}

if ($rates === null) {
    // Cache the failure for 5 minutes so we don't hammer dead upstreams
    // on every pageview (each attempt eats up to 4s × 3 providers).
    error_log('currency.php: all upstreams failed — ' . implode(', ', $errors));
    $failBody = json_encode([
        'base'  => 'USD',
        'date'  => date('Y-m-d'),
        'rates' => new stdClass(),
        'error' => 'upstream_unavailable',
    ], JSON_UNESCAPED_UNICODE);
    cache_set($cacheKey . ':fail', $failBody, 300);
    http_response_code(502);
    echo $failBody;
    exit;
}

// Normalise to the original payload shape before caching.
$out = json_encode([
    'base'  => 'USD',
    'date'  => $date,
    'rates' => $rates,
], JSON_UNESCAPED_UNICODE);

cache_set($cacheKey, $out, 3600);
echo $out;


function fx_fetch(string $url, int $timeout = 4): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT      => 'NewsflowFX/1.0 (+postfeed.emdatra.org)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout'       => $timeout,
            'follow_location' => 1,
            'user_agent'    => 'NewsflowFX/1.0',
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? $body : null;
}
