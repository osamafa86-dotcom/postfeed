<?php
/**
 * نيوز فيد - API: اسأل الأخبار (Q&A)
 * ==================================
 * GET /api/ask.php?q=السؤال
 *
 * Rate limited: 15 requests / hour / IP. This is a paid Claude call
 * so the envelope is tighter than /api/search.php or /api/trending.php.
 * Responses are cached in qa_ask() for 10 minutes per normalized
 * question, so burst traffic on the same query is essentially free.
 *
 * Response shape:
 *   { ok, answer, articles:[{id,title,image_url,url,...}], follow_ups:[...] }
 * or on failure:
 *   { ok:false, error }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://postfeed.emdatra.org');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: private, max-age=0, no-store');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/ai_qa.php';

// 15 questions / hour / IP. Claude Sonnet isn't free.
rate_limit_enforce_api('ask:' . client_ip(), 15, 3600);

try {
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    if ($q === '' && isset($_POST['q'])) $q = trim((string)$_POST['q']);
    if ($q === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_question'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = qa_ask($q);

    if (empty($result['ok'])) {
        http_response_code(200); // keep 200 so the UI can show the error nicely
        echo json_encode([
            'ok'    => false,
            'error' => $result['error'] ?? 'خطأ غير معروف',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok'         => true,
        'question'   => $q,
        'answer'     => $result['answer'],
        'articles'   => $result['articles'],
        'follow_ups' => $result['follow_ups'] ?? [],
        'confident'  => (bool)($result['confident'] ?? true),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[api/ask] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'server_error',
    ], JSON_UNESCAPED_UNICODE);
}
