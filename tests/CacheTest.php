<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/cache.php';

class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        cache_flush();
    }

    protected function tearDown(): void
    {
        cache_flush();
    }

    public function testSetAndGet(): void
    {
        cache_set('foo', ['a' => 1, 'b' => 'hello'], 60);
        $this->assertSame(['a' => 1, 'b' => 'hello'], cache_get('foo'));
    }

    public function testGetMissReturnsNull(): void
    {
        $this->assertNull(cache_get('nonexistent_' . uniqid()));
    }

    public function testExpiry(): void
    {
        // Write an already-expired payload directly to the cache file.
        // Avoids cache_set(), which would populate the in-memory memo
        // and cause cache_get() to short-circuit past the file check.
        $file = cache_path('expiring');
        $data = ['exp' => time() - 10, 'val' => 'value'];
        file_put_contents($file, serialize($data));
        $this->assertNull(cache_get('expiring'));
    }

    public function testRememberCallsCallbackOnce(): void
    {
        $calls = 0;
        $cb = function() use (&$calls) {
            $calls++;
            return 'computed';
        };

        $this->assertSame('computed', cache_remember('k1', 60, $cb));
        $this->assertSame('computed', cache_remember('k1', 60, $cb));
        $this->assertSame(1, $calls);
    }

    public function testForget(): void
    {
        cache_set('delete_me', 'value', 60);
        cache_forget('delete_me');
        $this->assertNull(cache_get('delete_me'));
    }

    public function testFlush(): void
    {
        cache_set('k1', 1, 60);
        cache_set('k2', 2, 60);
        cache_flush();
        $this->assertNull(cache_get('k1'));
        $this->assertNull(cache_get('k2'));
    }
}
