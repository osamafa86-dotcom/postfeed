<?php
/**
 * Simple file-based rate limiter (sliding window via fixed buckets).
 *
 * Usage:
 *   if (!rate_limit_check('api:'.client_ip(), 60, 60)) {
 *       http_response_code(429);
 *       header('Retry-After: 60');
 *       echo json_encode(['error' => 'rate_limit']);
 *       exit;
 *   }
 */

function rate_limit_dir() {
    $dir = __DIR__ . '/../storage/ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function client_ip() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Check and increment rate limit counter.
 * @param string $key      Unique key (e.g. "api:1.2.3.4")
 * @param int    $limit    Max requests per window
 * @param int    $window   Window length in seconds
 * @return bool  true if allowed, false if exceeded
 */
function rate_limit_check($key, $limit, $window) {
    $bucket = (int)(time() / $window);
    $file = rate_limit_dir() . '/' . md5($key . ':' . $bucket) . '.rl';

    $fp = @fopen($file, 'c+');
    if (!$fp) return true; // fail-open

    flock($fp, LOCK_EX);
    $count = (int) stream_get_contents($fp);
    $count++;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)$count);
    flock($fp, LOCK_UN);
    fclose($fp);

    // Opportunistic cleanup of stale buckets (1% chance)
    if (mt_rand(1, 100) === 1) rate_limit_gc($window);

    return $count <= $limit;
}

function rate_limit_gc($window) {
    $cutoff = time() - ($window * 3);
    foreach (glob(rate_limit_dir() . '/*.rl') as $f) {
        if (@filemtime($f) < $cutoff) @unlink($f);
    }
}

/**
 * Respond 429 and exit. For API endpoints.
 */
function rate_limit_enforce_api($key, $limit, $window) {
    if (!rate_limit_check($key, $limit, $window)) {
        http_response_code(429);
        header('Retry-After: ' . $window);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'rate_limit_exceeded', 'retry_after' => $window]);
        exit;
    }
}
