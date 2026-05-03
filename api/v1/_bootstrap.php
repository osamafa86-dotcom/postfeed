<?php
/**
 * نيوز فيد — API v1 Bootstrap
 * ============================
 * Foundation layer for the mobile app (iOS + Android).
 * Every endpoint under /api/v1/ requires this file first.
 *
 * Provides:
 *   - JSON response helpers
 *   - JWT-based stateless authentication (Bearer token)
 *   - CORS for the mobile clients
 *   - Rate limiting
 *   - Request body parsing
 *   - Pagination helpers
 *   - Error handler
 */

declare(strict_types=1);

// -------------------------------------------------
// CORS — must run BEFORE any include so preflight always succeeds,
// even if the includes throw or warn.
// -------------------------------------------------
// CORS — mobile apps don't need CORS, but web panel does.
$allowedOrigins = [
    'https://feedsnews.net',
    'https://www.feedsnews.net',
    'http://localhost:3000', // dev only
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // Mobile apps don't send Origin header, so no CORS header needed.
    // Browsers without matching Origin get no ACAO header → blocked.
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Device-Id, X-App-Version, X-Platform');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Vary: Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/user_migrate.php';

// Make sure user-related tables exist (idempotent).
user_dashboard_migrate();

// -------------------------------------------------
// Response helpers — always return a uniform envelope.
// -------------------------------------------------
function api_ok($data = null, array $meta = []): void {
    $payload = ['ok' => true, 'data' => $data];
    if ($meta) $payload['meta'] = $meta;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_err(string $code, string $message = '', int $status = 400, array $extra = []): void {
    http_response_code($status);
    $payload = ['ok' => false, 'error' => $code];
    if ($message !== '') $payload['message'] = $message;
    if ($extra) $payload['extra'] = $extra;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_method(string ...$allowed): void {
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($m, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        api_err('method_not_allowed', 'Method not allowed', 405);
    }
}

function api_body(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return $cached = $_POST ?: [];
    $j = json_decode($raw, true);
    return $cached = is_array($j) ? $j : [];
}

function api_input(string $key, $default = null) {
    if (array_key_exists($key, $_GET)) return $_GET[$key];
    $b = api_body();
    return $b[$key] ?? $default;
}

function api_pagination(int $defaultLimit = 20, int $maxLimit = 100): array {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = (int)($_GET['limit'] ?? $defaultLimit);
    $limit = max(1, min($limit, $maxLimit));
    $offset = ($page - 1) * $limit;
    return [$page, $limit, $offset];
}

function api_rate_limit(string $key, int $limit, int $window): void {
    $full = $key . ':' . client_ip();
    if (!rate_limit_check($full, $limit, $window)) {
        api_err('rate_limited', 'تم تجاوز الحد المسموح، حاول لاحقاً', 429);
    }
}

// -------------------------------------------------
// JWT (HS256) — stateless auth for mobile clients.
// We don't add a third-party dependency; spec is small.
// -------------------------------------------------
function jwt_b64url_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function jwt_b64url_decode(string $s): string {
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $r = base64_decode(strtr($s, '-_', '+/'), true);
    return $r === false ? '' : $r;
}

function jwt_secret(): string {
    return SECRET_KEY ?: 'change-me-in-env';
}

/**
 * Issue a signed JWT for a user.
 * Default lifetime: 30 days (mobile-friendly; refresh on use).
 */
function jwt_issue(int $userId, int $ttlSeconds = 2592000, array $extra = []): string {
    $now = time();
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = array_merge([
        'sub' => $userId,
        'iat' => $now,
        'exp' => $now + $ttlSeconds,
        'iss' => 'feedsnews',
    ], $extra);
    $h = jwt_b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = jwt_b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $sig = hash_hmac('sha256', "$h.$p", jwt_secret(), true);
    return "$h.$p." . jwt_b64url_encode($sig);
}

function jwt_verify(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = jwt_b64url_encode(hash_hmac('sha256', "$h.$p", jwt_secret(), true));
    if (!hash_equals($expected, $s)) return null;
    $payload = json_decode(jwt_b64url_decode($p), true);
    if (!is_array($payload)) return null;
    if (!isset($payload['exp']) || $payload['exp'] < time()) return null;
    return $payload;
}

function bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'authorization') === 0 && stripos($v, 'Bearer ') === 0) {
                return trim(substr($v, 7));
            }
        }
    }
    return null;
}

function api_optional_user(): ?array {
    $t = bearer_token();
    if (!$t) return null;
    $payload = jwt_verify($t);
    if (!$payload) return null;
    $uid = (int)($payload['sub'] ?? 0);
    if (!$uid) return null;
    try {
        $db = getDB();
        $st = $db->prepare("SELECT id, name, username, email, avatar_letter, bio, theme, role, reading_streak, last_read_date, notify_breaking, notify_followed, notify_digest, plan, created_at FROM users WHERE id = ? LIMIT 1");
        $st->execute([$uid]);
        $u = $st->fetch();
        return $u ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function api_require_user(): array {
    $u = api_optional_user();
    if (!$u) api_err('auth_required', 'يلزم تسجيل الدخول', 401);
    return $u;
}

// -------------------------------------------------
// Common transformers
// -------------------------------------------------
function api_user_public(array $u): array {
    return [
        'id'             => (int)$u['id'],
        'name'           => (string)$u['name'],
        'username'       => $u['username'] ?? null,
        'email'          => $u['email'] ?? null,
        'avatar_letter'  => $u['avatar_letter'] ?? mb_substr((string)$u['name'], 0, 1),
        'bio'            => $u['bio'] ?? '',
        'theme'          => $u['theme'] ?? 'auto',
        'role'           => $u['role'] ?? 'reader',
        'plan'           => $u['plan'] ?? 'free',
        'reading_streak' => (int)($u['reading_streak'] ?? 0),
        'notify' => [
            'breaking' => (int)($u['notify_breaking'] ?? 1),
            'followed' => (int)($u['notify_followed'] ?? 1),
            'digest'   => (int)($u['notify_digest']   ?? 1),
        ],
        'created_at' => $u['created_at'] ?? null,
    ];
}

function api_absolute_url(?string $path): ?string {
    if (!$path) return null;
    if (preg_match('#^https?://#i', $path)) return $path;
    $base = rtrim(SITE_URL, '/');
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;
    return $base . $path;
}

function api_image_url(?string $url): ?string {
    if (!$url) return null;
    return api_absolute_url($url);
}

function api_format_article(array $a): array {
    return [
        'id'            => (int)$a['id'],
        'title'         => (string)$a['title'],
        'slug'          => $a['slug'] ?? null,
        'excerpt'       => $a['excerpt'] ?? null,
        'content'       => $a['content'] ?? null,
        'image_url'     => api_image_url($a['image_url'] ?? null),
        'source_url'    => $a['source_url'] ?? null,
        'category' => isset($a['category_slug']) ? [
            'id'    => (int)($a['category_id'] ?? 0),
            'name'  => $a['category_name'] ?? null,
            'slug'  => $a['category_slug'] ?? null,
            'icon'  => $a['category_icon'] ?? null,
            'color' => $a['css_class'] ?? null,
        ] : null,
        'source' => isset($a['source_slug']) ? [
            'id'           => (int)($a['source_id'] ?? 0),
            'name'         => $a['source_name'] ?? null,
            'slug'         => $a['source_slug'] ?? null,
            'logo_letter'  => $a['logo_letter'] ?? null,
            'logo_color'   => $a['logo_color'] ?? null,
            'logo_bg'      => $a['logo_bg'] ?? null,
            'url'          => $a['source_site'] ?? null,
        ] : null,
        'is_breaking'  => (bool)($a['is_breaking'] ?? false),
        'is_featured'  => (bool)($a['is_featured'] ?? false),
        'is_hero'      => (bool)($a['is_hero'] ?? false),
        'view_count'   => (int)($a['view_count'] ?? 0),
        'comments'     => (int)($a['comments'] ?? 0),
        'published_at' => $a['published_at'] ?? null,
        'created_at'   => $a['created_at'] ?? null,
    ];
}

// -------------------------------------------------
// Global error handler — never expose stack traces.
// -------------------------------------------------
set_exception_handler(function (Throwable $e) {
    error_log('[api/v1] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if (!headers_sent()) http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'حدث خطأ غير متوقع، حاول لاحقاً',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});
