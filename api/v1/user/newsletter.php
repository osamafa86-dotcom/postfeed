<?php
/**
 * POST /api/v1/user/newsletter      — { email, action: subscribe|unsubscribe }
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
api_rate_limit('user:newsletter', 20, 600);

$body = api_body();
$email = strtolower(trim((string)($body['email'] ?? '')));
$action = (string)($body['action'] ?? 'subscribe');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_err('invalid_input', 'البريد غير صالح', 422);
if (!in_array($action, ['subscribe', 'unsubscribe'], true)) api_err('invalid_input', 'إجراء غير صالح', 422);

$db = getDB();
try {
    $db->exec("CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        is_confirmed TINYINT(1) NOT NULL DEFAULT 0,
        confirm_token VARCHAR(64) DEFAULT NULL,
        unsubscribe_token VARCHAR(64) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        confirmed_at TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

if ($action === 'unsubscribe') {
    $db->prepare("UPDATE newsletter_subscribers SET is_active=0 WHERE email=?")->execute([$email]);
    api_ok(['unsubscribed' => true]);
}

$confirmToken = bin2hex(random_bytes(16));
$unsubToken   = bin2hex(random_bytes(16));

$db->prepare("INSERT INTO newsletter_subscribers (email, is_confirmed, confirm_token, unsubscribe_token, is_active, created_at)
              VALUES (?,0,?,?,1,NOW())
              ON DUPLICATE KEY UPDATE is_active=1")
   ->execute([$email, $confirmToken, $unsubToken]);

// Trigger confirmation email if mailer is available.
try {
    if (function_exists('newsletter_send_confirm')) {
        newsletter_send_confirm($email, $confirmToken);
    }
} catch (Throwable $e) {}

api_ok(['subscribed' => true, 'requires_confirmation' => true]);
