<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/totp.php';

class TotpTest extends TestCase
{
    public function testGenerateSecretHasCorrectLength(): void
    {
        $secret = totp_generate_secret(32);
        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testGenerateSecretIsRandom(): void
    {
        $a = totp_generate_secret();
        $b = totp_generate_secret();
        $this->assertNotSame($a, $b);
    }

    public function testVerifyAcceptsCurrentCode(): void
    {
        $secret = totp_generate_secret();
        $counter = (int) floor(time() / 30);
        $code = totp_compute($secret, $counter);
        $this->assertTrue(totp_verify($secret, $code));
    }

    public function testVerifyRejectsWrongCode(): void
    {
        $secret = totp_generate_secret();
        $this->assertFalse(totp_verify($secret, '000000'));
    }

    public function testVerifyRejectsMalformedCode(): void
    {
        $secret = totp_generate_secret();
        $this->assertFalse(totp_verify($secret, 'abc123'));
        $this->assertFalse(totp_verify($secret, '12345'));
        $this->assertFalse(totp_verify($secret, ''));
    }

    public function testBase32DecodeKnownValue(): void
    {
        // RFC 4648 test: "JBSWY3DPEHPK3PXP" decodes to "Hello!\xDE\xAD\xBE\xEF"
        $decoded = totp_base32_decode('JBSWY3DPEHPK3PXP');
        $this->assertSame("Hello!\xDE\xAD\xBE\xEF", $decoded);
    }

    public function testTotpRfc6238KnownVector(): void
    {
        // RFC 6238 Appendix B test vectors use key "12345678901234567890"
        // which is base32 "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        // T = 59 → counter = 1
        $this->assertSame('287082', totp_compute($secret, 1));
    }

    public function testProvisioningUriContainsFields(): void
    {
        $uri = totp_provisioning_uri('ABC123', 'admin@test', 'NewsFlow');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=ABC123', $uri);
        $this->assertStringContainsString('issuer=NewsFlow', $uri);
    }
}
