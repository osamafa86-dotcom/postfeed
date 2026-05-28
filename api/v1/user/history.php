<?php
/**
 * GET  /api/v1/user/history          — recent reading history
 * POST /api/v1/user/history          — { article_id, dwell_seconds? }
 *
 * The actual user_reading_history schema uses `seconds_spent` (NOT
 * `dwell_seconds`) and has no UNIQUE KEY on (user_id, article_id), so
 * the old INSERT ... ON DUPLICATE KEY UPDATE silently 500'd on every
 * beacon. Switched to an UPDATE-or-INSERT pattern that doesn't need
 * the unique key, and added GROUP BY on the GET so any pre-existing
 * duplicates don't bloat the list.
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
        GROUP BY a.id
        ORDER BY MAX(urh.read_at) DESC
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    $st = $db->prepare($sql);
    $st->execute([(int)$user['id']]);
    $items = array_map('api_format_article', $st->fetchAll());
    api_ok($items, ['page' => $page, 'limit' => $limit]);
}

$body = api_body();
$aid = (int)($body['article_id'] ?? 0);
$dwell = max(0, min(7200, (int)($body['dwell_seconds'] ?? 0)));
if (!$aid) api_err('invalid_input', 'يلزم article_id', 422);

$st = $db->prepare("SELECT id FROM user_reading_history WHERE user_id=? AND article_id=? LIMIT 1");
$st->execute([(int)$user['id'], $aid]);
$existingId = (int)$st->fetchColumn();

if ($existingId) {
    $db->prepare("UPDATE user_reading_history
                  SET seconds_spent = seconds_spent + ?, read_at = NOW()
                  WHERE id = ?")
       ->execute([$dwell, $existingId]);
} else {
    $db->prepare("INSERT INTO user_reading_history (user_id, article_id, seconds_spent, read_at)
                  VALUES (?,?,?,NOW())")
       ->execute([(int)$user['id'], $aid, $dwell]);
}

// Bump streak (read today).
$db->prepare("UPDATE users SET last_read_date=CURDATE(),
                                reading_streak = IF(last_read_date = CURDATE() - INTERVAL 1 DAY, reading_streak+1,
                                                    IF(last_read_date = CURDATE(), reading_streak, 1))
              WHERE id=?")->execute([(int)$user['id']]);

api_ok(['logged' => true]);
