<?php
/**
 * One-time script to seed the `trends` table with essential Palestine topics.
 * Run: php setup_trends.php  OR  visit https://feedsnews.net/setup_trends.php?key=YOUR_CRON_KEY
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

// Ensure table exists
$db->exec("CREATE TABLE IF NOT EXISTS trends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    tweet_count INT DEFAULT 0,
    search_count INT DEFAULT 0,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$topics = [
    ['فلسطين', 1],
    ['غزة', 2],
    ['الضفة', 3],
    ['الأسرى', 4],
    ['القدس', 5],
    ['الاستيطان', 6],
];

$stmt = $db->prepare("INSERT IGNORE INTO trends (title, sort_order) VALUES (?, ?)");
foreach ($topics as [$title, $order]) {
    $stmt->execute([$title, $order]);
    echo "Inserted: $title\n";
}

echo "\nDone! " . count($topics) . " topics seeded.\n";
