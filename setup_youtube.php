<?php
/**
 * Diagnostic & setup script for YouTube integration.
 * Run: php setup_youtube.php  OR  visit https://feedsnews.net/setup_youtube.php?key=YOUR_CRON_KEY
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

// 1. Check if tables exist
echo "=== YouTube Diagnostics ===\n\n";

try {
    $tables = $db->query("SHOW TABLES LIKE 'youtube%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables found: " . implode(', ', $tables) . "\n";
} catch (Throwable $e) {
    echo "ERROR checking tables: {$e->getMessage()}\n";
}

// 2. Ensure tables exist
$db->exec("CREATE TABLE IF NOT EXISTS youtube_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(255) NOT NULL,
    channel_id VARCHAR(100),
    channel_url VARCHAR(500),
    avatar_url VARCHAR(500),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS youtube_videos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_id INT NOT NULL,
    video_id VARCHAR(50) NOT NULL UNIQUE,
    video_url VARCHAR(500) NOT NULL,
    title VARCHAR(500) NOT NULL,
    description TEXT,
    thumbnail_url VARCHAR(500),
    duration_seconds INT DEFAULT 0,
    posted_at DATETIME,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (source_id) REFERENCES youtube_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

echo "Tables ensured.\n\n";

// 3. Check sources count
$srcCount = $db->query("SELECT COUNT(*) FROM youtube_sources WHERE is_active=1")->fetchColumn();
echo "Active YouTube sources: $srcCount\n";

// 4. Check videos count
$vidCount = $db->query("SELECT COUNT(*) FROM youtube_videos WHERE is_active=1")->fetchColumn();
echo "Active YouTube videos: $vidCount\n";

// 5. Show recent videos
$recent = $db->query("SELECT v.id, v.title, v.posted_at, s.display_name
                       FROM youtube_videos v
                       JOIN youtube_sources s ON v.source_id = s.id
                       WHERE v.is_active=1
                       ORDER BY v.posted_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "\nRecent videos:\n";
foreach ($recent as $v) {
    echo "  [{$v['id']}] {$v['title']} ({$v['display_name']}) - {$v['posted_at']}\n";
}
if (empty($recent)) echo "  (none)\n";

echo "\n";
if ($srcCount == 0) {
    echo "⚠ No YouTube sources configured!\n";
    echo "Add sources in the panel: /panel/ai.php → YouTube section\n";
    echo "Or INSERT manually:\n";
    echo "  INSERT INTO youtube_sources (display_name, channel_id) VALUES ('اسم القناة', 'UC...');\n";
} elseif ($vidCount == 0) {
    echo "⚠ Sources exist but no videos! Run the cron:\n";
    echo "  php cron_youtube.php\n";
} else {
    echo "✓ YouTube is configured and has data.\n";
}
