<?php
/**
 * Auto-deploy from GitHub webhook.
 *
 * Verifies the HMAC signature sent by GitHub, hard-resets the working
 * tree to origin/main (so any manual cPanel edits on the server do not
 * block the sync), flushes the file cache, and nukes opcache so the
 * next request loads the freshly-pulled PHP.
 */

// Rate limiting - max 1 deploy per 10 seconds (enough to stop abuse,
// short enough that two back-to-back PR merges both get applied).
$lockFile = sys_get_temp_dir() . '/postfeed_deploy.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 10) {
    http_response_code(429);
    die('Too many requests');
}

// Verify webhook secret from environment or fallback
$secret = getenv('DEPLOY_SECRET') ?: 'postfeed_deploy_2026';

$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');
$hash = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($hash, $signature)) {
    http_response_code(403);
    die('Unauthorized');
}

// Update lock file
touch($lockFile);

// Log deployment
$logFile = __DIR__ . '/deploy_log.txt';
$logEntry = date('Y-m-d H:i:s') . " - Deploy triggered from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";

// Hard-sync to origin/main. `git pull` silently fails if the working
// tree has local modifications (e.g. an .htaccess edited in cPanel),
// which is exactly how we ended up with stale code on the server
// after merging PR #149. `fetch + reset --hard` always wins.
$repoDir = escapeshellarg(__DIR__);
$output  = shell_exec('cd ' . $repoDir . ' && git fetch origin main 2>&1');
$output .= shell_exec('cd ' . $repoDir . ' && git reset --hard origin/main 2>&1');
$output .= shell_exec('cd ' . $repoDir . ' && git clean -fd 2>&1');

// Flush our file cache so cache_remember() callbacks re-run against
// the newly-pulled code instead of returning stale blobs from
// storage/cache.
$cacheMsg = '';
$cacheFile = __DIR__ . '/includes/cache.php';
if (is_file($cacheFile)) {
    require_once $cacheFile;
    if (function_exists('cache_flush')) {
        cache_flush();
        $cacheMsg = 'file cache flushed';
    } else {
        $cacheMsg = 'cache_flush() not available';
    }
} else {
    $cacheMsg = 'cache.php not found';
}

// Invalidate opcache so newly-pulled PHP files take effect immediately.
// opcache_reset() alone is unreliable on shared hosting with multiple
// FPM pools, so we also invalidate each PHP file individually and rely
// on .user.ini to enable timestamp validation as a belt-and-braces.
$opcacheMsg = '';
if (function_exists('opcache_reset')) {
    $opcacheMsg = opcache_reset() ? 'opcache reset ok' : 'opcache reset failed';
    if (function_exists('opcache_invalidate')) {
        $count = 0;
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                __DIR__,
                FilesystemIterator::SKIP_DOTS
            )
        );
        foreach ($rii as $file) {
            if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                if (@opcache_invalidate($file->getPathname(), true)) {
                    $count++;
                }
            }
        }
        $opcacheMsg .= "; invalidated {$count} php files";
    }
} else {
    $opcacheMsg = 'opcache not available';
}

// Touch index.php so any remaining bytecode cache sees a new mtime.
@touch(__DIR__ . '/index.php');

$logEntry .= $output . "\n" . $cacheMsg . "\n" . $opcacheMsg . "\n---\n";
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

echo "Deploy done:\n" . $output . "\n" . $cacheMsg . "\n" . $opcacheMsg;
