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
function tgs_fetch(string $url, array $postFields = []): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
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
 * Extract Telegram channel usernames from an HTML blob by finding every
 * t.me/{name} link, filtering out reserved paths, and deduplicating.
 */
function tgs_extract_usernames(string $html): array {
    $reserved = ['s', 'iv', 'share', 'joinchat', 'joinchannel', 'proxy', 'socks', 'addstickers', 'setlanguage', 'contact', 'c'];
    $found = [];
    // Match any occurrence of t.me/username (with optional /s/ prefix) in hrefs,
    // in plain text, and inside DuckDuckGo's uddg= redirect parameter.
    if (preg_match_all('~(?:https?(?:%3A|:)(?://|%2F%2F)?)?t\.me(?:%2F|/)(?:s(?:%2F|/))?([a-zA-Z][a-zA-Z0-9_]{4,31})~i', $html, $mm)) {
        foreach ($mm[1] as $u) {
            $u = strtolower($u);
            if (in_array($u, $reserved, true)) continue;
            if (isset($found[$u])) continue;
            $found[$u] = true;
        }
    }
    return array_keys($found);
}

try {
    // DuckDuckGo HTML search: site:t.me/{query}
    // Use the POST form endpoint (more reliable than GET) plus a fallback.
    $searchQuery = 'site:t.me ' . $q;
    [$html, $code, $curlErr] = tgs_fetch(
        'https://html.duckduckgo.com/html/',
        ['q' => $searchQuery, 'b' => '', 'kl' => 'wt-wt']
    );

    if ($code !== 200 || $html === '') {
        // Fallback: GET-style URL
        [$html, $code, $curlErr] = tgs_fetch(
            'https://html.duckduckgo.com/html/?q=' . urlencode($searchQuery)
        );
    }

    if ($code !== 200 || $html === '') {
        tgs_json_exit([
            'ok'    => false,
            'error' => 'تعذّر الوصول لمحرك البحث (HTTP ' . $code . '). جرّب لصق الرابط في التبويب الآخر.',
        ]);
    }

    $usernames = tgs_extract_usernames($html);
    // Limit to top 12 to keep the UI clean.
    $usernames = array_slice($usernames, 0, 12);

    $results = [];
    foreach ($usernames as $u) {
        $results[] = [
            'username' => $u,
            'url'      => 'https://t.me/' . $u,
        ];
    }

    tgs_json_exit([
        'ok'      => true,
        'query'   => $q,
        'results' => $results,
    ]);

} catch (Throwable $e) {
    tgs_json_exit(['ok' => false, 'error' => 'خطأ داخلي: ' . $e->getMessage()]);
}
