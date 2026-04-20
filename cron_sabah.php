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

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(120);

$today = date('Y-m-d');

// Don't regenerate if today's briefing already exists.
$existing = sabah_get($today);
if ($existing && empty($_GET['force'])) {
    echo "briefing already exists for {$today} (#{$existing['id']})\n";
    exit;
}

echo "generating sabah briefing for {$today}...\n";
$t0 = microtime(true);

$briefing = sabah_generate();
if (!$briefing || empty($briefing['hook'])) {
    echo "failed: no briefing generated (not enough clusters?)\n";
    exit(1);
}

$id = sabah_save($briefing, $today);
$dt = round(microtime(true) - $t0, 2);

if ($id) {
    echo "ok: saved briefing #{$id} — \"{$briefing['headline']}\" ({$dt}s)\n";
} else {
    echo "generated but save failed ({$dt}s)\n";
    exit(1);
}
