<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/rate_limit.php';

class RateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        foreach (glob(__DIR__ . '/../storage/ratelimit/*.rl') as $f) @unlink($f);
    }

    public function testAllowsUpToLimit(): void
    {
        $key = 'test:' . uniqid();
        for ($i = 1; $i <= 5; $i++) {
            $this->assertTrue(rate_limit_check($key, 5, 60), "Request $i should be allowed");
        }
    }

    public function testBlocksAfterLimit(): void
    {
        $key = 'test:' . uniqid();
        for ($i = 1; $i <= 3; $i++) {
            rate_limit_check($key, 3, 60);
        }
        $this->assertFalse(rate_limit_check($key, 3, 60));
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $key1 = 'test:' . uniqid();
        $key2 = 'test:' . uniqid();
        rate_limit_check($key1, 1, 60);
        $this->assertFalse(rate_limit_check($key1, 1, 60));
        $this->assertTrue(rate_limit_check($key2, 1, 60));
    }

    public function testClientIpReturnsValidValue(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
        $this->assertSame('203.0.113.42', client_ip());

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.1';
        $this->assertSame('198.51.100.7', client_ip());

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function testClientIpFallsBackForInvalidInput(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        $this->assertSame('192.0.2.1', client_ip());
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }
}
