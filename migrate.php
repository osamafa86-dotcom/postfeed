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

// ---------- performance indexes ----------
add_idx($db, 'articles', 'idx_status_pub',     '`status`, `published_at` DESC',                       $applied);
add_idx($db, 'articles', 'idx_cat_status_pub', '`category_id`, `status`, `published_at` DESC',        $applied);
add_idx($db, 'articles', 'idx_src_status_pub', '`source_id`, `status`, `published_at` DESC',          $applied);
add_idx($db, 'articles', 'idx_breaking_pub',   '`is_breaking`, `published_at` DESC',                  $applied);
add_idx($db, 'articles', 'idx_hero_pub',       '`is_hero`, `published_at` DESC',                      $applied);
add_idx($db, 'articles', 'idx_ai_null',        '`ai_summary`(1)',                                     $applied);

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

if (empty($applied)) {
    echo "✓ لا تغييرات — قاعدة البيانات محدّثة\n";
} else {
    echo "تم تطبيق:\n" . implode("\n", $applied) . "\n";
}
