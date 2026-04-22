<?php
/**
 * Daily Podcast — AI script generator.
 *
 * Takes the top clustered stories from the last 24h and turns
 * them into a ~6-minute radio-style script: opener + 5-7
 * segments with natural transitions + closer. The script comes
 * back structured (title, intro, segments[]) so the TTS step
 * can insert brief pauses between pieces and the public page
 * can show chapters that skip to the right moment.
 *
 * One AI call per episode — uses the same tool-use path as
 * the Telegram summarizer and Weekly Rewind so all three share
 * rate-limit and retry behaviour.
 */

require_once __DIR__ . '/ai_provider.php';

if (!function_exists('pod_collect_candidates')) {

/**
 * Pull the top articles from the last N hours, deduped by
 * cluster_key (strongest view-count representative per
 * cluster), with a gentle breaking/hero boost. Identical
 * shape to wr_collect_candidates but scoped to 24h instead
 * of a whole week.
 */
function pod_collect_candidates(int $hours = 24, int $limit = 30): array {
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT a.id, a.title, a.excerpt, a.ai_summary, a.cluster_key,
                a.view_count, a.is_breaking, a.is_hero, a.published_at,
                c.name AS cat_name, s.name AS source_name
           FROM articles a
      LEFT JOIN categories c ON c.id = a.category_id
      LEFT JOIN sources    s ON s.id = a.source_id
          WHERE a.status = 'published'
            AND a.published_at >= DATE_SUB(NOW(), INTERVAL :h HOUR)
          ORDER BY a.is_breaking DESC, a.is_hero DESC,
                   a.view_count DESC, a.published_at DESC
          LIMIT :lim"
    );
    $stmt->bindValue(':h',   $hours,  PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit * 4, PDO::PARAM_INT);  // over-fetch before dedupe
    $stmt->execute();
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $seenClusters = [];
    $out = [];
    foreach ($raw as $r) {
        $ck = (string)($r['cluster_key'] ?? '');
        if ($ck !== '' && $ck !== '-' && isset($seenClusters[$ck])) continue;
        if ($ck !== '' && $ck !== '-') $seenClusters[$ck] = true;
        $out[] = $r;
        if (count($out) >= $limit) break;
    }
    return $out;
}

/**
 * Ask the model to write the podcast. Returns:
 *   ['ok'=>true, 'payload'=>[
 *      'title' => 'موجز الأربعاء 22 أبريل',
 *      'subtitle' => 'غزة، الاقتصاد، قمة الناتو',
 *      'intro' => 'أهلاً بكم...' (40-80 words),
 *      'segments' => [
 *        ['article_id'=>123, 'headline'=>'...', 'copy'=>'2-3 paragraphs',
 *         'transition'=>'short bridge sentence leading to the next one'],
 *        ...
 *      ],
 *      'closer' => 'شكراً لاستماعكم...' (20-40 words)
 *   ]]
 * or ['ok'=>false, 'error'=>'...']
 */
function pod_generate_script(array $candidates, string $dateHuman): array {
    if (empty($candidates)) {
        return ['ok' => false, 'error' => 'no candidates'];
    }
    if (!function_exists('ai_provider_tool_call')) {
        return ['ok' => false, 'error' => 'ai_provider not loaded'];
    }

    // Brief the model with ids + headlines + short summaries so
    // the total prompt stays under token budget.
    $briefLines = [];
    $validIds = [];
    foreach ($candidates as $i => $c) {
        $id = (int)$c['id'];
        $validIds[$id] = true;
        $summary = trim((string)($c['ai_summary'] ?: strip_tags((string)$c['excerpt'])));
        $summary = mb_substr($summary, 0, 240);
        $briefLines[] = sprintf(
            "[%d] %s — %s%s | %s",
            $id,
            trim((string)$c['title']),
            $summary ? $summary : '—',
            !empty($c['is_breaking']) ? ' [عاجل]' : '',
            (string)($c['source_name'] ?? '')
        );
    }

    $brief = implode("\n", $briefLines);
    $prompt = "أنت مذيع إذاعي محترف في موجز إخباري يومي. من القائمة التالية "
            . "من القصص المتاحة خلال آخر ٢٤ ساعة، اكتب سكربت لحلقة بودكاست "
            . "يومي بالعربية الفصحى المبسّطة، بطول ٥-٧ دقائق صوتية (تقريباً "
            . "٨٠٠-١٠٠٠ كلمة).\n\n"
            . "متطلبات الأسلوب:\n"
            . "- افتتاحية موجزة تُحدّد التاريخ وأهم ٣ عناوين.\n"
            . "- ٥-٧ قصص مختارة، مرتّبة من الأهم للأقل أهمية.\n"
            . "- كل قصة ٢-٣ فقرات (٦٠-١٢٠ كلمة)، أسلوب سردي لا مشوّق.\n"
            . "- جملة انتقال قصيرة قبل كل قصة جديدة ('وفي شأن اقتصادي'، "
            . "'ننتقل إلى الشرق الأوسط'، إلخ).\n"
            . "- خاتمة قصيرة تودّع المستمع وتشير لموعد حلقة الغد.\n"
            . "- لا عناوين فرعية أو رموز — سكربت متصل للقراءة الصوتية.\n"
            . "- تاريخ اليوم: {$dateHuman}\n\n"
            . "القصص المتاحة:\n{$brief}\n\n"
            . "استدعِ أداة record_episode بالنتيجة المنظّمة.";

    $tool = [
        'name'         => 'record_episode',
        'description'  => 'Record the final structured podcast episode.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'title'    => ['type' => 'string', 'description' => 'اسم الحلقة (مثال: "موجز الأربعاء ٢٢ أبريل")'],
                'subtitle' => ['type' => 'string', 'description' => 'وصف في سطر واحد للعناوين الرئيسية مفصولة بنقاط (•)'],
                'intro'    => ['type' => 'string', 'description' => 'افتتاحية الحلقة (٤٠-٨٠ كلمة)'],
                'segments' => [
                    'type' => 'array',
                    'minItems' => 4,
                    'maxItems' => 8,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'article_id' => ['type' => 'integer', 'description' => 'معرّف القصة المرجعية من القائمة'],
                            'headline'   => ['type' => 'string', 'description' => 'عنوان قصير للقسم'],
                            'copy'       => ['type' => 'string', 'description' => 'الجزء المنطوق من القصة (٢-٣ فقرات)'],
                            'transition' => ['type' => 'string', 'description' => 'جملة انتقال قصيرة تنهي هذا القسم وتمهّد للقسم التالي'],
                        ],
                        'required' => ['article_id', 'headline', 'copy'],
                    ],
                ],
                'closer' => ['type' => 'string', 'description' => 'خاتمة الحلقة (٢٠-٤٠ كلمة)'],
            ],
            'required' => ['title', 'intro', 'segments', 'closer'],
        ],
    ];

    $call = ai_provider_tool_call($prompt, $tool, 4000);
    if (empty($call['ok'])) {
        return ['ok' => false, 'error' => (string)($call['error'] ?? 'AI call failed')];
    }

    $data = $call['input'] ?? $call['args'] ?? null;
    if (!is_array($data) || empty($data['segments'])) {
        return ['ok' => false, 'error' => 'empty script payload'];
    }

    // Drop segments that reference article ids the model hallucinated.
    $segments = array_values(array_filter($data['segments'], function ($seg) use ($validIds) {
        return is_array($seg) && isset($validIds[(int)($seg['article_id'] ?? 0)]);
    }));
    if (count($segments) < 3) {
        return ['ok' => false, 'error' => 'too few valid segments (' . count($segments) . ')'];
    }

    $articleIds = array_map(fn($s) => (int)$s['article_id'], $segments);

    return [
        'ok' => true,
        'payload' => [
            'title'       => (string)($data['title']    ?? ''),
            'subtitle'    => (string)($data['subtitle'] ?? ''),
            'intro'       => (string)($data['intro']    ?? ''),
            'segments'    => $segments,
            'closer'      => (string)($data['closer']   ?? ''),
            'article_ids' => $articleIds,
        ],
    ];
}

/**
 * Assemble the structured script into a single flat text
 * string the TTS step will speak, and a chapter index.
 *
 * Chapter timing is estimated by word count (avg 150 wpm for
 * measured Arabic newsreader pace). The TTS step may adjust
 * these with real ffprobe timings once the MP3 exists — that
 * patch happens in the tts assembler.
 */
function pod_script_to_speech(array $payload): array {
    $words_per_minute = 150;  // conservative Arabic newsreader pace
    $speech = '';
    $chapters = [];
    $tAccum = 0;

    $addBlock = function (string $text, ?string $title = null, ?int $articleId = null) use (&$speech, &$chapters, &$tAccum, $words_per_minute) {
        $text = trim($text);
        if ($text === '') return;
        if ($title !== null) {
            $chapters[] = [
                'time'       => $tAccum,
                'title'      => $title,
                'article_id' => $articleId,
            ];
        }
        $speech .= $text . "\n\n";
        $words = max(1, str_word_count($text, 0, 'ابتثجحخدذرزسشصضطظعغفقكلمنهويىأإآةؤئء'));
        // Rough estimate — Arabic words are measured differently
        // by str_word_count; split on whitespace as a tiebreaker.
        $words = max($words, count(preg_split('/\s+/u', $text) ?: []));
        $tAccum += (int)round(($words / $words_per_minute) * 60);
    };

    $addBlock($payload['intro']    ?? '', 'المقدمة', null);
    foreach (($payload['segments'] ?? []) as $seg) {
        $copy = trim((string)($seg['copy'] ?? ''));
        $transition = trim((string)($seg['transition'] ?? ''));
        $blockText = $copy . ($transition ? "\n" . $transition : '');
        $addBlock($blockText, (string)($seg['headline'] ?? ''), (int)($seg['article_id'] ?? 0));
    }
    $addBlock($payload['closer'] ?? '', 'الخاتمة', null);

    return [
        'speech'    => trim($speech),
        'chapters'  => $chapters,
        'estimated_duration' => $tAccum,
    ];
}

} // function_exists guard
