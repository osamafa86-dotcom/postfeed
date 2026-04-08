<?php
declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use NewsFlow\Security\Totp;

final class TotpClassTest extends TestCase
{
    public function testGenerateSecretLength(): void
    {
        $this->assertSame(32, strlen(Totp::generateSecret()));
        $this->assertSame(16, strlen(Totp::generateSecret(16)));
    }

    public function testGenerateSecretIsRandom(): void
    {
        $this->assertNotSame(Totp::generateSecret(), Totp::generateSecret());
    }

    public function testRfc6238KnownVector(): void
    {
        // RFC 6238 test key "12345678901234567890" in base32
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::compute($secret, 1));
    }

    public function testVerifyAcceptsValidCode(): void
    {
        $secret = Totp::generateSecret();
        $counter = (int) floor(time() / 30);
        $code = Totp::compute($secret, $counter);
        $this->assertTrue(Totp::verify($secret, $code));
    }

    public function testVerifyRejectsInvalidCode(): void
    {
        $secret = Totp::generateSecret();
        $this->assertFalse(Totp::verify($secret, '000000'));
        $this->assertFalse(Totp::verify($secret, 'abcdef'));
        $this->assertFalse(Totp::verify($secret, '12345'));
    }

    public function testBase32DecodeKnownValue(): void
    {
        $this->assertSame("Hello!\xDE\xAD\xBE\xEF", Totp::base32Decode('JBSWY3DPEHPK3PXP'));
    }

    public function testProvisioningUri(): void
    {
        $uri = Totp::provisioningUri('ABC123', 'admin@test', 'NewsFlow');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=ABC123', $uri);
        $this->assertStringContainsString('issuer=NewsFlow', $uri);
    }
}
