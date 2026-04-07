<?php
/**
 * نيوزفلو - خريطة الموقع XML للسيو
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/xml; charset=utf-8');

$db = getDB();
$articles = $db->query("SELECT id, slug, published_at, image_url, title
                        FROM articles
                        WHERE status = 'published'
                        ORDER BY published_at DESC LIMIT 5000")->fetchAll();
$cats = $db->query("SELECT slug FROM categories WHERE is_active = 1")->fetchAll();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
  <url>
    <loc><?php echo SITE_URL; ?>/</loc>
    <changefreq>hourly</changefreq>
    <priority>1.0</priority>
  </url>
<?php foreach ($cats as $c): ?>
  <url>
    <loc><?php echo SITE_URL . '/' . htmlspecialchars(categoryUrl($c['slug'])); ?></loc>
    <changefreq>hourly</changefreq>
    <priority>0.7</priority>
  </url>
<?php endforeach; ?>
<?php foreach ($articles as $a):
    $loc = SITE_URL . '/' . articleUrl($a);
    $lastmod = !empty($a['published_at']) ? date('c', strtotime($a['published_at'])) : date('c');
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
