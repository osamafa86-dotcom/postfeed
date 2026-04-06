<?php
/**
 * Migration: Add source_url column to articles table
 * Run once via: php migrate_source_url.php
 * Or via browser: https://postfeed.emdatra.org/migrate_source_url.php
 */

require_once __DIR__ . '/includes/config.php';

$db = getDB();

try {
    // Check if column already exists
    $stmt = $db->query("SHOW COLUMNS FROM articles LIKE 'source_url'");
    if ($stmt->rowCount() > 0) {
        echo "Column source_url already exists.\n";
    } else {
        $db->exec("ALTER TABLE articles ADD COLUMN `source_url` varchar(1000) DEFAULT NULL AFTER `image_url`");
        echo "Column source_url added successfully.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
