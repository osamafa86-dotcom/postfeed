<?php
/**
 * نيوزفلو - صفحة المقالة الواحدة
 * =============================
 * عرض مقالة واحدة برابط: article.php?id=N
 */

session_start();
require_once __DIR__ . '/includes/functions.php';

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
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo e($article['title']); ?> - <?php echo SITE_NAME; ?></title>
    <meta name="description" content="<?php echo e(substr($article['excerpt'], 0, 160)); ?>">
    <meta name="keywords" content="<?php echo e($article['cat_name']); ?>, أخبار">

    <!-- OG Tags -->
    <meta property="og:title" content="<?php echo e($article['title']); ?>">
    <meta property="og:description" content="<?php echo e($article['excerpt']); ?>">
    <meta property="og:image" content="<?php echo e($article['image_url']); ?>">
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?php echo SITE_URL; ?>/article.php?id=<?php echo $article['id']; ?>">

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/article.css">
    <style>
        :root {
            --primary: #2563eb;
            --dark: #1e293b;
            --light: #f1f5f9;
            --gray: #64748b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--light);
            color: var(--dark);
            line-height: 1.6;
        }

        .header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
            text-decoration: none;
        }

        .back-btn {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: background 0.3s;
        }

        .back-btn:hover {
            background: var(--light);
        }

        .article-hero {
            width: 100%;
            height: 400px;
            object-fit: cover;
            background: var(--gray);
            border-radius: 12px;
            margin: 2rem 0;
        }

        .article-header {
            margin: 2rem 0;
        }

        .article-meta {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            font-size: 0.9rem;
            color: var(--gray);
        }

        .category-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
        }

        .category-badge.سياسة { background: #ef4444; }
        .category-badge.رياضة { background: #f59e0b; }
        .category-badge.تكنولوجيا { background: #3b82f6; }
        .category-badge.ترفيه { background: #8b5cf6; }
        .category-badge.صحة { background: #10b981; }

        h1 {
            font-size: 2.5rem;
            line-height: 1.2;
            margin: 1rem 0;
            color: var(--dark);
        }

        .article-excerpt {
            font-size: 1.2rem;
            color: var(--gray);
            margin: 1rem 0;
            font-weight: 500;
        }

        .source-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            margin: 1.5rem 0;
        }

        .source-logo {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 1.5rem;
        }

        .source-details h3 {
            font-size: 0.95rem;
            margin-bottom: 0.3rem;
        }

        .source-time {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .article-content {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin: 2rem 0;
            line-height: 1.8;
            font-size: 1.1rem;
        }

        .article-content p {
            margin: 1.5rem 0;
        }

        .article-content h2 {
            font-size: 1.8rem;
            margin: 2rem 0 1rem;
            color: var(--dark);
        }

        .article-content h3 {
            font-size: 1.3rem;
            margin: 1.5rem 0 0.8rem;
            color: var(--dark);
        }

        .article-content strong {
            color: var(--dark);
        }

        .article-content em {
            color: var(--gray);
            font-style: italic;
        }

        .article-content a {
            color: var(--primary);
            text-decoration: none;
            border-bottom: 1px solid var(--primary);
        }

        .article-content a:hover {
            background: #dbeafe;
            padding: 0 2px;
        }

        .article-footer {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin: 2rem 0;
            border-top: 2px solid var(--light);
        }

        .sharing {
            display: flex;
            gap: 1rem;
            margin: 1.5rem 0;
        }

        .share-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--light);
            color: var(--primary);
            text-decoration: none;
            transition: all 0.3s;
            font-size: 1.1rem;
        }

        .share-btn:hover {
            background: var(--primary);
            color: white;
            transform: scale(1.1);
        }

        .related-articles {
            margin: 3rem 0;
        }

        .related-articles h2 {
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            color: var(--dark);
        }

        .articles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .article-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
        }

        .article-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .article-card-image {
            width: 100%;
            height: 150px;
            object-fit: cover;
            background: var(--gray);
        }

        .article-card-body {
            padding: 1rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .article-card-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
            line-height: 1.4;
        }

        .article-card-meta {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .footer {
            background: var(--dark);
            color: white;
            padding: 2rem 0;
            margin-top: 3rem;
            text-align: center;
        }

        .footer p {
            margin: 0.5rem 0;
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 1.8rem;
            }

            .article-content {
                padding: 1rem;
                font-size: 1rem;
            }

            .article-hero {
                height: 250px;
            }

            .articles-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="logo"><?php echo SITE_NAME; ?></a>
                <a href="index.php" class="back-btn">← العودة للرئيسية</a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <!-- Article Hero Image -->
        <?php if ($article['image_url']): ?>
            <img src="<?php echo e($article['image_url']); ?>" alt="<?php echo e($article['title']); ?>" class="article-hero">
        <?php endif; ?>

        <!-- Article Header -->
        <article class="article-header">
            <div class="article-meta">
                <?php if ($article['cat_name']): ?>
                    <span class="category-badge <?php echo $article['cat_name']; ?>">
                        <?php echo e($article['cat_name']); ?>
                    </span>
                <?php endif; ?>
                <?php if ($article['is_breaking']): ?>
                    <span class="category-badge" style="background: #dc2626;">عاجل</span>
                <?php endif; ?>
                <span>📅 <?php echo date('d/m/Y H:i', strtotime($article['published_at'])); ?></span>
                <span>👁️ <?php echo formatViews($article['view_count']); ?> مشاهدة</span>
            </div>

            <h1><?php echo e($article['title']); ?></h1>

            <p class="article-excerpt"><?php echo e($article['excerpt']); ?></p>

            <!-- Source Info -->
            <?php if ($article['source_name']): ?>
                <div class="source-info">
                    <div class="source-logo" style="background: <?php echo e($article['logo_bg'] ?: '#3b82f6'); ?>; color: <?php echo e($article['logo_color'] ?: 'white'); ?>;">
                        <?php echo e(strtoupper(substr($article['source_name'], 0, 1))); ?>
                    </div>
                    <div class="source-details">
                        <h3><?php echo e($article['source_name']); ?></h3>
                        <p class="source-time"><?php echo timeAgo($article['published_at']); ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </article>

        <!-- Article Content -->
        <div class="article-content">
            <?php
            // تنظيف المحتوى - السماح فقط بوسوم HTML آمنة
            $allowedTags = '<p><br><strong><b><em><i><ul><ol><li><h2><h3><h4><blockquote><a><img>';
            echo strip_tags($article['content'], $allowedTags);
            ?>
        </div>

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

        <!-- Related Articles -->
        <?php if (!empty($relatedArticles)): ?>
            <section class="related-articles">
                <h2>مقالات ذات صلة من <?php echo e($article['cat_name']); ?></h2>
                <div class="articles-grid">
                    <?php foreach ($relatedArticles as $related): ?>
                        <a href="article.php?id=<?php echo $related['id']; ?>" class="article-card">
                            <?php if ($related['image_url']): ?>
                                <img src="<?php echo e($related['image_url']); ?>" alt="<?php echo e($related['title']); ?>" class="article-card-image">
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
</body>
</html>
