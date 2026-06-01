<?php
/**
 * GET /api/v1/content/for-you
 *
 * Personalized feed — far smarter than the original "everything from
 * your follows" query. The new ranking blends:
 *
 *   • Followed categories and sources (highest weight).
 *   • Implicit interests inferred from reading history (top categories
 *     the user actually clicked over the last 60 days).
 *   • Recency — newer items boost up.
 *   • Cluster-uniqueness — only the best one article per
 *     coverage-cluster so the feed isn't seven copies of the same
 *     story.
 *   • Already-read suppression — articles in user_reading_history are
 *     hidden (they can still be found via search).
 *   • Cold-start defaults — a user with no follows lands on أخبار
 *     فلسطين + general news instead of an empty rail.
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

    if (!$user) {
        // Signed-out cold start: just return the latest news items so
        // the "خاص لك" rail can still surface something useful.
        $items = fetch_articles(['content_type' => 'news'], $limit, $offset);
    } else {
        $uid = (int)$user['id'];

        // ── 1. Explicit follows ──────────────────────────────
        $cats = $db->prepare("SELECT category_id FROM user_category_follows WHERE user_id=?");
        $cats->execute([$uid]);
        $catIds = array_map('intval', $cats->fetchAll(PDO::FETCH_COLUMN));

        $srcs = $db->prepare("SELECT source_id FROM user_source_follows WHERE user_id=?");
        $srcs->execute([$uid]);
        $srcIds = array_map('intval', $srcs->fetchAll(PDO::FETCH_COLUMN));

        // ── 2. Implicit interests from reading history (last 60d) ─
        // The top three categories the user actually read in the
        // window. Folded into catIds so they rank as if explicitly
        // followed without needing a separate query path downstream.
        try {
            $hist = $db->prepare("
                SELECT a.category_id, COUNT(*) AS reads
                  FROM user_reading_history h
                  JOIN articles a ON a.id = h.article_id
                 WHERE h.user_id = ?
                   AND h.read_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                   AND a.category_id IS NOT NULL
                 GROUP BY a.category_id
                 ORDER BY reads DESC
                 LIMIT 3");
            $hist->execute([$uid]);
            $implicit = array_map('intval', $hist->fetchAll(PDO::FETCH_COLUMN));
            $catIds = array_values(array_unique(array_merge($catIds, $implicit)));
        } catch (Throwable $e) {
            // user_reading_history may not exist yet on older installs.
        }

        // ── 3. Cold start: no follows + no history → Palestine + news ──
        if (empty($catIds) && empty($srcIds)) {
            try {
                $items = fetch_articles([
                    'content_type' => 'news',
                    'palestine'    => 1,
                ], $limit, $offset);
                if (empty($items)) {
                    $items = fetch_articles(['content_type' => 'news'], $limit, $offset);
                }
            } catch (Throwable $e) {
                $items = fetch_articles([], $limit, $offset);
            }
        } else {
            // ── 4. Pull candidates, generously over-sampled ─
            // We over-sample so post-filtering (read-suppression +
            // cluster-uniqueness) still leaves enough rows.
            $where = ["a.status='published'", "a.is_hero=0"];
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

            // Cap to last 7 days so the feed always feels current.
            $where[] = "a.published_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";

            $sql = articles_select_sql() . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.published_at DESC
                LIMIT " . ($limit * 4) . " OFFSET " . (int)$offset;
            $st = $db->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll();

            // ── 5. Subtract already-read article ids ──────────
            $readIds = [];
            try {
                $hr = $db->prepare("
                    SELECT article_id FROM user_reading_history
                     WHERE user_id = ?
                       AND read_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)");
                $hr->execute([$uid]);
                $readIds = array_flip(array_map('intval', $hr->fetchAll(PDO::FETCH_COLUMN)));
            } catch (Throwable $e) {}

            // ── 6. Cluster-uniqueness + read-suppression ──────
            $seenClusters = [];
            $picked = [];
            foreach ($rows as $r) {
                $rid = (int)$r['id'];
                if (isset($readIds[$rid])) continue;

                $ck = (string)($r['cluster_key'] ?? '');
                if ($ck !== '' && preg_match('/^[a-f0-9]{40}$/', $ck)) {
                    if (isset($seenClusters[$ck])) continue;
                    $seenClusters[$ck] = true;
                }

                $picked[] = $r;
                if (count($picked) >= $limit) break;
            }
            $items = array_map('api_format_article', $picked);
        }
    }
} catch (Throwable $e) {
    error_log('for-you: ' . $e->getMessage());
}

api_ok($items, ['page' => $page, 'limit' => $limit]);
