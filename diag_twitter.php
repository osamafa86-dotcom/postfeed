<?php
/**
 * Twitter transport diagnostic. Hits both syndication endpoints with
 * the Origin/Referer headers and reports what we actually got back —
 * HTTP code, response size, presence of __NEXT_DATA__, and whether
 * the parsed entries array is empty or populated.
 *
 * Run: php diag_twitter.php [username]   (default: AJArabic)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$username = $argv[1] ?? 'AJArabic';
echo "=== فحص جلب تغريدات @{$username} ===\n\n";

// Match what tw_http_get sends.
$baseHeaders = [
    'Accept: text/html,application/json,application/xhtml+xml',
    'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
    'Cache-Control: no-cache, no-store, must-revalidate',
    'Pragma: no-cache',
];
$originHeaders = array_merge($baseHeaders, [
    'Origin: https://publish.twitter.com',
    'Referer: https://publish.twitter.com/',
    'Cookie: dnt=1',
]);
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

function probe(string $label, string $url, array $headers, string $ua): void {
    echo "--- {$label} ---\n";
    echo "URL: {$url}\n";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $err  = curl_error($ch);
    curl_close($ch);

    echo "HTTP: {$code}\n";
    echo "time: " . round($time, 2) . "s\n";
    echo "size: " . (is_string($body) ? strlen($body) : 0) . " bytes\n";
    if ($err) echo "curl error: {$err}\n";

    if (!$body) { echo "(no body)\n\n"; return; }

    // Try __NEXT_DATA__ extraction.
    if (preg_match('#<script[^>]+id="__NEXT_DATA__"[^>]*>(.+?)</script>#s', $body, $m)) {
        $data = json_decode($m[1], true);
        if (!is_array($data)) {
            echo "❌ __NEXT_DATA__ موجود لكن json_decode فشل\n\n";
            return;
        }
        $entriesPaths = [
            'props.pageProps.timeline.entries' =>
                $data['props']['pageProps']['timeline']['entries'] ?? null,
            'props.pageProps.contextProvider.initialState.timeline.entries' =>
                $data['props']['pageProps']['contextProvider']['initialState']['timeline']['entries'] ?? null,
        ];
        foreach ($entriesPaths as $path => $entries) {
            if (is_array($entries)) {
                echo "✓ {$path}: " . count($entries) . " entry\n";
            } else {
                echo "✗ {$path}: غير موجود/null\n";
            }
        }
        // Show top-level keys + first 200 chars of pageProps.
        echo "props.pageProps keys: " . implode(', ', array_slice(array_keys($data['props']['pageProps'] ?? []), 0, 12)) . "\n";
        $userObj = $data['props']['pageProps']['user'] ?? null;
        if (is_array($userObj)) {
            echo "user keys: " . implode(', ', array_slice(array_keys($userObj), 0, 12)) . "\n";
        }
    } else {
        // JSON response (cdn.syndication)?
        $j = json_decode($body, true);
        if (is_array($j)) {
            echo "✓ JSON parse ok — top keys: " . implode(', ', array_slice(array_keys($j), 0, 10)) . "\n";
            foreach (['body', 'tweets', 'timeline'] as $k) {
                if (isset($j[$k])) {
                    $count = is_array($j[$k]) ? count($j[$k]) : 'not-array';
                    echo "  {$k}: {$count}\n";
                }
            }
        } else {
            echo "❌ ما في __NEXT_DATA__ ولا JSON صالح\n";
            echo "snippet: " . mb_substr(strip_tags($body), 0, 240) . "\n";
        }
    }
    echo "\n";
}

// Test 1: syndication.twitter.com WITHOUT Origin (current baseline check)
probe(
    'syndication.twitter.com — بدون Origin',
    'https://syndication.twitter.com/srv/timeline-profile/screen-name/' . rawurlencode($username) . '?_cb=' . time(),
    $baseHeaders,
    $ua
);

// Test 2: syndication.twitter.com WITH Origin (the fix)
probe(
    'syndication.twitter.com — مع Origin/Referer/Cookie',
    'https://syndication.twitter.com/srv/timeline-profile/screen-name/' . rawurlencode($username) . '?_cb=' . time(),
    $originHeaders,
    $ua
);

// Test 3: cdn.syndication.twimg.com (different host)
probe(
    'cdn.syndication.twimg.com — مع Origin',
    'https://cdn.syndication.twimg.com/timeline/profile?screen_name=' . rawurlencode($username) . '&with_replies=false&suppress_response_codes=true&lang=en&_cb=' . time(),
    $originHeaders,
    $ua
);

// Test 4: try syndication with showcase=false param (some forks use this)
probe(
    'syndication مع showcase=false',
    'https://syndication.twitter.com/srv/timeline-profile/screen-name/' . rawurlencode($username) . '?showcase=false&with_replies=false&_cb=' . time(),
    $originHeaders,
    $ua
);
