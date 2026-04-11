<?php
/**
 * Diagnostic + repair endpoint.
 *
 * Use when the webhook deploy reports success but files that should be
 * present on disk (e.g. panel/evolving_stories.php) appear to be
 * missing. Dumps git state, directory listings, and file existence so
 * we can see what the server actually has — and, with ?repair=1,
 * force-re-runs `git fetch + reset --hard + clean -fdx` to fix drift.
 *
 * Auth: ?key=<cron_key>  (same setting used by cron endpoints)
 * Extra knobs:
 *   ?repair=1     → run fetch/reset/clean and opcache invalidate
 *   ?clean=1      → also pass -x to git clean (removes ignored files too)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

$expected = getSetting('cron_key', '');
if (!$expected || ($_GET['key'] ?? '') !== $expected) {
    http_response_code(403);
    exit("forbidden — pass ?key=<cron_key>\n");
}

@set_time_limit(300);

echo "=== SERVER REPAIR / DIAGNOSTIC ===\n";
echo "time: " . date('Y-m-d H:i:s') . "\n";
echo "php:  " . PHP_VERSION . "\n";
echo "dir:  " . __DIR__ . "\n";
echo "host: " . ($_SERVER['HTTP_HOST'] ?? 'cli') . "\n";
echo "\n";

// -------- 1. Git state --------
$repoDir = escapeshellarg(__DIR__);
echo "--- git status ---\n";
echo shell_exec('cd ' . $repoDir . ' && git rev-parse --abbrev-ref HEAD 2>&1');
echo shell_exec('cd ' . $repoDir . ' && git rev-parse HEAD 2>&1');
echo shell_exec('cd ' . $repoDir . ' && git log -1 --oneline 2>&1');
echo "\nremotes:\n";
echo shell_exec('cd ' . $repoDir . ' && git remote -v 2>&1');
echo "\nstatus (working tree):\n";
echo shell_exec('cd ' . $repoDir . ' && git status --short 2>&1');
echo "\n";

// -------- 2. Check key files existence (what the UI reported missing) --------
$checkFiles = [
    'panel/evolving_stories.php',
    'panel/preview.php',
    'panel/includes/panel_layout_head.php',
    'panel/ticker.php',
    'evolving-stories.php',
    'evolving-story.php',
    'evolving-story-book.php',
    'evolving-story-quotes.php',
    'evolving-stories-network.php',
    'includes/evolving_stories.php',
    'includes/evolving_stories_ai.php',
    'cron_evolving_ai.php',
    'migrations/002_evolving_stories.sql',
    'migrations/003_evolving_stories_phase2.sql',
];

echo "--- file existence check ---\n";
foreach ($checkFiles as $rel) {
    $p = __DIR__ . '/' . $rel;
    $exists = file_exists($p);
    $size   = $exists ? filesize($p) : 0;
    $mtime  = $exists ? date('Y-m-d H:i:s', filemtime($p)) : '-';
    printf("%-50s  %-7s  %8s  %s\n",
        $rel,
        $exists ? 'EXISTS' : 'MISSING',
        $exists ? $size . 'B' : '-',
        $mtime
    );
}
echo "\n";

// -------- 3. panel/ directory listing --------
echo "--- ls panel/ ---\n";
$panelDir = __DIR__ . '/panel';
if (is_dir($panelDir)) {
    $items = scandir($panelDir);
    sort($items);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $panelDir . '/' . $item;
        $type = is_dir($full) ? 'dir ' : 'file';
        $size = is_file($full) ? filesize($full) : 0;
        $mtime = date('Y-m-d H:i:s', filemtime($full));
        printf("%s  %8s  %s  %s\n", $type, $size, $mtime, $item);
    }
} else {
    echo "panel/ dir NOT FOUND at $panelDir\n";
}
echo "\n";

// -------- 4. Root php file count (match deploy log) --------
echo "--- php file count (recursive, excluding .git and vendor) ---\n";
$count = 0;
try {
    $rii = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS),
            function ($current) {
                $name = $current->getFilename();
                if ($name === '.git' || $name === 'vendor') return false;
                return true;
            }
        )
    );
    foreach ($rii as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
            $count++;
        }
    }
} catch (Throwable $e) {
    echo "iterator error: " . $e->getMessage() . "\n";
}
echo "total php files: $count\n\n";

// -------- 5. Permissions of current dir and key subdirs --------
echo "--- permissions ---\n";
$checkDirs = ['', 'panel', 'includes', 'migrations', 'storage', 'storage/cache'];
foreach ($checkDirs as $d) {
    $p = __DIR__ . ($d === '' ? '' : '/' . $d);
    if (file_exists($p)) {
        $perms = substr(sprintf('%o', fileperms($p)), -4);
        $w     = is_writable($p) ? 'W' : '-';
        $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($p))['name'] ?? fileowner($p)) : fileowner($p);
        printf("%-20s  %s  %s  owner=%s\n", $d ?: '.', $perms, $w, $owner);
    } else {
        printf("%-20s  MISSING\n", $d);
    }
}
echo "\n";

// -------- 6. Repair action (only if ?repair=1) --------
if (($_GET['repair'] ?? '') === '1') {
    echo "=== REPAIR: forcing git fetch + reset --hard + clean ===\n";
    echo shell_exec('cd ' . $repoDir . ' && git fetch origin main 2>&1');
    echo shell_exec('cd ' . $repoDir . ' && git reset --hard origin/main 2>&1');
    $cleanFlag = ($_GET['clean'] ?? '') === '1' ? '-fdx' : '-fd';
    echo shell_exec('cd ' . $repoDir . ' && git clean ' . $cleanFlag . ' 2>&1');
    echo "\n";
    echo shell_exec('cd ' . $repoDir . ' && git log -1 --oneline 2>&1');
    echo "\n";

    // Re-check the files after repair
    echo "--- after-repair recheck ---\n";
    foreach ($checkFiles as $rel) {
        $p = __DIR__ . '/' . $rel;
        printf("%-50s  %s\n", $rel, file_exists($p) ? 'EXISTS' : 'MISSING');
    }
    echo "\n";

    // Opcache reset
    if (function_exists('opcache_reset')) {
        opcache_reset();
        echo "opcache_reset() ok\n";
    } else {
        echo "opcache not available\n";
    }

    @touch(__DIR__ . '/index.php');
}

echo "\n=== END ===\n";
