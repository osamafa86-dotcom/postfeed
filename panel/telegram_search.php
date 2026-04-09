<?php
/**
 * Telegram channel search endpoint — searches by name/keyword (Arabic or
 * English) using DuckDuckGo's HTML search as a free, key-less data source.
 *
 * Usage: GET telegram_search.php?q=<name>
 * Response JSON:
 *   { ok: true, query, results: [ { username, url } ... ] }
 *   { ok: false, error: "..." }
 *
 * Defensive: always returns JSON even on PHP errors or expired session.
 */

// Lock output to JSON BEFORE anything that could emit a warning/notice.
ini_set('display_errors', '0');
error_reporting(0);
while (ob_get_level() > 0) { ob_end_clean(); }
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function tgs_json_exit(array $p): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

register_shutdown_function(function(){
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Server error: ' . $err['message']], JSON_UNESCAPED_UNICODE);
    }
});

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/functions.php';
} catch (Throwable $e) {
    tgs_json_exit(['ok' => false, 'error' => 'Init error: ' . $e->getMessage()]);
}

if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if (!isAdmin()) {
    tgs_json_exit(['ok' => false, 'error' => 'انتهت الجلسة. أعد تسجيل الدخول.']);
}

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    tgs_json_exit(['ok' => true, 'query' => $q, 'results' => []]);
}

/** Fetch a URL with a browser-ish UA. Returns [html, httpCode, curlErr]. */
function tgs_fetch(string $url, array $postFields = [], array $extraHeaders = []): array {
    $ch = curl_init($url);
    $headers = array_merge([
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ], $extraHeaders);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING       => '', // accept gzip/deflate transparently
    ];
    if (!empty($postFields)) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($postFields);
    }
    curl_setopt_array($ch, $opts);
    $html    = curl_exec($ch);
    $code    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    return [is_string($html) ? $html : '', $code, $curlErr];
}

/**
 * Extract Telegram channel usernames from an HTML blob. Matches:
 *   - t.me/username        (plain)
 *   - t.me/s/username      (preview URL)
 *   - telegram.me/username
 *   - tgstat.com/channel/@username  (so tgstat result pages also work)
 *   - URL-encoded variants (e.g. inside DuckDuckGo's uddg= redirect)
 */
function tgs_extract_usernames(string $html): array {
    $reserved = ['s', 'iv', 'share', 'joinchat', 'joinchannel', 'proxy', 'socks', 'addstickers', 'setlanguage', 'contact', 'c', 'channel'];
    $found = [];
    $patterns = [
        '~(?:https?(?:%3A|:)(?://|%2F%2F)?)?(?:t|telegram)\.me(?:%2F|/)(?:s(?:%2F|/))?([a-zA-Z][a-zA-Z0-9_]{4,31})~i',
        '~tgstat\.com/(?:[a-z]{2}/)?channel/@([a-zA-Z][a-zA-Z0-9_]{4,31})~i',
    ];
    foreach ($patterns as $pat) {
        if (preg_match_all($pat, $html, $mm)) {
            foreach ($mm[1] as $u) {
                $u = strtolower($u);
                if (in_array($u, $reserved, true)) continue;
                if (isset($found[$u])) continue;
                $found[$u] = true;
            }
        }
    }
    return array_keys($found);
}

/**
 * Try multiple search backends in order. The first one that returns a 200
 * response with at least one t.me match wins. If all fail we surface the
 * last HTTP code so the client can render a helpful error.
 */
function tgs_search_backends(string $q): array {
    $tried = [];
    $encQ = urlencode($q);
    $encSite = urlencode('site:t.me ' . $q);

    // --- 1) Bing HTML — generally tolerant of scraping w/ browser UA
    [$html, $code] = tgs_fetch('https://www.bing.com/search?q=' . $encSite . '&setmkt=en-US&setlang=en');
    $tried[] = ['backend' => 'bing', 'http_code' => $code, 'size' => strlen($html)];
    if ($code === 200 && $html !== '') {
        $names = tgs_extract_usernames($html);
        if (!empty($names)) return ['names' => $names, 'backend' => 'bing', 'tried' => $tried];
    }

    // --- 2) tgstat.com — dedicated Telegram directory, indexes Arabic names
    [$html, $code] = tgs_fetch('https://tgstat.com/search?query=' . $encQ);
    $tried[] = ['backend' => 'tgstat', 'http_code' => $code, 'size' => strlen($html)];
    if ($code === 200 && $html !== '') {
        $names = tgs_extract_usernames($html);
        if (!empty($names)) return ['names' => $names, 'backend' => 'tgstat', 'tried' => $tried];
    }

    // --- 3) tgstat.com English variant
    [$html, $code] = tgs_fetch('https://tgstat.com/en/search?query=' . $encQ);
    $tried[] = ['backend' => 'tgstat-en', 'http_code' => $code, 'size' => strlen($html)];
    if ($code === 200 && $html !== '') {
        $names = tgs_extract_usernames($html);
        if (!empty($names)) return ['names' => $names, 'backend' => 'tgstat-en', 'tried' => $tried];
    }

    // --- 4) DuckDuckGo HTML (POST form) — last resort, often rate-limited (202)
    [$html, $code] = tgs_fetch(
        'https://html.duckduckgo.com/html/',
        ['q' => 'site:t.me ' . $q, 'b' => '', 'kl' => 'wt-wt'],
        ['Referer: https://duckduckgo.com/', 'Origin: https://duckduckgo.com']
    );
    $tried[] = ['backend' => 'ddg-post', 'http_code' => $code, 'size' => strlen($html)];
    if ($code === 200 && $html !== '') {
        $names = tgs_extract_usernames($html);
        if (!empty($names)) return ['names' => $names, 'backend' => 'ddg-post', 'tried' => $tried];
    }

    // --- 5) Startpage HTML
    [$html, $code] = tgs_fetch('https://www.startpage.com/do/search?query=' . $encSite . '&cat=web');
    $tried[] = ['backend' => 'startpage', 'http_code' => $code, 'size' => strlen($html)];
    if ($code === 200 && $html !== '') {
        $names = tgs_extract_usernames($html);
        if (!empty($names)) return ['names' => $names, 'backend' => 'startpage', 'tried' => $tried];
    }

    return ['names' => [], 'backend' => null, 'tried' => $tried];
}

try {
    $debug = !empty($_GET['debug']);
    $result = tgs_search_backends($q);

    if ($debug) {
        tgs_json_exit([
            'ok'      => true,
            'debug'   => true,
            'query'   => $q,
            'backend' => $result['backend'],
            'tried'   => $result['tried'],
            'count'   => count($result['names']),
            'names'   => $result['names'],
        ]);
    }

    if (empty($result['names'])) {
        // Report the worst HTTP code we saw (for diagnostics)
        $codes = array_map(function($t){ return $t['http_code']; }, $result['tried']);
        $codesTxt = implode('/', $codes);
        tgs_json_exit([
            'ok'    => false,
            'error' => 'لم نعثر على نتائج من أي محرّك بحث (HTTP ' . $codesTxt . '). جرّب لصق الرابط مباشرةً في التبويب الآخر.',
            'tried' => $result['tried'],
        ]);
    }

    $names = array_slice($result['names'], 0, 12);
    $results = [];
    foreach ($names as $u) {
        $results[] = [
            'username' => $u,
            'url'      => 'https://t.me/' . $u,
        ];
    }

    tgs_json_exit([
        'ok'      => true,
        'query'   => $q,
        'backend' => $result['backend'],
        'results' => $results,
    ]);

} catch (Throwable $e) {
    tgs_json_exit(['ok' => false, 'error' => 'خطأ داخلي: ' . $e->getMessage()]);
}
