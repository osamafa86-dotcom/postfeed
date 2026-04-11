<?php
/**
 * Unified migrations runner.
 * CLI: php migrate.php
 * HTTP: migrate.php?key=CRON_KEY
 *
 * Idempotent — safe to run repeatedly.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/article_cluster.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$db = getDB();
$applied = [];

function col_exists($db, $table, $col) {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $col]);
    return (bool) $stmt->fetchColumn();
}
function idx_exists($db, $table, $name) {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $name]);
    return (bool) $stmt->fetchColumn();
}
function add_col($db, $table, $col, $ddl, &$applied) {
    if (!col_exists($db, $table, $col)) {
        $db->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
        $applied[] = "+ $table.$col";
    }
}
function add_idx($db, $table, $name, $cols, &$applied) {
    if (!idx_exists($db, $table, $name)) {
        $db->exec("CREATE INDEX `$name` ON `$table` ($cols)");
        $applied[] = "+ idx $table.$name";
    }
}

// ---------- sources tracking ----------
add_col($db, 'sources', 'last_fetched_at', 'last_fetched_at TIMESTAMP NULL DEFAULT NULL', $applied);
add_col($db, 'sources', 'last_error',      'last_error VARCHAR(500) DEFAULT NULL', $applied);
add_col($db, 'sources', 'last_new_count',  'last_new_count INT DEFAULT 0', $applied);
add_col($db, 'sources', 'total_articles',  'total_articles INT DEFAULT 0', $applied);
add_col($db, 'sources', 'cover_image',     'cover_image VARCHAR(500) DEFAULT NULL', $applied);
add_col($db, 'sources', 'followers_count', 'followers_count INT DEFAULT 0', $applied);

// ---------- articles AI columns ----------
add_col($db, 'articles', 'ai_summary',      'ai_summary TEXT', $applied);
add_col($db, 'articles', 'ai_key_points',   'ai_key_points TEXT', $applied);
add_col($db, 'articles', 'ai_keywords',     'ai_keywords VARCHAR(500)', $applied);
add_col($db, 'articles', 'ai_processed_at', 'ai_processed_at TIMESTAMP NULL', $applied);
add_col($db, 'articles', 'source_url',      'source_url VARCHAR(1000) DEFAULT NULL', $applied);
add_col($db, 'articles', 'cluster_key',     'cluster_key VARCHAR(64) NULL', $applied);

// ---------- performance indexes ----------
add_idx($db, 'articles', 'idx_status_pub',     '`status`, `published_at` DESC',                       $applied);
add_idx($db, 'articles', 'idx_cat_status_pub', '`category_id`, `status`, `published_at` DESC',        $applied);
add_idx($db, 'articles', 'idx_src_status_pub', '`source_id`, `status`, `published_at` DESC',          $applied);
add_idx($db, 'articles', 'idx_breaking_pub',   '`is_breaking`, `published_at` DESC',                  $applied);
add_idx($db, 'articles', 'idx_hero_pub',       '`is_hero`, `published_at` DESC',                      $applied);
add_idx($db, 'articles', 'idx_ai_null',        '`ai_summary`(1)',                                     $applied);
add_idx($db, 'articles', 'idx_cluster_key',    '`cluster_key`',                                       $applied);

// ---------- telegram tables ----------
$db->exec("CREATE TABLE IF NOT EXISTS telegram_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(150),
    avatar_url VARCHAR(500),
    is_active TINYINT(1) DEFAULT 1,
    last_fetched_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS telegram_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_id INT NOT NULL,
    post_id VARCHAR(100) NOT NULL,
    text TEXT,
    image_url VARCHAR(500),
    post_url VARCHAR(500),
    posted_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_source_post (source_id, post_id),
    INDEX idx_posted (posted_at DESC),
    FOREIGN KEY (source_id) REFERENCES telegram_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ---------- newsletter subscribers ----------
// Daily digest sign-ups: double opt-in via confirm_token, one-click
// unsubscribe via unsubscribe_token. last_sent_at is bumped by
// cron_newsletter.php so we can throttle / show "next send" in admin.
$db->exec("CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    confirmed TINYINT(1) NOT NULL DEFAULT 0,
    confirm_token VARCHAR(64) NOT NULL,
    unsubscribe_token VARCHAR(64) NOT NULL,
    subscribed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TIMESTAMP NULL,
    last_sent_at TIMESTAMP NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    INDEX idx_confirmed (confirmed),
    INDEX idx_confirm_token (confirm_token),
    INDEX idx_unsubscribe_token (unsubscribe_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Track each digest send so we can show stats in the admin and avoid
// double-sending if cron runs twice in the same window.
$db->exec("CREATE TABLE IF NOT EXISTS newsletter_sends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    subject VARCHAR(255) NOT NULL,
    article_count INT NOT NULL DEFAULT 0,
    recipient_count INT NOT NULL DEFAULT 0,
    success_count INT NOT NULL DEFAULT 0,
    fail_count INT NOT NULL DEFAULT 0,
    INDEX idx_sent (sent_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ---------- trending now (velocity tracking) ----------
// One row per page view, pruned to a 48h window. Used by
// includes/trending.php to compute velocity scores for the
// "🔥 الأكثر تداولاً الآن" rail. Two indexes: viewed_at for the
// time-window scan, and (article_id, viewed_at) for per-article
// lookups (debug pages, future "trending in category" rails).
$db->exec("CREATE TABLE IF NOT EXISTS article_view_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_viewed_at (viewed_at),
    INDEX idx_article_viewed (article_id, viewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ---------- reels tables ----------
$db->exec("CREATE TABLE IF NOT EXISTS reels_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(150),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS reels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_id INT,
    title VARCHAR(500),
    video_url VARCHAR(500),
    thumbnail_url VARCHAR(500),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ---------- backfill cluster_key for legacy rows ----------
// Walks rows in chronological order so each new article can fuzzy-match
// against the keys we already assigned earlier in the same run via
// cluster_assign(). Processes batches of 2000 per request — re-running
// migrate.php picks up where the last batch stopped (NULL filter), so a
// huge archive backfills incrementally without timing out.
//
// To force a re-cluster (e.g. after improving the tokenization rules)
// hit /migrate.php?key=...&recluster=1 once and it will reset the column
// before this block runs.
try {
    if (col_exists($db, 'articles', 'cluster_key')) {
        if (!empty($_GET['recluster'])) {
            $db->exec("UPDATE articles SET cluster_key = NULL");
            $applied[] = "+ cluster_key reset (recluster requested)";
        }

        $bf = $db->prepare("SELECT id, title FROM articles
                             WHERE cluster_key IS NULL AND title IS NOT NULL AND title <> ''
                             ORDER BY published_at ASC
                             LIMIT 2000");
        $bf->execute();
        $rows = $bf->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            // Seed the fuzzy index with whatever has already been
            // clustered (from prior batches or live cron inserts).
            $idx = cluster_index_build($db, 30, 1500);
            $upd = $db->prepare("UPDATE articles SET cluster_key = ? WHERE id = ?");
            $done = 0;
            foreach ($rows as $row) {
                $key = cluster_assign((string)$row['title'], $idx);
                $upd->execute([$key, (int)$row['id']]);
                $done++;
            }
            $applied[] = "+ backfilled cluster_key for $done rows";
        }
    }
} catch (Throwable $e) {
    $applied[] = "! cluster backfill skipped: " . $e->getMessage();
}

// --- FULLTEXT index on articles for search ---
try {
    $hasIdx = $db->query("SHOW INDEX FROM articles WHERE Key_name = 'ft_title_excerpt'")->fetch();
    if (!$hasIdx) {
        $db->exec("ALTER TABLE articles ADD FULLTEXT INDEX ft_title_excerpt (title, excerpt)");
        $applied[] = "+ added FULLTEXT index ft_title_excerpt on articles(title, excerpt)";
    }
} catch (Throwable $e) {
    $applied[] = "! FULLTEXT index skipped: " . $e->getMessage();
}

if (empty($applied)) {
    echo "✓ لا تغييرات — قاعدة البيانات محدّثة\n";
} else {
    echo "تم تطبيق:\n" . implode("\n", $applied) . "\n";
}
