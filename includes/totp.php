<?php
/**
 * TOTP (RFC 6238) - compatible with Google Authenticator / Authy / 1Password.
 * No external dependencies.
 */

/**
 * Generate a random base32 secret.
 */
function totp_generate_secret($length = 32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bytes = random_bytes($length);
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[ord($bytes[$i]) & 31];
    }
    return $out;
}

/**
 * Decode base32 string to binary.
 */
function totp_base32_decode($secret) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
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

/**
 * Compute the TOTP code for a given time counter.
 */
function totp_compute($secret, $counter, $digits = 6) {
    $key = totp_base32_decode($secret);
    if ($key === '') return str_pad('0', $digits, '0', STR_PAD_LEFT);

    // Pack counter as 64-bit big-endian
    $bin = pack('N*', 0, $counter);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $code = ((ord($hash[$offset]) & 0x7F) << 24)
          | ((ord($hash[$offset + 1]) & 0xFF) << 16)
          | ((ord($hash[$offset + 2]) & 0xFF) << 8)
          | (ord($hash[$offset + 3]) & 0xFF);
    $code = $code % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

/**
 * Verify a user-provided code against the secret (±1 step tolerance = 90s window).
 */
function totp_verify($secret, $code, $window = 1, $period = 30, $digits = 6) {
    $code = preg_replace('/\s+/', '', (string)$code);
    if (!preg_match('/^\d{' . $digits . '}$/', $code)) return false;
    $counter = (int) floor(time() / $period);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_compute($secret, $counter + $i, $digits), $code)) {
            return true;
        }
    }
    return false;
}

/**
 * Build the otpauth:// URI for QR code generation.
 */
function totp_provisioning_uri($secret, $account, $issuer) {
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);
    $params = http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30,
    ]);
    return "otpauth://totp/{$label}?{$params}";
}

/**
 * Return a URL to a Google Charts QR code for the otpauth URI.
 * (No QR library needed.)
 */
function totp_qr_image_url($uri, $size = 200) {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
         . '&data=' . urlencode($uri);
}
