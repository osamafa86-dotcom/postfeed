<?php
/**
 * Public RSS 2.0 feed for the homepage stream.
 *
 * Reachable at /rss.xml via the rewrite rule in .htaccess (and at
 * /rss.php directly as a fallback). Returns the most recent published
 * articles so other aggregators, readers, and syndication services
 * can pick the site up — turns NewsFlow from a pure RSS consumer
 * into an RSS source as well.
 *
 * Light caching: the same XML payload is reused for one minute so a
 * burst of polling readers can't hammer the database.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/cache.php';

const RSS_FEED_LIMIT = 30;
const RSS_FEED_CACHE_TTL = 60;

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=' . RSS_FEED_CACHE_TTL);

$siteUrl = rtrim((string)getSetting('site_url', defined('SITE_URL') ? SITE_URL : ''), '/');
if ($siteUrl === '') {
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $siteUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$siteName    = defined('SITE_NAME')    ? SITE_NAME    : 'News Feed';
$siteTagline = defined('SITE_TAGLINE') ? SITE_TAGLINE : '';

/** Build an absolute URL from a site-relative path. */
function rss_abs_url(string $siteUrl, string $path): string {
    if ($path === '') return $siteUrl;
    if (preg_match('#^https?://#i', $path)) return $path;
    return $siteUrl . '/' . ltrim($path, '/');
}

$xml = cache_remember('rss_feed_v1', RSS_FEED_CACHE_TTL, function() use ($siteUrl, $siteName, $siteTagline) {
    $db = getDB();
    $stmt = $db->prepare("SELECT a.id, a.title, a.slug, a.excerpt, a.image_url,
                                 a.published_at, a.ai_keywords,
                                 c.name AS cat_name, c.slug AS cat_slug,
                                 s.name AS source_name
                            FROM articles a
                            LEFT JOIN categories c ON a.category_id = c.id
                            LEFT JOIN sources    s ON a.source_id   = s.id
                           WHERE a.status = 'published'
                           ORDER BY a.published_at DESC, a.id DESC
                           LIMIT " . RSS_FEED_LIMIT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $feedUrl  = $siteUrl . '/rss.xml';
    $buildAt  = date(DATE_RSS);
    $latestAt = $rows ? date(DATE_RSS, strtotime($rows[0]['published_at'])) : $buildAt;

    $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $out .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n";
    $out .= "  <channel>\n";
    $out .= '    <title>' . htmlspecialchars($siteName, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</title>\n";
    $out .= '    <link>' . htmlspecialchars($siteUrl, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</link>\n";
    $out .= '    <description>' . htmlspecialchars($siteTagline, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</description>\n";
    $out .= "    <language>ar</language>\n";
    $out .= "    <lastBuildDate>{$latestAt}</lastBuildDate>\n";
    $out .= "    <pubDate>{$buildAt}</pubDate>\n";
    $out .= '    <atom:link href="' . htmlspecialchars($feedUrl, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '" rel="self" type="application/rss+xml" />' . "\n";

    foreach ($rows as $a) {
        $url   = rss_abs_url($siteUrl, articleUrl($a));
        $pub   = date(DATE_RSS, strtotime($a['published_at']));
        $title = htmlspecialchars((string)$a['title'], ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $desc  = htmlspecialchars(strip_tags((string)$a['excerpt']), ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $img   = trim((string)$a['image_url']);
        $cat   = (string)($a['cat_name'] ?? '');
        $src   = (string)($a['source_name'] ?? '');

        $out .= "    <item>\n";
        $out .= "      <title>{$title}</title>\n";
        $out .= "      <link>" . htmlspecialchars($url, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</link>\n";
        $out .= "      <guid isPermaLink=\"true\">" . htmlspecialchars($url, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</guid>\n";
        $out .= "      <pubDate>{$pub}</pubDate>\n";
        if ($desc !== '') {
            $out .= "      <description>{$desc}</description>\n";
        }
        if ($cat !== '') {
            $out .= "      <category>" . htmlspecialchars($cat, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</category>\n";
        }
        if ($src !== '') {
            $out .= "      <dc:creator>" . htmlspecialchars($src, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</dc:creator>\n";
        }
        if ($img !== '') {
            $absImg = rss_abs_url($siteUrl, $img);
            // Use enclosure for the canonical image; readers like
            // Feedly, Inoreader and NetNewsWire will pick it up.
            $out .= '      <enclosure url="' . htmlspecialchars($absImg, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '" type="image/jpeg" length="0" />' . "\n";
        }
        $out .= "    </item>\n";
    }

    $out .= "  </channel>\n";
    $out .= "</rss>\n";
    return $out;
});

echo $xml;
