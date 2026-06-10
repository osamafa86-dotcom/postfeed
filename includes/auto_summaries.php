<?php
/**
 * Self-healing summary generator trigger.
 *
 * Same idea as includes/auto_fetch.php (which keeps cron_rss.php
 * running even when the cPanel cron stops firing), but for the
 * briefing pipelines: morning briefing, telegram, twitter, youtube,
 * and the weekly rewind.
 *
 * Each surface has its own staleness threshold:
 *   - sabah    every 24h
 *   - tg       every 4h
 *   - twitter  every 4h
 *   - youtube  every 4h
 *   - weekly   every 7 days (only on Saturday onward, since the
 *              cron is supposed to run Saturday ~20:00 anyway)
 *
 * Spawned as DETACHED processes via exec(... > /dev/null 2>&1 &) so
 * the FPM worker is freed in <1 ms (same pattern that solved the
 * "offline page on article click" bug earlier this week).
 */

function auto_trigger_summaries_if_stale(): void {
    if (!function_exists('exec')) return;
    if (!function_exists('getDB')) return;

    // Cheap top-level guard so we only hit the DB at most every 5
    // minutes regardless of how many homepage/summaries hits we get.
    $tld = sys_get_temp_dir() . '/postfeed_summaries_topcheck.flag';
    if (file_exists($tld) && (time() - filemtime($tld)) < 300) return;
    @touch($tld);

    $phpBin = PHP_BINARY ?: 'php';
    $base   = dirname(__DIR__);

    // [staleness_seconds, cron_filename, lock_key]
    $surfaces = [
        'sabah'   => [86400,    'cron_sabah.php',          'sabah'],
        'tg'      => [4 * 3600, 'cron_tg_summary.php',     'tg'],
        'social'  => [4 * 3600, 'cron_social_summary.php', 'social'],
    ];

    foreach ($surfaces as $key => $cfg) {
        [$thresholdSec, $cronFile, $lockKey] = $cfg;

        // Per-surface lock (10 min) so we don't fan out multiple
        // spawns of the same cron while a prior one is still running.
        $lockFile = sys_get_temp_dir() . '/postfeed_summary_' . $lockKey . '.lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 600) continue;

        // Pull the latest-generated timestamp without loading the
        // whole row — keeps the homepage path cheap.
        $age = PHP_INT_MAX;
        try {
            $db = getDB();
            switch ($key) {
                case 'sabah':
                    $ts = $db->query("SELECT UNIX_TIMESTAMP(MAX(generated_at)) FROM sabah_briefings")->fetchColumn();
                    break;
                case 'tg':
                    $ts = $db->query("SELECT UNIX_TIMESTAMP(MAX(generated_at)) FROM tg_summaries")->fetchColumn();
                    break;
                case 'social':
                    // Trigger if EITHER twitter OR youtube is stale.
                    $ts = $db->query("SELECT UNIX_TIMESTAMP(MIN(latest)) FROM (
                                        SELECT MAX(generated_at) AS latest FROM social_summaries WHERE platform = 'twitter'
                                        UNION ALL
                                        SELECT MAX(generated_at) AS latest FROM social_summaries WHERE platform = 'youtube'
                                      ) t")->fetchColumn();
                    break;
                default:
                    $ts = null;
            }
            if ($ts) $age = time() - (int)$ts;
        } catch (Throwable $e) {
            continue; // table missing or DB hiccup → skip this surface
        }

        if ($age < $thresholdSec) continue; // still fresh

        @touch($lockFile);
        $cmd = escapeshellcmd($phpBin) . ' '
             . escapeshellarg($base . '/' . $cronFile)
             . ' > /dev/null 2>&1 &';
        @exec($cmd);
    }

    // Weekly rewind: only worth firing on/after Saturday, since the
    // cron is supposed to compose the week's digest Saturday ~20:00.
    // We check the row count instead of generated_at because the
    // weekly_rewinds schema uses published_at and we already gate by
    // the day-of-week.
    if ((int)date('N') >= 6) { // Sat(6) or Sun(7)
        $lockFile = sys_get_temp_dir() . '/postfeed_summary_weekly.lock';
        if (!file_exists($lockFile) || (time() - filemtime($lockFile)) >= 600) {
            try {
                $db = getDB();
                $latest = $db->query("SELECT UNIX_TIMESTAMP(MAX(published_at)) FROM weekly_rewinds")->fetchColumn();
                $age = $latest ? (time() - (int)$latest) : PHP_INT_MAX;
                if ($age >= 6 * 86400) {
                    @touch($lockFile);
                    $cmd = escapeshellcmd($phpBin) . ' '
                         . escapeshellarg($base . '/cron_weekly_rewind.php')
                         . ' > /dev/null 2>&1 &';
                    @exec($cmd);
                }
            } catch (Throwable $e) { /* schema missing; ignore */ }
        }
    }
}
