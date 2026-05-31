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
function sabah_collect_top_clusters(int $maxClusters = 8): array {
    try {
        $db = getDB();
        $limitSql = max(1, min(20, $maxClusters));

        // First pass: clusters with multi-source consensus (highest quality).
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
                 LIMIT {$limitSql}";
        $clusters = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // Second pass: if we got nothing or too few, fall back to top
        // single-source clusters so the briefing still ships on slow days.
        if (count($clusters) < 2) {
            $sqlFallback = "SELECT a.cluster_key,
                                   1 AS src_count,
                                   COUNT(*) AS art_count,
                                   MAX(a.published_at) AS latest_at,
                                   MAX(s.name) AS source_names
                              FROM articles a
                              LEFT JOIN sources s ON a.source_id = s.id
                             WHERE a.status = 'published'
                               AND a.cluster_key IS NOT NULL AND a.cluster_key <> '-'
                               AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                             GROUP BY a.cluster_key
                             ORDER BY art_count DESC, latest_at DESC
                             LIMIT {$limitSql}";
            $clusters = $db->query($sqlFallback)->fetchAll(PDO::FETCH_ASSOC);
            error_log('[sabah] using single-source fallback — only ' . count($clusters) . ' clusters available');
        }

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
    if ($data['count'] < 1) {
        error_log('[sabah] no clusters available in the last 24h — skipping generation');
        return null;
    }

    $prompt = "أنت رئيس تحرير نشرة \"صباح الخير من نيوز فيد\" — نشرة صباحية يومية بأسلوب NYT The Morning. "
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
        // Distinguish AI failure from "not enough news" — the old code
        // returned null for both, so a Gemini 429 looked identical to a
        // quiet news day in the cron output ("not enough clusters?").
        // Stash the real reason where the cron can read it.
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
