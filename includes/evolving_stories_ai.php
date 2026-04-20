<?php
/**
 * نيوز فيد — استخراج AI لبيانات القصص المتطوّرة
 * (Evolving Stories Phase 2 — entities + quotes extraction)
 *
 * This module powers two features on top of the evolving stories:
 *
 *   1. "Story by Numbers" — a dashboard of the key people, locations,
 *      organizations and terms appearing in the story's coverage.
 *   2. "Quote Wall" — a curated collection of direct quotes pulled out
 *      of the underlying articles, attributed to their speakers.
 *
 * Both are fed by a single Claude Haiku pass per new article. We walk
 * the story's unprocessed articles (tracked in evolving_story_extractions)
 * in small batches, send Claude the title + excerpt + ai_summary, and
 * force structured output via Anthropic tool-use so the parser can
 * never fail on stray prose.
 *
 * Nothing here is on the hot RSS-ingest path. cron_rss.php still owns
 * the keyword matcher; this file is only called from:
 *
 *   - cron_evolving_ai.php (nightly)
 *   - panel/evolving_stories.php (manual "Extract now" button)
 *
 * So a Claude outage, quota exhaustion or schema drift never blocks
 * article ingestion.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/evolving_stories.php';
require_once __DIR__ . '/ai_provider.php';

/**
 * Create the phase-2 tables if they don't already exist. Same lazy
 * pattern as evolving_stories_ensure_tables() so a fresh deploy on
 * shared hosting (no migration runner) still works.
 */
function evolving_stories_ai_ensure_tables(): void {
    static $ensured = false;
    if ($ensured) return;
    evolving_stories_ensure_tables();
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS evolving_story_entities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            story_id INT NOT NULL,
            entity_type VARCHAR(24) NOT NULL,
            entity_name VARCHAR(180) NOT NULL,
            mention_count INT UNSIGNED NOT NULL DEFAULT 1,
            first_seen_at TIMESTAMP NULL DEFAULT NULL,
            last_seen_at TIMESTAMP NULL DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_story_type_name (story_id, entity_type, entity_name),
            KEY idx_story_type_count (story_id, entity_type, mention_count)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS evolving_story_quotes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            story_id INT NOT NULL,
            article_id INT NOT NULL,
            quote_text TEXT NOT NULL,
            speaker VARCHAR(180) NOT NULL DEFAULT '',
            speaker_role VARCHAR(180) NOT NULL DEFAULT '',
            context VARCHAR(500) NOT NULL DEFAULT '',
            quote_hash CHAR(40) NOT NULL,
            published_at TIMESTAMP NULL DEFAULT NULL,
            extracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_story_hash (story_id, quote_hash),
            KEY idx_story_published (story_id, published_at),
            KEY idx_article (article_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS evolving_story_extractions (
            story_id INT NOT NULL,
            article_id INT NOT NULL,
            extracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(16) NOT NULL DEFAULT 'ok',
            PRIMARY KEY (story_id, article_id),
            KEY idx_extracted_at (extracted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $ensured = true;
    } catch (Throwable $e) {
        error_log('[evolving_stories_ai] ensure_tables: ' . $e->getMessage());
    }
}

/**
 * Send one article's title + excerpt + summary to the configured AI
 * provider and receive back a structured {entities, quotes} object via
 * forced tool-use.
 *
 * Returns ['ok'=>bool, 'entities'=>array, 'quotes'=>array, 'error'=>?]
 */
function evolving_stories_ai_extract_article(array $article): array {
    $title   = (string)($article['title']      ?? '');
    $excerpt = (string)($article['excerpt']    ?? '');
    $summary = (string)($article['ai_summary'] ?? '');
    // Cap each field so we stay comfortably within Haiku's context
    // and don't pay for noise. ai_summary is usually the densest
    // signal so we give it the biggest budget.
    $body_text = mb_substr(strip_tags($summary), 0, 2500) . "\n\n"
               . mb_substr(strip_tags($excerpt), 0, 1500);

    $prompt = "أنت محلل بيانات إخبارية. لديك تقرير إخباري، ومهمتك استخراج:\n"
            . "1) الكيانات (Entities): الأشخاص، الأماكن، المنظمات، والمصطلحات الرئيسية المذكورة.\n"
            . "2) الاقتباسات المباشرة (Quotes): أقوال منقولة بين علامتي تنصيص أو تلميحات صريحة لكلام شخص.\n\n"
            . "قواعد صارمة:\n"
            . "- لا تخترع أي كيان أو اقتباس غير موجود نصّاً في التقرير.\n"
            . "- الكيانات: استخرج فقط الأسماء الصريحة (ليس الضمائر، ليس الأوصاف العامة).\n"
            . "- نوع الكيان يكون واحداً من: person | location | organization | term.\n"
            . "- term = مصطلح/قضية محورية (مثل: صفقة التبادل، العدوان، الهدنة).\n"
            . "- الاقتباسات: يجب أن يكون القول منسوباً لشخص محدّد (حتى لو كان 'مصدر أمني').\n"
            . "- اقتباس واحد لكل شخص لكل موضوع — لا تكرّر.\n"
            . "- speaker = الاسم، speaker_role = المنصب/الجهة، context = جملة سياق قصيرة.\n"
            . "- إذا لم يوجد اقتباس واضح، أعد مصفوفة فارغة.\n\n"
            . "العنوان: {$title}\n\n"
            . "النص:\n{$body_text}\n\n"
            . "استدعِ الأداة submit_story_extraction مباشرةً بالنتيجة.";

    $tool = [
        'name'        => 'submit_story_extraction',
        'description' => 'Submit extracted entities and quotes from a news article.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'entities' => [
                    'type' => 'array',
                    'description' => 'الكيانات المستخرجة',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => ['person', 'location', 'organization', 'term'],
                            ],
                            'name' => ['type' => 'string'],
                        ],
                        'required' => ['type', 'name'],
                    ],
                ],
                'quotes' => [
                    'type' => 'array',
                    'description' => 'الاقتباسات المباشرة',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text'    => ['type' => 'string', 'description' => 'نص الاقتباس'],
                            'speaker' => ['type' => 'string', 'description' => 'اسم المتحدث'],
                            'role'    => ['type' => 'string', 'description' => 'منصب/جهة المتحدث'],
                            'context' => ['type' => 'string', 'description' => 'سياق قصير يوضح متى قيل الاقتباس'],
                        ],
                        'required' => ['text', 'speaker'],
                    ],
                ],
            ],
            'required' => ['entities', 'quotes'],
        ],
    ];

    $call = ai_provider_tool_call($prompt, $tool, 2000);
    if (empty($call['ok'])) {
        return ['ok' => false, 'error' => (string)($call['error'] ?? 'AI call failed')];
    }
    $parsed = $call['input'];
    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => 'Failed to parse AI response'];
    }

    $entities = [];
    foreach ((array)($parsed['entities'] ?? []) as $e) {
        if (!is_array($e)) continue;
        $type = (string)($e['type'] ?? '');
        $name = trim((string)($e['name'] ?? ''));
        if ($name === '' || !in_array($type, ['person','location','organization','term'], true)) continue;
        // Light normalization: collapse whitespace, cap length.
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = mb_substr($name, 0, 180);
        $entities[] = ['type' => $type, 'name' => $name];
    }

    $quotes = [];
    foreach ((array)($parsed['quotes'] ?? []) as $q) {
        if (!is_array($q)) continue;
        $text    = trim((string)($q['text']    ?? ''));
        $speaker = trim((string)($q['speaker'] ?? ''));
        if ($text === '' || $speaker === '') continue;
        // Strip common quote glyphs so dedup by hash is stable.
        $clean = preg_replace('/["«»“”„‟‹›"\'`]/u', '', $text);
        $clean = preg_replace('/\s+/u', ' ', $clean);
        $quotes[] = [
            'text'    => mb_substr($clean, 0, 800),
            'speaker' => mb_substr($speaker, 0, 180),
            'role'    => mb_substr(trim((string)($q['role']    ?? '')), 0, 180),
            'context' => mb_substr(trim((string)($q['context'] ?? '')), 0, 500),
        ];
    }

    return ['ok' => true, 'entities' => $entities, 'quotes' => $quotes];
}

/**
 * Persist the extracted entities + quotes for one (story, article)
 * pair. Uses ON DUPLICATE KEY / unique-hash semantics so re-running
 * extraction is idempotent.
 */
function evolving_stories_ai_persist(int $storyId, array $article, array $result): void {
    evolving_stories_ai_ensure_tables();
    if ($storyId <= 0 || empty($article['id'])) return;

    $articleId = (int)$article['id'];
    $publishedAt = (string)($article['published_at'] ?? date('Y-m-d H:i:s'));

    try {
        $db = getDB();

        // --- entities -----------------------------------------
        $ent = $db->prepare(
            "INSERT INTO evolving_story_entities
                (story_id, entity_type, entity_name, mention_count, first_seen_at, last_seen_at)
             VALUES (?, ?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                mention_count = mention_count + 1,
                last_seen_at  = GREATEST(last_seen_at, VALUES(last_seen_at))"
        );
        foreach (($result['entities'] ?? []) as $e) {
            $ent->execute([$storyId, $e['type'], $e['name'], $publishedAt, $publishedAt]);
        }

        // --- quotes -------------------------------------------
        $q = $db->prepare(
            "INSERT IGNORE INTO evolving_story_quotes
                (story_id, article_id, quote_text, speaker, speaker_role, context, quote_hash, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach (($result['quotes'] ?? []) as $qu) {
            $hash = sha1(mb_strtolower($qu['text'] . '|' . $qu['speaker']));
            $q->execute([
                $storyId, $articleId,
                $qu['text'], $qu['speaker'], $qu['role'], $qu['context'],
                $hash, $publishedAt,
            ]);
        }

        // --- mark processed -----------------------------------
        $db->prepare(
            "INSERT INTO evolving_story_extractions (story_id, article_id, status)
             VALUES (?, ?, 'ok')
             ON DUPLICATE KEY UPDATE extracted_at = NOW(), status = 'ok'"
        )->execute([$storyId, $articleId]);
    } catch (Throwable $e) {
        error_log('[evolving_stories_ai] persist: ' . $e->getMessage());
    }
}

/**
 * Mark an article as "tried but failed" so the cron doesn't retry it
 * forever and burn the Claude quota on a broken input.
 */
function evolving_stories_ai_mark_failed(int $storyId, int $articleId, string $reason = ''): void {
    evolving_stories_ai_ensure_tables();
    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO evolving_story_extractions (story_id, article_id, status)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE extracted_at = NOW(), status = VALUES(status)"
        )->execute([$storyId, $articleId, mb_substr('err:' . $reason, 0, 16)]);
    } catch (Throwable $e) {}
}

/**
 * Find up to $limit published articles in this story that have not
 * yet been extracted. Returns newest first so a partial run always
 * covers the most recent coverage first.
 */
function evolving_stories_ai_pending(int $storyId, int $limit = 10): array {
    evolving_stories_ai_ensure_tables();
    if ($storyId <= 0) return [];
    try {
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT a.id, a.title, a.excerpt, a.ai_summary, a.published_at
               FROM evolving_story_articles esa
               JOIN articles a ON a.id = esa.article_id
          LEFT JOIN evolving_story_extractions x
                 ON x.story_id = esa.story_id
                AND x.article_id = esa.article_id
              WHERE esa.story_id = ?
                AND a.status = 'published'
                AND x.article_id IS NULL
           ORDER BY a.published_at DESC
              LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute([$storyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[evolving_stories_ai] pending: ' . $e->getMessage());
        return [];
    }
}

/**
 * Run the extraction for a single story. Walks the pending list, calls
 * Claude per article, persists, and returns a summary of what happened.
 *
 * Caller is responsible for scheduling (cron or manual button). The
 * whole loop stays under $perRunBudget API calls so a runaway story
 * can't exhaust the Anthropic quota.
 */
function evolving_stories_ai_extract_story(int $storyId, int $perRunBudget = 8): array {
    evolving_stories_ai_ensure_tables();
    $summary = ['ok' => true, 'processed' => 0, 'failed' => 0, 'entities' => 0, 'quotes' => 0];
    $pending = evolving_stories_ai_pending($storyId, $perRunBudget);
    if (empty($pending)) return $summary;

    foreach ($pending as $art) {
        $res = evolving_stories_ai_extract_article($art);
        if (!empty($res['ok'])) {
            evolving_stories_ai_persist($storyId, $art, $res);
            $summary['processed']++;
            $summary['entities'] += count($res['entities'] ?? []);
            $summary['quotes']   += count($res['quotes']   ?? []);
        } else {
            evolving_stories_ai_mark_failed($storyId, (int)$art['id'], (string)($res['error'] ?? ''));
            $summary['failed']++;
        }
    }

    // Bust the dashboard cache so readers see the fresh numbers.
    cache_forget('evolving_story_dashboard_' . $storyId);
    cache_forget('evolving_story_quotes_' . $storyId);
    return $summary;
}

/**
 * Top entities for a story, grouped by type. Used by the dashboard.
 * Capped at $perType each so the UI never paginates.
 */
function evolving_stories_ai_dashboard(int $storyId, int $perType = 6): array {
    evolving_stories_ai_ensure_tables();
    $out = [
        'people'       => [],
        'locations'    => [],
        'organizations'=> [],
        'terms'        => [],
        'totals'       => ['entities' => 0, 'quotes' => 0],
    ];
    if ($storyId <= 0) return $out;

    try {
        $db = getDB();
        $map = [
            'person'       => 'people',
            'location'     => 'locations',
            'organization' => 'organizations',
            'term'         => 'terms',
        ];
        foreach ($map as $type => $key) {
            $stmt = $db->prepare(
                "SELECT entity_name, mention_count, last_seen_at
                   FROM evolving_story_entities
                  WHERE story_id = ? AND entity_type = ?
               ORDER BY mention_count DESC, last_seen_at DESC
                  LIMIT " . max(1, min(30, $perType))
            );
            $stmt->execute([$storyId, $type]);
            $out[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Totals for the header badge row.
        $stmt = $db->prepare("SELECT COUNT(*) FROM evolving_story_entities WHERE story_id = ?");
        $stmt->execute([$storyId]);
        $out['totals']['entities'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM evolving_story_quotes WHERE story_id = ?");
        $stmt->execute([$storyId]);
        $out['totals']['quotes'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[evolving_stories_ai] dashboard: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Paginated list of quotes for the Quote Wall page. Also used by the
 * "Top 3 quotes" preview on the main story page.
 */
function evolving_stories_ai_quotes(int $storyId, int $limit = 50, int $offset = 0): array {
    evolving_stories_ai_ensure_tables();
    if ($storyId <= 0) return [];
    $limit  = max(1, min(200, $limit));
    $offset = max(0, $offset);
    try {
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT q.id, q.quote_text, q.speaker, q.speaker_role, q.context,
                    q.published_at, q.article_id,
                    a.title AS article_title, a.slug AS article_slug,
                    s.name AS source_name
               FROM evolving_story_quotes q
               JOIN articles a ON a.id = q.article_id
          LEFT JOIN sources s ON a.source_id = s.id
              WHERE q.story_id = ?
                AND a.status = 'published'
           ORDER BY q.published_at DESC, q.id DESC
              LIMIT $limit OFFSET $offset"
        );
        $stmt->execute([$storyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[evolving_stories_ai] quotes: ' . $e->getMessage());
        return [];
    }
}

/**
 * Cheap count for the Quote Wall pagination footer.
 */
function evolving_stories_ai_quote_count(int $storyId): int {
    evolving_stories_ai_ensure_tables();
    if ($storyId <= 0) return 0;
    try {
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT COUNT(*)
               FROM evolving_story_quotes q
               JOIN articles a ON a.id = q.article_id
              WHERE q.story_id = ? AND a.status = 'published'"
        );
        $stmt->execute([$storyId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
