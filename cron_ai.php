<?php
/**
 * نيوزفلو - تلخيص الأخبار الجديدة بالذكاء الاصطناعي
 * يُشغل عبر Cron أو HTTP: cron_ai.php?key=XXX&limit=20
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ai_helper.php';
require_once __DIR__ . '/includes/tts.php';

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

// Provider gate: require the key for whichever provider is active so
// the cron bails out cleanly instead of hammering N articles × 500ms
// of curl failures.
$provider = strtolower((string)getSetting('ai_provider', 'gemini'));
$providerKey = $provider === 'anthropic'
    ? (env('ANTHROPIC_API_KEY', '') ?: getSetting('anthropic_api_key', ''))
    : (env('GEMINI_API_KEY',    '') ?: getSetting('gemini_api_key',    ''));
if (empty($providerKey)) {
    echo "AI provider '{$provider}' key not configured\n";
    exit;
}

$stmt = $db->prepare("SELECT id, title, content, excerpt FROM articles
                        WHERE ai_summary IS NULL AND status = 'published'
                        ORDER BY created_at DESC LIMIT ?");
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->execute();
$articles = $stmt->fetchAll();

$done = 0; $fail = 0;
$ttsDone = 0; $ttsFail = 0;
$ttsEnabled = tts_is_enabled();
$start = microtime(true);
foreach ($articles as $a) {
    $r = ai_summarize_article($a['title'], $a['content']);
    if ($r['ok']) {
        ai_save_summary($a['id'], $r);
        $done++;
        echo "  ✓ #{$a['id']}\n";

        // Pre-generate the MP3 for this article so the first reader
        // hears cached audio instantly. If cloud TTS is off in the
        // panel this is a no-op. We pass the freshly generated
        // summary directly instead of re-reading from the DB.
        if ($ttsEnabled) {
            $forTts = [
                'title'      => $a['title'],
                'ai_summary' => $r['summary'] ?? '',
                'excerpt'    => $a['excerpt'] ?? '',
                'content'    => $a['content'] ?? '',
            ];
            try {
                $ttsRes = tts_get_or_generate($forTts);
            } catch (Throwable $e) {
                $ttsRes = null;
            }
            if ($ttsRes) {
                $ttsDone++;
                echo "    🔊 TTS #{$a['id']} (" . number_format($ttsRes['bytes']) . "b"
                   . ($ttsRes['cached'] ? ', cached' : ', fresh') . ")\n";
            } else {
                $ttsFail++;
                echo "    ✗ TTS #{$a['id']} failed\n";
            }
            // Extra pacing when we also hit the TTS provider on the
            // same loop iteration to stay well under their burst caps.
            usleep(300000);
        }
    } else {
        $fail++;
        echo "  ✗ #{$a['id']}: " . ($r['error'] ?? '?') . "\n";
    }
    // gentle pacing to avoid rate limits
    usleep(200000);
}
$elapsed = round(microtime(true) - $start, 2);
echo "\nتم: $done | فشل: $fail";
if ($ttsEnabled) echo " | TTS: $ttsDone ✓ / $ttsFail ✗";
echo " | الوقت: {$elapsed}s\n";
