<?php
/**
 * Shared bootstrap for /api/v1/ — the mobile/native API surface.
 *
 * All endpoints under /api/v1/ speak JSON and authenticate with a
 * Bearer token (Authorization: Bearer <token>). Session cookies are
 * NOT honored here, so mobile clients don't need cookie jars or CSRF.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/user_auth.php';
require_once __DIR__ . '/../../includes/user_functions.php';
require_once __DIR__ . '/../../includes/api_tokens_migrate.php';
require_once __DIR__ . '/../../includes/rate_limit.php';

api_tokens_migrate();

// Mobile apps need permissive CORS so WKWebView previews, simulator, and
// Android emulators can all hit the API. Tokens + HTTPS are the real boundary.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-App-Version, X-Platform');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Vary: Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Send a JSON response and terminate.
 */
function api_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $code, string $message = '', int $status = 400, array $extra = []): void {
    api_json(array_merge([
        'ok' => false,
        'error' => $code,
        'message' => $message,
    ], $extra), $status);
}

function api_method(string ...$methods): void {
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
    if (!in_array($m, array_map('strtoupper', $methods), true)) {
        api_error('method_not_allowed', '', 405);
    }
}

/**
 * Read and decode a JSON request body. Falls back to $_POST for
 * convenience so we accept form-encoded bodies too.
 */
function api_body(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') { $cached = $_POST ?: []; return $cached; }
    $json = json_decode($raw, true);
    $cached = is_array($json) ? $json : ($_POST ?: []);
    return $cached;
}

/**
 * Extract the Bearer token from the Authorization header (or ?access_token=).
 * Returns null when no token is present.
 */
function api_bearer_token(): ?string {
    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if ($auth === '' && function_exists('getallheaders')) {
        $all = getallheaders() ?: [];
        foreach ($all as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = (string)$v; break; }
        }
    }
    if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
        $t = trim(substr($auth, 7));
        return $t !== '' ? $t : null;
    }
    $q = $_GET['access_token'] ?? '';
    return $q !== '' ? (string)$q : null;
}

/**
 * Look up an API token and load its owner. Returns [userId, tokenRow] or null.
 * Touches `last_used_at` / `last_ip` without blocking the request if it fails.
 */
function api_auth_lookup(): ?array {
    $token = api_bearer_token();
    if ($token === null) return null;
    // Raw tokens are opaque random strings; we store only their SHA-256.
    $hash = hash('sha256', $token);
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, user_id, revoked_at, expires_at FROM api_tokens WHERE token_hash = ? LIMIT 1");
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) return null;
        if (!empty($row['revoked_at'])) return null;
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return null;

        try {
            $upd = $db->prepare("UPDATE api_tokens SET last_used_at = NOW(), last_ip = ? WHERE id = ?");
            $upd->execute([substr(client_ip(), 0, 45), (int)$row['id']]);
        } catch (Throwable $e) {}

        return ['user_id' => (int)$row['user_id'], 'token_id' => (int)$row['id']];
    } catch (Throwable $e) {
        error_log('api_auth_lookup: ' . $e->getMessage());
        return null;
    }
}

/**
 * Current user id from Bearer token, or null if anonymous.
 */
function api_current_user_id(): ?int {
    static $cached = false;
    if ($cached !== false) return $cached;
    $auth = api_auth_lookup();
    $cached = $auth ? $auth['user_id'] : null;
    return $cached;
}

function api_require_user(): int {
    $uid = api_current_user_id();
    if (!$uid) api_error('auth_required', 'يجب تسجيل الدخول', 401);
    return $uid;
}

function api_current_user(): ?array {
    $uid = api_current_user_id();
    if (!$uid) return null;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, name, username, email, avatar_letter, bio, theme, role, reading_streak, last_read_date, notify_breaking, notify_followed, notify_digest, plan, created_at FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$uid]);
        $u = $stmt->fetch();
        return $u ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Issue a new API token for a user and return the raw (unhashed) value.
 * The raw token is only visible to the client at creation time.
 */
function api_token_issue(int $userId, string $platform = 'ios', ?string $deviceName = null, ?string $appVersion = null): string {
    $raw = bin2hex(random_bytes(32)); // 64 hex chars
    $hash = hash('sha256', $raw);
    $prefix = substr($raw, 0, 12);
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO api_tokens (user_id, token_hash, token_prefix, platform, device_name, app_version, expires_at) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 365 DAY))");
    $stmt->execute([$userId, $hash, $prefix, $platform, $deviceName, $appVersion]);
    return $raw;
}

function api_token_revoke(int $tokenId): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE api_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL");
        $stmt->execute([$tokenId]);
    } catch (Throwable $e) {}
}

function api_rate_limit(string $key, int $limit, int $window): void {
    $scope = api_current_user_id() ? ('u:' . api_current_user_id()) : ('ip:' . client_ip());
    if (!rate_limit_check($key . ':' . $scope, $limit, $window)) {
        api_error('rate_limited', 'الرجاء المحاولة بعد قليل', 429);
    }
}

/**
 * Shape an article row (joined with categories + sources) into the API payload.
 */
function api_article_shape(array $a, bool $withContent = false): array {
    $out = [
        'id' => (int)$a['id'],
        'title' => (string)($a['title'] ?? ''),
        'slug' => (string)($a['slug'] ?? ''),
        'excerpt' => (string)($a['excerpt'] ?? ''),
        'image_url' => $a['image_url'] ?? null,
        'source_url' => $a['source_url'] ?? null,
        'published_at' => $a['published_at'] ?? null,
        'view_count' => (int)($a['view_count'] ?? 0),
        'comments' => (int)($a['comments'] ?? 0),
        'is_breaking' => (bool)($a['is_breaking'] ?? false),
        'is_featured' => (bool)($a['is_featured'] ?? false),
        'category' => [
            'id' => isset($a['category_id']) && $a['category_id'] !== null ? (int)$a['category_id'] : null,
            'name' => $a['category_name'] ?? null,
            'slug' => $a['category_slug'] ?? null,
            'icon' => $a['category_icon'] ?? null,
            'css_class' => $a['category_class'] ?? null,
        ],
        'source' => [
            'id' => isset($a['source_id']) && $a['source_id'] !== null ? (int)$a['source_id'] : null,
            'name' => $a['source_name'] ?? null,
            'logo_letter' => $a['logo_letter'] ?? null,
            'logo_color' => $a['logo_color'] ?? null,
            'logo_bg' => $a['logo_bg'] ?? null,
            'url' => $a['source_site_url'] ?? null,
        ],
    ];
    if ($withContent) {
        $out['content'] = (string)($a['content'] ?? '');
    }
    return $out;
}
