<?php
/**
 * Simple file-based cache with a per-request in-memory layer on top.
 *
 * Usage:
 *   $data = cache_remember('key', 300, function() { return expensiveQuery(); });
 *   cache_forget('key');
 *   cache_flush();
 *
 * The homepage makes ~20 separate cache_remember calls per request
 * (hero, breaking, palestine, latest, categories, sources, trends,
 *  settings, poll, reels, …). Without a memoization layer each one
 * stats+reads+unserializes its own cache file. The static $memo below
 * makes subsequent lookups for the same key free within a request.
 */

function cache_dir() {
    $dir = __DIR__ . '/../storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function cache_path($key) {
    return cache_dir() . '/' . md5($key) . '.cache';
}

/**
 * Per-request memo store. A sentinel object distinguishes "missing"
 * from "null was legitimately cached", and a dedicated negative cache
 * avoids re-statting a missing file on every call within the request.
 */
function &_cache_memo() {
    static $memo = [];
    return $memo;
}
function _cache_memo_miss() {
    static $sentinel;
    if ($sentinel === null) $sentinel = new stdClass();
    return $sentinel;
}

function cache_get($key) {
    $memo =& _cache_memo();
    if (array_key_exists($key, $memo)) {
        $val = $memo[$key];
        if ($val === _cache_memo_miss()) return null;
        return $val;
    }
    $file = cache_path($key);
    if (!is_file($file)) {
        $memo[$key] = _cache_memo_miss();
        return null;
    }
    $raw = @file_get_contents($file);
    if ($raw === false) {
        $memo[$key] = _cache_memo_miss();
        return null;
    }
    $payload = @unserialize($raw);
    if (!is_array($payload) || !isset($payload['exp'], $payload['val'])) {
        $memo[$key] = _cache_memo_miss();
        return null;
    }
    if ($payload['exp'] > 0 && $payload['exp'] < time()) {
        @unlink($file);
        $memo[$key] = _cache_memo_miss();
        return null;
    }
    $memo[$key] = $payload['val'];
    return $payload['val'];
}

function cache_set($key, $value, $ttl = 300) {
    $file = cache_path($key);
    $payload = [
        'exp' => $ttl > 0 ? (time() + $ttl) : 0,
        'val' => $value,
    ];
    @file_put_contents($file, serialize($payload), LOCK_EX);
    $memo =& _cache_memo();
    $memo[$key] = $value;
}

function cache_remember($key, $ttl, callable $callback) {
    $memo =& _cache_memo();
    if (array_key_exists($key, $memo) && $memo[$key] !== _cache_memo_miss()) {
        return $memo[$key];
    }
    $hit = cache_get($key);
    if ($hit !== null) return $hit;
    $val = $callback();
    cache_set($key, $val, $ttl);
    return $val;
}

function cache_forget($key) {
    $file = cache_path($key);
    if (is_file($file)) @unlink($file);
    $memo =& _cache_memo();
    unset($memo[$key]);
}

function cache_flush() {
    $dir = cache_dir();
    foreach (glob($dir . '/*.cache') as $f) @unlink($f);
    $memo =& _cache_memo();
    $memo = [];
}
