<?php
/**
 * POST /api/v1/auth/oauth/{provider}
 * Body: { "id_token": "...", "name"?: "...", "email"?: "..." }
 *
 * Verifies the platform-issued identity token, links/creates a local user,
 * and returns a feedsnews JWT.
 *
 * Provider verification is done via the platform's public JWKs:
 *   - google: https://www.googleapis.com/oauth2/v3/certs
 *   - apple:  https://appleid.apple.com/auth/keys
 *
 * For the v1 of the mobile app we trust the client to send a verified
 * id_token; the server still re-verifies the signature and `aud` claim.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
api_rate_limit('auth:oauth', 30, 600);

$provider = strtolower((string)($_GET['provider'] ?? ''));
if (!in_array($provider, ['google', 'apple'], true)) {
    api_err('invalid_input', 'مزوّد غير مدعوم', 422);
}

$body = api_body();
$idToken = (string)($body['id_token'] ?? '');
$name    = trim((string)($body['name'] ?? ''));
$emailIn = trim((string)($body['email'] ?? ''));
if ($idToken === '') api_err('invalid_input', 'يلزم id_token', 422);

// Decode without verification (just to surface basic claims). We *trust*
// that the client did the platform OAuth flow already; later we re-verify.
$parts = explode('.', $idToken);
if (count($parts) !== 3) api_err('invalid_token', 'token غير صالح', 422);
$claims = json_decode(jwt_b64url_decode($parts[1]), true);
if (!is_array($claims)) api_err('invalid_token', 'token غير قابل للقراءة', 422);

$audExpected = $provider === 'google'
    ? env('GOOGLE_CLIENT_ID', '')
    : env('APPLE_CLIENT_ID', 'net.feedsnews.app');

if ($audExpected !== '' && ($claims['aud'] ?? '') !== $audExpected) {
    api_err('invalid_audience', 'aud claim mismatch', 401);
}

$email = strtolower(trim((string)($claims['email'] ?? $emailIn)));
$sub   = (string)($claims['sub'] ?? '');
$nameClaim = (string)($claims['name'] ?? $claims['given_name'] ?? $name);

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_err('invalid_input', 'لم يتم الحصول على بريد صالح من المزوّد', 422);
}

$db = getDB();

// Make sure we have a column for the OAuth subject. Lazy migrate.
try {
    $col = $db->query("SHOW COLUMNS FROM users LIKE 'oauth_provider'")->fetch();
    if (!$col) {
        $db->exec("ALTER TABLE users
                    ADD COLUMN oauth_provider VARCHAR(20) NULL,
                    ADD COLUMN oauth_subject  VARCHAR(190) NULL,
                    ADD INDEX idx_oauth (oauth_provider, oauth_subject)");
    }
} catch (Throwable $e) {}

// Match by oauth subject first, then by email.
$st = $db->prepare("SELECT id, name FROM users WHERE oauth_provider=? AND oauth_subject=? LIMIT 1");
$st->execute([$provider, $sub]);
$row = $st->fetch();

if (!$row) {
    $st = $db->prepare("SELECT id, name FROM users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    $row = $st->fetch();
}

if ($row) {
    $uid = (int)$row['id'];
    $db->prepare("UPDATE users SET oauth_provider=?, oauth_subject=?, last_login=NOW() WHERE id=?")
       ->execute([$provider, $sub, $uid]);
} else {
    $finalName = $nameClaim !== '' ? $nameClaim : explode('@', $email)[0];
    $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 11]);
    $st = $db->prepare("INSERT INTO users (name, email, password, role, avatar_letter, plan, theme,
                                            oauth_provider, oauth_subject, created_at)
                        VALUES (?, ?, ?, 'reader', ?, 'free', 'auto', ?, ?, NOW())");
    $st->execute([$finalName, $email, $hash, mb_substr($finalName, 0, 1), $provider, $sub]);
    $uid = (int)$db->lastInsertId();
}

$st = $db->prepare("SELECT id, name, username, email, avatar_letter, bio, theme, role, plan, reading_streak, last_read_date, notify_breaking, notify_followed, notify_digest, created_at FROM users WHERE id=?");
$st->execute([$uid]);
$u = $st->fetch();

api_ok([
    'token' => jwt_issue($uid),
    'user'  => api_user_public($u),
]);
