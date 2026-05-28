<?php
/**
 * Apple / Google id_token signature verification.
 *
 * Both providers publish RSA public keys at a JWKS URL. To verify an
 * id_token we:
 *   1. Decode the JWT header to learn the `kid` (key id).
 *   2. Pull the JWKS (cached for ~24h so we don't hammer it).
 *   3. Build an OpenSSL public key from the matching JWK.
 *   4. Verify the RS256 signature over `header.payload`.
 *   5. Validate the `iss`, `aud`, `exp` claims.
 *
 * Anything off → caller treats the token as invalid.
 */

const OAUTH_JWKS_GOOGLE = 'https://www.googleapis.com/oauth2/v3/certs';
const OAUTH_JWKS_APPLE  = 'https://appleid.apple.com/auth/keys';
const OAUTH_ISS_GOOGLE  = ['https://accounts.google.com', 'accounts.google.com'];
const OAUTH_ISS_APPLE   = 'https://appleid.apple.com';

/**
 * Verify an id_token from Apple or Google. Returns the validated
 * claims on success, or null on any failure (signature, iss, aud, exp).
 *
 * @param string $idToken JWT from Sign in with Apple / Google Sign-In
 * @param string $provider 'apple' or 'google'
 * @param string $expectedAud bundle id (apple) or OAuth client id (google)
 */
function oauth_verify_id_token(string $idToken, string $provider, string $expectedAud): ?array {
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) return null;

    [$h64, $p64, $s64] = $parts;
    $header = json_decode(jwt_b64url_decode($h64), true);
    $claims = json_decode(jwt_b64url_decode($p64), true);
    $sig    = jwt_b64url_decode($s64);

    if (!is_array($header) || !is_array($claims) || $sig === '') return null;
    $alg = (string)($header['alg'] ?? '');
    $kid = (string)($header['kid'] ?? '');
    if ($alg !== 'RS256' || $kid === '') return null;

    // ── exp ──
    $now = time();
    $exp = (int)($claims['exp'] ?? 0);
    if ($exp < $now) return null;

    // ── iss ──
    $iss = (string)($claims['iss'] ?? '');
    $validIss = $provider === 'google'
        ? in_array($iss, OAUTH_ISS_GOOGLE, true)
        : $iss === OAUTH_ISS_APPLE;
    if (!$validIss) return null;

    // ── aud ──
    if ($expectedAud === '' || ($claims['aud'] ?? '') !== $expectedAud) return null;

    // ── signature ──
    $jwks = oauth_fetch_jwks($provider);
    if (!$jwks) return null;

    $jwk = null;
    foreach ($jwks as $k) {
        if (($k['kid'] ?? '') === $kid) { $jwk = $k; break; }
    }
    if (!$jwk) return null;

    $pem = oauth_jwk_to_pem($jwk);
    if ($pem === null) return null;

    $ok = openssl_verify($h64 . '.' . $p64, $sig, $pem, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) return null;

    return $claims;
}

function oauth_fetch_jwks(string $provider): ?array {
    $url = $provider === 'google' ? OAUTH_JWKS_GOOGLE : OAUTH_JWKS_APPLE;
    $cacheKey = 'oauth_jwks_' . $provider;

    if (function_exists('cache_get')) {
        $cached = cache_get($cacheKey);
        if (is_array($cached)) return $cached;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !$body) return null;

    $data = json_decode((string)$body, true);
    $keys = $data['keys'] ?? null;
    if (!is_array($keys)) return null;

    if (function_exists('cache_set')) {
        cache_set($cacheKey, $keys, 86400); // 24h
    }
    return $keys;
}

/**
 * Build a PEM-encoded RSA public key from a JWK with `n` and `e`
 * base64url-encoded fields. Pure PHP / OpenSSL, no third-party libs.
 */
function oauth_jwk_to_pem(array $jwk): ?string {
    $n = jwt_b64url_decode((string)($jwk['n'] ?? ''));
    $e = jwt_b64url_decode((string)($jwk['e'] ?? ''));
    if ($n === '' || $e === '') return null;

    // Build the DER for RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
    $modulusInt  = oauth_der_int($n);
    $exponentInt = oauth_der_int($e);
    $seq         = oauth_der_seq($modulusInt . $exponentInt);

    // Wrap in SubjectPublicKeyInfo: AlgorithmIdentifier(rsaEncryption) + BIT STRING(modulus|exponent)
    $algId  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $bitStr = oauth_der_bitstring($seq);
    $spki   = oauth_der_seq($algId . $bitStr);

    $pem = "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($spki), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
    return $pem;
}

function oauth_der_len(int $len): string {
    if ($len < 0x80) return chr($len);
    $bytes = '';
    while ($len > 0) { $bytes = chr($len & 0xff) . $bytes; $len >>= 8; }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function oauth_der_int(string $bin): string {
    // Prepend 0x00 if the high bit is set (DER INTEGER is signed).
    if (strlen($bin) > 0 && (ord($bin[0]) & 0x80) !== 0) $bin = "\x00" . $bin;
    return "\x02" . oauth_der_len(strlen($bin)) . $bin;
}

function oauth_der_seq(string $bin): string {
    return "\x30" . oauth_der_len(strlen($bin)) . $bin;
}

function oauth_der_bitstring(string $bin): string {
    // 0x03 BIT STRING, 1 unused-bits byte (0), then content.
    return "\x03" . oauth_der_len(strlen($bin) + 1) . "\x00" . $bin;
}
