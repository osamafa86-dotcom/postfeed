<?php
/**
 * Smart Story Timelines — Guardian Live inspired chronological view
 * of ongoing news stories.
 *
 * A "story" is any cluster (articles sharing a cluster_key) that has
 * enough articles to justify a narrative timeline — we require at
 * least STORY_TIMELINE_MIN_ARTICLES articles before we'll call Claude
 * to aggregate events.
 *
 * The generated timeline is persisted in the story_timelines table
 * keyed by cluster_key. Regeneration is triggered when:
 *   - the article count for the cluster has changed since the last
 *     generation, OR
 *   - the stored timeline is older than STORY_TIMELINE_MAX_AGE_HOURS.
 *
 * Claude is called via the tool_use pattern (same shape used by
 * ai_summarize_telegram) so the return is always structured and
 * parsing-safe.
 */

require_once __DIR__ . '/ai_helper.php';
require_once __DIR__ . '/ai_provider.php';
require_once __DIR__ . '/cache.php';

const STORY_TIMELINE_MIN_ARTICLES   = 3;     // below this we redirect to cluster.php
const STORY_TIMELINE_MAX_AGE_HOURS  = 6;     // force regenerate after this even if article count unchanged
const STORY_TIMELINE_GEN_THROTTLE   = 120;   // seconds — prevents per-visitor thundering herd on misses
const STORY_TIMELINE_ARTICLE_LIMIT  = 40;    // cap articles fed to Claude per story
const STORY_TIMELINE_LIST_DEFAULT   = 12;

/**
 * Lazy-create the story_timelines table. Returns true on success.
 * Keyed by cluster_key (unique) so INSERT…ON DUPLICATE KEY UPDATE
 * is the natural upsert path.
 *
 * Also runs a narrow ALTER to add the `entities` column on existing
 * deployments that were created before Tier 1 shipped. A stored row
 * without entities will also be treated as stale by is_stale() so the
 * next visit regenerates it against the new schema.
 */
function story_timeline_ensure_table(): void {
    static $ensured = false;
    if ($ensured) return;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS story_timelines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cluster_key CHAR(40) NOT NULL,
            headline VARCHAR(300) NOT NULL DEFAULT '',
            intro TEXT,
            events LONGTEXT,
            topics TEXT,
            entities LONGTEXT NULL,
            article_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            source_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cluster (cluster_key),
            INDEX idx_generated_at (generated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Narrow auto-migration for tables created before Tier 1 shipped.
        $col = $db->query("SHOW COLUMNS FROM story_timelines LIKE 'entities'")->fetch();
        if (!$col) {
            $db->exec("ALTER TABLE story_timelines ADD COLUMN entities LONGTEXT NULL AFTER topics");
        }

        $ensured = true;
    } catch (Throwable $e) {
        error_log('[story_timeline] ensure_table: ' . $e->getMessage());
    }
}

/**
 * Fetch all published articles for a cluster, ordered chronologically.
 * Joins source + category metadata because the timeline cards need them.
 */
function story_timeline_fetch_articles(string $clusterKey, int $limit = STORY_TIMELINE_ARTICLE_LIMIT): array {
    if (!preg_match('/^[a-f0-9]{40}$/', $clusterKey)) return [];
    $db = getDB();
    $stmt = $db->prepare("SELECT a.id, a.title, a.slug, a.excerpt, a.image_url,
                                 a.source_url, a.ai_summary, a.ai_keywords,
                                 a.published_at, a.category_id, a.source_id,
                                 c.name AS cat_name, c.slug AS cat_slug,
                                 s.name AS source_name, s.logo_color
                            FROM articles a
                            LEFT JOIN categories c ON a.category_id = c.id
                            LEFT JOIN sources    s ON a.source_id   = s.id
                           WHERE a.cluster_key = ?
                             AND a.status = 'published'
                           ORDER BY a.published_at ASC
                           LIMIT " . (int)$limit);
    $stmt->execute([$clusterKey]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Count published articles in a cluster without hydrating them.
 * Used for the freshness check — if the count has grown since the
 * stored timeline was generated, we regenerate.
 */
function story_timeline_article_count(string $clusterKey): int {
    if (!preg_match('/^[a-f0-9]{40}$/', $clusterKey)) return 0;
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE cluster_key = ? AND status = 'published'");
    $stmt->execute([$clusterKey]);
    return (int)$stmt->fetchColumn();
}

/**
 * Count distinct sources in a cluster. Surfaced in the UI as
 * "coverage" signal (more sources = more credible).
 */
function story_timeline_source_count(string $clusterKey): int {
    if (!preg_match('/^[a-f0-9]{40}$/', $clusterKey)) return 0;
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(DISTINCT source_id) FROM articles
                           WHERE cluster_key = ? AND status = 'published' AND source_id IS NOT NULL");
    $stmt->execute([$clusterKey]);
    return (int)$stmt->fetchColumn();
}

/**
 * Decode a DB row into the shape timeline.php expects. Entities
 * default to an empty structure when the column is missing so older
 * rows stay renderable during the regeneration window.
 */
function story_timeline_hydrate(array $row): array {
    $events   = json_decode((string)($row['events']   ?? '[]'), true);
    $topics   = json_decode((string)($row['topics']   ?? '[]'), true);
    $entities = json_decode((string)($row['entities'] ?? '[]'), true);
    if (!is_array($entities)) $entities = [];
    // Ensure the three named buckets always exist so template code
    // doesn't have to null-check every access path.
    $entities += ['people' => [], 'places' => [], 'organizations' => []];
    return [
        'id'            => (int)$row['id'],
        'cluster_key'   => (string)$row['cluster_key'],
        'headline'      => (string)$row['headline'],
        'intro'         => (string)($row['intro'] ?? ''),
        'events'        => is_array($events) ? $events : [],
        'topics'        => is_array($topics) ? $topics : [],
        'entities'      => $entities,
        'article_count' => (int)$row['article_count'],
        'source_count'  => (int)$row['source_count'],
        'generated_at'  => (string)$row['generated_at'],
    ];
}

/**
 * Fetch a stored timeline by cluster_key, or null if none exists.
 */
function story_timeline_get(string $clusterKey): ?array {
    story_timeline_ensure_table();
    if (!preg_match('/^[a-f0-9]{40}$/', $clusterKey)) return null;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM story_timelines WHERE cluster_key = ?");
        $stmt->execute([$clusterKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? story_timeline_hydrate($row) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Upsert a generated timeline. Returns the hydrated row, or null on failure.
 */
function story_timeline_save(string $clusterKey, array $ai, int $articleCount, int $sourceCount): ?array {
    story_timeline_ensure_table();
    if (!preg_match('/^[a-f0-9]{40}$/', $clusterKey)) return null;
    if (empty($ai['ok'])) return null;
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO story_timelines
            (cluster_key, headline, intro, events, topics, entities, article_count, source_count, generated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                headline=VALUES(headline),
                intro=VALUES(intro),
                events=VALUES(events),
                topics=VALUES(topics),
                entities=VALUES(entities),
                article_count=VALUES(article_count),
                source_count=VALUES(source_count),
                generated_at=NOW()");
        $stmt->execute([
            $clusterKey,
            (string)($ai['headline'] ?? ''),
            (string)($ai['intro']    ?? ''),
            json_encode($ai['events']   ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($ai['topics']   ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($ai['entities'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
            $articleCount,
            $sourceCount,
        ]);
        return story_timeline_get($clusterKey);
    } catch (Throwable $e) {
        error_log('[story_timeline] save: ' . $e->getMessage());
        return null;
    }
}

/**
 * Is the stored timeline stale? Stale means: older than
 * STORY_TIMELINE_MAX_AGE_HOURS, the cluster has acquired new
 * articles since the last generation, or the stored row is from an
 * older schema version (no entities yet) and needs upgrading.
 */
function story_timeline_is_stale(array $stored, int $currentArticleCount): bool {
    if ((int)$stored['article_count'] !== $currentArticleCount) return true;
    $ageSecs = time() - (strtotime($stored['generated_at'] ?? '') ?: 0);
    if ($ageSecs > STORY_TIMELINE_MAX_AGE_HOURS * 3600) return true;
    // Schema upgrade: rows from before Tier 1 shipped have no
    // entities. Force a regeneration on next visit so the richer UI
    // actually has data to display.
    $ent = $stored['entities'] ?? [];
    $hasEntities = !empty($ent['people']) || !empty($ent['places']) || !empty($ent['organizations']);
    if (!$hasEntities) return true;
    // Schema upgrade: rows from before Tier 2 shipped have no severity
    // field on their events. Look at the first event — if it lacks
    // severity, the row was generated before Tier 2 and we regenerate.
    $events = $stored['events'] ?? [];
    if (is_array($events) && !empty($events) && empty($events[0]['severity'])) {
        return true;
    }
    return false;
}

/**
 * Build the Claude prompt and call the API via tool_use to get a
 * structured timeline back. Returns the same contract as the other
 * ai_* helpers: ['ok'=>true, ...] or ['ok'=>false, 'error'=>string].
 *
 * Each article is labeled with an A1/A2/… handle so Claude can
 * reference them by id in the events[].sources field, letting the UI
 * link back to the original article cards.
 */
function story_timeline_generate(array $articles): array {
    if (count($articles) < STORY_TIMELINE_MIN_ARTICLES) {
        return ['ok' => false, 'error' => 'لا توجد مقالات كافية لتوليد خط زمني.'];
    }

    // Build a compact corpus. Each article gets a short label (A1, A2…)
    // so Claude can cite them in the events back to us. We keep the
    // payload under ~40K chars to leave headroom for the tool schema
    // and response.
    $lines   = [];
    $labels  = [];  // label => article id
    $budget  = 40000;
    $used    = 0;
    foreach ($articles as $idx => $a) {
        $label = 'A' . ($idx + 1);
        $labels[$label] = (int)$a['id'];
        $title   = trim((string)($a['title'] ?? ''));
        $summary = trim((string)($a['ai_summary'] ?? $a['excerpt'] ?? ''));
        $summary = preg_replace('/\s+/u', ' ', strip_tags($summary));
        if (mb_strlen($summary) > 500) $summary = mb_substr($summary, 0, 500) . '…';
        $when   = !empty($a['published_at']) ? date('Y-m-d H:i', strtotime($a['published_at'])) : '';
        $source = trim((string)($a['source_name'] ?? ''));
        $block  = "[{$label}] {$when} · {$source}\n"
                . "العنوان: {$title}\n"
                . ($summary ? "الملخص: {$summary}\n" : '');
        $len    = mb_strlen($block);
        if ($used + $len > $budget) break;
        $lines[] = $block;
        $used   += $len + 2;
    }

    $corpus = implode("\n", $lines);
    $count  = count($lines);

    $prompt = "أنت محرر أخبار محترف في غرفة أخبار عربية كبيرة. تحت يدك {$count} تقريراً من "
            . "مصادر متعددة تغطي قصة إخبارية واحدة متطورة عبر الزمن. مهمتك: قراءة كل التقارير ثم "
            . "بناء \"خط زمني\" (Live Timeline) مُنظّم يُظهر كيف تطوّرت القصة، عبر استدعاء "
            . "الأداة submit_story_timeline.\n\n"
            . "تعليمات حاسمة:\n"
            . "- العنوان الرئيسي (headline) يجب أن يُلخّص القصة كلها بنبرة صحفية احترافية (أقل من 100 حرف).\n"
            . "- المقدمة (intro) فقرة من 3-5 جمل تعرض المشهد العام، كأنها افتتاحية لمقال تحليلي.\n"
            . "- الأحداث (events) يجب أن تكون مرتّبة زمنياً من الأقدم إلى الأحدث.\n"
            . "- ادمج التقارير المتشابهة في حدث واحد، ولا تكرّر نفس المعلومة في أحداث متتالية.\n"
            . "- كل حدث يمثل خطوة حقيقية في تطور القصة (إعلان، اجتماع، ضربة، قرار، رد فعل…).\n"
            . "- عدد الأحداث المثالي بين 4 و 10، حسب ما يستحق التطور الفعلي للقصة.\n"
            . "- لكل حدث اكتب عنواناً قصيراً جذّاباً (title، أقل من 90 حرفاً) وملخصاً من 2-3 جمل (summary) "
            . "يعطي السياق كاملاً لمن لم يتابع القصة من قبل.\n"
            . "- حقل date يجب أن يكون بصيغة YYYY-MM-DD مأخوذاً من أقدم تقرير يغطي الحدث.\n"
            . "- حقل sources قائمة برموز التقارير (A1, A2…) التي استندت إليها في صياغة الحدث.\n"
            . "- استخدم رمز emoji واحد واقعي لكل حدث (مثل 🛰️ 🏛️ ⚔️ 📢 🤝 🚨 🩺 🗳️ 📉).\n"
            . "- لكل حدث، إن وُجد في التقارير اقتباس مباشر مميّز (تصريح مسؤول، جملة مفتاحية) "
            . "استخرجه في الحقل quote مع اسم قائله في speaker. **لا تخترع** اقتباساً لم يرد نصّاً في التقرير — "
            . "إن لم يوجد اقتباس فعلي، اترك quote فارغاً.\n"
            . "- استخدم لغة عربية فصحى محايدة، لا تستعمل صيغاً متحيزة.\n"
            . "- لا تخترع معلومات غير موجودة في التقارير؛ التزم بما ورد حرفياً.\n"
            . "- اختر 3-6 وسوم (topics) قصيرة تُمثّل القصة بدون رمز #.\n\n"
            . "ديناميكية القصة (severity / trajectory / whats_new) — مهم جداً:\n"
            . "- severity: صنّف أهمية كل حدث ضمن أربع درجات بدقة:\n"
            . "    breaking = حدث عاجل/تحوّل كبير وجوهري في القصة (بداية حرب، اغتيال، قرار تاريخي…).\n"
            . "    major    = تطور مهم لكن ليس انقلاباً جذرياً (قرار رسمي، جولة مفاوضات حاسمة…).\n"
            . "    update   = تحديث رقمي/إضافة على ما سبق (ارتفاع حصيلة، تصريح ثانوي…).\n"
            . "    context  = حدث سياقي/خلفية تاريخية لفهم القصة وليس تطوراً جديداً.\n"
            . "- trajectory: من الحدث الثاني فصاعداً، حدّد اتجاه القصة مقارنة بالحدث السابق:\n"
            . "    escalation    = تصعيد، الأمور تتجه نحو الأسوأ.\n"
            . "    de-escalation = تهدئة، انفراج، خطوة نحو الحل.\n"
            . "    steady        = استمرار على نفس الوتيرة دون تغيّر يُذكر.\n"
            . "    shift         = تحوّل نوعي في طبيعة القصة (فاعل جديد، ساحة جديدة، أولوية مختلفة).\n"
            . "    اترك الحقل فارغاً للحدث الأول فقط.\n"
            . "- whats_new: من الحدث الثاني فصاعداً، اكتب جملة قصيرة جداً (أقل من 80 حرفاً) تُلخّص ما الجديد في هذا الحدث "
            . "مقارنة بالحدث السابق مباشرة، تبدأ غالباً بفعل ('أعلنت'، 'ارتفعت'، 'انضمت'…). اترك الحقل فارغاً للحدث الأول، "
            . "ولا تكرّر العنوان نفسه.\n\n"
            . "استخراج الكيانات (entities) — مهم جداً:\n"
            . "- people: قائمة الأشخاص المحوريين في القصة (رؤساء، وزراء، قادة، ضحايا بارزين…). "
            . "لكل شخص اسمه الكامل كما ورد في التقارير، ودوره/منصبه باختصار شديد (مثل \"رئيس الوزراء الإسرائيلي\").\n"
            . "- places: الأماكن الجغرافية البارزة في القصة (مدن، دول، مواقع حدث). "
            . "لكل مكان اسمه وسياقه المختصر (مثل \"العاصمة حيث وقعت الضربة\").\n"
            . "- organizations: المنظمات/الأحزاب/المؤسسات المذكورة (حماس، الأمم المتحدة، حزب معين…) مع سياقها.\n"
            . "- لا تُضمّن في entities إلا من ورد فعلياً في التقارير. الحد الأقصى: 8 أشخاص، 6 أماكن، 6 منظمات.\n\n"
            . "التقارير:\n" . $corpus;

    $tool = [
        'name'        => 'submit_story_timeline',
        'description' => 'Submit a structured Arabic story timeline with headline, intro, chronological events, key entities, and topic tags.',
        'input_schema' => [
            'type'     => 'object',
            'properties' => [
                'headline' => [
                    'type'        => 'string',
                    'description' => 'عنوان رئيسي قوي يلخّص القصة كلها (أقل من 100 حرف).',
                ],
                'intro' => [
                    'type'        => 'string',
                    'description' => 'فقرة افتتاحية من 3-5 جمل تعطي المشهد العام للقصة.',
                ],
                'events' => [
                    'type'        => 'array',
                    'description' => 'الأحداث الرئيسية للقصة مرتبة زمنياً من الأقدم إلى الأحدث، بين 4 و 10 أحداث.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => [
                                'type'        => 'string',
                                'description' => 'تاريخ الحدث بصيغة YYYY-MM-DD.',
                            ],
                            'icon' => [
                                'type'        => 'string',
                                'description' => 'رمز emoji واحد يعبّر عن طبيعة الحدث.',
                            ],
                            'title' => [
                                'type'        => 'string',
                                'description' => 'عنوان قصير للحدث (أقل من 90 حرفاً).',
                            ],
                            'summary' => [
                                'type'        => 'string',
                                'description' => 'ملخص الحدث في 2-3 جمل كاملة تعطي السياق.',
                            ],
                            'sources' => [
                                'type'        => 'array',
                                'description' => 'رموز التقارير المرجعية التي استند إليها الحدث (مثل A1, A3).',
                                'items'       => ['type' => 'string'],
                            ],
                            'quote' => [
                                'type'        => 'object',
                                'description' => 'اقتباس مباشر مميز ورد حرفياً في التقارير. اتركه فارغاً إن لم يوجد اقتباس فعلي.',
                                'properties'  => [
                                    'text'    => ['type' => 'string', 'description' => 'نص الاقتباس بدون علامات تنصيص.'],
                                    'speaker' => ['type' => 'string', 'description' => 'اسم قائل الاقتباس ومنصبه إن وُجد.'],
                                ],
                            ],
                            'severity' => [
                                'type'        => 'string',
                                'enum'        => ['breaking', 'major', 'update', 'context'],
                                'description' => 'درجة أهمية الحدث في سياق القصة.',
                            ],
                            'trajectory' => [
                                'type'        => 'string',
                                'enum'        => ['escalation', 'de-escalation', 'steady', 'shift', ''],
                                'description' => 'اتجاه تطور القصة مقارنة بالحدث السابق. اتركه فارغاً للحدث الأول.',
                            ],
                            'whats_new' => [
                                'type'        => 'string',
                                'description' => 'جملة واحدة قصيرة تُلخّص ما الجديد مقارنة بالحدث السابق (< 80 حرفاً). اتركها فارغة للحدث الأول.',
                            ],
                        ],
                        'required' => ['date', 'title', 'summary', 'severity'],
                    ],
                ],
                'entities' => [
                    'type'        => 'object',
                    'description' => 'الكيانات المحورية في القصة — الأشخاص والأماكن والمنظمات.',
                    'properties'  => [
                        'people' => [
                            'type'        => 'array',
                            'description' => 'الأشخاص المحوريون. الحد الأقصى 8.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string', 'description' => 'الاسم الكامل كما ورد.'],
                                    'role' => ['type' => 'string', 'description' => 'المنصب أو الدور باختصار.'],
                                ],
                                'required' => ['name'],
                            ],
                        ],
                        'places' => [
                            'type'        => 'array',
                            'description' => 'الأماكن الجغرافية البارزة. الحد الأقصى 6.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'name'    => ['type' => 'string', 'description' => 'اسم المكان.'],
                                    'context' => ['type' => 'string', 'description' => 'سياقه المختصر في القصة.'],
                                ],
                                'required' => ['name'],
                            ],
                        ],
                        'organizations' => [
                            'type'        => 'array',
                            'description' => 'المنظمات/الأحزاب/المؤسسات المذكورة. الحد الأقصى 6.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'name'    => ['type' => 'string', 'description' => 'اسم المنظمة.'],
                                    'context' => ['type' => 'string', 'description' => 'دورها في القصة باختصار.'],
                                ],
                                'required' => ['name'],
                            ],
                        ],
                    ],
                ],
                'topics' => [
                    'type'        => 'array',
                    'description' => 'وسوم قصيرة تمثّل القصة، 3-6 وسوم بدون رمز #.',
                    'items'       => ['type' => 'string'],
                ],
            ],
            'required' => ['headline', 'intro', 'events', 'topics'],
        ],
    ];

    // 6000-token cap leaves room for the richer schema (entities +
    // per-event quotes) without truncation on long stories.
    $call = ai_provider_tool_call($prompt, $tool, 6000);
    if (empty($call['ok'])) {
        return ['ok' => false, 'error' => (string)($call['error'] ?? 'تعذّر توليد الخط الزمني.')];
    }
    $parsed = $call['input'];
    if (!is_array($parsed) || empty($parsed['events'])) {
        error_log('[story_timeline] parse failed: ' . mb_substr(json_encode($parsed, JSON_UNESCAPED_UNICODE), 0, 1500));
        return ['ok' => false, 'error' => 'تعذّر توليد الخط الزمني — رد غير مكتمل.'];
    }

    // Normalize events and resolve source labels (A1…) to article ids
    // so the frontend can render links directly without another query.
    $allowedSeverity   = ['breaking', 'major', 'update', 'context'];
    $allowedTrajectory = ['escalation', 'de-escalation', 'steady', 'shift'];
    $events = [];
    foreach ((array)$parsed['events'] as $ev) {
        if (!is_array($ev)) continue;
        $title   = trim((string)($ev['title']   ?? ''));
        $summary = trim((string)($ev['summary'] ?? ''));
        if ($title === '' || $summary === '') continue;

        $rawSources = (array)($ev['sources'] ?? []);
        $sourceIds  = [];
        foreach ($rawSources as $label) {
            $label = strtoupper(trim((string)$label));
            if (isset($labels[$label])) $sourceIds[] = $labels[$label];
        }

        // Key quote — only keep when both the text and speaker fields
        // look substantive. Claude was explicitly told not to invent.
        $quote = null;
        if (isset($ev['quote']) && is_array($ev['quote'])) {
            $qText    = trim((string)($ev['quote']['text']    ?? ''));
            $qSpeaker = trim((string)($ev['quote']['speaker'] ?? ''));
            if (mb_strlen($qText) >= 12) {
                $quote = ['text' => $qText, 'speaker' => $qSpeaker];
            }
        }

        // Tier 2 — severity / trajectory / whats_new. Severity defaults
        // to 'update' (the most neutral bucket) when Claude returns
        // something unexpected so the UI never shows a missing badge.
        $severity = strtolower(trim((string)($ev['severity'] ?? '')));
        if (!in_array($severity, $allowedSeverity, true)) {
            $severity = 'update';
        }
        $trajectory = strtolower(trim((string)($ev['trajectory'] ?? '')));
        if (!in_array($trajectory, $allowedTrajectory, true)) {
            $trajectory = '';
        }
        $whatsNew = trim((string)($ev['whats_new'] ?? ''));
        // Drop "diff" copy that just echoes the title — adds no value.
        if ($whatsNew !== '' && mb_stripos($whatsNew, $title) !== false) {
            $whatsNew = '';
        }
        if (mb_strlen($whatsNew) > 140) {
            $whatsNew = mb_substr($whatsNew, 0, 138) . '…';
        }

        $events[] = [
            'date'        => trim((string)($ev['date']  ?? '')),
            'icon'        => trim((string)($ev['icon']  ?? '')),
            'title'       => $title,
            'summary'     => $summary,
            'source_ids'  => array_values(array_unique($sourceIds)),
            'quote'       => $quote,
            'severity'    => $severity,
            'trajectory'  => $trajectory,
            'whats_new'   => $whatsNew,
            'entity_refs' => [], // filled in by post-processing below
        ];
    }

    // The very first event should never carry a trajectory or
    // whats_new (nothing came before it). Force-clear in case Claude
    // ignored the prompt.
    if (!empty($events)) {
        $events[0]['trajectory'] = '';
        $events[0]['whats_new']  = '';
    }

    if (!$events) {
        return ['ok' => false, 'error' => 'لم يتم توليد أي حدث صالح.'];
    }

    // Normalize entities. Each bucket keeps at most the top N items
    // and drops any rows without a name (schema allows loose input).
    $normBucket = function(array $rows, int $cap): array {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $name = trim((string)($r['name'] ?? ''));
            if ($name === '' || mb_strlen($name) < 2) continue;
            $item = ['name' => $name];
            if (!empty($r['role']))    $item['role']    = trim((string)$r['role']);
            if (!empty($r['context'])) $item['context'] = trim((string)$r['context']);
            $out[] = $item;
            if (count($out) >= $cap) break;
        }
        return $out;
    };
    $rawEnt  = is_array($parsed['entities'] ?? null) ? $parsed['entities'] : [];
    $entities = [
        'people'        => $normBucket((array)($rawEnt['people']        ?? []), 8),
        'places'        => $normBucket((array)($rawEnt['places']        ?? []), 6),
        'organizations' => $normBucket((array)($rawEnt['organizations'] ?? []), 6),
    ];

    // Cross-reference: for each event, scan title+summary for any
    // entity name (case-insensitive, whitespace-trimmed) and record
    // which entities that event mentions. This powers the "click an
    // entity to highlight matching events" interaction in the UI
    // without needing another Claude call.
    $allEntityNames = [];
    foreach (['people', 'places', 'organizations'] as $bucket) {
        foreach ($entities[$bucket] as $e) {
            $allEntityNames[] = $e['name'];
        }
    }
    foreach ($events as &$ev) {
        $hay  = mb_strtolower($ev['title'] . ' ' . $ev['summary']);
        $refs = [];
        foreach ($allEntityNames as $name) {
            if ($name === '') continue;
            if (mb_strpos($hay, mb_strtolower($name)) !== false) {
                $refs[] = $name;
            }
        }
        $ev['entity_refs'] = array_values(array_unique($refs));
    }
    unset($ev);

    return [
        'ok'       => true,
        'headline' => (string)($parsed['headline'] ?? ''),
        'intro'    => (string)($parsed['intro']    ?? ''),
        'events'   => $events,
        'entities' => $entities,
        'topics'   => array_values(array_filter(array_map('strval', (array)($parsed['topics'] ?? [])))),
    ];
}

/**
 * High-level entry point: return a timeline for a cluster, generating
 * and caching as needed. Returns null if the cluster doesn't have
 * enough articles (caller should redirect to cluster.php in that case).
 *
 * A short cache-based throttle prevents two concurrent visitors from
 * triggering duplicate Claude calls when the stored timeline is
 * stale — the second visitor gets the stored (stale) copy while the
 * first is regenerating.
 */
function story_timeline_for(string $clusterKey): ?array {
    if (!preg_match('/^[a-f0-9]{40}$/', $clusterKey)) return null;

    $count = story_timeline_article_count($clusterKey);
    if ($count < STORY_TIMELINE_MIN_ARTICLES) return null;

    $stored = story_timeline_get($clusterKey);

    // Fresh hit — return immediately.
    if ($stored && !story_timeline_is_stale($stored, $count)) {
        return $stored;
    }

    // Stale or missing — check the throttle before calling Claude.
    $throttleKey = 'story_timeline_gen_' . $clusterKey;
    $lastAttempt = (int)(cache_get($throttleKey) ?: 0);
    if ($lastAttempt > 0 && (time() - $lastAttempt) < STORY_TIMELINE_GEN_THROTTLE) {
        // Another request is (probably) generating. Return the stale
        // copy if we have one rather than blocking.
        return $stored;
    }
    cache_set($throttleKey, time(), STORY_TIMELINE_GEN_THROTTLE * 2);

    $articles = story_timeline_fetch_articles($clusterKey);
    if (count($articles) < STORY_TIMELINE_MIN_ARTICLES) {
        return $stored; // degrade to whatever we have
    }

    $ai = story_timeline_generate($articles);
    if (empty($ai['ok'])) {
        error_log('[story_timeline] generate failed for ' . $clusterKey . ': ' . ($ai['error'] ?? '?'));
        return $stored; // serve stale on failure
    }

    $sourceCount = story_timeline_source_count($clusterKey);
    $saved = story_timeline_save($clusterKey, $ai, $count, $sourceCount);
    return $saved ?: $stored;
}

/**
 * List the most recently updated story timelines — used by any
 * "Ongoing Stories" discovery rail and by the homepage CTA.
 */
function story_timeline_list(int $limit = STORY_TIMELINE_LIST_DEFAULT): array {
    story_timeline_ensure_table();
    $limit = max(1, min(50, $limit));
    try {
        $db = getDB();
        $rows = $db->query("SELECT id, cluster_key, headline, intro, article_count, source_count, generated_at
                              FROM story_timelines
                             ORDER BY generated_at DESC, id DESC
                             LIMIT {$limit}")
                    ->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Find clusters in the last N days that have enough articles to
 * deserve a timeline but don't have one yet (or have a very stale one).
 * Used by a background job — or a discovery page — to seed new stories.
 */
function story_timeline_candidates(int $days = 7, int $minArticles = 4, int $limit = 20): array {
    $db = getDB();
    $days        = max(1, min(60, $days));
    $minArticles = max(STORY_TIMELINE_MIN_ARTICLES, $minArticles);
    $limit       = max(1, min(100, $limit));
    try {
        // Clusters ordered by recent article volume. We filter down to
        // "ongoing" by requiring at least 2 distinct days of coverage
        // — a single-day burst is just one event, not a story arc.
        $stmt = $db->prepare("SELECT cluster_key,
                                     COUNT(*) AS article_count,
                                     COUNT(DISTINCT source_id) AS source_count,
                                     COUNT(DISTINCT DATE(published_at)) AS day_span,
                                     MAX(published_at) AS last_seen
                                FROM articles
                               WHERE cluster_key IS NOT NULL
                                 AND cluster_key <> ''
                                 AND status = 'published'
                                 AND published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                               GROUP BY cluster_key
                              HAVING article_count >= ?
                                 AND day_span >= 2
                               ORDER BY article_count DESC, last_seen DESC
                               LIMIT {$limit}");
        $stmt->execute([$days, $minArticles]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Batch lookup: given a list of cluster keys (from a page worth of
 * article cards), return an assoc array [cluster_key => true] for
 * the keys that already have a stored story timeline. Designed to be
 * called once per page-load and stashed in a global, so the card
 * renderer doesn't hit the DB per card.
 *
 * Used by renderTimelineBadge() below and by any listing page that
 * wants to highlight "evolving story" articles inline instead of in
 * a separate discovery rail.
 */
function story_timeline_keys_for(array $clusterKeys): array {
    $clusterKeys = array_values(array_unique(array_filter(
        $clusterKeys,
        fn($k) => is_string($k) && preg_match('/^[a-f0-9]{40}$/', $k)
    )));
    if (!$clusterKeys) return [];
    try {
        story_timeline_ensure_table();
        $db    = getDB();
        $place = implode(',', array_fill(0, count($clusterKeys), '?'));
        $stmt  = $db->prepare("SELECT cluster_key FROM story_timelines
                                WHERE cluster_key IN ($place)");
        $stmt->execute($clusterKeys);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $k) {
            $out[(string)$k] = true;
        }
        return $out;
    } catch (Throwable $e) {
        // Table missing or migration still pending — degrade quietly.
        return [];
    }
}

/**
 * Inline badge for an article card. Returns '' when the article's
 * cluster does not have a timeline yet, or when the page didn't
 * pre-populate the `$GLOBALS['__nf_timeline_keys']` lookup (no fallback
 * per-article DB hit — the batch helper is the only supported path).
 *
 * Rendered as a <span role="link"> for the same reason as the cluster
 * badge: the card is already wrapped in an outer <a>, and nested
 * anchors are forbidden in HTML5. Keyboard users still get role +
 * tabindex so it stays accessible.
 */
if (!function_exists('renderTimelineBadge')) {
    function renderTimelineBadge(array $article): string {
        $key = (string)($article['cluster_key'] ?? '');
        if ($key === '' || !preg_match('/^[a-f0-9]{40}$/', $key)) return '';
        $lookup = $GLOBALS['__nf_timeline_keys'] ?? null;
        if (!is_array($lookup) || empty($lookup[$key])) return '';
        $href = '/timeline/' . $key;
        $hrefAttr = htmlspecialchars($href, ENT_QUOTES);
        return '<span class="timeline-badge" role="link" tabindex="0"'
             . ' data-href="' . $hrefAttr . '"'
             . ' title="هذا الخبر جزء من قصة متطوّرة — اعرض الخط الزمني الكامل"'
             . ' onclick="event.preventDefault();event.stopPropagation();window.location.href=\'' . $hrefAttr . '\';"'
             . ' onkeydown="if(event.key===\'Enter\'){event.preventDefault();window.location.href=\'' . $hrefAttr . '\';}"'
             . '>📅 قصة متطوّرة ›</span>';
    }
}
