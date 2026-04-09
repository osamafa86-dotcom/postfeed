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
function ai_summarize_telegram(array $messages, int $maxTokens = 3500): array {
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

    // Build a compact corpus. We cap each message at 350 chars and the
    // whole blob at ~45 000 chars. Claude Haiku has a 200K-token context
    // window so this still leaves massive headroom for the prompt + output.
    $lines = [];
    $budget = 45000;
    $used   = 0;
    foreach ($messages as $m) {
        $text = trim((string)($m['text'] ?? ''));
        if ($text === '') continue;
        $text = preg_replace('/\s+/u', ' ', $text);
        if (mb_strlen($text) > 350) $text = mb_substr($text, 0, 350) . '…';
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

    $prompt = "أنت رئيس تحرير في غرفة أخبار عربية محترفة. لديك قائمة بآخر {$count} رسالة وصلت "
            . "من قنوات تيليغرام إخبارية خلال الفترة الماضية. مهمتك: قراءة كل الرسائل ثم إرسال "
            . "تقرير إخباري عربي منظم ومنسّق بمستوى احترافي عالٍ، كأنه تقرير موجز في نشرة أخبار "
            . "رسمية، عبر استدعاء الأداة submit_news_briefing.\n\n"
            . "قواعد التحرير:\n"
            . "- اجمع الرسائل المتعلقة بنفس الحدث في عنصر واحد، ولا تكرّر الخبر.\n"
            . "- تجاهل الإعلانات والرسائل غير الإخبارية وروابط الاشتراك.\n"
            . "- نظّم المحتوى في محاور رئيسية (ملفات إخبارية)، وكل محور يضم تفاصيل متعلقة.\n"
            . "- استخدم لغة عربية فصحى، محايدة، بنبرة صحفية رسمية.\n"
            . "- كل تفصيلة إخبارية يجب أن تكون جملة كاملة وواضحة تعطي السياق والمكان والأطراف.\n"
            . "- لا تخترع أي معلومة غير موجودة في الرسائل.\n"
            . "- رتّب المحاور من الأهم إلى الأقل أهمية.\n"
            . "- اجعل عدد المحاور بين 3 و 6 حسب ما تستحقه الأخبار.\n"
            . "- كل محور يحتوي على 2 إلى 5 تفاصيل إخبارية.\n"
            . "- استخدم رمز emoji واحد فقط لكل محور (مثل 🔴 🟢 🗞️ ⚡ 🛡️ 🏛️ 📍 ⚖️ 🕌).\n"
            . "- العنوان الرئيسي (headline) يجب أن يكون جذّاباً وإخبارياً، لا عاماً.\n\n"
            . "الرسائل:\n" . $corpus;

    // Force structured output via Anthropic tool use. Claude is guaranteed
    // to return a tool_use block whose `input` matches the JSON schema,
    // which eliminates the "free-text JSON with stray prose / markdown
    // fences" parsing failures we had with the previous approach.
    $tool = [
        'name'        => 'submit_news_briefing',
        'description' => 'Submit a structured Arabic news briefing with headline, summary, thematic sections and topic tags.',
        'input_schema' => [
            'type'     => 'object',
            'properties' => [
                'headline' => [
                    'type'        => 'string',
                    'description' => 'عنوان رئيسي قوي يلخّص أبرز حدث (أقل من 90 حرفاً).',
                ],
                'summary' => [
                    'type'        => 'string',
                    'description' => 'فقرة افتتاحية من 3-5 جمل تعطي المشهد العام بأسلوب نشرة الأخبار.',
                ],
                'sections' => [
                    'type'        => 'array',
                    'description' => 'المحاور الإخبارية، بين 3 و 6 محاور، مرتبة من الأهم إلى الأقل أهمية.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => [
                                'type'        => 'string',
                                'description' => 'عنوان المحور، مثل: التطورات العسكرية في الشمال.',
                            ],
                            'icon'  => [
                                'type'        => 'string',
                                'description' => 'رمز emoji واحد فقط يعبّر عن المحور.',
                            ],
                            'items' => [
                                'type'        => 'array',
                                'description' => 'بين 2 و 5 تفاصيل إخبارية كاملة بجمل واضحة.',
                                'items'       => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['title', 'items'],
                    ],
                ],
                'topics' => [
                    'type'        => 'array',
                    'description' => 'وسوم قصيرة للمواضيع البارزة، 3-6 وسوم بدون رمز #.',
                    'items'       => ['type' => 'string'],
                ],
            ],
            'required' => ['headline', 'summary', 'sections', 'topics'],
        ],
    ];

    $body = json_encode([
        'model'       => 'claude-haiku-4-5-20251001',
        'max_tokens'  => $maxTokens,
        'tools'       => [$tool],
        'tool_choice' => ['type' => 'tool', 'name' => 'submit_news_briefing'],
        'messages'    => [['role' => 'user', 'content' => $prompt]],
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
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'تعذّر قراءة رد خدمة الملخصات.'];
    }

    // With tool_choice forced, Claude returns one or more content blocks
    // and the briefing arrives as a tool_use block whose `input` is
    // already a structured array matching our schema — no JSON parsing,
    // no stray prose, no markdown fences.
    $parsed = null;
    foreach ((array)($data['content'] ?? []) as $block) {
        if (!is_array($block)) continue;
        if (($block['type'] ?? '') === 'tool_use'
            && ($block['name'] ?? '') === 'submit_news_briefing'
            && is_array($block['input'] ?? null)) {
            $parsed = $block['input'];
            break;
        }
    }

    if (!is_array($parsed) || empty($parsed['summary'])) {
        // Stop-reason diagnostics help explain max-tokens truncation.
        $stopReason = (string)($data['stop_reason'] ?? '');
        if ($stopReason === 'max_tokens') {
            return ['ok' => false, 'error' => 'الرد طويل جداً ولم يكتمل. سنقلّل حجم الرسائل وتحاول مرة أخرى.'];
        }
        error_log('[tg_summary] Failed to parse AI response. stop_reason=' . $stopReason . ' raw=' . mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 2000));
        return ['ok' => false, 'error' => 'تعذّر توليد الملخص — الرد غير مكتمل.'];
    }

    // Normalize sections: each section becomes
    //   ['title'=>string, 'icon'=>string, 'items'=>string[]]
    // Drop any section that has no usable items, and bail out to the
    // legacy flat-bullets shape if the model ignored the new schema.
    $sections = [];
    foreach ((array)($parsed['sections'] ?? []) as $sec) {
        if (!is_array($sec)) continue;
        $title = trim((string)($sec['title'] ?? ''));
        $icon  = trim((string)($sec['icon']  ?? ''));
        $items = array_values(array_filter(array_map(
            function($v) { return trim((string)$v); },
            (array)($sec['items'] ?? [])
        )));
        if (!$items) continue;
        $sections[] = [
            'title' => $title,
            'icon'  => $icon,
            'items' => $items,
        ];
    }

    $bullets = array_values(array_filter(array_map('strval', (array)($parsed['bullets'] ?? []))));

    return [
        'ok'       => true,
        'headline' => (string)($parsed['headline'] ?? ''),
        'summary'  => (string)$parsed['summary'],
        'sections' => $sections,
        'bullets'  => $bullets,
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
