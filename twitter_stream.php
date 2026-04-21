<?php
/**
 * Server-Sent Events stream for the Twitter/X section.
 *
 * Mirrors telegram_stream.php one-for-one — same lifetime, same keepalive
 * cadence, same on-connection scraping pattern — so the live behavior is
 * identical on both sections. Lock file is namespaced to nf_tw_sync so
 * Twitter and Telegram scrapes don't block each other.
 *
 * Query params:
 *   since_id  — only stream messages with id > since_id
 */

// Suppress any warnings that would corrupt the SSE stream
ini_set('display_errors', '0');
error_reporting(0);
// Make sure no output buffers swallow our flushes
while (ob_get_level() > 0) { ob_end_clean(); }

ignore_user_abort(false);
set_time_limit(0);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

const TW_STREAM_MAX_SECS      = 55;
const TW_DB_POLL_SECS         = 1;
const TW_SCRAPE_EVERY_SECS    = 20;   // Twitter is ratelimit-sensitive; be less aggressive than tg
const TW_SCRAPE_COOLDOWN_SECS = 15;
const TW_KEEPALIVE_SECS       = 15;
const TW_RECONNECT_MS         = 2000;

function tw_sse_send(string $event, $data): void {
    if ($event !== '' && $event !== 'message') {
        echo "event: " . $event . "\n";
    }
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
}

function tw_sse_comment(string $text): void {
    echo ": " . $text . "\n\n";
    @ob_flush();
    @flush();
}

function tw_stream_try_scrape(): bool {
    $lockFile = sys_get_temp_dir() . '/nf_tw_sync.lock';
    $now      = time();
    if (file_exists($lockFile)) {
        $age = $now - (int)@filemtime($lockFile);
        if ($age < TW_SCRAPE_COOLDOWN_SECS) return false;
    }
    $fp = @fopen($lockFile, 'c');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return false; }
    try {
        require_once __DIR__ . '/includes/twitter_fetch.php';
        tw_sync_all_sources();
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

function tw_stream_fetch_new(PDO $db, int $sinceId, int $limit = 20): array {
    $stmt = $db->prepare("SELECT m.id, m.source_id, m.post_url, m.text, m.image_url, m.posted_at,
                                 s.display_name, s.username, s.avatar_url
                          FROM twitter_messages m
                          JOIN twitter_sources s ON m.source_id = s.id
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

echo "retry: " . TW_RECONNECT_MS . "\n\n";
@ob_flush(); @flush();

try {
    $db        = getDB();
    $sinceId   = max(0, (int)($_GET['since_id'] ?? 0));
    $startTs   = time();
    $lastKeep  = $startTs;
    $lastScrape = 0;

    tw_sse_send('hello', ['server_time' => date('c'), 'since_id' => $sinceId]);

    while (true) {
        if (connection_aborted()) break;

        $now = time();
        if ($now - $startTs >= TW_STREAM_MAX_SECS) {
            tw_sse_send('bye', ['reason' => 'max_lifetime']);
            break;
        }

        if ($now - $lastScrape >= TW_SCRAPE_EVERY_SECS) {
            $lastScrape = $now;
            tw_stream_try_scrape();
        }

        $messages = tw_stream_fetch_new($db, $sinceId);
        if (!empty($messages)) {
            foreach ($messages as $m) {
                if ($m['id'] > $sinceId) $sinceId = $m['id'];
            }
            tw_sse_send('messages', [
                'count'     => count($messages),
                'latest_id' => $sinceId,
                'messages'  => $messages,
            ]);
            $lastKeep = $now;
        } elseif ($now - $lastKeep >= TW_KEEPALIVE_SECS) {
            tw_sse_comment('keepalive ' . $now);
            $lastKeep = $now;
        }

        usleep((int)(TW_DB_POLL_SECS * 1000000));
    }
} catch (Throwable $e) {
    tw_sse_send('error', ['message' => 'stream error']);
}
