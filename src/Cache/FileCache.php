<?php
declare(strict_types=1);

namespace NewsFlow\Cache;

/**
 * Object wrapper around the file-based cache. Uses the same storage
 * location as the procedural cache_*() helpers so the two APIs coexist.
 */
final class FileCache
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? __DIR__ . '/../../storage/cache';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . md5($key) . '.cache';
    }

    public function get(string $key): mixed
    {
        $file = $this->path($key);
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

    public function set(string $key, mixed $value, int $ttl = 300): void
    {
        $payload = [
            'exp' => $ttl > 0 ? (time() + $ttl) : 0,
            'val' => $value,
        ];
        @file_put_contents($this->path($key), serialize($payload), LOCK_EX);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $hit = $this->get($key);
        if ($hit !== null) return $hit;
        $val = $callback();
        $this->set($key, $val, $ttl);
        return $val;
    }

    public function forget(string $key): void
    {
        $file = $this->path($key);
        if (is_file($file)) @unlink($file);
    }

    public function flush(): void
    {
        foreach (glob($this->dir . '/*.cache') ?: [] as $f) @unlink($f);
    }
}
