<?php
/**
 * Ingestion for user-owned sources (stage 2).
 *
 * Fetches RSS / website feeds the user added and stores items in
 * `user_source_articles` (kept separate from the global `articles` table so
 * a user's private picks never leak into the public site). Telegram / X /
 * YouTube user sources are ingested later via the platform pipelines.
 */
require_once __DIR__ . '/user_sources.php';

/** Self-healing storage for ingested user-source items. */
function user_source_articles_ensure(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS user_source_articles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_source_id INT NOT NULL,
            user_id INT NOT NULL,
            title VARCHAR(500) NOT NULL,
            url VARCHAR(700) NOT NULL,
            image_url VARCHAR(700) DEFAULT NULL,
            excerpt TEXT DEFAULT NULL,
            published_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_src_url (user_source_id, url(255)),
            KEY idx_user_pub (user_id, published_at),
            KEY idx_src (user_source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* migrate.php covers it */ }
}

/** Simple HTTP GET with redirects + lenient SSL (feeds are often misconfigured). */
function usrc_http_get(string $url, int $timeout = 10): ?string {
    if (!preg_match('#^https?://#i', $url)) return null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT      => 'NewsFlowUserSrc/1.0 (+https://feedsnews.net)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING       => '',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 400) ? (string) $body : null;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'user_agent' => 'NewsFlowUserSrc/1.0', 'follow_location' => 1, 'max_redirects' => 4],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

function usrc_abs_url(string $href, string $base): string {
    $href = trim($href);
    if (preg_match('#^https?://#i', $href)) return $href;
    $p = parse_url($base);
    if (!$p || empty($p['host'])) return $href;
    $root = $p['scheme'] . '://' . $p['host'];
    if (strpos($href, '//') === 0) return ($p['scheme'] ?? 'https') . ':' . $href;
    if ($href !== '' && $href[0] === '/') return $root . $href;
    return rtrim($base, '/') . '/' . ltrim($href, '/');
}

/** Resolve a feed URL for a website (rss link discovery, then common guesses). */
function usrc_resolve_feed(string $pageUrl, bool $guess = true): ?string {
    $html = usrc_http_get($pageUrl, 10);
    if ($html) {
        if (preg_match_all('#<link\b[^>]*>#i', $html, $mm)) {
            foreach ($mm[0] as $tag) {
                if (preg_match('#type\s*=\s*["\']application/(?:rss|atom)\+xml["\']#i', $tag)
                    && preg_match('#href\s*=\s*["\']([^"\']+)["\']#i', $tag, $h)) {
                    return usrc_abs_url(html_entity_decode($h[1]), $pageUrl);
                }
            }
        }
    }
    if (!$guess) return null;
    $p = parse_url($pageUrl);
    if (!$p || empty($p['host'])) return null;
    $base = $p['scheme'] . '://' . $p['host'];
    foreach (['/feed', '/rss', '/feed.xml', '/index.xml', '/rss.xml'] as $g) {
        $b = usrc_http_get($base . $g, 8);
        if ($b && preg_match('#<(rss|feed|rdf)[\s>:]#i', $b)) return $base . $g;
    }
    return null;
}

function usrc_date(string $raw): string {
    if ($raw !== '') { $ts = strtotime($raw); if ($ts) return date('Y-m-d H:i:s', $ts); }
    return date('Y-m-d H:i:s');
}

/** Parse an RSS 2.0 / RDF / Atom feed body into normalized items. */
function usrc_parse_feed(string $body): array {
    if (trim($body) === '') return [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    if ($xml === false) return [];
    $items = [];
    $add = function ($title, $link, $desc, $date, $img) use (&$items) {
        $t = trim(strip_tags((string) $title));
        $u = trim((string) $link);
        if ($t === '' || $u === '') return;
        $items[] = [
            'title'        => $t,
            'url'          => $u,
            'excerpt'      => mb_substr(trim(strip_tags((string) $desc)), 0, 1000),
            'published_at' => usrc_date((string) $date),
            'image'        => (string) $img,
        ];
    };
    // RSS 2.0 / RDF
    $itemset = isset($xml->channel->item) ? $xml->channel->item : (isset($xml->item) ? $xml->item : null);
    if ($itemset) {
        foreach ($itemset as $it) {
            $img = '';
            $media = $it->children('http://search.yahoo.com/mrss/');
            if (isset($media->content) && $media->content->attributes()) {
                $img = (string) $media->content->attributes()->url;
            }
            if ($img === '' && isset($it->enclosure) && $it->enclosure->attributes()) {
                $att = $it->enclosure->attributes();
                if (stripos((string) ($att->type ?? ''), 'image') !== false || (string)($att->url ?? '') !== '') {
                    $img = (string) $att->url;
                }
            }
            if ($img === '' && preg_match('#<img[^>]+src=["\']([^"\']+)["\']#i', (string) $it->description, $m)) {
                $img = $m[1];
            }
            $add($it->title, $it->link, $it->description, $it->pubDate, $img);
        }
        return $items;
    }
    // Atom
    if (isset($xml->entry)) {
        foreach ($xml->entry as $e) {
            $link = '';
            if (isset($e->link)) {
                foreach ($e->link as $l) {
                    if ((string) $l['rel'] === 'alternate' || $link === '') $link = (string) $l['href'];
                }
            }
            $add($e->title, $link, ($e->summary ?? $e->content ?? ''), ($e->published ?? $e->updated ?? ''), '');
        }
    }
    return $items;
}

function usrc_mark_fetched(int $sourceId): void {
    try {
        $db = getDB();
        $db->prepare("UPDATE user_sources us SET last_fetched_at = NOW(),
                        article_count = (SELECT COUNT(*) FROM user_source_articles a WHERE a.user_source_id = us.id)
                      WHERE us.id = ?")->execute([$sourceId]);
    } catch (Throwable $e) {}
}

/** Ingest one source row. Returns number of NEW items stored. */
function user_source_ingest_one(array $src, int $maxItems = 25, bool $guess = true): int {
    user_source_articles_ensure();
    $type = $src['type'] ?? '';
    $feed = null;
    if ($type === 'rss') $feed = $src['url'];
    elseif ($type === 'website') $feed = usrc_resolve_feed($src['url'], $guess);
    else return 0; // telegram/x/youtube handled by platform pipelines later
    if (!$feed) { usrc_mark_fetched((int) $src['id']); return 0; }

    $body = usrc_http_get($feed, 12);
    if (!$body) { usrc_mark_fetched((int) $src['id']); return 0; }
    $items = usrc_parse_feed($body);

    $db = getDB();
    $ins = $db->prepare("INSERT IGNORE INTO user_source_articles
        (user_source_id, user_id, title, url, image_url, excerpt, published_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $n = 0;
    foreach (array_slice($items, 0, $maxItems) as $it) {
        try {
            $ins->execute([
                (int) $src['id'], (int) $src['user_id'],
                mb_substr($it['title'], 0, 500), mb_substr($it['url'], 0, 700),
                ($it['image'] ?? '') !== '' ? mb_substr($it['image'], 0, 700) : null,
                ($it['excerpt'] ?? '') !== '' ? $it['excerpt'] : null,
                $it['published_at'] ?? date('Y-m-d H:i:s'),
            ]);
            if ($ins->rowCount() > 0) $n++;
        } catch (Throwable $e) {}
    }
    usrc_mark_fetched((int) $src['id']);
    return $n;
}

/** Ingest active rss/website sources that are due (cron entry). */
function user_source_ingest_due(int $limit = 15, int $minMinutes = 20): array {
    user_sources_ensure();
    user_source_articles_ensure();
    try {
        $db = getDB();
        $st = $db->prepare("SELECT * FROM user_sources
            WHERE is_active = 1 AND type IN ('rss','website')
              AND (last_fetched_at IS NULL OR last_fetched_at < (NOW() - INTERVAL ? MINUTE))
            ORDER BY (last_fetched_at IS NULL) DESC, last_fetched_at ASC
            LIMIT ?");
        $st->bindValue(1, $minMinutes, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        $srcs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return ['processed' => 0, 'new' => 0, 'error' => $e->getMessage()]; }

    $new = 0; $proc = 0;
    foreach ($srcs as $s) {
        try { $new += user_source_ingest_one($s); } catch (Throwable $e) {}
        $proc++;
    }
    return ['processed' => $proc, 'new' => $new];
}

/** The personal feed: items from the user's active sources, newest first. */
function user_feed(int $uid, int $limit = 30, ?int $sourceId = null): array {
    user_source_articles_ensure();
    try {
        $db = getDB();
        $sql = "SELECT a.*, s.name AS source_name, s.type AS source_type, s.handle AS source_handle
                FROM user_source_articles a
                JOIN user_sources s ON a.user_source_id = s.id
                WHERE a.user_id = ? AND s.is_active = 1";
        $params = [$uid];
        if ($sourceId) { $sql .= " AND a.user_source_id = ?"; $params[] = $sourceId; }
        $sql .= " ORDER BY a.published_at DESC, a.id DESC LIMIT " . (int) $limit;
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}
