<?php
/**
 * POST /api/v1/media/tts
 * Generate or fetch cached TTS audio for arbitrary text or for an article.
 *
 * Body: { "article_id": 123 }  OR  { "text": "..." }
 * Response: { "audio_url": "https://...", "duration": N, "cached": bool }
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/tts.php';

api_method('POST');
api_rate_limit('media:tts', 30, 300);

$body = api_body();
$articleId = isset($body['article_id']) ? (int)$body['article_id'] : 0;
$text = isset($body['text']) ? trim((string)$body['text']) : '';

if (!$articleId && $text === '') api_err('invalid_input', 'يلزم article_id أو text', 422);

try {
    if ($articleId) {
        $db = getDB();
        $st = $db->prepare("SELECT id, title, excerpt, content FROM articles WHERE id=? AND status='published' LIMIT 1");
        $st->execute([$articleId]);
        $a = $st->fetch();
        if (!$a) api_err('not_found', 'المقال غير موجود', 404);
        $text = trim(strip_tags((string)$a['content']) ?: ((string)$a['excerpt'] ?: (string)$a['title']));
    }
    if ($text === '') api_err('invalid_input', 'لا يوجد نص للقراءة', 422);

    if (!function_exists('tts_synthesize_cached')) {
        api_err('not_implemented', 'TTS غير متاح', 501);
    }

    $res = tts_synthesize_cached($text, $articleId);
    if (!$res || empty($res['audio_url'])) api_err('tts_error', 'تعذّر توليد الصوت', 502);

    api_ok([
        'audio_url' => api_absolute_url($res['audio_url']),
        'duration' => (int)($res['duration'] ?? 0),
        'cached' => (bool)($res['cached'] ?? false),
        'provider' => $res['provider'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('tts api: ' . $e->getMessage());
    api_err('tts_error', 'حدث خطأ غير متوقع', 502);
}
