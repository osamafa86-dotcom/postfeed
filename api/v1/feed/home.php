<?php
/**
 * GET /api/v1/feed/home.php
 *
 * Single endpoint returning every section rendered on the web homepage,
 * in the exact same order. Lets the iOS client render the full page in
 * one round-trip rather than stitching 10 calls together.
 *
 * Response shape:
 *   {
 *     ok: true,
 *     stats: { total_articles, active_sources, views_today, last_update },
 *     hero: Article?,                     // biggest featured
 *     featured_grid: [Article],           // 6 articles for the featured block
 *     palestine: { hero: Article?, items: [Article] },
 *     breaking: [Article],                // latest breaking
 *     category_rails: [{ slug, name, icon, css_class, items: [Article] }],
 *     most_read: [Article],
 *     trending: [TrendingItem],
 *     evolving_stories: [{slug,name,icon,cover_image,accent_color,article_count}],
 *     readers_now: int
 *   }
 */

require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('feed.home', 240, 60);

// Simple 60-second cache per query string, stored in storage/cache.
$cacheKey = 'home_' . md5((string)($_GET['v'] ?? '') . '_' . ($_GET['for_you'] ?? '0'));
$cacheFile = __DIR__ . '/../../../storage/cache/v1_' . $cacheKey . '.json';
$cacheTtl = 60;
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    header('X-Cache: HIT');
    readfile($cacheFile);
    exit;
}

try {
    $db = getDB();

    $articleColumns = "a.id, a.title, a.slug, a.excerpt, a.image_url, a.source_url,
        a.published_at, a.view_count, a.comments, a.is_breaking, a.is_featured,
        c.id AS category_id, c.name AS category_name, c.slug AS category_slug,
        c.icon AS category_icon, c.css_class AS category_class,
        s.id AS source_id, s.name AS source_name,
        s.logo_letter, s.logo_color, s.logo_bg, s.url AS source_site_url";
    $joins = "LEFT JOIN categories c ON a.category_id = c.id
              LEFT JOIN sources    s ON a.source_id   = s.id";

    // --- Stats strip --------------------------------------------------
    $stats = [
        'total_articles' => (int)$db->query("SELECT COUNT(*) FROM articles WHERE status = 'published'")->fetchColumn(),
        'active_sources' => (int)$db->query("SELECT COUNT(*) FROM sources WHERE is_active = 1")->fetchColumn(),
        'views_today'    => (int)$db->query("SELECT COALESCE(SUM(view_count),0) FROM articles WHERE DATE(published_at) = CURDATE()")->fetchColumn(),
        'last_update'    => (string)$db->query("SELECT MAX(published_at) FROM articles WHERE status = 'published'")->fetchColumn(),
    ];

    // --- Hero + featured grid -----------------------------------------
    $heroRow = $db->query("SELECT $articleColumns FROM articles a $joins WHERE a.status='published' AND a.is_featured=1 ORDER BY a.published_at DESC LIMIT 1")->fetch();
    $hero = $heroRow ? api_article_shape($heroRow, false) : null;

    $featuredSql = "SELECT $articleColumns FROM articles a $joins WHERE a.status='published'
                    " . ($heroRow ? 'AND a.id <> ' . (int)$heroRow['id'] : '') . "
                    ORDER BY a.is_featured DESC, a.published_at DESC LIMIT 6";
    $featured = array_map(fn($r) => api_article_shape($r, false), $db->query($featuredSql)->fetchAll());

    // --- Palestine rail (keyword search) ------------------------------
    $palestineKeywords = [
        'فلسطين','غزة','الضفة','القدس','الاحتلال','الفلسطيني','حماس',
        'المقاومة','الأقصى','رفح','خان يونس','جنين','نابلس','طوفان',
    ];
    $palestineClauses = [];
    $palestineParams = [];
    foreach ($palestineKeywords as $kw) {
        $palestineClauses[] = 'a.title LIKE ?';
        $palestineParams[] = '%' . $kw . '%';
    }
    $palestineSql = "SELECT $articleColumns FROM articles a $joins
                     WHERE a.status='published' AND (" . implode(' OR ', $palestineClauses) . ")
                     ORDER BY a.published_at DESC LIMIT 4";
    $stmt = $db->prepare($palestineSql);
    $stmt->execute($palestineParams);
    $palRows = $stmt->fetchAll();
    $palestine = [
        'hero'  => isset($palRows[0]) ? api_article_shape($palRows[0], false) : null,
        'items' => array_map(fn($r) => api_article_shape($r, false), array_slice($palRows, 1)),
    ];

    // --- Breaking strip ------------------------------------------------
    $breakingRows = $db->query("SELECT $articleColumns FROM articles a $joins
                                WHERE a.status='published' AND a.is_breaking=1
                                ORDER BY a.published_at DESC LIMIT 8")->fetchAll();
    $breaking = array_map(fn($r) => api_article_shape($r, false), $breakingRows);

    // --- Category rails (6 homepage categories) -----------------------
    $homepageSlugs = ['political','economy','sports','arts','media','reports'];
    $catMeta = $db->query("SELECT id, name, slug, icon, css_class FROM categories WHERE slug IN ('" . implode("','", $homepageSlugs) . "') ORDER BY sort_order ASC")->fetchAll();

    $categoryRails = [];
    foreach ($catMeta as $meta) {
        $stmt = $db->prepare("SELECT $articleColumns FROM articles a $joins
                              WHERE a.status='published' AND a.category_id = ?
                              ORDER BY a.published_at DESC LIMIT 4");
        $stmt->execute([(int)$meta['id']]);
        $categoryRails[] = [
            'slug' => $meta['slug'],
            'name' => $meta['name'],
            'icon' => $meta['icon'],
            'css_class' => $meta['css_class'],
            'items' => array_map(fn($r) => api_article_shape($r, false), $stmt->fetchAll()),
        ];
    }

    // --- Most read (last 24h by view_count) ---------------------------
    $mostReadRows = $db->query("SELECT $articleColumns FROM articles a $joins
                                WHERE a.status='published' AND a.published_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
                                ORDER BY a.view_count DESC LIMIT 8")->fetchAll();
    $mostRead = array_map(fn($r) => api_article_shape($r, false), $mostReadRows);

    // --- Evolving stories (if table exists) ---------------------------
    $evolving = [];
    try {
        $ev = $db->query("SELECT slug, name, icon, cover_image, accent_color, article_count
                          FROM evolving_stories WHERE is_active = 1
                          ORDER BY article_count DESC, id ASC LIMIT 6")->fetchAll();
        $evolving = array_map(fn($r) => [
            'slug' => $r['slug'],
            'name' => $r['name'],
            'icon' => $r['icon'],
            'cover_image' => $r['cover_image'],
            'accent_color' => $r['accent_color'],
            'article_count' => (int)$r['article_count'],
        ], $ev);
    } catch (Throwable $e) { /* table not deployed yet — ok */ }

    // --- Readers now (optional, cheap) --------------------------------
    $readersNow = 0;
    try {
        if (function_exists('trending_readers_now')) {
            $readersNow = (int)trending_readers_now();
        }
    } catch (Throwable $e) {}

    $response = [
        'ok' => true,
        'generated_at' => date('c'),
        'stats' => $stats,
        'hero' => $hero,
        'featured_grid' => $featured,
        'palestine' => $palestine,
        'breaking' => $breaking,
        'category_rails' => $categoryRails,
        'most_read' => $mostRead,
        'evolving_stories' => $evolving,
        'readers_now' => $readersNow,
    ];

    $json = json_encode($response, JSON_UNESCAPED_UNICODE);
    @mkdir(dirname($cacheFile), 0755, true);
    @file_put_contents($cacheFile, $json);

    header('X-Cache: MISS');
    echo $json;
} catch (Throwable $e) {
    error_log('v1/feed/home: ' . $e->getMessage());
    api_error('server_error', 'تعذّر تحميل الصفحة الرئيسية', 500);
}
