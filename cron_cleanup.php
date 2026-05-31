<?php
/**
 * Periodic cleanup to keep the database from growing without bound.
 * Run nightly via cron: cron_cleanup.php?key=XXX
 *
 * What it deletes:
 * - article_view_events older than 48h (only the recent window is used
 *   for the trending velocity score)
 * - password_resets that are used or expired
 * - user_notifications older than 60 days (Settings → "إشعارات" UI
 *   never paginates that far back)
 * - comment_reports that have been actioned/dismissed > 30 days
 * - cache files older than 1 day (file cache TTL is honored on read,
 *   but stale files still sit on disk)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(120);
$db = getDB();
$out = [];

function _try_delete(PDO $db, string $sql, array $params, string $label, array &$out): void {
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        $n = $st->rowCount();
        if ($n > 0) $out[] = sprintf('- %s: حذف %d صف', $label, $n);
    } catch (Throwable $e) {
        // Don't fail the whole cron because one table is missing on a fresh deploy.
        $out[] = sprintf('! %s: تخطّى (%s)', $label, $e->getMessage());
    }
}

// Trending velocity reads only the last 24h. Anything older is dead weight.
_try_delete($db,
    "DELETE FROM article_view_events WHERE viewed_at < (NOW() - INTERVAL 48 HOUR)",
    [], 'article_view_events قديمة (>48h)', $out);

// Used or expired password reset codes.
_try_delete($db,
    "DELETE FROM password_resets WHERE used_at IS NOT NULL OR expires_at < NOW()",
    [], 'password_resets منتهية', $out);

// Long-tail notifications no user is going to scroll back to.
_try_delete($db,
    "DELETE FROM user_notifications WHERE created_at < (NOW() - INTERVAL 60 DAY)",
    [], 'user_notifications قديمة (>60d)', $out);

// Comment reports that have been actioned or dismissed.
_try_delete($db,
    "DELETE FROM comment_reports WHERE status IN ('actioned','dismissed') AND reviewed_at < (NOW() - INTERVAL 30 DAY)",
    [], 'comment_reports منجزة قديمة', $out);

// On-disk cache: file-cache honors TTL on read, but never sweeps. Drop
// anything not touched in the last 24h.
$cacheDir = __DIR__ . '/storage/cache';
if (is_dir($cacheDir)) {
    $cutoff = time() - 86400;
    $cleaned = 0;
    foreach (glob($cacheDir . '/*.cache') ?: [] as $f) {
        $mt = @filemtime($f);
        if ($mt && $mt < $cutoff) {
            if (@unlink($f)) $cleaned++;
        }
    }
    if ($cleaned > 0) $out[] = "- ملفات الكاش قديمة: حذف $cleaned ملف";
}

if (empty($out)) {
    echo "✓ لا شي لينظَّف\n";
} else {
    echo "تم التنظيف:\n" . implode("\n", $out) . "\n";
}
