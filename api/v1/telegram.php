<?php
/**
 * GET /api/v1/telegram.php — Telegram feed messages across active channels.
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET');
api_rate_limit('telegram', 120, 60);

$limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 50)) : 24;
$sourceId = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
$latestId = isset($_GET['latest_id']) ? (int)$_GET['latest_id'] : 0;

try {
    $db = getDB();

    $sources = [];
    try {
        $sources = $db->query("SELECT id, username, display_name, avatar_url
                               FROM telegram_sources WHERE is_active = 1
                               ORDER BY sort_order ASC, id ASC")->fetchAll();
    } catch (Throwable $e) {
        api_json(['ok' => true, 'sources' => [], 'items' => [], 'count' => 0]);
    }

    $where = ['m.is_active = 1'];
    $params = [];
    if ($sourceId > 0) { $where[] = 'm.source_id = ?'; $params[] = $sourceId; }
    if ($latestId > 0) { $where[] = 'm.id > ?';       $params[] = $latestId; }

    $sql = "SELECT m.id, m.source_id, m.message_id, m.post_url, m.text, m.image_url, m.posted_at,
                   s.username, s.display_name, s.avatar_url
            FROM telegram_messages m
            LEFT JOIN telegram_sources s ON s.id = m.source_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.posted_at DESC
            LIMIT ?";
    $stmt = $db->prepare($sql);
    $i = 1;
    foreach ($params as $p) $stmt->bindValue($i++, $p);
    $stmt->bindValue($i, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $items = array_map(fn($r) => [
        'id' => (int)$r['id'],
        'message_id' => (int)$r['message_id'],
        'post_url' => $r['post_url'],
        'text' => mb_strlen((string)$r['text']) > 600 ? mb_substr((string)$r['text'], 0, 600) . '…' : (string)$r['text'],
        'image_url' => $r['image_url'],
        'posted_at' => $r['posted_at'],
        'source' => [
            'id' => (int)$r['source_id'],
            'username' => $r['username'],
            'name' => $r['display_name'],
            'avatar_url' => $r['avatar_url'],
        ],
    ], $rows);

    api_json([
        'ok' => true,
        'count' => count($items),
        'items' => $items,
        'sources' => array_map(fn($s) => [
            'id' => (int)$s['id'],
            'username' => $s['username'],
            'name' => $s['display_name'],
            'avatar_url' => $s['avatar_url'],
        ], $sources),
    ]);
} catch (Throwable $e) {
    error_log('v1/telegram: ' . $e->getMessage());
    api_error('server_error', '', 500);
}
