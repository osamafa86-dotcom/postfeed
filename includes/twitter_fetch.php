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
 *   0) Authenticated GraphQL (x.com/i/api) — the ONLY path that reliably
 *      works from a datacenter IP in 2026. Requires a logged-in session
 *      (auth_token + ct0 cookies pasted in the admin panel). Tried first
 *      whenever those credentials are configured.
 *   1) NEXT_DATA (syndication.twitter.com) — blocked (403/429) for most
 *      datacenter IPs now; kept for hosts that still reach it.
 *   2) Nitter — almost every public instance is dead or blocked.
 *   3) RSSHub — mostly broken for Twitter, kept for completeness.
 *   4) CDN JSON — dead; last resort.
 *
 * @return array<int, array{tweet_id:string, text:string, image_url:string, posted_at:string, url:string}>
 */
function tw_fetch_user_tweets(string $username, int $limit = 20): array {
    $username = ltrim(trim($username), '@');
    if ($username === '') return [];

    // Transport 0 (preferred when configured): authenticated GraphQL.
    // Twitter blocks the anonymous syndication/Nitter routes at the IP
    // level for datacenter hosts, so a real logged-in session is the
    // only reliable free path. Skipped automatically when no cookies
    // are set, falling through to the legacy anonymous transports.
    if (tw_gql_enabled()) {
        $out = tw_fetch_via_graphql($username, $limit);
        if (!empty($out)) return tw_finalize_tweets($out, $username, $limit);
    }

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
    $budget   = 15; // seconds across all instances — slightly higher than
                    // before so the HTML-profile fallback has room to run.
    $anyOk    = false;

    foreach (TW_NITTER_INSTANCES as $host) {
        if ((microtime(true) - $startTs) > $budget) break;

        // First try /username/rss — the cheapest, cleanest path.
        $rssUrl = 'https://' . $host . '/' . rawurlencode($username) . '/rss';
        $body   = tw_http_get($rssUrl, 4);
        $items  = [];

        if ($body) {
            // Looks like real RSS/Atom? Parse it.
            if (stripos(ltrim($body), '<?xml') === 0 || stripos($body, '<rss') !== false) {
                $items = tw_parse_rss_feed($body);
            }
            // Several forks (notably nitter.adminforge.de) now serve the
            // HTML profile page from /rss instead of XML — empty parse
            // result + an HTML body is the tell. Try the HTML extractor
            // before giving up on this instance.
            if (empty($items) && stripos($body, '<html') !== false) {
                $items = tw_parse_nitter_html($body, $username);
            }
        }

        // If neither path produced tweets, fall back to /username (no
        // /rss suffix) — some instances disabled RSS but still serve
        // the profile HTML.
        if (empty($items) && (microtime(true) - $startTs) <= $budget) {
            $htmlUrl = 'https://' . $host . '/' . rawurlencode($username);
            $html    = tw_http_get($htmlUrl, 4);
            if ($html && stripos($html, '<html') !== false) {
                $items = tw_parse_nitter_html($html, $username);
            }
        }

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
 * Extract tweets from a Nitter-style HTML profile page. Most public
 * Nitter forks (xcancel, lightbrd, adminforge, tiekoetter) ship roughly
 * the same template — a list of `.timeline-item` divs, each with a
 * status-link, a `tweet-date` title, a `tweet-content` body, and
 * optionally an `.attachments` block with images.
 *
 * Regex over DOMDocument because Nitter HTML routinely has unclosed
 * tags, mixed quoting, and broken UTF-8 sequences that make the DOM
 * parser bail. Regex is forgiving and only needs the three signals we
 * care about (status link, date, body).
 *
 * Used as a fallback when /username/rss returns an HTML page instead
 * of XML, which several public instances started doing in 2026.
 */
function tw_parse_nitter_html(string $html, string $username): array {
    $username = ltrim(trim($username), '@');
    if ($username === '' || $html === '') return [];

    // Split into per-tweet chunks at each `timeline-item` opening div.
    // The first chunk is the page header — discard it.
    $chunks = preg_split('#<div\b[^>]*class="[^"]*\btimeline-item\b[^"]*"#i', $html);
    if (count($chunks) < 2) return [];
    array_shift($chunks);

    $out      = [];
    $userQ    = preg_quote($username, '#');

    foreach ($chunks as $chunk) {
        // Anything past the first `</div></div>` belongs to the next
        // tweet — trim so attribute extraction can't cross-contaminate.
        // (Not strictly necessary because we split on the next opening
        // div, but keeps the inner regexes bounded.)
        if (preg_match('#/' . $userQ . '/status/(\d+)#i', $chunk, $idM)) {
            $tweetId = $idM[1];
        } else {
            continue;
        }

        // Drop retweets — they carry a `retweet-header` row and we
        // only want original-author posts.
        if (stripos($chunk, 'retweet-header') !== false) continue;

        // Posted-at from <span class="tweet-date">…title="Mon DD, YYYY · HH:MM …"
        // Nitter renders the date with a middle-dot (·) between the date
        // and the time, which strtotime treats as garbage and bails on
        // ("Jan 15, 2026 · 12:30 PM UTC" → false). Strip the separator
        // before parsing so the time component actually gets honoured.
        $postedAt = date('Y-m-d H:i:s');
        if (preg_match('#class="[^"]*\btweet-date\b[^"]*"[^>]*>\s*<a[^>]+title="([^"]+)"#i', $chunk, $dateM)) {
            $raw = html_entity_decode($dateM[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $raw = preg_replace('/\s*[·\xC2\xB7]\s*/u', ' ', $raw);
            $ts  = strtotime((string)$raw);
            if ($ts) $postedAt = date('Y-m-d H:i:s', $ts);
        }

        // Tweet body — first <div class="tweet-content …">…</div>.
        // Greedy `.+?` and the `s` flag because tweet bodies routinely
        // span lines.
        $text = '';
        if (preg_match('#<div\b[^>]*class="[^"]*\btweet-content\b[^"]*"[^>]*>(.+?)</div>#si', $chunk, $bodyM)) {
            $bodyHtml = preg_replace('#<br\s*/?\s*>#i', "\n", $bodyM[1]);
            $text     = trim(strip_tags($bodyHtml));
            $text     = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Strip trailing t.co link Twitter auto-appends to long tweets
            $text     = trim((string)preg_replace('#https?://t\.co/\S+\s*$#', '', $text));
        }

        // First image / video poster — covers .attachments .image,
        // .video.gif posters, and any direct <img src> inside the chunk.
        $image = '';
        if (preg_match('#<(?:img|source|video)\b[^>]+(?:src|poster|data-src)="([^"]+)"#i', $chunk, $imgM)) {
            $cand = html_entity_decode($imgM[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Nitter proxies media via /pic/<encoded>. Rewrite back to
            // pbs.twimg.com so the URL keeps loading if the instance
            // disappears between scrape and render.
            if (preg_match('#^(?:https?://[^/]+)?/pic/(.+)$#', $cand, $pm)) {
                $inner = rawurldecode($pm[1]);
                $cand  = preg_match('#^https?://#', $inner)
                       ? $inner
                       : 'https://pbs.twimg.com/' . ltrim($inner, '/');
            }
            // Skip the profile avatar fallback Nitter shows when a tweet
            // has no media of its own — its URL contains "profile_images".
            if (stripos($cand, 'profile_images') === false
             && stripos($cand, 'emoji') === false) {
                $image = $cand;
            }
        }

        if ($text === '' && $image === '') continue;

        $out[] = [
            'tweet_id'  => $tweetId,
            'text'      => $text,
            'image_url' => $image,
            'posted_at' => $postedAt,
            'url'       => 'https://twitter.com/' . $username . '/status/' . $tweetId,
        ];

        if (count($out) >= 50) break; // sanity cap
    }

    return $out;
}

/**
 * Parse an RSS or Atom feed (Nitter, RSSHub, or modern Nitter fork shape)
 * into our tweet rows. Items without a /status/NNN link are ignored because
 * they're not tweets (e.g. retweets that Nitter renders differently).
 *
 * Handles three feed shapes seen in the wild from Nitter forks:
 *   - Classic RSS 2.0:  <rss><channel><item><link>...</link></item>
 *   - Atom 1.0:         <feed><entry><link href="..."/></entry>
 *     (adminforge.de and a few revival forks ship Atom now)
 *   - HTML challenge page (Cloudflare/Anubis interstitial) — detected and
 *     logged so we know which instances are gated and not silently treated
 *     as "empty feed".
 *
 * Companion: tw_parse_nitter_html() above handles the case where the
 * instance's /rss endpoint returns its HTML profile page instead of XML.
 */
function tw_parse_rss_feed(string $xml): array {
    // Fast-fail on HTML challenge / error pages before SimpleXML chokes.
    // Anubis (used by tiekoetter/adminforge), Cloudflare interstitials,
    // and Nitter's own "User not found" HTML all start with <!DOCTYPE or
    // <html. Logging the snippet lets us see which instance went HTML.
    $head = ltrim(substr($xml, 0, 200));
    if (stripos($head, '<!doctype html') === 0 || stripos($head, '<html') === 0) {
        error_log('tw_parse_rss_feed: got HTML page (gated/blocked), head=' . substr($head, 0, 120));
        return [];
    }

    libxml_use_internal_errors(true);
    $rss = simplexml_load_string($xml);
    libxml_clear_errors();
    if (!$rss) {
        error_log('tw_parse_rss_feed: simplexml load failed, head=' . substr($xml, 0, 200));
        return [];
    }

    // Atom feed shape: <feed><entry>...</entry></feed>. Some Nitter forks
    // (notably adminforge.de) emit Atom instead of RSS 2.0.
    $rootName = $rss->getName();
    if ($rootName === 'feed' || isset($rss->entry)) {
        return tw_parse_atom_entries($rss);
    }

    if (!isset($rss->channel->item)) {
        error_log("tw_parse_rss_feed: unknown shape, root=$rootName, head=" . substr($xml, 0, 200));
        return [];
    }

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
 * Parse an Atom 1.0 feed (the shape adminforge.de and a few revival Nitter
 * forks ship). Atom uses <entry> instead of <item> and <link href="..."/>
 * as a self-closing element with the URL in an attribute. Content lives
 * under <content>, <summary>, or rarely <description>.
 */
function tw_parse_atom_entries(SimpleXMLElement $feed): array {
    $out = [];
    $entries = $feed->entry ?? [];
    foreach ($entries as $entry) {
        // Atom link is <link href="..."/>; sometimes there are multiple
        // (rel="alternate", rel="self", etc.) — pick the alternate or
        // the first one without rel.
        $link = '';
        foreach ($entry->link as $l) {
            $rel = (string)($l['rel'] ?? '');
            $href = (string)($l['href'] ?? '');
            if ($href === '') continue;
            if ($rel === '' || $rel === 'alternate') { $link = $href; break; }
            if ($link === '') $link = $href;
        }
        if ($link === '') $link = (string)$entry->id;
        if (!preg_match('#/status/(\d+)#', $link, $m)) continue;
        $tweetId = $m[1];

        $content = (string)($entry->content ?? $entry->summary ?? $entry->description ?? $entry->title ?? '');

        $image = '';
        if (preg_match('#<img[^>]+src=["\']([^"\']+)["\']#i', $content, $im)) {
            $image = html_entity_decode($im[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#^https?://[^/]+/pic/(.+)$#', $image, $pm)) {
                $inner = rawurldecode($pm[1]);
                $image = preg_match('#^https?://#', $inner)
                    ? $inner
                    : 'https://pbs.twimg.com/' . ltrim($inner, '/');
            }
        }

        $text = trim(strip_tags(str_replace(['<br/>', '<br>', '<br />'], "\n", $content)));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string)preg_replace('#https?://t\.co/\S+$#', '', $text));

        // Atom uses <published> or <updated>; RSS uses <pubDate>.
        $pubRaw = (string)($entry->published ?? $entry->updated ?? '');
        $ts = $pubRaw !== '' ? strtotime($pubRaw) : 0;
        $postedAt = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

        if ($text === '' && $image === '') continue;

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

// ════════════════════════════════════════════════════════════════
// AUTHENTICATED GraphQL TRANSPORT (x.com/i/api/graphql)
// ════════════════════════════════════════════════════════════════
// In 2026 the anonymous timeline endpoints (syndication.twitter.com,
// cdn.syndication.twimg.com) return 403/429 to every datacenter IP —
// no header/cookie trick gets around an IP-level block. The only
// reliable free path is the same GraphQL API x.com's own web client
// calls, authenticated with a real logged-in session.
//
// The admin pastes two cookies from a (burner) X account in the panel:
//   • auth_token  — the session cookie
//   • ct0         — the CSRF double-submit token
// stored as settings.twitter_auth_token / settings.twitter_ct0.

// Public web-app bearer token. NOT a per-user secret — it's a constant
// baked into x.com's JS bundle, identical for every visitor. Auth comes
// from the cookies, not this.
const TW_GQL_BEARER = 'AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs%3D1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';

// GraphQL query IDs (the hash in the URL path) rotate every few weeks.
// These defaults are current as of early 2026; override without a
// deploy via settings if X rotates them:
//   twitter_gql_userbyscreenname_qid
//   twitter_gql_usertweets_qid
const TW_GQL_QID_USER_BY_NAME = 'oUZZZ8Oddwxs8Cd3iW3UEA';
const TW_GQL_QID_USER_TWEETS  = 'E3opETHurmVJflFsUBVuUQ';

/** Read the stored session cookies. */
function tw_gql_credentials(): array {
    return [
        'auth_token' => trim((string)getSetting('twitter_auth_token', '')),
        'ct0'        => trim((string)getSetting('twitter_ct0', '')),
    ];
}

/** True when both cookies are configured. */
function tw_gql_enabled(): bool {
    $c = tw_gql_credentials();
    return $c['auth_token'] !== '' && $c['ct0'] !== '';
}

/**
 * Low-level GraphQL GET. Returns a structured result:
 *   ['ok'=>bool, 'http'=>int, 'data'=>array|null, 'error'=>?string, 'body'=>string]
 * Never throws — callers inspect ['ok'].
 */
function tw_gql_request(string $opName, string $qid, array $variables, array $features): array {
    $cred = tw_gql_credentials();
    if ($cred['auth_token'] === '' || $cred['ct0'] === '') {
        return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'no_credentials', 'body' => ''];
    }

    $url = 'https://x.com/i/api/graphql/' . rawurlencode($qid) . '/' . $opName
         . '?variables=' . rawurlencode(json_encode($variables, JSON_UNESCAPED_UNICODE))
         . '&features='  . rawurlencode(json_encode($features,  JSON_UNESCAPED_UNICODE));

    $headers = [
        'Authorization: Bearer ' . TW_GQL_BEARER,
        'x-csrf-token: ' . $cred['ct0'],
        'x-twitter-auth-type: OAuth2Session',
        'x-twitter-active-user: yes',
        'x-twitter-client-language: ar',
        'Accept: */*',
        'Accept-Language: ar,en-US;q=0.9,en;q=0.8',
        'Content-Type: application/json',
        'Referer: https://x.com/',
        'Origin: https://x.com',
        // Both cookies on the wire. ct0 must match the x-csrf-token header.
        'Cookie: auth_token=' . $cred['auth_token'] . '; ct0=' . $cred['ct0'],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => TW_USER_AGENTS[array_rand(TW_USER_AGENTS)],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cErr = curl_error($ch);
    curl_close($ch);

    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'http' => $code, 'data' => null,
                'error' => 'curl: ' . ($cErr ?: 'empty body'), 'body' => ''];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'http' => $code, 'data' => null,
                'error' => 'json_decode_failed', 'body' => $body];
    }
    // GraphQL surfaces problems in a top-level "errors" array even on
    // HTTP 200. The message names the cause (bad auth, missing feature
    // flag, rate limit) — propagate it so the diagnostic is actionable.
    if (!empty($data['errors'])) {
        $msg = $data['errors'][0]['message'] ?? 'unknown graphql error';
        return ['ok' => false, 'http' => $code, 'data' => $data,
                'error' => 'graphql: ' . $msg, 'body' => $body];
    }
    return ['ok' => true, 'http' => $code, 'data' => $data, 'error' => null, 'body' => $body];
}

/** Feature flags for UserByScreenName (smaller set than UserTweets). */
function tw_gql_userbyname_features(): array {
    return [
        'hidden_profile_subscriptions_enabled' => true,
        'rweb_tipjar_consumption_enabled' => true,
        'responsive_web_graphql_exclude_directive_enabled' => true,
        'verified_phone_label_enabled' => false,
        'subscriptions_verification_info_is_identity_verified_enabled' => true,
        'subscriptions_verification_info_verified_since_enabled' => true,
        'highlights_tweets_tab_ui_enabled' => true,
        'responsive_web_twitter_article_notes_tab_enabled' => true,
        'subscriptions_feature_can_gift_premium' => true,
        'creator_subscriptions_tweet_preview_api_enabled' => true,
        'responsive_web_graphql_skip_user_profile_image_extensions_enabled' => false,
        'responsive_web_graphql_timeline_navigation_enabled' => true,
    ];
}

/**
 * Feature flags for UserTweets. Overridable wholesale via
 * settings.twitter_gql_features (a JSON object) so we can add a flag X
 * starts requiring ("The following features cannot be null: …") without
 * shipping a new build.
 */
function tw_gql_usertweets_features(): array {
    $override = trim((string)getSetting('twitter_gql_features', ''));
    if ($override !== '') {
        $decoded = json_decode($override, true);
        if (is_array($decoded) && !empty($decoded)) return $decoded;
    }
    return [
        'rweb_tipjar_consumption_enabled' => true,
        'responsive_web_graphql_exclude_directive_enabled' => true,
        'verified_phone_label_enabled' => false,
        'creator_subscriptions_tweet_preview_api_enabled' => true,
        'responsive_web_graphql_timeline_navigation_enabled' => true,
        'responsive_web_graphql_skip_user_profile_image_extensions_enabled' => false,
        'communities_web_enable_tweet_community_results_fetch' => true,
        'c9s_tweet_anatomy_moderator_badge_enabled' => true,
        'articles_preview_enabled' => true,
        'responsive_web_edit_tweet_api_enabled' => true,
        'graphql_is_translatable_rweb_tweet_is_translatable_enabled' => true,
        'view_counts_everywhere_api_enabled' => true,
        'longform_notetweets_consumption_enabled' => true,
        'responsive_web_twitter_article_tweet_consumption_enabled' => true,
        'tweet_awards_web_tipping_enabled' => false,
        'creator_subscriptions_quote_tweet_preview_enabled' => false,
        'freedom_of_speech_not_reach_fetch_enabled' => true,
        'standardized_nudges_misinfo' => true,
        'tweet_with_visibility_results_prefer_gql_limited_actions_policy_enabled' => true,
        'rweb_video_timestamps_enabled' => true,
        'longform_notetweets_rich_text_read_enabled' => true,
        'longform_notetweets_inline_media_enabled' => true,
        'responsive_web_enhance_cards_enabled' => false,
    ];
}

/**
 * Resolve a screen name to its numeric user id (rest_id), cached on disk
 * for a day since it never changes for a given handle.
 */
function tw_gql_user_id(string $username): ?string {
    $cacheFile = sys_get_temp_dir() . '/nf_tw_uid_' . md5(strtolower($username)) . '.txt';
    if (is_file($cacheFile) && (time() - @filemtime($cacheFile)) < 86400) {
        $cached = trim((string)@file_get_contents($cacheFile));
        if ($cached !== '') return $cached;
    }
    $qid = trim((string)getSetting('twitter_gql_userbyscreenname_qid', '')) ?: TW_GQL_QID_USER_BY_NAME;
    $res = tw_gql_request('UserByScreenName', $qid,
        ['screen_name' => $username, 'withSafetyModeUserFields' => true],
        tw_gql_userbyname_features());
    if (!$res['ok']) {
        error_log('tw_gql: UserByScreenName failed for ' . $username . ' — ' . $res['error']);
        return null;
    }
    $id = $res['data']['data']['user']['result']['rest_id'] ?? null;
    if (!$id) {
        error_log('tw_gql: no rest_id in UserByScreenName response for ' . $username);
        return null;
    }
    @file_put_contents($cacheFile, (string)$id, LOCK_EX);
    return (string)$id;
}

/**
 * Transport 0: authenticated UserTweets GraphQL call. Returns our normal
 * tweet-row shape, or [] (and logs why) on any failure.
 */
function tw_fetch_via_graphql(string $username, int $limit): array {
    if (!tw_gql_enabled()) return [];
    $userId = tw_gql_user_id($username);
    if (!$userId) return [];

    $qid = trim((string)getSetting('twitter_gql_usertweets_qid', '')) ?: TW_GQL_QID_USER_TWEETS;
    $res = tw_gql_request('UserTweets', $qid, [
        'userId' => $userId,
        'count'  => max(20, min($limit, 40)),
        'includePromotedContent' => false,
        'withQuickPromoteEligibilityTweetFields' => false,
        'withVoice' => false,
        'withV2Timeline' => true,
    ], tw_gql_usertweets_features());

    if (!$res['ok']) {
        error_log('tw_gql: UserTweets failed for ' . $username . ' — ' . $res['error']);
        return [];
    }
    $rows = tw_gql_parse_user_tweets($res['data']);
    error_log('tw_gql: UserTweets parsed ' . count($rows) . ' tweets for ' . $username);
    return $rows;
}

/**
 * Walk the UserTweets timeline instructions and normalize each tweet
 * entry. Skips cursors, pinned entries, promoted content, and retweets.
 */
function tw_gql_parse_user_tweets(array $data): array {
    $instructions = $data['data']['user']['result']['timeline_v2']['timeline']['instructions']
                 ?? $data['data']['user']['result']['timeline']['timeline']['instructions']
                 ?? [];
    if (!is_array($instructions)) return [];

    $out = [];
    foreach ($instructions as $inst) {
        // Pinned tweets arrive in their own instruction — skip so an old
        // pinned tweet can't shadow newer posts at the top of our feed.
        if (($inst['type'] ?? '') === 'TimelinePinEntry') continue;
        $entries = $inst['entries'] ?? [];
        if (!is_array($entries)) continue;

        foreach ($entries as $entry) {
            // Only top-level tweet entries. Skip cursor-*, promoted-*,
            // who-to-follow-*, and conversation module wrappers.
            if (strpos((string)($entry['entryId'] ?? ''), 'tweet-') !== 0) continue;
            $result = $entry['content']['itemContent']['tweet_results']['result'] ?? null;
            $t = tw_gql_normalize_result($result);
            if ($t) $out[] = $t;
        }
    }
    return $out;
}

/**
 * Turn one GraphQL tweet_results.result node into our row shape by
 * extracting its `legacy` object and handing it to tw_normalize_tweet
 * (the legacy object is the same v1.1-style dict that helper already
 * understands). Handles the TweetWithVisibilityResults wrapper and
 * long-form note tweets, and drops retweets.
 */
function tw_gql_normalize_result($result): ?array {
    if (!is_array($result)) return null;
    // Limited-visibility tweets wrap the real payload one level down.
    if (($result['__typename'] ?? '') === 'TweetWithVisibilityResults' && isset($result['tweet'])) {
        $result = $result['tweet'];
    }
    $legacy = $result['legacy'] ?? null;
    if (!is_array($legacy)) return null;

    // GraphQL marks retweets with retweeted_status_result (not the
    // legacy retweeted_status field tw_normalize_tweet checks for).
    if (!empty($legacy['retweeted_status_result'])) return null;

    // Long-form "note" tweets keep the full body outside legacy.full_text.
    $note = $result['note_tweet']['note_tweet_results']['result']['text'] ?? null;
    if (is_string($note) && $note !== '') $legacy['full_text'] = $note;

    return tw_normalize_tweet($legacy);
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

    // Transport 0 (preferred): authenticated GraphQL. Shown first since
    // it's the only reliable path in 2026. Reports whether credentials
    // are set, the HTTP code, any GraphQL error message, and how many
    // tweets parsed — so an operator can tell at a glance whether the
    // session cookies are valid / expired / missing a feature flag.
    $g = ['label' => 'GraphQL مُصادَق (جلسة X)', 'http_code' => 0,
          'total_time' => 0, 'size' => 0, 'parsed_count' => 0,
          'curl_error' => null, 'body_snippet' => null, 'url' => 'x.com/i/api/graphql/UserTweets'];
    if (!tw_gql_enabled()) {
        $g['parse_error'] = 'لا توجد كوكيز — أضف auth_token و ct0 بالأسفل';
        $report['transports'][] = $g;
    } else {
        $t0 = microtime(true);
        $userId = tw_gql_user_id($username);
        if (!$userId) {
            $g['parse_error'] = 'فشل UserByScreenName — الكوكيز غالباً منتهية أو خاطئة (شوف error.log)';
            $g['total_time'] = round(microtime(true) - $t0, 2);
            $report['transports'][] = $g;
        } else {
            $qid = trim((string)getSetting('twitter_gql_usertweets_qid', '')) ?: TW_GQL_QID_USER_TWEETS;
            $res = tw_gql_request('UserTweets', $qid, [
                'userId' => $userId, 'count' => 20,
                'includePromotedContent' => false,
                'withQuickPromoteEligibilityTweetFields' => false,
                'withVoice' => false, 'withV2Timeline' => true,
            ], tw_gql_usertweets_features());
            $g['http_code']  = $res['http'];
            $g['total_time'] = round(microtime(true) - $t0, 2);
            $g['size']       = is_string($res['body'] ?? null) ? strlen($res['body']) : 0;
            $g['body_snippet'] = isset($res['body']) ? mb_substr((string)$res['body'], 0, 500) : null;
            if ($res['ok']) {
                $rows = tw_gql_parse_user_tweets($res['data']);
                $g['parsed_count'] = count($rows);
                $g['newest_posted_at'] = $rows[0]['posted_at'] ?? null;
                $g['resolved_user_id'] = $userId;
            } else {
                $g['parse_error'] = $res['error'];
            }
            $report['transports'][] = $g;
            // GraphQL worked — no need to hammer the dead anonymous
            // transports in the diagnostic too.
            if ($g['parsed_count'] > 0) return $report;
        }
    }

    // Transport 0: Nitter — try each public instance. Mirrors
    // tw_fetch_via_nitter() so the diagnostic reflects what the live
    // scraper actually does (RSS first, HTML fallback on the same body,
    // then /username HTML if /rss came back blank).
    foreach (TW_NITTER_INSTANCES as $host) {
        $urlN = 'https://' . $host . '/' . rawurlencode($username) . '/rss';
        $rN   = tw_debug_http($urlN, 8);
        $rN['parsed_count'] = 0;
        $rN['label'] = 'Nitter @ ' . $host;
        $items  = [];
        if ($rN['body']) {
            if (stripos(ltrim($rN['body']), '<?xml') === 0 || stripos($rN['body'], '<rss') !== false) {
                $items = tw_parse_rss_feed($rN['body']);
                if (!empty($items)) $rN['parse_mode'] = 'rss';
            }
            if (empty($items) && stripos($rN['body'], '<html') !== false) {
                $items = tw_parse_nitter_html($rN['body'], $username);
                if (!empty($items)) $rN['parse_mode'] = 'html-from-rss-url';
            }
        }
        // If /rss gave us nothing parseable, try the bare /username page
        // — that's the path tw_fetch_via_nitter falls back to.
        if (empty($items)) {
            $urlH = 'https://' . $host . '/' . rawurlencode($username);
            $rH   = tw_debug_http($urlH, 8);
            if ($rH['body'] && stripos($rH['body'], '<html') !== false) {
                $items = tw_parse_nitter_html($rH['body'], $username);
                if (!empty($items)) {
                    $rN['parse_mode']  = 'html-from-profile';
                    $rN['profile_url'] = $urlH;
                    $rN['profile_size'] = $rH['size'];
                }
            }
        }
        $rN['parsed_count'] = count($items);
        if (!empty($items)) {
            // Show the newest-parsed timestamp so operators can see
            // how fresh this instance actually is.
            $rN['newest_posted_at'] = $items[0]['posted_at'] ?? null;
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
