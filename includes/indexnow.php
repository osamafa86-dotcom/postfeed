<?php
/**
 * نيوزفلو — IndexNow protocol client.
 *
 * IndexNow lets search engines (Bing, Yandex, Seznam, Naver) index a
 * URL within minutes of publication instead of waiting for the next
 * crawl. Google doesn't participate directly but Bing shares the
 * pings through IndexNow's aggregator so coverage is effectively
 * the whole non-Google search ecosystem.
 *
 * Flow:
 *   1. A random hex key is generated on first use and stored under
 *      /storage/.indexnow_key (outside the webroot by convention;
 *      we still serve it from /{key}.txt via a rewrite so search
 *      engines can verify ownership).
 *   2. indexnow_submit($urls) POSTs a JSON payload to the protocol
 *      endpoint. Non-blocking best-effort: failures are logged but
 *      never abort the caller (usually the cron).
 *
 * Docs: https://www.indexnow.org/documentation
 */

define('INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow');

/**
 * Return (and provision if needed) the site's IndexNow key.
 *
 * First checks the INDEXNOW_KEY env var (ops can pin a specific key);
 * falls back to a file under /storage that we auto-generate once.
 * Last-resort: a per-request random key, which still works but won't
 * survive verification on the next crawl — logs a warning so ops
 * notice and fix the storage permissions.
 */
function indexnow_key(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    $fromEnv = env('INDEXNOW_KEY', '');
    if ($fromEnv !== '' && ctype_xdigit($fromEnv)) {
        return $cached = $fromEnv;
    }

    $path = __DIR__ . '/../storage/.indexnow_key';
    if (is_readable($path)) {
        $k = trim((string)@file_get_contents($path));
        if ($k !== '' && ctype_xdigit($k)) return $cached = $k;
    }

    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $generated = bin2hex(random_bytes(16)); // 32-char hex, within the 8-128 range IndexNow requires
    if (@file_put_contents($path, $generated, LOCK_EX) !== false) {
        @chmod($path, 0600);
        return $cached = $generated;
    }

    error_log('indexnow: could not persist key to ' . $path . ' — falling back to ephemeral key');
    return $cached = $generated;
}

/** Absolute URL the search engine fetches to verify ownership. */
function indexnow_key_location(): string {
    return rtrim(SITE_URL, '/') . '/' . indexnow_key() . '.txt';
}

/**
 * Submit up to 10,000 URLs to IndexNow in a single call. Larger batches
 * are chunked. Returns ['ok' => bool, 'sent' => int, 'error' => string|null].
 *
 * All URLs must be on the same host as SITE_URL — IndexNow rejects
 * cross-host submissions (that's anti-spam by design).
 */
function indexnow_submit(array $urls): array {
    $urls = array_values(array_unique(array_filter($urls, 'is_string')));
    if (!$urls) return ['ok' => true, 'sent' => 0, 'error' => null];

    $host = parse_url(SITE_URL, PHP_URL_HOST) ?: '';
    if ($host === '') {
        return ['ok' => false, 'sent' => 0, 'error' => 'invalid SITE_URL'];
    }

    $key     = indexnow_key();
    $keyLoc  = indexnow_key_location();
    $sent    = 0;
    $lastErr = null;

    foreach (array_chunk($urls, 10000) as $chunk) {
        $payload = json_encode([
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => $keyLoc,
            'urlList'     => $chunk,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init(INDEXNOW_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        // 200 (accepted), 202 (accepted later). Anything else is a protocol
        // failure — log and continue with the next chunk so one bad batch
        // doesn't silently skip the rest.
        if ($code === 200 || $code === 202) {
            $sent += count($chunk);
        } else {
            $lastErr = 'http=' . $code . ($err ? ' curl=' . $err : '');
            error_log('indexnow: submit failed (' . $lastErr . ') for ' . count($chunk) . ' urls');
        }
    }

    return ['ok' => $lastErr === null, 'sent' => $sent, 'error' => $lastErr];
}

/**
 * Given a batch of freshly-inserted article ids, build their canonical
 * URLs and submit them. Safe to call with an empty array.
 */
function indexnow_submit_article_ids(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return ['ok' => true, 'sent' => 0, 'error' => null];

    $db = getDB();
    // PDO-friendly IN-clause for variable-length id lists.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, slug FROM articles WHERE id IN ($placeholders) AND status = 'published'");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $base = rtrim(SITE_URL, '/');
    $urls = array_map(fn($r) => $base . '/' . articleUrl($r), $rows);
    return indexnow_submit($urls);
}
