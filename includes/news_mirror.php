<?php
/**
 * نيوز فيد — مرايا الأخبار (News Mirror)
 *
 * For a cluster covered by ≥2 *distinct* sources, asks the AI to expose
 * how those sources framed the SAME event differently:
 *   - الخلاصة المحايدة (neutral_summary) — the agreed facts, stripped of loaded language.
 *   - تباين المصطلحات (divergent_terms)  — same concept, different words ("اشتباك" vs "عملية").
 *   - زاوية كل مصدر (framings)            — what each source emphasized or played down.
 *
 * One AI tool-call per cluster, stored in cluster_mirror, cached via
 * cache_remember for 30 min. Lazy-generated on first view — same shape
 * and lifecycle as includes/smart_brevity.php.
 *
 * Note: all ingested sources are Arabic, and clusters are keyed on
 * normalized Arabic title tokens, so this contrasts framing *within*
 * Arabic coverage — not a cross-lingual mirror.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/ai_provider.php';

function news_mirror_ensure_table(): void {
    static $done = false;
    if ($done) return;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS cluster_mirror (
            cluster_key CHAR(40) NOT NULL PRIMARY KEY,
            article_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            source_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            neutral_summary TEXT NOT NULL,
            divergent_terms TEXT,
            framings TEXT,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_generated (generated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    } catch (Throwable $e) {
        error_log('[news_mirror] ensure_table: ' . $e->getMessage());
    }
}

/**
 * Get cached mirror for a cluster. Returns null if not generated yet
 * or if the cluster has grown significantly since last generation.
 */
function news_mirror_get(string $clusterKey, int $currentCount = 0): ?array {
    news_mirror_ensure_table();
    $cacheKey = 'mirror_' . $clusterKey;
    return cache_remember($cacheKey, 1800, function () use ($clusterKey, $currentCount) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM cluster_mirror WHERE cluster_key = ?");
            $stmt->execute([$clusterKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            // Stale if cluster grew by ≥3 articles since generation.
            if ($currentCount > 0 && ($currentCount - (int)$row['article_count']) >= 3) {
                return null;
            }
            $row['divergent_terms'] = json_decode((string)$row['divergent_terms'], true) ?: [];
            $row['framings']        = json_decode((string)$row['framings'], true) ?: [];
            return $row;
        } catch (Throwable $e) {
            return null;
        }
    });
}

/**
 * Count distinct, non-empty source names across the cluster's articles.
 */
function news_mirror_distinct_sources(array $articles): int {
    $set = [];
    foreach ($articles as $a) {
        $s = trim((string)($a['source_name'] ?? ''));
        if ($s !== '') $set[$s] = true;
    }
    return count($set);
}

/**
 * Generate the mirror for a cluster from its articles.
 * Requires ≥2 distinct sources to be meaningful (the whole point is
 * contrasting how *different* outlets told the same story).
 */
function news_mirror_generate(string $clusterKey, array $articles): ?array {
    if (news_mirror_distinct_sources($articles) < 2) return null;
    news_mirror_ensure_table();

    // Build a compact corpus from article titles + summaries, keeping
    // the source attribution on every line so the model can attribute
    // each framing/term to who used it.
    $lines  = [];
    $budget = 8000;
    $used   = 0;
    foreach ($articles as $a) {
        $title   = trim((string)($a['title'] ?? ''));
        if ($title === '') continue;
        $summary = trim(strip_tags((string)($a['ai_summary'] ?? $a['excerpt'] ?? '')));
        if (mb_strlen($summary) > 400) $summary = mb_substr($summary, 0, 400) . '…';
        $source  = trim((string)($a['source_name'] ?? 'مصدر'));
        $line = "- [{$source}] {$title}\n  {$summary}";
        $len  = mb_strlen($line);
        if ($used + $len > $budget) break;
        $lines[] = $line;
        $used += $len + 1;
    }

    if (count($lines) < 2) return null;

    $corpus   = implode("\n", $lines);
    $srcCount = news_mirror_distinct_sources($articles);
    $count    = count($lines);

    $prompt = "أنت محلّل إعلامي محايد. لديك {$count} تقريراً من {$srcCount} مصدراً عربياً مختلفاً، تغطّي نفس الحدث. "
            . "مهمتك كشف كيف اختلفت المصادر في صياغة الخبر ذاته — وهي «مرايا الأخبار».\n\n"
            . "قواعد صارمة:\n"
            . "- اعتمد فقط على نص التقارير أدناه؛ لا تخترع أي معلومة.\n"
            . "- «الخلاصة المحايدة»: فقرة قصيرة (2-3 جمل) تذكر الحقائق المتّفق عليها بلغة خالية من أي شحنة أو انحياز.\n"
            . "- «تباين المصطلحات»: ابحث عن نفس المفهوم/الفعل حين تصفه المصادر بكلمات مختلفة "
            . "(مثال: «اشتباك» مقابل «عملية» مقابل «هجوم»). اذكر 2-5 مفاهيم على الأكثر، ولكلٍّ منها الصيغ المستخدمة، "
            . "ومن استخدم كل صيغة، ودرجة شحنتها. إن كانت الصياغة متطابقة فعلاً بين المصادر فأعد قائمة فارغة.\n"
            . "- «زاوية كل مصدر»: لكل مصدر (أو مجموعة مصادر متشابهة) اذكر زاويته، وما الذي ركّز عليه أو خفّف منه.\n"
            . "- اكتب كل المخرجات بالعربية الفصحى.\n\n"
            . "التقارير:\n" . $corpus;

    $tool = [
        'name'        => 'submit_news_mirror',
        'description' => 'Submit a framing-contrast (News Mirror) analysis of a news cluster.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'neutral_summary' => [
                    'type'        => 'string',
                    'description' => 'الخلاصة المحايدة — 2-3 جمل بالحقائق المتّفق عليها بلا لغة محمّلة.',
                ],
                'divergent_terms' => [
                    'type'        => 'array',
                    'description' => 'تباين المصطلحات — نفس المفهوم بكلمات مختلفة بين المصادر (0-5 عناصر).',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'concept'  => [
                                'type'        => 'string',
                                'description' => 'المفهوم أو الحدث المشترك الموصوف بطرق مختلفة.',
                            ],
                            'variants' => [
                                'type'        => 'array',
                                'description' => 'الصيغ المختلفة لهذا المفهوم.',
                                'items'       => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'term'    => ['type' => 'string', 'description' => 'الكلمة/العبارة كما وردت.'],
                                        'sources' => [
                                            'type'        => 'array',
                                            'description' => 'أسماء المصادر التي استخدمت هذه الصيغة.',
                                            'items'       => ['type' => 'string'],
                                        ],
                                        'tone'    => [
                                            'type'        => 'string',
                                            'description' => 'درجة الشحنة: محايد | مشحون إيجابياً | مشحون سلبياً.',
                                        ],
                                    ],
                                    'required' => ['term', 'sources', 'tone'],
                                ],
                            ],
                        ],
                        'required' => ['concept', 'variants'],
                    ],
                ],
                'framings' => [
                    'type'        => 'array',
                    'description' => 'زاوية كل مصدر — كيف أطّر كل مصدر (أو مجموعة) الخبر.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'sources'  => [
                                'type'        => 'array',
                                'description' => 'أسماء المصادر التي تشترك في هذه الزاوية.',
                                'items'       => ['type' => 'string'],
                            ],
                            'angle'    => ['type' => 'string', 'description' => 'وصف موجز لزاوية التأطير.'],
                            'emphasis' => ['type' => 'string', 'description' => 'جملة عمّا ركّز عليه أو خفّف منه.'],
                        ],
                        'required' => ['sources', 'angle', 'emphasis'],
                    ],
                ],
            ],
            'required' => ['neutral_summary', 'divergent_terms', 'framings'],
        ],
    ];

    $call = ai_provider_tool_call($prompt, $tool, 1800);
    if (empty($call['ok']) || !is_array($call['input'])) {
        error_log('[news_mirror] AI failed for ' . $clusterKey . ': ' . ($call['error'] ?? ''));
        return null;
    }

    $p = $call['input'];

    // Normalize the nested arrays into a predictable shape for the view.
    $terms = [];
    foreach ((array)($p['divergent_terms'] ?? []) as $t) {
        if (!is_array($t)) continue;
        $concept = trim((string)($t['concept'] ?? ''));
        $variants = [];
        foreach ((array)($t['variants'] ?? []) as $v) {
            if (!is_array($v)) continue;
            $term = trim((string)($v['term'] ?? ''));
            if ($term === '') continue;
            $variants[] = [
                'term'    => $term,
                'sources' => array_values(array_filter(array_map(
                    fn($s) => trim((string)$s),
                    (array)($v['sources'] ?? [])
                ), fn($s) => $s !== '')),
                'tone'    => trim((string)($v['tone'] ?? '')),
            ];
        }
        if ($concept !== '' && count($variants) >= 2) {
            $terms[] = ['concept' => $concept, 'variants' => $variants];
        }
    }

    $framings = [];
    foreach ((array)($p['framings'] ?? []) as $f) {
        if (!is_array($f)) continue;
        $angle = trim((string)($f['angle'] ?? ''));
        if ($angle === '') continue;
        $framings[] = [
            'sources'  => array_values(array_filter(array_map(
                fn($s) => trim((string)$s),
                (array)($f['sources'] ?? [])
            ), fn($s) => $s !== '')),
            'angle'    => $angle,
            'emphasis' => trim((string)($f['emphasis'] ?? '')),
        ];
    }

    $row = [
        'cluster_key'     => $clusterKey,
        'article_count'   => count($articles),
        'source_count'    => $srcCount,
        'neutral_summary' => trim((string)($p['neutral_summary'] ?? '')),
        'divergent_terms' => $terms,
        'framings'        => $framings,
    ];

    if ($row['neutral_summary'] === '' && empty($terms) && empty($framings)) {
        return null;
    }

    // Persist.
    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO cluster_mirror
                (cluster_key, article_count, source_count, neutral_summary, divergent_terms, framings)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                article_count   = VALUES(article_count),
                source_count    = VALUES(source_count),
                neutral_summary = VALUES(neutral_summary),
                divergent_terms = VALUES(divergent_terms),
                framings        = VALUES(framings),
                generated_at    = NOW()"
        )->execute([
            $clusterKey,
            $row['article_count'],
            $row['source_count'],
            $row['neutral_summary'],
            json_encode($row['divergent_terms'], JSON_UNESCAPED_UNICODE),
            json_encode($row['framings'], JSON_UNESCAPED_UNICODE),
        ]);
        cache_forget('mirror_' . $clusterKey);
    } catch (Throwable $e) {
        error_log('[news_mirror] persist: ' . $e->getMessage());
    }

    return $row;
}

/**
 * Get-or-generate: returns cached mirror or generates on the fly.
 * Pass $articles so it can generate if needed. If $articles is empty
 * and no cache exists, returns null (won't query DB for articles).
 */
function news_mirror_for_cluster(string $clusterKey, array $articles = []): ?array {
    $cached = news_mirror_get($clusterKey, count($articles));
    if ($cached) return $cached;
    if (news_mirror_distinct_sources($articles) < 2) return null;
    return news_mirror_generate($clusterKey, $articles);
}
