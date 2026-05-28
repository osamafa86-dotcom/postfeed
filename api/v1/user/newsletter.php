<?php
/**
 * POST /api/v1/user/newsletter      — { email, action: subscribe|unsubscribe }
 *
 * The production newsletter_subscribers schema (migrate.php) uses
 * `confirmed` (not `is_confirmed`) and has NO `is_active` column.
 * Unsubscribe is a DELETE (same pattern as newsletter_unsubscribe.php).
 * The previous version queried both wrong columns, so subscribe/unsub
 * silently 500'd on every call.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
api_rate_limit('user:newsletter', 20, 600);

$body = api_body();
$email = strtolower(trim((string)($body['email'] ?? '')));
$action = (string)($body['action'] ?? 'subscribe');

// The mobile app used to hard-code 'user@feedsnews.net' as a placeholder
// in the body. If we have an authenticated user, swap to their real
// email so the subscribers list isn't filled with the placeholder.
$me = api_optional_user();
if ($me && ($email === '' || $email === 'user@feedsnews.net')) {
    $email = strtolower(trim((string)($me['email'] ?? '')));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_err('invalid_input', 'البريد غير صالح', 422);
if (!in_array($action, ['subscribe', 'unsubscribe'], true)) api_err('invalid_input', 'إجراء غير صالح', 422);

$db = getDB();

if ($action === 'unsubscribe') {
    $db->prepare("DELETE FROM newsletter_subscribers WHERE email=?")->execute([$email]);
    api_ok(['unsubscribed' => true]);
}

$confirmToken = bin2hex(random_bytes(16));
$unsubToken   = bin2hex(random_bytes(16));

// Insert if new, or refresh tokens if re-subscribing.
$db->prepare("INSERT INTO newsletter_subscribers
                (email, confirmed, confirm_token, unsubscribe_token, subscribed_at, ip_address)
              VALUES (?, 0, ?, ?, NOW(), ?)
              ON DUPLICATE KEY UPDATE
                confirm_token = VALUES(confirm_token),
                unsubscribe_token = VALUES(unsubscribe_token)")
   ->execute([$email, $confirmToken, $unsubToken, $_SERVER['REMOTE_ADDR'] ?? null]);

// Trigger confirmation email if mailer is wired.
try {
    if (function_exists('newsletter_send_confirm')) {
        newsletter_send_confirm($email, $confirmToken);
    }
} catch (Throwable $e) {
    error_log('newsletter confirm send: ' . $e->getMessage());
}

api_ok(['subscribed' => true, 'requires_confirmation' => true]);
