<?php
/**
 * GET  /api/v1/user/history          — recent reading history
 * POST /api/v1/user/history          — { article_id, dwell_seconds? }
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET', 'POST');
$user = api_require_user();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    [$page, $limit, $offset] = api_pagination(20, 100);
    $sql = articles_select_sql() . "
        INNER JOIN user_reading_history urh ON urh.article_id = a.id
        WHERE urh.user_id=? AND a.status='published'
        ORDER BY urh.read_at DESC LIMIT $limit OFFSET $offset";
    $st = $db->prepare($sql);
    $st->execute([(int)$user['id']]);
    $items = array_map('api_format_article', $st->fetchAll());
    api_ok($items, ['page' => $page, 'limit' => $limit]);
}

$body = api_body();
$aid = (int)($body['article_id'] ?? 0);
$dwell = max(0, min(7200, (int)($body['dwell_seconds'] ?? 0)));
if (!$aid) api_err('invalid_input', 'يلزم article_id', 422);

$db->prepare("INSERT INTO user_reading_history (user_id, article_id, dwell_seconds, read_at)
              VALUES (?,?,?,NOW())
              ON DUPLICATE KEY UPDATE dwell_seconds = dwell_seconds + VALUES(dwell_seconds), read_at = NOW()")
   ->execute([(int)$user['id'], $aid, $dwell]);

// Bump streak (read today).
$db->prepare("UPDATE users SET last_read_date=CURDATE(),
                                reading_streak = IF(last_read_date = CURDATE() - INTERVAL 1 DAY, reading_streak+1,
                                                    IF(last_read_date = CURDATE(), reading_streak, 1))
              WHERE id=?")->execute([(int)$user['id']]);

api_ok(['logged' => true]);
