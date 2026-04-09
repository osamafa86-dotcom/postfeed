<?php
/**
 * RSS / site discovery helper.
 *
 * Given a website name or URL, tries to:
 *   1. Resolve it to a real site URL (via DuckDuckGo HTML search if it's a name)
 *   2. Find its RSS/Atom feed URL by parsing <link rel="alternate"> tags and
 *      falling back to common paths like /rss, /feed, /feed.xml, /atom.xml...
 *   3. Extract metadata from the feed (title, description) and the site
 *      (favicon) so the admin form can be pre-filled.
 *
 * All remote calls go through fetchUrlHtml() with a short timeout so the
 * panel request stays responsive.
 */

require_once __DIR__ . '/article_fetch.php';

/**
 * Check whether a string looks like a URL we can fetch directly.
 */
function rssd_looks_like_url(string $s): bool {
    $s = trim($s);
    if ($s === '') return false;
    if (preg_match('#^https?://#i', $s)) return true;
    // Bare domain like "aljazeera.net" or "example.co.uk"
    if (preg_match('#^[a-z0-9][a-z0-9\-\.]*\.[a-z]{2,}(/.*)?$#i', $s)) return true;
    return false;
}

/**
 * Normalize user input into a fetchable URL.
 */
function rssd_normalize_url(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    if (!preg_match('#^https?://#i', $s)) {
        $s = 'https://' . ltrim($s, '/');
    }
    return $s;
}

/**
 * Search DuckDuckGo HTML for the first result matching a free-text query
 * (e.g. an Arabic or English site name). Returns a URL or ''.
 *
 * DuckDuckGo's HTML endpoint (html.duckduckgo.com/html/) does not require
 * JavaScript and is friendly to scraping for light use.
 */
function rssd_search_for_site(string $query): string {
    $query = trim($query);
    if ($query === '') return '';

    $endpoint = 'https://html.duckduckgo.com/html/?q=' . rawurlencode($query);
    $html = fetchUrlHtml($endpoint);
    if (!$html) return '';

    // DuckDuckGo HTML results wrap the target URL in a redirect like
    //   //duckduckgo.com/l/?uddg=<url-encoded>
    // Grab the first one.
    if (preg_match('#<a[^>]+class="result__a"[^>]+href="([^"]+)"#i', $html, $m)) {
        $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        if (preg_match('#[?&]uddg=([^&]+)#', $href, $m2)) {
            return rawurldecode($m2[1]);
        }
        if (preg_match('#^https?://#i', $href)) return $href;
    }
    // Fallback: any plain external link in the results list.
    if (preg_match('#<a[^>]+href="(https?://[^"]+)"[^>]*>#i', $html, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Turn a full URL into its https://host root.
 */
function rssd_site_root(string $url): string {
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return '';
    $scheme = $p['scheme'] ?? 'https';
    return $scheme . '://' . $p['host'];
}

/**
 * Resolve a URL relative to a base (for <link href="/rss"> style tags).
 */
function rssd_absolute_url(string $href, string $baseUrl): string {
    $href = trim($href);
    if ($href === '') return '';
    if (preg_match('#^https?://#i', $href)) return $href;
    if (strpos($href, '//') === 0) {
        $p = parse_url($baseUrl);
        return (($p['scheme'] ?? 'https') . ':' . $href);
    }
    $root = rssd_site_root($baseUrl);
    if ($href[0] === '/') return $root . $href;
    // Relative to directory of base
    $dir = rtrim(preg_replace('#/[^/]*$#', '', $baseUrl), '/');
    return $dir . '/' . $href;
}

/**
 * Parse HTML and return every RSS/Atom feed candidate URL we can find in
 * <link rel="alternate"> tags.
 */
function rssd_extract_feed_links(string $html, string $baseUrl): array {
    $out = [];
    if (!$html) return $out;
    if (preg_match_all('#<link\b[^>]+>#i', $html, $matches)) {
        foreach ($matches[0] as $tag) {
            if (!preg_match('#rel\s*=\s*["\']?alternate["\']?#i', $tag)) continue;
            if (!preg_match('#type\s*=\s*["\']?(application/(rss|atom)\+xml|application/xml|text/xml)["\']?#i', $tag)) continue;
            if (!preg_match('#href\s*=\s*"([^"]+)"#i', $tag, $hm)
             && !preg_match("#href\s*=\s*'([^']+)'#i", $tag, $hm)) continue;
            $href = html_entity_decode($hm[1], ENT_QUOTES | ENT_HTML5);
            $abs  = rssd_absolute_url($href, $baseUrl);
            if ($abs) $out[] = $abs;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Fetch a URL and confirm the response body looks like an RSS or Atom feed.
 */
function rssd_is_valid_feed(string $url): bool {
    $body = fetchUrlHtml($url);
    if (!$body) return false;
    $head = ltrim(mb_substr($body, 0, 2000));
    if (stripos($head, '<?xml') === false && stripos($head, '<rss') === false && stripos($head, '<feed') === false) {
        return false;
    }
    return (stripos($body, '<rss') !== false)
        || (stripos($body, '<feed') !== false && stripos($body, 'xmlns') !== false)
        || (stripos($body, '<channel') !== false);
}

/**
 * Try a list of well-known feed paths under the site root.
 */
function rssd_probe_common_paths(string $siteUrl): string {
    $root = rssd_site_root($siteUrl);
    if (!$root) return '';
    $paths = [
        '/feed', '/rss', '/rss.xml', '/feed.xml', '/atom.xml',
        '/index.xml', '/feeds/posts/default', '/?feed=rss2',
    ];
    foreach ($paths as $p) {
        $candidate = $root . $p;
        if (rssd_is_valid_feed($candidate)) return $candidate;
    }
    return '';
}

/**
 * Pull a clean title and description out of an RSS/Atom feed body.
 */
function rssd_parse_feed_meta(string $feedUrl): array {
    $body = fetchUrlHtml($feedUrl);
    $meta = ['title' => '', 'description' => '', 'link' => ''];
    if (!$body) return $meta;
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_clear_errors();
    if (!$xml) return $meta;

    if (isset($xml->channel)) { // RSS
        $meta['title']       = trim((string)($xml->channel->title ?? ''));
        $meta['description'] = trim((string)($xml->channel->description ?? ''));
        $meta['link']        = trim((string)($xml->channel->link ?? ''));
    } else { // Atom
        $meta['title']       = trim((string)($xml->title ?? ''));
        $meta['description'] = trim((string)($xml->subtitle ?? ''));
        if (isset($xml->link)) {
            foreach ($xml->link as $l) {
                $rel  = (string)$l['rel'];
                $href = (string)$l['href'];
                if ($href && ($rel === '' || $rel === 'alternate')) { $meta['link'] = $href; break; }
            }
        }
    }
    return $meta;
}

/**
 * Extract the site's favicon URL from its HTML head, or fall back to
 * /favicon.ico under the root.
 */
function rssd_extract_favicon(string $html, string $baseUrl): string {
    if ($html && preg_match_all('#<link\b[^>]+>#i', $html, $matches)) {
        foreach ($matches[0] as $tag) {
            if (!preg_match('#rel\s*=\s*["\']?(?:shortcut\s+)?icon["\']?#i', $tag)) continue;
            if (preg_match('#href\s*=\s*"([^"]+)"#i', $tag, $hm)
             || preg_match("#href\s*=\s*'([^']+)'#i", $tag, $hm)) {
                return rssd_absolute_url(html_entity_decode($hm[1], ENT_QUOTES | ENT_HTML5), $baseUrl);
            }
        }
    }
    $root = rssd_site_root($baseUrl);
    return $root ? ($root . '/favicon.ico') : '';
}

/**
 * Build a URL-friendly slug from the site name.
 */
function rssd_slugify(string $name, string $fallbackHost = ''): string {
    $name = trim($name);
    // Slug must be ASCII-only for the Apache rewrite rules, so strip any
    // non-ASCII letters (Arabic, etc.) and fall back to the host when the
    // name only contains non-ASCII characters.
    $s = strtolower(preg_replace('#[^a-z0-9]+#i', '-', $name) ?? '');
    $s = trim($s, '-');
    if ($s === '' && $fallbackHost !== '') {
        $host = preg_replace('#^www\.#i', '', $fallbackHost);
        $host = preg_replace('#\..*$#', '', $host);
        $s = strtolower(preg_replace('#[^a-z0-9]+#i', '-', $host) ?? '');
        $s = trim($s, '-');
    }
    if ($s === '') $s = 'source-' . substr(md5($name . $fallbackHost), 0, 6);
    return $s;
}

/**
 * Main entry point: given a URL or a site name, return everything the
 * admin panel needs to pre-fill the form.
 *
 * Returns:
 *   ['ok' => bool, 'error' => string,
 *    'site_url' => string, 'feed_url' => string,
 *    'name' => string, 'description' => string,
 *    'slug' => string, 'logo_letter' => string, 'favicon' => string]
 */
function rssd_discover(string $query): array {
    $out = [
        'ok' => false, 'error' => '',
        'site_url' => '', 'feed_url' => '',
        'name' => '', 'description' => '',
        'slug' => '', 'logo_letter' => '', 'favicon' => '',
    ];

    $query = trim($query);
    if ($query === '') {
        $out['error'] = 'أدخل اسماً أو رابطاً';
        return $out;
    }

    // Step 1: resolve the query to a site URL
    if (rssd_looks_like_url($query)) {
        $siteUrl = rssd_normalize_url($query);
    } else {
        $siteUrl = rssd_search_for_site($query);
        if (!$siteUrl) {
            $out['error'] = 'ما قدرنا نلاقي الموقع. جرّب تدخل الرابط مباشرة.';
            return $out;
        }
    }

    // Step 2: fetch the homepage and look for feed link tags
    $html = fetchUrlHtml($siteUrl);
    if (!$html) {
        // If the user typed e.g. "example.com/some/path" and it 404s, try the root
        $root = rssd_site_root($siteUrl);
        if ($root && $root !== $siteUrl) {
            $html = fetchUrlHtml($root);
            if ($html) $siteUrl = $root;
        }
    }

    $feedUrl = '';
    if ($html) {
        $candidates = rssd_extract_feed_links($html, $siteUrl);
        foreach ($candidates as $c) {
            if (rssd_is_valid_feed($c)) { $feedUrl = $c; break; }
        }
    }

    // Step 3: if the homepage didn't declare a feed, probe common paths
    if (!$feedUrl) {
        $feedUrl = rssd_probe_common_paths($siteUrl);
    }

    if (!$feedUrl) {
        // Still populate what we can so the admin can finish manually
        $out['site_url'] = $siteUrl;
        $host = parse_url($siteUrl, PHP_URL_HOST) ?: '';
        $out['name']        = preg_replace('#^www\.#i', '', $host);
        $out['slug']        = rssd_slugify('', $host);
        $out['logo_letter'] = mb_strtoupper(mb_substr($out['name'], 0, 1));
        $out['favicon']     = rssd_extract_favicon($html ?? '', $siteUrl);
        $out['error']       = 'لم نجد RSS تلقائياً — عبّي الحقل يدوياً.';
        return $out;
    }

    // Step 4: pull feed meta + favicon
    $fm = rssd_parse_feed_meta($feedUrl);
    $name = $fm['title'] ?: parse_url($siteUrl, PHP_URL_HOST);
    $name = preg_replace('#^www\.#i', '', (string)$name);
    $host = parse_url($siteUrl, PHP_URL_HOST) ?: '';

    $out['ok']          = true;
    $out['site_url']    = $fm['link'] ?: $siteUrl;
    $out['feed_url']    = $feedUrl;
    $out['name']        = $name;
    $out['description'] = mb_substr($fm['description'], 0, 500);
    $out['slug']        = rssd_slugify($name, $host);
    $out['logo_letter'] = mb_strtoupper(mb_substr($name, 0, 1));
    $out['favicon']     = rssd_extract_favicon($html ?? '', $out['site_url']);
    return $out;
}
