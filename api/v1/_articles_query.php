<?php
/**
 * Shared article-fetch helpers used by multiple endpoints.
 */

/**
 * Build a FULLTEXT MATCH...AGAINST expression for a user search query.
 * Returns ['sql' => '...', 'param' => '...'] when the wider search index
 * (ft_articles_search on title+excerpt+ai_summary) is present AND the
 * tokenized query yields at least one usable token. Returns null
 * otherwise so callers fall back to LIKE.
 *
 * Token rules: strip punctuation/tatweel, peel the Arabic "ال/وال/فال…"
 * prefix off each token (so "الأقصى" still finds documents that say
 * "أقصى"), and require every remaining token (BOOLEAN MODE +word*).
 * Tokens under 2 chars are dropped because BOOLEAN MODE wildcards
 * need a non-empty stem.
 */
function articles_search_clause(string $q): ?array {
    static $hasIdx = null;
    if ($hasIdx === null) {
        try {
            $hasIdx = (bool)getDB()
                ->query("SHOW INDEX FROM articles WHERE Key_name = 'ft_articles_search'")
                ->fetch();
        } catch (Throwable $e) {
            $hasIdx = false;
        }
    }
    if (!$hasIdx) return null;

    $q = preg_replace('/[\p{P}\p{S}\x{0640}]+/u', ' ', $q);
    $tokens = preg_split('/\s+/u', trim((string)$q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$tokens) return null;

    // MySQL FULLTEXT indexes each Arabic word AS-IS — "الأقصى" and
    // "أقصى" are independent tokens. So a query for one form won't
    // match documents that only contain the other. To get LIKE-level
    // recall we expand every token into its forms-with-and-without the
    // common Arabic article prefixes and OR them together inside a
    // required group: +(الأقصى* أقصى*) means "must contain one of
    // these forms". This roughly doubles the index hits but keeps the
    // FULLTEXT speed (still milliseconds, even on hundreds of thousands
    // of rows).
    $exprs = [];
    $opStrip = ['+', '-', '*', '"', '~', '<', '>', '(', ')', '@'];
    foreach ($tokens as $t) {
        $t = str_replace($opStrip, '', $t);
        if (mb_strlen($t) < 2) continue;

        $variants = [];
        $variants[$t] = true;

        // If the token starts with a common Arabic article prefix, also
        // search the stem ("الأقصى" → also "أقصى").
        $stripped = preg_replace('/^(وال|فال|بال|كال|ال|لل)/u', '', $t);
        if ($stripped !== '' && $stripped !== $t && mb_strlen($stripped) >= 2) {
            $variants[$stripped] = true;
        } else {
            // Otherwise, also search with "ال" prepended ("أقصى" →
            // also "الأقصى") because content is more often prefixed
            // than bare.
            $variants['ال' . $t] = true;
        }

        $forms = array_map(fn($v) => $v . '*', array_keys($variants));
        // Single variant → +word* ; multiple → +(form1* form2*)
        $exprs[] = count($forms) === 1
            ? '+' . $forms[0]
            : '+(' . implode(' ', $forms) . ')';
    }
    if (!$exprs) return null;

    return [
        'sql'   => 'MATCH(a.title, a.excerpt, a.ai_summary) AGAINST(? IN BOOLEAN MODE)',
        'param' => implode(' ', $exprs),
    ];
}

function articles_select_sql(): string {
    return "SELECT
        a.id, a.title, a.slug, a.excerpt, a.image_url, a.source_url,
        a.is_breaking, a.is_featured, a.is_hero,
        a.view_count, a.comments, a.published_at, a.created_at,
        a.category_id, a.source_id, a.cluster_key,
        a.ai_summary, a.ai_key_points,
        c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, c.css_class,
        s.name AS source_name, s.slug AS source_slug, s.logo_letter, s.logo_color, s.logo_bg, s.url AS source_site
      FROM articles a
      LEFT JOIN categories c ON c.id = a.category_id
      LEFT JOIN sources    s ON s.id = a.source_id";
}

function fetch_articles(array $filters = [], int $limit = 20, int $offset = 0): array {
    $db = getDB();
    $where = ["a.status = 'published'"];
    $params = [];

    if (!empty($filters['category'])) {
        $where[] = 'c.slug = ?';
        $params[] = $filters['category'];
    }
    if (!empty($filters['category_id'])) {
        $where[] = 'a.category_id = ?';
        $params[] = (int)$filters['category_id'];
    }
    if (!empty($filters['source'])) {
        $where[] = 's.slug = ?';
        $params[] = $filters['source'];
    }
    if (!empty($filters['source_id'])) {
        $where[] = 'a.source_id = ?';
        $params[] = (int)$filters['source_id'];
    }
    if (!empty($filters['breaking'])) {
        $where[] = 'a.is_breaking = 1';
    }
    if (!empty($filters['content_type'])) {
        // Multi-value support: ?content_type=report,article maps to IN(...).
        // The column was added by the lazy migration in
        // includes/content_classifier.php — try/catch keeps fetch_articles
        // working on installs that haven't deployed the classifier yet.
        $types = array_filter(array_map('trim', explode(',', (string)$filters['content_type'])));
        $allowed = ['news', 'report', 'article'];
        $types = array_values(array_intersect($allowed, $types));
        if (count($types) === 1) {
            $where[] = 'a.content_type = ?';
            $params[] = $types[0];
        } elseif (count($types) > 1) {
            $ph = implode(',', array_fill(0, count($types), '?'));
            $where[] = "a.content_type IN ($ph)";
            foreach ($types as $t) $params[] = $t;
        }
    }
    if (!empty($filters['category_slugs'])) {
        // For aggregate tabs like "منوعات" (sports+arts+tech+media).
        $slugs = is_array($filters['category_slugs'])
            ? $filters['category_slugs']
            : array_filter(array_map('trim', explode(',', (string)$filters['category_slugs'])));
        if (!empty($slugs)) {
            $ph = implode(',', array_fill(0, count($slugs), '?'));
            $where[] = "c.slug IN ($ph)";
            foreach ($slugs as $s) $params[] = $s;
        }
    }

    // Palestine focus filter — keyword match on the title. Powers the
    // dedicated أخبار فلسطين bucket. Same keyword list as the homepage's
    // getPalestineNews() in includes/functions.php — keep them in sync
    // so the app and the web show consistent rails. `not_palestine`
    // negates it so the عربي ودولي bucket doesn't double-show items.
    if (!empty($filters['palestine']) || !empty($filters['not_palestine'])) {
        $palKeywords = [
            'فلسطين', 'غزة', 'الضفة', 'القدس', 'الاحتلال', 'الفلسطيني',
            'حماس', 'المقاومة', 'الأقصى', 'رفح', 'خان يونس', 'جنين',
            'نابلس', 'طوفان', 'الشهداء', 'شهيد', 'إسرائيل', 'الإسرائيلي',
            'بيت لحم', 'الخليل', 'طولكرم', 'قلقيلية',
        ];
        $palClauses = [];
        foreach ($palKeywords as $kw) {
            $palClauses[] = 'a.title LIKE ?';
            $params[] = '%' . $kw . '%';
        }
        $combined = '(' . implode(' OR ', $palClauses) . ')';
        $where[] = !empty($filters['palestine']) ? $combined : "NOT $combined";
    }
    if (!empty($filters['featured'])) {
        $where[] = 'a.is_featured = 1';
    }
    if (!empty($filters['hero'])) {
        $where[] = 'a.is_hero = 1';
    }
    if (!empty($filters['since'])) {
        $where[] = 'a.published_at >= ?';
        $params[] = $filters['since'];
    }
    if (!empty($filters['until'])) {
        $where[] = 'a.published_at <= ?';
        $params[] = $filters['until'];
    }
    $ftSearch = null;
    if (!empty($filters['q'])) {
        $ftSearch = articles_search_clause((string)$filters['q']);
        if ($ftSearch) {
            $where[] = $ftSearch['sql'];
            $params[] = $ftSearch['param'];
        } else {
            // LIKE fallback when the wide FULLTEXT index isn't deployed yet
            // OR the query tokenized to nothing usable (e.g. all 1-char
            // tokens). ai_summary is included so the AI-extracted keywords
            // still drive matches — same column the FULLTEXT covers.
            $where[] = '(a.title LIKE ? OR a.excerpt LIKE ? OR a.ai_summary LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
    }
    if (!empty($filters['ids']) && is_array($filters['ids'])) {
        $in = implode(',', array_map('intval', $filters['ids']));
        if ($in !== '') $where[] = "a.id IN ($in)";
    }
    if (!empty($filters['cluster_key'])) {
        // Coverage-comparison view: pull every article that shares a
        // cluster_key. Only accept the canonical 40-char sha1 the
        // pipeline stamps; anything else is silently ignored so we
        // never bind garbage to the query.
        $ck = (string)$filters['cluster_key'];
        if (preg_match('/^[a-f0-9]{40}$/', $ck)) {
            $where[] = 'a.cluster_key = ?';
            $params[] = $ck;
        }
    }

    $order = $filters['order'] ?? 'published_at DESC';
    $allowedOrders = [
        'published_at DESC', 'published_at ASC',
        'view_count DESC', 'view_count ASC',
        'created_at DESC',
    ];
    if (!in_array($order, $allowedOrders, true)) $order = 'published_at DESC';

    // Search results: relevance first, recency as tiebreaker. MySQL
    // computes MATCH() once when the same expression appears in WHERE
    // and ORDER BY, so the extra placeholder is free at execution time.
    if ($ftSearch) {
        $order = $ftSearch['sql'] . ' DESC, a.published_at DESC';
        $params[] = $ftSearch['param'];
    }

    $sql = articles_select_sql()
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . $order
        . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    return array_map('api_format_article', $rows);
}

function count_articles(array $filters = []): int {
    $db = getDB();
    $where = ["a.status = 'published'"];
    $params = [];
    if (!empty($filters['category'])) { $where[] = 'c.slug = ?'; $params[] = $filters['category']; }
    if (!empty($filters['source']))   { $where[] = 's.slug = ?'; $params[] = $filters['source']; }
    if (!empty($filters['breaking'])) { $where[] = 'a.is_breaking = 1'; }
    if (!empty($filters['q'])) {
        $ftSearch = articles_search_clause((string)$filters['q']);
        if ($ftSearch) {
            $where[] = $ftSearch['sql'];
            $params[] = $ftSearch['param'];
        } else {
            $where[] = '(a.title LIKE ? OR a.excerpt LIKE ? OR a.ai_summary LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
    }
    $sql = "SELECT COUNT(*) FROM articles a
            LEFT JOIN categories c ON c.id = a.category_id
            LEFT JOIN sources    s ON s.id = a.source_id
            WHERE " . implode(' AND ', $where);
    $st = $db->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}
