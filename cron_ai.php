<?php
/**
 * نيوزفلو - تلخيص الأخبار الجديدة بالذكاء الاصطناعي
 * يُشغل عبر Cron أو HTTP: cron_ai.php?key=XXX&limit=20
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ai_helper.php';

// HTTP access requires key; CLI always allowed
if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(300);
$db = getDB();
$limit = (int)($_GET['limit'] ?? ($argv[1] ?? 20));
if ($limit < 1 || $limit > 100) $limit = 20;

// Ensure columns exist
try {
    $cols = $db->query("SHOW COLUMNS FROM articles LIKE 'ai_summary'")->fetch();
    if (!$cols) {
        $db->exec("ALTER TABLE articles
            ADD COLUMN ai_summary TEXT,
            ADD COLUMN ai_key_points TEXT,
            ADD COLUMN ai_keywords VARCHAR(500),
            ADD COLUMN ai_processed_at TIMESTAMP NULL");
    }
} catch (Exception $e) {}

$apiKey = getSetting('anthropic_api_key', '');
if (empty($apiKey)) {
    echo "API key not configured\n";
    exit;
}

$articles = $db->query("SELECT id, title, content FROM articles
                        WHERE ai_summary IS NULL AND status = 'published'
                        ORDER BY created_at DESC LIMIT $limit")->fetchAll();

$done = 0; $fail = 0;
$start = microtime(true);
foreach ($articles as $a) {
    $r = ai_summarize_article($a['title'], $a['content']);
    if ($r['ok']) {
        ai_save_summary($a['id'], $r);
        $done++;
        echo "  ✓ #{$a['id']}\n";
    } else {
        $fail++;
        echo "  ✗ #{$a['id']}: " . ($r['error'] ?? '?') . "\n";
    }
    // gentle pacing to avoid rate limits
    usleep(200000);
}
$elapsed = round(microtime(true) - $start, 2);
echo "\nتم: $done | فشل: $fail | الوقت: {$elapsed}s\n";
