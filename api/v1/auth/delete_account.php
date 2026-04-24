<?php
/**
 * POST /api/v1/auth/delete_account — REQUIRED by App Store guideline 5.1.1(v).
 * Erases the user's account and personal data. Articles/sources are shared
 * content and remain; per-user rows (bookmarks, follows, history, comments,
 * notifications, tokens) are removed.
 *
 * Body: { "password": "..." } — re-auth to prevent accidental deletion.
 */

require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
$uid = api_require_user();
api_rate_limit('auth.delete', 3, 3600);

$body = api_body();
$password = (string)($body['password'] ?? '');
if ($password === '') api_error('password_required', 'كلمة المرور مطلوبة لحذف الحساب', 400);

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password'])) {
        api_error('invalid_credentials', 'كلمة المرور غير صحيحة', 401);
    }

    $tables = [
        'user_bookmarks', 'user_category_follows', 'user_source_follows',
        'user_notifications', 'article_comments', 'article_reactions',
        'article_reading_history', 'device_tokens', 'api_tokens',
    ];
    foreach ($tables as $t) {
        try { $db->prepare("DELETE FROM `$t` WHERE user_id = ?")->execute([$uid]); } catch (Throwable $e) {}
    }
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);

    api_json(['ok' => true, 'deleted' => true]);
} catch (Throwable $e) {
    error_log('v1/auth/delete: ' . $e->getMessage());
    api_error('server_error', 'فشل حذف الحساب', 500);
}
