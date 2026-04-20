<?php
/**
 * نيوز فيد - AI Q&A over the news archive
 * ========================================
 * "اسأل الأخبار" — let the reader ask anything in Arabic and get a
 * grounded answer that cites our own articles.
 *
 * Pipeline:
 *   1. qa_extract_keywords()   — strip Arabic stopwords, keep content words
 *   2. qa_retrieve_articles()  — LIKE-search the last N days, rank by
 *                                (hit_count * recency_decay) and take top-K
 *   3. qa_build_context()      — compact text block that fits in the prompt
 *   4. qa_call_claude()        — Sonnet forced via tool_use to return
 *                                {answer, cited_ids[], follow_ups[]}
 *   5. qa_ask()                — cached wrapper around the full pipeline
 *
 * Design notes:
 *   - Uses the SAME anthropic_api_key setting as ai_helper.php so the
 *     admin only configures it once.
 *   - Caches each normalized question for 10 minutes (cache_remember)
 *     to amortise repeated bursts — e.g. a viral question hitting the
 *     homepage link from Telegram.
 *   - Declines to answer out-of-scope questions, so we don't become a
 *     general-purpose chatbot burning API tokens on "what's the weather".
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/ai_provider.php';

// ---------------------------------------------------------------------
// Arabic stopwords — pruned so only content words feed the LIKE search
// ---------------------------------------------------------------------
function qa_stopwords(): array {
    static $sw = null;
    if ($sw !== null) return $sw;
    $sw = array_flip([
        // Arabic common words
        'من','إلى','عن','على','في','هو','هي','هم','أنت','أنا','نحن',
        'هذا','هذه','ذلك','تلك','هناك','هنا','كل','بعض','كان','كانت',
        'يكون','تكون','قد','لقد','لم','لن','لا','ما','ماذا','متى','كيف',
        'أين','لماذا','أي','أيها','الذي','التي','الذين','اللذان','اللتان',
        'و','أو','ثم','إن','أن','إذا','لو','حتى','بين','بعد','قبل','فوق',
        'تحت','أمام','خلف','عند','لدى','مع','ضد','بدون','لكن','غير','سوى',
        'كما','أيضا','أيضاً','فقط','جدا','جداً','هل','يا','أم','إما','إلا',
        'كانت','ليس','ليست','لست','لستم','لسنا','لستن','ذاك','تيك',
        'مثل','منذ','نفس','كل','بعد','قبل','كذلك','أكثر','أقل','أول',
        'آخر','يوم','سنة','شهر','اليوم','أمس','غدا','الآن',
        // Question words without information content
        'شو','ايش','وين','ليش','اي','ولا',
        // English — our content is mostly Arabic but some queries mix
        'the','a','an','is','are','was','were','of','in','on','at','to',
        'for','with','from','by','and','or','what','when','where','how',
        'why','who','which','this','that','these','those','it','its',
    ]);
    return $sw;
}

/**
 * Pull content words out of the user's question. Returns at most 6
 * keywords (de-duped, longest-first so the most specific terms anchor
 * the LIKE search).
 */
function qa_extract_keywords(string $question): array {
    // Strip punctuation/tatweel, normalize whitespace.
    $q = preg_replace('/[\p{P}\p{S}\x{0640}]+/u', ' ', $question);
    $q = preg_replace('/\s+/u', ' ', $q);
    $q = trim((string)$q);
    if ($q === '') return [];

    $stop   = qa_stopwords();
    $tokens = explode(' ', $q);
    $out    = [];
    foreach ($tokens as $t) {
        $t = trim($t);
        if (mb_strlen($t) < 3) continue;
        $lc = mb_strtolower($t);
        if (isset($stop[$lc]) || isset($stop[$t])) continue;
        // Drop very common Arabic prefixes so "الحرب" matches "حرب".
        $stripped = preg_replace('/^(ال|وال|فال|بال|كال|لل|وا|فا|ب|ل|و|ف)/u', '', $t);
        if ($stripped !== '' && mb_strlen($stripped) >= 3) {
            $t = $stripped;
        }
        $out[$t] = mb_strlen($t);
    }
    // Prefer longer tokens first (more specific) and cap at 6.
    arsort($out);
    return array_slice(array_keys($out), 0, 6);
}

/**
 * Retrieve the most relevant articles for a question. Scans the last
 * $windowDays days, LIKE-scores each candidate by keyword hits with
 * a small recency boost, and returns the top $limit rows.
 *
 * @return array<int,array> article rows ready for qa_build_context()
 */
function qa_retrieve_articles(string $question, int $limit = 10, int $windowDays = 14): array {
    $kws = qa_extract_keywords($question);
    if (!$kws) return [];

    $db = getDB();

    // Build a WHERE clause where at least ONE keyword has to match
    // (across title / ai_summary / ai_keywords / excerpt). This is a
    // cheap OR-of-LIKEs — for a handful of keywords on a few
    // thousand recent rows it runs in a few ms.
    $orClauses = [];
    $params    = [];
    foreach ($kws as $kw) {
        $like = '%' . $kw . '%';
        $orClauses[] = '(a.title LIKE ? OR a.ai_summary LIKE ? OR a.ai_keywords LIKE ? OR a.excerpt LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    $params[] = $windowDays;

    $sql = "SELECT a.id, a.title, a.slug, a.excerpt, a.ai_summary, a.ai_keywords,
                   a.image_url, a.published_at, a.source_id, a.category_id,
                   c.name AS cat_name, c.slug AS cat_slug,
                   s.name AS source_name
              FROM articles a
              LEFT JOIN categories c ON a.category_id = c.id
              LEFT JOIN sources s    ON a.source_id  = s.id
             WHERE a.status = 'published'
               AND (" . implode(' OR ', $orClauses) . ")
               AND a.published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY a.published_at DESC
             LIMIT 80";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        // Widen the window one time — if even the 14-day window returned
        // nothing, fall back to the last 60 days so archive questions
        // ("ما أهم تطورات الحرب في ديسمبر؟") still find something.
        $sql2 = "SELECT a.id, a.title, a.slug, a.excerpt, a.ai_summary, a.ai_keywords,
                        a.image_url, a.published_at, a.source_id, a.category_id,
                        c.name AS cat_name, c.slug AS cat_slug,
                        s.name AS source_name
                   FROM articles a
                   LEFT JOIN categories c ON a.category_id = c.id
                   LEFT JOIN sources s    ON a.source_id  = s.id
                  WHERE a.status = 'published'
                    AND (" . implode(' OR ', $orClauses) . ")
                    AND a.published_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                  ORDER BY a.published_at DESC
                  LIMIT 80";
        // Rebuild params WITHOUT the trailing window days placeholder —
        // the literal 60 is embedded above because this path is rare.
        $params2 = [];
        foreach ($kws as $kw) {
            $like = '%' . $kw . '%';
            array_push($params2, $like, $like, $like, $like);
        }
        $stmt = $db->prepare($sql2);
        $stmt->execute($params2);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
    }

    // Score each candidate. Title hits count the most, followed by
    // ai_summary, then ai_keywords, then excerpt. Multiply by a
    // recency decay so yesterday's coverage outranks week-old coverage.
    $now = time();
    foreach ($rows as &$r) {
        $title   = mb_strtolower((string)$r['title']);
        $summary = mb_strtolower((string)$r['ai_summary']);
        $keywds  = mb_strtolower((string)$r['ai_keywords']);
        $excerpt = mb_strtolower((string)$r['excerpt']);
        $score = 0;
        foreach ($kws as $kw) {
            $kwLc = mb_strtolower($kw);
            if ($title   !== '' && mb_strpos($title,   $kwLc) !== false) $score += 5;
            if ($summary !== '' && mb_strpos($summary, $kwLc) !== false) $score += 3;
            if ($keywds  !== '' && mb_strpos($keywds,  $kwLc) !== false) $score += 2;
            if ($excerpt !== '' && mb_strpos($excerpt, $kwLc) !== false) $score += 1;
        }
        // Recency decay: full weight for today, half-life ~4 days.
        $ageDays = max(0, ($now - strtotime($r['published_at'])) / 86400);
        $decay   = 1 / (1 + ($ageDays / 4));
        $r['_score'] = $score * $decay;
    }
    unset($r);

    usort($rows, fn($a, $b) => $b['_score'] <=> $a['_score']);
    return array_slice($rows, 0, $limit);
}

/**
 * Turn retrieved articles into a compact Arabic context block for
 * Claude. Each entry has an ID tag so the model can cite it as [#123].
 *
 * @return array{context:string,id_map:array<int,array>}
 */
function qa_build_context(array $rows): array {
    $lines  = [];
    $id_map = [];
    $budget = 12000;  // chars, not tokens — gives plenty of headroom
    $used   = 0;
    foreach ($rows as $r) {
        $id      = (int)$r['id'];
        $title   = trim((string)$r['title']);
        $summary = trim((string)($r['ai_summary'] ?: $r['excerpt']));
        if ($summary === '') continue;
        if (mb_strlen($summary) > 600) {
            $summary = mb_substr($summary, 0, 600) . '…';
        }
        $when   = !empty($r['published_at']) ? date('Y-m-d', strtotime($r['published_at'])) : '';
        $source = trim((string)($r['source_name'] ?? ''));
        $line   = "[#{$id}] ({$when}" . ($source !== '' ? " — {$source}" : '') . ")\n"
                . "العنوان: {$title}\n"
                . "الملخص: {$summary}\n";
        $len = mb_strlen($line);
        if ($used + $len > $budget) break;
        $lines[]    = $line;
        $id_map[$id] = $r;
        $used += $len + 1;
    }
    return [
        'context' => implode("\n", $lines),
        'id_map'  => $id_map,
    ];
}

// ---------------------------------------------------------------------
// Claude call — forced structured output via tool_use
// ---------------------------------------------------------------------

function qa_call_claude(string $question, string $context, array $id_map): array {
    if (trim($context) === '') {
        return [
            'ok'       => true,
            'answer'   => 'لم أجد في أرشيف نيوز فيد مقالات ذات صلة مباشرة بسؤالك. جرّب صياغة أخرى أو استخدم كلمات مفتاحية مختلفة.',
            'cited'    => [],
            'follow_ups' => [],
        ];
    }

    $prompt = "أنت مساعد ذكي لموقع نيوز فيد الإخباري العربي. مهمتك الإجابة عن سؤال القارئ "
            . "بالاعتماد حصرياً على المقالات المُرفقة أدناه — لا تستخدم أي معلومة خارجية.\n\n"
            . "قواعد صارمة:\n"
            . "- أجب بالعربية الفصحى، بأسلوب صحفي محايد وواضح.\n"
            . "- اذكر معرّف المقالة بين قوسين مربّعين عند كل معلومة تستشهد بها، مثال: [#123].\n"
            . "- إذا كانت المقالات المُرفقة لا تحتوي على إجابة كافية، قل ذلك صراحة واقترح صياغة أفضل.\n"
            . "- لا تُلفّق أسماء أو أرقام أو تواريخ غير موجودة في المقالات.\n"
            . "- ابنِ إجابة من 2 إلى 5 فقرات قصيرة حسب تعقيد السؤال.\n"
            . "- استدعِ أداة submit_answer دائماً لإرجاع الإجابة.\n\n"
            . "سؤال القارئ:\n{$question}\n\n"
            . "المقالات المتاحة (كل مقالة لها معرّف [#id]):\n{$context}";

    $validIds = array_keys($id_map);
    $tool = [
        'name'        => 'submit_answer',
        'description' => 'Return a grounded Arabic answer to the reader question, a list of cited article IDs, and up to 3 follow-up question suggestions.',
        'input_schema' => [
            'type'     => 'object',
            'properties' => [
                'answer' => [
                    'type'        => 'string',
                    'description' => 'الإجابة الكاملة بالعربية، مع الاستشهاد بمعرّفات المقالات [#id].',
                ],
                'cited_ids' => [
                    'type'        => 'array',
                    'description' => 'قائمة مُعرّفات المقالات التي استخدمتها فعلياً في الإجابة.',
                    'items'       => ['type' => 'integer'],
                ],
                'follow_ups' => [
                    'type'        => 'array',
                    'description' => 'حتى ٣ أسئلة متابعة يقترح المساعد على القارئ طرحها.',
                    'items'       => ['type' => 'string'],
                ],
                'confident' => [
                    'type'        => 'boolean',
                    'description' => 'هل الإجابة مبنيّة على أدلة كافية من المقالات؟',
                ],
            ],
            'required' => ['answer', 'cited_ids', 'follow_ups', 'confident'],
        ],
    ];

    $call = ai_provider_tool_call($prompt, $tool, 1500);
    if (empty($call['ok'])) {
        return ['ok' => false, 'error' => (string)($call['error'] ?? 'تعذّر توليد الإجابة.')];
    }
    $parsed = $call['input'];
    if (!is_array($parsed) || empty($parsed['answer'])) {
        return ['ok' => false, 'error' => 'لم أتمكّن من توليد إجابة الآن.'];
    }

    // Whitelist: drop any cited_ids the model hallucinated (it
    // occasionally volunteers IDs that weren't in the context).
    $cited = [];
    foreach ((array)($parsed['cited_ids'] ?? []) as $id) {
        $id = (int)$id;
        if (isset($id_map[$id])) $cited[] = $id;
    }
    $cited = array_values(array_unique($cited));

    $followUps = array_values(array_filter(
        array_map(fn($s) => trim((string)$s), (array)($parsed['follow_ups'] ?? [])),
        fn($s) => $s !== ''
    ));
    $followUps = array_slice($followUps, 0, 3);

    return [
        'ok'         => true,
        'answer'     => trim((string)$parsed['answer']),
        'cited'      => $cited,
        'follow_ups' => $followUps,
        'confident'  => (bool)($parsed['confident'] ?? true),
    ];
}

// ---------------------------------------------------------------------
// Public entry — cached full pipeline
// ---------------------------------------------------------------------

/**
 * Normalize a question so trivially different spellings share a cache
 * entry. We don't over-normalize (no stemming) — the goal is just to
 * collapse whitespace and remove punctuation.
 */
function qa_cache_key(string $question): string {
    $q = preg_replace('/[\p{P}\p{S}]+/u', ' ', $question);
    $q = preg_replace('/\s+/u', ' ', $q);
    $q = trim(mb_strtolower((string)$q));
    return 'qa:' . sha1($q);
}

/**
 * Full end-to-end: retrieve → build context → ask Claude. Cached for
 * ten minutes so a viral question doesn't hammer the API.
 *
 * @return array{ok:bool, answer?:string, articles?:array, follow_ups?:array, error?:string}
 */
function qa_ask(string $question, int $ttl = 600): array {
    $question = trim($question);
    if ($question === '' || mb_strlen($question) < 4) {
        return ['ok' => false, 'error' => 'السؤال قصير جداً. اكتب سؤالاً واضحاً (٤ أحرف على الأقل).'];
    }
    if (mb_strlen($question) > 500) {
        $question = mb_substr($question, 0, 500);
    }

    $cacheKey = qa_cache_key($question);
    return cache_remember($cacheKey, $ttl, function() use ($question) {
        $rows = qa_retrieve_articles($question, 10, 14);
        $ctx  = qa_build_context($rows);
        $ai   = qa_call_claude($question, $ctx['context'], $ctx['id_map']);
        if (empty($ai['ok'])) {
            return ['ok' => false, 'error' => $ai['error'] ?? 'خطأ غير معروف'];
        }

        // Shape the article payload for the frontend.
        $articles = [];
        foreach ($ai['cited'] as $id) {
            $r = $ctx['id_map'][$id] ?? null;
            if (!$r) continue;
            $articles[] = [
                'id'            => (int)$r['id'],
                'title'         => (string)$r['title'],
                'image_url'     => (string)($r['image_url'] ?? ''),
                'published_at'  => (string)($r['published_at'] ?? ''),
                'source'        => (string)($r['source_name'] ?? ''),
                'category'      => (string)($r['cat_name'] ?? ''),
                'category_slug' => (string)($r['cat_slug'] ?? ''),
                'url'           => '/' . articleUrl($r),
            ];
        }

        return [
            'ok'         => true,
            'answer'     => $ai['answer'],
            'articles'   => $articles,
            'follow_ups' => $ai['follow_ups'],
            'confident'  => $ai['confident'],
        ];
    });
}
