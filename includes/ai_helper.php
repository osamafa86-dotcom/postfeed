<?php
/**
 * Claude AI helper for article summarization.
 * API key is read from settings table (anthropic_api_key).
 */

function ai_summarize_article($title, $content, $maxTokens = 500) {
    // Prefer the key saved through the admin panel so rotations from
    // panel/ai.php take effect immediately. Fall back to the env var
    // only if the DB setting is empty.
    $apiKey = trim((string)getSetting('anthropic_api_key', ''));
    if ($apiKey === '') $apiKey = trim((string)env('ANTHROPIC_API_KEY', ''));
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'API key not configured'];
    }

    $prompt = "أنت محرر أخبار محترف. لخّص الخبر التالي بالعربية بأسلوب صحفي محايد.\n\n"
            . "العنوان: $title\n\n"
            . "النص:\n" . mb_substr(strip_tags($content), 0, 6000) . "\n\n"
            . "أعطني رداً بصيغة JSON فقط (بدون أي شرح إضافي) بالشكل:\n"
            . '{"summary":"ملخص في 3-4 جمل","key_points":["نقطة 1","نقطة 2","نقطة 3"],"keywords":["كلمة1","كلمة2","كلمة3"]}';

    $body = json_encode([
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => $maxTokens,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($http !== 200) {
        return ['ok' => false, 'error' => "HTTP $http: " . ($err ?: $resp)];
    }

    $data = json_decode($resp, true);
    $text = $data['content'][0]['text'] ?? '';

    // Extract JSON from response
    if (preg_match('/\{.*\}/s', $text, $m)) {
        $parsed = json_decode($m[0], true);
        if ($parsed && isset($parsed['summary'])) {
            return [
                'ok' => true,
                'summary' => $parsed['summary'],
                'key_points' => $parsed['key_points'] ?? [],
                'keywords' => $parsed['keywords'] ?? [],
            ];
        }
    }

    return ['ok' => false, 'error' => 'Failed to parse AI response', 'raw' => $text];
}

/**
 * Summarize a batch of Telegram channel messages into a compact Arabic
 * news briefing.
 *
 * @param array  $messages   Rows from telegram_messages joined with telegram_sources.
 *                           Each row must include at least: text, username, posted_at.
 * @param int    $maxTokens  Upper bound on Claude's response tokens.
 * @return array             ['ok'=>bool, 'headline'=>string, 'summary'=>string,
 *                            'bullets'=>array<string>, 'topics'=>array<string>]
 *                           or ['ok'=>false, 'error'=>string].
 */
function ai_summarize_telegram(array $messages, int $maxTokens = 900): array {
    // Prefer the key saved through the admin panel so rotations from
    // panel/ai.php take effect immediately. Fall back to the env var
    // only if the DB setting is empty.
    $apiKey = trim((string)getSetting('anthropic_api_key', ''));
    if ($apiKey === '') $apiKey = trim((string)env('ANTHROPIC_API_KEY', ''));
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'مفتاح Anthropic API غير مُعدّ. يرجى إضافته من لوحة التحكم.'];
    }
    if (!$messages) {
        return ['ok' => false, 'error' => 'لا توجد رسائل للتلخيص'];
    }

    // Build a compact corpus. We cap each message at 400 chars and the
    // whole blob at ~12 000 chars so we stay well under Claude's input
    // budget even for very chatty windows.
    $lines = [];
    $budget = 12000;
    $used   = 0;
    foreach ($messages as $m) {
        $text = trim((string)($m['text'] ?? ''));
        if ($text === '') continue;
        $text = preg_replace('/\s+/u', ' ', $text);
        if (mb_strlen($text) > 400) $text = mb_substr($text, 0, 400) . '…';
        $handle = '@' . ($m['username'] ?? 'tg');
        $when   = !empty($m['posted_at']) ? date('H:i', strtotime($m['posted_at'])) : '';
        $line   = '- [' . $handle . ' ' . $when . '] ' . $text;
        $len    = mb_strlen($line);
        if ($used + $len > $budget) break;
        $lines[] = $line;
        $used += $len + 1;
    }
    if (!$lines) {
        return ['ok' => false, 'error' => 'لا توجد رسائل نصية كافية للتلخيص'];
    }

    $corpus = implode("\n", $lines);
    $count  = count($lines);

    $prompt = "أنت محرر أخبار محترف في غرفة أخبار عربية. لديك قائمة بآخر {$count} رسالة وصلت "
            . "من قنوات تيليغرام إخبارية خلال الفترة الماضية. اقرأها كلها ثم اكتب موجزاً إخبارياً "
            . "عربياً قصيراً ومحايداً. ادمج الأخبار المكررة أو المتعلقة بنفس الحدث في نقطة واحدة، "
            . "وتجاهل الإعلانات والرسائل غير الإخبارية. حافظ على النبرة الصحفية الرسمية ولا تخترع "
            . "معلومات غير موجودة في الرسائل.\n\n"
            . "الرسائل:\n" . $corpus . "\n\n"
            . "أعطني الرد بصيغة JSON صالح فقط (بدون أي نص خارج الـ JSON) بالشكل التالي:\n"
            . '{'
            . '"headline":"عنوان رئيسي قصير يلخّص أبرز ما جاء في الرسائل (أقل من 90 حرفاً)",'
            . '"summary":"فقرة موجزة من 3-5 جمل تلخّص المشهد العام",'
            . '"bullets":["نقطة إخبارية 1","نقطة 2","نقطة 3","نقطة 4","نقطة 5"],'
            . '"topics":["موضوع 1","موضوع 2","موضوع 3"]'
            . '}';

    $body = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => $maxTokens,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $body,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 60,
        CURLOPT_HTTPHEADER      => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($http !== 200) {
        // Surface a friendly Arabic message for the common failure modes so the
        // UI doesn't show a raw HTTP dump to end users.
        if ($http === 401 || $http === 403) {
            return [
                'ok'    => false,
                'error' => 'مفتاح Anthropic API غير صالح أو منتهي الصلاحية. يرجى تحديثه من لوحة التحكم.',
            ];
        }
        if ($http === 429) {
            return [
                'ok'    => false,
                'error' => 'تم تجاوز حد الطلبات لخدمة الملخصات حالياً. حاول مرة أخرى بعد قليل.',
            ];
        }
        if ($http === 0 || $http >= 500) {
            return [
                'ok'    => false,
                'error' => 'تعذّر الاتصال بخدمة الملخصات حالياً. حاول مرة أخرى بعد قليل.',
            ];
        }
        return ['ok' => false, 'error' => "HTTP $http: " . ($err ?: mb_substr((string)$resp, 0, 200))];
    }

    $data = json_decode($resp, true);
    $text = $data['content'][0]['text'] ?? '';
    if ($text === '') {
        return ['ok' => false, 'error' => 'Empty AI response'];
    }

    // Claude will usually return pure JSON, but guard against it wrapping
    // the payload in prose by extracting the first {...} block.
    $parsed = null;
    if (preg_match('/\{.*\}/s', $text, $m)) {
        $parsed = json_decode($m[0], true);
    }
    if (!is_array($parsed) || empty($parsed['summary'])) {
        return ['ok' => false, 'error' => 'Failed to parse AI response', 'raw' => $text];
    }

    return [
        'ok'       => true,
        'headline' => (string)($parsed['headline'] ?? ''),
        'summary'  => (string)$parsed['summary'],
        'bullets'  => array_values(array_filter(array_map('strval', (array)($parsed['bullets'] ?? [])))),
        'topics'   => array_values(array_filter(array_map('strval', (array)($parsed['topics']  ?? [])))),
    ];
}

function ai_save_summary($articleId, $result) {
    if (!$result['ok']) return false;
    $db = getDB();
    // Auto-migrate columns
    try {
        $cols = $db->query("SHOW COLUMNS FROM articles LIKE 'ai_summary'")->fetch();
        if (!$cols) {
            $db->exec("ALTER TABLE articles
                ADD COLUMN ai_summary TEXT,
                ADD COLUMN ai_key_points TEXT,
                ADD COLUMN ai_keywords VARCHAR(500),
                ADD COLUMN ai_processed_at TIMESTAMP NULL");
        }
    } catch (Exception $e) {}

    $stmt = $db->prepare("UPDATE articles SET ai_summary=?, ai_key_points=?, ai_keywords=?, ai_processed_at=NOW() WHERE id=?");
    return $stmt->execute([
        $result['summary'],
        json_encode($result['key_points'], JSON_UNESCAPED_UNICODE),
        implode(', ', $result['keywords']),
        $articleId
    ]);
}
