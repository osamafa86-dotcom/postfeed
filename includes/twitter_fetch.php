<?php
/**
 * Twitter/X feed fetcher — pulls recent tweets from public profiles via
 * Twitter's own embed syndication endpoint (same one used by the "Follow
 * on X" widget). No API key or paid plan required.
 *
 * Endpoint: https://syndication.twitter.com/srv/timeline-profile/screen-name/{user}
 * Response: HTML shell with a <script id="__NEXT_DATA__" type="application/json">
 *           block that holds the rendered timeline payload.
 *
 * If Twitter ever revokes this endpoint we degrade gracefully — the
 * fetchers return [] and the homepage section just shows whatever is
 * already in the DB. No hard failure for callers.
 */

/**
 * Fetch the latest tweets for a single username. Returns newest-last so
 * callers can array_reverse into chronological order.
 *
 * @return array<int, array{tweet_id:string, text:string, image_url:string, posted_at:string, url:string}>
 */
function tw_fetch_user_tweets(string $username, int $limit = 20): array {
    $username = ltrim(trim($username), '@');
    if ($username === '') return [];

    $url = 'https://syndication.twitter.com/srv/timeline-profile/screen-name/' . rawurlencode($username);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NewsFlowBot/1.0; +https://postfeed.emdatra.org)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
        ],
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!$html || $code >= 400) return [];

    // Pull the embedded JSON payload out of the HTML. Twitter wraps the
    // rendered timeline inside <script id="__NEXT_DATA__" type="application/json">.
    if (!preg_match('#<script[^>]+id="__NEXT_DATA__"[^>]*>(.+?)</script>#s', $html, $m)) {
        return [];
    }
    $data = json_decode($m[1], true);
    if (!is_array($data)) return [];

    // Walk defensively — the payload shape has changed a few times.
    $entries = $data['props']['pageProps']['timeline']['entries']
            ?? $data['props']['pageProps']['contextProvider']['initialState']['timeline']['entries']
            ?? [];
    if (!is_array($entries)) return [];

    $out = [];
    foreach ($entries as $entry) {
        $tweet = $entry['content']['tweet']
              ?? $entry['content']['item']['content']['tweet']
              ?? null;
        if (!is_array($tweet)) continue;

        $tweetId = (string)($tweet['id_str'] ?? $tweet['id'] ?? '');
        if ($tweetId === '') continue;

        // Skip retweets — we want the original author's content only.
        if (!empty($tweet['retweeted_status']) || !empty($tweet['retweeted_status_id_str'])) continue;

        $text = (string)($tweet['full_text'] ?? $tweet['text'] ?? '');
        // Twitter appends the short t.co media URL to full_text; strip it so
        // we don't show raw URLs that duplicate the inline image we render.
        $text = preg_replace('#https?://t\.co/\S+$#', '', trim($text));
        $text = trim((string)$text);

        // First photo, if any. Videos surface as preview thumbnails here.
        $image = '';
        $media = $tweet['mediaDetails'] ?? $tweet['extended_entities']['media'] ?? [];
        if (is_array($media)) {
            foreach ($media as $mItem) {
                $candidate = $mItem['media_url_https'] ?? $mItem['media_url'] ?? '';
                if ($candidate) { $image = $candidate; break; }
            }
        }

        // created_at is Twitter's classic "Sun Apr 20 15:23:45 +0000 2026" format.
        $ts = 0;
        if (!empty($tweet['created_at'])) {
            $ts = strtotime((string)$tweet['created_at']);
        }
        $postedAt = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

        $postUrl = 'https://twitter.com/' . $username . '/status/' . $tweetId;

        if ($text === '' && $image === '') continue;

        $out[] = [
            'tweet_id'  => $tweetId,
            'text'      => $text,
            'image_url' => $image,
            'posted_at' => $postedAt,
            'url'       => $postUrl,
        ];

        if (count($out) >= $limit) break;
    }

    return $out;
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
        return 0;
    }

    $total = 0;
    foreach ($sources as $src) {
        $tweets = tw_fetch_user_tweets($src['username'], 20);
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
                // Duplicate-key races + transient DB issues: ignore, keep going.
            }
        }
        try {
            $db->prepare("UPDATE twitter_sources SET last_fetched_at = NOW() WHERE id = ?")
               ->execute([(int)$src['id']]);
        } catch (Throwable $e) {}
    }
    return $total;
}
