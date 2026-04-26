<?php
/**
 * POST /api/v1/media/ask
 * AI-powered Q&A over the news archive.
 *
 * Body: { "question": "..." }
 * Response: { "answer": "...", "sources": [...] }
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
    if (function_exists('ai_qa_ask')) {
        $result = ai_qa_ask($q);
    } elseif (function_exists('ai_qa_answer')) {
        $result = ai_qa_answer($q);
    } else {
        api_err('not_implemented', 'الميزة غير متاحة', 501);
    }
} catch (Throwable $e) {
    error_log('ask api: ' . $e->getMessage());
    api_err('ai_error', 'تعذّر الحصول على الإجابة، حاول لاحقاً', 502);
}

if (!is_array($result)) {
    api_err('ai_error', 'استجابة غير صالحة', 502);
}

api_ok([
    'answer' => $result['answer'] ?? ($result['text'] ?? ''),
    'sources' => array_map(function ($s) {
        if (!is_array($s)) return null;
        return [
            'id' => isset($s['id']) ? (int)$s['id'] : null,
            'title' => $s['title'] ?? null,
            'slug' => $s['slug'] ?? null,
            'image_url' => isset($s['image_url']) ? api_image_url($s['image_url']) : null,
            'url' => $s['url'] ?? null,
            'published_at' => $s['published_at'] ?? null,
        ];
    }, $result['sources'] ?? []),
]);
