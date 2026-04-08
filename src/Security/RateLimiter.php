<?php
declare(strict_types=1);

namespace NewsFlow\Security;

/**
 * File-based sliding window rate limiter.
 */
final class RateLimiter
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? __DIR__ . '/../../storage/ratelimit';
        if (!is_dir($this->dir)) @mkdir($this->dir, 0755, true);
    }

    /**
     * @return bool true if allowed, false if the limit has been exceeded
     */
    public function check(string $key, int $limit, int $window): bool
    {
        $bucket = (int) (time() / $window);
        $file = $this->dir . '/' . md5($key . ':' . $bucket) . '.rl';

        $fp = @fopen($file, 'c+');
        if (!$fp) return true; // fail-open

        flock($fp, LOCK_EX);
        $count = (int) stream_get_contents($fp);
        $count++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $count);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (mt_rand(1, 100) === 1) $this->gc($window);

        return $count <= $limit;
    }

    public function gc(int $window): void
    {
        $cutoff = time() - ($window * 3);
        foreach (glob($this->dir . '/*.rl') ?: [] as $f) {
            if (@filemtime($f) < $cutoff) @unlink($f);
        }
    }

    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string) $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
