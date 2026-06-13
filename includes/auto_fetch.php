<?php
/**
 * Self-healing RSS fetch trigger.
 *
 * Spawns cron_rss.php as a DETACHED background process when the
 * freshest sources.last_fetched_at is older than the threshold.
 *
 * Important: we don't run cron_rss inline (even from a shutdown
 * handler) because cron_rss takes 30–120 seconds and holds the
 * FPM worker hostage until it returns. On shared hosting with a
 * small worker pool that quickly starves other visitors, who then
 * get the offline page from the service worker.
 *
 * Instead we use `exec(... > /dev/null 2>&1 &)` to fork a fully
 * detached PHP CLI process, so this function returns in <1ms and
 * the FPM worker is freed immediately. If exec is disabled by
 * disable_functions, the whole helper no-ops silently — the
 * cPanel cron is still the primary trigger.
 */

function auto_trigger_rss_fetch_if_stale(int $thresholdSeconds = 1800): void {
    // We dispatch via nf_trigger_cron() (detached curl to the cron's
    // HTTP key path). Bail silently if the helper isn't loaded.
    if (!function_exists('nf_trigger_cron')) return;

    // Cheap path: only consult the DB once per 60 seconds so a
    // burst of homepage visits doesn't add a SELECT to each one.
    $flagFile = sys_get_temp_dir() . '/postfeed_rss_freshcheck.flag';
    if (file_exists($flagFile) && (time() - filemtime($flagFile)) < 60) {
        return;
    }
    @touch($flagFile);

    // Single-flight lock — held for 5 minutes — so the moment we
    // spawn a fetch, the next ~5 minutes of visitors skip the
    // check entirely.
    $lockFile = sys_get_temp_dir() . '/postfeed_rss_autotrigger.lock';
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
        return;
    }

    try {
        $db = getDB();
        $last = $db->query("SELECT MAX(last_fetched_at) FROM sources WHERE is_active = 1")->fetchColumn();
        if (!$last) return;
        $age = time() - strtotime((string)$last);
        if ($age < $thresholdSeconds) return;
    } catch (Throwable $e) {
        return;
    }

    @touch($lockFile);

    // Detached fork — does NOT block the current FPM worker.
    // Detached curl to cron_rss.php's HTTP path — see nf_trigger_cron().
    // (The old exec(PHP_BINARY cron_rss.php &) spawn was broken on this
    // LiteSpeed host: PHP_BINARY is lsphp, not CLI php, so it ran nothing.)
    nf_trigger_cron('cron_rss.php');
}
