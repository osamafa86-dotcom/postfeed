<?php
/**
 * Shared helpers for the user dashboard JSON APIs.
 * Every endpoint should require this file first.
 */
require_once __DIR__ . '/../includes/user_auth.php';
require_once __DIR__ . '/../includes/user_functions.php';
require_once __DIR__ . '/../includes/rate_limit.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function require_post_json(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

function require_user_json(): int {
    $uid = current_user_id();
    if (!$uid) json_out(['ok' => false, 'error' => 'auth_required'], 401);
    return (int)$uid;
}

function require_csrf_json(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!csrf_verify($token)) json_out(['ok' => false, 'error' => 'csrf'], 403);
}

function rate_limit_json(string $key, int $limit, int $window): void {
    if (!rate_limit_check($key . ':' . client_ip(), $limit, $window)) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
    }
}
