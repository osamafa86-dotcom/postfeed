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

// Public Nitter instances tried in rotation. First one that returns
// parseable RSS wins. Instances come and go — if all start failing,
// refresh this list from https://github.com/zedeus/nitter/wiki/Instances
const TW_NITTER_INSTANCES = [
    'nitter.privacydev.net',
    'nitter.poast.org',
    'nitter.net',
    'nitter.unixfox.eu',
    'nitter.d420.de',
    'nitter.no-logs.com',
    'nitter.cz',
    'nitter.1d4.us',
];

/**
 * Fetch the latest tweets for a single username.
 *
 * Transports in order (most real-time first):
 *   1) Nitter — scrapes the live profile page; freshest data.
 *   2) NEXT_DATA (syndication.twitter.com) — reliable but cache-lagged.
 *   3) RSSHub — RSS bridge; Twitter route is often broken.
 *   4) CDN JSON — mostly dead; kept as last resort.
 *
 * @return array<int, array{tweet_id:string, text:string, image_url:string, posted_at:string, url:string}>
 */
function tw_fetch_user_tweets(string $username, int $limit = 20): array {
    $username = ltrim(trim($username), '@');
    if ($username === '') return [];

    $out = tw_fetch_via_nitter($username, $limit);
    if (!empty($out)) return tw_finalize_tweets($out, $username, $limit);

    $out = tw_fetch_via_next_data($username, $limit);
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
            // Nitter proxies images through /pic/<url-encoded-path>.
            // Decode the proxy path and rewrite back to pbs.twimg.com so
            // the image keeps loading if the instance that served the
            // RSS later disappears. Two shapes we see in the wild:
            //   .../pic/media%2FXYZ.jpg?name=orig   (relative path)
            //   .../pic/https%3A%2F%2Fpbs.twimg...  (full URL wrapped)
            if (preg_match('#^https?://[^/]*nitter[^/]*/pic/(.+)$#', $image, $pm)) {
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
 * GET helper with a cache-bypass query param so Cloudflare/CDN edges
 * don't hand us yesterday's cached response. $timeout lets slower
 * transports like rsshub.app extend the window before giving up.
 */
function tw_http_get(string $url, int $timeout = 15): ?string {
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url .= $sep . '_cb=' . time();

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/json,application/xhtml+xml',
            'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
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

    $body = tw_http_get($url);
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
 */
function tw_fetch_via_next_data(string $username, int $limit): array {
    $url = 'https://syndication.twitter.com/srv/timeline-profile/screen-name/' . rawurlencode($username);
    $html = tw_http_get($url);
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
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
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
function tw_sync_all_sources(): int {
    $db = getDB();
    try {
        $sources = $db->query("SELECT * FROM twitter_sources WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('tw_sync: sources query failed: ' . $e->getMessage());
        return 0;
    }

    $total = 0;
    foreach ($sources as $src) {
        $tweets = tw_fetch_user_tweets($src['username'], 20);
        if (empty($tweets)) {
            error_log('tw_sync: no tweets returned for @' . $src['username']);
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
                if ($stmt->rowCount() > 0) $total++;
            } catch (Throwable $e) {
                // Duplicate-key + transient issues: skip this row, keep going.
            }
        }
        try {
            $db->prepare("UPDATE twitter_sources SET last_fetched_at = NOW() WHERE id = ?")
               ->execute([(int)$src['id']]);
        } catch (Throwable $e) {}
    }
    return $total;
}
