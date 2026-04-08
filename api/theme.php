<?php
require __DIR__ . '/_json.php';

require_post_json();
require_csrf_json();
rate_limit_json('theme', 60, 60);

$theme = $_POST['theme'] ?? 'auto';
if (!in_array($theme, ['light', 'dark', 'auto'], true)) json_out(['ok' => false, 'error' => 'bad_theme'], 400);

// Cookie fallback for anonymous visitors
setcookie('nf_theme', $theme, [
    'expires'  => time() + 86400 * 365,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => false,
    'samesite' => 'Lax',
]);

$uid = current_user_id();
if ($uid) {
    try {
        $db = getDB();
        $db->prepare("UPDATE users SET theme = ? WHERE id = ?")->execute([$theme, $uid]);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'server'], 500);
    }
}

json_out(['ok' => true, 'theme' => $theme]);
