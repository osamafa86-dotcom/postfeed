<?php
/**
 * Build-fingerprint helper.
 *
 * Exposes app_version() — a small struct describing the code currently
 * running on the server. Used by the site header to emit a console
 * banner + a <meta> tag so we can visually confirm "yes, my commit is
 * live" without SSH'ing in.
 *
 * The semver comes from the VERSION file at the repo root (bump
 * manually for notable releases). The build suffix is derived from
 * the git short SHA — read straight out of .git so we don't need
 * shell_exec, which is disabled on most shared hosting.
 *
 * Everything is memoised per-request.
 */

function app_version(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $root = dirname(__DIR__);

    // Semver from VERSION file — single source of truth, easy to bump.
    $semver = '0.0.0';
    $vfile = $root . '/VERSION';
    if (is_readable($vfile)) {
        $raw = trim((string) @file_get_contents($vfile));
        if ($raw !== '') $semver = $raw;
    }

    // Short SHA — pulled from .git/HEAD, following the symbolic ref
    // into refs/heads/<branch>. Falls back to packed-refs for branches
    // that have been garbage-collected into the packfile.
    $sha = '';
    $shaTime = 0;
    $head = $root . '/.git/HEAD';
    if (is_readable($head)) {
        $headRaw = trim((string) @file_get_contents($head));
        if (strpos($headRaw, 'ref: ') === 0) {
            $refPath = $root . '/.git/' . substr($headRaw, 5);
            if (is_readable($refPath)) {
                $sha = substr(trim((string) @file_get_contents($refPath)), 0, 7);
                $shaTime = (int) @filemtime($refPath);
            } else {
                // Ref might only exist in packed-refs.
                $packed = $root . '/.git/packed-refs';
                if (is_readable($packed)) {
                    $target = substr($headRaw, 5);
                    foreach ((array) @file($packed, FILE_IGNORE_NEW_LINES) as $line) {
                        if ($line === '' || $line[0] === '#' || $line[0] === '^') continue;
                        if (str_ends_with($line, ' ' . $target)) {
                            $sha = substr(trim(strtok($line, ' ')), 0, 7);
                            $shaTime = (int) @filemtime($packed);
                            break;
                        }
                    }
                }
            }
        } else {
            // Detached HEAD — raw SHA.
            $sha = substr($headRaw, 0, 7);
            $shaTime = (int) @filemtime($head);
        }
    }

    // Deploy time: the most recent touch of HEAD or the ref file is a
    // good proxy — deploy.php does `git reset --hard` and touches these.
    $deployedAt = $shaTime ?: (int) @filemtime($root . '/index.php');

    $full = $sha !== '' ? ($semver . '+' . $sha) : $semver;

    return $cached = [
        'semver'       => $semver,
        'sha'          => $sha,
        'full'         => $full,
        'deployed_at'  => $deployedAt,
        'deployed_iso' => $deployedAt ? date('c', $deployedAt) : '',
    ];
}
