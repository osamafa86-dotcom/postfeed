<?php
/**
 * نيوزفلو — موجز الصباح (Morning Briefing)
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
        $done = true;
    } catch (Throwable $e) {
        error_log('[sabah] ensure_table: ' . $e->getMessage());
    }
}

/**
 * Pick the top clusters from the last 24 hours, ranked by distinct
 * source count, then by most recent article. Returns a corpus string
 * suitable for the prompt.
 */
function sabah_collect_top_clusters(int $maxClusters = 8): array {
    try {
        $db = getDB();
        $sql = "SELECT a.cluster_key,
                       COUNT(DISTINCT a.source_id) AS src_count,
                       COUNT(*) AS art_count,
                       MAX(a.published_at) AS latest_at,
                       GROUP_CONCAT(DISTINCT s.name SEPARATOR '، ') AS source_names
                  FROM articles a
                  LEFT JOIN sources s ON a.source_id = s.id
                 WHERE a.status = 'published'
                   AND a.cluster_key IS NOT NULL AND a.cluster_key <> '-'
                   AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 GROUP BY a.cluster_key
                HAVING src_count >= 2
                 ORDER BY src_count DESC, latest_at DESC
                 LIMIT " . max(1, min(20, $maxClusters));
        $clusters = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (empty($clusters)) return ['corpus' => '', 'count' => 0, 'article_count' => 0];

        $lines = [];
        $totalArticles = 0;
        $budget = 25000;
        $used = 0;
        foreach ($clusters as $idx => $cl) {
            $ck = $cl['cluster_key'];
            $label = 'C' . ($idx + 1);
            // Get representative articles for this cluster.
            $stmt = $db->prepare(
                "SELECT a.title, a.ai_summary, a.excerpt, s.name AS source_name
                   FROM articles a
                   LEFT JOIN sources s ON a.source_id = s.id
                  WHERE a.cluster_key = ? AND a.status = 'published'
                  ORDER BY LENGTH(a.title) DESC
                  LIMIT 3"
            );
            $stmt->execute([$ck]);
            $arts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $block = "[{$label}] ({$cl['src_count']} مصادر: {$cl['source_names']})\n";
            foreach ($arts as $art) {
                $summary = trim(strip_tags((string)($art['ai_summary'] ?? $art['excerpt'] ?? '')));
                if (mb_strlen($summary) > 300) $summary = mb_substr($summary, 0, 300) . '…';
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
    $data = sabah_collect_top_clusters(8);
    if ($data['count'] < 2) return null;

    $prompt = "أنت رئيس تحرير نشرة \"صباح الخير من نيوزفلو\" — نشرة صباحية يومية بأسلوب NYT The Morning. "
            . "لديك {$data['count']} ملفات إخبارية بارزة من آخر 24 ساعة. مهمتك: كتابة موجز صباحي "
            . "احترافي ممتع بالعربية الفصحى. هذه ليست قائمة عناوين، بل مقال صباحي قصير يروي القصص بسياق.\n\n"
            . "التعليمات:\n"
            . "- العنوان (headline): جملة جذابة أقل من 80 حرفاً تمثل أبرز ما يحدث اليوم.\n"
            . "- الافتتاحية (hook): فقرة من 3-5 جمل بصوت تحريري دافئ. تبدأ بأبرز خبر ثم تربط بالمحاور الأخرى.\n"
            . "- الأقسام (sections): 3-6 أقسام، كل قسم يمثّل محوراً إخبارياً.\n"
            . "  · لكل قسم: عنوان + icon + فقرة من 2-4 جمل تروي القصة بالسياق وليس مجرد ملخّص.\n"
            . "- السؤال الختامي (closing_question): سؤال مفتوح يحفّز القارئ على التفكير.\n"
            . "- لا تخترع معلومات غير موجودة في الملفات.\n"
            . "- استخدم أسلوباً صحفياً دافئاً كأنك تكتب لصديق مثقّف.\n\n"
            . "الملفات الإخبارية:\n" . $data['corpus'];

    $tool = [
        'name'        => 'submit_sabah_briefing',
        'description' => 'Submit the morning editorial briefing.',
        'input_schema' => [
            'type'     => 'object',
            'properties' => [
                'headline' => [
                    'type'        => 'string',
                    'description' => 'عنوان رئيسي جذاب أقل من 80 حرفاً.',
                ],
                'hook' => [
                    'type'        => 'string',
                    'description' => 'فقرة افتتاحية من 3-5 جمل بصوت تحريري دافئ.',
                ],
                'sections' => [
                    'type'        => 'array',
                    'description' => '3-6 أقسام إخبارية.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'عنوان القسم.'],
                            'icon'  => ['type' => 'string', 'description' => 'رمز emoji واحد.'],
                            'body'  => ['type' => 'string', 'description' => 'فقرة من 2-4 جمل تحكي القصة بسياق.'],
                        ],
                        'required' => ['title', 'body'],
                    ],
                ],
                'closing_question' => [
                    'type'        => 'string',
                    'description' => 'سؤال ختامي مفتوح يحفّز التفكير.',
                ],
            ],
            'required' => ['headline', 'hook', 'sections', 'closing_question'],
        ],
    ];

    $call = ai_provider_tool_call($prompt, $tool, 3000);
    if (empty($call['ok']) || !is_array($call['input'])) {
        error_log('[sabah] AI failed: ' . ($call['error'] ?? ''));
        return null;
    }

    $p = $call['input'];
    $sections = [];
    foreach ((array)($p['sections'] ?? []) as $sec) {
        if (!is_array($sec)) continue;
        $title = trim((string)($sec['title'] ?? ''));
        $body  = trim((string)($sec['body']  ?? ''));
        if ($title === '' || $body === '') continue;
        $sections[] = [
            'title' => $title,
            'icon'  => trim((string)($sec['icon'] ?? '')),
            'body'  => $body,
        ];
    }

    return [
        'headline'         => trim((string)($p['headline'] ?? '')),
        'hook'             => trim((string)($p['hook'] ?? '')),
        'sections'         => $sections,
        'closing_question' => trim((string)($p['closing_question'] ?? '')),
        'article_count'    => $data['article_count'],
    ];
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
                (briefing_date, headline, hook, sections, closing_question, article_count)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                headline = VALUES(headline), hook = VALUES(hook),
                sections = VALUES(sections), closing_question = VALUES(closing_question),
                article_count = VALUES(article_count), generated_at = NOW()"
        )->execute([
            $date,
            $briefing['headline'],
            $briefing['hook'],
            json_encode($briefing['sections'], JSON_UNESCAPED_UNICODE),
            $briefing['closing_question'],
            (int)$briefing['article_count'],
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
        $row['sections'] = json_decode((string)$row['sections'], true) ?: [];
        return $row;
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
        $row['sections'] = json_decode((string)$row['sections'], true) ?: [];
        return $row;
    } catch (Throwable $e) {
        return null;
    }
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
