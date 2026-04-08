<?php
/**
 * Simple file-based cache.
 * Usage:
 *   $data = cache_remember('key', 300, function() { return expensiveQuery(); });
 *   cache_forget('key');
 *   cache_flush();
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

function cache_get($key) {
    $file = cache_path($key);
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $payload = @unserialize($raw);
    if (!is_array($payload) || !isset($payload['exp'], $payload['val'])) return null;
    if ($payload['exp'] > 0 && $payload['exp'] < time()) {
        @unlink($file);
        return null;
    }
    return $payload['val'];
}

function cache_set($key, $value, $ttl = 300) {
    $file = cache_path($key);
    $payload = [
        'exp' => $ttl > 0 ? (time() + $ttl) : 0,
        'val' => $value,
    ];
    @file_put_contents($file, serialize($payload), LOCK_EX);
}

function cache_remember($key, $ttl, callable $callback) {
    $hit = cache_get($key);
    if ($hit !== null) return $hit;
    $val = $callback();
    cache_set($key, $val, $ttl);
    return $val;
}

function cache_forget($key) {
    $file = cache_path($key);
    if (is_file($file)) @unlink($file);
}

function cache_flush() {
    $dir = cache_dir();
    foreach (glob($dir . '/*.cache') as $f) @unlink($f);
}
