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
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a73e8;
            --primary-light: #eff6ff;
            --dark: #1a1a2e;
            --light: #faf6ec;
            --gray: #6b7280;
            --border: #e0e3e8;
            --card: #ffffff;
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

        @media (max-width: 768px) {
            h1 { font-size: 1.6rem; }
            .article-content { padding: 1.25rem; font-size: 1rem; }
            .article-hero { height: 250px; border-radius: 12px; }
            .articles-grid { grid-template-columns: 1fr; }
            .article-footer { padding: 1.5rem; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/user.css?v=2">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <a href="/" class="logo">نيوز<span>فلو</span></a>
            <a href="/" class="back-btn">&larr; العودة للرئيسية</a>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <!-- Article Hero Image -->
        <?php if ($article['image_url']): ?>
            <img src="<?php echo e($article['image_url']); ?>" alt="<?php echo e($article['title']); ?>" class="article-hero" decoding="async" fetchpriority="high">
        <?php endif; ?>

        <!-- Article Header -->
        <article class="article-header">
            <div class="article-meta">
                <?php if ($article['cat_name']): ?>
                    <span class="category-badge <?php echo e($article['css_class'] ?? ''); ?>">
                        <?php echo e($article['cat_name']); ?>
                    </span>
                <?php endif; ?>
                <?php if ($article['is_breaking']): ?>
                    <span class="category-badge" style="background: #dc2626;">عاجل</span>
                <?php endif; ?>
                <span>📅 <?php echo date('d/m/Y H:i', strtotime($article['published_at'])); ?></span>
                <span>👁️ <?php echo formatViews($article['view_count']); ?> مشاهدة</span>
                <span>💬 <?php echo (int)$commentsCount; ?> تعليق</span>
                <button type="button" class="article-save-btn <?php echo $isSaved ? 'saved' : ''; ?>" data-save-id="<?php echo (int)$articleId; ?>" onclick="NF.toggleSave(this)" style="margin-inline-start:auto;background:none;border:1px solid var(--border,#e0e3e8);border-radius:999px;padding:6px 14px;font-family:inherit;font-size:13px;cursor:pointer;color:inherit;display:inline-flex;align-items:center;gap:6px;">
                  🔖 <span><?php echo $isSaved ? 'محفوظ' : 'حفظ'; ?></span>
                </button>
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
            // تنظيف المحتوى - السماح فقط بوسوم HTML آمنة + تعقيم href/src
            $allowedTags = '<p><br><strong><b><em><i><ul><ol><li><h2><h3><h4><blockquote><a><img>';
            $cleanContent = strip_tags($article['content'], $allowedTags);
            // إزالة جميع event handlers (onclick, onerror, ...)
            $cleanContent = preg_replace('#\s+on[a-z]+\s*=\s*"[^"]*"#i', '', $cleanContent);
            $cleanContent = preg_replace("#\s+on[a-z]+\s*=\s*'[^']*'#i", '', $cleanContent);
            // تعقيم روابط href: السماح فقط بـ http(s) و mailto
            $cleanContent = preg_replace_callback('#<a\s+([^>]*?)href\s*=\s*"([^"]*)"([^>]*)>#i', function($m){
                $u = trim($m[2]);
                if (!preg_match('#^(https?:|mailto:|/|\#)#i', $u)) $u = '#';
                return '<a ' . $m[1] . 'href="' . htmlspecialchars($u, ENT_QUOTES) . '" rel="noopener nofollow" target="_blank"' . $m[3] . '>';
            }, $cleanContent);
            // تعقيم src للصور
            $cleanContent = preg_replace_callback('#<img\s+([^>]*?)src\s*=\s*"([^"]*)"([^>]*)>#i', function($m){
                $u = trim($m[2]);
                if (!preg_match('#^(https?:|/)#i', $u)) return '';
                return '<img ' . $m[1] . 'src="' . htmlspecialchars($u, ENT_QUOTES) . '" loading="lazy" decoding="async"' . $m[3] . '>';
            }, $cleanContent);
            echo $cleanContent;
            ?>
        </div>

        <?php if (!empty($article['ai_summary'])): ?>
        <div style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:1px solid #bae6fd;border-right:4px solid #0284c7;border-radius:14px;padding:20px;margin:24px 0;">
          <div style="display:flex;align-items:center;gap:8px;font-weight:800;color:#0284c7;margin-bottom:12px;font-size:15px;">
            🤖 ملخص بالذكاء الاصطناعي
          </div>
          <p style="font-size:15px;line-height:1.9;color:#1e293b;margin:0 0 14px;"><?php echo nl2br(e($article['ai_summary'])); ?></p>
          <?php
          $keyPoints = !empty($article['ai_key_points']) ? json_decode($article['ai_key_points'], true) : [];
          if (is_array($keyPoints) && !empty($keyPoints)): ?>
            <div style="font-weight:700;color:#0284c7;font-size:13px;margin-bottom:6px;">📌 النقاط الرئيسية:</div>
            <ul style="margin:0;padding-right:20px;font-size:13px;line-height:1.8;color:#334155;">
              <?php foreach ($keyPoints as $kp): ?><li><?php echo e($kp); ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Read Full Article from Source -->
        <?php
        $readMoreUrl = '';
        if (!empty($article['source_url'])) {
            $readMoreUrl = $article['source_url'];
        } elseif (!empty($article['source_website'])) {
            $readMoreUrl = $article['source_website'];
        }
        ?>
        <?php if (!empty($readMoreUrl)): ?>
            <div style="text-align:center; margin: 1.5rem 0;">
                <a href="<?php echo e($readMoreUrl); ?>" target="_blank" rel="noopener noreferrer"
                   style="display:inline-flex; align-items:center; gap:8px; padding:14px 32px; background:linear-gradient(135deg,#1a73e8,#4f46e5); color:#fff; border-radius:12px; font-size:15px; font-weight:700; text-decoration:none; transition:all .2s; box-shadow:0 4px 16px rgba(26,115,232,.3); font-family:inherit;"
                   onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 24px rgba(26,115,232,.4)'"
                   onmouseout="this.style.transform='';this.style.boxShadow='0 4px 16px rgba(26,115,232,.3)'">
                    اقرأ الخبر كاملاً من <?php echo e($article['source_name']); ?> &larr;
                </a>
            </div>
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
    <script src="assets/js/user.js?v=2"></script>
    <?php if ($viewerId): ?>
    <script>
      // Log this read after 5 seconds (so page views count only as "read")
      setTimeout(function(){ NF.logRead(<?php echo (int)$articleId; ?>); }, 5000);
    </script>
    <?php endif; ?>
</body>
</html>
