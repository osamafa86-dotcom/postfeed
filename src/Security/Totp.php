<?php
declare(strict_types=1);

namespace NewsFlow\Security;

/**
 * RFC 6238 TOTP — compatible with Google Authenticator / Authy / 1Password.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    public static function generateSecret(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bytes = random_bytes($length);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[ord($bytes[$i]) & 31];
        }
        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        if ($secret === '') return '';
        $binary = '';
        $buffer = 0;
        $bitsLeft = 0;
        for ($i = 0, $n = strlen($secret); $i < $n; $i++) {
            $val = strpos($alphabet, $secret[$i]);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $binary;
    }

    public static function compute(string $secret, int $counter, int $digits = self::DIGITS): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') return str_pad('0', $digits, '0', STR_PAD_LEFT);

        $bin = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = ((ord($hash[$offset]) & 0x7F) << 24)
              | ((ord($hash[$offset + 1]) & 0xFF) << 16)
              | ((ord($hash[$offset + 2]) & 0xFF) << 8)
              | (ord($hash[$offset + 3]) & 0xFF);
        $code = $code % (10 ** $digits);
        return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
    }

    public static function verify(
        string $secret,
        string $code,
        int $window = 1,
        int $period = self::PERIOD,
        int $digits = self::DIGITS
    ): bool {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . $digits . '}$/', $code)) return false;
        $counter = (int) floor(time() / $period);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::compute($secret, $counter + $i, $digits), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    public static function qrImageUrl(string $uri, int $size = 200): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
             . '&data=' . urlencode($uri);
    }
}
