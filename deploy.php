<?php
/**
 * Auto-deploy from GitHub webhook.
 *
 * Verifies the HMAC signature sent by GitHub, hard-resets the working
 * tree to origin/main (so any manual cPanel edits on the server do not
 * block the sync), flushes the file cache, and nukes opcache so the
 * next request loads the freshly-pulled PHP.
 *
 * Concurrency model: if a deploy arrives while another is running, we
 * set a "pending" flag and return 202. The running deploy picks up the
 * flag after it finishes and deploys again — so the latest commit wins
 * even when two webhooks fire within seconds of each other (force-push
 * + merge-to-main is the usual culprit).
 */

$lockFile    = sys_get_temp_dir() . '/postfeed_deploy.lock';
$pendingFile = sys_get_temp_dir() . '/postfeed_deploy_pending.flag';
$logFile     = __DIR__ . '/deploy_log.txt';

// --- 1. Verify HMAC signature first -----------------------------------
$secret = getenv('DEPLOY_SECRET');
if (!$secret) {
    http_response_code(500);
    die('DEPLOY_SECRET env not set');
}
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');
$hash = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($hash, $signature)) {
    http_response_code(403);
    die('Unauthorized');
}

// --- 2. If a deploy is already running, queue this one ----------------
// Stale lock after 120 seconds is assumed dead (process crashed).
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 120) {
    touch($pendingFile);
    http_response_code(202);
    die('Deploy queued — will run after the current one finishes');
}

// --- 3. Acquire the lock ----------------------------------------------
touch($lockFile);

// Make sure we release the lock even if something below throws.
register_shutdown_function(function () use ($lockFile) {
    @unlink($lockFile);
});

// --- 4. Run the deploy, possibly more than once if another webhook
// --- fires while we're running. ---------------------------------------
function run_deploy_cycle(): string {
    $repoDir = escapeshellarg(__DIR__);
    $output  = shell_exec('cd ' . $repoDir . ' && git fetch origin main 2>&1');
    $output .= shell_exec('cd ' . $repoDir . ' && git reset --hard origin/main 2>&1');
    $output .= shell_exec('cd ' . $repoDir . ' && git clean -fd 2>&1');

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

    @touch(__DIR__ . '/index.php');

    return $output . "\n" . $cacheMsg . "\n" . $opcacheMsg;
}

$logEntry = date('Y-m-d H:i:s') . " - Deploy triggered from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";

$allOutput  = run_deploy_cycle();
$logEntry  .= $allOutput . "\n";

// If another webhook landed while we were running, run again. Cap at 2
// extra cycles so a stuck pending flag can't loop forever.
$maxChained = 2;
while (file_exists($pendingFile) && $maxChained-- > 0) {
    @unlink($pendingFile);
    // Refresh lock mtime so concurrent callers keep queuing.
    touch($lockFile);
    $chained = run_deploy_cycle();
    $logEntry .= "\n--- chained re-deploy ---\n" . $chained . "\n";
    $allOutput .= "\n\n--- chained re-deploy ---\n" . $chained;
}

$logEntry .= "---\n";
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

@unlink($pendingFile);
@unlink($lockFile);

echo "Deploy done:\n" . $allOutput;
