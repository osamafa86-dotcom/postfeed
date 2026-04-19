<?php
/**
 * نيوزفلو — Google News sitemap
 *
 * Separate from /sitemap.php because Google News requires:
 *   - A rolling 48-hour window (articles older than 2 days MUST be
 *     removed from this file — leaving them in produces a Search
 *     Console warning on every refresh).
 *   - A max of 1,000 URLs per file.
 *   - The <news:news> wrapper with publication.name, publication.language
 *     and publication_date (ISO-8601 with timezone).
 *
 * We also keep regular <loc>/<lastmod> nodes so the file doubles as
 * a standard sitemap for bots that don't speak the news extension.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=utf-8');
// 10-min cache — Google News crawls this file frequently during a
// news cycle; we don't want it to regenerate on every hit but we do
// want new articles to show up within a reasonable window.
header('Cache-Control: public, max-age=600');

$db = getDB();

// Articles published in the last 48 hours, newest first, capped at
// Google's 1000-item limit. Using a bound parameter keeps the
// prepared-statement plan cache warm.
$cutoff = date('Y-m-d H:i:s', time() - 48 * 3600);
$stmt = $db->prepare(
    "SELECT a.id, a.slug, a.title, a.image_url, a.published_at,
            c.name AS cat_name, s.name AS source_name
     FROM articles a
     LEFT JOIN categories c ON c.id = a.category_id
     LEFT JOIN sources    s ON s.id = a.source_id
     WHERE a.status = 'published'
       AND a.published_at >= ?
     ORDER BY a.published_at DESC
     LIMIT 1000"
);
$stmt->execute([$cutoff]);
$articles = $stmt->fetchAll();

$base = rtrim(SITE_URL, '/');
$pub  = getSetting('site_name', SITE_NAME);

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
<?php foreach ($articles as $a):
    $loc      = $base . '/' . articleUrl($a);
    $pubISO   = !empty($a['published_at']) ? date('c', strtotime($a['published_at'])) : date('c');
    $keywords = trim((string)($a['cat_name'] ?? ''));
?>
  <url>
    <loc><?php echo htmlspecialchars($loc); ?></loc>
    <news:news>
      <news:publication>
        <news:name><?php echo htmlspecialchars($pub); ?></news:name>
        <news:language>ar</news:language>
      </news:publication>
      <news:publication_date><?php echo $pubISO; ?></news:publication_date>
      <news:title><?php echo htmlspecialchars($a['title']); ?></news:title>
      <?php if ($keywords): ?>
      <news:keywords><?php echo htmlspecialchars($keywords); ?></news:keywords>
      <?php endif; ?>
    </news:news>
    <?php if (!empty($a['image_url'])): ?>
    <image:image>
      <image:loc><?php echo htmlspecialchars($a['image_url']); ?></image:loc>
      <image:title><?php echo htmlspecialchars($a['title']); ?></image:title>
    </image:image>
    <?php endif; ?>
  </url>
<?php endforeach; ?>
</urlset>
