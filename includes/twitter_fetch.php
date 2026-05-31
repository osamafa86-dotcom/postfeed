<?php
/**
 * Twitter/X feed fetcher — pulls recent tweets from public profiles via
 * Twitter's own embed syndication infrastructure (same one used by the
 * "Follow on X" widget). No API key or paid plan required.
 *
 * Two fallback transports:
 *   1) https://cdn.syndication.twimg.com/timeline/profile (JSON first)
 *   2) https://syndication.twitter.com/srv/timeline-profile/screen-name/{user}
 *      (HTML with __NEXT_DATA__ embedded JSON)
 *
 * If both fail the function returns [] — no hard error so the homepage
 * section just keeps showing whatever is already in the DB.
 *
 * Behavior notes:
 *   - Pinned tweets are dropped from the chronological list so an old
 *     pinned tweet can't shadow newer posts at the top of the feed.
 *   - Retweets are dropped — we only want original content.
 *   - Results are sorted by created_at descending before return, so
 *     however Twitter orders the raw timeline we always surface the
 *     newest tweet first.
 *   - On every failure path we error_log a short reason so operators
 *     can grep the log if the section goes stale.
 */

// Public Nitter-style instances tried in rotation. First one that returns
// parseable RSS wins. Order matters — the budget across all instances is
// only ~10s, so a few dead hosts at the top of the list eat the whole
// window before we reach any live ones.
//
// 2026 state (per public status pages + community trackers):
//   - nitter.poast.org : the most consistently alive public instance,
//     aggressive rate-limiting on its side but still serves RSS
//   - xcancel.com      : non-Nitter fork, separate operator, kept up
//   - lightbrd.com     : non-Nitter fork, similar
//   - everything else  : dying or dead. Kept as low-priority fallbacks
//     so a single host coming back doesn't require a deploy to be tried
//
// Instances come and go — if all start failing, refresh this list from
// https://github.com/zedeus/nitter/wiki/Instances.
const TW_NITTER_INSTANCES = [
    'nitter.poast.org',
    'xcancel.com',
    'lightbrd.com',
    'nitter.privacyredirect.com',
    'nitter.space',
    'nitter.tiekoetter.com',
    'nitter.adminforge.de',
    'nitter.privacydev.net',
];

// User-Agent pool — rotated per request so Twitter/Nitter edges can't
// fingerprint and cache a single client identity. Keep these recent
// (within last ~12 months) to look like real traffic.
const TW_USER_AGENTS = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
];

/**
 * Fetch the latest tweets for a single username.
 *
 * Transports in order (most reliable in 2026 first):
 *   1) NEXT_DATA (syndication.twitter.com) — the only consistently
 *      working path. Returns full timelines (~99 tweets) when not
 *      rate-limited.
 *   2) Nitter — almost every public instance is dead or blocked; kept
 *      as a fallback for the day syndication itself goes 429.
 *   3) RSSHub — mostly broken for Twitter, kept for completeness.
 *   4) CDN JSON — dead; last resort.
 *
 * @return array<int, array{tweet_id:string, text:string, image_url:string, posted_at:string, url:string}>
 */
function tw_fetch_user_tweets(string $username, int $limit = 20): array {
    $username = ltrim(trim($username), '@');
    if ($username === '') return [];

    // One-time-per-hour visit to publish.twitter.com so the cookie jar
    // carries the guest_id / personalization_id Twitter's syndication
    // endpoint expects. Without those cookies the timeline payload comes
    // back empty even with HTTP 200 + correct Origin header.
    tw_warmup_session();

    $out = tw_fetch_via_next_data($username, $limit);
    if (!empty($out)) return tw_finalize_tweets($out, $username, $limit);

    $out = tw_fetch_via_nitter($username, $limit);
    if (!empty($out)) return tw_finalize_tweets($out, $username, $limit);

    $out = tw_fetch_via_rsshub($username, $limit);
    if (!empty($out)) return tw_finalize_tweets($out, $username, $limit);

    $out = tw_fetch_via_cdn_json($username, $limit);
    if (!empty($out)) return tw_finalize_tweets($out, $username, $limit);

    return [];
}

/**
 * Transport: Nitter — query every public instance and merge their
 * results, newest wins after dedupe by tweet id. Each Nitter instance
 * runs its own ~10-minute cache of the profile feed, so caches age
 * independently; pooling across instances means whichever one most
 * recently refreshed wins and we get the freshest tweet seen anywhere.
 *
 * Capped at TW_NITTER_TOTAL_SECS total wall-clock across all instances
 * so a cluster of slow hosts can't stall the scraper.
 */
function tw_fetch_via_nitter(string $username, int $limit): array {
    $merged   = [];
    $seenIds  = [];
    $startTs  = microtime(true);
    $budget   = 10; // seconds across all instances, keep scrape fast
    $anyOk    = false;

    foreach (TW_NITTER_INSTANCES as $host) {
        if ((microtime(true) - $startTs) > $budget) break;

        $url  = 'https://' . $host . '/' . rawurlencode($username) . '/rss';
        $xml  = tw_http_get($url, 4);
        if (!$xml) continue;

        $items = tw_parse_rss_feed($xml);
        if (empty($items)) continue;
        $anyOk = true;

        foreach ($items as $item) {
            $id = $item['tweet_id'];
            if (isset($seenIds[$id])) continue;
            $seenIds[$id] = true;
            $merged[] = $item;
        }
    }

    if ($anyOk) {
        error_log('tw_fetch: nitter merged ' . count($merged) . ' unique items for ' . $username);
    } else {
        error_log('tw_fetch: all nitter instances failed for ' . $username);
    }
    return $merged;
}

/**
 * Parse an RSS feed (Nitter or RSSHub shape) into our tweet rows.
 * Items without a /status/NNN link are ignored because they're not
 * tweets (e.g. retweets that Nitter renders differently).
 */
function tw_parse_rss_feed(string $xml): array {
    libxml_use_internal_errors(true);
    $rss = simplexml_load_string($xml);
    libxml_clear_errors();
    if (!$rss || !isset($rss->channel->item)) return [];

    $out = [];
    foreach ($rss->channel->item as $item) {
        $link = (string)$item->link;
        if (!preg_match('#/status/(\d+)#', $link, $m)) continue;
        $tweetId = $m[1];

        $desc = (string)$item->description;

        $image = '';
        if (preg_match('#<img[^>]+src=["\']([^"\']+)["\']#i', $desc, $im)) {
            $image = html_entity_decode($im[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Nitter-style instances (including xcancel.com, lightbrd.com)
            // proxy images through /pic/<url-encoded-path>. Decode and
            // rewrite back to pbs.twimg.com so the image keeps loading
            // if the instance that served the RSS later disappears.
            // Two shapes we see in the wild:
            //   .../pic/media%2FXYZ.jpg?name=orig   (relative path)
            //   .../pic/https%3A%2F%2Fpbs.twimg...  (full URL wrapped)
            if (preg_match('#^https?://[^/]+/pic/(.+)$#', $image, $pm)) {
                $inner = rawurldecode($pm[1]);
                if (preg_match('#^https?://#', $inner)) {
                    $image = $inner;
                } else {
                    $image = 'https://pbs.twimg.com/' . ltrim($inner, '/');
                }
            }
        }

        $text = trim(strip_tags(str_replace(['<br/>', '<br>', '<br />'], "\n", $desc)));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string)preg_replace('#https?://t\.co/\S+$#', '', $text));

        $ts = !empty($item->pubDate) ? strtotime((string)$item->pubDate) : 0;
        $postedAt = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

        if ($text === '' && $image === '') continue;

        // Canonicalize the tweet URL to twitter.com so clicks work
        // regardless of which instance gave us the row.
        $canonLink = preg_replace(
            '#^https?://[^/]+/#',
            'https://twitter.com/',
            $link
        );

        $out[] = [
            'tweet_id'  => $tweetId,
            'text'      => $text,
            'image_url' => $image,
            'posted_at' => $postedAt,
            'url'       => $canonLink,
        ];
    }
    return $out;
}

/**
 * Transport: rsshub.app hosted bridge — returns RSS XML that we parse
 * into our tweet shape. Free, no auth. Twitter route has been broken
 * on rsshub.app for a while so this is more of a "maybe it's back"
 * fallback than a real transport.
 */
function tw_fetch_via_rsshub(string $username, int $limit): array {
    $url = 'https://rsshub.app/twitter/user/' . rawurlencode($username);
    $xml = tw_http_get($url, 15);
    if (!$xml) return [];
    $out = tw_parse_rss_feed($xml);
    if (empty($out)) {
        error_log("tw_fetch: rsshub returned empty for $username");
        return [];
    }
    return $out;
}

/**
 * Sort by posted_at desc and trim to $limit. Also fills the post URL
 * now that the username is known for certain.
 */
function tw_finalize_tweets(array $tweets, string $username, int $limit): array {
    usort($tweets, function($a, $b) {
        return strcmp((string)$b['posted_at'], (string)$a['posted_at']);
    });
    foreach ($tweets as &$t) {
        if (empty($t['url'])) {
            $t['url'] = 'https://twitter.com/' . $username . '/status/' . $t['tweet_id'];
        }
    }
    unset($t);
    return array_slice($tweets, 0, $limit);
}

/**
 * Path to the shared cookie jar — accumulates guest_id / personalization
 * cookies set by twitter.com and publish.twitter.com so subsequent
 * syndication requests carry the same browser-like session state. The
 * file is rewritten on every request that returns Set-Cookie, so even
 * stale cookies eventually refresh themselves.
 */
function tw_cookie_jar_path(): string {
    return sys_get_temp_dir() . '/nf_tw_cookies.txt';
}

/**
 * Visit publish.twitter.com once per session to acquire the guest cookies
 * (guest_id, guest_id_marketing, personalization_id, etc.) that Twitter's
 * syndication endpoint uses to decide whether to return a real timeline.
 *
 * Without these cookies, syndication.twitter.com returns an HTML page
 * with __NEXT_DATA__.timeline.entries = [] even though the HTTP status
 * is 200. Acquiring them once an hour is enough — the cookies are
 * long-lived and persist via the cookie jar.
 */
function tw_warmup_session(): void {
    $marker = sys_get_temp_dir() . '/nf_tw_session_warm';
    // 1-hour TTL on the marker, but only if the warmup actually
    // succeeded — we don't want a single 5xx blip to lock us out of
    // re-trying for an hour.
    if (is_file($marker) && (time() - @filemtime($marker)) < 3600) return;

    // Hit two endpoints in sequence: x.com sets the guest_id family of
    // cookies, then publish.twitter.com sets the embed-specific ones.
    // Both write to the shared cookie jar via curl's COOKIEJAR.
    foreach (['https://x.com/', 'https://publish.twitter.com/'] as $warmupUrl) {
        $ch = curl_init($warmupUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => TW_USER_AGENTS[array_rand(TW_USER_AGENTS)],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEJAR      => tw_cookie_jar_path(),
            CURLOPT_COOKIEFILE     => tw_cookie_jar_path(),
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9',
                'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
            ],
        ]);
        @curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        error_log("tw_warmup: $warmupUrl -> HTTP $code");
        // Hold off marking warm if we got blocked — a 403/429 means
        // the cookies likely didn't get set either, and we'd rather
        // retry in a minute than wait an hour for nothing.
        if ($code === 403 || $code === 429 || $code === 0) return;
    }
    @touch($marker);
}

/**
 * GET helper with a cache-bypass query param so Cloudflare/CDN edges
 * don't hand us yesterday's cached response. $timeout lets slower
 * transports like rsshub.app extend the window before giving up.
 *
 * Extra headers can be passed in via $extraHeaders for transports that
 * Twitter expects to come from a specific origin (the syndication
 * endpoint, for example, only returns timeline data when Origin is set
 * to https://publish.twitter.com — without it the response is empty
 * even though the HTTP status is 200).
 *
 * Uses a shared cookie jar so cookies set by an earlier request (e.g.
 * the publish.twitter.com session warm-up) automatically ride along on
 * the next syndication/cdn call.
 */
function tw_http_get(string $url, int $timeout = 15, array $extraHeaders = []): ?string {
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url .= $sep . '_cb=' . time() . mt_rand(100, 999);

    $headers = array_merge([
        'Accept: text/html,application/json,application/xhtml+xml',
        'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
        'Cache-Control: no-cache, no-store, must-revalidate',
        'Pragma: no-cache',
    ], $extraHeaders);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => TW_USER_AGENTS[array_rand(TW_USER_AGENTS)],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_COOKIEJAR      => tw_cookie_jar_path(),
        CURLOPT_COOKIEFILE     => tw_cookie_jar_path(),
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!$body || $code >= 400) {
        error_log("tw_fetch: HTTP $code for $url");
        return null;
    }
    return $body;
}

/**
 * Transport 1: cdn.syndication.twimg.com returns JSON directly.
 */
function tw_fetch_via_cdn_json(string $username, int $limit): array {
    $url = 'https://cdn.syndication.twimg.com/timeline/profile'
         . '?screen_name=' . rawurlencode($username)
         . '&with_replies=false&suppress_response_codes=true&lang=en';

    // Same Origin trick the syndication HTML endpoint needs — Twitter
    // checks for it before serving timeline payloads.
    $body = tw_http_get($url, 15, [
        'Origin: https://publish.twitter.com',
        'Referer: https://publish.twitter.com/',
    ]);
    if (!$body) return [];

    // CDN sometimes wraps in JSONP — strip callback wrapper if present.
    if (preg_match('/^\s*[A-Za-z0-9_]+\(/', $body)) {
        $body = preg_replace('/^\s*[A-Za-z0-9_]+\(/', '', $body);
        $body = rtrim(rtrim($body), ');');
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        error_log("tw_fetch: cdn JSON parse failed for $username");
        return [];
    }

    // Timeline items live under different shapes across API versions.
    $items = $data['body'] ?? $data['tweets'] ?? [];
    if (!is_array($items) || empty($items)) {
        $items = $data['props']['pageProps']['timeline']['entries'] ?? [];
    }

    $out = [];
    foreach ($items as $raw) {
        $t = tw_normalize_tweet($raw);
        if ($t) $out[] = $t;
    }
    return $out;
}

/**
 * Transport 2: syndication.twitter.com HTML page with __NEXT_DATA__.
 *
 * This is the primary working transport in 2026. Twitter's edge will
 * 429 if hit too aggressively for the same UA — tw_http_get rotates UA
 * per call, but we also stash the parsed result in a tiny per-user file
 * cache so back-to-back debug runs and concurrent SSE streams reuse the
 * payload instead of hammering syndication.
 *
 * Cache TTL is intentionally shorter than the SSE scrape cadence
 * (TW_SCRAPE_EVERY_SECS = 8s) so each scheduled scrape actually hits
 * Twitter for fresh data — otherwise new tweets sit invisible in the
 * cache for ~25s and the "live" feed lags noticeably. The cache still
 * absorbs bursts (debug + cron + concurrent SSE clients within the
 * same few seconds), which is the only thing it's meant to protect.
 */
function tw_fetch_via_next_data(string $username, int $limit): array {
    $cacheFile = sys_get_temp_dir() . '/nf_tw_nd_' . md5($username) . '.json';
    $cacheTtl  = 5; // seconds — short enough that SSE (8s cadence) always sees fresh data

    if (is_file($cacheFile) && (time() - @filemtime($cacheFile)) < $cacheTtl) {
        $cached = @file_get_contents($cacheFile);
        $rows   = $cached ? json_decode($cached, true) : null;
        if (is_array($rows) && !empty($rows)) return $rows;
    }

    // Match the query-string shape the official publish widget sends —
    // syndication's gatekeeper checks for these params (especially
    // origin + widgetsVersion) when deciding whether to populate the
    // timeline. A bare URL with just screen-name is more likely to come
    // back empty than one that fully impersonates the widget. We skip
    // the giant base64 `features` blob because its bucket values rotate
    // and stale ones don't help, but the lightweight params still
    // matter.
    $url = 'https://syndication.twitter.com/srv/timeline-profile/screen-name/' . rawurlencode($username)
         . '?dnt=true'
         . '&embedId=twitter-widget-0'
         . '&origin=https%3A%2F%2Fpublish.twitter.com'
         . '&sessionId=' . md5($username . date('Ymd'))
         . '&showHeader=false'
         . '&showReplies=false'
         . '&transparent=false';
    // syndication.twitter.com only returns timeline data when the
    // request looks like it came from the official publish widget —
    // Origin: https://publish.twitter.com is the magic header. Without
    // it we get a 200 response but the __NEXT_DATA__ blob has empty
    // entries (which is exactly what we were seeing in production —
    // all sources failing with "no tweets returned").
    //
    // Note: we deliberately do NOT pass an explicit "Cookie:" header
    // here. Curl's cookie jar already carries the guest_id family from
    // the warmup visit, and an explicit Cookie header would override
    // the jar entirely in modern curl — leaving the request looking
    // like it came from a fresh anonymous client again.
    $html = tw_http_get($url, 15, [
        'Origin: https://publish.twitter.com',
        'Referer: https://publish.twitter.com/',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: cross-site',
    ]);
    if (!$html) return [];

    if (!preg_match('#<script[^>]+id="__NEXT_DATA__"[^>]*>(.+?)</script>#s', $html, $m)) {
        error_log("tw_fetch: __NEXT_DATA__ not found for $username");
        return [];
    }
    $data = json_decode($m[1], true);
    if (!is_array($data)) {
        error_log("tw_fetch: __NEXT_DATA__ JSON parse failed for $username");
        return [];
    }

    $entries = $data['props']['pageProps']['timeline']['entries']
            ?? $data['props']['pageProps']['contextProvider']['initialState']['timeline']['entries']
            ?? [];
    if (!is_array($entries)) return [];

    $out = [];
    foreach ($entries as $entry) {
        // Skip pinned entries — they're not chronological and would
        // otherwise shadow newer tweets at the top of our feed.
        $entryId = (string)($entry['entryId'] ?? $entry['type'] ?? '');
        if (stripos($entryId, 'pinned') !== false) continue;

        $tweet = $entry['content']['tweet']
              ?? $entry['content']['item']['content']['tweet']
              ?? $entry['content']
              ?? null;
        $t = tw_normalize_tweet($tweet);
        if ($t) $out[] = $t;
    }
    if (!empty($out)) {
        @file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $out;
}

/**
 * Normalize any tweet-like dict into our internal shape. Returns null
 * if the row can't be interpreted (retweet, no text+no image, etc.).
 */
function tw_normalize_tweet($raw): ?array {
    if (!is_array($raw)) return null;

    // Some wrappers bury the tweet one level deeper.
    if (isset($raw['tweet']) && is_array($raw['tweet'])) $raw = $raw['tweet'];

    $id = (string)($raw['id_str'] ?? $raw['id'] ?? '');
    if ($id === '') return null;

    // Drop retweets — we want original authorship only.
    if (!empty($raw['retweeted_status']) || !empty($raw['retweeted_status_id_str'])) return null;

    $text = (string)($raw['full_text'] ?? $raw['text'] ?? '');
    $text = preg_replace('#https?://t\.co/\S+$#', '', trim($text));
    $text = trim((string)$text);

    $image = '';
    $media = $raw['mediaDetails'] ?? $raw['extended_entities']['media'] ?? $raw['entities']['media'] ?? [];
    if (is_array($media)) {
        foreach ($media as $mItem) {
            $candidate = $mItem['media_url_https'] ?? $mItem['media_url'] ?? '';
            if ($candidate) { $image = $candidate; break; }
        }
    }

    $ts = 0;
    if (!empty($raw['created_at'])) $ts = strtotime((string)$raw['created_at']);
    $postedAt = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

    if ($text === '' && $image === '') return null;

    return [
        'tweet_id'  => $id,
        'text'      => $text,
        'image_url' => $image,
        'posted_at' => $postedAt,
        'url'       => '',
    ];
}

/**
 * Verbose diagnostic fetch for a single username — returns what each
 * transport saw (HTTP code, response size, a short body snippet, and
 * how many tweets parsed out). Used by the admin panel "Debug" button
 * so operators can tell whether the server can reach Twitter at all
 * and whether our parser understood the payload.
 *
 * No DB writes — this is pure read/inspect.
 */
function tw_debug_fetch_source(string $username): array {
    $username = ltrim(trim($username), '@');
    $report = ['username' => $username, 'transports' => []];
    if ($username === '') {
        $report['error'] = 'empty username';
        return $report;
    }

    // Transport 0: Nitter — try each public instance
    foreach (TW_NITTER_INSTANCES as $host) {
        $urlN = 'https://' . $host . '/' . rawurlencode($username) . '/rss';
        $rN   = tw_debug_http($urlN, 8);
        $rN['parsed_count'] = 0;
        $rN['label'] = 'Nitter @ ' . $host;
        if ($rN['body']) {
            $items = tw_parse_rss_feed($rN['body']);
            $rN['parsed_count'] = count($items);
            if (!empty($items)) {
                // Show the newest-parsed timestamp so operators can see
                // how fresh this instance actually is.
                $rN['newest_posted_at'] = $items[0]['posted_at'] ?? null;
            }
        }
        $report['transports'][] = $rN;
        // Stop rotating as soon as one instance works — the rest are
        // just noise in the debug output.
        if ($rN['parsed_count'] > 0) break;
    }

    // Transport 1: RSSHub hosted bridge
    $urlR = 'https://rsshub.app/twitter/user/' . rawurlencode($username);
    $rR   = tw_debug_http($urlR, 20);
    $rR['parsed_count'] = 0;
    if ($rR['body']) {
        libxml_use_internal_errors(true);
        $rss = simplexml_load_string($rR['body']);
        libxml_clear_errors();
        if ($rss && isset($rss->channel->item)) {
            foreach ($rss->channel->item as $item) {
                $link = (string)$item->link;
                if (preg_match('#/status/(\d+)#', $link)) $rR['parsed_count']++;
            }
        } else {
            $rR['parse_error'] = 'rss xml parse failed / no items';
        }
    }
    $rR['label'] = 'rsshub.app (RSS bridge)';
    $report['transports'][] = $rR;

    // Transport 1: CDN JSON
    $url1  = 'https://cdn.syndication.twimg.com/timeline/profile?screen_name=' . rawurlencode($username) . '&with_replies=false&lang=en&_cb=' . time();
    $r1    = tw_debug_http($url1);
    $r1['parsed_count'] = 0;
    if ($r1['body']) {
        $body = $r1['body'];
        if (preg_match('/^\s*[A-Za-z0-9_]+\(/', $body)) {
            $body = preg_replace('/^\s*[A-Za-z0-9_]+\(/', '', $body);
            $body = rtrim(rtrim($body), ');');
        }
        $data = json_decode($body, true);
        if (is_array($data)) {
            $items = $data['body'] ?? $data['tweets'] ?? $data['props']['pageProps']['timeline']['entries'] ?? [];
            if (is_array($items)) {
                foreach ($items as $raw) {
                    if (tw_normalize_tweet($raw)) $r1['parsed_count']++;
                }
            }
            $r1['top_level_keys'] = array_slice(array_keys($data), 0, 10);
        } else {
            $r1['parse_error'] = 'json_decode failed';
        }
    }
    $r1['label'] = 'CDN JSON';
    $report['transports'][] = $r1;

    // Transport 2: NEXT_DATA HTML
    $url2 = 'https://syndication.twitter.com/srv/timeline-profile/screen-name/' . rawurlencode($username) . '?_cb=' . time();
    $r2   = tw_debug_http($url2);
    $r2['parsed_count'] = 0;
    if ($r2['body']) {
        if (preg_match('#<script[^>]+id="__NEXT_DATA__"[^>]*>(.+?)</script>#s', $r2['body'], $m)) {
            $data = json_decode($m[1], true);
            if (is_array($data)) {
                $entries = $data['props']['pageProps']['timeline']['entries']
                        ?? $data['props']['pageProps']['contextProvider']['initialState']['timeline']['entries']
                        ?? [];
                if (is_array($entries)) {
                    foreach ($entries as $entry) {
                        $tweet = $entry['content']['tweet']
                              ?? $entry['content']['item']['content']['tweet']
                              ?? $entry['content']
                              ?? null;
                        if (tw_normalize_tweet($tweet)) $r2['parsed_count']++;
                    }
                }
                $r2['top_level_keys'] = array_slice(array_keys($data['props']['pageProps'] ?? []), 0, 10);
            } else {
                $r2['parse_error'] = 'NEXT_DATA json_decode failed';
            }
        } else {
            $r2['parse_error'] = '__NEXT_DATA__ script not found';
        }
    }
    $r2['label'] = 'syndication.twitter.com NEXT_DATA';
    $report['transports'][] = $r2;

    return $report;
}

function tw_debug_http(string $url, int $timeout = 15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => TW_USER_AGENTS[array_rand(TW_USER_AGENTS)],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/json,application/xhtml+xml',
            'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
            'Cache-Control: no-cache',
        ],
    ]);
    $body     = curl_exec($ch);
    $info     = curl_getinfo($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    return [
        'url'          => $url,
        'http_code'    => (int)($info['http_code'] ?? 0),
        'total_time'   => round((float)($info['total_time'] ?? 0), 2),
        'size'         => is_string($body) ? strlen($body) : 0,
        'curl_error'   => $curlErr ?: null,
        'body'         => is_string($body) ? $body : null,
        'body_snippet' => is_string($body) ? mb_substr($body, 0, 500) : null,
    ];
}

/**
 * Fetch latest tweets for every active source in twitter_sources and
 * persist new ones into twitter_messages. Returns the count of newly
 * inserted rows across all sources.
 */
/**
 * Convert an RSS/Atom feed item into our tweet-row shape. Lets admins
 * point a Twitter source at an alternate RSS feed (Nitter mirror, the
 * source's own website, RSSHub instance, etc.) when the Twitter
 * transports stop working for that specific handle.
 */
function tw_fetch_via_custom_rss(string $rssUrl, int $limit): array {
    $rssUrl = trim($rssUrl);
    if ($rssUrl === '' || !preg_match('#^https?://#i', $rssUrl)) return [];

    $xml = tw_http_get($rssUrl, 12);
    if (!$xml) return [];

    libxml_use_internal_errors(true);
    $rss = simplexml_load_string($xml);
    libxml_clear_errors();
    if (!$rss) return [];

    // Support both RSS 2.0 (channel->item) and Atom (entry) feeds.
    $items = [];
    if (isset($rss->channel->item)) {
        $items = $rss->channel->item;
    } elseif (isset($rss->entry)) {
        $items = $rss->entry;
    }
    if (empty($items)) return [];

    $out = [];
    foreach ($items as $item) {
        $title = trim((string)($item->title ?? ''));
        // Atom uses <link href="..."/>, RSS uses <link>...</link>.
        $link = '';
        if (isset($item->link['href'])) {
            $link = (string)$item->link['href'];
        } elseif (isset($item->link)) {
            $link = (string)$item->link;
        }
        if ($link === '') continue;

        $desc = (string)($item->description ?? $item->summary ?? $item->content ?? '');
        $image = '';
        if (preg_match('#<img[^>]+src=["\']([^"\']+)["\']#i', $desc, $im)) {
            $image = html_entity_decode($im[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        // <media:content> / <enclosure> as image fallbacks.
        if ($image === '' && isset($item->enclosure['url'])) {
            $image = (string)$item->enclosure['url'];
        }

        $text = trim(strip_tags(str_replace(['<br/>', '<br>', '<br />'], "\n", $desc)));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($title !== '' && $text !== '' && stripos($text, $title) !== 0) {
            $text = $title . "\n\n" . $text;
        } elseif ($title !== '' && $text === '') {
            $text = $title;
        }
        // Trim long article bodies down to a tweet-like length.
        if (mb_strlen($text) > 600) $text = mb_substr($text, 0, 600) . '…';

        $pubRaw = (string)($item->pubDate ?? $item->published ?? $item->updated ?? '');
        $ts = $pubRaw !== '' ? strtotime($pubRaw) : 0;
        $postedAt = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

        // Synthesize a stable tweet_id from the link so dedup via the
        // UNIQUE KEY (source_id, tweet_id) keeps working unchanged.
        $tweetId = substr(sha1($link), 0, 19);

        $out[] = [
            'tweet_id'  => $tweetId,
            'text'      => $text,
            'image_url' => $image,
            'posted_at' => $postedAt,
            'url'       => $link,
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

// Per-source minimum interval between scrape attempts. Twitter's
// syndication endpoint throttles by server IP, and the SSE scraper
// fires tw_sync_all_sources() every ~8 seconds whenever anyone has the
// homepage open. Without this floor, every handle gets re-hit ~10
// times per minute (×4 transports each on failure) — that's exactly
// the kind of burst that earns a 429 for the whole pool, after which
// syndication.twitter.com returns an empty timeline for ALL handles
// (the visible symptom: "no tweets returned" across every source).
//
// With the floor, each handle is re-checked at most once per
// TW_SOURCE_REFETCH_FLOOR_SECS, no matter how many SSE clients are
// kicking the scraper concurrently. Real-time freshness is bounded
// below by this constant, not by TW_SCRAPE_EVERY_SECS.
const TW_SOURCE_REFETCH_FLOOR_SECS = 75;

function tw_sync_all_sources(bool $force = false): int {
    $db = getDB();

    // Lazy-add the error-tracking + RSS-fallback columns so the admin
    // panel can show which sources failed (and admins can rescue them
    // by pointing at a working RSS feed) without a separate migration.
    try {
        $db->exec("ALTER TABLE twitter_sources
                    ADD COLUMN last_error VARCHAR(500) DEFAULT NULL,
                    ADD COLUMN last_new_count INT DEFAULT 0,
                    ADD COLUMN consecutive_failures INT DEFAULT 0,
                    ADD COLUMN fallback_rss_url VARCHAR(500) DEFAULT NULL");
    } catch (Throwable $e) { /* one or more columns already exist */ }
    // Add the column individually too, for older installs that already
    // ran the previous migration with only the three error columns.
    try {
        $db->exec("ALTER TABLE twitter_sources ADD COLUMN fallback_rss_url VARCHAR(500) DEFAULT NULL");
    } catch (Throwable $e) {}

    try {
        // Stalest first so when the rate budget runs out mid-batch the
        // freshest data still comes from the handles that needed it most.
        $sources = $db->query("SELECT * FROM twitter_sources
                                WHERE is_active = 1
                                ORDER BY last_fetched_at IS NULL DESC,
                                         last_fetched_at ASC")
                      ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('tw_sync: sources query failed: ' . $e->getMessage());
        return 0;
    }

    // Per-source freshness gate — skip handles re-fetched recently so
    // back-to-back SSE-driven scrapes don't keep hammering the same
    // handles. Admin "🔄 جلب الآن" passes $force=true to bypass.
    // The floor is overridable via settings.twitter_refetch_floor_secs
    // so ops can raise it during persistent throttling without a deploy.
    if (!$force) {
        $floor = (int)getSetting('twitter_refetch_floor_secs', (string)TW_SOURCE_REFETCH_FLOOR_SECS);
        if ($floor < 10) $floor = TW_SOURCE_REFETCH_FLOOR_SECS;
        $now = time();
        $sources = array_values(array_filter($sources, function ($src) use ($now, $floor) {
            if (empty($src['last_fetched_at'])) return true;
            $age = $now - strtotime((string)$src['last_fetched_at']);
            return $age >= $floor;
        }));
        if (empty($sources)) return 0;
    }

    $total = 0;
    foreach ($sources as $i => $src) {
        // Small spacing between source fetches so a multi-source sync
        // doesn't burst-then-429 against syndication.twitter.com.
        // 200ms is enough to dodge the per-IP burst detector without
        // adding visible lag to the SSE scrape cycle.
        if ($i > 0) usleep(200000); // 200ms between sources

        $srcNew = 0;
        $err = null;
        $usedFallback = false;
        try {
            $tweets = tw_fetch_user_tweets($src['username'], 20);
        } catch (Throwable $e) {
            $tweets = [];
            $err = 'fetch exception: ' . $e->getMessage();
        }

        // Twitter transports came back empty — try the admin-configured
        // RSS fallback if one is set. This is how we keep the section
        // alive for handles where Nitter / syndication are consistently
        // blocked for that specific handle.
        if (empty($tweets) && !empty($src['fallback_rss_url'])) {
            try {
                $tweets = tw_fetch_via_custom_rss((string)$src['fallback_rss_url'], 20);
                if (!empty($tweets)) {
                    $usedFallback = true;
                    $err = null;
                } else {
                    $err = 'twitter empty + RSS fallback also returned 0 items';
                }
            } catch (Throwable $e) {
                $err = 'RSS fallback exception: ' . $e->getMessage();
            }
        }

        if (empty($tweets) && $err === null) {
            $err = 'no tweets returned (all transports failed — set a fallback_rss_url for this source)';
        }
        foreach ($tweets as $t) {
            try {
                $stmt = $db->prepare("INSERT IGNORE INTO twitter_messages
                    (source_id, tweet_id, post_url, text, image_url, posted_at)
                    VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    (int)$src['id'],
                    $t['tweet_id'],
                    $t['url'],
                    $t['text'],
                    $t['image_url'],
                    $t['posted_at'],
                ]);
                if ($stmt->rowCount() > 0) { $total++; $srcNew++; }
            } catch (Throwable $e) {
                // Duplicate-key + transient issues: skip this row, keep going.
            }
        }
        try {
            if ($err !== null) {
                $db->prepare("UPDATE twitter_sources
                                 SET last_fetched_at = NOW(),
                                     last_error = ?,
                                     last_new_count = 0,
                                     consecutive_failures = consecutive_failures + 1
                               WHERE id = ?")
                   ->execute([mb_substr($err, 0, 500), (int)$src['id']]);
            } else {
                $okLabel = $usedFallback ? 'ok (RSS fallback)' : 'ok';
                $db->prepare("UPDATE twitter_sources
                                 SET last_fetched_at = NOW(),
                                     last_error = ?,
                                     last_new_count = ?,
                                     consecutive_failures = 0
                               WHERE id = ?")
                   ->execute([$okLabel, $srcNew, (int)$src['id']]);
            }
        } catch (Throwable $e) {}
    }
    return $total;
}
