<?php
/**
 * YouTube channel fetcher — uses the public Atom feed that YouTube
 * exposes for every channel:
 *
 *   https://www.youtube.com/feeds/videos.xml?channel_id=UCxxxx
 *
 * No API key, no auth, no rate-limit (within reason). Returns up to 15
 * latest uploads. We parse with SimpleXML, pulling out video id, title,
 * description, thumbnail URL, and published date.
 *
 * yt_resolve_channel_id() handles the admin ergonomics — users can
 * paste a channel URL, an @handle, or the raw UCxxx id, and we figure
 * out the canonical channel_id by scraping the channel's HTML page
 * when necessary.
 */

/**
 * Fetch the latest videos for a channel.
 *
 * @return array<int, array{video_id:string, title:string, description:string, thumbnail_url:string, posted_at:string, url:string}>
 */
function yt_fetch_channel_videos(string $channelId, int $limit = 15): array {
    $channelId = trim($channelId);
    if ($channelId === '' || !preg_match('#^UC[A-Za-z0-9_-]{22}$#', $channelId)) {
        return [];
    }

    $url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channelId);
    $xml = yt_http_get($url);
    if (!$xml) return [];

    libxml_use_internal_errors(true);
    $feed = simplexml_load_string($xml);
    libxml_clear_errors();
    if (!$feed || !isset($feed->entry)) {
        error_log('yt_fetch: xml parse failed for ' . $channelId);
        return [];
    }

    // Register namespaces used by YouTube's Atom extensions.
    $feed->registerXPathNamespace('atom',  'http://www.w3.org/2005/Atom');
    $feed->registerXPathNamespace('yt',    'http://www.youtube.com/xml/schemas/2015');
    $feed->registerXPathNamespace('media', 'http://search.yahoo.com/mrss/');

    $out = [];
    foreach ($feed->entry as $entry) {
        $ytNs    = $entry->children('http://www.youtube.com/xml/schemas/2015');
        $mediaNs = $entry->children('http://search.yahoo.com/mrss/');
        // Atom children via explicit namespace so we never rely on
        // SimpleXML's default-namespace auto-unwrap, which has been
        // flaky across PHP versions.
        $atomNs  = $entry->children('http://www.w3.org/2005/Atom');

        $videoId = (string)($ytNs->videoId ?? '');
        if ($videoId === '') continue;

        $title = trim((string)($atomNs->title ?? $entry->title ?? ''));

        // Pick the <link rel="alternate"> — the human-facing watch URL.
        $watchUrl = '';
        $links = isset($atomNs->link) ? $atomNs->link : $entry->link;
        foreach ($links as $link) {
            $rel = (string)$link['rel'];
            if ($rel === 'alternate' || $rel === '') {
                $watchUrl = (string)$link['href'];
                break;
            }
        }
        if ($watchUrl === '') {
            $watchUrl = 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
        }

        $thumbnail = '';
        $description = '';
        if (isset($mediaNs->group)) {
            $group = $mediaNs->group;
            $gMedia = $group->children('http://search.yahoo.com/mrss/');
            if (isset($gMedia->thumbnail)) {
                $thumbnail = (string)$gMedia->thumbnail->attributes()['url'];
            }
            if (isset($gMedia->description)) {
                $description = trim((string)$gMedia->description);
            }
        }
        if ($thumbnail === '') {
            $thumbnail = 'https://i.ytimg.com/vi/' . rawurlencode($videoId) . '/hqdefault.jpg';
        }

        // Real-published date is the #1 correctness gotcha here — if we
        // fail to parse it, every video ends up with posted_at = "now",
        // which kills chronological sorting. Try in this order:
        //   1) explicit Atom-namespace <published>
        //   2) default-namespace $entry->published (works on many setups)
        //   3) explicit Atom-namespace <updated>
        //   4) default-namespace $entry->updated
        //   5) give up and fall through to now() with an error_log so
        //      operators can notice the feed shape has drifted.
        $publishedStr = '';
        if (!empty($atomNs->published))       $publishedStr = (string)$atomNs->published;
        elseif (!empty($entry->published))    $publishedStr = (string)$entry->published;
        elseif (!empty($atomNs->updated))     $publishedStr = (string)$atomNs->updated;
        elseif (!empty($entry->updated))      $publishedStr = (string)$entry->updated;

        if ($publishedStr === '') {
            error_log('yt_fetch: no <published> or <updated> for video ' . $videoId . ' on ' . $channelId);
        }
        $ts = $publishedStr !== '' ? strtotime($publishedStr) : 0;
        if (!$ts) $ts = time();
        $postedAt = date('Y-m-d H:i:s', $ts);

        if ($title === '') continue;

        $out[] = [
            'video_id'      => $videoId,
            'title'         => $title,
            'description'   => $description,
            'thumbnail_url' => $thumbnail,
            'posted_at'     => $postedAt,
            'url'           => $watchUrl,
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

/**
 * Accept a channel URL, @handle, or raw UCxxx id and return the
 * canonical channel_id. Returns null when the input can't be resolved.
 *
 * Shapes supported:
 *   UCxxxxxxxxxxxxxxxxxxxxxx                      — direct id
 *   https://youtube.com/channel/UCxxxxxxxxxxxxxxxxxxxxxx
 *   https://youtube.com/@handle
 *   @handle
 *   handle                                         — bare
 */
function yt_resolve_channel_id(string $input): ?string {
    $input = trim($input);
    if ($input === '') return null;

    // Direct channel id.
    if (preg_match('#^UC[A-Za-z0-9_-]{22}$#', $input)) {
        return $input;
    }
    // Channel URL.
    if (preg_match('#youtube\.com/channel/(UC[A-Za-z0-9_-]{22})#i', $input, $m)) {
        return $m[1];
    }

    // Handle — either from a URL or bare/@-prefixed.
    $handle = null;
    if (preg_match('#youtube\.com/@([A-Za-z0-9._-]+)#i', $input, $m)) {
        $handle = $m[1];
    } elseif (preg_match('#^@?([A-Za-z0-9._-]+)$#', $input, $m)) {
        $handle = $m[1];
    }
    if (!$handle) return null;

    $html = yt_http_get('https://www.youtube.com/@' . rawurlencode($handle));
    if (!$html) return null;

    // YouTube's HTML embeds the channelId in multiple places; any of these works.
    if (preg_match('#"channelId":"(UC[A-Za-z0-9_-]{22})"#', $html, $m)) return $m[1];
    if (preg_match('#"externalId":"(UC[A-Za-z0-9_-]{22})"#', $html, $m)) return $m[1];
    if (preg_match('#<meta[^>]+itemprop="(?:channelId|identifier)"[^>]+content="(UC[A-Za-z0-9_-]{22})"#i', $html, $m)) return $m[1];
    if (preg_match('#<link[^>]+rel="canonical"[^>]+href="[^"]*youtube\.com/channel/(UC[A-Za-z0-9_-]{22})#i', $html, $m)) return $m[1];

    return null;
}

/**
 * Try to pull the channel's display name + avatar URL from its HTML
 * page. Best-effort — returns what it can find, empty strings for
 * whatever it couldn't.
 *
 * @return array{display_name:string, avatar_url:string}
 */
function yt_fetch_channel_meta(string $channelId): array {
    $meta = ['display_name' => '', 'avatar_url' => ''];
    if (!preg_match('#^UC[A-Za-z0-9_-]{22}$#', $channelId)) return $meta;

    $html = yt_http_get('https://www.youtube.com/channel/' . rawurlencode($channelId));
    if (!$html) return $meta;

    if (preg_match('#"author":\{"name":"([^"]+)"#', $html, $m)) {
        $meta['display_name'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } elseif (preg_match('#<meta[^>]+property="og:title"[^>]+content="([^"]+)"#i', $html, $m)) {
        $meta['display_name'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('#"avatar":\{"thumbnails":\[\{"url":"([^"]+)"#', $html, $m)) {
        $meta['avatar_url'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } elseif (preg_match('#<meta[^>]+property="og:image"[^>]+content="([^"]+)"#i', $html, $m)) {
        $meta['avatar_url'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $meta;
}

/**
 * GET helper — shared across youtube_fetch, with a cache-bypass param
 * and a browser-like User-Agent since YouTube sometimes returns empty
 * to headless UAs.
 */
function yt_http_get(string $url): ?string {
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
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9',
            'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
            'Cache-Control: no-cache',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!$body || $code >= 400) {
        error_log("yt_fetch: HTTP $code for $url");
        return null;
    }
    return $body;
}

/**
 * Fetch the latest videos for every active source and persist the new
 * ones into youtube_videos. Returns the count of newly inserted rows.
 */
function yt_sync_all_sources(): int {
    $db = getDB();
    try {
        $sources = $db->query("SELECT * FROM youtube_sources WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('yt_sync: sources query failed: ' . $e->getMessage());
        return 0;
    }

    $total = 0;
    foreach ($sources as $src) {
        $videos = yt_fetch_channel_videos($src['channel_id'], 15);
        if (empty($videos)) {
            error_log('yt_sync: no videos returned for ' . $src['channel_id']);
        }
        foreach ($videos as $v) {
            try {
                $stmt = $db->prepare("INSERT IGNORE INTO youtube_videos
                    (source_id, video_id, post_url, title, description, thumbnail_url, posted_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    (int)$src['id'],
                    $v['video_id'],
                    $v['url'],
                    $v['title'],
                    $v['description'],
                    $v['thumbnail_url'],
                    $v['posted_at'],
                ]);
                if ($stmt->rowCount() > 0) $total++;
            } catch (Throwable $e) {}
        }
        try {
            $db->prepare("UPDATE youtube_sources SET last_fetched_at = NOW() WHERE id = ?")
               ->execute([(int)$src['id']]);
        } catch (Throwable $e) {}
    }
    return $total;
}
