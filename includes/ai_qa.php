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

// Aggregation-style articles (hourly briefings, daily roundups) clog
// the retrieval results for every broad question because their titles
// match common words like "أخبار" but their bodies are just lists of
// other articles with no substantive content. Filter them out at the
// SQL level using a SHARED pattern so we can't accidentally leak one
// into the corpus from one path but not another.
const QA_AGGREGATION_TITLE_NOT_LIKE = [
    'موجز أخبار%',
    'موجز اخبار%',
    'موجز الساعة%',
    'موجز اليوم%',
    'موجز يومي%',
    'موجز إخباري%',
    'موجز اخباري%',
    'النشرة%',
    'نشرة الأخبار%',
    'ملخص الأخبار%',
    'ملخص اخبار%',
];

/** Build `AND (title NOT LIKE ? AND ...)` clause + params. */
function qa_aggregation_exclusion_clause(string $alias = 'a'): array {
    $parts  = [];
    $params = [];
    foreach (QA_AGGREGATION_TITLE_NOT_LIKE as $pat) {
        $parts[]  = "{$alias}.title NOT LIKE ?";
        $params[] = $pat;
    }
    return ['sql' => '(' . implode(' AND ', $parts) . ')', 'params' => $params];
}

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
    $excl = qa_aggregation_exclusion_clause('a');
    $params = array_merge($params, $excl['params']);
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
               AND " . $excl['sql'] . "
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
                    AND " . $excl['sql'] . "
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
        $params2 = array_merge($params2, $excl['params']);
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
 * Detect a "broad" / "what's new today" style question. These don't
 * benefit from keyword retrieval because the user isn't asking about a
 * specific story — they want a digest. We route them to cluster-based
 * retrieval instead so the AI sees the day's actual top stories
 * (organized by editorial consensus) rather than whatever LIKE-matched
 * the word "أخبار".
 */
function qa_is_broad_query(string $question): bool {
    $kws = qa_extract_keywords($question);
    // Empty after stopword stripping → user typed something like "إيش
    // الجديد" with only filler words → broad digest is the best fallback.
    if (empty($kws)) return true;

    // "Intent" words signal the question SHAPE (what's new / today's
    // top), not a specific topic. Strip them and see if anything
    // substantive remains. If nothing remains → broad. If even one real
    // topic word remains (e.g. "غزة", "الكنيست") → specific.
    static $intent = [
        // News intent
        'اخبار','أخبار','احداث','أحداث','ملخص','موجز','نشرة','تطورات','مستجدات',
        // Ranking adjectives
        'ابرز','أبرز','اهم','أهم','اخر','آخر','احدث','أحدث','جديد','الجديد',
        // Time anchors
        'اليوم','يوم','الان','الآن','عاجل','عاجلة','حاليا','الآنية',
        // Question/intent verbs
        'ماذا','حدث','يحدث','صار','شو','حصل','يحصل','يجري','جرى',
        // English fallbacks
        'news','today','top','latest','breaking','summary','stories','story',
        'happening','events','update','updates',
    ];
    $intentSet = array_flip(array_map('mb_strtolower', $intent));
    foreach ($kws as $kw) {
        if (!isset($intentSet[mb_strtolower($kw)])) return false;
    }
    return true;
}

/**
 * Cluster-based retrieval for broad queries. Picks the top recent
 * clusters by editorial consensus (multi-source first, falling back to
 * single-source on quiet days) and flattens them into article rows
 * shaped exactly like qa_retrieve_articles output. Excludes aggregation
 * articles so the AI never sees "موجز الساعة" as if it were a real
 * story.
 */
function qa_retrieve_top_today(int $limit = 12, int $hoursWindow = 24): array {
    $db = getDB();
    $excl = qa_aggregation_exclusion_clause('a');

    try {
        // First pass: clusters with 2+ sources in the last $hoursWindow.
        $sql = "SELECT a.cluster_key,
                       COUNT(DISTINCT a.source_id) AS src_count,
                       COUNT(*) AS art_count,
                       MAX(a.published_at) AS latest_at
                  FROM articles a
                 WHERE a.status = 'published'
                   AND a.cluster_key IS NOT NULL AND a.cluster_key <> '-'
                   AND a.published_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                   AND " . $excl['sql'] . "
                 GROUP BY a.cluster_key
                HAVING src_count >= 2
                 ORDER BY src_count DESC, latest_at DESC
                 LIMIT ?";
        $stmt = $db->prepare($sql);
        $params = array_merge([$hoursWindow], $excl['params'], [$limit]);
        $i = 1;
        foreach ($params as $p) {
            $type = is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($i++, $p, $type);
        }
        $stmt->execute();
        $clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback to single-source clusters when consensus is thin.
        if (count($clusters) < 4) {
            $sql2 = "SELECT a.cluster_key,
                            COUNT(DISTINCT a.source_id) AS src_count,
                            COUNT(*) AS art_count,
                            MAX(a.published_at) AS latest_at
                       FROM articles a
                      WHERE a.status = 'published'
                        AND a.cluster_key IS NOT NULL AND a.cluster_key <> '-'
                        AND a.published_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                        AND " . $excl['sql'] . "
                      GROUP BY a.cluster_key
                      ORDER BY art_count DESC, latest_at DESC
                      LIMIT ?";
            $stmt = $db->prepare($sql2);
            $params = array_merge([$hoursWindow], $excl['params'], [$limit]);
            $i = 1;
            foreach ($params as $p) {
                $type = is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($i++, $p, $type);
            }
            $stmt->execute();
            $clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!$clusters) {
            // Last-ditch: just the latest non-aggregation articles.
            $sql3 = "SELECT a.id, a.title, a.slug, a.excerpt, a.ai_summary, a.ai_keywords,
                            a.image_url, a.published_at, a.source_id, a.category_id,
                            c.name AS cat_name, c.slug AS cat_slug,
                            s.name AS source_name
                       FROM articles a
                       LEFT JOIN categories c ON a.category_id = c.id
                       LEFT JOIN sources s    ON a.source_id  = s.id
                      WHERE a.status = 'published'
                        AND a.published_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                        AND " . $excl['sql'] . "
                      ORDER BY a.published_at DESC
                      LIMIT ?";
            $stmt = $db->prepare($sql3);
            $params = array_merge([$hoursWindow], $excl['params'], [$limit]);
            $i = 1;
            foreach ($params as $p) {
                $type = is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($i++, $p, $type);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Pick one representative article per cluster (the longest-titled
        // one with an AI summary tends to be the most useful) — gives the
        // AI N distinct stories instead of N copies of the same story.
        $rows = [];
        foreach ($clusters as $cl) {
            $ck = $cl['cluster_key'];
            $artSql = "SELECT a.id, a.title, a.slug, a.excerpt, a.ai_summary, a.ai_keywords,
                              a.image_url, a.published_at, a.source_id, a.category_id,
                              c.name AS cat_name, c.slug AS cat_slug,
                              s.name AS source_name,
                              ? AS _src_count, ? AS _art_count
                         FROM articles a
                         LEFT JOIN categories c ON a.category_id = c.id
                         LEFT JOIN sources s    ON a.source_id  = s.id
                        WHERE a.cluster_key = ? AND a.status = 'published'
                          AND " . $excl['sql'] . "
                        ORDER BY (a.ai_summary IS NOT NULL AND a.ai_summary <> '') DESC,
                                 LENGTH(a.title) DESC
                        LIMIT 1";
            $stmt = $db->prepare($artSql);
            $params = array_merge(
                [(int)$cl['src_count'], (int)$cl['art_count'], $ck],
                $excl['params']
            );
            $stmt->execute($params);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) $rows[] = $r;
            if (count($rows) >= $limit) break;
        }
        return $rows;
    } catch (Throwable $e) {
        error_log('qa_retrieve_top_today: ' . $e->getMessage());
        return [];
    }
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
        $when   = !empty($r['published_at']) ? date('Y-m-d H:i', strtotime($r['published_at'])) : '';
        $source = trim((string)($r['source_name'] ?? ''));
        // Cluster metadata (only present when retrieved via the broad path)
        // helps the AI rank what's "abrz" (most prominent) — a story with
        // 5 sources is by definition bigger than a 1-source filing.
        $srcCount = isset($r['_src_count']) ? (int)$r['_src_count'] : 0;
        $artCount = isset($r['_art_count']) ? (int)$r['_art_count'] : 0;
        $clusterTag = '';
        if ($srcCount > 1) {
            $clusterTag = " — {$srcCount} مصادر تغطّيها، {$artCount} مقال";
        }
        $line   = "[#{$id}] ({$when}" . ($source !== '' ? " — {$source}" : '') . "{$clusterTag})\n"
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

function qa_call_claude(string $question, string $context, array $id_map, bool $isBroad = false): array {
    if (trim($context) === '') {
        return [
            'ok'       => true,
            'answer'   => 'لم أجد في أرشيف نيوز فيد مقالات ذات صلة مباشرة بسؤالك. جرّب صياغة أخرى أو استخدم كلمات مفتاحية مختلفة.',
            'cited'    => [],
            'follow_ups' => [],
        ];
    }

    // Broad "what's today's news" queries get a digest-style prompt that
    // tells the model to write a multi-section roundup ranked by editorial
    // weight (cluster size = how many outlets covered it). Specific
    // queries get the original "answer the question" prompt.
    if ($isBroad) {
        $prompt = "أنت مساعد ذكي لموقع نيوز فيد الإخباري العربي. القارئ طلب موجزًا "
                . "لأبرز الأخبار، وعليك أن تصنع له موجزًا صحفيًا متماسكًا اعتمادًا "
                . "حصريًا على المقالات المُرفقة أدناه.\n\n"
                . "قواعد صارمة:\n"
                . "- ابدأ بفقرة افتتاحية (3-5 أسطر) تلخّص الصورة العامة لليوم.\n"
                . "- ثم اكتب 4 إلى 6 محاور قصيرة، كلّ محور برأس عريض (سطر مفرد ينتهي بـ :) "
                  . "متبوعًا بفقرة من 2-3 جمل تشرح القصة.\n"
                . "- رتّب المحاور بحسب الأهمية: القصص التي غطّتها أكثر من مصدر تأتي أولًا "
                  . "(انظر إلى عدد المصادر في وصف كل مقال).\n"
                . "- أعطِ أولوية للأخبار الفلسطينية إن وُجدت.\n"
                . "- اذكر معرّف المقالة بين قوسين مربّعين عند كل معلومة، مثال: [#123].\n"
                . "- لا تُلفّق أرقامًا أو أسماءً أو تواريخ غير موجودة في المقالات.\n"
                . "- العربية الفصحى، أسلوب صحفي محايد وواضح.\n"
                . "- استدعِ أداة submit_answer لإرجاع الإجابة، واملأ cited_ids "
                  . "بكل المقالات التي ذكرتها فعلًا.\n\n"
                . "سؤال القارئ:\n{$question}\n\n"
                . "أبرز قصص اليوم (كل مقال يمثّل قصّة، مع عدد المصادر التي غطّتها):\n{$context}";
    } else {
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
    }

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

    // Broad queries (digest) need more tokens than a Q&A answer.
    $maxTokens = $isBroad ? 2500 : 1500;
    $call = ai_provider_tool_call($prompt, $tool, $maxTokens);
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
// Default cache TTL raised 10min → 1h. News Q&A answers don't change
// meaningfully within an hour (the underlying 14-day article window
// barely shifts), and a longer cache absorbs the "viral link on
// Telegram → 50 identical questions" spike that used to hammer the AI
// quota. Only successful answers are cached (failures return null →
// treated as a miss), so a rate-limited answer never sticks.
function qa_ask(string $question, int $ttl = 3600): array {
    $question = trim($question);
    if ($question === '' || mb_strlen($question) < 4) {
        return ['ok' => false, 'error' => 'السؤال قصير جداً. اكتب سؤالاً واضحاً (٤ أحرف على الأقل).'];
    }
    if (mb_strlen($question) > 500) {
        $question = mb_substr($question, 0, 500);
    }

    $isBroad = qa_is_broad_query($question);
    // Broad queries are time-sensitive ("today's news" goes stale fast),
    // so cap their cache at 15 min. Specific queries can ride the full
    // hour because the underlying article body doesn't change.
    if ($isBroad) $ttl = min($ttl, 900);

    $cacheKey = qa_cache_key($question) . ($isBroad ? ':broad' : '');
    $result = cache_remember($cacheKey, $ttl, function() use ($question, $isBroad) {
        $rows = $isBroad
            ? qa_retrieve_top_today(12, 24)
            : qa_retrieve_articles($question, 10, 14);
        $ctx  = qa_build_context($rows);
        $ai   = qa_call_claude($question, $ctx['context'], $ctx['id_map'], $isBroad);
        if (empty($ai['ok'])) {
            // Return null so cache_remember treats this as a miss and
            // the next request retries instead of getting the same
            // stale 429 / network-error response for 10 minutes.
            return null;
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

    // cache_remember returned null → AI call failed and was not cached.
    if ($result === null) {
        return ['ok' => false, 'error' => 'تعذّر الحصول على الإجابة الآن، حاول بعد قليل.'];
    }
    return $result;
}
