<?php
/**
 * DELETE /api/v1/auth/account
 * ===========================
 * Permanently deletes the authenticated user and every row that
 * references them. Required by Apple App Store Guideline 5.1.1(v).
 *
 * Implementation notes:
 *   - Each cleanup statement is wrapped in its own try/catch so a
 *     missing table (e.g. push_tokens on installs that never created
 *     it) doesn't roll back the rest of the work.
 *   - The deletion of the `users` row itself is the only required
 *     step — without it, the account technically still exists, so it
 *     runs OUTSIDE the per-table try/catch and a failure surfaces to
 *     the caller as 500.
 *   - Table names match the schema in includes/user_migrate.php.
 *     Earlier versions of this file used wrong names (bookmarks,
 *     read_history, user_followed_categories, push_tokens) which
 *     caused every account deletion to fail.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('DELETE');

$user   = api_require_user();
$userId = (int) $user['id'];

$db = getDB();

// Helper: try a DELETE, swallow missing-table / missing-column errors.
$tryDelete = function (PDO $db, string $sql, array $params): void {
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
    } catch (Throwable $e) {
        // Schema drift across deployments is expected — log and continue.
        error_log('account delete cleanup: ' . $e->getMessage());
    }
};

// ── User content the app stores ────────────────────────────────
$tryDelete($db, "DELETE FROM article_comments       WHERE user_id = ?", [$userId]);
$tryDelete($db, "DELETE FROM comment_likes          WHERE user_id = ?", [$userId]);
$tryDelete($db, "DELETE FROM article_reactions      WHERE user_id = ?", [$userId]);
$tryDelete($db, "DELETE FROM user_bookmarks         WHERE user_id = ?", [$userId]);
$tryDelete($db, "DELETE FROM user_reading_history   WHERE user_id = ?", [$userId]);
$tryDelete($db, "DELETE FROM user_category_follows  WHERE user_id = ?", [$userId]);
$tryDelete($db, "DELETE FROM user_source_follows    WHERE user_id = ?", [$userId]);
$tryDelete($db, "DELETE FROM user_notifications     WHERE user_id = ?", [$userId]);

// ── Comment moderation tables (App Store Guideline 1.2) ────────
// Drop both sides of the block list and any reports the user filed.
$tryDelete($db, "DELETE FROM user_blocks            WHERE blocker_id = ? OR blocked_id = ?", [$userId, $userId]);
$tryDelete($db, "DELETE FROM comment_reports        WHERE reporter_id = ?", [$userId]);

// ── Device tokens for push notifications ───────────────────────
// Tied to user_id; not removing them means push to a re-issued user_id
// could still reach the old device. Both names exist across deploys.
$tryDelete($db, "DELETE FROM user_devices           WHERE user_id = ?", [$userId]);
$tryDelete($db, "DELETE FROM push_tokens            WHERE user_id = ?", [$userId]);

// ── Optional tables that older installs may not have ───────────
$tryDelete($db, "DELETE FROM newsletter_subscribers WHERE user_id = ?", [$userId]);
// password_resets is lazy-created on first /auth/forgot, no FK to
// users — clean it up explicitly so 5.1.1(v) holds (no residue).
$tryDelete($db, "DELETE FROM password_resets       WHERE user_id = ?", [$userId]);

// ── The account itself — this MUST succeed ─────────────────────
try {
    $st = $db->prepare("DELETE FROM users WHERE id = ?");
    $st->execute([$userId]);
    if ($st->rowCount() === 0) {
        api_err('not_found', 'الحساب غير موجود', 404);
    }
} catch (Throwable $e) {
    error_log("Account deletion failed for user $userId: " . $e->getMessage());
    api_err('delete_failed', 'تعذّر حذف الحساب، حاول مرة أخرى', 500);
}

api_ok(['deleted' => true]);
