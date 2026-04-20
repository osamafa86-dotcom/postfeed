<?php
/**
 * نيوز فيد — Main XML sitemap (articles + category hubs).
 *
 * News articles with a <48h publish window also appear in the
 * companion /sitemap-news.php — that one uses the
 * http://www.google.com/schemas/sitemap-news/0.9 schema and has
 * the <news:news> metadata Google News requires.
 *
 * Why we split them:
 *   - Google News has a strict 48-hour rolling window and a 1000-url
 *     cap; the main sitemap has no such constraints and is fine to
 *     ship 5000+ urls.
 *   - Mixing both schemas in one file is valid XML but harder to
 *     debug when Search Console flags issues.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=utf-8');
// 30-min cache — 5000-row query is cheap but no reason to re-run
// it on every crawler hit.
header('Cache-Control: public, max-age=1800');

$db = getDB();
$articles = $db->query("SELECT id, slug, published_at, updated_at, image_url, title
                        FROM articles
                        WHERE status = 'published'
                        ORDER BY published_at DESC LIMIT 5000")->fetchAll();

$categories = $db->query("SELECT slug FROM categories ORDER BY sort_order, id")->fetchAll();

$base = rtrim(SITE_URL, '/');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
  <url>
    <loc><?php echo $base; ?>/</loc>
    <changefreq>hourly</changefreq>
    <priority>1.0</priority>
  </url>
<?php foreach ($categories as $c): if (empty($c['slug'])) continue; ?>
  <url>
    <loc><?php echo htmlspecialchars($base . '/category/' . rawurlencode($c['slug'])); ?></loc>
    <changefreq>hourly</changefreq>
    <priority>0.7</priority>
  </url>
<?php endforeach; ?>
<?php foreach ($articles as $a):
    // Use the friendly URL the site actually serves; falls back to
    // the legacy ?id= form if articleUrl() can't construct a slug.
    $loc = $base . '/' . articleUrl($a);
    // lastmod prefers updated_at (auto-touches on any row change)
    // and falls back to published_at.
    $lastStamp = $a['updated_at'] ?? $a['published_at'];
    $lastmod   = !empty($lastStamp) ? date('c', strtotime($lastStamp)) : date('c');
?>
  <url>
    <loc><?php echo htmlspecialchars($loc); ?></loc>
    <lastmod><?php echo $lastmod; ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>0.8</priority>
    <?php if (!empty($a['image_url'])): ?>
    <image:image>
      <image:loc><?php echo htmlspecialchars($a['image_url']); ?></image:loc>
      <image:title><?php echo htmlspecialchars($a['title']); ?></image:title>
    </image:image>
    <?php endif; ?>
  </url>
<?php endforeach; ?>
</urlset>
