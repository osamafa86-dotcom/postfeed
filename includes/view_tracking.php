<?php
/**
 * Accurate view tracking with bot filtering, IP deduplication,
 * and daily aggregation for the admin dashboard.
 *
 * Replaces the naive view_count++ on every hit with a system that:
 *   1. Skips bots / crawlers
 *   2. Deduplicates by IP+article (30-min window)
 *   3. Maintains a daily_view_stats table for time-series charts
 *   4. Still increments articles.view_count (filtered, not raw)
 */

require_once __DIR__ . '/config.php';

function view_tracking_ensure_tables(PDO $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS daily_view_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stat_date DATE NOT NULL,
            total_views INT NOT NULL DEFAULT 0,
            unique_visitors INT NOT NULL DEFAULT 0,
            UNIQUE INDEX idx_stat_date (stat_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS view_dedup (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            article_id INT NOT NULL,
            ip_hash CHAR(40) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dedup_lookup (article_id, ip_hash, created_at),
            INDEX idx_dedup_prune (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS referrer_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stat_date DATE NOT NULL,
            source_type VARCHAR(20) NOT NULL,
            source_domain VARCHAR(120) NOT NULL DEFAULT '',
            views INT NOT NULL DEFAULT 0,
            UNIQUE INDEX idx_ref_unique (stat_date, source_type, source_domain),
            INDEX idx_ref_date (stat_date),
            INDEX idx_ref_type (source_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS hourly_view_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stat_hour DATETIME NOT NULL,
            total_views INT NOT NULL DEFAULT 0,
            UNIQUE INDEX idx_stat_hour (stat_hour)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}

/**
 * Classify a referrer URL into { source_type, source_domain }.
 * source_type is one of: search, social, direct, internal, other.
 * source_domain is the bare host (e.g. "google.com", "" for direct).
 */
function classify_referrer(string $referrer, string $ownHost = ''): array {
    $referrer = trim($referrer);
    if ($referrer === '') {
        return ['direct', ''];
    }
    $host = parse_url($referrer, PHP_URL_HOST);
    if (!$host) {
        return ['direct', ''];
    }
    $host = strtolower(preg_replace('/^www\./', '', $host));

    if ($ownHost !== '' && ($host === $ownHost || str_ends_with($host, '.' . $ownHost))) {
        return ['internal', $host];
    }

    $searchEngines = ['google.', 'bing.com', 'yahoo.', 'duckduckgo.com', 'yandex.', 'baidu.com', 'ecosia.org', 'qwant.com', 'brave.com'];
    foreach ($searchEngines as $needle) {
        if (str_contains($host, $needle)) {
            return ['search', $host];
        }
    }

    $social = [
        'facebook.com', 'fb.com', 'm.facebook.com', 'l.facebook.com',
        'twitter.com', 'x.com', 't.co',
        'instagram.com', 'l.instagram.com',
        'youtube.com', 'youtu.be',
        'linkedin.com', 'lnkd.in',
        'reddit.com',
        'telegram.org', 't.me', 'web.telegram.org',
        'whatsapp.com', 'wa.me', 'api.whatsapp.com',
        'pinterest.com', 'pin.it',
        'tiktok.com',
        'threads.net',
    ];
    foreach ($social as $needle) {
        if ($host === $needle || str_ends_with($host, '.' . $needle)) {
            return ['social', $host];
        }
    }

    return ['other', $host];
}

function increment_referrer_stats(PDO $db, string $referrer): void {
    $ownHost = parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_HOST) ?: '';
    $ownHost = strtolower(preg_replace('/^www\./', '', (string)$ownHost));
    [$type, $domain] = classify_referrer($referrer, $ownHost);

    try {
        $today = date('Y-m-d');
        $db->prepare(
            "INSERT INTO referrer_stats (stat_date, source_type, source_domain, views)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE views = views + 1"
        )->execute([$today, $type, $domain]);
    } catch (Throwable $e) {}
}

function increment_hourly_stats(PDO $db): void {
    try {
        $hour = date('Y-m-d H:00:00');
        $db->prepare(
            "INSERT INTO hourly_view_stats (stat_hour, total_views)
             VALUES (?, 1)
             ON DUPLICATE KEY UPDATE total_views = total_views + 1"
        )->execute([$hour]);

        if (mt_rand(1, 500) === 1) {
            $db->exec("DELETE FROM hourly_view_stats WHERE stat_hour < DATE_SUB(NOW(), INTERVAL 14 DAY)");
        }
    } catch (Throwable $e) {}
}

function is_bot_request(): bool {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return true;
    return (bool)preg_match(
        '/bot|crawl|spider|slurp|mediapartners|facebookexternalhit|preview|wget|curl|python|java\/|ahrefsbot|semrushbot|dotbot|yandexbot|baiduspider|bingbot|googlebot/i',
        $ua
    );
}

function is_duplicate_view(PDO $db, int $articleId, string $ipHash): bool {
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM view_dedup
             WHERE article_id = ? AND ip_hash = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
             LIMIT 1"
        );
        $stmt->execute([$articleId, $ipHash]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function record_dedup_entry(PDO $db, int $articleId, string $ipHash): void {
    try {
        $db->prepare("INSERT INTO view_dedup (article_id, ip_hash) VALUES (?, ?)")
           ->execute([$articleId, $ipHash]);
    } catch (Throwable $e) {}
}

function increment_daily_stats(PDO $db): void {
    $today = date('Y-m-d');
    try {
        $db->prepare(
            "INSERT INTO daily_view_stats (stat_date, total_views, unique_visitors)
             VALUES (?, 1, 1)
             ON DUPLICATE KEY UPDATE total_views = total_views + 1"
        )->execute([$today]);
    } catch (Throwable $e) {}
}

/**
 * Record an article view with full filtering and deduplication.
 * Returns true if the view was counted, false if skipped.
 */
function record_article_view(int $articleId): bool {
    if ($articleId <= 0) return false;
    if (is_bot_request()) return false;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ipHash = sha1($ip . '::salt_nf_2026');

    try {
        $db = getDB();
        view_tracking_ensure_tables($db);

        if (is_duplicate_view($db, $articleId, $ipHash)) {
            return false;
        }

        record_dedup_entry($db, $articleId, $ipHash);

        $db->prepare("UPDATE articles SET view_count = view_count + 1 WHERE id = ?")
           ->execute([$articleId]);

        increment_daily_stats($db);
        increment_hourly_stats($db);
        increment_referrer_stats($db, (string)($_SERVER['HTTP_REFERER'] ?? ''));

        if (mt_rand(1, 200) === 1) {
            view_dedup_prune($db);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function view_dedup_prune(PDO $db): void {
    try {
        $db->exec("DELETE FROM view_dedup WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
    } catch (Throwable $e) {}
}

/**
 * Record a page view for non-article pages (homepage, category, search, …).
 * Counts toward daily/hourly/referrer stats but does NOT touch articles.view_count.
 * IP-deduplicated per-bucket (same IP+bucket within 30 min = one view).
 */
function record_page_view(string $bucket): bool {
    if ($bucket === '' || is_bot_request()) return false;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ipHash = sha1($ip . '::salt_nf_2026');
    $pseudoId = crc32('page:' . $bucket);
    $pseudoId = $pseudoId > 0 ? -$pseudoId : $pseudoId;

    try {
        $db = getDB();
        view_tracking_ensure_tables($db);

        if (is_duplicate_view($db, $pseudoId, $ipHash)) return false;
        record_dedup_entry($db, $pseudoId, $ipHash);

        increment_daily_stats($db);
        increment_hourly_stats($db);
        increment_referrer_stats($db, (string)($_SERVER['HTTP_REFERER'] ?? ''));

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get daily view counts for the last N days (for dashboard charts).
 * Uses daily_view_stats table, falling back to article_view_events
 * for recent data if daily_view_stats is empty.
 */
function get_daily_views(int $days = 30): array {
    $result = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $result[$d] = 0;
    }

    try {
        $db = getDB();
        view_tracking_ensure_tables($db);

        $stmt = $db->prepare(
            "SELECT stat_date, total_views FROM daily_view_stats
             WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             ORDER BY stat_date"
        );
        $stmt->execute([$days]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if (isset($result[$row['stat_date']])) {
                $result[$row['stat_date']] = (int)$row['total_views'];
            }
        }

        $hasData = array_sum($result) > 0;
        if (!$hasData) {
            try {
                $stmt = $db->prepare(
                    "SELECT DATE(viewed_at) as vday, COUNT(*) as cnt
                     FROM article_view_events
                     WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                     GROUP BY DATE(viewed_at)"
                );
                $stmt->execute([$days]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (isset($result[$row['vday']])) {
                        $result[$row['vday']] = (int)$row['cnt'];
                    }
                }
            } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}

    return $result;
}

/**
 * Get today's view count from daily_view_stats.
 */
function get_today_views(): int {
    try {
        $db = getDB();
        view_tracking_ensure_tables($db);
        $stmt = $db->prepare("SELECT total_views FROM daily_view_stats WHERE stat_date = CURDATE()");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val !== false) return (int)$val;

        $stmt = $db->query(
            "SELECT COUNT(*) FROM article_view_events WHERE DATE(viewed_at) = CURDATE()"
        );
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
