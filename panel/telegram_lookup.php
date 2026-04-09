<?php
/**
 * Telegram channel lookup endpoint — verifies a channel username exists
 * by scraping t.me/s/{username} and returns its public metadata.
 *
 * Usage: GET telegram_lookup.php?q=<url-or-username>
 * Response JSON:
 *   { ok: true, username, display_name, avatar_url, subscribers, description }
 *   { ok: false, error: "..." }
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['ok' => false, 'error' => 'اكتب اسم القناة أو رابطها']);
    exit;
}

/**
 * Normalize anything the user might type into a clean public channel username.
 * Accepts: @name, name, t.me/name, https://t.me/name, https://telegram.me/name,
 *          https://t.me/s/name, t.me/+invite (rejected, private)
 */
function tg_normalize_username(string $raw): ?string {
    $raw = trim($raw);
    $raw = preg_replace('~^https?://~i', '', $raw);
    $raw = preg_replace('~^(www\.)?(t|telegram)\.me/~i', '', $raw);
    $raw = preg_replace('~^s/~i', '', $raw);
    $raw = ltrim($raw ?? '', '@');
    // Strip trailing path/query/hash
    $raw = preg_replace('~[/?#].*$~', '', $raw) ?? '';
    // Reject private invite links (start with +) and joinchat
    if ($raw === '' || $raw[0] === '+' || strcasecmp($raw, 'joinchat') === 0) return null;
    // Telegram usernames: 5..32 chars, alnum + underscore, must start with a letter
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{4,31}$/', $raw)) return null;
    return strtolower($raw);
}

$username = tg_normalize_username($q);
if (!$username) {
    echo json_encode(['ok' => false, 'error' => 'اسم المستخدم غير صالح. يجب أن تكون قناة عامة.']);
    exit;
}

$url = 'https://t.me/s/' . urlencode($username);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NewsFlowBot/1.0)',
    CURLOPT_SSL_VERIFYPEER => false,
]);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$html || $httpCode >= 400) {
    echo json_encode(['ok' => false, 'error' => 'تعذر الاتصال بـ Telegram. حاول لاحقاً.']);
    exit;
}

// Telegram returns 200 even for non-existent channels; detect by content.
// A real public channel has `tgme_channel_info` or `tgme_page_title`.
$exists = (strpos($html, 'tgme_channel_info') !== false)
       || (strpos($html, 'tgme_widget_message') !== false);
if (!$exists) {
    echo json_encode(['ok' => false, 'error' => 'لم يتم العثور على قناة بهذا الاسم، أو أنها غير عامة.']);
    exit;
}

// Extract display name (prefer og:title, fallback to tgme_channel_info .tgme_channel_info_header_title)
$displayName = $username;
if (preg_match('#<meta property="og:title" content="([^"]+)"#', $html, $m)) {
    $displayName = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
} elseif (preg_match('#tgme_channel_info_header_title[^>]*>\s*<span[^>]*>([^<]+)</span>#', $html, $m)) {
    $displayName = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Extract avatar URL (og:image first, fallback to first tgme_page_photo_image)
$avatar = '';
if (preg_match('#<meta property="og:image" content="([^"]+)"#', $html, $m)) {
    $avatar = $m[1];
} elseif (preg_match('#tgme_page_photo_image"[^>]*src="([^"]+)"#', $html, $m)) {
    $avatar = $m[1];
}

// Extract description
$description = '';
if (preg_match('#<meta property="og:description" content="([^"]+)"#', $html, $m)) {
    $description = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Extract subscriber count (from channel header counters)
$subscribers = '';
if (preg_match_all('#tgme_channel_info_counter"[^>]*>\s*<span class="counter_value"[^>]*>([^<]+)</span>\s*<span class="counter_type"[^>]*>([^<]+)</span>#', $html, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $row) {
        if (stripos($row[2], 'subscriber') !== false || stripos($row[2], 'member') !== false) {
            $subscribers = trim($row[1]);
            break;
        }
    }
}

echo json_encode([
    'ok'           => true,
    'username'     => $username,
    'display_name' => $displayName,
    'avatar_url'   => $avatar,
    'description'  => $description,
    'subscribers'  => $subscribers,
    'channel_url'  => 'https://t.me/' . $username,
], JSON_UNESCAPED_UNICODE);
