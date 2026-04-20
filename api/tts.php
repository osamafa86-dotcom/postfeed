<?php
/**
 * نيوز فيد - TTS streaming endpoint
 * =================================
 * GET /api/tts.php?id=N
 *
 * Returns an MP3 audio stream for the given article. Behaviour:
 *   - If cloud TTS is disabled in settings -> 404 (frontend falls
 *     back to the browser Web Speech API).
 *   - If the cache already has the right MP3 for the current
 *     provider+voice+text combo -> streams it immediately.
 *   - Otherwise it calls the provider, writes the file, and streams.
 *
 * Rate limited per IP to keep anyone from burning through the
 * ElevenLabs quota with a simple refresh loop.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/tts.php';

// 60 requests / minute / IP. Individual articles hit the cache on
// every call after the first, so this is generous.
rate_limit_enforce_api('tts:' . client_ip(), 60, 60);

$articleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($articleId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'bad_id']);
    exit;
}

// Master switch. The frontend checks for 404 on this endpoint to
// decide whether to fall back to the browser Web Speech API.
if (!tts_is_enabled()) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'tts_disabled']);
    exit;
}

$article = getArticleById($articleId);
if (!$article) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

try {
    $result = tts_get_or_generate($article);
} catch (Throwable $e) {
    error_log('[api/tts] ' . $e->getMessage());
    $result = null;
}

if (!$result || !is_file($result['path'])) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'tts_generation_failed']);
    exit;
}

$path  = $result['path'];
$bytes = (int)$result['bytes'];
$etag  = '"' . $result['key'] . '"';

// Conditional GET — lets the browser cache the audio aggressively
// and only re-fetch when the hash changes (i.e. provider/voice/text
// changed).
$ifNone = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNone !== '' && trim($ifNone) === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=2592000, immutable');
    exit;
}

// Stream the MP3.
header('Content-Type: audio/mpeg');
header('Content-Length: ' . $bytes);
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=2592000, immutable');
header('X-TTS-Provider: ' . $result['provider']);
header('X-TTS-Cached: '   . ($result['cached'] ? '1' : '0'));
header('Accept-Ranges: none');

// Flush any OB that might have been started by config.php.
while (ob_get_level() > 0) { ob_end_clean(); }

readfile($path);
exit;
