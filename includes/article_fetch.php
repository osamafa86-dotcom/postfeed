<?php
/**
 * Fetch the raw HTML of a URL.
 */
function fetchUrlHtml($url) {
    if (empty($url)) return '';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NewsFlow/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html ?: '';
}

/**
 * Fetch many URLs in parallel with a concurrency cap.
 * Returns [url => html] map (empty string on failure).
 */
function fetchUrlsHtmlMulti(array $urls, $concurrency = 10) {
    $urls = array_values(array_unique(array_filter($urls)));
    $results = [];
    if (empty($urls)) return $results;

    $total = count($urls);
    $concurrency = max(1, min($concurrency, $total));
    $multi = curl_multi_init();
    $handles = [];
    $urlOf = [];
    $idx = 0;

    $addHandle = function($url) use (&$handles, &$urlOf, $multi) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NewsFlow/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
        ]);
        $key = (int) $ch;
        $handles[$key] = $ch;
        $urlOf[$key] = $url;
        curl_multi_add_handle($multi, $ch);
    };

    // Seed initial batch
    while ($idx < $concurrency && $idx < $total) {
        $addHandle($urls[$idx++]);
    }

    $active = null;
    do {
        do {
            $status = curl_multi_exec($multi, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($active) curl_multi_select($multi, 1.0);

        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $key = (int) $ch;
            $url = $urlOf[$key] ?? '';
            $body = curl_multi_getcontent($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $results[$url] = ($http >= 200 && $http < 400 && $body) ? $body : '';

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            unset($handles[$key], $urlOf[$key]);

            if ($idx < $total) {
                $addHandle($urls[$idx++]);
                $active = 1;
            }
        }
    } while ($active && $status === CURLM_OK);

    curl_multi_close($multi);
    return $results;
}

/**
 * Extract a representative image from article HTML.
 * Tries og:image, twitter:image, link rel=image_src, then first sizable <img>.
 */
function extractArticleImage($html, $baseUrl = '') {
    if (empty($html)) return '';
    $patterns = [
        '#<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']#i',
        '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']#i',
        '#<meta[^>]+name=["\']twitter:image(?::src)?["\'][^>]+content=["\']([^"\']+)["\']#i',
        '#<link[^>]+rel=["\']image_src["\'][^>]+href=["\']([^"\']+)["\']#i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $html, $m)) return absoluteUrl(trim($m[1]), $baseUrl);
    }
    // Fallback: first <img> inside <article> or whole page
    $scope = $html;
    if (preg_match('#<article[^>]*>(.*?)</article>#is', $html, $mm)) $scope = $mm[1];
    if (preg_match_all('#<img[^>]+src=["\']([^"\']+)["\'][^>]*>#i', $scope, $imgs)) {
        foreach ($imgs[1] as $src) {
            if (preg_match('#(sprite|icon|logo|blank|pixel|1x1|spacer)\.#i', $src)) continue;
            if (preg_match('#data:image#i', $src)) continue;
            return absoluteUrl($src, $baseUrl);
        }
    }
    return '';
}

function absoluteUrl($url, $base) {
    if (empty($url)) return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    if (strpos($url, '//') === 0) return 'https:' . $url;
    if (empty($base)) return $url;
    $parts = parse_url($base);
    if (!$parts) return $url;
    $scheme = $parts['scheme'] ?? 'https';
    $host   = $parts['host'] ?? '';
    if ($url[0] === '/') return $scheme . '://' . $host . $url;
    return $scheme . '://' . $host . '/' . ltrim($url, '/');
}

/**
 * Fetch full article body (≥3 paragraphs) from a source URL.
 */
function fetchArticleBody($url) {
    $html = fetchUrlHtml($url);
    return fetchArticleBodyFromHtml($html);
}

function fetchArticleBodyFromHtml($html) {
    if (empty($html)) return '';

    $body = $html;
    if (preg_match('#<article[^>]*>(.*?)</article>#is', $html, $m)) {
        $body = $m[1];
    } elseif (preg_match('#<div[^>]*class="[^"]*(?:article|content|entry|post)[^"]*"[^>]*>(.*?)</div>\s*</div>#is', $html, $m)) {
        $body = $m[1];
    }

    $body = preg_replace('#<(script|style|nav|aside|header|footer|form|iframe)[^>]*>.*?</\1>#is', '', $body);

    if (!preg_match_all('#<p[^>]*>(.*?)</p>#is', $body, $matches)) return '';

    $paragraphs = [];
    foreach ($matches[1] as $p) {
        $text = trim(strip_tags($p));
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        if (mb_strlen($text) < 40) continue;
        $paragraphs[] = $text;
        if (count($paragraphs) >= 6) break;
    }
    if (count($paragraphs) < 3) return '';

    $out = '';
    foreach ($paragraphs as $p) {
        $out .= '<p>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";
    }
    return $out;
}
