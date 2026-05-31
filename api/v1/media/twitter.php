<?php
/**
 * GET /api/v1/media/twitter?limit=&since_id=&sync=
 * Returns the aggregated Twitter/X stream.
 *
 * When sync=1, opportunistically triggers a real scrape behind a file
 * lock (same lock as twitter_feed.php on web) if the freshest DB row is
 * older than TWAPI_SYNC_IF_STALE_SECS — so mobile users get the same
 * freshness as web visitors instead of waiting for the cron.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('media:twitter', 240, 60);

const TWAPI_SYNC_COOLDOWN_SECS = 15;
const TWAPI_SYNC_IF_STALE_SECS = 30;

$limit    = max(1, min((int)($_GET['limit'] ?? 30), 100));
$sinceId  = max(0, (int)($_GET['since_id'] ?? 0));
$beforeId = max(0, (int)($_GET['before_id'] ?? 0));  // "load older" cursor
$syncReq  = !empty($_GET['sync']);

$db = getDB();

// Trigger a real scrape if requested and the DB is stale. Uses the
// same lock file as twitter_feed.php / twitter_stream.php so mobile
// polls and web viewers cooperate — only one scrape per cooldown
// across the whole system.
$syncResult = null;
if ($syncReq) {
    try {
        // Use created_at (DB insertion time) instead of posted_at —
        // Twitter timestamps can land in the future relative to the
        // server clock when timezone interpretation goes wrong, which
        // would make (time() - newestTs) negative and trick this check
        // into thinking the data is fresh forever.
        $row = $db->query("SELECT UNIX_TIMESTAMP(MAX(created_at)) AS ts FROM twitter_messages WHERE is_active=1")->fetch(PDO::FETCH_ASSOC);
        $newestTs = (int)($row['ts'] ?? 0);
        if ($newestTs === 0 || (time() - $newestTs) >= TWAPI_SYNC_IF_STALE_SECS) {
            $lockFile = sys_get_temp_dir() . '/nf_tw_sync.lock';
            $nowTs    = time();
            $canRun   = true;
            if (file_exists($lockFile)) {
                $age = $nowTs - (int)@filemtime($lockFile);
                if ($age < TWAPI_SYNC_COOLDOWN_SECS) {
                    $canRun = false;
                    $syncResult = ['synced' => false, 'reason' => 'cooldown', 'age' => $age];
                }
            }
            if ($canRun) {
                $fp = @fopen($lockFile, 'c');
                if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
                    try {
                        require_once __DIR__ . '/../../../includes/twitter_fetch.php';
                        $added = tw_sync_all_sources();
                        @ftruncate($fp, 0);
                        @fwrite($fp, (string)$nowTs);
                        @touch($lockFile, $nowTs);
                        $syncResult = ['synced' => true, 'added' => $added];
                    } catch (Throwable $e) {
                        $syncResult = ['synced' => false, 'reason' => 'exception'];
                    } finally {
                        flock($fp, LOCK_UN);
                        fclose($fp);
                    }
                } else {
                    if ($fp) fclose($fp);
                    $syncResult = ['synced' => false, 'reason' => 'lock_busy'];
                }
            }
        } else {
            $syncResult = ['synced' => false, 'reason' => 'fresh', 'age' => time() - $newestTs];
        }
    } catch (Throwable $e) {
        $syncResult = ['synced' => false, 'reason' => 'stale_check_failed'];
    }
}

$messages = [];
try {
    if ($sinceId > 0) {
        $stmt = $db->prepare("SELECT m.id, m.source_id, m.post_url, m.text, m.image_url, m.posted_at,
                                     s.display_name, s.username, s.avatar_url
                               FROM twitter_messages m
                               JOIN twitter_sources s ON m.source_id = s.id
                               WHERE m.is_active=1 AND s.is_active=1 AND m.id > ?
                               ORDER BY m.id DESC LIMIT ?");
        $stmt->bindValue(1, $sinceId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    } elseif ($beforeId > 0) {
        $stmt = $db->prepare("SELECT m.id, m.source_id, m.post_url, m.text, m.image_url, m.posted_at,
                                     s.display_name, s.username, s.avatar_url
                               FROM twitter_messages m
                               JOIN twitter_sources s ON m.source_id = s.id
                               WHERE m.is_active=1 AND s.is_active=1 AND m.id < ?
                               ORDER BY m.posted_at DESC, m.id DESC LIMIT ?");
        $stmt->bindValue(1, $beforeId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    } else {
        $stmt = $db->prepare("SELECT m.id, m.source_id, m.post_url, m.text, m.image_url, m.posted_at,
                                     s.display_name, s.username, s.avatar_url
                               FROM twitter_messages m
                               JOIN twitter_sources s ON m.source_id = s.id
                               WHERE m.is_active=1 AND s.is_active=1
                               ORDER BY m.posted_at DESC, m.id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $messages[] = [
            'id' => (int)$r['id'],
            'text' => $r['text'],
            'image_url' => api_image_url($r['image_url']),
            'post_url' => $r['post_url'],
            'posted_at' => $r['posted_at'],
            'source' => [
                'id' => (int)$r['source_id'],
                'display_name' => $r['display_name'],
                'username' => $r['username'],
                'avatar_url' => api_image_url($r['avatar_url']),
            ],
        ];
    }
} catch (Throwable $e) {
    error_log('twitter api: ' . $e->getMessage());
}

$meta = [
    'count' => count($messages),
    'latest_id' => $messages[0]['id'] ?? $sinceId,
];
if ($syncResult !== null) $meta['sync'] = $syncResult;
api_ok($messages, $meta);
