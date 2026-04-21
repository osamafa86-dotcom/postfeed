<?php
/**
 * Server-Sent Events stream for the YouTube section.
 *
 * Mirrors telegram_stream.php / twitter_stream.php. Lock file is
 * namespaced to nf_yt_sync so YouTube scrapes don't block the other
 * social streams.
 *
 * Query params:
 *   since_id — only stream videos with id > since_id
 */

ini_set('display_errors', '0');
error_reporting(0);
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

const YT_STREAM_MAX_SECS      = 55;
const YT_DB_POLL_SECS         = 1;
// YouTube's RSS only updates every ~10min on their end so scraping
// faster than that is wasted effort. 30s / 20s gives a good balance.
const YT_SCRAPE_EVERY_SECS    = 30;
const YT_SCRAPE_COOLDOWN_SECS = 20;
const YT_KEEPALIVE_SECS       = 15;
const YT_RECONNECT_MS         = 2000;

function yt_sse_send(string $event, $data): void {
    if ($event !== '' && $event !== 'message') {
        echo "event: " . $event . "\n";
    }
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
}

function yt_sse_comment(string $text): void {
    echo ": " . $text . "\n\n";
    @ob_flush();
    @flush();
}

function yt_stream_try_scrape(): bool {
    $lockFile = sys_get_temp_dir() . '/nf_yt_sync.lock';
    $now      = time();
    if (file_exists($lockFile)) {
        $age = $now - (int)@filemtime($lockFile);
        if ($age < YT_SCRAPE_COOLDOWN_SECS) return false;
    }
    $fp = @fopen($lockFile, 'c');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return false; }
    try {
        require_once __DIR__ . '/includes/youtube_fetch.php';
        yt_sync_all_sources();
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

function yt_stream_fetch_new(PDO $db, int $sinceId, int $limit = 20): array {
    $stmt = $db->prepare("SELECT v.id, v.source_id, v.post_url, v.title, v.description, v.thumbnail_url, v.posted_at,
                                 s.display_name, s.handle, s.avatar_url
                          FROM youtube_videos v
                          JOIN youtube_sources s ON v.source_id = s.id
                          WHERE v.is_active=1 AND s.is_active=1 AND v.id > ?
                          ORDER BY v.id DESC
                          LIMIT ?");
    $stmt->bindValue(1, $sinceId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $title = (string)($r['title'] ?? '');
        if (mb_strlen($title) > 200) $title = mb_substr($title, 0, 200) . '...';
        $out[] = [
            'id'            => (int)$r['id'],
            'url'           => $r['post_url'],
            'title'         => $title,
            'channel_name'  => $r['display_name'],
            'channel_handle'=> $r['handle'],
            'avatar_url'    => $r['avatar_url'],
            'thumbnail_url' => $r['thumbnail_url'],
            'posted_at'     => $r['posted_at'],
            'time_ago'      => timeAgo($r['posted_at']),
        ];
    }
    return $out;
}

echo "retry: " . YT_RECONNECT_MS . "\n\n";
@ob_flush(); @flush();

try {
    $db         = getDB();
    $sinceId    = max(0, (int)($_GET['since_id'] ?? 0));
    $startTs    = time();
    $lastKeep   = $startTs;
    $lastScrape = 0;

    yt_sse_send('hello', ['server_time' => date('c'), 'since_id' => $sinceId]);

    while (true) {
        if (connection_aborted()) break;

        $now = time();
        if ($now - $startTs >= YT_STREAM_MAX_SECS) {
            yt_sse_send('bye', ['reason' => 'max_lifetime']);
            break;
        }

        if ($now - $lastScrape >= YT_SCRAPE_EVERY_SECS) {
            $lastScrape = $now;
            yt_stream_try_scrape();
        }

        $videos = yt_stream_fetch_new($db, $sinceId);
        if (!empty($videos)) {
            foreach ($videos as $v) {
                if ($v['id'] > $sinceId) $sinceId = $v['id'];
            }
            yt_sse_send('messages', [
                'count'     => count($videos),
                'latest_id' => $sinceId,
                'messages'  => $videos,
            ]);
            $lastKeep = $now;
        } elseif ($now - $lastKeep >= YT_KEEPALIVE_SECS) {
            yt_sse_comment('keepalive ' . $now);
            $lastKeep = $now;
        }

        usleep((int)(YT_DB_POLL_SECS * 1000000));
    }
} catch (Throwable $e) {
    yt_sse_send('error', ['message' => 'stream error']);
}
