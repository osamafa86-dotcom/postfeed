<?php
/**
 * نيوزفلو - خريطة الموقع XML للسيو
 */
require_once __DIR__ . '/includes/config.php';
header('Content-Type: application/xml; charset=utf-8');

$db = getDB();
$articles = $db->query("SELECT id, published_at, image_url, title
                        FROM articles
                        WHERE status = 'published'
                        ORDER BY published_at DESC LIMIT 5000")->fetchAll();

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
<?php foreach ($articles as $a):
    $loc = SITE_URL . '/article.php?id=' . (int)$a['id'];
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
