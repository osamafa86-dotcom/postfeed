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
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

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

/**
 * Fetch a t.me URL with a browser-ish user agent. Returns [html, httpCode].
 */
function tg_lookup_fetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$html ?: '', $code];
}

// Try the public message-stream page first — it's what our existing scraper uses.
[$html, $httpCode] = tg_lookup_fetch('https://t.me/s/' . urlencode($username));

// Fallback: canonical channel page (no /s/). This works for channels without
// visible messages on the preview page.
if ($httpCode >= 400 || $html === '' || (strpos($html, 'og:title') === false && strpos($html, 'tgme_channel_info') === false)) {
    [$html, $httpCode] = tg_lookup_fetch('https://t.me/' . urlencode($username));
}

if ($httpCode >= 500 || $html === '') {
    echo json_encode(['ok' => false, 'error' => 'تعذر الاتصال بـ Telegram. حاول لاحقاً.']);
    exit;
}

// Pull OG meta tags first — this is the most reliable detection across both page variants.
$ogTitle = '';
if (preg_match('~<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) {
    $ogTitle = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
$ogImage = '';
if (preg_match('~<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) {
    $ogImage = $m[1];
}
$ogDescription = '';
if (preg_match('~<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) {
    $ogDescription = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// A non-existent or private channel typically has og:title = "Telegram: Contact @..."
// or an empty/missing og:title, or no og:image (default Telegram logo).
$titleIsGeneric = ($ogTitle === '' || stripos($ogTitle, 'Telegram: Contact') !== false);
$hasChannelMarkup = (strpos($html, 'tgme_channel_info') !== false)
                 || (strpos($html, 'tgme_widget_message') !== false)
                 || (strpos($html, 'tgme_page_title') !== false);

if ($titleIsGeneric && !$hasChannelMarkup) {
    echo json_encode(['ok' => false, 'error' => 'لم يتم العثور على قناة بهذا الاسم، أو أنها غير عامة.']);
    exit;
}

// Display name: prefer og:title, fall back to the page title or the username.
$displayName = $ogTitle !== '' ? $ogTitle : $username;
// Some og:titles come through as "Channel Name" — good. Others as "Telegram: Contact @name" — skip that.
if (stripos($displayName, 'Telegram: Contact') !== false) {
    if (preg_match('#tgme_page_title[^>]*>\s*<span[^>]*>([^<]+)</span>#', $html, $m)) {
        $displayName = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    } elseif (preg_match('#tgme_channel_info_header_title[^>]*>\s*<span[^>]*>([^<]+)</span>#', $html, $m)) {
        $displayName = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    } else {
        $displayName = $username;
    }
}

// Avatar
$avatar = $ogImage;
if ($avatar === '' && preg_match('#tgme_page_photo_image[^>]*src="([^"]+)"#', $html, $m)) {
    $avatar = $m[1];
}

$description = $ogDescription;

// Subscriber count — parse from channel info counters if present
$subscribers = '';
if (preg_match_all('#tgme_channel_info_counter"[^>]*>\s*<span class="counter_value"[^>]*>([^<]+)</span>\s*<span class="counter_type"[^>]*>([^<]+)</span>#', $html, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $row) {
        if (stripos($row[2], 'subscriber') !== false || stripos($row[2], 'member') !== false) {
            $subscribers = trim($row[1]);
            break;
        }
    }
}
// Fallback: the non-/s/ page uses .tgme_page_extra for subscriber count
if ($subscribers === '' && preg_match('#tgme_page_extra[^>]*>([^<]+)</div>#', $html, $m)) {
    $subscribers = trim($m[1]);
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
