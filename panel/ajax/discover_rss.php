<?php
/**
 * JSON endpoint: POST { query } -> discovered source metadata.
 * Called from the admin "add source" page to auto-fill the form.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/rss_discover.php';

header('Content-Type: application/json; charset=utf-8');

// Editor or admin — viewers cannot add sources.
if (session_status() === PHP_SESSION_NONE) session_start();
if (!hasRole('editor')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'غير مصرّح']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST فقط']);
    exit;
}

if (!csrf_verify($_POST['_csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

$query = trim($_POST['query'] ?? '');
if ($query === '') {
    echo json_encode(['ok' => false, 'error' => 'أدخل اسماً أو رابطاً للموقع']);
    exit;
}

@set_time_limit(25);
$result = rssd_discover($query);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
