<?php
/**
 * AI Assistant endpoint for the article editor.
 *
 * Accepts POST with {task, text, title?} and returns {ok, result}.
 * Supported tasks:
 *   - summarize: produce a 2-3 sentence excerpt
 *   - title_suggestions: propose 3 alternative titles
 *   - improve: rewrite for clarity/flow, same meaning
 *   - keywords: extract 5-7 SEO keywords
 *   - key_points: extract 4-6 bullet key points
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/ai_provider.php';
requireRole('editor');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$csrfToken = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_verify($csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF']);
    exit;
}

$task  = $_POST['task']  ?? '';
$title = trim($_POST['title'] ?? '');
$text  = trim(strip_tags((string)($_POST['text'] ?? '')));

if ($text === '' && $title === '') {
    echo json_encode(['ok' => false, 'error' => 'لا يوجد محتوى للمعالجة']);
    exit;
}

// Cap input size to keep token usage sane.
if (mb_strlen($text) > 6000) {
    $text = mb_substr($text, 0, 6000);
}

$prompts = [
    'summarize' => "أنت محرر أخبار محترف. اكتب ملخصاً موجزاً (جملتين إلى ثلاث جمل) بالعربية الفصحى لهذا الخبر، بأسلوب صحفي واضح، بدون أي تمهيد أو عبارات افتتاحية. فقط الملخص.\n\nالعنوان: {$title}\n\nالمحتوى:\n{$text}",
    'title_suggestions' => "اقترح 3 عناوين صحفية جذابة وبديلة لهذا الخبر بالعربية، كل عنوان في سطر منفصل، بدون ترقيم ولا علامات. عناوين قصيرة (أقل من 12 كلمة) ومؤثرة.\n\nالعنوان الحالي: {$title}\n\nالمحتوى:\n{$text}",
    'improve' => "أعد صياغة هذا النص بالعربية الفصحى بأسلوب صحفي احترافي، مع الحفاظ التام على المعنى والحقائق. حسّن التدفق والوضوح واللغة. أعد النص الكامل فقط، بدون أي تعليق أو مقدمة.\n\n{$text}",
    'keywords' => "استخرج 5-7 كلمات مفتاحية لـ SEO من هذا الخبر بالعربية، كل كلمة مفتاحية مفصولة بفاصلة، بدون أي تفسير أو ترقيم.\n\nالعنوان: {$title}\n\nالمحتوى:\n{$text}",
    'key_points' => "استخرج 4-6 نقاط رئيسية من هذا الخبر بالعربية الفصحى، كل نقطة في سطر منفصل تبدأ بشرطة (-)، بشكل موجز وواضح، بدون أي مقدمة.\n\nالعنوان: {$title}\n\nالمحتوى:\n{$text}",
];

if (!isset($prompts[$task])) {
    echo json_encode(['ok' => false, 'error' => 'مهمة غير معروفة']);
    exit;
}

$maxTokens = ($task === 'improve') ? 2500 : 500;

try {
    $result = ai_provider_text_call($prompts[$task], $maxTokens);
    if (!empty($result['ok']) && !empty($result['text'])) {
        echo json_encode(['ok' => true, 'result' => trim($result['text'])], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'فشل الاتصال بـ AI']);
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
