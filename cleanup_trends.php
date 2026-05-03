<?php
/**
 * Removes all old trends and keeps only the 6 Palestine topics.
 * Run: visit https://feedsnews.net/cleanup_trends.php?key=YOUR_CRON_KEY
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

$keep = ['فلسطين', 'غزة', 'الضفة', 'الأسرى', 'القدس', 'الاستيطان'];

// Count before
$before = $db->query("SELECT COUNT(*) FROM trends")->fetchColumn();
echo "Trends before cleanup: $before\n";

// Delete all except the 6 Palestine topics
$placeholders = implode(',', array_fill(0, count($keep), '?'));
$stmt = $db->prepare("DELETE FROM trends WHERE title NOT IN ($placeholders)");
$stmt->execute($keep);
$deleted = $stmt->rowCount();

echo "Deleted: $deleted old trends\n";

// Show remaining
$remaining = $db->query("SELECT id, title, sort_order FROM trends ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
echo "\nRemaining trends:\n";
foreach ($remaining as $r) {
    echo "  [{$r['id']}] {$r['title']} (order: {$r['sort_order']})\n";
}
echo "\nDone! " . count($remaining) . " trends remaining.\n";
