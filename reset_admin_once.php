<?php
/**
 * One-time admin password reset — DELETE THIS FILE IMMEDIATELY AFTER USE.
 *
 * Rotates admin@admin.com to a fresh bcrypt hash, wipes any 2FA config
 * so the admin can log in even if they lost their authenticator, and
 * stamps /storage/reset_admin_done.flag so the script refuses to run a
 * second time. The token in the URL is a shared secret printed in the
 * deploy conversation — without it, every request returns 403.
 */

require_once __DIR__ . '/includes/config.php';

$TOKEN = 'f1d513860dd96fb31ff7b96e5ec8e4e7';
$EMAIL = 'admin@admin.com';
// bcrypt hash of the agreed-upon password. Kept as a constant so the
// plaintext never touches this repo.
$HASH  = '$2y$12$OSM6RVHRZ0FdWzl.5xeZb.3xnSmTMJXXZa3.w0DO6d9AglwsYgETK';

header('Content-Type: text/html; charset=utf-8');

$given = (string)($_GET['token'] ?? '');
if (!hash_equals($TOKEN, $given)) {
    http_response_code(403);
    echo '<h2>403 Forbidden</h2><p>Missing or wrong token.</p>';
    exit;
}

$flag = __DIR__ . '/storage/reset_admin_done.flag';
if (is_file($flag)) {
    http_response_code(410);
    echo '<h2>410 Gone</h2><p>This reset link was already used. Delete <code>reset_admin_once.php</code> now.</p>';
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users
                             SET password = ?,
                                 totp_enabled = 0,
                                 totp_secret = NULL,
                                 is_active = 1
                           WHERE email = ?");
    $stmt->execute([$HASH, $EMAIL]);
    $rows = $stmt->rowCount();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h2>500</h2><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

if ($rows === 0) {
    echo '<h2>No matching user</h2>';
    echo '<p>No row found for <code>' . htmlspecialchars($EMAIL) . '</code>.</p>';
    $list = $db->query("SELECT id, email, role FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
    echo '<p>Existing admin accounts:</p><ul>';
    foreach ($list as $u) {
        echo '<li>#' . (int)$u['id'] . ' — ' . htmlspecialchars((string)$u['email']) . ' (' . htmlspecialchars((string)$u['role']) . ')</li>';
    }
    echo '</ul>';
    echo '<p>Delete <code>reset_admin_once.php</code> and tell the operator which email to use.</p>';
    exit;
}

@mkdir(__DIR__ . '/storage', 0755, true);
@file_put_contents($flag, date('c'));

echo '<h2>✅ Password reset</h2>';
echo '<p>Email: <code>' . htmlspecialchars($EMAIL) . '</code></p>';
echo '<p>You can now sign in at <a href="/panel/login.php">/panel/login.php</a>.</p>';
echo '<p style="color:#b91c1c"><strong>⚠️ Delete this file now: <code>reset_admin_once.php</code></strong></p>';
