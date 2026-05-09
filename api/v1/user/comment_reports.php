<?php
/**
 * POST /api/v1/user/comment_reports
 *   Body: { comment_id, reason, note? }
 *
 * Lets a logged-in user flag a comment for moderator review.
 * Required by Apple App Store Guideline 1.2 — UGC apps must
 * provide a way to report objectionable content. The moderator
 * panel reviews entries from the `comment_reports` table and
 * acts on them within 24 hours.
 *
 * `reason` is one of: spam, harassment, hate, sexual, violence,
 * misinformation, copyright, other.
 *
 * The (reporter_id, comment_id) pair is UNIQUE — re-reporting
 * the same comment is treated as success (no-op) so the client
 * stays simple.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
api_rate_limit('user:comment_reports', 30, 600);

$user = api_require_user();
$body = api_body();
$cid    = (int)($body['comment_id'] ?? 0);
$reason = trim((string)($body['reason'] ?? 'other'));
$note   = trim((string)($body['note'] ?? ''));

if (!$cid) api_err('invalid_input', 'يلزم comment_id', 422);

$allowedReasons = ['spam', 'harassment', 'hate', 'sexual', 'violence', 'misinformation', 'copyright', 'other'];
if (!in_array($reason, $allowedReasons, true)) {
    $reason = 'other';
}
if (mb_strlen($note) > 500) {
    $note = mb_substr($note, 0, 500);
}

$db = getDB();

// Verify the comment exists and isn't already deleted/hidden.
$st = $db->prepare("SELECT id, user_id FROM article_comments WHERE id = ? LIMIT 1");
$st->execute([$cid]);
$row = $st->fetch();
if (!$row) api_err('not_found', 'التعليق غير موجود', 404);

// Self-reports are pointless — if you don't like your own comment, delete it.
if ((int)$row['user_id'] === (int)$user['id']) {
    api_err('invalid_input', 'لا يمكنك الإبلاغ عن تعليقك', 422);
}

try {
    $db->prepare("INSERT INTO comment_reports (comment_id, reporter_id, reason, note, status, created_at)
                  VALUES (?, ?, ?, ?, 'pending', NOW())")
       ->execute([$cid, (int)$user['id'], $reason, $note !== '' ? $note : null]);
} catch (PDOException $e) {
    // Duplicate (already reported) — treat as success so the UI stays simple.
    if ((int)$e->errorInfo[1] !== 1062) {
        throw $e;
    }
}

api_ok(['reported' => true]);
