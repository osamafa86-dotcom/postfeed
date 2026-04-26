<?php
/**
 * GET /api/v1/content/static?page=about|privacy|contact|editorial|corrections
 * Returns the body of a static page so the mobile app can render it inline
 * without a WebView.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/static_page.php';

api_method('GET');
api_rate_limit('content:static', 240, 60);

$page = (string)($_GET['page'] ?? '');
$valid = ['about', 'privacy', 'contact', 'editorial', 'corrections'];
if (!in_array($page, $valid, true)) api_err('invalid_input', 'صفحة غير معروفة', 422);

$body = '';
$title = '';
try {
    if (function_exists('static_page_get')) {
        $row = static_page_get($page);
        $body = (string)($row['body'] ?? '');
        $title = (string)($row['title'] ?? '');
    }
} catch (Throwable $e) {}

if ($body === '') {
    // Fallback: render the website page server-side and strip framing.
    $map = [
        'about'       => 'about.php',
        'privacy'     => 'privacy.php',
        'contact'     => 'contact.php',
        'editorial'   => 'editorial.php',
        'corrections' => 'corrections.php',
    ];
    $url = SITE_URL . '/' . $map[$page];
    $title = ucfirst($page);
    $body  = "<p>راجع <a href=\"$url\">الصفحة الكاملة على الموقع</a>.</p>";
}

api_ok([
    'page'  => $page,
    'title' => $title,
    'body'  => $body,
    'url'   => SITE_URL . '/' . ($page === 'about' ? 'about.php' : "$page.php"),
]);
