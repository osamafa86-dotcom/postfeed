<?php
/**
 * Trending now — velocity-based "hottest stories right now".
 *
 * Backed by article_view_events: one row per page view, indexed by
 * viewed_at. Cron prunes rows older than 48h so the table stays
 * cheap. Velocity formula:
 *
 *     score = (views_last_hour × 4) + views_last_6h
 *
 * Recency dominates, so a story two hours old with 200 reads beats a
 * day-old story with 1500 reads — exactly the FOMO signal a "what's
 * hot right now" rail should surface.
 *
 * Aggregation key is cluster_key (not article_id) so the rail shows
 * eight distinct stories, not eight rewrites of the same headline.
 * Articles whose cluster_key is NULL or '-' fall back to grouping by
 * their own id so they still participate in the ranking.
 */

if (!function_exists('trending_ensure_table')) {
    function trending_ensure_table(PDO $db): void {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS article_view_events (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                article_id INT NOT NULL,
                viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_viewed_at (viewed_at),
                INDEX idx_article_viewed (article_id, viewed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('trending_log_view')) {
    /**
     * Record a single view event. Skips obvious bot user agents so
     * Googlebot doesn't poison the velocity score.
     */
    function trending_log_view(int $articleId): void {
        if ($articleId <= 0) return;
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|mediapartners|facebookexternalhit/i', $ua)) return;
        try {
            $db = getDB();
            trending_ensure_table($db);
            $db->prepare("INSERT INTO article_view_events (article_id) VALUES (?)")
               ->execute([$articleId]);
        } catch (Throwable $e) {}
    }
}

if (!function_exists('trending_prune')) {
    /**
     * Drop view events older than 48 hours. Called opportunistically
     * (1% chance per logged view) so we don't need a dedicated cron.
     */
    function trending_prune(PDO $db): int {
        try {
            $stmt = $db->exec("DELETE FROM article_view_events WHERE viewed_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)");
            return (int)$stmt;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('trending_get_top')) {
    /**
     * Top N trending stories by velocity. Returns rows with the
     * standard article fields plus:
     *   - velocity_score    (int)
     *   - views_last_hour   (int)
     *   - views_last_6h     (int)
     *   - cluster_size      (int)  — number of sources in the cluster
     *
     * Cached for 90s so the homepage doesn't run a heavy aggregation
     * on every visitor. Cache key is the limit so /trending and the
     * homepage strip can share or differ as needed.
     */
    function trending_get_top(int $limit = 8): array {
        $limit = max(1, min(50, $limit));
        return cache_remember('trending_top_' . $limit, 90, function() use ($limit) {
            $db = getDB();
            trending_ensure_table($db);

            // Aggregate by cluster_key, falling back to article id for
            // unclustered rows. The COALESCE in the GROUP BY makes the
            // unclustered articles cluster against themselves.
            try {
                $sql = "SELECT
                          a.id, a.title, a.slug, a.excerpt, a.image_url, a.published_at,
                          a.cluster_key, a.view_count, a.ai_summary,
                          c.name AS cat_name, c.slug AS cat_slug, c.css_class,
                          s.name AS source_name, s.logo_color,
                          v.last_hour AS views_last_hour,
                          v.last_6h   AS views_last_6h,
                          (v.last_hour * 4 + v.last_6h) AS velocity_score,
                          (SELECT COUNT(*) FROM articles a2
                            WHERE a2.status='published'
                              AND a2.cluster_key = a.cluster_key
                              AND a.cluster_key IS NOT NULL
                              AND a.cluster_key <> '-') AS cluster_size
                       FROM (
                           SELECT e.article_id,
                                  SUM(CASE WHEN e.viewed_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS last_hour,
                                  SUM(CASE WHEN e.viewed_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN 1 ELSE 0 END) AS last_6h
                             FROM article_view_events e
                            WHERE e.viewed_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                            GROUP BY e.article_id
                       ) v
                       JOIN articles a ON a.id = v.article_id
                       LEFT JOIN categories c ON a.category_id = c.id
                       LEFT JOIN sources s ON a.source_id = s.id
                       WHERE a.status = 'published'
                       ORDER BY velocity_score DESC, a.published_at DESC";
                $stmt = $db->query($sql);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                return [];
            }

            // De-dup by cluster_key in PHP so the SQL stays simple.
            // First occurrence wins (already ordered by velocity DESC).
            $seen = [];
            $out  = [];
            foreach ($rows as $r) {
                $ck = (string)($r['cluster_key'] ?? '');
                $key = ($ck !== '' && $ck !== '-') ? 'c:' . $ck : 'a:' . $r['id'];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $out[] = $r;
                if (count($out) >= $limit) break;
            }
            return $out;
        });
    }
}

if (!function_exists('cluster_source_velocity')) {
    /**
     * Source velocity for a single cluster: how fast new outlets are
     * joining the coverage. Returns counts of distinct sources in
     * 15-min / 1-hour / 6-hour windows plus a composite score.
     *
     * NewsWhip found that 79% of monster stories show outstanding
     * source velocity in their first hour. Unlike view-based velocity,
     * this measures editorial consensus — more meaningful for trust.
     *
     * Score = (sources_15min × 8) + (sources_1h × 3) + sources_6h
     */
    function cluster_source_velocity(string $clusterKey): array {
        if ($clusterKey === '' || $clusterKey === '-') {
            return ['sources_15m' => 0, 'sources_1h' => 0, 'sources_6h' => 0, 'score' => 0, 'label' => ''];
        }
        return cache_remember('src_vel_' . $clusterKey, 120, function () use ($clusterKey) {
            try {
                $db = getDB();
                $stmt = $db->prepare(
                    "SELECT
                        COUNT(DISTINCT CASE WHEN a.published_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN a.source_id END) AS s15m,
                        COUNT(DISTINCT CASE WHEN a.published_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN a.source_id END) AS s1h,
                        COUNT(DISTINCT CASE WHEN a.published_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN a.source_id END) AS s6h
                     FROM articles a
                     WHERE a.cluster_key = ? AND a.status = 'published'"
                );
                $stmt->execute([$clusterKey]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                $s15m = (int)($r['s15m'] ?? 0);
                $s1h  = (int)($r['s1h']  ?? 0);
                $s6h  = (int)($r['s6h']  ?? 0);
                $score = ($s15m * 8) + ($s1h * 3) + $s6h;
                // Human-friendly label.
                $label = '';
                if ($s15m >= 3)     $label = '🔴 عاجل — ' . $s15m . ' مصادر في 15 دقيقة';
                elseif ($s1h >= 3)  $label = '🔥 قصة صاعدة — ' . $s1h . ' مصادر في ساعة';
                elseif ($s6h >= 4)  $label = '📈 تغطية واسعة — ' . $s6h . ' مصادر في 6 ساعات';
                return ['sources_15m' => $s15m, 'sources_1h' => $s1h, 'sources_6h' => $s6h, 'score' => $score, 'label' => $label];
            } catch (Throwable $e) {
                return ['sources_15m' => 0, 'sources_1h' => 0, 'sources_6h' => 0, 'score' => 0, 'label' => ''];
            }
        });
    }
}

if (!function_exists('trending_by_source_velocity')) {
    /**
     * Top clusters ranked by source velocity (how fast outlets join).
     * Complements the existing view-based trending_get_top(). This
     * query looks at clusters where ≥2 distinct sources published in
     * the last 6 hours, ranked by velocity score.
     */
    function trending_by_source_velocity(int $limit = 10): array {
        $limit = max(1, min(30, $limit));
        return cache_remember('trending_src_vel_' . $limit, 120, function () use ($limit) {
            try {
                $db = getDB();
                $sql = "SELECT
                          a.cluster_key,
                          COUNT(DISTINCT CASE WHEN a.published_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN a.source_id END) AS s15m,
                          COUNT(DISTINCT CASE WHEN a.published_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN a.source_id END) AS s1h,
                          COUNT(DISTINCT CASE WHEN a.published_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN a.source_id END) AS s6h,
                          COUNT(DISTINCT a.source_id) AS total_sources,
                          MAX(a.published_at) AS latest_at,
                          (COUNT(DISTINCT CASE WHEN a.published_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN a.source_id END) * 8
                           + COUNT(DISTINCT CASE WHEN a.published_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN a.source_id END) * 3
                           + COUNT(DISTINCT CASE WHEN a.published_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN a.source_id END)) AS vel_score
                       FROM articles a
                       WHERE a.status = 'published'
                         AND a.cluster_key IS NOT NULL AND a.cluster_key <> '-'
                         AND a.published_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                       GROUP BY a.cluster_key
                       HAVING COUNT(DISTINCT a.source_id) >= 2
                       ORDER BY vel_score DESC, latest_at DESC
                       LIMIT {$limit}";
                $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                // Enrich each row with a representative article (longest title).
                foreach ($rows as &$row) {
                    $stmt = $db->prepare(
                        "SELECT a.id, a.title, a.slug, a.image_url, a.published_at,
                                s.name AS source_name, c.name AS cat_name
                         FROM articles a
                         LEFT JOIN sources s ON a.source_id = s.id
                         LEFT JOIN categories c ON a.category_id = c.id
                         WHERE a.cluster_key = ? AND a.status = 'published'
                         ORDER BY LENGTH(a.title) DESC LIMIT 1"
                    );
                    $stmt->execute([$row['cluster_key']]);
                    $rep = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($rep) $row = array_merge($row, $rep);
                }
                unset($row);
                return $rows;
            } catch (Throwable $e) {
                return [];
            }
        });
    }
}

if (!function_exists('trending_active_readers')) {
    /**
     * Rough estimate of "people reading right now": distinct view
     * events in the last 5 minutes. A useful badge ("X يقرأ الآن")
     * on the trending strip — feels alive, costs nothing.
     */
    function trending_active_readers(): int {
        return cache_remember('trending_active_readers', 30, function() {
            try {
                $db = getDB();
                trending_ensure_table($db);
                return (int)$db->query("SELECT COUNT(*) FROM article_view_events WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
            } catch (Throwable $e) {
                return 0;
            }
        });
    }
}
