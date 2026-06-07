<?php
/**
 * Social briefings generator — every 3h (Twitter/X + YouTube).
 *
 * Mirrors cron_tg_summary.php but for the other two platforms. Produces
 * a comprehensive, de-duplicated summary per platform every 3 hours,
 * stored in the generic `social_summaries` table and served by
 * /api/v1/media/social-summary.php.
 *
 * Cron schedule (cPanel) — every 3 hours:
 *   10 0,3,6,9,12,15,18,21 * * * curl -fsS "https://feedsnews.net/cron_social_summary.php?key=YOUR_CRON_KEY" > /dev/null
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

$windowMins = 1440;   // comprehensive 24h window, refreshed every 3h
$maxMsgs    = 1200;
$maxTokens  = 6500;

$force = !empty($_GET['force']) || (PHP_SAPI === 'cli' && in_array('--force', $argv ?? [], true));

// Which to run: ?platform=twitter|youtube|all, or a CLI arg. Empty = twitter
// + youtube + the unified "all" brief.
$only = strtolower(trim((string)($_GET['platform'] ?? ($argv[1] ?? ''))));
$only = in_array($only, ['twitter', 'youtube', 'all'], true) ? $only : '';
$platforms = $only === '' ? ['twitter', 'youtube'] : ($only === 'all' ? [] : [$only]);

foreach ($platforms as $platform) {
    // Cadence guard (~2.5h floor for clock drift), unless &force=1.
    if (!$force) {
        $latest = social_summary_get_latest($platform);
        if ($latest && !empty($latest['generated_at'])) {
            $ageSecs = time() - strtotime($latest['generated_at']);
            if ($ageSecs < 9000) {
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

// ── Unified cross-platform brief (Telegram + X + YouTube, de-duplicated) ──
if ($only === '' || $only === 'all') {
    $skip = false;
    if (!$force) {
        $latest = social_summary_get_latest('all');
        if ($latest && !empty($latest['generated_at'])) {
            $ageSecs = time() - strtotime($latest['generated_at']);
            if ($ageSecs < 9000) {
                $hrs = round($ageSecs / 3600, 1);
                echo "all: skip — latest is only {$hrs}h old (id={$latest['id']})\n";
                $skip = true;
            }
        }
    }
    if (!$skip) {
        $messages = social_summary_collect_all($windowMins, $maxMsgs);
        if (count($messages) < 5) {
            echo "all: skip — only " . count($messages) . " posts in last 24h\n";
        } else {
            $start   = microtime(true);
            $ai      = ai_summarize_social_daily('all', $messages, $maxTokens);
            $elapsed = round(microtime(true) - $start, 2);
            if (empty($ai['ok'])) {
                $err = $ai['error'] ?? 'unknown';
                echo "all: fail — {$err} ({$elapsed}s)\n";
                error_log("[cron_social_summary] all generation failed: {$err}");
            } else {
                $id = social_summary_save('all', $ai, count($messages), $windowMins);
                if (!$id) {
                    echo "all: fail — could not save to DB\n";
                } else {
                    social_summary_prune('all', 60);
                    echo "all: ok — saved #{$id} | " . count($messages) . " posts | {$elapsed}s\n";
                    echo "  headline: " . ($ai['headline'] ?? '') . "\n";
                }
            }
        }
    }
}
