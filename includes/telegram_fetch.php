<?php
/**
 * Telegram channel fetcher — scrapes the public t.me/s/{channel} preview.
 * No API key required.
 *
 * Anti-block design (the root fix for the recurring "works for a while then
 * stops" lag): t.me rate-limits / soft-blocks an IP that scrapes too hard.
 * The old code re-fetched ALL channels on every sync call (every ~30s while
 * a page was open) → ~1500+ requests/hour from one IP → periodic blocks that
 * lasted an hour or two, then lifted. We now:
 *   - stagger: live paths fetch only the few most-overdue channels per call
 *     (tg_sync_due_sources), each channel at most once per cooldown window;
 *   - rotate the User-Agent and add a small gap between channel fetches;
 *   - detect block/challenge/empty responses and record last_error so the
 *     health diagnostic can SEE a block instead of us guessing.
 */

/** Add the diagnostic columns we rely on (idempotent, self-healing). */
function tg_sources_ensure_cols(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach ([
        "ADD COLUMN last_error VARCHAR(300) DEFAULT NULL",
        "ADD COLUMN last_status SMALLINT DEFAULT NULL",
    ] as $ddl) {
        try { getDB()->exec("ALTER TABLE telegram_sources $ddl"); } catch (Throwable $e) { /* exists */ }
    }
}

/** A small pool of realistic browser UAs; one is picked per request. */
function tg_user_agent(): string {
    static $uas = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    ];
    return $uas[array_rand($uas)];
}

/**
 * Fetch + parse one channel. Returns a rich result so callers can tell a
 * genuine "no new posts" from a block/error:
 *   ['ok'=>bool, 'http'=>int, 'bytes'=>int, 'messages'=>[...], 'error'=>?string]
 */
function tg_fetch_channel_ex($username, $limit = 20): array {
    $username = ltrim((string)$username, '@');
    if ($username === '') return ['ok' => false, 'http' => 0, 'bytes' => 0, 'messages' => [], 'error' => 'empty_username'];
    $url = 'https://t.me/s/' . urlencode($username);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT      => tg_user_agent(),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ar,en-US;q=0.8,en;q=0.6',
            'Referer: https://t.me/' . $username,
        ],
    ]);
    $html = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($html === false || $html === null) {
        return ['ok' => false, 'http' => $http, 'bytes' => 0, 'messages' => [], 'error' => $err ?: 'curl_failed'];
    }
    $bytes = strlen($html);
    if ($http !== 200) {
        return ['ok' => false, 'http' => $http, 'bytes' => $bytes, 'messages' => [], 'error' => 'http_' . $http];
    }
    // A valid t.me/s/ page always carries the tgme_ widget markup. Its
    // absence means a challenge / rate-limit / empty page → treat as a block.
    if (strpos($html, 'tgme_') === false) {
        return ['ok' => false, 'http' => $http, 'bytes' => $bytes, 'messages' => [], 'error' => 'no_tgme_markup'];
    }

    $messages = [];
    if (preg_match_all('#<div class="tgme_widget_message[^"]*"[^>]*data-post="([^"]+)"[^>]*>(.*?)</div>\s*</div>\s*</div>#s', $html, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $b) {
            $postId = $b[1]; // channel/123
            $body   = $b[2];

            $text = '';
            if (preg_match('#<div class="tgme_widget_message_text[^"]*"[^>]*>(.*?)</div>#s', $body, $t)) {
                $text = trim(strip_tags(str_replace(['<br/>', '<br>', '<br />'], "\n", $t[1])));
            }
            $image = '';
            if (preg_match("#tgme_widget_message_photo_wrap[^\"]*\"[^>]*style=\"background-image:url\\('([^']+)'\\)#", $body, $img)) {
                $image = $img[1];
            }
            $datetime = null;
            if (preg_match('#<time[^>]*datetime="([^"]+)"#', $body, $dt)) {
                $datetime = $dt[1];
            }
            if (empty($text) && empty($image)) continue;

            $messages[] = [
                'post_id'    => $postId,
                'message_id' => (int)substr($postId, strrpos($postId, '/') + 1),
                'text'       => $text,
                'image_url'  => $image,
                'posted_at'  => $datetime ? date('Y-m-d H:i:s', strtotime($datetime)) : date('Y-m-d H:i:s'),
                'url'        => 'https://t.me/' . $postId,
            ];
        }
    }

    return [
        'ok'       => true,
        'http'     => $http,
        'bytes'    => $bytes,
        'messages' => array_slice(array_reverse($messages), 0, $limit),
        'error'    => null,
    ];
}

/**
 * Back-compat wrapper: returns just the messages array (used by
 * includes/user_source_ingest.php and any legacy caller).
 */
function tg_fetch_channel($username, $limit = 20) {
    $r = tg_fetch_channel_ex($username, $limit);
    return $r['messages'];
}

/**
 * Fetch one source row, store its new messages, and record fetch state.
 * Bumps last_fetched_at even on failure so we don't immediately re-hammer
 * a channel that's erroring. Returns the number of NEW messages stored.
 */
function tg_sync_one_source(PDO $db, array $src, int $limit = 20): int {
    $r   = tg_fetch_channel_ex($src['username'], $limit);
    $new = 0;
    if (!empty($r['ok'])) {
        $ins = $db->prepare("INSERT IGNORE INTO telegram_messages
            (source_id, message_id, post_url, text, image_url, posted_at)
            VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($r['messages'] as $m) {
            try {
                $ins->execute([
                    $src['id'], $m['message_id'], $m['url'],
                    $m['text'], $m['image_url'], $m['posted_at'],
                ]);
                if ($ins->rowCount() > 0) $new++;
            } catch (Exception $e) { /* dupe / transient */ }
        }
        try {
            $db->prepare("UPDATE telegram_sources SET last_fetched_at = NOW(), last_error = NULL, last_status = 200 WHERE id = ?")
               ->execute([$src['id']]);
        } catch (Throwable $e) {}
    } else {
        try {
            $db->prepare("UPDATE telegram_sources SET last_fetched_at = NOW(), last_error = ?, last_status = ? WHERE id = ?")
               ->execute([mb_substr((string)($r['error'] ?? 'error'), 0, 290), (int)($r['http'] ?? 0), $src['id']]);
        } catch (Throwable $e) {}
    }
    return $new;
}

/**
 * LIVE path: fetch only the few most-overdue channels (per-channel cooldown),
 * so traffic-driven syncing never bursts all channels at t.me. This is what
 * keeps the feed fresh without getting the IP blocked.
 */
function tg_sync_due_sources(int $maxChannels = 6, int $cooldownSecs = 75, int $limit = 20): int {
    $db = getDB();
    tg_sources_ensure_cols();
    try {
        $st = $db->prepare("SELECT * FROM telegram_sources
            WHERE is_active = 1
              AND (last_fetched_at IS NULL OR last_fetched_at < (NOW() - INTERVAL ? SECOND))
            ORDER BY last_fetched_at ASC, id ASC
            LIMIT ?");
        $st->bindValue(1, $cooldownSecs, PDO::PARAM_INT);
        $st->bindValue(2, $maxChannels, PDO::PARAM_INT);
        $st->execute();
        $sources = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return 0; }

    $total = 0;
    foreach ($sources as $i => $src) {
        if ($i > 0) usleep(200000); // 200ms gap → no burst
        $total += tg_sync_one_source($db, $src, $limit);
    }
    return $total;
}

/**
 * BACKBONE path (cron): sweep every active channel once, politely (small gap
 * between fetches). Order by oldest-fetched first so a partial/killed run
 * still makes forward progress next time.
 */
function tg_sync_all_sources(int $limit = 20): int {
    $db = getDB();
    tg_sources_ensure_cols();
    try {
        $sources = $db->query("SELECT * FROM telegram_sources WHERE is_active = 1
                                ORDER BY last_fetched_at ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return 0; }

    $total = 0;
    foreach ($sources as $i => $src) {
        if ($i > 0) usleep(200000);
        $total += tg_sync_one_source($db, $src, $limit);
    }
    return $total;
}
