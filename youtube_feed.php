<?php
/**
 * JSON feed for the homepage YouTube section. Polling fallback used by
 * youtube-live.js when Server-Sent Events isn't available.
 *
 * GET params:
 *   since_id — only return videos newer than this
 *   limit    — 1..50, default 20
 *   sync     — "1" triggers a real scrape behind a file lock if the
 *              freshest DB row is older than SYNC_IF_STALE_SECS and the
 *              global cooldown has elapsed.
 */

ini_set('display_errors', '0');
error_reporting(0);
while (ob_get_level() > 0) { ob_end_clean(); }
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function ytf_json_exit(array $p): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

register_shutdown_function(function(){
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Server error: ' . $err['message']], JSON_UNESCAPED_UNICODE);
    }
});

try {
    require_once __DIR__ . '/includes/config.php';
    require_once __DIR__ . '/includes/functions.php';
} catch (Throwable $e) {
    ytf_json_exit(['ok' => false, 'error' => 'init: ' . $e->getMessage()]);
}

const YTF_SYNC_COOLDOWN_SECS = 120; // YouTube RSS refreshes ~every 10min
const YTF_SYNC_IF_STALE_SECS = 180;

$sinceId = max(0, (int)($_GET['since_id'] ?? 0));
$limit   = max(1, min(50, (int)($_GET['limit'] ?? 20)));
$syncReq = !empty($_GET['sync']);

function ytf_try_sync(): array {
    $lockFile = sys_get_temp_dir() . '/nf_yt_sync.lock';
    $now      = time();
    if (file_exists($lockFile)) {
        $age = $now - (int)@filemtime($lockFile);
        if ($age < YTF_SYNC_COOLDOWN_SECS) {
            return ['synced' => false, 'reason' => 'cooldown', 'age' => $age];
        }
    }
    $fp = @fopen($lockFile, 'c');
    if (!$fp) return ['synced' => false, 'reason' => 'lock_open_failed'];
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return ['synced' => false, 'reason' => 'lock_busy'];
    }
    try {
        require_once __DIR__ . '/includes/youtube_fetch.php';
        $added = yt_sync_all_sources();
        @ftruncate($fp, 0);
        @fwrite($fp, (string)$now);
        @touch($lockFile, $now);
        return ['synced' => true, 'added' => $added];
    } catch (Throwable $e) {
        return ['synced' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

try {
    $db = getDB();

    $syncResult = null;
    if ($syncReq) {
        $row = $db->query("SELECT UNIX_TIMESTAMP(MAX(COALESCE(posted_at, created_at))) AS ts FROM youtube_videos WHERE is_active=1")->fetch(PDO::FETCH_ASSOC);
        $newestTs = (int)($row['ts'] ?? 0);
        if ($newestTs === 0 || (time() - $newestTs) >= YTF_SYNC_IF_STALE_SECS) {
            $syncResult = ytf_try_sync();
        } else {
            $syncResult = ['synced' => false, 'reason' => 'fresh', 'age' => time() - $newestTs];
        }
    }

    if ($sinceId > 0) {
        $stmt = $db->prepare("SELECT v.id, v.source_id, v.post_url, v.title, v.description, v.thumbnail_url, v.posted_at,
                                     s.display_name, s.handle, s.avatar_url
                               FROM youtube_videos v
                               JOIN youtube_sources s ON v.source_id = s.id
                               WHERE v.is_active=1 AND s.is_active=1 AND v.id > ?
                               ORDER BY v.id DESC
                               LIMIT ?");
        $stmt->bindValue(1, $sinceId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    } else {
        $stmt = $db->prepare("SELECT v.id, v.source_id, v.post_url, v.title, v.description, v.thumbnail_url, v.posted_at,
                                     s.display_name, s.handle, s.avatar_url
                               FROM youtube_videos v
                               JOIN youtube_sources s ON v.source_id = s.id
                               WHERE v.is_active=1 AND s.is_active=1
                               ORDER BY v.posted_at DESC, v.id DESC
                               LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $videos = [];
    $latestId = $sinceId;
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        if ($id > $latestId) $latestId = $id;
        $title = (string)($r['title'] ?? '');
        if (mb_strlen($title) > 200) $title = mb_substr($title, 0, 200) . '...';
        $videos[] = [
            'id'             => $id,
            'url'            => $r['post_url'],
            'title'          => $title,
            'channel_name'   => $r['display_name'],
            'channel_handle' => $r['handle'],
            'avatar_url'     => $r['avatar_url'],
            'thumbnail_url'  => $r['thumbnail_url'],
            'posted_at'      => $r['posted_at'],
            'time_ago'       => timeAgo($r['posted_at']),
        ];
    }

    ytf_json_exit([
        'ok'          => true,
        'count'       => count($videos),
        'latest_id'   => $latestId,
        'server_time' => date('c'),
        'sync'        => $syncResult,
        'messages'    => $videos,
    ]);

} catch (Throwable $e) {
    ytf_json_exit(['ok' => false, 'error' => 'query: ' . $e->getMessage()]);
}
