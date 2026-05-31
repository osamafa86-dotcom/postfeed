<?php
/**
 * نيوز فيد — موجز الصباح (Morning Briefing)
 *
 * NYT "The Morning"-inspired daily editorial briefing: one lead essay
 * + thematic sections + a closing question. Generated once daily from
 * the top clusters (by source count × velocity) and stored for
 * permanent archiving at /sabah/YYYY-MM-DD.
 *
 * Unlike the hourly Telegram summary (ephemeral, section-only), the
 * morning briefing has a narrative voice with a hook paragraph, named
 * sections, and a closing provocative question. It's also a standalone
 * page, not a JSON endpoint.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/ai_provider.php';

function sabah_ensure_table(): void {
    static $done = false;
    if ($done) return;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS sabah_briefings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            briefing_date DATE NOT NULL UNIQUE,
            headline VARCHAR(300) NOT NULL DEFAULT '',
            hook TEXT NOT NULL,
            sections TEXT,
            closing_question VARCHAR(500) NOT NULL DEFAULT '',
            article_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_date (briefing_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Lazy ALTER for the v2 fields — keeps existing installs working
        // without a separate migration step.
        $cols = [
            'subheadline'  => "ADD COLUMN subheadline VARCHAR(400) NOT NULL DEFAULT ''",
            'key_numbers'  => "ADD COLUMN key_numbers TEXT NULL",
            'regions'      => "ADD COLUMN regions TEXT NULL",
            'quote_of_day' => "ADD COLUMN quote_of_day TEXT NULL",
        ];
        foreach ($cols as $col => $ddl) {
            try {
                $exists = $db->query("SHOW COLUMNS FROM sabah_briefings LIKE '" . $col . "'")->fetch();
                if (!$exists) $db->exec("ALTER TABLE sabah_briefings " . $ddl);
            } catch (Throwable $e) {}
        }
        $done = true;
    } catch (Throwable $e) {
        error_log('[sabah] ensure_table: ' . $e->getMessage());
    }
}

/**
 * Pick the top clusters from the last 24 hours, ranked by distinct
 * source count, then by most recent article. Returns a corpus string
 * suitable for the prompt.
 *
 * Two-pass fallback: prefer 2+ source clusters (real news consensus),
 * but on quiet days (Fridays, public holidays) accept single-source
 * clusters too so the briefing still publishes. Previously the strict
 * `src_count >= 2` cutoff silently skipped generation whenever the
 * news flow slowed and the morning page just 404'd.
 */
function sabah_collect_top_clusters(int $maxClusters = 18): array {
    try {
        $db = getDB();
        $limitSql = max(1, min(30, $maxClusters));

        // Palestinian-focus keyword set. Clusters whose title/excerpt
        // mention any of these get scored higher and surface first in the
        // briefing — the editorial priority is wall-to-wall coverage of
        // Palestine and the occupation. The regex matches anywhere in
        // the text, no word-boundary check needed for Arabic.
        $paleRegex = 'فلسطين|غزة|الضفة|الضفة الغربية|القدس|الأقصى|المسجد الأقصى|قبة الصخرة|'
                   . 'الأسرى|الأسير|الأسيرات|أسير|أسيرات|معتقل|اعتقال|اعتقالات|نادي الأسير|'
                   . 'مستوطن|مستوطنين|الاستيطان|استيطان|مستوطنة|مستوطنات|بؤرة استيطانية|اعتداءات المستوطنين|'
                   . 'الاحتلال|الجيش الإسرائيلي|إسرائيل|إسرائيلي|إسرائيلية|نتنياهو|الكنيست|'
                   . 'رفح|خان يونس|بيت حانون|بيت لاهيا|جباليا|الشجاعية|دير البلح|النصيرات|المغازي|البريج|مخيم الشاطئ|شمال غزة|جنوب غزة|معبر رفح|كرم أبو سالم|'
                   . 'نابلس|جنين|مخيم جنين|رام الله|الخليل|طولكرم|قلقيلية|بيت لحم|أريحا|سلفيت|طوباس|بيتا|حوارة|مخيم بلاطة|مخيم نور شمس|'
                   . 'حزب الله|جنوب لبنان|الحوثي|الحوثيين|اليمن|صنعاء|'
                   . 'حماس|الجهاد الإسلامي|الفصائل|كتائب القسام|سرايا القدس|'
                   . 'شهيد|شهداء|استشهاد|عدوان|قصف|اقتحام|مجزرة|إبادة';

        // First pass: multi-source clusters, ranked by Palestine score first.
        $sql = "SELECT a.cluster_key,
                       COUNT(DISTINCT a.source_id) AS src_count,
                       COUNT(*) AS art_count,
                       MAX(a.published_at) AS latest_at,
                       GROUP_CONCAT(DISTINCT s.name SEPARATOR '، ') AS source_names,
                       SUM(CASE WHEN (a.title REGEXP ? OR a.excerpt REGEXP ?)
                                THEN 1 ELSE 0 END) AS pale_score
                  FROM articles a
                  LEFT JOIN sources s ON a.source_id = s.id
                 WHERE a.status = 'published'
                   AND a.cluster_key IS NOT NULL AND a.cluster_key <> '-'
                   AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 GROUP BY a.cluster_key
                HAVING src_count >= 2
                 ORDER BY pale_score DESC, src_count DESC, latest_at DESC
                 LIMIT {$limitSql}";
        $stmt = $db->prepare($sql);
        $stmt->execute([$paleRegex, $paleRegex]);
        $clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: include single-source clusters too if we're short.
        if (count($clusters) < 6) {
            $sqlFallback = "SELECT a.cluster_key,
                                   COUNT(DISTINCT a.source_id) AS src_count,
                                   COUNT(*) AS art_count,
                                   MAX(a.published_at) AS latest_at,
                                   MAX(s.name) AS source_names,
                                   SUM(CASE WHEN (a.title REGEXP ? OR a.excerpt REGEXP ?)
                                            THEN 1 ELSE 0 END) AS pale_score
                              FROM articles a
                              LEFT JOIN sources s ON a.source_id = s.id
                             WHERE a.status = 'published'
                               AND a.cluster_key IS NOT NULL AND a.cluster_key <> '-'
                               AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                             GROUP BY a.cluster_key
                             ORDER BY pale_score DESC, art_count DESC, latest_at DESC
                             LIMIT {$limitSql}";
            $stmt = $db->prepare($sqlFallback);
            $stmt->execute([$paleRegex, $paleRegex]);
            $clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log('[sabah] using fallback ranking — got ' . count($clusters) . ' clusters');
        }

        if (empty($clusters)) return ['corpus' => '', 'count' => 0, 'article_count' => 0];

        $lines = [];
        $totalArticles = 0;
        // Bigger budget — we now pack up to ~75 articles per briefing.
        $budget = 60000;
        $used = 0;
        foreach ($clusters as $idx => $cl) {
            $ck = $cl['cluster_key'];
            $label = 'C' . ($idx + 1);
            $paleScore = (int)($cl['pale_score'] ?? 0);
            // Pull 5 representative articles per cluster (was 3).
            $stmt = $db->prepare(
                "SELECT a.title, a.ai_summary, a.excerpt, s.name AS source_name
                   FROM articles a
                   LEFT JOIN sources s ON a.source_id = s.id
                  WHERE a.cluster_key = ? AND a.status = 'published'
                  ORDER BY (a.ai_summary IS NOT NULL) DESC, LENGTH(a.title) DESC
                  LIMIT 5"
            );
            $stmt->execute([$ck]);
            $arts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Mark Palestinian-focus clusters so the AI prioritizes them.
            $tag = $paleScore > 0 ? ' 🇵🇸' : '';
            $block = "[{$label}{$tag}] ({$cl['src_count']} مصادر، {$cl['art_count']} مقال: {$cl['source_names']})\n";
            foreach ($arts as $art) {
                $summary = trim(strip_tags((string)($art['ai_summary'] ?? $art['excerpt'] ?? '')));
                if (mb_strlen($summary) > 350) $summary = mb_substr($summary, 0, 350) . '…';
                $block .= "  - [{$art['source_name']}] {$art['title']}\n    {$summary}\n";
            }
            $len = mb_strlen($block);
            if ($used + $len > $budget) break;
            $lines[] = $block;
            $used += $len + 1;
            $totalArticles += (int)$cl['art_count'];
        }

        return [
            'corpus'        => implode("\n", $lines),
            'count'         => count($lines),
            'article_count' => $totalArticles,
        ];
    } catch (Throwable $e) {
        error_log('[sabah] collect: ' . $e->getMessage());
        return ['corpus' => '', 'count' => 0, 'article_count' => 0];
    }
}

/**
 * Generate a morning briefing via Gemini.
 */
function sabah_generate(): ?array {
    $data = sabah_collect_top_clusters(18);
    if ($data['count'] < 1) {
        error_log('[sabah] no clusters available in the last 24h — skipping generation');
        return null;
    }

    $prompt = "أنت رئيس تحرير نشرة \"صباح فيد نيوز\" — تقرير صباحي يومي بأسلوب NYT The Morning + Reuters Daily Briefing، "
            . "ولكن مع تخصص واضح في القضية الفلسطينية.\n\n"
            . "لديك {$data['count']} ملفات إخبارية بارزة من آخر 24 ساعة (إجمالي {$data['article_count']} خبر مجمّعة). "
            . "الملفات المعلّمة بـ 🇵🇸 لها أولوية تحريرية قصوى.\n\n"
            . "**التركيز التحريري** (مهم جداً):\n"
            . "- القضية الفلسطينية هي القلب: غزة، الضفة، القدس، الأقصى، الأسرى، الاستيطان، الاعتداءات، شهداء، اعتقالات.\n"
            . "- خصّص **70% على الأقل من الأقسام** للمحاور الفلسطينية (4-5 من 6-8 أقسام).\n"
            . "- الباقي للأخبار العالمية/الإقليمية المرتبطة (إيران، حزب الله، اليمن، الموقف الدولي).\n"
            . "- لو في يوم بدون أخبار فلسطينية كافية (نادر)، وسّع للأخبار العربية ثم العالمية.\n\n"
            . "اكتب بالعربية الفصحى الراقية، أسلوب صحفي تحريري كأنك تكتب لقارئ مثقف يتابع التفاصيل اليومية.\n\n"
            . "البنية المطلوبة:\n\n"
            . "1. **headline**: عنوان رئيسي (60-90 حرفاً) يجمع 2-3 محاور — تبدأ غالباً بأهم محور فلسطيني.\n\n"
            . "2. **subheadline**: عنوان فرعي (80-130 حرفاً) يوضّح الخيط الرابط.\n\n"
            . "3. **hook**: فقرة افتتاحية (5-7 جمل، 100-180 كلمة). تبدأ بأقوى محور فلسطيني، تربط بالمحاور الأخرى، "
            . "تعطي القارئ خلاصة \"شو صار اليوم\".\n\n"
            . "4. **sections** (6-8 أقسام): كل قسم محور إخباري معمّق.\n"
            . "   - **4-5 أقسام على الأقل فلسطينية محضة** (مثلاً: غزة، الضفة، الأسرى، الاستيطان، الأقصى، اعتقالات).\n"
            . "   - **1-2 قسم إقليمي/دولي مرتبط** (لبنان، اليمن، إيران، الموقف الأوروبي/الأمريكي).\n"
            . "   - **0-1 قسم عالمي خارجي** (لو في خبر مهم بحق، مثل زلزال أو حدث استثنائي).\n\n"
            . "   لكل قسم:\n"
            . "   - title: عنوان جذاب (40-80 حرفاً)\n"
            . "   - icon: emoji واحد يعبّر عن الموضوع (🇵🇸 للفلسطيني، 🏛️ سياسة، إلخ)\n"
            . "   - body: فقرة سردية معمّقة (5-8 جمل، 100-220 كلمة). اذكر الأرقام، الأسماء، الأماكن، التطورات.\n"
            . "   - why_matters: جملة قوية تجيب \"لماذا هذا الخبر مهم؟\" (20-40 كلمة)\n"
            . "   - tags: مصفوفة 3-5 كلمات مفتاحية\n\n"
            . "5. **key_numbers**: مصفوفة من 4-7 أرقام/إحصائيات بارزة، **يجب أن تشمل أرقام فلسطينية** (شهداء، جرحى، اعتقالات، مقتحمي الأقصى، وحدات استيطانية، إلخ).\n"
            . "   - value: الرقم نفسه (\"23 شهيداً\", \"450 معتقلاً\", \"3 آلاف وحدة استيطانية\")\n"
            . "   - context: شرح موجز (\"حصيلة عدوان غزة منذ الفجر\", \"اعتقالات الضفة هذا الأسبوع\")\n\n"
            . "6. **regions**: مصفوفة 3-5 مناطق جغرافية محورية. يجب أن تتضمن مناطق فلسطينية محددة (غزة، نابلس، رام الله، القدس، الخليل، الأقصى) قدر الإمكان.\n\n"
            . "7. **quote_of_day**: اقتباس قوي (يُفضّل من شخصية فلسطينية، مسؤول، شاهد عيان، أو محلل).\n"
            . "   - text: نص الاقتباس\n"
            . "   - speaker: من قاله + صفته\n"
            . "   - context: متى وأين بإيجاز\n"
            . "   (null لو لا يوجد اقتباس قوي)\n\n"
            . "8. **closing_question**: سؤال ختامي عميق يحفّز التفكير في القضية أو في المشهد الإقليمي.\n\n"
            . "قواعد صارمة:\n"
            . "- لا تخترع أي معلومة غير موجودة في الملفات.\n"
            . "- استخدم أسماء الأماكن والأشخاص بدقة كما وردت.\n"
            . "- أعطِ الأولوية للملفات المعلّمة بـ 🇵🇸 + الملفات اللي تغطيها عدة مصادر.\n"
            . "- تجنّب الكليشيهات والتعبيرات المستهلكة.\n"
            . "- استخدم تسميات دقيقة: \"الشهداء\" مش \"القتلى\", \"الاحتلال\" مش \"إسرائيل\" (إلا في السياق الرسمي).\n\n"
            . "الملفات الإخبارية:\n" . $data['corpus'];

    $tool = [
        'name'        => 'submit_sabah_briefing',
        'description' => 'Submit the comprehensive morning editorial briefing.',
        'input_schema' => [
            'type'     => 'object',
            'properties' => [
                'headline' => [
                    'type'        => 'string',
                    'description' => 'عنوان رئيسي 60-80 حرفاً يجمع 2-3 محاور.',
                ],
                'subheadline' => [
                    'type'        => 'string',
                    'description' => 'عنوان فرعي 80-120 حرفاً يوضح الخيط الرابط.',
                ],
                'hook' => [
                    'type'        => 'string',
                    'description' => 'فقرة افتتاحية 4-6 جمل (80-150 كلمة).',
                ],
                'sections' => [
                    'type'        => 'array',
                    'description' => '6-8 أقسام إخبارية معمّقة، 4-5 على الأقل فلسطينية.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'icon'  => ['type' => 'string', 'description' => 'emoji واحد'],
                            'body'  => ['type' => 'string', 'description' => 'فقرة سردية 5-8 جمل (100-220 كلمة)'],
                            'why_matters' => ['type' => 'string', 'description' => 'لماذا هذا الخبر مهم - جملة قوية'],
                            'tags' => [
                                'type' => 'array',
                                'description' => '3-5 كلمات مفتاحية',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['title', 'body', 'why_matters'],
                    ],
                ],
                'key_numbers' => [
                    'type'        => 'array',
                    'description' => '4-7 أرقام/إحصائيات بارزة (يجب تشمل أرقام فلسطينية).',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'value'   => ['type' => 'string', 'description' => 'الرقم/الإحصائية'],
                            'context' => ['type' => 'string', 'description' => 'شرح موجز'],
                        ],
                        'required' => ['value', 'context'],
                    ],
                ],
                'regions' => [
                    'type'        => 'array',
                    'description' => '3-5 مناطق جغرافية محورية (تشمل فلسطينية محددة).',
                    'items' => ['type' => 'string'],
                ],
                'quote_of_day' => [
                    'type'        => 'object',
                    'description' => 'اقتباس بارز - أو null لو لا يوجد.',
                    'properties' => [
                        'text'    => ['type' => 'string'],
                        'speaker' => ['type' => 'string'],
                        'context' => ['type' => 'string'],
                    ],
                ],
                'closing_question' => [
                    'type'        => 'string',
                    'description' => 'سؤال ختامي عميق.',
                ],
            ],
            'required' => ['headline', 'hook', 'sections', 'closing_question'],
        ],
    ];

    // 8000 output tokens — accommodates 6-8 longer sections + the v2
    // metadata (key_numbers, regions, quote_of_day, why_matters per section).
    $call = ai_provider_tool_call($prompt, $tool, 8000);
    if (empty($call['ok']) || !is_array($call['input'])) {
        $GLOBALS['_sabah_last_error'] = 'AI: ' . ($call['error'] ?? 'unknown');
        error_log('[sabah] AI failed: ' . ($call['error'] ?? ''));
        // Don't return null — the AI free tier (Gemini 20 req/day after
        // the Dec-2025 cuts) is regularly exhausted, and a missing
        // morning briefing makes the home-screen card look broken.
        // Build a no-AI briefing straight from the clustered articles
        // instead. It's less narrative, but it ships every day.
        return sabah_build_without_ai();
    }

    $p = $call['input'];
    $sections = [];
    foreach ((array)($p['sections'] ?? []) as $sec) {
        if (!is_array($sec)) continue;
        $title = trim((string)($sec['title'] ?? ''));
        $body  = trim((string)($sec['body']  ?? ''));
        if ($title === '' || $body === '') continue;
        $tags = [];
        foreach ((array)($sec['tags'] ?? []) as $t) {
            $t = trim((string)$t);
            if ($t !== '') $tags[] = $t;
        }
        $sections[] = [
            'title'       => $title,
            'icon'        => trim((string)($sec['icon'] ?? '')),
            'body'        => $body,
            'why_matters' => trim((string)($sec['why_matters'] ?? '')),
            'tags'        => $tags,
        ];
    }

    // Key numbers — clean + cap at 5 entries.
    $keyNumbers = [];
    foreach ((array)($p['key_numbers'] ?? []) as $n) {
        if (!is_array($n)) continue;
        $value   = trim((string)($n['value']   ?? ''));
        $context = trim((string)($n['context'] ?? ''));
        if ($value === '' || $context === '') continue;
        $keyNumbers[] = ['value' => $value, 'context' => $context];
        if (count($keyNumbers) >= 7) break;
    }

    // Regions — dedupe + cap at 5.
    $regions = [];
    foreach ((array)($p['regions'] ?? []) as $r) {
        $r = trim((string)$r);
        if ($r !== '' && !in_array($r, $regions, true)) $regions[] = $r;
        if (count($regions) >= 5) break;
    }

    // Quote — only keep if both speaker and text exist.
    $quote = null;
    $q = $p['quote_of_day'] ?? null;
    if (is_array($q)) {
        $qText    = trim((string)($q['text']    ?? ''));
        $qSpeaker = trim((string)($q['speaker'] ?? ''));
        if ($qText !== '' && $qSpeaker !== '') {
            $quote = [
                'text'    => $qText,
                'speaker' => $qSpeaker,
                'context' => trim((string)($q['context'] ?? '')),
            ];
        }
    }

    return [
        'headline'         => trim((string)($p['headline'] ?? '')),
        'subheadline'      => trim((string)($p['subheadline'] ?? '')),
        'hook'             => trim((string)($p['hook'] ?? '')),
        'sections'         => $sections,
        'key_numbers'      => $keyNumbers,
        'regions'          => $regions,
        'quote_of_day'     => $quote,
        'closing_question' => trim((string)($p['closing_question'] ?? '')),
        'article_count'    => $data['article_count'],
    ];
}

/**
 * No-AI morning briefing. Built directly from today's top clusters when
 * the AI provider is unavailable (Gemini quota exhausted, Anthropic out
 * of credit). The result is a plain digest — each top story becomes a
 * section using the longest headline as the title and the article's own
 * ai_summary/excerpt as the body — but it guarantees the daily briefing
 * always exists so the home-screen card never looks broken.
 */
function sabah_build_without_ai(): ?array {
    try {
        $db = getDB();
        // Top multi-source clusters first, then any clusters — same
        // ranking sabah_collect_top_clusters uses.
        $clusters = $db->query("
            SELECT a.cluster_key,
                   COUNT(DISTINCT a.source_id) AS src_count,
                   COUNT(*) AS art_count,
                   MAX(a.published_at) AS latest_at
              FROM articles a
             WHERE a.status = 'published'
               AND a.cluster_key IS NOT NULL AND a.cluster_key <> '-'
               AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY a.cluster_key
             ORDER BY src_count DESC, art_count DESC, latest_at DESC
             LIMIT 6
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($clusters)) {
            $GLOBALS['_sabah_last_error'] = 'no clusters for no-AI fallback';
            return null;
        }

        $icons = ['📰', '🌍', '⚡', '📌', '🔔', '🗞️'];
        $sections = [];
        $totalArticles = 0;
        $leadTitle = '';

        foreach ($clusters as $idx => $cl) {
            $stmt = $db->prepare(
                "SELECT a.title, a.ai_summary, a.excerpt, s.name AS source_name
                   FROM articles a
                   LEFT JOIN sources s ON a.source_id = s.id
                  WHERE a.cluster_key = ? AND a.status = 'published'
                  ORDER BY LENGTH(COALESCE(a.ai_summary, a.excerpt, '')) DESC
                  LIMIT 1"
            );
            $stmt->execute([$cl['cluster_key']]);
            $art = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$art) continue;

            $title = trim((string)$art['title']);
            $body  = trim(strip_tags((string)($art['ai_summary'] ?? $art['excerpt'] ?? '')));
            if ($body === '') $body = $title;
            if (mb_strlen($body) > 360) $body = mb_substr($body, 0, 360) . '…';

            // Note how many outlets are covering it — adds a little signal
            // even without AI narration.
            if ((int)$cl['src_count'] >= 2) {
                $body .= "\n\n📡 يغطّيه " . (int)$cl['src_count'] . " مصادر.";
            }

            if ($idx === 0) $leadTitle = $title;
            $sections[] = [
                'title' => $title,
                'icon'  => $icons[$idx % count($icons)],
                'body'  => $body,
            ];
            $totalArticles += (int)$cl['art_count'];
        }

        if (empty($sections)) {
            $GLOBALS['_sabah_last_error'] = 'no articles for no-AI fallback';
            return null;
        }

        $dateAr = date('Y-m-d');
        return [
            'headline'         => $leadTitle !== '' ? $leadTitle : 'أبرز أخبار اليوم',
            'hook'             => 'إليك أبرز ما تناقلته المصادر خلال الـ 24 ساعة الماضية، '
                                . 'مرتّبة حسب اهتمام المصادر بها. اضغط أي قسم لقراءة التفاصيل.',
            'sections'         => $sections,
            'closing_question' => 'أي هذه الأخبار يهمّك أكثر اليوم؟',
            'article_count'    => $totalArticles,
        ];
    } catch (Throwable $e) {
        $GLOBALS['_sabah_last_error'] = 'no-AI fallback exception: ' . $e->getMessage();
        error_log('[sabah] no-AI fallback failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Save a briefing to the DB. Returns the row id.
 */
function sabah_save(array $briefing, string $date): ?int {
    sabah_ensure_table();
    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO sabah_briefings
                (briefing_date, headline, subheadline, hook, sections,
                 key_numbers, regions, quote_of_day,
                 closing_question, article_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                headline = VALUES(headline),
                subheadline = VALUES(subheadline),
                hook = VALUES(hook),
                sections = VALUES(sections),
                key_numbers = VALUES(key_numbers),
                regions = VALUES(regions),
                quote_of_day = VALUES(quote_of_day),
                closing_question = VALUES(closing_question),
                article_count = VALUES(article_count),
                generated_at = NOW()"
        )->execute([
            $date,
            $briefing['headline'] ?? '',
            $briefing['subheadline'] ?? '',
            $briefing['hook'] ?? '',
            json_encode($briefing['sections'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($briefing['key_numbers'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($briefing['regions'] ?? [], JSON_UNESCAPED_UNICODE),
            !empty($briefing['quote_of_day'])
                ? json_encode($briefing['quote_of_day'], JSON_UNESCAPED_UNICODE)
                : null,
            $briefing['closing_question'] ?? '',
            (int)($briefing['article_count'] ?? 0),
        ]);
        return (int)$db->lastInsertId() ?: null;
    } catch (Throwable $e) {
        error_log('[sabah] save: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get briefing for a date. Returns null if not generated yet.
 */
function sabah_get(string $date): ?array {
    sabah_ensure_table();
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM sabah_briefings WHERE briefing_date = ?");
        $stmt->execute([$date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return _sabah_decode_row($row);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Get latest briefing regardless of date.
 */
function sabah_get_latest(): ?array {
    sabah_ensure_table();
    try {
        $db = getDB();
        $row = $db->query("SELECT * FROM sabah_briefings ORDER BY briefing_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return _sabah_decode_row($row);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Decode the JSON columns of a sabah_briefings row into native arrays.
 * Centralized so sabah_get / sabah_get_latest stay in sync and a future
 * column addition only needs one edit.
 */
function _sabah_decode_row(array $row): array {
    $row['sections']     = json_decode((string)($row['sections']    ?? ''), true) ?: [];
    $row['key_numbers']  = json_decode((string)($row['key_numbers'] ?? ''), true) ?: [];
    $row['regions']      = json_decode((string)($row['regions']     ?? ''), true) ?: [];
    $q = $row['quote_of_day'] ?? null;
    $row['quote_of_day'] = is_string($q) && $q !== '' ? json_decode($q, true) : null;
    return $row;
}

/**
 * List recent briefings for the archive sidebar.
 */
function sabah_list(int $limit = 14): array {
    sabah_ensure_table();
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, briefing_date, headline, generated_at FROM sabah_briefings ORDER BY briefing_date DESC LIMIT ?");
        $stmt->bindValue(1, max(1, min(60, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
