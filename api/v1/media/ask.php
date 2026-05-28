<?php
/**
 * POST /api/v1/media/ask
 * AI-powered Q&A over the news archive.
 *
 * Body: { "question": "..." }
 * Response: { "answer": "...", "sources": [...] }
 *
 * The previous version called ai_qa_ask()/ai_qa_answer() — neither
 * exists. The real function is qa_ask() in includes/ai_qa.php, which
 * returns articles under the `articles` key (not `sources`). Result:
 * every request 501'd and the Ask feature was completely dead.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/ai_qa.php';

api_method('POST');
api_rate_limit('media:ask', 30, 300);

$body = api_body();
$q = trim((string)($body['question'] ?? $body['q'] ?? ''));
if (mb_strlen($q) < 3) api_err('invalid_input', 'السؤال قصير جداً', 422);
if (mb_strlen($q) > 500) api_err('invalid_input', 'السؤال طويل جداً', 422);

try {
    $result = qa_ask($q);
} catch (Throwable $e) {
    error_log('ask api: ' . $e->getMessage());
    api_err('ai_error', 'تعذّر الحصول على الإجابة، حاول لاحقاً', 502);
}

if (!is_array($result)) {
    api_err('ai_error', 'استجابة غير صالحة', 502);
}

// qa_ask returns {ok: bool, error?: string, answer?: string, articles?: array}
if (empty($result['ok'])) {
    api_err('ai_error', (string)($result['error'] ?? 'تعذّر الحصول على الإجابة'), 502);
}

api_ok([
    'answer'  => (string)($result['answer'] ?? ''),
    'sources' => array_map(function ($s) {
        if (!is_array($s)) return null;
        return [
            'id'           => isset($s['id']) ? (int)$s['id'] : null,
            'title'        => $s['title'] ?? null,
            'slug'         => $s['slug'] ?? null,
            'image_url'    => isset($s['image_url']) ? api_image_url($s['image_url']) : null,
            'url'          => $s['url'] ?? null,
            'published_at' => $s['published_at'] ?? null,
        ];
    }, $result['articles'] ?? []),
    'follow_ups' => $result['follow_ups'] ?? [],
]);
