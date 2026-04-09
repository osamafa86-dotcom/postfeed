<?php
/**
 * Newsletter signup endpoint.
 *
 * Accepts POST { email, _csrf } from the footer widget. Public — no
 * login required, but rate-limited per IP and gated on a CSRF token
 * grabbed from the homepage. Always returns ok=true for valid emails
 * (even if already subscribed) so we don't leak which addresses are
 * already on the list.
 *
 * On a fresh email: inserts a row, generates a confirm_token + an
 * unsubscribe_token, and emails a "click to confirm" link. Until
 * confirmed=1 the address is excluded from cron_newsletter sends.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function nl_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nl_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
if (!csrf_verify($token)) {
    nl_out(['ok' => false, 'error' => 'csrf'], 403);
}
if (!rate_limit_check('nl_sub:' . client_ip(), 5, 600)) {
    nl_out(['ok' => false, 'error' => 'rate_limited'], 429);
}

$email = trim((string)($_POST['email'] ?? ''));
$email = mb_strtolower($email);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    nl_out(['ok' => false, 'error' => 'invalid_email'], 400);
}

$db = getDB();

// Make sure the table exists even on a fresh deploy that hasn't been
// migrated yet — copying the same auto-migrate pattern cron_rss uses
// for cluster_key. Keeps the endpoint resilient to deploy ordering.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        confirmed TINYINT(1) NOT NULL DEFAULT 0,
        confirm_token VARCHAR(64) NOT NULL,
        unsubscribe_token VARCHAR(64) NOT NULL,
        subscribed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        confirmed_at TIMESTAMP NULL,
        last_sent_at TIMESTAMP NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        INDEX idx_confirmed (confirmed),
        INDEX idx_confirm_token (confirm_token),
        INDEX idx_unsubscribe_token (unsubscribe_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

try {
    $stmt = $db->prepare("SELECT id, confirmed, confirm_token FROM newsletter_subscribers WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ((int)$existing['confirmed'] === 1) {
            // Already a subscriber — silently report success.
            nl_out(['ok' => true, 'already' => true]);
        }
        // Pending — resend the confirmation using the same token.
        $confirmToken = (string)$existing['confirm_token'];
    } else {
        $confirmToken     = bin2hex(random_bytes(24));
        $unsubscribeToken = bin2hex(random_bytes(24));
        $ins = $db->prepare("INSERT INTO newsletter_subscribers (email, confirmed, confirm_token, unsubscribe_token, ip_address) VALUES (?, 0, ?, ?, ?)");
        $ins->execute([$email, $confirmToken, $unsubscribeToken, client_ip()]);
    }

    // Build confirmation link.
    $confirmUrl = SITE_URL . '/newsletter/confirm/' . $confirmToken;
    $siteName   = e(getSetting('site_name', SITE_NAME));
    $brand      = '#1a5c5c';

    $body  = '<p style="margin:0 0 16px;font-size:16px;">مرحبًا 👋</p>';
    $body .= '<p style="margin:0 0 16px;">شكرًا لاهتمامك بنشرة <strong>' . $siteName . '</strong> اليومية. اضغط على الزر التالي لتأكيد اشتراكك:</p>';
    $body .= '<p style="text-align:center;margin:28px 0;"><a href="' . e($confirmUrl) . '" style="background:' . $brand . ';color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block;">تأكيد الاشتراك</a></p>';
    $body .= '<p style="margin:0 0 8px;color:#64748b;font-size:13px;">أو انسخ هذا الرابط في متصفحك:</p>';
    $body .= '<p style="margin:0 0 16px;color:#64748b;font-size:12px;word-break:break-all;">' . e($confirmUrl) . '</p>';
    $body .= '<p style="margin:24px 0 0;color:#94a3b8;font-size:12px;">إذا لم تطلب هذا الاشتراك، تجاهل الرسالة.</p>';

    // Use a placeholder unsubscribe link in the confirm email — we
    // don't want to expose the unsubscribe token until they've actually
    // confirmed, so just point at the homepage.
    $html = newsletter_email_html('تأكيد الاشتراك في النشرة', $body, SITE_URL);
    @mailer_send($email, 'تأكيد اشتراكك في نشرة ' . getSetting('site_name', SITE_NAME), $html);

    nl_out(['ok' => true]);
} catch (Throwable $e) {
    error_log('newsletter_subscribe: ' . $e->getMessage());
    nl_out(['ok' => false, 'error' => 'server'], 500);
}
