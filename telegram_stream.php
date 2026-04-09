<?php
/**
 * Server-Sent Events stream for the Telegram page.
 *
 * Keeps a long-lived HTTP connection open and pushes new Telegram
 * messages to the browser the moment they land in the DB. While the
 * client is connected, this endpoint ALSO drives the scraper itself
 * (behind a file lock so concurrent viewers don't stampede), so
 * freshness is no longer bottlenecked by cron.
 *
 * Query params:
 *   since_id  — only stream messages with id > since_id
 *
 * Lifetime:
 *   A single connection lives up to STREAM_MAX_SECS (55s). After that we
 *   ask the client to reconnect via `retry:` and exit cleanly. The
 *   browser's EventSource auto-reconnects, so the stream feels endless.
 *
 * Behavior per tick:
 *   - Every DB_POLL_SECS (1s) we SELECT new messages and emit them as
 *     `message` events.
 *   - Every SCRAPE_EVERY_SECS (12s) we attempt a real Telegram scrape
 *     behind a flock'd temp file with SCRAPE_COOLDOWN_SECS (10s)
 *     cooldown — so across all viewers we hit t.me at most every ~10s.
 *   - Every KEEPALIVE_SECS (15s) we emit an SSE comment so proxies
 *     don't time the idle connection out.
 *
 * Shared-hosting caveats: we disable output buffering, flush explicitly,
 * set `X-Accel-Buffering: no` for nginx, and bail out as soon as
 * connection_aborted() returns true so PHP-FPM workers don't pile up.
 */

// Suppress any warnings that would corrupt the SSE stream
ini_set('display_errors', '0');
error_reporting(0);
// Ensure no output buffers swallow our flushes
while (ob_get_level() > 0) { ob_end_clean(); }

ignore_user_abort(false);
set_time_limit(0);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');    // nginx / FastCGI: disable response buffering

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

const STREAM_MAX_SECS      = 55;   // close after this, client reconnects
const DB_POLL_SECS         = 1;    // check DB for new rows this often
const SCRAPE_EVERY_SECS    = 12;   // attempt a live scrape this often (per-connection)
const SCRAPE_COOLDOWN_SECS = 10;   // global minimum gap between real scrapes
const KEEPALIVE_SECS       = 15;   // emit idle SSE comment this often
const RECONNECT_MS         = 2000; // client reconnect delay

/** Emit a standard SSE "message" frame. */
function sse_send(string $event, $data): void {
    if ($event !== '' && $event !== 'message') {
        echo "event: " . $event . "\n";
    }
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
}

/** Emit an SSE comment — keeps proxies happy on idle connections. */
function sse_comment(string $text): void {
    echo ": " . $text . "\n\n";
    @ob_flush();
    @flush();
}

/**
 * Attempt a real Telegram scrape under a temp-file lock. Bounded to one
 * real sync per SCRAPE_COOLDOWN_SECS across all connected clients.
 */
function tg_stream_try_scrape(): bool {
    $lockFile = sys_get_temp_dir() . '/nf_tg_sync.lock';
    $now      = time();
    if (file_exists($lockFile)) {
        $age = $now - (int)@filemtime($lockFile);
        if ($age < SCRAPE_COOLDOWN_SECS) return false;
    }
    $fp = @fopen($lockFile, 'c');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return false; }
    try {
        require_once __DIR__ . '/includes/telegram_fetch.php';
        tg_sync_all_sources();
        @ftruncate($fp, 0);
        @fwrite($fp, (string)$now);
        @touch($lockFile, $now);
        return true;
    } catch (Throwable $e) {
        return false;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/** Fetch messages strictly newer than $sinceId, newest first. */
function tg_stream_fetch_new(PDO $db, int $sinceId, int $limit = 20): array {
    $stmt = $db->prepare("SELECT m.id, m.source_id, m.post_url, m.text, m.image_url, m.posted_at,
                                 s.display_name, s.username, s.avatar_url
                          FROM telegram_messages m
                          JOIN telegram_sources s ON m.source_id = s.id
                          WHERE m.is_active=1 AND s.is_active=1 AND m.id > ?
                          ORDER BY m.id DESC
                          LIMIT ?");
    $stmt->bindValue(1, $sinceId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $text = (string)($r['text'] ?? '');
        if (mb_strlen($text) > 600) $text = mb_substr($text, 0, 600) . '...';
        $out[] = [
            'id'         => (int)$r['id'],
            'url'        => $r['post_url'],
            'username'   => $r['username'],
            'name'       => $r['display_name'],
            'avatar_url' => $r['avatar_url'],
            'image_url'  => $r['image_url'],
            'text'       => $text,
            'posted_at'  => $r['posted_at'],
            'time_ago'   => timeAgo($r['posted_at']),
        ];
    }
    return $out;
}

// ---------- Stream loop ----------

// Tell EventSource how long to wait before reconnecting on drop.
echo "retry: " . RECONNECT_MS . "\n\n";
@ob_flush(); @flush();

try {
    $db       = getDB();
    $sinceId  = max(0, (int)($_GET['since_id'] ?? 0));
    $startTs  = time();
    $lastKeep = $startTs;
    $lastScrape = 0; // force one scrape attempt on first iteration

    sse_send('hello', ['server_time' => date('c'), 'since_id' => $sinceId]);

    while (true) {
        // Client closed the tab? Get out ASAP so PHP-FPM workers don't linger.
        if (connection_aborted()) break;

        $now = time();
        if ($now - $startTs >= STREAM_MAX_SECS) {
            // Clean exit — client will reconnect automatically.
            sse_send('bye', ['reason' => 'max_lifetime']);
            break;
        }

        // 1) Kick the scraper if it's been a while. The lock makes this
        //    cheap when other viewers already did it.
        if ($now - $lastScrape >= SCRAPE_EVERY_SECS) {
            $lastScrape = $now;
            tg_stream_try_scrape();
        }

        // 2) Check the DB for anything newer than what the client has.
        $messages = tg_stream_fetch_new($db, $sinceId);
        if (!empty($messages)) {
            // Newest first from the query; update cursor to the highest id.
            foreach ($messages as $m) {
                if ($m['id'] > $sinceId) $sinceId = $m['id'];
            }
            sse_send('messages', [
                'count'     => count($messages),
                'latest_id' => $sinceId,
                'messages'  => $messages,
            ]);
            $lastKeep = $now; // real traffic counts as keepalive
        } elseif ($now - $lastKeep >= KEEPALIVE_SECS) {
            sse_comment('keepalive ' . $now);
            $lastKeep = $now;
        }

        // Sleep one tick. usleep gives us sub-second granularity if we
        // ever want DB_POLL_SECS < 1.
        usleep((int)(DB_POLL_SECS * 1000000));
    }
} catch (Throwable $e) {
    // Don't leak stack traces — just tell the client to retry.
    sse_send('error', ['message' => 'stream error']);
}
