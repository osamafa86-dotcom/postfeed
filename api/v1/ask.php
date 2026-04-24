<?php
/**
 * GET /api/v1/ask?q=... — proxy to the AI Q&A system (Claude/Gemini).
 * Rate-limited aggressively because AI calls cost money.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/ai_qa.php';

api_method('GET', 'POST');
api_rate_limit('ask', 15, 3600); // 15/hour

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
    $body = api_body();
    $q = trim((string)($body['q'] ?? ''));
}
if (mb_strlen($q) < 3) api_error('invalid_input', 'اكتب سؤالاً أوضح');
if (mb_strlen($q) > 300) $q = mb_substr($q, 0, 300);

try {
    if (!function_exists('ai_qa_answer')) {
        api_error('not_available', 'خدمة الأسئلة غير متاحة حالياً', 503);
    }
    $answer = ai_qa_answer($q);
    api_json([
        'ok' => true,
        'question' => $q,
        'answer' => $answer['answer'] ?? '',
        'articles' => $answer['articles'] ?? [],
        'follow_ups' => $answer['follow_ups'] ?? [],
        'confident' => (bool)($answer['confident'] ?? false),
    ]);
} catch (Throwable $e) {
    error_log('v1/ask: ' . $e->getMessage());
    api_error('server_error', 'تعذّر الحصول على إجابة', 500);
}
