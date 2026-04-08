<?php
declare(strict_types=1);

namespace Tests\Cache;

use PHPUnit\Framework\TestCase;
use NewsFlow\Cache\FileCache;

final class FileCacheTest extends TestCase
{
    private string $dir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/newsflow-test-' . uniqid();
        mkdir($this->dir, 0755, true);
        $this->cache = new FileCache($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }

    public function testSetAndGet(): void
    {
        $this->cache->set('k', ['a' => 1], 60);
        $this->assertSame(['a' => 1], $this->cache->get('k'));
    }

    public function testGetMissReturnsNull(): void
    {
        $this->assertNull($this->cache->get('nope'));
    }

    public function testRememberOnlyCallsCallbackOnce(): void
    {
        $calls = 0;
        $cb = function () use (&$calls) {
            $calls++;
            return 'v';
        };
        $this->cache->remember('k', 60, $cb);
        $this->cache->remember('k', 60, $cb);
        $this->assertSame(1, $calls);
    }

    public function testForget(): void
    {
        $this->cache->set('x', 'y', 60);
        $this->cache->forget('x');
        $this->assertNull($this->cache->get('x'));
    }

    public function testFlush(): void
    {
        $this->cache->set('a', 1, 60);
        $this->cache->set('b', 2, 60);
        $this->cache->flush();
        $this->assertNull($this->cache->get('a'));
        $this->assertNull($this->cache->get('b'));
    }
}
