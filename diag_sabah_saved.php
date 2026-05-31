<?php
/**
 * Quick read-only check on the latest sabah briefing's stored sections.
 * Delete after use.
 *
 * Run: php diag_sabah_saved.php
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sabah.php';

$db = getDB();
$row = $db->query("SELECT * FROM sabah_briefings ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "لا يوجد briefings محفوظة\n";
    exit;
}

echo "=== آخر briefing ===\n";
echo "id: {$row['id']}\n";
echo "date: {$row['briefing_date']}\n";
echo "generated_at: {$row['generated_at']}\n";
echo "article_count: {$row['article_count']}\n";
echo "headline: {$row['headline']}\n";
echo "hook (" . mb_strlen((string)$row['hook']) . " حرف): " . mb_substr((string)$row['hook'], 0, 200) . "\n";
echo "closing_question: {$row['closing_question']}\n";

echo "\n=== sections (raw) ===\n";
echo "طول النص الخام: " . mb_strlen((string)$row['sections']) . " حرف\n";
echo "أول 500 حرف:\n" . mb_substr((string)$row['sections'], 0, 500) . "\n";

echo "\n=== sections (decoded) ===\n";
$sections = json_decode((string)$row['sections'], true);
if (!is_array($sections)) {
    echo "❌ فشل json_decode (السبب: " . json_last_error_msg() . ")\n";
} else {
    echo "عدد الأقسام: " . count($sections) . "\n";
    foreach ($sections as $i => $s) {
        $title = is_array($s) ? ($s['title'] ?? '?') : '?';
        $bodyLen = is_array($s) ? mb_strlen((string)($s['body'] ?? '')) : 0;
        echo "  [{$i}] title: " . mb_substr((string)$title, 0, 60) . " — body: {$bodyLen} حرف\n";
    }
}

echo "\n=== ما يرجع من API ===\n";
$apiData = [
    'date'             => $row['briefing_date'],
    'title'            => $row['headline'],
    'summary'          => $row['hook'],
    'sections'         => is_array($sections) ? $sections : [],
    'closing_question' => $row['closing_question'],
    'article_count'    => (int)$row['article_count'],
];
echo "json keys: " . implode(', ', array_keys($apiData)) . "\n";
echo "sections in API output: " . count($apiData['sections']) . " قسم\n";
