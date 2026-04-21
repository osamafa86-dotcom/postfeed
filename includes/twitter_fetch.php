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

/**
 * Fetch the latest tweets for a single username.
 * @return array<int, array{tweet_id:string, text:string, image_url:string, posted_at:string, url:string}>
 */
function tw_fetch_user_tweets(string $username, int $limit = 20): array {
    $username = ltrim(trim($username), '@');
    if ($username === '') return [];

    $out = tw_fetch_via_cdn_json($username, $limit);
    if (!empty($out)) return tw_finalize_tweets($out, $username, $limit);

    $out = tw_fetch_via_next_data($username, $limit);
    if (!empty($out)) return tw_finalize_tweets($out, $username, $limit);

    return [];
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
 * don't hand us yesterday's cached response.
 */
function tw_http_get(string $url): ?string {
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url .= $sep . '_cb=' . time();

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
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
