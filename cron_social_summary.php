<?php
/**
 * Social briefings generator — DAILY (Twitter/X + YouTube).
 *
 * Mirrors cron_tg_summary.php but for the other two platforms. Produces
 * ONE comprehensive, de-duplicated summary per platform per day, stored
 * in the generic `social_summaries` table and served by
 * /api/v1/media/social-summary.php.
 *
 * Cron schedule (server runs UTC, 22:00 Jerusalem = 19:00 UTC):
 *   5 19 * * * curl -fsS "https://feedsnews.net/cron_social_summary.php?key=YOUR_CRON_KEY" > /dev/null
 *
 * Manual / CLI:
 *   php cron_social_summary.php                 # both platforms
 *   php cron_social_summary.php twitter         # one platform
 *   curl ".../cron_social_summary.php?key=KEY&platform=youtube&force=1"
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ai_helper.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(300);

$windowMins = 1440;   // full 24h window
$maxMsgs    = 400;
$maxTokens  = 5000;

$force = !empty($_GET['force']) || (PHP_SAPI === 'cli' && in_array('--force', $argv ?? [], true));

// Which platforms to run: ?platform=twitter|youtube, or a CLI arg, else both.
$only = strtolower(trim((string)($_GET['platform'] ?? ($argv[1] ?? ''))));
$only = in_array($only, ['twitter', 'youtube'], true) ? $only : '';
$platforms = $only ? [$only] : ['twitter', 'youtube'];

foreach ($platforms as $platform) {
    // One-per-day guard (20h floor for clock drift), unless &force=1.
    if (!$force) {
        $latest = social_summary_get_latest($platform);
        if ($latest && !empty($latest['generated_at'])) {
            $ageSecs = time() - strtotime($latest['generated_at']);
            if ($ageSecs < 20 * 3600) {
                $hrs = round($ageSecs / 3600, 1);
                echo "{$platform}: skip — latest is only {$hrs}h old (id={$latest['id']})\n";
                continue;
            }
        }
    }

    $messages = social_summary_collect($platform, $windowMins, $maxMsgs);
    if (count($messages) < 5) {
        echo "{$platform}: skip — only " . count($messages) . " posts in last 24h\n";
        continue;
    }

    $start   = microtime(true);
    $ai      = ai_summarize_social_daily($platform, $messages, $maxTokens);
    $elapsed = round(microtime(true) - $start, 2);

    if (empty($ai['ok'])) {
        $err = $ai['error'] ?? 'unknown';
        echo "{$platform}: fail — {$err} ({$elapsed}s)\n";
        error_log("[cron_social_summary] {$platform} generation failed: {$err}");
        continue;
    }

    $id = social_summary_save($platform, $ai, count($messages), $windowMins);
    if (!$id) {
        echo "{$platform}: fail — could not save to DB\n";
        continue;
    }

    social_summary_prune($platform, 60);
    echo "{$platform}: ok — saved #{$id} | " . count($messages) . " posts | {$elapsed}s\n";
    echo "  headline: " . ($ai['headline'] ?? '') . "\n";
}
