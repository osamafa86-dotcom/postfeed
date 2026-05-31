<?php
/**
 * نيوز فيد — توليد موجز الصباح (Morning Briefing cron)
 *
 * Recommended schedule: once daily at 05:30 Cairo time.
 *   30 5 * * * curl -fsS "https://postfeed.emdatra.org/cron_sabah.php?key=XXX" > /dev/null 2>&1
 *
 * HTTP access: cron_sabah.php?key=XXX
 * CLI access:  php cron_sabah.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sabah.php';
require_once __DIR__ . '/includes/push.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// The briefing is generated once a day, so it's worth being patient.
// Allow up to ~5 minutes so we can sit through a couple of Gemini
// free-tier 429 windows (each ~60s) instead of giving up on the first.
@set_time_limit(360);

$today = date('Y-m-d');

// Don't regenerate if today's briefing already exists.
$existing = sabah_get($today);
if ($existing && empty($_GET['force'])) {
    echo "briefing already exists for {$today} (#{$existing['id']})\n";
    exit;
}

echo "generating sabah briefing for {$today}...\n";
$t0 = microtime(true);

// Retry loop: Gemini's free tier caps at 20 requests/minute and
// cron_ai often eats that allowance seconds earlier. Rather than fail
// for the whole day, wait out the quota window and retry. Up to 4
// attempts with a 65s gap = covers ~4 minutes of contention, which is
// plenty for a once-daily job.
$briefing = null;
$maxAttempts = 4;
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $briefing = sabah_generate();
    if ($briefing && !empty($briefing['hook'])) break;

    $reason = $GLOBALS['_sabah_last_error'] ?? 'not enough clusters';
    // Only worth retrying on a rate-limit (429). A genuine "no clusters"
    // or a hard error won't fix itself in 60s, so bail immediately.
    $isRateLimit = strpos((string)$reason, '429') !== false
                || stripos((string)$reason, 'quota') !== false;
    if (!$isRateLimit || $attempt === $maxAttempts) {
        echo "failed after {$attempt} attempt(s): {$reason}\n";
        exit(1);
    }
    echo "  attempt {$attempt}: rate-limited, waiting 65s before retry…\n";
    sleep(65);
}

$id = sabah_save($briefing, $today);
$dt = round(microtime(true) - $t0, 2);

if (!$id) {
    echo "generated but save failed ({$dt}s)\n";
    exit(1);
}

echo "ok: saved briefing #{$id} — \"{$briefing['headline']}\" ({$dt}s)\n";

// Push "صباح الخير" to everyone who opted into the daily digest.
$headline = trim((string)($briefing['headline'] ?? ''));
$body = $headline !== '' ? $headline : 'ملخّص أخبار اليوم جاهز للقراءة.';
$push = push_broadcast(
    '☀️ صباح الخير — ملخّص اليوم',
    $body,
    ['channel' => 'daily', 'link' => '/sabah'],
    'notify_digest'
);
if ($push['skipped']) {
    echo "push: skipped (FCM not configured)\n";
} else {
    echo "push: sent {$push['sent']}, pruned {$push['pruned']} stale tokens\n";
}
