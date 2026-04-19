<?php
/**
 * Serves the IndexNow ownership-verification key file.
 *
 * IndexNow fetches https://feedsnews.net/{key}.txt and expects the
 * body to equal {key}. Hosting the key in a PHP endpoint (instead of
 * dropping a .txt into the webroot on deploy) keeps the key under
 * /storage where it belongs and means the key rotates transparently
 * if storage is wiped.
 *
 * .htaccess rewrites /{hexkey}.txt → indexnow-key.php?key={hexkey};
 * hitting the endpoint directly with the wrong ?key= returns 404 so
 * the file doesn't leak the key to scanners enumerating random names.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/indexnow.php';

$requested = $_GET['key'] ?? '';
$actual    = indexnow_key();

if (!is_string($requested) || !ctype_xdigit($requested) || !hash_equals($actual, $requested)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo $actual;
