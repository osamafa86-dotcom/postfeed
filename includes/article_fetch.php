<?php
/**
 * Fetch the raw HTML of a URL.
 * Uses a real browser User-Agent + headers because many Arabic news sites
 * block generic bots. Retries once with SSL verification off if the first
 * attempt fails — some shared-hosting CA bundles are out of date.
 */
function fetchUrlHtml($url) {
    if (empty($url)) return '';
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: ar,en-US;q=0.9,en;q=0.8',
        'Cache-Control: no-cache',
    ];

    $attempt = function($verify) use ($url, $ua, $headers) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_ENCODING => '',
        ]);
        $html = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($html && $http >= 200 && $http < 400) ? $html : '';
    };

    $html = $attempt(true);
    if ($html === '') $html = $attempt(false);
    return $html;
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

    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
    $hdrs = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: ar,en-US;q=0.9,en;q=0.8',
    ];
    $addHandle = function($url) use (&$handles, &$urlOf, $multi, $ua, $hdrs) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
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

/**
 * Extract the article body text from a page HTML.
 *
 * Strategy:
 *   1. Strip noise (script/style/nav/etc.)
 *   2. Pick the container most likely to be the article:
 *      <article>, then <main>, then any div/section whose class or id
 *      contains article|content|entry|post|story|body|news|text.
 *   3. From that container, take every <p>/<div> paragraph with enough
 *      text. Arabic text is often inside plain <div>s or uses non-standard
 *      wrappers, so we also accept those.
 *   4. Fall back to scanning the whole document if the container didn't
 *      yield enough.
 *   5. Return up to 6 paragraphs (min 2) — the article page renderer
 *      will show whatever we return, even if it's just 2 paragraphs.
 */
function fetchArticleBodyFromHtml($html) {
    if (empty($html)) return '';

    // Kill noise once up-front
    $clean = preg_replace('#<(script|style|nav|aside|header|footer|form|iframe|noscript)[^>]*>.*?</\1>#is', '', $html);
    if ($clean === null) $clean = $html;
    // Remove HTML comments too
    $clean = preg_replace('#<!--.*?-->#s', '', $clean) ?: $clean;

    // Step 1: locate article container (best-effort)
    $container = '';
    if (preg_match('#<article\b[^>]*>(.*?)</article>#is', $clean, $m)) {
        $container = $m[1];
    } elseif (preg_match('#<main\b[^>]*>(.*?)</main>#is', $clean, $m)) {
        $container = $m[1];
    }
    if ($container === '') {
        // Look for divs/sections with article-ish class or id
        if (preg_match_all(
            '#<(?:div|section)\b[^>]*(?:class|id)\s*=\s*["\'][^"\']*(?:article-?(?:body|content|text)|entry-?(?:content|body)|post-?(?:content|body)|story-?(?:body|content)|news-?(?:body|content)|content-?body|single-?content|rich-?text|prose|mainContent|the-?content)[^"\']*["\'][^>]*>#i',
            $clean, $starts, PREG_OFFSET_CAPTURE
        )) {
            // For each match, take the first ~25KB of content after it —
            // regex can't match balanced tags, so we just grab a window.
            $best = '';
            foreach ($starts[0] as $hit) {
                $offset = $hit[1];
                $window = mb_substr($clean, $offset, 30000);
                if (mb_strlen($window) > mb_strlen($best)) $best = $window;
            }
            $container = $best;
        }
    }
    if ($container === '') $container = $clean;

    // Step 2: collect paragraph-like blocks
    $paragraphs = _extractParagraphs($container);

    // Step 3: if container-based extraction was thin, retry on the full doc
    if (count($paragraphs) < 3) {
        $fullParas = _extractParagraphs($clean);
        if (count($fullParas) > count($paragraphs)) $paragraphs = $fullParas;
    }

    // Require at least 2 paragraphs so we always display something more
    // substantial than the excerpt when we can. The caller falls back to
    // the excerpt if we return ''.
    if (count($paragraphs) < 2) return '';

    // Cap at 6 paragraphs
    $paragraphs = array_slice($paragraphs, 0, 6);
    $out = '';
    foreach ($paragraphs as $p) {
        $out .= '<p>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";
    }
    return $out;
}

/**
 * Pull every <p> / <div> text block with enough characters out of $html.
 * De-duplicates and preserves order. Internal helper for fetchArticleBodyFromHtml.
 */
function _extractParagraphs($html) {
    $out  = [];
    $seen = [];

    // Primary: <p> tags
    if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $matches)) {
        foreach ($matches[1] as $raw) {
            $text = _cleanParaText($raw);
            if ($text === '' || isset($seen[$text])) continue;
            // Require ~25 chars — Arabic news paragraphs can be shorter than
            // English ones, especially for the lead sentence.
            if (mb_strlen($text) < 25) continue;
            // Skip obvious chrome/menu/UI text
            if (preg_match('#©|كل الحقوق|Copyright|tags:|keywords?:|اقرأ\s*أيض|related\s+articles?#i', $text)) continue;
            $out[] = $text;
            $seen[$text] = true;
        }
    }

    // Secondary: plain text <div>s (only if we still don't have enough)
    if (count($out) < 3 && preg_match_all('#<div\b[^>]*>([^<]{80,})</div>#is', $html, $matches)) {
        foreach ($matches[1] as $raw) {
            $text = _cleanParaText($raw);
            if ($text === '' || isset($seen[$text])) continue;
            if (mb_strlen($text) < 60) continue;
            $out[] = $text;
            $seen[$text] = true;
        }
    }

    return $out;
}

function _cleanParaText($raw) {
    $text = strip_tags($raw);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}
