<?php
/**
 * User sources API — add / toggle / delete a user's own source.
 * POST, auth + CSRF required. Mirrors api/follow.php conventions.
 */
require __DIR__ . '/_json.php';
require_once __DIR__ . '/../includes/user_source_ingest.php';

require_post_json();
$uid = require_user_json();
require_csrf_json();
rate_limit_json('usrc', 60, 60);

$action = $_POST['action'] ?? '';

try {
    if ($action === 'add') {
        $r = user_source_add($uid, (string)($_POST['input'] ?? ''));
        if (empty($r['ok'])) json_out(['ok' => false, 'error' => $r['error'] ?? 'failed'], 400);
        // Best-effort first ingest so the user sees articles right away
        // (RSS/website only, no slow feed-guessing on the add request).
        $ingested = 0;
        if (in_array($r['type'], ['rss', 'website'], true)) {
            @set_time_limit(25);
            try {
                $ingested = user_source_ingest_one(
                    ['id' => $r['id'], 'user_id' => $uid, 'type' => $r['type'], 'url' => $r['url']],
                    25, false
                );
            } catch (Throwable $e) {}
        }
        json_out(['ok' => true, 'source' => $r, 'ingested' => $ingested]);
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $on = (string)($_POST['on'] ?? '') === '1';
        if (!$id) json_out(['ok' => false, 'error' => 'bad_request'], 400);
        user_source_set_active($uid, $id, $on);
        json_out(['ok' => true, 'active' => $on]);
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'bad_request'], 400);
        user_source_delete($uid, $id);
        json_out(['ok' => true]);
    } else {
        json_out(['ok' => false, 'error' => 'bad_action'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'server'], 500);
}
