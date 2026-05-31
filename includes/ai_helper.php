<?php
/**
 * AI helper for article + Telegram summarization.
 *
 * All provider-specific HTTP lives in includes/ai_provider.php; this
 * file just builds prompts, schemas, and handles response shaping.
 */

require_once __DIR__ . '/ai_provider.php';

function ai_summarize_article($title, $content, $maxTokens = 500) {
    $prompt = "أنت محرر أخبار محترف. لخّص الخبر التالي بالعربية بأسلوب صحفي محايد.\n\n"
            . "العنوان: $title\n\n"
            . "النص:\n" . mb_substr(strip_tags($content), 0, 6000) . "\n\n"
            . "أعطني رداً بصيغة JSON فقط (بدون أي شرح إضافي) بالشكل:\n"
            . '{"summary":"ملخص في 3-4 جمل","key_points":["نقطة 1","نقطة 2","نقطة 3"],"keywords":["كلمة1","كلمة2","كلمة3"]}';

    $call = ai_provider_text_call($prompt, (int)$maxTokens);
    if (empty($call['ok'])) {
        return ['ok' => false, 'error' => (string)($call['error'] ?? 'AI call failed')];
    }
    $text = (string)$call['text'];

    // Extract JSON from response (the prompt asks for raw JSON but
    // models sometimes wrap it in prose or markdown fences).
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

    // Force structured output via the provider-agnostic abstraction.
    // Both Anthropic tool_use and Gemini functionCall return the tool
    // `input` as a ready-to-use associative array — no JSON parsing,
    // no stray prose, no markdown fences.
    $call = ai_provider_tool_call($prompt, $tool, $maxTokens);
    if (empty($call['ok'])) {
        return ['ok' => false, 'error' => (string)($call['error'] ?? 'تعذّر توليد الملخص.')];
    }
    $parsed = $call['input'];
    if (!is_array($parsed) || empty($parsed['summary'])) {
        error_log('[tg_summary] parsed missing summary: ' . mb_substr(json_encode($parsed, JSON_UNESCAPED_UNICODE), 0, 1500));
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

/**
 * Comprehensive daily news summary — called once at noon.
 *
 * Uses the same tool-use approach as ai_summarize_telegram() but with
 * a wider message window (24 h), a richer editorial prompt, and more
 * sections (up to 8). The output is saved to the same
 * telegram_summaries table so the UI picks it up transparently.
 */
function ai_summarize_telegram_daily(array $messages, int $maxTokens = 5000): array {
    if (!$messages) {
        return ['ok' => false, 'error' => 'لا توجد رسائل للتلخيص'];
    }

    // Palestinian focus regex — mirrors sabah.php. Used to bubble
    // Palestinian messages to the front of the corpus so they fit
    // even when the AI hits its budget. Also drives the 🇵🇸 tag the
    // AI uses to prioritize sections.
    $paleRegex = '/فلسطين|غزة|الضفة|القدس|الأقصى|قبة الصخرة|'
               . 'الأسرى|الأسير|الأسيرات|أسير|معتقل|اعتقال|نادي الأسير|'
               . 'مستوطن|مستوطنين|الاستيطان|استيطان|بؤرة استيطانية|'
               . 'الاحتلال|الجيش الإسرائيلي|إسرائيل|نتنياهو|'
               . 'رفح|خان يونس|جباليا|الشجاعية|دير البلح|النصيرات|المغازي|البريج|شمال غزة|جنوب غزة|معبر رفح|'
               . 'نابلس|جنين|رام الله|الخليل|طولكرم|قلقيلية|بيت لحم|أريحا|سلفيت|طوباس|بيتا|حوارة|'
               . 'حزب الله|جنوب لبنان|الحوثي|اليمن|'
               . 'حماس|الجهاد الإسلامي|الفصائل|كتائب القسام|سرايا القدس|'
               . 'شهيد|شهداء|استشهاد|عدوان|قصف|اقتحام|مجزرة|إبادة/u';

    // Two-pass corpus build: Palestinian-relevant messages first so they
    // never get truncated by the 60K char budget, then everything else.
    $paleLines = [];
    $otherLines = [];
    foreach ($messages as $m) {
        $text = trim((string)($m['text'] ?? ''));
        if ($text === '') continue;
        $text = preg_replace('/\s+/u', ' ', $text);
        if (mb_strlen($text) > 400) $text = mb_substr($text, 0, 400) . '…';
        $handle = '@' . ($m['username'] ?? 'tg');
        $when   = !empty($m['posted_at']) ? date('H:i', strtotime($m['posted_at'])) : '';
        $isPale = (bool)preg_match($paleRegex, $text);
        $marker = $isPale ? '🇵🇸 ' : '';
        $line   = '- ' . $marker . '[' . $handle . ' ' . $when . '] ' . $text;
        if ($isPale) $paleLines[] = $line;
        else         $otherLines[] = $line;
    }

    // Pack: 80% of budget reserved for Palestinian content.
    $totalBudget = 60000;
    $paleBudget  = (int)($totalBudget * 0.80);
    $otherBudget = $totalBudget - $paleBudget;

    $packed = [];
    $used = 0;
    foreach ($paleLines as $l) {
        $len = mb_strlen($l) + 1;
        if ($used + $len > $paleBudget) break;
        $packed[] = $l; $used += $len;
    }
    $afterPaleUsed = $used;
    foreach ($otherLines as $l) {
        $len = mb_strlen($l) + 1;
        if ($used - $afterPaleUsed + $len > $otherBudget) break;
        if ($used + $len > $totalBudget) break;
        $packed[] = $l; $used += $len;
    }

    if (!$packed) {
        return ['ok' => false, 'error' => 'لا توجد رسائل نصية كافية للتلخيص'];
    }

    $corpus = implode("\n", $packed);
    $count  = count($packed);
    $paleCount = count(array_filter($packed, fn($l) => strpos($l, '🇵🇸') !== false));

    $prompt = "أنت رئيس تحرير كبير في غرفة أخبار عربية متخصصة بالشأن الفلسطيني والعربي.\n\n"
            . "لديك {$count} رسالة من قنوات تيليغرام إخبارية خلال آخر 24 ساعة، منها **{$paleCount} رسالة فلسطينية** "
            . "(معلّمة بـ 🇵🇸). مهمتك: إعداد التقرير اليومي الختامي يُبث الساعة 10 مساءً بتوقيت القدس.\n\n"
            . "**التركيز التحريري** (مهم جداً):\n"
            . "- **80% على الأقل من المحتوى فلسطيني**: غزة، الضفة، القدس، الأقصى، الأسرى، الاستيطان، الاعتداءات، شهداء، اعتقالات.\n"
            . "- 4-5 محاور فلسطينية من أصل 5-7 محاور.\n"
            . "- الباقي (1-2 محور): إقليمي مرتبط (لبنان، اليمن، إيران، الموقف الدولي).\n\n"
            . "**قاعدة عدم التكرار (الأهم)**:\n"
            . "- نفس الخبر يصل من 5-10 قنوات مختلفة بصياغات متشابهة. **اجمعها كلها في عنصر واحد**.\n"
            . "- استخدم الرواية الأشمل وأضف أي تفصيلة فريدة من القنوات الأخرى.\n"
            . "- لو في خبر متطوّر خلال اليوم (مثلاً: استشهاد → جنازة → تشييع)، اعرضه ك**خط زمني** في عنصر واحد.\n\n"
            . "قواعد التحرير:\n"
            . "- لغة عربية فصحى راقية، نبرة صحفية رسمية.\n"
            . "- تجاهل الإعلانات وروابط الاشتراك والرسائل غير الإخبارية.\n"
            . "- استخدم تسميات دقيقة: \"الشهداء\" مش \"القتلى\"، \"الاحتلال\" مش \"إسرائيل\" إلا في السياق الرسمي.\n"
            . "- لا تخترع معلومات غير موجودة.\n"
            . "- كل تفصيلة جملة كاملة بسياق + أرقام + أماكن.\n\n"
            . "البنية المطلوبة:\n\n"
            . "1. **headline**: عنوان رئيسي (60-90 حرفاً) يجمع 2-3 محاور — يبدأ غالباً بأهم محور فلسطيني.\n\n"
            . "2. **subheadline**: عنوان فرعي (80-130 حرفاً) يوضّح الخيط الرابط.\n\n"
            . "3. **summary**: فقرة افتتاحية شاملة (6-8 جمل، 120-200 كلمة) تعطي المشهد العام لأخبار اليوم، تبدأ بأبرز محور فلسطيني وتنتقل عبر المحاور.\n\n"
            . "4. **sections** (5-7 محاور): كل محور حدث/ملف معمّق.\n"
            . "   - title: عنوان واضح\n"
            . "   - icon: emoji واحد (🇵🇸 للفلسطيني المحض، ⚡ سياسة، 🛡️ مقاومة، ⚖️ أسرى، 🏛️ موقف دولي، إلخ)\n"
            . "   - items: 4-7 تفاصيل إخبارية، **مجموعة من رسائل متعددة بدون تكرار**\n"
            . "   - why_matters: جملة قوية (15-30 كلمة) تجيب لماذا هذا الملف مهم اليوم\n\n"
            . "5. **key_numbers** (4-7): أرقام يوم بارزة. **يجب تشمل أرقام فلسطينية** (شهداء، جرحى، اعتقالات، مقتحمي الأقصى).\n"
            . "   - value: الرقم (\"23 شهيداً\", \"450 معتقلاً\")\n"
            . "   - context: شرح موجز\n\n"
            . "6. **regions** (3-5): مناطق محورية اليوم (تشمل مدن فلسطينية محددة).\n\n"
            . "7. **topics** (5-8 وسوم قصيرة بدون #).\n\n"
            . "الرسائل (المعلّمة بـ 🇵🇸 لها أولوية تحريرية قصوى):\n" . $corpus;

    $tool = [
        'name'        => 'submit_news_briefing',
        'description' => 'Submit the comprehensive Palestinian-focused daily news summary.',
        'input_schema' => [
            'type'     => 'object',
            'properties' => [
                'headline' => [
                    'type'        => 'string',
                    'description' => 'عنوان 60-90 حرفاً يجمع 2-3 محاور.',
                ],
                'subheadline' => [
                    'type'        => 'string',
                    'description' => 'عنوان فرعي 80-130 حرفاً.',
                ],
                'summary' => [
                    'type'        => 'string',
                    'description' => 'فقرة افتتاحية 6-8 جمل (120-200 كلمة).',
                ],
                'sections' => [
                    'type'        => 'array',
                    'description' => '5-7 محاور، 4-5 منها فلسطينية على الأقل.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'icon'  => ['type' => 'string', 'description' => 'emoji واحد'],
                            'items' => [
                                'type' => 'array',
                                'description' => '4-7 تفاصيل إخبارية مجموعة من رسائل متعددة بدون تكرار',
                                'items' => ['type' => 'string'],
                            ],
                            'why_matters' => [
                                'type' => 'string',
                                'description' => 'لماذا هذا الملف مهم اليوم - جملة قوية',
                            ],
                        ],
                        'required' => ['title', 'items'],
                    ],
                ],
                'key_numbers' => [
                    'type'        => 'array',
                    'description' => '4-7 أرقام بارزة، تشمل فلسطينية.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'value'   => ['type' => 'string'],
                            'context' => ['type' => 'string'],
                        ],
                        'required' => ['value', 'context'],
                    ],
                ],
                'regions' => [
                    'type'        => 'array',
                    'description' => '3-5 مناطق جغرافية.',
                    'items' => ['type' => 'string'],
                ],
                'topics' => [
                    'type'  => 'array',
                    'description' => '5-8 وسوم قصيرة بدون #.',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['headline', 'summary', 'sections', 'topics'],
        ],
    ];

    $call = ai_provider_tool_call($prompt, $tool, $maxTokens);
    if (empty($call['ok'])) {
        return ['ok' => false, 'error' => (string)($call['error'] ?? 'تعذّر توليد الملخص اليومي.')];
    }
    $parsed = $call['input'];
    if (!is_array($parsed) || empty($parsed['summary'])) {
        return ['ok' => false, 'error' => 'تعذّر توليد الملخص اليومي.'];
    }

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
            'title'       => $title,
            'icon'        => $icon,
            'items'       => $items,
            'why_matters' => trim((string)($sec['why_matters'] ?? '')),
        ];
    }

    // Clean + cap key_numbers / regions.
    $keyNumbers = [];
    foreach ((array)($parsed['key_numbers'] ?? []) as $n) {
        if (!is_array($n)) continue;
        $v = trim((string)($n['value']   ?? ''));
        $c = trim((string)($n['context'] ?? ''));
        if ($v === '' || $c === '') continue;
        $keyNumbers[] = ['value' => $v, 'context' => $c];
        if (count($keyNumbers) >= 7) break;
    }
    $regions = [];
    foreach ((array)($parsed['regions'] ?? []) as $r) {
        $r = trim((string)$r);
        if ($r !== '' && !in_array($r, $regions, true)) $regions[] = $r;
        if (count($regions) >= 5) break;
    }

    return [
        'ok'          => true,
        'headline'    => (string)($parsed['headline'] ?? ''),
        'subheadline' => (string)($parsed['subheadline'] ?? ''),
        'summary'     => (string)$parsed['summary'],
        'sections'    => $sections,
        'bullets'     => [],
        'key_numbers' => $keyNumbers,
        'regions'     => $regions,
        'topics'      => array_values(array_filter(array_map('strval', (array)($parsed['topics'] ?? [])))),
    ];
}

/**
 * Persistent store for generated Telegram news briefings.
 *
 * The live summary card on /telegram.php used to call Claude on every
 * click (cached for 30 minutes). To cut cost, briefings are now
 * generated once per hour by cron_tg_summary.php and saved here;
 * telegram_summary.php just reads from this table.
 */
function tg_summary_ensure_table(): void {
    static $ensured = false;
    if ($ensured) return;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS telegram_summaries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            headline VARCHAR(300) NOT NULL DEFAULT '',
            summary TEXT NOT NULL,
            sections LONGTEXT,
            topics TEXT,
            window_mins SMALLINT UNSIGNED NOT NULL DEFAULT 60,
            message_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_generated_at (generated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Lazy ALTER for v2 fields (subheadline, key_numbers, regions) —
        // matches the sabah_briefings pattern.
        $cols = [
            'subheadline' => "ADD COLUMN subheadline VARCHAR(400) NOT NULL DEFAULT ''",
            'key_numbers' => "ADD COLUMN key_numbers TEXT NULL",
            'regions'     => "ADD COLUMN regions TEXT NULL",
        ];
        foreach ($cols as $col => $ddl) {
            try {
                $exists = $db->query("SHOW COLUMNS FROM telegram_summaries LIKE '" . $col . "'")->fetch();
                if (!$exists) $db->exec("ALTER TABLE telegram_summaries " . $ddl);
            } catch (Throwable $e) {}
        }
        $ensured = true;
    } catch (Throwable $e) {
        // Let callers fail loudly on their own query; we don't want
        // migration errors to crash an unrelated page.
    }
}

/** Insert a generated briefing and return the new row id. */
function tg_summary_save(array $ai, int $messageCount, int $windowMins = 60): ?int {
    if (empty($ai['ok'])) return null;
    tg_summary_ensure_table();
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO telegram_summaries
        (headline, subheadline, summary, sections, topics, key_numbers, regions, window_mins, message_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ok = $stmt->execute([
        (string)($ai['headline']    ?? ''),
        (string)($ai['subheadline'] ?? ''),
        (string)($ai['summary']     ?? ''),
        json_encode($ai['sections']    ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($ai['topics']      ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($ai['key_numbers'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($ai['regions']     ?? [], JSON_UNESCAPED_UNICODE),
        $windowMins,
        $messageCount,
    ]);
    return $ok ? (int)$db->lastInsertId() : null;
}

/** Keep only the most recent N briefings to bound disk use. */
function tg_summary_prune(int $keep = 48): void {
    tg_summary_ensure_table();
    $keep = max(1, min(500, $keep));
    try {
        $db = getDB();
        $db->exec("DELETE FROM telegram_summaries
                    WHERE id NOT IN (
                        SELECT id FROM (
                            SELECT id FROM telegram_summaries
                            ORDER BY generated_at DESC, id DESC
                            LIMIT {$keep}
                        ) keep_rows
                    )");
    } catch (Throwable $e) {}
}

/**
 * Format a MySQL TIMESTAMP epoch as ISO 8601 with the server's
 * timezone offset, e.g. "2026-04-09T12:00:00+03:00". Frontend code
 * can pass this straight to `new Date()` and get correct local-time
 * rendering across browsers — without it, Chrome and Safari disagree
 * on how to interpret a bare "YYYY-MM-DD HH:MM:SS" string.
 */
function tg_summary_format_ts($epoch): string {
    $ts = is_numeric($epoch) ? (int)$epoch : (int)strtotime((string)$epoch);
    if ($ts <= 0) return '';
    return date('c', $ts);
}

/** Decode a stored row into the shape the frontend expects. */
function tg_summary_hydrate(array $row): array {
    $sections   = json_decode((string)($row['sections']    ?? '[]'), true);
    $topics     = json_decode((string)($row['topics']      ?? '[]'), true);
    $keyNumbers = json_decode((string)($row['key_numbers'] ?? '[]'), true);
    $regions    = json_decode((string)($row['regions']     ?? '[]'), true);
    // Prefer the unambiguous UNIX_TIMESTAMP value when present (set by
    // the SELECTs below); fall back to parsing the raw TIMESTAMP string
    // through PHP's local timezone, which is set to TIMEZONE in config.
    $tsSource = $row['generated_at_unix'] ?? $row['generated_at'] ?? null;
    return [
        'id'            => (int)$row['id'],
        'headline'      => (string)$row['headline'],
        'subheadline'   => (string)($row['subheadline'] ?? ''),
        'summary'       => (string)$row['summary'],
        'sections'      => is_array($sections)   ? $sections   : [],
        'topics'        => is_array($topics)     ? $topics     : [],
        'key_numbers'   => is_array($keyNumbers) ? $keyNumbers : [],
        'regions'       => is_array($regions)    ? $regions    : [],
        'window_mins'   => (int)$row['window_mins'],
        'message_count' => (int)$row['message_count'],
        'generated_at'  => tg_summary_format_ts($tsSource),
    ];
}

/** Most recently generated briefing, or null if none exist yet. */
function tg_summary_get_latest(): ?array {
    tg_summary_ensure_table();
    try {
        $db = getDB();
        $row = $db->query("SELECT *, UNIX_TIMESTAMP(generated_at) AS generated_at_unix
                             FROM telegram_summaries
                            ORDER BY generated_at DESC, id DESC LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC);
        return $row ? tg_summary_hydrate($row) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Fetch a specific briefing by id, or null if missing. */
function tg_summary_get_by_id(int $id): ?array {
    tg_summary_ensure_table();
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT *, UNIX_TIMESTAMP(generated_at) AS generated_at_unix
                                FROM telegram_summaries WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? tg_summary_hydrate($row) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Lightweight list of recent briefings for the archive pill bar. */
function tg_summary_list(int $limit = 24): array {
    tg_summary_ensure_table();
    $limit = max(1, min(100, $limit));
    try {
        $db = getDB();
        $rows = $db->query("SELECT id, headline,
                                   UNIX_TIMESTAMP(generated_at) AS generated_at_unix,
                                   message_count, window_mins
                             FROM telegram_summaries
                             ORDER BY generated_at DESC, id DESC
                             LIMIT {$limit}")
                    ->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        // Normalize the timestamp the same way hydrate does so the
        // archive pills agree with the main panel.
        foreach ($rows as &$r) {
            $r['generated_at'] = tg_summary_format_ts($r['generated_at_unix'] ?? null);
            unset($r['generated_at_unix']);
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Pull the Telegram messages that will feed one briefing.
 * Shared between the cron and the one-time seed path.
 */
function tg_summary_collect_messages(int $windowMins = 60, int $maxMsgs = 250): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT m.id, m.text, m.posted_at, s.username, s.display_name
                          FROM telegram_messages m
                          JOIN telegram_sources s ON m.source_id = s.id
                          WHERE m.is_active = 1
                            AND s.is_active = 1
                            AND m.text IS NOT NULL
                            AND m.text <> ''
                            AND m.posted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                          ORDER BY m.posted_at DESC, m.id DESC
                          LIMIT " . (int)$maxMsgs);
    $stmt->execute([$windowMins]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If the window is quiet, widen to the most recent N messages so
    // the briefing always has something to work with.
    if (count($messages) < 3) {
        $stmt = $db->query("SELECT m.id, m.text, m.posted_at, s.username, s.display_name
                            FROM telegram_messages m
                            JOIN telegram_sources s ON m.source_id = s.id
                            WHERE m.is_active = 1
                              AND s.is_active = 1
                              AND m.text IS NOT NULL
                              AND m.text <> ''
                            ORDER BY m.posted_at DESC, m.id DESC
                            LIMIT " . (int)$maxMsgs);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $messages;
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
