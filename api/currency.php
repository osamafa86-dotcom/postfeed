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

// Try the current endpoint first, fall back to the legacy one. cURL is
// preferred because it lets us cap the timeout tightly; if it's not
// available we fall back to file_get_contents with a stream context.
$symbols = 'ILS,JOD,EUR,GBP,SAR,EGP,TRY,AED,KWD';
$urls = [
    'https://api.frankfurter.dev/v1/latest?base=USD&symbols=' . $symbols,
    'https://api.frankfurter.app/latest?from=USD&to=' . $symbols,
];

$body = null;
foreach ($urls as $url) {
    $body = fx_fetch($url, 4);
    if ($body !== null) break;
}

if ($body === null) {
    // Don't cache failures — next request will retry. Return a minimal
    // structure so the frontend shows placeholders instead of crashing.
    http_response_code(502);
    echo json_encode([
        'base'  => 'USD',
        'date'  => date('Y-m-d'),
        'rates' => new stdClass(),
        'error' => 'upstream_unavailable',
    ]);
    exit;
}

$decoded = json_decode($body, true);
if (!is_array($decoded) || empty($decoded['rates'])) {
    http_response_code(502);
    echo json_encode(['base' => 'USD', 'date' => date('Y-m-d'), 'rates' => new stdClass(), 'error' => 'bad_upstream']);
    exit;
}

// Normalise to the original payload shape before caching.
$out = json_encode([
    'base'  => 'USD',
    'date'  => $decoded['date'] ?? date('Y-m-d'),
    'rates' => $decoded['rates'],
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
