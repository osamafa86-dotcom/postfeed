<?php
/**
 * DELETE /api/v1/auth/account
 * ===========================
 * حذف حساب المستخدم نهائياً مع جميع بياناته.
 * مطلوب من Apple App Store Guidelines منذ يونيو 2022.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('DELETE');

$user = api_require_user();
$userId = (int) $user['id'];

$db = getDB();

try {
    $db->beginTransaction();

    // حذف التعليقات
    $st = $db->prepare("DELETE FROM article_comments WHERE user_id = ?");
    $st->execute([$userId]);

    // حذف الإشارات المرجعية
    $st = $db->prepare("DELETE FROM bookmarks WHERE user_id = ?");
    $st->execute([$userId]);

    // حذف سجل القراءة
    $st = $db->prepare("DELETE FROM read_history WHERE user_id = ?");
    $st->execute([$userId]);

    // حذف متابعات الأقسام
    $st = $db->prepare("DELETE FROM user_followed_categories WHERE user_id = ?");
    $st->execute([$userId]);

    // حذف تفضيلات الإشعارات
    $st = $db->prepare("DELETE FROM user_notifications WHERE user_id = ?");
    $st->execute([$userId]);

    // حذف FCM tokens
    $st = $db->prepare("DELETE FROM push_tokens WHERE user_id = ?");
    $st->execute([$userId]);

    // حذف اشتراك النشرة البريدية
    $st = $db->prepare("DELETE FROM newsletter_subscribers WHERE user_id = ?");
    $st->execute([$userId]);

    // حذف الحساب نفسه
    $st = $db->prepare("DELETE FROM users WHERE id = ?");
    $st->execute([$userId]);

    $db->commit();

    api_ok(['deleted' => true]);
} catch (Throwable $e) {
    $db->rollBack();
    error_log("Account deletion failed for user $userId: " . $e->getMessage());
    api_err('delete_failed', 'تعذّر حذف الحساب، حاول مرة أخرى', 500);
}
