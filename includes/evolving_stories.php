<?php
/**
 * نيوزفلو — القصص المتطوّرة المُعرَّفة من الإدارة (Evolving Stories)
 *
 * Unlike the automatic cluster-based "story timelines" (see
 * includes/story_timeline.php), this module powers *persistent*
 * editor-defined topics. The admin creates a story ("أخبار الأقصى"),
 * provides a list of Arabic keywords, and every new article that
 * matches enough of them is linked to that story automatically.
 *
 * Storage:
 *   - evolving_stories           — the topics themselves
 *   - evolving_story_articles    — junction: story ↔ article
 *
 * Matching is a pure keyword lookup against title + excerpt. No AI
 * calls, so it can run on every insert in cron_rss.php with no
 * meaningful overhead. An AI layer can be added later for edge cases
 * by calling story_timeline_generate() over the latest articles of a
 * story — that's what the single-story page (evolving-story.php)
 * already does to produce a narrative summary.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache.php';

/**
 * Create the two evolving-stories tables if they don't already exist.
 * Called lazily from every public function here so a fresh deploy
 * doesn't blow up if migrations/002 hasn't been applied yet.
 */
function evolving_stories_ensure_tables(): void {
    static $ensured = false;
    if ($ensured) return;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS evolving_stories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            slug VARCHAR(150) NOT NULL,
            description TEXT NULL,
            icon VARCHAR(20) NOT NULL DEFAULT '',
            cover_image VARCHAR(500) NULL,
            accent_color VARCHAR(20) NOT NULL DEFAULT '#0d9488',
            keywords TEXT NOT NULL,
            exclude_keywords TEXT NULL,
            min_match_score TINYINT UNSIGNED NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            article_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_matched_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_slug (slug),
            KEY idx_active_order (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS evolving_story_articles (
            story_id INT NOT NULL,
            article_id INT NOT NULL,
            match_score TINYINT UNSIGNED NOT NULL DEFAULT 1,
            matched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (story_id, article_id),
            KEY idx_article (article_id),
            KEY idx_story_matched (story_id, matched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $ensured = true;
    } catch (Throwable $e) {
        error_log('[evolving_stories] ensure_tables: ' . $e->getMessage());
    }
}

/**
 * Hydrate a raw DB row into the shape the UI layer expects. Decodes
 * the JSON keyword columns and normalizes nulls to sane defaults.
 */
function evolving_story_hydrate(array $row): array {
    $kw  = json_decode((string)($row['keywords'] ?? '[]'), true);
    $ex  = json_decode((string)($row['exclude_keywords'] ?? '[]'), true);
    return [
        'id'               => (int)$row['id'],
        'name'             => (string)$row['name'],
        'slug'             => (string)$row['slug'],
        'description'      => (string)($row['description'] ?? ''),
        'icon'             => (string)($row['icon'] ?? ''),
        'cover_image'      => (string)($row['cover_image'] ?? ''),
        'accent_color'     => (string)($row['accent_color'] ?? '#0d9488'),
        'keywords'         => is_array($kw) ? $kw : [],
        'exclude_keywords' => is_array($ex) ? $ex : [],
        'min_match_score'  => (int)($row['min_match_score'] ?? 1),
        'sort_order'       => (int)($row['sort_order'] ?? 0),
        'is_active'        => (int)($row['is_active'] ?? 0),
        'article_count'    => (int)($row['article_count'] ?? 0),
        'last_matched_at'  => (string)($row['last_matched_at'] ?? ''),
        'created_at'       => (string)($row['created_at'] ?? ''),
        'updated_at'       => (string)($row['updated_at'] ?? ''),
    ];
}

/**
 * Return all stories. By default only the active ones (which is
 * what the public pages and the auto-matcher want). Admin list
 * passes false.
 */
function evolving_stories_list(bool $activeOnly = true): array {
    evolving_stories_ensure_tables();
    try {
        $db  = getDB();
        $sql = "SELECT * FROM evolving_stories"
             . ($activeOnly ? " WHERE is_active = 1" : "")
             . " ORDER BY sort_order ASC, id ASC";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return array_map('evolving_story_hydrate', $rows);
    } catch (Throwable $e) {
        error_log('[evolving_stories] list: ' . $e->getMessage());
        return [];
    }
}

/**
 * Fetch one story by slug. Returns null if not found.
 */
function evolving_story_get_by_slug(string $slug): ?array {
    evolving_stories_ensure_tables();
    if ($slug === '') return null;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM evolving_stories WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? evolving_story_hydrate($row) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Fetch one story by id. Used by the admin edit page.
 */
function evolving_story_get_by_id(int $id): ?array {
    evolving_stories_ensure_tables();
    if ($id <= 0) return null;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM evolving_stories WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? evolving_story_hydrate($row) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Lowercase + normalize a haystack once so the per-keyword lookups
 * are fast and case-insensitive. Strip extra whitespace.
 */
function evolving_story_normalize_text(string $text): string {
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return mb_strtolower(trim($text));
}

/**
 * Core matcher. Given an article's id + title + excerpt, walk every
 * active story and insert a row into evolving_story_articles for each
 * match.
 *
 * Matching rule (intentionally simple, no AI):
 *   1. Lowercase title + excerpt into one haystack.
 *   2. For each story, if any exclude_keyword is present → skip it.
 *   3. Otherwise count how many keywords from the list appear.
 *   4. If score ≥ min_match_score → link the article to the story.
 *
 * Returns the number of stories matched. Safe to call many times for
 * the same article — the PRIMARY KEY makes INSERT IGNORE idempotent.
 */
function evolving_story_match_article(int $articleId, string $title, string $excerpt = ''): int {
    if ($articleId <= 0) return 0;
    $stories = evolving_stories_list(true);
    if (empty($stories)) return 0;

    $haystack = evolving_story_normalize_text($title . ' ' . $excerpt);
    if ($haystack === '') return 0;

    $matched = 0;
    try {
        $db   = getDB();
        $ins  = $db->prepare("INSERT IGNORE INTO evolving_story_articles
                                (story_id, article_id, match_score, matched_at)
                              VALUES (?, ?, ?, NOW())");
        $touched = [];
        foreach ($stories as $story) {
            // Negative filter: any exclude keyword present → skip.
            $skip = false;
            foreach ($story['exclude_keywords'] as $ex) {
                $ex = mb_strtolower(trim((string)$ex));
                if ($ex !== '' && mb_strpos($haystack, $ex) !== false) { $skip = true; break; }
            }
            if ($skip) continue;

            // Positive score: count distinct keyword hits.
            $score = 0;
            foreach ($story['keywords'] as $kw) {
                $kw = mb_strtolower(trim((string)$kw));
                if ($kw === '') continue;
                if (mb_strpos($haystack, $kw) !== false) $score++;
            }
            if ($score < max(1, $story['min_match_score'])) continue;

            $ins->execute([$story['id'], $articleId, min(255, $score)]);
            if ($ins->rowCount() > 0) {
                $matched++;
                $touched[] = (int)$story['id'];
            }
        }

        // Bump counters + last_matched_at for every story that gained
        // a new article. Keeps the homepage sort by freshness honest.
        if (!empty($touched)) {
            $placeholders = implode(',', array_fill(0, count($touched), '?'));
            $db->prepare("UPDATE evolving_stories
                             SET article_count = article_count + 1,
                                 last_matched_at = NOW()
                           WHERE id IN ($placeholders)")
               ->execute($touched);
        }
    } catch (Throwable $e) {
        error_log('[evolving_stories] match_article: ' . $e->getMessage());
    }
    return $matched;
}

/**
 * Latest articles for a story, joined with source + category metadata
 * so the templates can render cards without extra queries.
 */
function evolving_story_articles(int $storyId, int $limit = 30, int $offset = 0): array {
    evolving_stories_ensure_tables();
    if ($storyId <= 0) return [];
    $limit  = max(1, min(200, $limit));
    $offset = max(0, $offset);
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT a.id, a.title, a.slug, a.excerpt, a.image_url,
                                     a.source_url, a.ai_summary, a.ai_keywords,
                                     a.view_count, a.published_at, a.cluster_key,
                                     c.name AS cat_name, c.slug AS cat_slug, c.css_class,
                                     s.name AS source_name
                                FROM evolving_story_articles esa
                                JOIN articles a ON a.id = esa.article_id
                           LEFT JOIN categories c ON a.category_id = c.id
                           LEFT JOIN sources    s ON a.source_id   = s.id
                               WHERE esa.story_id = ?
                                 AND a.status = 'published'
                            ORDER BY a.published_at DESC
                               LIMIT $limit OFFSET $offset");
        $stmt->execute([$storyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[evolving_stories] articles: ' . $e->getMessage());
        return [];
    }
}

/**
 * Count articles linked to a story. Used when the cached counter is
 * suspected to be drifted (e.g. after a manual unlink) or when a
 * recount is explicitly requested from the admin UI.
 */
function evolving_story_count_articles(int $storyId): int {
    evolving_stories_ensure_tables();
    if ($storyId <= 0) return 0;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*)
                                FROM evolving_story_articles esa
                                JOIN articles a ON a.id = esa.article_id
                               WHERE esa.story_id = ?
                                 AND a.status = 'published'");
        $stmt->execute([$storyId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Refresh the cached article_count + last_matched_at for a story.
 * Called after backfill or manual edits.
 */
function evolving_story_recount(int $storyId): int {
    evolving_stories_ensure_tables();
    $count = evolving_story_count_articles($storyId);
    try {
        $db = getDB();
        $db->prepare("UPDATE evolving_stories SET article_count = ? WHERE id = ?")
           ->execute([$count, $storyId]);
    } catch (Throwable $e) {}
    return $count;
}

/**
 * Scan recent articles (default last 30 days) and match them against
 * a single story. Used right after a story is created or its keyword
 * list is edited — it gives admin instant feedback that the topic is
 * already populated before the next cron run.
 *
 * Returns the number of new links created.
 */
function evolving_story_backfill(int $storyId, int $days = 30, int $maxArticles = 5000): int {
    evolving_stories_ensure_tables();
    $story = evolving_story_get_by_id($storyId);
    if (!$story || !$story['is_active']) return 0;
    if (empty($story['keywords'])) return 0;

    try {
        $db = getDB();
        $since = date('Y-m-d H:i:s', time() - ($days * 86400));
        $stmt  = $db->prepare("SELECT id, title, excerpt, published_at
                                 FROM articles
                                WHERE status = 'published'
                                  AND published_at >= ?
                             ORDER BY published_at DESC
                                LIMIT " . (int)$maxArticles);
        $stmt->execute([$since]);

        // Use the article's own published_at as the matched_at so the
        // backfill respects historical ordering — otherwise all old
        // articles would share the same "just now" timestamp and the
        // story page would mis-sort.
        $ins = $db->prepare("INSERT IGNORE INTO evolving_story_articles
                                (story_id, article_id, match_score, matched_at)
                             VALUES (?, ?, ?, ?)");

        $added = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $haystack = evolving_story_normalize_text(
                ($row['title'] ?? '') . ' ' . ($row['excerpt'] ?? '')
            );
            if ($haystack === '') continue;

            // Negative filter first.
            $skip = false;
            foreach ($story['exclude_keywords'] as $ex) {
                $ex = mb_strtolower(trim((string)$ex));
                if ($ex !== '' && mb_strpos($haystack, $ex) !== false) { $skip = true; break; }
            }
            if ($skip) continue;

            $score = 0;
            foreach ($story['keywords'] as $kw) {
                $kw = mb_strtolower(trim((string)$kw));
                if ($kw === '') continue;
                if (mb_strpos($haystack, $kw) !== false) $score++;
            }
            if ($score < max(1, $story['min_match_score'])) continue;

            $published = !empty($row['published_at']) ? $row['published_at'] : date('Y-m-d H:i:s');
            $ins->execute([$story['id'], (int)$row['id'], min(255, $score), $published]);
            if ($ins->rowCount() > 0) $added++;
        }

        if ($added > 0) {
            $db->prepare("UPDATE evolving_stories
                             SET article_count = ?, last_matched_at = NOW()
                           WHERE id = ?")
               ->execute([evolving_story_count_articles($story['id']), $story['id']]);
        } else {
            // Still refresh the cached count in case rows existed.
            evolving_story_recount($story['id']);
        }
        return $added;
    } catch (Throwable $e) {
        error_log('[evolving_stories] backfill: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Manually unlink an article from a story. Used by the admin UI when
 * the keyword matcher made a false positive.
 */
function evolving_story_unlink_article(int $storyId, int $articleId): bool {
    evolving_stories_ensure_tables();
    try {
        $db = getDB();
        $db->prepare("DELETE FROM evolving_story_articles WHERE story_id = ? AND article_id = ?")
           ->execute([$storyId, $articleId]);
        evolving_story_recount($storyId);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Build a preview of a story with up to N latest articles. Used by
 * the homepage rail and the /evolving-stories index — cheaper than
 * issuing one extra query per story from the template.
 */
function evolving_stories_with_previews(int $articlesPerStory = 3): array {
    $stories = evolving_stories_list(true);
    if (empty($stories)) return [];

    // Sort for the homepage rail by freshness: stories that had a
    // match most recently bubble up. Fall back to sort_order for
    // stories that haven't matched anything yet.
    usort($stories, function($a, $b) {
        $ta = strtotime($a['last_matched_at'] ?? '') ?: 0;
        $tb = strtotime($b['last_matched_at'] ?? '') ?: 0;
        if ($ta !== $tb) return $tb <=> $ta;
        return $a['sort_order'] <=> $b['sort_order'];
    });

    foreach ($stories as &$story) {
        $story['latest'] = evolving_story_articles($story['id'], $articlesPerStory);
        // Borrow a cover image from the newest article if the story
        // doesn't have its own uploaded cover.
        if (empty($story['cover_image']) && !empty($story['latest'])) {
            foreach ($story['latest'] as $a) {
                if (!empty($a['image_url'])) { $story['cover_image'] = $a['image_url']; break; }
            }
        }
    }
    unset($story);
    return $stories;
}

/**
 * URL helper — keeps the slug in one place. Callers should prefer
 * this over hard-coding "/evolving-story/..." so future rewrites
 * only need one edit here.
 */
function evolving_story_url(array $story): string {
    return '/evolving-story/' . rawurlencode((string)$story['slug']);
}
