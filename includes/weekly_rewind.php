<?php
/**
 * Weekly Rewind — data layer.
 *
 * One row per ISO week, generated every Saturday evening, surfaced
 * on a public /weekly/<year>-<week> page and emailed to subscribers
 * Sunday morning. The actual content (curated stories, intro copy,
 * stats, "what to watch next") lives in `content_json` so the
 * schema stays stable as the editorial format evolves.
 *
 * Lifecycle:
 *   1. cron_weekly_rewind.php → wr_generate(...)  builds & saves
 *   2. weekly.php             → wr_get_by_week    public read
 *   3. cron_weekly_rewind.php → wr_send_emails     Sunday delivery
 *   4. panel/weekly_rewind.php → admin tools (regenerate, preview)
 */

if (!function_exists('wr_ensure_table')) {

function wr_ensure_table(): void {
    static $ensured = false;
    if ($ensured) return;
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS weekly_rewinds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year_week VARCHAR(10) NOT NULL UNIQUE,        -- '2026-17'
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        cover_title VARCHAR(500) NOT NULL DEFAULT '',
        cover_subtitle VARCHAR(500) NOT NULL DEFAULT '',
        cover_image_url VARCHAR(500) NOT NULL DEFAULT '',
        intro_text TEXT,
        content_json LONGTEXT,                         -- structured editorial blocks
        stats_json TEXT,                               -- {articles, sources, breaking, ...}
        article_ids TEXT,                              -- comma-separated ids included
        view_count INT NOT NULL DEFAULT 0,
        share_count INT NOT NULL DEFAULT 0,
        published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        emailed_at TIMESTAMP NULL,
        regenerated_at TIMESTAMP NULL,
        INDEX idx_year_week (year_week),
        INDEX idx_published (published_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS weekly_rewind_deliveries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rewind_id INT NOT NULL,
        recipient_kind ENUM('subscriber','user') NOT NULL DEFAULT 'subscriber',
        recipient_id INT NOT NULL,
        delivered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        opened_at TIMESTAMP NULL,
        clicked_at TIMESTAMP NULL,
        UNIQUE KEY uniq_send (rewind_id, recipient_kind, recipient_id),
        INDEX idx_rewind (rewind_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ensured = true;
}

/** Resolve the ISO year-week label (e.g. "2026-17") for a given date. */
function wr_year_week_for(int $ts): string {
    return date('o-W', $ts);
}

/** Saturday-to-Friday window for a given ISO year-week (Sun-of-week-1 anchor). */
function wr_dates_for_year_week(string $yearWeek): array {
    [$year, $week] = array_map('intval', explode('-', $yearWeek));
    $weekStr = str_pad((string)$week, 2, '0', STR_PAD_LEFT);
    // ISO week starts Monday; we want Saturday as first to match an
    // Arab newsroom week. Compute Monday, then back up two days.
    $monday = new DateTime();
    $monday->setISODate($year, (int)$weekStr);
    $start = clone $monday;
    $start->modify('-2 days');                  // Saturday
    $end   = clone $start;
    $end->modify('+6 days');                     // Friday
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function wr_save(string $yearWeek, array $payload): int {
    wr_ensure_table();
    $db = getDB();
    [$start, $end] = wr_dates_for_year_week($yearWeek);
    $existing = wr_get_by_week($yearWeek);

    $params = [
        ':yw'        => $yearWeek,
        ':start'     => $start,
        ':end'       => $end,
        ':title'     => (string)($payload['cover_title']    ?? ''),
        ':subtitle'  => (string)($payload['cover_subtitle'] ?? ''),
        ':cover'     => (string)($payload['cover_image_url']?? ''),
        ':intro'     => (string)($payload['intro_text']     ?? ''),
        ':content'   => json_encode($payload['content']     ?? [], JSON_UNESCAPED_UNICODE),
        ':stats'     => json_encode($payload['stats']       ?? [], JSON_UNESCAPED_UNICODE),
        ':ids'       => implode(',', array_map('intval', (array)($payload['article_ids'] ?? []))),
    ];

    if ($existing) {
        $params[':id'] = (int)$existing['id'];
        $sql = "UPDATE weekly_rewinds SET
                  cover_title=:title, cover_subtitle=:subtitle, cover_image_url=:cover,
                  intro_text=:intro, content_json=:content, stats_json=:stats,
                  article_ids=:ids, regenerated_at=NOW()
                WHERE id=:id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$existing['id'];
    }

    $sql = "INSERT INTO weekly_rewinds
              (year_week, start_date, end_date, cover_title, cover_subtitle,
               cover_image_url, intro_text, content_json, stats_json, article_ids)
            VALUES
              (:yw, :start, :end, :title, :subtitle, :cover, :intro, :content, :stats, :ids)";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$db->lastInsertId();
}

function wr_hydrate(array $row): array {
    $content = json_decode((string)($row['content_json'] ?? '[]'), true);
    $stats   = json_decode((string)($row['stats_json']   ?? '{}'), true);
    $ids     = array_filter(array_map('intval', explode(',', (string)($row['article_ids'] ?? ''))));
    return [
        'id'              => (int)$row['id'],
        'year_week'       => (string)$row['year_week'],
        'start_date'      => (string)$row['start_date'],
        'end_date'        => (string)$row['end_date'],
        'cover_title'     => (string)$row['cover_title'],
        'cover_subtitle'  => (string)$row['cover_subtitle'],
        'cover_image_url' => (string)$row['cover_image_url'],
        'intro_text'      => (string)$row['intro_text'],
        'content'         => is_array($content) ? $content : [],
        'stats'           => is_array($stats)   ? $stats   : [],
        'article_ids'     => array_values($ids),
        'view_count'      => (int)$row['view_count'],
        'share_count'     => (int)$row['share_count'],
        'published_at'    => (string)$row['published_at'],
        'emailed_at'      => (string)($row['emailed_at'] ?? ''),
    ];
}

function wr_get_by_week(string $yearWeek): ?array {
    wr_ensure_table();
    $stmt = getDB()->prepare("SELECT * FROM weekly_rewinds WHERE year_week = ?");
    $stmt->execute([$yearWeek]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? wr_hydrate($row) : null;
}

function wr_get_latest(): ?array {
    wr_ensure_table();
    $row = getDB()->query("SELECT * FROM weekly_rewinds ORDER BY published_at DESC LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC);
    return $row ? wr_hydrate($row) : null;
}

function wr_list(int $limit = 20): array {
    wr_ensure_table();
    $limit = max(1, min(100, $limit));
    $rows = getDB()->query("SELECT * FROM weekly_rewinds
                            ORDER BY published_at DESC LIMIT {$limit}")
                   ->fetchAll(PDO::FETCH_ASSOC);
    return array_map('wr_hydrate', $rows ?: []);
}

function wr_bump_views(int $id): void {
    try {
        $stmt = getDB()->prepare("UPDATE weekly_rewinds SET view_count = view_count + 1 WHERE id = ?");
        $stmt->execute([$id]);
    } catch (Throwable $e) {}
}

function wr_mark_emailed(int $id): void {
    $stmt = getDB()->prepare("UPDATE weekly_rewinds SET emailed_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
}

} // function_exists guard

/* ==================================================================
 * Weekly digest generator.
 *
 * Produces the week's editorial content in two stages:
 *
 *   1. wr_collect_candidates()  — aggregates the last 7 days of
 *      articles, dedupes by cluster_key, keeps the strongest
 *      representative per cluster, ranks the list by a composite
 *      score (cluster size × freshness × views) and trims to ~50.
 *
 *   2. wr_generate_with_ai() — sends those 50 candidates to the
 *      model via Anthropic/Gemini tool-use. The tool schema forces
 *      a clean JSON structure (cover_title, intro_text, stories[],
 *      watching_next[], numbers[]) so there's no fragile prose
 *      parsing. The AI picks the top 7–10 stories, rewrites titles
 *      in an editorial voice, and identifies trends to watch next.
 *
 * The caller (cron_weekly_rewind.php) combines both with
 * wr_save() to persist the finished digest.
 * ================================================================ */

if (!function_exists('wr_collect_candidates')) {

function wr_collect_candidates(string $startDate, string $endDate, int $maxPerCluster = 1, int $limit = 60): array {
    $db = getDB();
    // Pull the week's articles ordered by a light-weight score so the
    // "first representative we see per cluster" is a strong one.
    $stmt = $db->prepare("
        SELECT a.id, a.title, a.slug, a.excerpt, a.ai_summary, a.ai_keywords,
               a.image_url, a.cluster_key, a.view_count, a.is_breaking,
               a.published_at,
               c.name AS cat_name, c.slug AS cat_slug,
               s.name AS source_name
          FROM articles a
     LEFT JOIN categories c ON a.category_id = c.id
     LEFT JOIN sources    s ON a.source_id   = s.id
         WHERE a.status = 'published'
           AND a.published_at >= ?
           AND a.published_at <  DATE_ADD(?, INTERVAL 1 DAY)
      ORDER BY (a.view_count + CASE WHEN a.is_breaking=1 THEN 500 ELSE 0 END
                             + CASE WHEN a.is_hero=1     THEN 300 ELSE 0 END) DESC,
               a.published_at DESC
         LIMIT 600");
    $stmt->execute([$startDate, $endDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    // Cluster sizes across the week — feeds the composite score.
    $clusterCount = [];
    foreach ($rows as $r) {
        $ck = (string)($r['cluster_key'] ?? '');
        if ($ck === '' || $ck === '-') continue;
        $clusterCount[$ck] = ($clusterCount[$ck] ?? 0) + 1;
    }

    // Keep the top `maxPerCluster` representatives per cluster. Since
    // the SQL is already view-sorted, the first-seen is the strongest.
    $perCluster = [];
    $candidates = [];
    foreach ($rows as $r) {
        $ck = (string)($r['cluster_key'] ?? '');
        $key = ($ck !== '' && $ck !== '-') ? $ck : 'solo-' . $r['id'];
        $perCluster[$key] = ($perCluster[$key] ?? 0) + 1;
        if ($perCluster[$key] > $maxPerCluster) continue;
        $r['cluster_size'] = $clusterCount[$ck] ?? 1;
        $candidates[] = $r;
    }

    // Composite score: cluster coverage + view popularity + breaking
    // boost. Freshness is secondary since the whole pool is one week.
    foreach ($candidates as &$c) {
        $c['_score'] =
              ($c['cluster_size'] * 60)
            + min(5000, (int)$c['view_count'])
            + ((int)$c['is_breaking'] * 400);
    }
    unset($c);
    usort($candidates, fn($a, $b) => $b['_score'] <=> $a['_score']);

    return array_slice($candidates, 0, max(1, $limit));
}

/**
 * Call the AI to compose the week's editorial digest from the candidates.
 * Returns a normalized array matching the shape expected by wr_save().
 */
function wr_generate_with_ai(array $candidates, string $startDate, string $endDate): array {
    if (!$candidates) {
        return ['ok' => false, 'error' => 'no candidates'];
    }
    require_once __DIR__ . '/ai_provider.php';

    // Build the compact article menu for the prompt. Each line is
    // `#id | category | source | title — excerpt`. Keep the blob
    // within roughly 35K chars so there's headroom for the response.
    $lines = [];
    $used  = 0;
    $budget = 35000;
    foreach ($candidates as $c) {
        $title   = trim(preg_replace('/\s+/u', ' ', (string)$c['title']));
        $excerpt = trim(preg_replace('/\s+/u', ' ', (string)($c['ai_summary'] ?? $c['excerpt'] ?? '')));
        if (mb_strlen($excerpt) > 280) $excerpt = mb_substr($excerpt, 0, 280) . '…';
        $cat = trim((string)($c['cat_name'] ?? ''));
        $src = trim((string)($c['source_name'] ?? ''));
        $line = "#{$c['id']} | {$cat} | {$src} | {$title}" . ($excerpt ? " — {$excerpt}" : '');
        $len = mb_strlen($line);
        if ($used + $len > $budget) break;
        $lines[] = $line;
        $used += $len + 1;
    }
    if (!$lines) {
        return ['ok' => false, 'error' => 'candidates too large'];
    }
    $menu = implode("\n", $lines);
    $count = count($lines);

    $prompt = "أنت رئيس تحرير عربي مخضرم. بين يديك {$count} خبر مرشح من أسبوع "
            . "({$startDate} إلى {$endDate}). مهمتك: صياغة \"مراجعة الأسبوع\" بأسلوب مجلة "
            . "أسبوعية رصينة، يقرأها القارئ العربي صباح الأحد ويخرج بصورة شاملة عن أهم ما جرى.\n\n"
            . "اختر بين 7 و 10 قصص فقط. يجب أن تكون:\n"
            . "- متنوّعة (لا تكرّر محاور متشابهة)\n"
            . "- إقليمياً أولاً ثم عالمياً\n"
            . "- ذات أثر مستدام (ليست مجرد خبر عابر)\n\n"
            . "لكل قصة، أعد صياغة العنوان بنبرة افتتاحية هادئة (ليست عاجل/كارثي)، "
            . "واكتب فقرة من 4–6 جمل تشرح: ماذا حصل، لماذا مهم، كيف يتصل بما قبله.\n\n"
            . "أضف:\n"
            . "- عنوان رئيسي وغلاف يلخّص \"ثيمة الأسبوع\"\n"
            . "- فقرة افتتاحية (intro) من 3–4 جمل\n"
            . "- 3–5 عناصر \"ما ننتظره الأسبوع القادم\" بتوقّعات معقولة\n"
            . "- 4–6 أرقام/إحصاءات بارزة من الأسبوع (إذا ظهرت في القصص)\n\n"
            . "استخدم الأداة submit_weekly_rewind لإرسال المخرجات.\n\n"
            . "قائمة المقالات:\n" . $menu;

    $tool = [
        'name'        => 'submit_weekly_rewind',
        'description' => 'Submit a curated Arabic weekly news digest.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'cover_title' => [
                    'type' => 'string',
                    'description' => 'عنوان رئيسي للأسبوع (≤ 80 حرف)، لا يكون خبراً بل ثيمة.',
                ],
                'cover_subtitle' => [
                    'type' => 'string',
                    'description' => 'جملة توضيحية واحدة (≤ 140 حرف) تحدّد زاوية المراجعة.',
                ],
                'intro_text' => [
                    'type' => 'string',
                    'description' => 'فقرة افتتاحية من 3–4 جمل، تمهيد للقارئ بأسلوب محرر.',
                ],
                'stories' => [
                    'type' => 'array',
                    'description' => 'القصص المختارة، من 7 إلى 10 قصص.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'headline' => [
                                'type' => 'string',
                                'description' => 'عنوان افتتاحي (≤ 100 حرف) — ليس نسخة حرفية من عنوان المصدر.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'فقرة من 4–6 جمل تشرح الحدث وسياقه.',
                            ],
                            'why_it_matters' => [
                                'type' => 'string',
                                'description' => 'سطر واحد: لماذا هذا الخبر مهم للقارئ العربي.',
                            ],
                            'category' => [
                                'type' => 'string',
                                'description' => 'تصنيف قصير: سياسة / اقتصاد / رياضة / تكنولوجيا / ثقافة / ...',
                            ],
                            'icon' => [
                                'type' => 'string',
                                'description' => 'رمز emoji واحد يعبّر عن موضوع القصة.',
                            ],
                            'article_ids' => [
                                'type' => 'array',
                                'description' => 'أرقام المقالات من القائمة المرفقة التي تغطّي هذه القصة (1–5 أرقام).',
                                'items' => ['type' => 'integer'],
                            ],
                        ],
                        'required' => ['headline', 'summary', 'article_ids'],
                    ],
                ],
                'watching_next' => [
                    'type' => 'array',
                    'description' => '3–5 أحداث أو ملفات ينتظرها الأسبوع القادم.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'note'  => ['type' => 'string', 'description' => 'سطر قصير يشرح السياق.'],
                            'icon'  => ['type' => 'string', 'description' => 'emoji واحد.'],
                        ],
                        'required' => ['title'],
                    ],
                ],
                'numbers' => [
                    'type' => 'array',
                    'description' => '4–6 أرقام بارزة من الأسبوع.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => ['type' => 'string', 'description' => 'الرقم أو النسبة.'],
                            'label' => ['type' => 'string', 'description' => 'شرح مختصر.'],
                        ],
                        'required' => ['value', 'label'],
                    ],
                ],
            ],
            'required' => ['cover_title', 'intro_text', 'stories'],
        ],
    ];

    $call = ai_provider_tool_call($prompt, $tool, 6000);
    if (empty($call['ok'])) {
        return ['ok' => false, 'error' => (string)($call['error'] ?? 'AI call failed')];
    }
    $out = $call['input'];
    if (!is_array($out) || empty($out['stories'])) {
        return ['ok' => false, 'error' => 'Invalid AI response shape'];
    }

    // Normalize stories + collect union of article ids actually cited.
    $stories = [];
    $articleIdSet = [];
    $validIds = array_flip(array_map('intval', array_column($candidates, 'id')));
    foreach ((array)$out['stories'] as $s) {
        if (!is_array($s)) continue;
        $ids = array_values(array_filter(
            array_map('intval', (array)($s['article_ids'] ?? [])),
            fn($i) => isset($validIds[$i])
        ));
        if (!$ids) continue;
        // Attach full article records for the rendering layer.
        $fullArticles = [];
        foreach ($ids as $aid) {
            foreach ($candidates as $c) {
                if ((int)$c['id'] === $aid) {
                    $fullArticles[] = [
                        'id'          => (int)$c['id'],
                        'title'       => (string)$c['title'],
                        'slug'        => (string)$c['slug'],
                        'image_url'   => (string)($c['image_url'] ?? ''),
                        'source_name' => (string)($c['source_name'] ?? ''),
                        'published_at'=> (string)($c['published_at'] ?? ''),
                    ];
                    break;
                }
            }
        }
        $stories[] = [
            'headline'       => trim((string)($s['headline'] ?? '')),
            'summary'        => trim((string)($s['summary']  ?? '')),
            'why_it_matters' => trim((string)($s['why_it_matters'] ?? '')),
            'category'       => trim((string)($s['category'] ?? '')),
            'icon'           => trim((string)($s['icon']     ?? '📰')),
            'article_ids'    => $ids,
            'articles'       => $fullArticles,
        ];
        foreach ($ids as $aid) $articleIdSet[$aid] = true;
    }

    // Cover image: first story's first article with a usable image.
    $coverImage = '';
    foreach ($stories as $s) {
        foreach ($s['articles'] as $a) {
            if (!empty($a['image_url']) && preg_match('#^https?://#', $a['image_url'])) {
                $coverImage = $a['image_url'];
                break 2;
            }
        }
    }

    return [
        'ok' => true,
        'payload' => [
            'cover_title'     => (string)($out['cover_title'] ?? ''),
            'cover_subtitle'  => (string)($out['cover_subtitle'] ?? ''),
            'cover_image_url' => $coverImage,
            'intro_text'      => (string)($out['intro_text'] ?? ''),
            'content'         => [
                'stories'       => $stories,
                'watching_next' => array_values(array_filter(
                    array_map(function($n) {
                        if (!is_array($n)) return null;
                        $title = trim((string)($n['title'] ?? ''));
                        if ($title === '') return null;
                        return [
                            'title' => $title,
                            'note'  => trim((string)($n['note'] ?? '')),
                            'icon'  => trim((string)($n['icon'] ?? '📅')),
                        ];
                    }, (array)($out['watching_next'] ?? []))
                )),
                'numbers' => array_values(array_filter(
                    array_map(function($n) {
                        if (!is_array($n)) return null;
                        $value = trim((string)($n['value'] ?? ''));
                        $label = trim((string)($n['label'] ?? ''));
                        if ($value === '' || $label === '') return null;
                        return ['value' => $value, 'label' => $label];
                    }, (array)($out['numbers'] ?? []))
                )),
            ],
            'stats' => [
                'candidates_reviewed' => count($candidates),
                'stories_picked'      => count($stories),
                'articles_cited'      => count($articleIdSet),
            ],
            'article_ids' => array_keys($articleIdSet),
        ],
    ];
}

} // function_exists guard (generator)

/* ==================================================================
 * Email rendering.
 *
 * Produces the HTML body for the Sunday-morning digest email. Uses
 * inline styles + table-ish div structure (no Flexbox / no Grid) so
 * it renders sanely across Gmail, Apple Mail, Outlook.com, and the
 * legacy Outlook desktop client. The layout intentionally mirrors
 * the /weekly/<year-week> web page so a forward-to-browser feels
 * continuous, but without the site chrome.
 *
 * The caller passes the unsubscribe URL so it's unique per recipient.
 * ================================================================ */

if (!function_exists('wr_email_html')) {

function wr_email_html(array $rewind, string $unsubscribeUrl, string $webUrl = ''): string {
    $brand     = '#1a5c5c';
    $accent    = '#0d9488';
    $gold      = '#f59e0b';
    $siteName  = getSetting('site_name', SITE_NAME);
    $title     = e((string)($rewind['cover_title'] ?? 'مراجعة الأسبوع'));
    $subtitle  = e((string)($rewind['cover_subtitle'] ?? ''));
    $dateRange = e(wr_format_date_range_internal($rewind['start_date'], $rewind['end_date']));
    $cover     = trim((string)($rewind['cover_image_url'] ?? ''));
    $intro     = e((string)($rewind['intro_text'] ?? ''));
    if ($webUrl === '') $webUrl = SITE_URL . '/weekly/' . ($rewind['year_week'] ?? '');

    // ----- Hero --------------------------------------------------
    $coverImg = '';
    if ($cover !== '' && preg_match('#^https?://#', $cover)) {
        $coverImg = '<img src="' . e($cover) . '" alt="" style="display:block;width:100%;max-height:280px;object-fit:cover;border-radius:0;">';
    }
    $hero  = '<div style="background:linear-gradient(135deg,#0f172a 0%,' . $brand . ' 100%);color:#fff;padding:0;border-radius:16px 16px 0 0;overflow:hidden;">'
           . $coverImg
           . '<div style="padding:28px 32px 32px;">'
           . '<div style="display:inline-block;background:' . $gold . ';color:#1a1a2e;padding:5px 14px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.5px;margin-bottom:14px;">📅 مراجعة الأسبوع</div>'
           . '<div style="font-size:12px;color:rgba(255,255,255,.75);margin-bottom:14px;font-weight:600;">' . $dateRange . '</div>'
           . '<h1 style="font-size:30px;line-height:1.3;margin:0 0 12px;font-weight:900;color:#fff;">' . $title . '</h1>'
           . ($subtitle !== '' ? '<p style="font-size:15px;line-height:1.7;margin:0;color:rgba(255,255,255,.9);">' . $subtitle . '</p>' : '')
           . '</div></div>';

    // ----- Intro -------------------------------------------------
    $introBlock = '';
    if ($intro !== '') {
        $introBlock = '<div style="padding:28px 32px 8px;">'
                    . '<div style="font-size:40px;color:' . $accent . ';font-weight:900;line-height:1;margin-bottom:8px;">—</div>'
                    . '<p style="font-size:16px;line-height:1.85;color:#374151;margin:0;font-weight:500;">' . nl2br($intro) . '</p>'
                    . '</div>';
    }

    // ----- Numbers strip -----------------------------------------
    $numbersHtml = '';
    $nums = (array)($rewind['content']['numbers'] ?? []);
    if ($nums) {
        $cells = '';
        foreach ($nums as $n) {
            $val = e((string)($n['value'] ?? ''));
            $lbl = e((string)($n['label'] ?? ''));
            if ($val === '' || $lbl === '') continue;
            $cells .= '<td style="width:50%;padding:8px;">'
                    . '<div style="background:#fff;border:1px solid #e3e6ec;border-radius:12px;padding:18px 14px;text-align:center;">'
                    . '<div style="font-size:26px;font-weight:900;color:' . $accent . ';line-height:1.1;margin-bottom:6px;">' . $val . '</div>'
                    . '<div style="font-size:12px;color:#6b7280;line-height:1.5;">' . $lbl . '</div>'
                    . '</div></td>';
        }
        // Wrap two per row via simple table rows.
        $numbersHtml = '<div style="padding:32px 24px 8px;">'
                     . wr_email_section_heading('أرقام هذا الأسبوع', $accent)
                     . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $cells . '</tr></table>'
                     . '</div>';
    }

    // ----- Stories ----------------------------------------------
    $storiesHtml = '';
    foreach (($rewind['content']['stories'] ?? []) as $i => $story) {
        $idx  = str_pad((string)($i+1), 2, '0', STR_PAD_LEFT);
        $ico  = e((string)($story['icon'] ?? '📰'));
        $cat  = e((string)($story['category'] ?? ''));
        $hl   = e((string)$story['headline']);
        $sm   = nl2br(e((string)$story['summary']));
        $why  = e((string)($story['why_it_matters'] ?? ''));

        $srcHtml = '';
        foreach (($story['articles'] ?? []) as $a) {
            $url = SITE_URL . '/' . articleUrl(['id' => $a['id'], 'slug' => $a['slug']]);
            $srcHtml .= '<a href="' . e($url) . '" style="display:block;padding:6px 10px;margin:2px 0;text-decoration:none;color:#374151;font-size:13px;line-height:1.55;border-radius:6px;">'
                     . '<span style="display:inline-block;background:rgba(26,115,232,.1);color:#1a73e8;padding:1px 7px;border-radius:4px;font-size:10.5px;font-weight:700;margin-left:6px;">' . e((string)($a['source_name'] ?? '—')) . '</span>'
                     . e((string)$a['title'])
                     . '</a>';
        }
        $sourcesBlock = $srcHtml !== ''
            ? '<div style="margin-top:14px;padding-top:12px;border-top:1px dashed #e3e6ec;">'
              . '<div style="font-size:11px;font-weight:700;color:#6b7280;margin-bottom:6px;">المصادر:</div>'
              . $srcHtml . '</div>'
            : '';

        $whyBlock = $why !== ''
            ? '<div style="background:rgba(245,158,11,.08);border-right:3px solid ' . $gold . ';padding:12px 16px;border-radius:0 10px 10px 0;font-size:14px;line-height:1.75;color:#374151;margin-top:14px;">'
              . '<div style="font-size:10.5px;font-weight:800;color:#b45309;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;">لماذا يهم؟</div>'
              . $why . '</div>'
            : '';

        $catLine = $cat !== ''
            ? '<div style="font-size:10.5px;font-weight:800;color:' . $accent . ';text-transform:uppercase;letter-spacing:1.5px;margin-bottom:6px;">' . $cat . '</div>'
            : '';

        $storiesHtml .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;border-bottom:1px solid #e3e6ec;padding-bottom:24px;">'
                      . '<tr>'
                      . '<td valign="top" width="64" style="padding:4px 0 0 14px;text-align:center;">'
                      . '<div style="width:48px;height:48px;background:linear-gradient(135deg,rgba(13,148,136,.12),rgba(26,92,92,.08));border-radius:12px;font-size:26px;line-height:48px;text-align:center;margin:0 auto 6px;">' . $ico . '</div>'
                      . '<div style="font-size:13px;font-weight:900;color:#9ca3af;font-family:Menlo,Consolas,monospace;">' . $idx . '</div>'
                      . '</td>'
                      . '<td valign="top" style="padding:4px 0 0;">'
                      . $catLine
                      . '<h3 style="font-size:21px;font-weight:900;line-height:1.35;margin:0 0 10px;color:#1a1a2e;">' . $hl . '</h3>'
                      . '<p style="font-size:15px;line-height:1.9;color:#374151;margin:0;">' . $sm . '</p>'
                      . $whyBlock
                      . $sourcesBlock
                      . '</td></tr></table>';
    }
    $storiesBlock = $storiesHtml !== ''
        ? '<div style="padding:8px 24px;">' . wr_email_section_heading('القصص المختارة', $accent) . $storiesHtml . '</div>'
        : '';

    // ----- Watching next ----------------------------------------
    $watchingHtml = '';
    foreach (($rewind['content']['watching_next'] ?? []) as $w) {
        $ico   = e((string)($w['icon'] ?? '📅'));
        $wt    = e((string)($w['title'] ?? ''));
        $wnote = e((string)($w['note']  ?? ''));
        if ($wt === '') continue;
        $watchingHtml .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 10px;">'
                       . '<tr><td>'
                       . '<div style="background:#fff;border:1px solid #e3e6ec;border-right:4px solid ' . $accent . ';border-radius:12px;padding:14px 16px;">'
                       . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>'
                       . '<td width="48" valign="top">'
                       . '<div style="width:40px;height:40px;background:rgba(13,148,136,.12);border-radius:10px;font-size:22px;line-height:40px;text-align:center;">' . $ico . '</div>'
                       . '</td>'
                       . '<td valign="top" style="padding-right:12px;">'
                       . '<strong style="display:block;font-size:15px;font-weight:800;color:#1a1a2e;margin-bottom:3px;">' . $wt . '</strong>'
                       . ($wnote !== '' ? '<span style="font-size:13px;color:#6b7280;line-height:1.65;">' . $wnote . '</span>' : '')
                       . '</td></tr></table></div></td></tr></table>';
    }
    $watchingBlock = $watchingHtml !== ''
        ? '<div style="padding:20px 24px 8px;">' . wr_email_section_heading('ما ننتظره الأسبوع القادم', $accent) . $watchingHtml . '</div>'
        : '';

    // ----- CTA + footer -----------------------------------------
    $ctaBlock = '<div style="text-align:center;padding:28px 24px 8px;">'
              . '<a href="' . e($webUrl) . '" style="display:inline-block;background:' . $accent . ';color:#fff;padding:14px 32px;border-radius:10px;text-decoration:none;font-weight:800;font-size:15px;">افتح المراجعة على الموقع ←</a>'
              . '</div>';

    $footer = '<div style="padding:24px 24px 28px;text-align:center;border-top:1px solid #e3e6ec;margin-top:24px;">'
            . '<div style="font-size:12px;color:#6b7280;line-height:1.8;">'
            . 'تصلك هذه المراجعة الأسبوعية من <strong style="color:#1a1a2e;">' . e($siteName) . '</strong>.<br>'
            . 'لا تريد استلامها بعد الآن؟ '
            . '<a href="' . e($unsubscribeUrl) . '" style="color:' . $accent . ';text-decoration:underline;">إلغاء الاشتراك</a>'
            . '</div></div>';

    // Outer shell + white card.
    $body = '<div style="background:#f5f5f1;padding:24px 12px;">'
          . '<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.06);">'
          . $hero
          . $introBlock
          . $numbersHtml
          . $storiesBlock
          . $watchingBlock
          . $ctaBlock
          . $footer
          . '</div></div>';

    // Wrap in the shared newsletter shell (RTL html+body, head, fonts).
    if (function_exists('newsletter_email_html')) {
        return newsletter_email_html((string)$rewind['cover_title'], $body, $unsubscribeUrl);
    }
    // Fallback minimal shell if mailer hasn't been loaded yet.
    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"></head>'
         . '<body style="margin:0;padding:0;font-family:Tajawal,Segoe UI,Tahoma,sans-serif;">' . $body . '</body></html>';
}

function wr_email_section_heading(string $text, string $color): string {
    return '<div style="font-size:12px;text-transform:uppercase;letter-spacing:2px;font-weight:800;color:' . $color . ';margin:0 0 18px;padding:0 4px;">'
         . e($text) . '</div>';
}

function wr_format_date_range_internal(string $start, string $end): string {
    if (function_exists('wr_format_date_range')) return wr_format_date_range($start, $end);
    return $start . ' – ' . $end;
}

} // function_exists guard (email)
