<?php
/**
 * Content type classifier — labels every article as one of:
 *   - news     (خبر):    short factual report of an event (< ~2500 chars)
 *   - report   (تقرير):  in-depth analysis, investigation, feature
 *   - article  (مقالة):  opinion, column, personal viewpoint
 *
 * This is orthogonal to the existing `category_id` (politics/sports/health
 * etc.) — a sports column is content_type=article + category=sports.
 *
 * Two-stage hybrid pipeline:
 *   1) classify_by_pattern() — URL/title/length/opinion-marker heuristics.
 *      No AI call. Returns confidence 0.0-1.0. ≥ 0.70 wins outright.
 *   2) classify_by_ai_batch() — Gemini call for cases the patterns
 *      couldn't decide. Batched 15 at a time to keep API cost down.
 *
 * Persisted columns (lazy-added via classify_ensure_columns):
 *   content_type             ENUM('news','report','article')
 *   content_type_method      ENUM('pattern','ai','manual')
 *   content_type_confidence  DECIMAL(3,2)
 *   content_type_at          TIMESTAMP
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai_helper.php';

/**
 * One-time ALTER. Idempotent — repeated calls in the same request short-
 * circuit on a static flag, and the catch swallows the duplicate-column
 * error on subsequent process invocations.
 */
function classify_ensure_columns(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $db = getDB();
    try {
        $exists = $db->query("SHOW COLUMNS FROM articles LIKE 'content_type'")->fetch();
        if ($exists) return;
        $db->exec("ALTER TABLE articles
            ADD COLUMN content_type ENUM('news','report','article') DEFAULT 'news',
            ADD COLUMN content_type_method ENUM('pattern','ai','manual') DEFAULT NULL,
            ADD COLUMN content_type_confidence DECIMAL(3,2) DEFAULT NULL,
            ADD COLUMN content_type_at TIMESTAMP NULL,
            ADD INDEX idx_content_type (content_type, published_at)");
        error_log('content_classifier: added content_type columns to articles');
    } catch (Throwable $e) {
        // Column probably already exists from a previous run on another worker.
    }
}

/**
 * URL path patterns. First match wins. The same source can publish
 * news + opinion + reports under different paths, so URL is the single
 * strongest signal we have.
 */
const CONTENT_URL_PATTERNS = [
    ['type' => 'article', 'confidence' => 0.95, 'regex' => '#/(opinion|opinions|column|columns|op-ed|editorial|editorials|maqal|maqalat|rai|raie|views|viewpoint|blog|blogs|writers)/#i'],
    ['type' => 'report',  'confidence' => 0.92, 'regex' => '#/(report|reports|taqarir|tahqiq|investigation|investigations|in-depth|in_depth|feature|features|analysis|analyses)/#i'],
    ['type' => 'news',    'confidence' => 0.85, 'regex' => '#/(news|breaking|akhbar|ajel|3ajel|latest)/#i'],
];

/**
 * Title prefixes / patterns. Less reliable than URLs but a strong tie-
 * breaker when URLs are generic (/article/123 style).
 */
const CONTENT_TITLE_PATTERNS = [
    ['type' => 'article', 'confidence' => 0.88, 'regex' => '/^\s*(رأي|مقال|مقالة|وجهة\s*نظر|تعليق|عمود)[\s:]/u'],
    ['type' => 'article', 'confidence' => 0.85, 'regex' => '/(^|\s)(يكتب|بقلم|بقلم:)\s+\S/u'],
    ['type' => 'report',  'confidence' => 0.85, 'regex' => '/^\s*(تقرير|تحقيق|ملف|تغطية\s*موسعة|دراسة|بحث|قراءة|كرونولوجيا)[\s:]/u'],
    ['type' => 'report',  'confidence' => 0.75, 'regex' => '/^\s*تحليل[\s:]/u'],
];

/**
 * Opinion markers used as length-based tiebreakers for very long pieces.
 * Two+ of these in body content suggests an article over a report.
 */
const CONTENT_OPINION_MARKERS = [
    'أرى أن', 'في رأيي', 'برأيي', 'نعتقد', 'أعتقد', 'من وجهة نظري',
    'يجب علينا', 'يجب علينا أن', 'لا بد لنا', 'يتوجب', 'لا شك',
];

/**
 * Pure-pattern classification. Returns a result with a confidence the
 * caller can compare against a threshold. Never calls the AI.
 *
 * @return array|null ['type','confidence','reason'] or null when nothing
 *                    matched and we don't even have a length signal.
 */
function classify_by_pattern(array $article): ?array {
    $url   = (string)($article['source_url'] ?? '');
    $title = (string)($article['title'] ?? '');
    $text  = (string)($article['content'] ?? $article['excerpt'] ?? '');
    $len   = mb_strlen(strip_tags($text));

    // (1) URL path — strongest signal.
    foreach (CONTENT_URL_PATTERNS as $p) {
        if (preg_match($p['regex'], $url)) {
            return ['type' => $p['type'], 'confidence' => $p['confidence'], 'reason' => 'url'];
        }
    }

    // (2) Title prefix / byline.
    foreach (CONTENT_TITLE_PATTERNS as $p) {
        if (preg_match($p['regex'], $title)) {
            return ['type' => $p['type'], 'confidence' => $p['confidence'], 'reason' => 'title'];
        }
    }

    // (3) Length-based heuristic (medium confidence).
    if ($len === 0) return null;
    if ($len < 1500) {
        return ['type' => 'news', 'confidence' => 0.75, 'reason' => 'short_body'];
    }
    if ($len >= 4500) {
        $opinionHits = 0;
        foreach (CONTENT_OPINION_MARKERS as $m) {
            $opinionHits += substr_count($text, $m);
            if ($opinionHits >= 2) break;
        }
        if ($opinionHits >= 2) {
            return ['type' => 'article', 'confidence' => 0.65, 'reason' => 'long_opinion'];
        }
        return ['type' => 'report', 'confidence' => 0.70, 'reason' => 'long_body'];
    }

    return null; // 1500..4500 chars, no other signal — punt to AI.
}

/**
 * Classify one article. If `$allowAi` is on and patterns are weak, falls
 * back to an AI call for just this article (one-shot, not batched).
 * Prefer classify_backfill for bulk work — it batches 15 at a time.
 */
function classify_article(array $article, bool $allowAi = false): array {
    $p = classify_by_pattern($article);
    if ($p && $p['confidence'] >= 0.70) {
        return ['type' => $p['type'], 'method' => 'pattern', 'confidence' => $p['confidence'], 'reason' => $p['reason']];
    }

    if ($allowAi) {
        $batch = classify_by_ai_batch([$article]);
        if (!empty($batch[0])) return $batch[0];
    }

    // Fall back to the weak pattern result if we had one, otherwise news.
    if ($p) {
        return ['type' => $p['type'], 'method' => 'pattern', 'confidence' => $p['confidence'], 'reason' => $p['reason']];
    }
    return ['type' => 'news', 'method' => 'pattern', 'confidence' => 0.40, 'reason' => 'default'];
}

/**
 * Batched AI classification. Sends up to ~15 articles per call (one
 * prompt, multiple labels back) which keeps the per-article cost low.
 *
 * @return array same shape as classify_article, indexed by input order.
 */
function classify_by_ai_batch(array $articles): array {
    $n = count($articles);
    if ($n === 0) return [];

    $prompt = "أنت محرّر صحفي خبير. صنّف كل عنصر إلى نوع واحد من ثلاثة:\n"
            . "  - news    (خبر): تقرير قصير عن حدث/واقعة محددة، يجاوب على من/ماذا/متى/أين.\n"
            . "  - report  (تقرير): تغطية موسعة أو تحليل أو تحقيق، عادة >1000 كلمة، عدة مصادر، وأرقام.\n"
            . "  - article (مقالة): رأي شخصي أو عمود أو وجهة نظر، صوت مؤلف واضح.\n\n"
            . "أعد JSON فقط بهذا الشكل:\n"
            . '{"results":[{"id":1,"type":"news","confidence":0.85,"reason":"شرح قصير"}]}' . "\n\n"
            . "العناصر:\n";

    foreach ($articles as $i => $a) {
        $idx     = $i + 1;
        $title   = trim((string)($a['title'] ?? ''));
        $excerpt = trim(mb_substr(strip_tags((string)($a['excerpt'] ?? $a['content'] ?? '')), 0, 400));
        $url     = (string)($a['source_url'] ?? '');
        $prompt .= "\n[$idx] {$title}\n     URL: {$url}\n     مقتطف: {$excerpt}\n";
    }

    $call = ai_provider_text_call($prompt, 1500);
    if (empty($call['ok'])) {
        $err = (string)($call['error'] ?? 'unknown');
        error_log("classify_by_ai_batch: AI call failed: $err");
        return array_fill(0, $n, ['type' => 'news', 'method' => 'pattern', 'confidence' => 0.30, 'reason' => 'ai_failed']);
    }

    $text = (string)$call['text'];
    if (!preg_match('/\{[\s\S]*\}/', $text, $m)) {
        return array_fill(0, $n, ['type' => 'news', 'method' => 'pattern', 'confidence' => 0.30, 'reason' => 'ai_no_json']);
    }

    $parsed = json_decode($m[0], true);
    $results = $parsed['results'] ?? [];

    $out = [];
    $byId = [];
    foreach ($results as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) $byId[$id] = $r;
    }

    foreach ($articles as $i => $_) {
        $id = $i + 1;
        $r  = $byId[$id] ?? null;
        if ($r && in_array($r['type'] ?? '', ['news', 'report', 'article'], true)) {
            $out[$i] = [
                'type'       => $r['type'],
                'method'     => 'ai',
                'confidence' => max(0.5, min(1.0, (float)($r['confidence'] ?? 0.75))),
                'reason'     => 'ai: ' . mb_substr((string)($r['reason'] ?? ''), 0, 80),
            ];
        } else {
            $out[$i] = ['type' => 'news', 'method' => 'pattern', 'confidence' => 0.30, 'reason' => 'ai_missed'];
        }
    }
    return $out;
}

/**
 * Persist a classification result on a single article row.
 */
function classify_save(int $articleId, array $result): bool {
    classify_ensure_columns();
    $db = getDB();
    try {
        $stmt = $db->prepare("UPDATE articles SET
            content_type = ?,
            content_type_method = ?,
            content_type_confidence = ?,
            content_type_at = NOW()
            WHERE id = ?");
        return $stmt->execute([
            (string)$result['type'],
            (string)$result['method'],
            round((float)$result['confidence'], 2),
            $articleId,
        ]);
    } catch (Throwable $e) {
        error_log('classify_save: ' . $e->getMessage());
        return false;
    }
}

/**
 * Backfill recent articles that haven't been classified yet.
 *
 * Strategy:
 *   1) Pull every unclassified article within the lookback window.
 *   2) Run patterns first — strong matches (≥0.70) are saved immediately,
 *      no AI call needed. This handles ~70-80% for free.
 *   3) Remaining ambiguous articles get batched 15 at a time to Gemini.
 *
 * @return array ['total','pattern','ai','failed','elapsed_sec']
 */
function classify_backfill(int $days = 7, int $maxArticles = 10000, bool $useAi = true): array {
    classify_ensure_columns();
    $start = microtime(true);

    $db = getDB();
    $sql = "SELECT id, title, excerpt, content, source_url, category_id, published_at
              FROM articles
             WHERE published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND content_type_at IS NULL
             ORDER BY published_at DESC
             LIMIT ?";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(1, $days,         PDO::PARAM_INT);
    $stmt->bindValue(2, $maxArticles,  PDO::PARAM_INT);
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = ['total' => count($articles), 'pattern' => 0, 'ai' => 0, 'failed' => 0, 'elapsed_sec' => 0];
    if (empty($articles)) {
        $stats['elapsed_sec'] = round(microtime(true) - $start, 1);
        return $stats;
    }

    $ambiguous = [];
    foreach ($articles as $a) {
        $p = classify_by_pattern($a);
        if ($p && $p['confidence'] >= 0.70) {
            classify_save((int)$a['id'], [
                'type' => $p['type'], 'method' => 'pattern', 'confidence' => $p['confidence'],
            ]);
            $stats['pattern']++;
        } else {
            $ambiguous[] = $a;
        }
    }

    if (!empty($ambiguous)) {
        if ($useAi) {
            foreach (array_chunk($ambiguous, 15) as $batch) {
                $aiResults = classify_by_ai_batch($batch);
                foreach ($batch as $i => $a) {
                    $r = $aiResults[$i] ?? null;
                    if ($r) {
                        classify_save((int)$a['id'], $r);
                        if ($r['method'] === 'ai') $stats['ai']++;
                        else                       $stats['failed']++;
                    } else {
                        $stats['failed']++;
                    }
                }
                usleep(250000); // 250ms between batches — be nice to Gemini
            }
        } else {
            // No-AI mode: stamp ambiguous articles with their weak pattern
            // guess (or 'news' default), so they aren't reprocessed forever.
            foreach ($ambiguous as $a) {
                $p = classify_by_pattern($a);
                classify_save((int)$a['id'], $p
                    ? ['type' => $p['type'], 'method' => 'pattern', 'confidence' => $p['confidence']]
                    : ['type' => 'news',     'method' => 'pattern', 'confidence' => 0.40]);
                $stats['pattern']++;
            }
        }
    }

    $stats['elapsed_sec'] = round(microtime(true) - $start, 1);
    return $stats;
}

/**
 * Convenience wrapper for the cron — classify a freshly-inserted article
 * with patterns only (no AI). The nightly backfill will pick up anything
 * patterns couldn't decide and run AI on it in batches.
 */
function classify_new_article(int $articleId, array $articleRow): void {
    $result = classify_article($articleRow, false);
    classify_save($articleId, $result);
}
