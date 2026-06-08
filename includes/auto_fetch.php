<?php
/**
 * Self-healing RSS fetch trigger.
 *
 * The site relies on a cPanel cron to run cron_rss.php every few
 * minutes. When that cron misses runs (host downtime, paused job,
 * cPanel deactivating it after a server move), the homepage can sit
 * with stale content for hours.
 *
 * This helper fires off cron_rss.php in the background from the
 * homepage when the freshest sources.last_fetched_at is older than
 * a threshold (default 30 minutes). It is gated by a filesystem lock
 * so concurrent page views never spawn more than one fetch, and the
 * trigger happens AFTER the response is flushed to the client so
 * the page load stays fast.
 */

/**
 * Kick off cron_rss.php in the background if the last fetch is old.
 *
 * @param int $thresholdSeconds Trigger when last fetch is older than this.
 * @return void
 */
function auto_trigger_rss_fetch_if_stale(int $thresholdSeconds = 1800): void {
    // Cheap path: rely on a small file flag so we only hit the DB
    // every $thresholdSeconds at most, not on every page view.
    $flagFile = sys_get_temp_dir() . '/postfeed_rss_freshcheck.flag';
    if (file_exists($flagFile) && (time() - filemtime($flagFile)) < 60) {
        return; // checked recently
    }
    @touch($flagFile);

    // Single-flight lock so concurrent visitors don't fan out into N forks.
    $lockFile = sys_get_temp_dir() . '/postfeed_rss_autotrigger.lock';
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
        return; // a fetch is already running (or recently ran)
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

    // Defer the actual fork to after the response is sent so the
    // homepage paint isn't held up. fastcgi_finish_request() is the
    // standard PHP-FPM hook; if it's not available we fall back to
    // ignore_user_abort + a flush.
    register_shutdown_function(function() use ($lockFile) {
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            @ob_end_flush();
            @flush();
        }
        // Run cron_rss inline in this shutdown handler. The lock file
        // keeps subsequent visitors from re-triggering until it's done.
        @set_time_limit(180);
        try {
            require __DIR__ . '/../cron_rss.php';
        } catch (Throwable $e) {
            @error_log('auto_trigger_rss: ' . $e->getMessage());
        }
        @unlink($lockFile);
    });
}
