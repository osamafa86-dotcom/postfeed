<?php
/**
 * Lightweight JSON feed for the /telegram.php page and the homepage's
 * Telegram section. Used for near-real-time polling.
 *
 * GET params:
 *   since_id  — only return messages with id > since_id (for incremental polls)
 *   limit     — max number of messages to return (default 20, max 50)
 *   sync      — if "1" and the newest message is older than SYNC_IF_STALE_SECS,
 *               trigger tg_sync_all_sources() under a file lock so caller
 *               traffic actually drives updates. Bounded to one real sync per
 *               SYNC_COOLDOWN_SECS to prevent stampedes.
 *
 * Response JSON:
 *   { ok: true, count, latest_id, server_time, messages: [ ... ] }
 */

ini_set('display_errors', '0');
error_reporting(0);
while (ob_get_level() > 0) { ob_end_clean(); }
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function tgf_json_exit(array $p): void {
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
    tgf_json_exit(['ok' => false, 'error' => 'init: ' . $e->getMessage()]);
}

const SYNC_COOLDOWN_SECS   = 45;  // never real-sync more than once per this window
const SYNC_IF_STALE_SECS   = 60;  // only sync when newest message is older than this

$sinceId = max(0, (int)($_GET['since_id'] ?? 0));
$limit   = max(1, min(50, (int)($_GET['limit'] ?? 20)));
$syncReq = !empty($_GET['sync']);

/**
 * Attempt to drive a Telegram sync if the last one is stale and the
 * cooldown has elapsed. Uses a file lock so concurrent visitors don't
 * all fire the expensive scraper at once.
 */
function tgf_try_sync(): array {
    $lockFile = sys_get_temp_dir() . '/nf_tg_sync.lock';
    $now      = time();

    // Don't even bother if we synced recently
    if (file_exists($lockFile)) {
        $age = $now - (int)@filemtime($lockFile);
        if ($age < SYNC_COOLDOWN_SECS) {
            return ['synced' => false, 'reason' => 'cooldown', 'age' => $age];
        }
    }

    // Non-blocking lock — first caller wins, the rest get skipped
    $fp = @fopen($lockFile, 'c');
    if (!$fp) return ['synced' => false, 'reason' => 'lock_open_failed'];
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return ['synced' => false, 'reason' => 'lock_busy'];
    }

    try {
        require_once __DIR__ . '/includes/telegram_fetch.php';
        $added = tg_sync_all_sources();
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

    // Decide whether we need to sync on-demand. We check the newest message
    // age — if it's older than the staleness threshold AND the caller opted
    // in, run tg_sync_all_sources() behind a file lock.
    $syncResult = null;
    if ($syncReq) {
        $row = $db->query("SELECT UNIX_TIMESTAMP(MAX(COALESCE(posted_at, created_at))) AS ts FROM telegram_messages WHERE is_active=1")->fetch(PDO::FETCH_ASSOC);
        $newestTs = (int)($row['ts'] ?? 0);
        if ($newestTs === 0 || (time() - $newestTs) >= SYNC_IF_STALE_SECS) {
            $syncResult = tgf_try_sync();
        } else {
            $syncResult = ['synced' => false, 'reason' => 'fresh', 'age' => time() - $newestTs];
        }
    }

    // Fetch newest messages (optionally only newer than since_id)
    if ($sinceId > 0) {
        $stmt = $db->prepare("SELECT m.id, m.source_id, m.post_url, m.text, m.image_url, m.posted_at,
                                     s.display_name, s.username, s.avatar_url
                               FROM telegram_messages m
                               JOIN telegram_sources s ON m.source_id = s.id
                               WHERE m.is_active=1 AND s.is_active=1 AND m.id > ?
                               ORDER BY m.id DESC
                               LIMIT ?");
        $stmt->bindValue(1, $sinceId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    } else {
        $stmt = $db->prepare("SELECT m.id, m.source_id, m.post_url, m.text, m.image_url, m.posted_at,
                                     s.display_name, s.username, s.avatar_url
                               FROM telegram_messages m
                               JOIN telegram_sources s ON m.source_id = s.id
                               WHERE m.is_active=1 AND s.is_active=1
                               ORDER BY m.posted_at DESC, m.id DESC
                               LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $messages = [];
    $latestId = $sinceId;
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        if ($id > $latestId) $latestId = $id;
        // Trim text for wire-size
        $text = (string)($r['text'] ?? '');
        if (mb_strlen($text) > 600) $text = mb_substr($text, 0, 600) . '...';
        $messages[] = [
            'id'          => $id,
            'url'         => $r['post_url'],
            'username'    => $r['username'],
            'name'        => $r['display_name'],
            'avatar_url'  => $r['avatar_url'],
            'image_url'   => $r['image_url'],
            'text'        => $text,
            'posted_at'   => $r['posted_at'],
            'time_ago'    => timeAgo($r['posted_at']),
        ];
    }

    tgf_json_exit([
        'ok'          => true,
        'count'       => count($messages),
        'latest_id'   => $latestId,
        'server_time' => date('c'),
        'sync'        => $syncResult,
        'messages'    => $messages,
    ]);

} catch (Throwable $e) {
    tgf_json_exit(['ok' => false, 'error' => 'query: ' . $e->getMessage()]);
}
