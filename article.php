<?php
/**
 * نيوزفلو - صفحة المقالة الواحدة
 * =============================
 * عرض مقالة واحدة برابط: article.php?id=N
 */

session_start();
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/user_functions.php';
require_once __DIR__ . '/includes/trending.php';

// Get article ID
$articleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$articleId) {
    header('Location: index.php');
    exit;
}

// Fetch article
$article = getArticleById($articleId);

if (!$article) {
    http_response_code(404);
    header('Location: index.php');
    exit;
}

// Increment view count
try {
    $db = getDB();
    $stmt = $db->prepare("UPDATE articles SET `view_count` = `view_count` + 1 WHERE id = ?");
    $stmt->execute([$articleId]);
} catch (Exception $e) {
    // Silent fail on view count increment
}

// Velocity log for "trending now" rail. Skips bots internally.
// 1% chance per view we also prune the events table — saves a cron.
trending_log_view($articleId);
if (mt_rand(1, 100) === 1) {
    try { trending_prune(getDB()); } catch (Throwable $e) {}
}

// Fetch user context (for save button, comments, theme)
$viewer = current_user();
$viewerId = $viewer ? (int)$viewer['id'] : 0;
$isSaved = $viewerId && in_array($articleId, user_bookmark_ids_for($viewerId, [$articleId]), true);
$comments = article_comments($articleId, $viewerId ?: null);
$commentsCount = count($comments);
$pageTheme = current_theme();

// Get related articles (same category)
$relatedArticles = [];
if ($article['cat_slug']) {
    $db = getDB();
    $stmt = $db->prepare("SELECT a.id, a.title, a.image_url, a.excerpt, a.published_at,
                          c.name as cat_name, c.slug as cat_slug, c.css_class,
                          s.name as source_name
                          FROM articles a
                          LEFT JOIN categories c ON a.category_id = c.id
                          LEFT JOIN sources s ON a.source_id = s.id
                          WHERE c.slug = ? AND a.id != ? AND a.status = 'published'
                          ORDER BY a.published_at DESC LIMIT 4");
    $stmt->execute([$article['cat_slug'], $articleId]);
    $relatedArticles = $stmt->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo e($pageTheme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="/">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <?php
        $seoDesc = !empty($article['ai_summary'])
            ? mb_substr(trim(preg_replace('/\s+/', ' ', $article['ai_summary'])), 0, 160)
            : mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($article['excerpt']))), 0, 160);
        $seoKeywords = !empty($article['ai_keywords'])
            ? $article['ai_keywords']
            : ($article['cat_name'] . '، أخبار، نيوزفلو');
        // Canonical points to original source to avoid duplicate-content penalty
        $canonical = !empty($article['source_url'])
            ? $article['source_url']
            : SITE_URL . '/article.php?id=' . (int)$article['id'];
        $selfUrl = SITE_URL . '/article.php?id=' . (int)$article['id'];
        $publishedISO = !empty($article['published_at']) ? date('c', strtotime($article['published_at'])) : date('c');
        $modifiedISO  = !empty($article['ai_processed_at']) ? date('c', strtotime($article['ai_processed_at'])) : $publishedISO;
    ?>
    <title><?php echo e($article['title']); ?> - <?php echo SITE_NAME; ?></title>
    <meta name="description" content="<?php echo e($seoDesc); ?>">
    <meta name="keywords" content="<?php echo e($seoKeywords); ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <meta name="author" content="<?php echo e($article['source_name'] ?? SITE_NAME); ?>">
    <link rel="canonical" href="<?php echo e($canonical); ?>">
    <link rel="alternate" hreflang="ar" href="<?php echo e($selfUrl); ?>">
    <link rel="alternate" hreflang="x-default" href="<?php echo e($selfUrl); ?>">
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#1a73e8">

    <!-- OG Tags -->
    <meta property="og:site_name" content="<?php echo e(SITE_NAME); ?>">
    <meta property="og:locale" content="ar_AR">
    <meta property="og:title" content="<?php echo e($article['title']); ?>">
    <meta property="og:description" content="<?php echo e($seoDesc); ?>">
    <meta property="og:image" content="<?php echo e($article['image_url']); ?>">
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?php echo e($selfUrl); ?>">
    <meta property="article:published_time" content="<?php echo e($publishedISO); ?>">
    <meta property="article:modified_time" content="<?php echo e($modifiedISO); ?>">
    <meta property="article:section" content="<?php echo e($article['cat_name']); ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo e($article['title']); ?>">
    <meta name="twitter:description" content="<?php echo e($seoDesc); ?>">
    <meta name="twitter:image" content="<?php echo e($article['image_url']); ?>">

    <!-- JSON-LD NewsArticle -->
    <script type="application/ld+json">
    <?php echo json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        'headline' => $article['title'],
        'description' => $seoDesc,
        'image' => [$article['image_url']],
        'datePublished' => $publishedISO,
        'dateModified' => $modifiedISO,
        'author' => ['@type' => 'Organization', 'name' => $article['source_name'] ?? SITE_NAME],
        'publisher' => [
            '@type' => 'Organization',
            'name' => SITE_NAME,
            'logo' => ['@type' => 'ImageObject', 'url' => SITE_URL . '/assets/logo.png']
        ],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $selfUrl],
        'articleSection' => $article['cat_name'],
        'keywords' => $seoKeywords,
        'inLanguage' => 'ar',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    </script>

    <!-- BreadcrumbList -->
    <script type="application/ld+json">
    <?php echo json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => SITE_NAME, 'item' => SITE_URL],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $article['cat_name'] ?? '', 'item' => SITE_URL . '/category.php?slug=' . ($article['cat_slug'] ?? '')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $article['title']],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"></noscript>
    <style>
        :root {
            --primary: #1a73e8;
            --primary-light: #eff6ff;
            --dark: #1a1a2e;
            --light: #faf6ec;
            --gray: #6b7280;
            --border: #e0e3e8;
            --card: #ffffff;
            --read-font: 1.1rem;
        }
        [data-theme="dark"] {
            --dark: #e5e7eb;
            --light: #0f172a;
            --card: #1e293b;
            --gray: #94a3b8;
            --border: #334155;
            --primary-light: #1e293b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, sans-serif;
            background: var(--light);
            color: var(--dark);
            line-height: 1.7;
        }

        a { text-decoration: none; color: inherit; }

        .header {
            background: var(--dark);
            padding: 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            height: 64px;
            display: flex;
            align-items: center;
        }

        .container {
            max-width: 820px;
            margin: 0 auto;
            padding: 0 1.25rem;
        }

        .header .container {
            max-width: 1200px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 900;
            color: #fff;
            text-decoration: none;
        }
        .logo span { color: #60a5fa; }

        .back-btn {
            color: rgba(255,255,255,.6);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.2s;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .back-btn:hover {
            background: rgba(255,255,255,.08);
            color: #fff;
        }

        .article-hero {
            width: 100%;
            height: 450px;
            object-fit: cover;
            background: #334155;
            border-radius: 16px;
            margin: 2rem 0;
            box-shadow: 0 8px 30px rgba(0,0,0,.12);
        }

        .article-header {
            margin: 2rem 0;
        }

        .article-meta {
            display: flex;
            gap: 1.25rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            font-size: 0.9rem;
            color: var(--gray);
            align-items: center;
            font-weight: 500;
        }

        .category-badge {
            display: inline-block;
            padding: 0.35rem 0.9rem;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            color: white;
            letter-spacing: .3px;
        }

        .category-badge.cat-political { background: #dc2626; }
        .category-badge.cat-sports { background: #1d4ed8; }
        .category-badge.cat-economic { background: #15803d; }
        .category-badge.cat-arts { background: #7c3aed; }
        .category-badge.cat-tech { background: #0d9488; }
        .category-badge.cat-health { background: #16a34a; }
        .category-badge.cat-reports { background: #b45309; }
        .category-badge.cat-media { background: #a21caf; }

        h1 {
            font-size: 2.2rem;
            line-height: 1.4;
            margin: 1rem 0;
            color: var(--dark);
            font-weight: 900;
        }

        .article-excerpt {
            font-size: 1.15rem;
            color: var(--gray);
            margin: 1rem 0;
            font-weight: 500;
            line-height: 1.8;
        }

        .source-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.1rem 1.25rem;
            background: var(--card);
            border-radius: 12px;
            margin: 1.5rem 0;
            border: 1px solid var(--border);
        }

        .source-logo {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: white;
            font-size: 1.3rem;
        }

        .source-details h3 {
            font-size: 0.95rem;
            margin-bottom: 0.2rem;
            font-weight: 700;
        }

        .source-time {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .article-content {
            background: var(--card);
            padding: 2.5rem;
            border-radius: 16px;
            margin: 2rem 0;
            line-height: 2;
            font-size: 1.1rem;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }

        .article-content p { margin: 1.5rem 0; }
        .article-content h2 { font-size: 1.6rem; margin: 2rem 0 1rem; color: var(--dark); font-weight: 800; }
        .article-content h3 { font-size: 1.25rem; margin: 1.5rem 0 0.8rem; color: var(--dark); font-weight: 700; }
        .article-content strong { color: var(--dark); }
        .article-content em { color: var(--gray); }
        .article-content a { color: var(--primary); border-bottom: 1px solid rgba(26,115,232,.3); transition: all .2s; }
        .article-content a:hover { background: var(--primary-light); }
        .article-content blockquote { border-right: 4px solid var(--primary); padding: 1rem 1.5rem; margin: 1.5rem 0; background: var(--primary-light); border-radius: 0 8px 8px 0; }

        .article-footer {
            background: var(--card);
            padding: 2rem 2.5rem;
            border-radius: 16px;
            margin: 2rem 0;
            border: 1px solid var(--border);
        }

        .sharing {
            display: flex;
            gap: 0.75rem;
            margin: 1rem 0;
        }

        .share-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: var(--light);
            color: var(--gray);
            text-decoration: none;
            transition: all 0.25s;
            font-size: 1rem;
            border: 1px solid var(--border);
            font-weight: 700;
        }

        .share-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(26,115,232,.3);
        }

        .related-articles { margin: 3rem 0; }
        .related-articles h2 { font-size: 1.5rem; margin-bottom: 1.5rem; color: var(--dark); font-weight: 800; }

        .articles-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }

        .article-card {
            background: var(--card);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }

        .article-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-color: rgba(26,115,232,.2);
        }

        .article-card-image {
            width: 100%;
            height: 160px;
            object-fit: cover;
            background: #e5e7eb;
        }

        .article-card-body {
            padding: 1.1rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .article-card-title {
            font-weight: 700;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .article-card-meta {
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: 500;
        }

        .footer {
            background: var(--dark);
            color: rgba(255,255,255,.5);
            padding: 2rem 0;
            margin-top: 3rem;
            text-align: center;
            font-size: 0.9rem;
        }

        .footer p { margin: 0.3rem 0; }

        /* === Reading progress bar === */
        .read-progress {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: transparent;
            z-index: 200;
            pointer-events: none;
        }
        .read-progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #1a73e8, #4f46e5);
            transition: width .1s linear;
            box-shadow: 0 0 8px rgba(26,115,232,.5);
        }

        /* === Source card (under hero image) — compact amber theme === */
        .source-top {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            background:
                linear-gradient(135deg, #fff8e1 0%, #fff3c4 55%, #ffe9a8 100%);
            border: 1px solid rgba(217,119,6,.22);
            border-radius: 14px;
            margin: 1rem 0 1.25rem;
            box-shadow:
                0 6px 18px rgba(217,119,6,.10),
                0 1px 2px rgba(0,0,0,.03),
                inset 0 1px 0 rgba(255,255,255,.55);
            overflow: hidden;
            transition: box-shadow .25s ease, transform .25s ease;
        }
        /* subtle decorative shine in the corner */
        .source-top::before {
            content:'';
            position: absolute;
            top: -40%;
            left: -10%;
            width: 55%;
            height: 180%;
            background: radial-gradient(closest-side, rgba(255,255,255,.5), transparent 70%);
            pointer-events: none;
        }
        .source-top:hover {
            box-shadow:
                0 10px 24px rgba(217,119,6,.16),
                0 2px 4px rgba(0,0,0,.04),
                inset 0 1px 0 rgba(255,255,255,.55);
        }
        .source-top-logo-link {
            display: inline-flex;
            flex-shrink: 0;
            text-decoration: none;
            border-radius: 10px;
            transition: transform .2s ease;
            position: relative;
            z-index: 1;
        }
        .source-top-logo-link:hover { transform: scale(1.06) rotate(-2deg); }
        .source-top .source-logo {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            font-size: 1.05rem;
            font-weight: 900;
            flex-shrink: 0;
            box-shadow:
                0 4px 10px rgba(0,0,0,.12),
                inset 0 -2px 4px rgba(0,0,0,.1),
                0 0 0 2px rgba(255,255,255,.7);
        }
        .source-top-info {
            flex: 1;
            min-width: 0;
            position: relative;
            z-index: 1;
        }
        .source-top-info h3 {
            font-size: .92rem;
            font-weight: 800;
            margin: 0 0 2px;
            color: #5a3a00;
            line-height: 1.25;
        }
        .source-top-name {
            color: inherit;
            text-decoration: none;
            background-image: linear-gradient(#b45309, #b45309);
            background-size: 0% 2px;
            background-repeat: no-repeat;
            background-position: right 100%;
            transition: background-size .25s ease, color .2s ease;
            padding-bottom: 1px;
        }
        .source-top-name:hover {
            color: #b45309;
            background-size: 100% 2px;
        }
        .source-top-meta {
            font-size: .72rem;
            color: #8a5a0b;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .source-top-meta .category-badge { font-size: .68rem; padding: 2px 8px; }
        .source-top-meta .dot { width:3px;height:3px;border-radius:50%;background:#b45309;opacity:.45; }
        .source-top-visit {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 13px;
            background: #fff;
            color: #b45309;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 800;
            border: 1px solid rgba(217,119,6,.35);
            box-shadow: 0 2px 6px rgba(217,119,6,.14);
            transition: all .25s ease;
            white-space: nowrap;
            position: relative;
            z-index: 1;
        }
        .source-top-visit:hover {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            border-color: #d97706;
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(217,119,6,.32);
        }

        /* === Article toolbar (TTS, font, share, save, print) === */
        .article-toolbar {
            display: flex;
            gap: 8px;
            align-items: center;
            padding: 10px 14px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            margin: 1.25rem 0;
            flex-wrap: wrap;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .tool-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--dark);
            font-family: inherit;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
        }
        .tool-btn:hover {
            background: var(--primary-light);
            border-color: var(--primary);
            color: var(--primary);
        }
        .tool-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }
        .tool-btn svg {
            width: 16px;
            height: 16px;
            fill: currentColor;
        }
        .tool-group {
            display: inline-flex;
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }
        .tool-group .tool-btn {
            border: none;
            border-radius: 0;
            padding: 8px 10px;
        }
        .tool-group .tool-btn + .tool-btn {
            border-right: 1px solid var(--border);
        }
        .tool-divider {
            width: 1px;
            height: 24px;
            background: var(--border);
            margin: 0 4px;
        }
        .tool-spacer { flex: 1; }

        /* Live reading time badge */
        .read-time-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 700;
            border: 1px solid rgba(26,115,232,.2);
        }

        /* Article content responds to font-size CSS var */
        .article-content { font-size: var(--read-font); }

        /* === AI summary (prominent) === */
        .ai-summary-card {
            background: linear-gradient(135deg, #eff6ff, #ede9fe);
            border: 1px solid #bae6fd;
            border-right: 5px solid #1a73e8;
            border-radius: 16px;
            padding: 24px 28px;
            margin: 1.5rem 0;
            box-shadow: 0 4px 20px rgba(26,115,232,.08);
        }
        [data-theme="dark"] .ai-summary-card {
            background: linear-gradient(135deg, #172033, #1e1b4b);
            border-color: #334155;
            border-right-color: #60a5fa;
        }
        .ai-summary-card .ai-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }
        .ai-summary-card .ai-icon {
            width: 36px;
            height: 36px;
            background: #1a73e8;
            color: #fff;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            box-shadow: 0 4px 12px rgba(26,115,232,.3);
        }
        .ai-summary-card .ai-title {
            font-size: 1rem;
            font-weight: 800;
            color: #0f172a;
        }
        [data-theme="dark"] .ai-summary-card .ai-title { color: #e5e7eb; }
        .ai-summary-card .ai-subtitle {
            font-size: .75rem;
            color: #64748b;
            margin-top: 1px;
        }
        .ai-summary-body {
            font-size: 1.02rem;
            line-height: 1.9;
            color: #1e293b;
            margin-bottom: 16px;
        }
        [data-theme="dark"] .ai-summary-body { color: #cbd5e1; }
        .key-points-title {
            font-weight: 800;
            color: #0369a1;
            font-size: .85rem;
            margin: 16px 0 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        [data-theme="dark"] .key-points-title { color: #60a5fa; }
        .key-points {
            display: grid;
            gap: 10px;
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .key-points li {
            background: rgba(255,255,255,.6);
            padding: 10px 14px;
            border-radius: 10px;
            font-size: .92rem;
            line-height: 1.7;
            color: #334155;
            border: 1px solid rgba(186,230,253,.5);
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        [data-theme="dark"] .key-points li {
            background: rgba(15,23,42,.4);
            color: #cbd5e1;
            border-color: rgba(96,165,250,.2);
        }
        .key-points li::before {
            content: "◆";
            color: #1a73e8;
            font-size: .8rem;
            flex-shrink: 0;
            margin-top: 3px;
        }

        /* === Compare coverage CTA — multi-source cluster banner === */
        .compare-coverage-cta {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 18px 22px;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1.5px solid #fcd34d;
            border-radius: 16px;
            margin: 1.5rem 0 2rem;
            text-decoration: none;
            color: #78350f;
            transition: all .25s ease;
            box-shadow: 0 4px 16px -6px rgba(245,158,11,.25);
        }
        .compare-coverage-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 28px -10px rgba(245,158,11,.35);
            border-color: #f59e0b;
        }
        .compare-coverage-cta .cc-icon {
            font-size: 32px;
            flex-shrink: 0;
            background: rgba(255,255,255,.6);
            width: 56px; height: 56px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 14px;
        }
        .compare-coverage-cta .cc-text { flex: 1; display: flex; flex-direction: column; gap: 4px; }
        .compare-coverage-cta .cc-text strong { font-size: 17px; font-weight: 900; color: #78350f; }
        .compare-coverage-cta .cc-text em { font-style: normal; font-size: 13px; color: #92400e; line-height: 1.55; }
        .compare-coverage-cta .cc-arrow { font-size: 28px; font-weight: 900; color: #92400e; flex-shrink: 0; }
        [data-theme="dark"] .compare-coverage-cta {
            background: linear-gradient(135deg, rgba(120,53,15,.4) 0%, rgba(146,64,14,.5) 100%);
            border-color: rgba(245,158,11,.5);
            color: #fde68a;
        }
        [data-theme="dark"] .compare-coverage-cta .cc-text strong,
        [data-theme="dark"] .compare-coverage-cta .cc-text em,
        [data-theme="dark"] .compare-coverage-cta .cc-arrow { color: #fde68a; }

        /* === Source CTA (read full article) === */
        .source-cta {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px 24px;
            background: linear-gradient(135deg, #1a73e8, #4f46e5);
            color: #fff;
            border-radius: 16px;
            margin: 2rem 0;
            box-shadow: 0 8px 24px rgba(26,115,232,.25);
            text-decoration: none;
            transition: all .25s;
        }
        .source-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(26,115,232,.35);
        }
        .source-cta-icon {
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
            backdrop-filter: blur(8px);
        }
        .source-cta-text { flex: 1; min-width: 0; }
        .source-cta-label {
            font-size: .78rem;
            opacity: .85;
            font-weight: 500;
            margin-bottom: 2px;
        }
        .source-cta-title {
            font-size: 1rem;
            font-weight: 800;
        }
        .source-cta-arrow {
            font-size: 1.3rem;
            opacity: .9;
            flex-shrink: 0;
        }

        /* === TTS player === */
        .tts-player {
            display: none;
            flex-direction: column;
            gap: 12px;
            padding: 14px 18px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            margin: 1rem 0;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .tts-player.active { display: flex; }
        .tts-player-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .tts-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 700;
        }
        .tts-ctrl {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: none;
            background: var(--primary);
            color: #fff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all .2s;
        }
        .tts-ctrl:hover { transform: scale(1.05); box-shadow: 0 4px 12px rgba(26,115,232,.3); }
        .tts-ctrl.secondary {
            background: transparent;
            color: var(--gray);
            border: 1px solid var(--border);
            width: 34px;
            height: 34px;
        }
        .tts-speed {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 6px 10px;
            font-family: inherit;
            font-size: .8rem;
            font-weight: 600;
            color: var(--dark);
            cursor: pointer;
        }
        .tts-time {
            font-size: .75rem;
            color: var(--gray);
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            min-width: 78px;
            text-align: center;
        }
        .tts-status {
            font-size: .85rem;
            color: var(--gray);
            font-weight: 500;
            flex: 1;
            min-width: 150px;
        }
        .tts-progress {
            height: 6px;
            background: var(--border);
            border-radius: 999px;
            overflow: hidden;
            cursor: pointer;
            position: relative;
        }
        .tts-progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--primary), #0d9488);
            border-radius: 999px;
            transition: width .15s linear;
        }
        /* Hide the raw <audio> element — we render our own chrome. */
        #ttsAudio { display: none; }

        /* Dark theme fixes for article content */
        [data-theme="dark"] .article-content,
        [data-theme="dark"] .article-footer,
        [data-theme="dark"] .article-card {
            background: var(--card);
            color: var(--dark);
        }
        [data-theme="dark"] .article-hero { background: #1e293b; }
        [data-theme="dark"] h1 { color: var(--dark); }

        /* Print-friendly */
        @media print {
            .header, .footer, .article-toolbar, .tts-player, .source-cta,
            .source-top-visit, .article-save-btn, .share-btn, .sharing,
            .comments-wrap, .related-articles, .read-progress { display: none !important; }
            body { background: #fff !important; color: #000 !important; }
            .article-content, .article-header, .source-top, .ai-summary-card {
                border: none !important; box-shadow: none !important; background: #fff !important;
            }
            .article-hero { max-height: 300px; }
        }

        @media (max-width: 768px) {
            h1 { font-size: 1.6rem; }
            .article-content { padding: 1.25rem; font-size: 1rem; }
            .article-hero { height: 250px; border-radius: 12px; }
            .articles-grid { grid-template-columns: 1fr; }
            .article-footer { padding: 1.5rem; }
            .source-top { flex-wrap: wrap; padding: 10px 12px; gap: 10px; }
            .source-top-info { flex-basis: calc(100% - 54px); }
            .source-top-visit { width: 100%; justify-content: center; padding: 8px 14px; }
            .article-toolbar { padding: 8px 10px; gap: 6px; }
            .tool-btn { padding: 7px 10px; font-size: .8rem; }
            .tool-btn span { display: none; }
            .tool-spacer { display: none; }
            .ai-summary-card { padding: 18px; }
            .source-cta { flex-direction: column; text-align: center; padding: 18px; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/user.min.css?v=m1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
</head>
<body>
    <!-- Reading progress bar -->
    <div class="read-progress" aria-hidden="true"><div class="read-progress-bar" id="readProgressBar"></div></div>

    <!-- Header -->
    <header class="header">
        <div class="container">
            <a href="/" class="logo">نيوز<span>فلو</span></a>
            <a href="/" class="back-btn">&larr; العودة للرئيسية</a>
        </div>
    </header>

    <?php
        // Estimate read time: ~180 Arabic words/min
        $wordCount = str_word_count(strip_tags(($article['content'] ?? '') . ' ' . ($article['ai_summary'] ?? '')));
        if ($wordCount < 50) $wordCount = mb_strlen(strip_tags($article['content'] ?? '')) / 6;
        $readMinutes = max(1, (int)ceil($wordCount / 180));
        // Pre-compute source URL for CTA/top visit
        $readMoreUrl = '';
        if (!empty($article['source_url']))       { $readMoreUrl = $article['source_url']; }
        elseif (!empty($article['source_website'])) { $readMoreUrl = $article['source_website']; }
    ?>

    <!-- Main Content -->
    <main class="container">

        <!-- Article Hero Image -->
        <?php if ($article['image_url']): ?>
            <img src="<?php echo e($article['image_url']); ?>" alt="<?php echo e($article['title']); ?>" class="article-hero" decoding="async" fetchpriority="high">
        <?php endif; ?>

        <!-- === SOURCE CARD (under hero image) === -->
        <?php if ($article['source_name']):
            $sourceProfileUrl = !empty($article['source_id']) ? sourceUrl((int)$article['source_id']) : '';
        ?>
            <div class="source-top">
                <?php if ($sourceProfileUrl): ?>
                    <a class="source-top-logo-link" href="<?php echo e($sourceProfileUrl); ?>" title="<?php echo e('بروفايل ' . $article['source_name']); ?>">
                <?php endif; ?>
                    <div class="source-logo" style="background: <?php echo e($article['logo_bg'] ?: '#3b82f6'); ?>; color: <?php echo e($article['logo_color'] ?: 'white'); ?>;">
                        <?php echo e(strtoupper(mb_substr($article['source_name'], 0, 1))); ?>
                    </div>
                <?php if ($sourceProfileUrl): ?>
                    </a>
                <?php endif; ?>
                <div class="source-top-info">
                    <?php if ($sourceProfileUrl): ?>
                        <h3><a class="source-top-name" href="<?php echo e($sourceProfileUrl); ?>"><?php echo e($article['source_name']); ?></a></h3>
                    <?php else: ?>
                        <h3><?php echo e($article['source_name']); ?></h3>
                    <?php endif; ?>
                    <div class="source-top-meta">
                        <?php if ($article['cat_name']): ?>
                            <span class="category-badge <?php echo e($article['css_class'] ?? ''); ?>"><?php echo e($article['cat_name']); ?></span>
                        <?php endif; ?>
                        <?php if ($article['is_breaking']): ?>
                            <span class="category-badge" style="background:#dc2626;">عاجل</span>
                        <?php endif; ?>
                        <span><?php echo timeAgo($article['published_at']); ?></span>
                        <span class="dot"></span>
                        <span>👁 <?php echo formatViews($article['view_count']); ?></span>
                        <span class="dot"></span>
                        <span class="read-time-badge">⏱ <?php echo (int)$readMinutes; ?> د قراءة</span>
                    </div>
                </div>
                <?php if ($readMoreUrl): ?>
                    <a class="source-top-visit" href="<?php echo e($readMoreUrl); ?>" target="_blank" rel="noopener noreferrer">
                        زيارة المصدر &larr;
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Article Header -->
        <article class="article-header">
            <h1 id="articleTitle"><?php echo e($article['title']); ?></h1>

            <?php if (!empty($article['excerpt'])): ?>
                <p class="article-excerpt"><?php echo e($article['excerpt']); ?></p>
            <?php endif; ?>

            <!-- === TOOLBAR === -->
            <div class="article-toolbar" role="toolbar" aria-label="أدوات المقالة">
                <button type="button" class="tool-btn" id="ttsToggle" title="استمع إلى المقالة">
                    <svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                    <span>استماع</span>
                </button>

                <div class="tool-group" title="حجم الخط">
                    <button type="button" class="tool-btn" id="fontDec" title="تصغير الخط" aria-label="تصغير الخط">A-</button>
                    <button type="button" class="tool-btn" id="fontReset" title="إعادة" aria-label="حجم الخط الافتراضي">A</button>
                    <button type="button" class="tool-btn" id="fontInc" title="تكبير الخط" aria-label="تكبير الخط">A+</button>
                </div>

                <button type="button" class="tool-btn" id="copyLinkBtn" title="نسخ الرابط">
                    <svg viewBox="0 0 24 24"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7a5 5 0 1 0 0 10h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4a5 5 0 1 0 0-10z"/></svg>
                    <span>نسخ الرابط</span>
                </button>

                <button type="button" class="tool-btn" onclick="window.print()" title="طباعة">
                    <svg viewBox="0 0 24 24"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
                    <span>طباعة</span>
                </button>

                <div class="tool-spacer"></div>

                <button type="button" class="tool-btn article-save-btn <?php echo $isSaved ? 'active' : ''; ?>" data-save-id="<?php echo (int)$articleId; ?>" onclick="NF.toggleSave(this)">
                    <svg viewBox="0 0 24 24"><path d="M17 3H7c-1.1 0-2 .9-2 2v16l7-3 7 3V5c0-1.1-.9-2-2-2z"/></svg>
                    <span><?php echo $isSaved ? 'محفوظ' : 'حفظ'; ?></span>
                </button>
            </div>

            <!-- TTS Player (hidden until play pressed) -->
            <div class="tts-player" id="ttsPlayer" aria-hidden="true" data-article-id="<?php echo (int)$articleId; ?>">
                <div class="tts-player-row">
                    <span class="tts-pill">🔊 وضع الاستماع</span>
                    <button type="button" class="tts-ctrl" id="ttsPlayBtn" aria-label="تشغيل">▶</button>
                    <button type="button" class="tts-ctrl secondary" id="ttsStopBtn" aria-label="إيقاف">■</button>
                    <select class="tts-speed" id="ttsSpeed" aria-label="سرعة القراءة">
                        <option value="0.75">0.75x</option>
                        <option value="1" selected>1x</option>
                        <option value="1.25">1.25x</option>
                        <option value="1.5">1.5x</option>
                        <option value="2">2x</option>
                    </select>
                    <span class="tts-time" id="ttsTime">0:00 / 0:00</span>
                    <span class="tts-status" id="ttsStatus">جاهز للقراءة</span>
                </div>
                <div class="tts-progress" id="ttsProgress" aria-hidden="true">
                    <div class="tts-progress-bar" id="ttsProgressBar"></div>
                </div>
                <!-- Hidden <audio> element populated by the JS below when
                     cloud TTS is enabled. Falls back to window.speechSynthesis
                     if /api/tts.php returns 404 (cloud TTS disabled server-side). -->
                <audio id="ttsAudio" preload="none"></audio>
            </div>
        </article>

        <!-- === AI Summary (moved above content, prominent) === -->
        <?php if (!empty($article['ai_summary'])): ?>
            <div class="ai-summary-card">
                <div class="ai-head">
                    <div class="ai-icon">🤖</div>
                    <div>
                        <div class="ai-title">ملخص ذكي بالذكاء الاصطناعي</div>
                        <div class="ai-subtitle">مُلخَّص تلقائياً من الخبر الأصلي</div>
                    </div>
                </div>
                <div class="ai-summary-body" id="aiSummaryBody"><?php echo nl2br(e($article['ai_summary'])); ?></div>
                <?php
                $keyPoints = !empty($article['ai_key_points']) ? json_decode($article['ai_key_points'], true) : [];
                if (is_array($keyPoints) && !empty($keyPoints)): ?>
                    <div class="key-points-title">📌 أبرز النقاط</div>
                    <ul class="key-points">
                        <?php foreach ($keyPoints as $kp): ?>
                            <li><?php echo e($kp); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php
                // Topic chips: turn the comma-separated ai_keywords into
                // clickable links to /topic/{kw}. Lets readers jump from
                // one article to every related story we've ingested.
                $__kwList = !empty($article['ai_keywords'])
                    ? array_filter(array_map('trim', explode(',', (string)$article['ai_keywords'])), fn($s) => $s !== '')
                    : [];
                if (!empty($__kwList)): ?>
                    <div class="ai-topics" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;padding-top:14px;border-top:1px dashed rgba(0,0,0,.08);">
                        <?php foreach (array_slice($__kwList, 0, 8) as $__kw): ?>
                            <a href="/topic/<?php echo rawurlencode($__kw); ?>" style="display:inline-flex;align-items:center;gap:4px;background:rgba(13,148,136,.08);color:#0d9488;border:1px solid rgba(13,148,136,.2);padding:5px 12px;border-radius:999px;font-size:12px;font-weight:700;text-decoration:none;transition:all .2s;" onmouseover="this.style.background='#0d9488';this.style.color='#fff';" onmouseout="this.style.background='rgba(13,148,136,.08)';this.style.color='#0d9488';">#<?php echo e($__kw); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- === Compare coverage callout ===
             If this article is part of a multi-source cluster, surface a
             prominent CTA so readers can jump to the side-by-side
             "قارن التغطية" page. Validates the key shape inline so a
             stale row with junk data is just hidden. -->
        <?php
        $__ck = (string)($article['cluster_key'] ?? '');
        $__cnt = 0;
        if ($__ck !== '' && $__ck !== '-' && preg_match('/^[a-f0-9]{40}$/', $__ck)) {
            try {
                require_once __DIR__ . '/includes/article_cluster.php';
                $__cm = cluster_counts_for([$__ck]);
                $__cnt = (int)($__cm[$__ck] ?? 0);
            } catch (Throwable $e) { $__cnt = 0; }
        }
        if ($__cnt >= 2): ?>
            <a class="compare-coverage-cta" href="/cluster/<?php echo e($__ck); ?>">
                <span class="cc-icon">📰</span>
                <span class="cc-text">
                    <strong>قارن التغطية</strong>
                    <em>هذا الخبر نُشر في <?php echo (int)$__cnt; ?> مصادر مختلفة — اطّلع على كل التغطيات جنباً إلى جنب</em>
                </span>
                <span class="cc-arrow">›</span>
            </a>
        <?php endif; ?>

        <!-- Article Content -->
        <?php
        // تنظيف المحتوى - السماح فقط بوسوم HTML آمنة + تعقيم href/src
        $allowedTags = '<p><br><strong><b><em><i><ul><ol><li><h2><h3><h4><blockquote><a><img>';
        $cleanContent = strip_tags($article['content'], $allowedTags);
        $cleanContent = preg_replace('#\s+on[a-z]+\s*=\s*"[^"]*"#i', '', $cleanContent);
        $cleanContent = preg_replace("#\s+on[a-z]+\s*=\s*'[^']*'#i", '', $cleanContent);
        $cleanContent = preg_replace_callback('#<a\s+([^>]*?)href\s*=\s*"([^"]*)"([^>]*)>#i', function($m){
            $u = trim($m[2]);
            if (!preg_match('#^(https?:|mailto:|/|\#)#i', $u)) $u = '#';
            return '<a ' . $m[1] . 'href="' . htmlspecialchars($u, ENT_QUOTES) . '" rel="noopener nofollow" target="_blank"' . $m[3] . '>';
        }, $cleanContent);
        $cleanContent = preg_replace_callback('#<img\s+([^>]*?)src\s*=\s*"([^"]*)"([^>]*)>#i', function($m){
            $u = trim($m[2]);
            if (!preg_match('#^(https?:|/)#i', $u)) return '';
            return '<img ' . $m[1] . 'src="' . htmlspecialchars($u, ENT_QUOTES) . '" loading="lazy" decoding="async"' . $m[3] . '>';
        }, $cleanContent);
        // Only show content block if there's actual text (skip if it's a duplicate of excerpt)
        $plainContent = trim(strip_tags($cleanContent));
        $plainExcerpt = trim($article['excerpt'] ?? '');
        $hasMeaningfulContent = ($plainContent !== '' && $plainContent !== $plainExcerpt);
        ?>
        <?php if ($hasMeaningfulContent): ?>
            <div class="article-content" id="articleContent">
                <?php echo $cleanContent; ?>
            </div>
        <?php endif; ?>

        <!-- Read Full Article from Source -->
        <?php if (!empty($readMoreUrl)): ?>
            <a class="source-cta" href="<?php echo e($readMoreUrl); ?>" target="_blank" rel="noopener noreferrer">
                <div class="source-cta-icon">📰</div>
                <div class="source-cta-text">
                    <div class="source-cta-label">المقال الكامل متوفر على موقع المصدر</div>
                    <div class="source-cta-title">اقرأ الخبر كاملاً من <?php echo e($article['source_name'] ?? 'المصدر'); ?></div>
                </div>
                <div class="source-cta-arrow">&larr;</div>
            </a>
        <?php endif; ?>

        <!-- Article Footer with Sharing -->
        <div class="article-footer">
            <h3 style="margin-bottom: 1rem;">شارك هذه المقالة</h3>
            <div class="sharing">
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(SITE_URL . '/article.php?id=' . $article['id']); ?>" class="share-btn" title="شارك على فيسبوك" target="_blank">f</a>
                <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode($article['title']); ?>&url=<?php echo urlencode(SITE_URL . '/article.php?id=' . $article['id']); ?>" class="share-btn" title="شارك على تويتر" target="_blank">𝕏</a>
                <a href="https://wa.me/?text=<?php echo urlencode($article['title'] . ' ' . SITE_URL . '/article.php?id=' . $article['id']); ?>" class="share-btn" title="شارك على واتس آب" target="_blank">W</a>
                <a href="mailto:?subject=<?php echo urlencode($article['title']); ?>&body=<?php echo urlencode($article['title'] . '\n' . SITE_URL . '/article.php?id=' . $article['id']); ?>" class="share-btn" title="أرسل بالبريد الإلكتروني">@</a>
            </div>
        </div>

        <!-- Comments -->
        <section class="comments-wrap">
          <h2>💬 التعليقات (<?php echo (int)$commentsCount; ?>)</h2>
          <?php if ($viewerId): ?>
            <form class="comment-form" data-article-id="<?php echo (int)$articleId; ?>" onsubmit="event.preventDefault(); NF.submitComment(this);">
              <textarea placeholder="شاركنا رأيك..." maxlength="2000" required></textarea>
              <div class="cf-bottom">
                <button type="submit" class="btn primary">إرسال</button>
              </div>
            </form>
          <?php else: ?>
            <div class="comment-login-cta">
              <a href="account/login.php?return=<?php echo urlencode('article/' . (int)$articleId); ?>">سجّل الدخول</a>
              للتعليق ومشاركة رأيك
            </div>
          <?php endif; ?>
          <div id="commentsList">
            <?php foreach ($comments as $c): ?>
              <div class="comment">
                <div class="avatar"><?php echo e($c['avatar_letter'] ?: mb_substr($c['user_name'] ?? '?', 0, 1)); ?></div>
                <div class="c-body">
                  <div>
                    <span class="c-name"><?php echo e($c['user_name'] ?? 'مجهول'); ?></span>
                    <span class="c-time"><?php echo timeAgo($c['created_at']); ?></span>
                  </div>
                  <div class="c-text"><?php echo e($c['body']); ?></div>
                  <div class="c-actions">
                    <button type="button" class="<?php echo !empty($c['viewer_liked']) ? 'liked' : ''; ?>" onclick="NF.likeComment(this, <?php echo (int)$c['id']; ?>)">
                      <?php echo !empty($c['viewer_liked']) ? '♥' : '♡'; ?> <?php echo (int)$c['likes']; ?>
                    </button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <!-- Related Articles -->
        <?php if (!empty($relatedArticles)): ?>
            <section class="related-articles">
                <h2>مقالات ذات صلة من <?php echo e($article['cat_name']); ?></h2>
                <div class="articles-grid">
                    <?php foreach ($relatedArticles as $related): ?>
                        <a href="article.php?id=<?php echo $related['id']; ?>" class="article-card">
                            <?php if ($related['image_url']): ?>
                                <img src="<?php echo e($related['image_url']); ?>" alt="<?php echo e($related['title']); ?>" class="article-card-image" loading="lazy" decoding="async">
                            <?php endif; ?>
                            <div class="article-card-body">
                                <h3 class="article-card-title"><?php echo e($related['title']); ?></h3>
                                <p class="article-card-meta">
                                    <?php echo timeAgo($related['published_at']); ?> • <?php echo e($related['source_name']); ?>
                                </p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2026 <?php echo SITE_NAME; ?> - <?php echo SITE_TAGLINE; ?></p>
            <p>جميع الحقوق محفوظة</p>
        </div>
    </footer>
    <div class="nf-toast" id="nfToast"></div>
    <script src="assets/js/user.min.js?v=m1"></script>
    <script>
    (function(){
        // ====== Reading progress bar ======
        const bar = document.getElementById('readProgressBar');
        if (bar) {
            const update = () => {
                const h = document.documentElement;
                const scrolled = h.scrollTop;
                const max = h.scrollHeight - h.clientHeight;
                bar.style.width = (max > 0 ? Math.min(100, (scrolled / max) * 100) : 0) + '%';
            };
            update();
            window.addEventListener('scroll', update, { passive: true });
            window.addEventListener('resize', update);
        }

        // ====== Font size controls ======
        const root = document.documentElement;
        const STORAGE_KEY = 'nf_article_font';
        const SIZES = [0.9, 1, 1.1, 1.2, 1.35, 1.5, 1.7];
        let fontIdx = parseInt(localStorage.getItem(STORAGE_KEY) || '2', 10);
        if (isNaN(fontIdx) || fontIdx < 0 || fontIdx >= SIZES.length) fontIdx = 2;
        const applyFont = () => {
            root.style.setProperty('--read-font', SIZES[fontIdx] + 'rem');
            localStorage.setItem(STORAGE_KEY, String(fontIdx));
        };
        applyFont();
        document.getElementById('fontInc')?.addEventListener('click', () => {
            if (fontIdx < SIZES.length - 1) { fontIdx++; applyFont(); }
        });
        document.getElementById('fontDec')?.addEventListener('click', () => {
            if (fontIdx > 0) { fontIdx--; applyFont(); }
        });
        document.getElementById('fontReset')?.addEventListener('click', () => {
            fontIdx = 2; applyFont();
        });

        // ====== Copy link ======
        document.getElementById('copyLinkBtn')?.addEventListener('click', async function(){
            try {
                await navigator.clipboard.writeText(window.location.href);
                if (window.NF && NF.toast) NF.toast('تم نسخ الرابط ✓'); else this.textContent = '✓';
            } catch(e) {
                const ta = document.createElement('textarea');
                ta.value = window.location.href;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch(_){}
                document.body.removeChild(ta);
                if (window.NF && NF.toast) NF.toast('تم نسخ الرابط ✓');
            }
        });

        // ====== Text-to-speech ======
        // Dual-path player:
        //   1. Cloud TTS  -> streams an MP3 from /api/tts.php?id=N.
        //      Used when the admin has enabled high-quality TTS in the
        //      panel. Offers a real <audio> element with seek, speed,
        //      and a progress bar.
        //   2. Web Speech -> window.speechSynthesis fallback. Used when
        //      /api/tts.php returns 404 (= TTS disabled server-side)
        //      or when the MP3 request fails. Keeps the feature working
        //      even without API keys configured.
        const ttsToggle    = document.getElementById('ttsToggle');
        const ttsPlayer    = document.getElementById('ttsPlayer');
        const ttsPlayBtn   = document.getElementById('ttsPlayBtn');
        const ttsStopBtn   = document.getElementById('ttsStopBtn');
        const ttsSpeed     = document.getElementById('ttsSpeed');
        const ttsStatus    = document.getElementById('ttsStatus');
        const ttsTime      = document.getElementById('ttsTime');
        const ttsProgress  = document.getElementById('ttsProgress');
        const ttsProgressBar = document.getElementById('ttsProgressBar');
        const ttsAudio     = document.getElementById('ttsAudio');
        const synth        = window.speechSynthesis;
        const articleId    = ttsPlayer ? parseInt(ttsPlayer.dataset.articleId || '0', 10) : 0;

        // 'cloud' | 'browser' | null (unknown until first click)
        let ttsMode = null;

        const fmtTime = (s) => {
            if (!isFinite(s) || s < 0) s = 0;
            const m = Math.floor(s / 60);
            const r = Math.floor(s % 60);
            return m + ':' + (r < 10 ? '0' : '') + r;
        };

        const setStatus = (txt) => { if (ttsStatus) ttsStatus.textContent = txt; };

        const getReadableText = () => {
            const parts = [];
            const title = document.getElementById('articleTitle');
            if (title) parts.push(title.textContent.trim());
            const excerpt = document.querySelector('.article-excerpt');
            if (excerpt) parts.push(excerpt.textContent.trim());
            const summary = document.getElementById('aiSummaryBody');
            if (summary) parts.push(summary.textContent.trim());
            const content = document.getElementById('articleContent');
            if (content) parts.push(content.textContent.trim().replace(/\s+/g, ' '));
            return parts.filter(Boolean).join('. ');
        };

        // ---- Cloud MP3 path ----------------------------------------
        const tryCloudStart = async () => {
            if (!articleId || !ttsAudio) return false;
            setStatus('جاري تحميل الصوت…');
            ttsAudio.src = '/api/tts.php?id=' + articleId;
            ttsAudio.playbackRate = parseFloat(ttsSpeed.value) || 1;
            try {
                await ttsAudio.play();
                ttsMode = 'cloud';
                ttsPlayBtn.textContent = '❚❚';
                setStatus('⏵ يقرأ الآن…');
                return true;
            } catch (err) {
                // Browser blocked autoplay (rare here, the click is user-initiated)
                // or the endpoint returned an error/404.
                return false;
            }
        };

        ttsAudio?.addEventListener('loadedmetadata', () => {
            if (ttsTime) ttsTime.textContent = fmtTime(0) + ' / ' + fmtTime(ttsAudio.duration);
        });
        ttsAudio?.addEventListener('timeupdate', () => {
            if (!ttsAudio.duration) return;
            const pct = (ttsAudio.currentTime / ttsAudio.duration) * 100;
            if (ttsProgressBar) ttsProgressBar.style.width = pct + '%';
            if (ttsTime) ttsTime.textContent = fmtTime(ttsAudio.currentTime) + ' / ' + fmtTime(ttsAudio.duration);
        });
        ttsAudio?.addEventListener('ended', () => {
            ttsPlayBtn.textContent = '▶';
            setStatus('✓ انتهت القراءة');
            if (ttsProgressBar) ttsProgressBar.style.width = '100%';
        });
        ttsAudio?.addEventListener('error', () => {
            // Cloud endpoint gave up — fall back to browser TTS on the
            // next click so the user is never stranded.
            ttsMode = null;
            setStatus('تعذّر تحميل الصوت السحابي — تم التحويل إلى المتصفح');
            ttsPlayBtn.textContent = '▶';
        });
        // Click the progress bar to seek (cloud path only — Web Speech
        // has no seek API).
        ttsProgress?.addEventListener('click', (e) => {
            if (ttsMode !== 'cloud' || !ttsAudio.duration) return;
            const rect = ttsProgress.getBoundingClientRect();
            // RTL layout: progress fills from right-to-left, so invert.
            const ratio = 1 - ((e.clientX - rect.left) / rect.width);
            ttsAudio.currentTime = Math.max(0, Math.min(ttsAudio.duration, ratio * ttsAudio.duration));
        });

        // ---- Browser Web Speech fallback ---------------------------
        let utter = null;
        let isPaused = false;

        const pickArabicVoice = () => {
            if (!synth) return null;
            const voices = synth.getVoices();
            return voices.find(v => /^ar/i.test(v.lang)) || voices.find(v => v.default) || voices[0];
        };

        const startBrowserSpeaking = () => {
            if (!synth) {
                setStatus('المتصفح لا يدعم القراءة الصوتية');
                return;
            }
            synth.cancel();
            const text = getReadableText();
            if (!text) { setStatus('لا يوجد نص للقراءة'); return; }
            utter = new SpeechSynthesisUtterance(text);
            utter.lang = 'ar-SA';
            utter.rate = parseFloat(ttsSpeed.value) || 1;
            const v = pickArabicVoice();
            if (v) utter.voice = v;
            utter.onstart = () => { setStatus('⏵ يقرأ الآن… (صوت المتصفح)'); ttsPlayBtn.textContent = '❚❚'; isPaused = false; };
            utter.onend   = () => { setStatus('✓ انتهت القراءة'); ttsPlayBtn.textContent = '▶'; isPaused = false; };
            utter.onerror = () => { setStatus('حدث خطأ أثناء القراءة'); ttsPlayBtn.textContent = '▶'; };
            synth.speak(utter);
            ttsMode = 'browser';
        };

        // ---- Unified play/pause/stop -------------------------------
        const startPlayback = async () => {
            // Try cloud first unless we've already fallen back this session.
            if (ttsMode !== 'browser') {
                const ok = await tryCloudStart();
                if (ok) return;
            }
            startBrowserSpeaking();
        };

        const stopPlayback = () => {
            if (ttsAudio) { try { ttsAudio.pause(); ttsAudio.currentTime = 0; } catch(_){} }
            if (synth)   { synth.cancel(); }
            if (ttsProgressBar) ttsProgressBar.style.width = '0%';
            ttsPlayBtn.textContent = '▶';
            isPaused = false;
            setStatus('جاهز للقراءة');
        };

        ttsToggle?.addEventListener('click', () => {
            const isOpen = ttsPlayer.classList.toggle('active');
            ttsToggle.classList.toggle('active', isOpen);
            ttsPlayer.setAttribute('aria-hidden', String(!isOpen));
            if (isOpen) {
                startPlayback();
            } else {
                stopPlayback();
            }
        });

        ttsPlayBtn?.addEventListener('click', () => {
            if (ttsMode === 'cloud') {
                if (ttsAudio.paused) {
                    ttsAudio.play().catch(()=>{});
                    ttsPlayBtn.textContent = '❚❚';
                    setStatus('⏵ يقرأ الآن…');
                } else {
                    ttsAudio.pause();
                    ttsPlayBtn.textContent = '▶';
                    setStatus('⏸ موقوف مؤقتاً');
                }
                return;
            }
            if (ttsMode === 'browser' && synth) {
                if (synth.speaking && !isPaused) {
                    synth.pause();
                    isPaused = true;
                    ttsPlayBtn.textContent = '▶';
                    setStatus('⏸ موقوف مؤقتاً');
                } else if (isPaused) {
                    synth.resume();
                    isPaused = false;
                    ttsPlayBtn.textContent = '❚❚';
                    setStatus('⏵ يقرأ الآن…');
                } else {
                    startBrowserSpeaking();
                }
                return;
            }
            // First click — nothing playing yet
            startPlayback();
        });

        ttsStopBtn?.addEventListener('click', stopPlayback);

        ttsSpeed?.addEventListener('change', () => {
            const rate = parseFloat(ttsSpeed.value) || 1;
            if (ttsMode === 'cloud' && ttsAudio) {
                ttsAudio.playbackRate = rate;
            } else if (ttsMode === 'browser' && synth && synth.speaking) {
                // Web Speech rate is set at speak() time — restart to apply.
                startBrowserSpeaking();
            }
        });

        // Stop everything when leaving the page so audio doesn't keep
        // playing after navigation.
        window.addEventListener('beforeunload', () => {
            try { if (ttsAudio) ttsAudio.pause(); } catch(_){}
            if (synth) synth.cancel();
        });
    })();
    </script>
    <?php if ($viewerId): ?>
    <script>
      // Log this read after 5 seconds (so page views count only as "read")
      setTimeout(function(){ NF.logRead(<?php echo (int)$articleId; ?>); }, 5000);
    </script>
    <?php endif; ?>
</body>
</html>
