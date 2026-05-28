<?php
/**
 * GET /api/v1/content/for-you
 *
 * Personalized feed based on the authenticated user's follows.
 * Without login it just returns the latest items — the home tab
 * hides the "خاص بك" rail when this list is empty, so an empty
 * response is also a valid "we have nothing for you yet" state.
 *
 * Previously this endpoint didn't exist; every authenticated home
 * load fired a 404 in the logs.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:for-you', 240, 60);

[$page, $limit, $offset] = api_pagination(20, 50);

$user = api_optional_user();
$items = [];

try {
    $db = getDB();

    if ($user) {
        $uid = (int)$user['id'];
        // Categories the user follows.
        $cats = $db->prepare("SELECT category_id FROM user_category_follows WHERE user_id=?");
        $cats->execute([$uid]);
        $catIds = array_map('intval', $cats->fetchAll(PDO::FETCH_COLUMN));

        // Sources the user follows.
        $srcs = $db->prepare("SELECT source_id FROM user_source_follows WHERE user_id=?");
        $srcs->execute([$uid]);
        $srcIds = array_map('intval', $srcs->fetchAll(PDO::FETCH_COLUMN));

        if (!empty($catIds) || !empty($srcIds)) {
            $where = ["a.status='published'"];
            $params = [];

            $orParts = [];
            if (!empty($catIds)) {
                $ph = implode(',', array_fill(0, count($catIds), '?'));
                $orParts[] = "a.category_id IN ($ph)";
                $params = array_merge($params, $catIds);
            }
            if (!empty($srcIds)) {
                $ph = implode(',', array_fill(0, count($srcIds), '?'));
                $orParts[] = "a.source_id IN ($ph)";
                $params = array_merge($params, $srcIds);
            }
            $where[] = '(' . implode(' OR ', $orParts) . ')';

            $sql = articles_select_sql() . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.published_at DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
            $st = $db->prepare($sql);
            $st->execute($params);
            $items = array_map('api_format_article', $st->fetchAll());
        }
    }
} catch (Throwable $e) {
    error_log('for-you: ' . $e->getMessage());
}

api_ok($items, ['page' => $page, 'limit' => $limit]);
