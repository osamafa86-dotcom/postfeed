<?php
/**
 * نيوزفلو — بطاقات الإيجاز الذكي (Smart Brevity)
 *
 * Generates an Axios-style structured breakdown for article clusters:
 *   - لماذا يهم (Why it matters)
 *   - الصورة الأكبر (The big picture)
 *   - بالأرقام (By the numbers)
 *   - ماذا يقولون (What they're saying)
 *   - تقريب العدسة (Zoom in)
 *
 * One Gemini call per cluster, stored in cluster_brevity, cached via
 * cache_remember for 30 min. Lazy-generated on first view.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/ai_provider.php';

function smart_brevity_ensure_table(): void {
    static $done = false;
    if ($done) return;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS cluster_brevity (
            cluster_key CHAR(40) NOT NULL PRIMARY KEY,
            article_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            why_matters TEXT NOT NULL,
            big_picture TEXT NOT NULL,
            by_the_numbers TEXT,
            what_they_say TEXT,
            zoom_in TEXT NOT NULL,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_generated (generated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    } catch (Throwable $e) {
        error_log('[smart_brevity] ensure_table: ' . $e->getMessage());
    }
}

/**
 * Get cached brevity for a cluster. Returns null if not generated yet
 * or if the cluster has grown significantly since last generation.
 */
function smart_brevity_get(string $clusterKey, int $currentCount = 0): ?array {
    smart_brevity_ensure_table();
    $cacheKey = 'brevity_' . $clusterKey;
    return cache_remember($cacheKey, 1800, function () use ($clusterKey, $currentCount) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM cluster_brevity WHERE cluster_key = ?");
            $stmt->execute([$clusterKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            // Stale if cluster grew by ≥3 articles since generation.
            if ($currentCount > 0 && ($currentCount - (int)$row['article_count']) >= 3) {
                return null;
            }
            $row['by_the_numbers'] = json_decode((string)$row['by_the_numbers'], true) ?: [];
            $row['what_they_say']  = json_decode((string)$row['what_they_say'], true) ?: [];
            return $row;
        } catch (Throwable $e) {
            return null;
        }
    });
}

/**
 * Generate Smart Brevity for a cluster from its articles.
 * Requires ≥2 articles to be meaningful.
 */
function smart_brevity_generate(string $clusterKey, array $articles): ?array {
    if (count($articles) < 2) return null;
    smart_brevity_ensure_table();

    // Build a compact corpus from article titles + summaries.
    $lines = [];
    $budget = 8000;
    $used = 0;
    foreach ($articles as $a) {
        $title   = trim((string)($a['title'] ?? ''));
        $summary = trim(strip_tags((string)($a['ai_summary'] ?? $a['excerpt'] ?? '')));
        if (mb_strlen($summary) > 400) $summary = mb_substr($summary, 0, 400) . '…';
        $source = trim((string)($a['source_name'] ?? ''));
        $line = "- [{$source}] {$title}\n  {$summary}";
        $len = mb_strlen($line);
        if ($used + $len > $budget) break;
        $lines[] = $line;
        $used += $len + 1;
    }

    $corpus = implode("\n", $lines);
    $count  = count($lines);

    $prompt = "أنت محرر أخبار متخصّص في أسلوب Smart Brevity (الإيجاز الذكي). لديك {$count} تقريراً من مصادر متعدّدة "
            . "تغطي نفس الخبر. مهمتك: استخراج تحليل موجز وذكي بالعربية.\n\n"
            . "قواعد:\n"
            . "- لا تخترع معلومات غير موجودة في التقارير.\n"
            . "- \"لماذا يهم\" = جملتان قويتان عن الأثر الحقيقي.\n"
            . "- \"الصورة الأكبر\" = السياق الأوسع، كيف يتصل بأحداث أخرى.\n"
            . "- \"بالأرقام\" = 2-4 أرقام/إحصائيات محددة وردت في التقارير.\n"
            . "- \"ماذا يقولون\" = 1-3 تصريحات منسوبة لأشخاص محددين.\n"
            . "- \"تقريب العدسة\" = التفصيلة الأكثر إثارة التي قد يفوّتها القارئ.\n\n"
            . "التقارير:\n" . $corpus;

    $tool = [
        'name'        => 'submit_smart_brevity',
        'description' => 'Submit a Smart Brevity analysis of a news cluster.',
        'input_schema' => [
            'type'     => 'object',
            'properties' => [
                'why_matters' => [
                    'type'        => 'string',
                    'description' => 'لماذا يهم — جملتان عن الأثر الحقيقي لهذا الخبر.',
                ],
                'big_picture' => [
                    'type'        => 'string',
                    'description' => 'الصورة الأكبر — السياق الأوسع وكيف يتصل بأحداث أخرى.',
                ],
                'by_the_numbers' => [
                    'type'        => 'array',
                    'description' => 'بالأرقام — 2-4 أرقام/إحصائيات مع سياق.',
                    'items'       => [
                        'type' => 'object',
                        'properties' => [
                            'value'   => ['type' => 'string', 'description' => 'الرقم أو الإحصائية.'],
                            'context' => ['type' => 'string', 'description' => 'سياق الرقم في جملة.'],
                        ],
                        'required' => ['value', 'context'],
                    ],
                ],
                'what_they_say' => [
                    'type'        => 'array',
                    'description' => 'ماذا يقولون — 1-3 تصريحات منسوبة.',
                    'items'       => [
                        'type' => 'object',
                        'properties' => [
                            'speaker' => ['type' => 'string', 'description' => 'اسم المتحدث ومنصبه.'],
                            'quote'   => ['type' => 'string', 'description' => 'نص التصريح.'],
                        ],
                        'required' => ['speaker', 'quote'],
                    ],
                ],
                'zoom_in' => [
                    'type'        => 'string',
                    'description' => 'تقريب العدسة — التفصيلة الأكثر إثارة التي قد يفوّتها القارئ.',
                ],
            ],
            'required' => ['why_matters', 'big_picture', 'by_the_numbers', 'what_they_say', 'zoom_in'],
        ],
    ];

    $call = ai_provider_tool_call($prompt, $tool, 1500);
    if (empty($call['ok']) || !is_array($call['input'])) {
        error_log('[smart_brevity] AI failed for ' . $clusterKey . ': ' . ($call['error'] ?? ''));
        return null;
    }

    $p = $call['input'];
    $row = [
        'cluster_key'    => $clusterKey,
        'article_count'  => count($articles),
        'why_matters'    => trim((string)($p['why_matters'] ?? '')),
        'big_picture'    => trim((string)($p['big_picture'] ?? '')),
        'by_the_numbers' => (array)($p['by_the_numbers'] ?? []),
        'what_they_say'  => (array)($p['what_they_say'] ?? []),
        'zoom_in'        => trim((string)($p['zoom_in'] ?? '')),
    ];

    // Persist.
    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO cluster_brevity
                (cluster_key, article_count, why_matters, big_picture, by_the_numbers, what_they_say, zoom_in)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                article_count  = VALUES(article_count),
                why_matters    = VALUES(why_matters),
                big_picture    = VALUES(big_picture),
                by_the_numbers = VALUES(by_the_numbers),
                what_they_say  = VALUES(what_they_say),
                zoom_in        = VALUES(zoom_in),
                generated_at   = NOW()"
        )->execute([
            $clusterKey,
            $row['article_count'],
            $row['why_matters'],
            $row['big_picture'],
            json_encode($row['by_the_numbers'], JSON_UNESCAPED_UNICODE),
            json_encode($row['what_they_say'], JSON_UNESCAPED_UNICODE),
            $row['zoom_in'],
        ]);
        cache_forget('brevity_' . $clusterKey);
    } catch (Throwable $e) {
        error_log('[smart_brevity] persist: ' . $e->getMessage());
    }

    return $row;
}

/**
 * Get-or-generate: returns cached brevity or generates on the fly.
 * Pass $articles so it can generate if needed. If $articles is empty
 * and no cache exists, returns null (won't query DB for articles).
 */
function smart_brevity_for_cluster(string $clusterKey, array $articles = []): ?array {
    $cached = smart_brevity_get($clusterKey, count($articles));
    if ($cached) return $cached;
    if (empty($articles) || count($articles) < 2) return null;
    return smart_brevity_generate($clusterKey, $articles);
}
