<?php
/**
 * Telegram channel lookup endpoint — verifies a channel username exists
 * by scraping t.me/s/{username} and returns its public metadata.
 *
 * Usage: GET telegram_lookup.php?q=<url-or-username>[&debug=1]
 * Response JSON:
 *   { ok: true, username, display_name, avatar_url, subscribers, description }
 *   { ok: false, error: "..." }
 *
 * Defensive design: always returns JSON. Suppresses PHP errors so they
 * don't corrupt the response body, wraps everything in try/catch, and does
 * its own admin check (to avoid requireAdmin()'s HTML redirect).
 */

// 1) Lock output to JSON BEFORE anything that could emit a warning/notice.
ini_set('display_errors', '0');
error_reporting(0);
while (ob_get_level() > 0) { ob_end_clean(); }
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

/** Flush the output buffer and emit JSON, always. */
function tg_json_exit(array $payload): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Catch fatal errors so the client still gets JSON
register_shutdown_function(function(){
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Server error: ' . $err['message']], JSON_UNESCAPED_UNICODE);
    }
});

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/functions.php';
} catch (Throwable $e) {
    tg_json_exit(['ok' => false, 'error' => 'Init error: ' . $e->getMessage()]);
}

// 2) Manual role check — do NOT call requireRole() because it redirects.
//    Editors and admins can use the lookup; viewers cannot.
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if (!hasRole('editor')) {
    tg_json_exit(['ok' => false, 'error' => 'انتهت الجلسة. أعد تسجيل الدخول من لوحة التحكم.']);
}

$debug = !empty($_GET['debug']);
$q = trim($_GET['q'] ?? '');
if ($q === '') {
    tg_json_exit(['ok' => false, 'error' => 'اكتب اسم القناة أو رابطها']);
}

/**
 * Normalize anything the user might type into a clean public channel username.
 * Accepts: @name, name, t.me/name, https://t.me/name, https://telegram.me/name,
 *          https://t.me/s/name, t.me/+invite (rejected, private)
 */
function tg_normalize_username(string $raw): ?string {
    $raw = trim($raw);
    $raw = (string)preg_replace('~^https?://~i', '', $raw);
    $raw = (string)preg_replace('~^(www\.)?(t|telegram)\.me/~i', '', $raw);
    $raw = (string)preg_replace('~^s/~i', '', $raw);
    $raw = ltrim($raw, '@');
    // Strip trailing path/query/hash
    $raw = (string)preg_replace('~[/?#].*$~', '', $raw);
    // Reject private invite links (start with +) and joinchat
    if ($raw === '' || $raw[0] === '+' || strcasecmp($raw, 'joinchat') === 0) return null;
    // Telegram usernames: 5..32 chars, alnum + underscore, must start with a letter
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{4,31}$/', $raw)) return null;
    return strtolower($raw);
}

$username = tg_normalize_username($q);
if (!$username) {
    tg_json_exit(['ok' => false, 'error' => 'اسم المستخدم غير صالح. يجب أن تكون قناة عامة.']);
}

/**
 * Fetch a t.me URL. Returns [html, httpCode, curlError].
 * Uses the SAME User-Agent as the existing working tg_fetch_channel()
 * scraper in includes/telegram_fetch.php so we know Telegram doesn't
 * block us (it's already serving this UA on every cron run).
 */
function tg_lookup_fetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NewsFlowBot/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $html    = curl_exec($ch);
    $code    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    return [is_string($html) ? $html : '', $code, $curlErr];
}

try {
    // Fetch the canonical channel page FIRST — it has reliable info markers
    // (tgme_page_photo, tgme_channel_info_header_title) for every real
    // public channel, even ones without visible messages.
    [$html, $httpCode, $curlErr] = tg_lookup_fetch('https://t.me/' . urlencode($username));
    $fetchedUrl = 'https://t.me/' . $username;

    // Fallback to the /s/ preview page if the canonical page failed.
    if ($httpCode >= 400 || $html === '' || strlen($html) < 500) {
        [$html2, $httpCode2, $curlErr2] = tg_lookup_fetch('https://t.me/s/' . urlencode($username));
        if ($httpCode2 === 200 && is_string($html2) && strlen($html2) > 500) {
            $html = $html2; $httpCode = $httpCode2; $curlErr = $curlErr2;
            $fetchedUrl = 'https://t.me/s/' . $username;
        }
    }

    if ($debug) {
        tg_json_exit([
            'ok'         => true,
            'debug'      => true,
            'username'   => $username,
            'fetched'    => $fetchedUrl,
            'http_code'  => $httpCode,
            'curl_error' => $curlErr,
            'html_size'  => strlen($html),
            'html_head'  => mb_substr($html, 0, 1200),
            'has_og'     => strpos($html, 'og:title') !== false,
            'has_info'   => strpos($html, 'tgme_channel_info') !== false,
            'has_widget' => strpos($html, 'tgme_widget_message') !== false,
            'has_title'  => strpos($html, 'tgme_page_title') !== false,
        ]);
    }

    if ($curlErr !== '' && $html === '') {
        tg_json_exit(['ok' => false, 'error' => 'تعذر الاتصال بـ Telegram: ' . $curlErr]);
    }
    if ($httpCode >= 500 || $html === '') {
        tg_json_exit(['ok' => false, 'error' => 'تعذر الاتصال بـ Telegram (HTTP ' . $httpCode . '). حاول لاحقاً.']);
    }

    // Pull OG meta tags — most reliable detection across both page variants.
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

    // Accept the channel if ANY reliable marker of a real public channel is
    // present. Non-existent usernames on Telegram render a stub page with only
    // the generic "Telegram: Contact" og:title and the default logo image —
    // they never include tgme_page_photo, tgme_channel_info, or a
    // cdn-telegram.org avatar.
    $channelExists = false;
    $markers = [
        'tgme_page_photo',              // channel avatar block
        'tgme_channel_info',            // canonical page info block
        'tgme_channel_info_header',     // ditto
        'tgme_widget_message_wrap',     // preview messages
        'tgme_page_title',              // canonical title
    ];
    foreach ($markers as $mrk) {
        if (strpos($html, $mrk) !== false) { $channelExists = true; break; }
    }
    // A cdn-telegram.org og:image means Telegram is serving a real avatar.
    if (!$channelExists && $ogImage !== '' && stripos($ogImage, 'cdn-telegram.org') !== false) {
        $channelExists = true;
    }
    // A specific (non-generic) og:title also counts.
    if (!$channelExists && $ogTitle !== '' && stripos($ogTitle, 'Telegram: Contact') === false) {
        $channelExists = true;
    }

    if (!$channelExists) {
        tg_json_exit([
            'ok'    => false,
            'error' => 'لم يتم العثور على قناة @' . $username . '. تأكد من اليوزرنيم، أو افتح ' . $fetchedUrl . ' للتحقق.',
            'tried' => $fetchedUrl,
        ]);
    }

    // Display name: prefer og:title when it's specific, otherwise parse the
    // page title element, finally fall back to the username.
    $displayName = '';
    if ($ogTitle !== '' && stripos($ogTitle, 'Telegram: Contact') === false) {
        $displayName = $ogTitle;
    }
    if ($displayName === '' && preg_match('#tgme_channel_info_header_title[^>]*>\s*<span[^>]*>([^<]+)</span>#', $html, $m)) {
        $displayName = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if ($displayName === '' && preg_match('#tgme_page_title[^>]*>\s*<span[^>]*>([^<]+)</span>#', $html, $m)) {
        $displayName = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if ($displayName === '') {
        $displayName = $username;
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

    tg_json_exit([
        'ok'           => true,
        'username'     => $username,
        'display_name' => $displayName,
        'avatar_url'   => $avatar,
        'description'  => $description,
        'subscribers'  => $subscribers,
        'channel_url'  => 'https://t.me/' . $username,
    ]);

} catch (Throwable $e) {
    tg_json_exit(['ok' => false, 'error' => 'خطأ داخلي: ' . $e->getMessage()]);
}
