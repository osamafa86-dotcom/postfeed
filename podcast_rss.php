<?php
/**
 * /podcast.xml — iTunes-compatible RSS podcast feed.
 *
 * Any podcast app (Apple Podcasts, Spotify, Overcast, Pocket
 * Casts, Google Podcasts via the auto-discovery path) can
 * point at this URL and index all our episodes. Apple's
 * validator expects:
 *   - <itunes:image> at channel level (≥ 1400x1400)
 *   - <itunes:category>
 *   - <itunes:explicit>
 *   - <enclosure> with real file length + MIME
 *   - <itunes:duration> in HH:MM:SS
 *
 * All served from the DB — no build step, no cron involvement.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/podcast.php';
require_once __DIR__ . '/includes/cache.php';

header('Content-Type: application/rss+xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');  // 15 min

$siteName = getSetting('site_name', SITE_NAME);
$siteUrl  = rtrim(SITE_URL, '/');
$feedUrl  = $siteUrl . '/podcast.xml';
$pageUrl  = $siteUrl . '/podcast';
$imageUrl = $siteUrl . '/icon.php?size=1400';  // iTunes wants ≥1400px

$episodes = cache_remember('podcast_rss_v1', 600, function() {
    return pod_list_recent(50);
});

function pod_rss_fmt_duration(int $secs): string {
    $secs = max(0, $secs);
    $h = floor($secs / 3600);
    $m = floor(($secs % 3600) / 60);
    $s = $secs % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function pod_rss_xml_escape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function pod_rss_rfc822(string $iso): string {
    $ts = strtotime($iso);
    if (!$ts) $ts = time();
    return date('D, d M Y H:i:s O', $ts);
}

$latestTs = $episodes
    ? strtotime($episodes[0]['published_at'])
    : time();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"
     xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title><?php echo pod_rss_xml_escape($siteName . ' — البودكاست اليومي'); ?></title>
  <link><?php echo pod_rss_xml_escape($pageUrl); ?></link>
  <language>ar</language>
  <description>موجز إخباري يومي بالصوت من <?php echo pod_rss_xml_escape($siteName); ?>، يغطّي أهم أحداث آخر 24 ساعة في 5-8 دقائق.</description>
  <copyright>© <?php echo date('Y'); ?> <?php echo pod_rss_xml_escape($siteName); ?></copyright>
  <lastBuildDate><?php echo pod_rss_rfc822(date('c', $latestTs)); ?></lastBuildDate>
  <atom:link href="<?php echo pod_rss_xml_escape($feedUrl); ?>" rel="self" type="application/rss+xml"/>
  <itunes:author><?php echo pod_rss_xml_escape($siteName); ?></itunes:author>
  <itunes:summary>موجز إخباري يومي بالصوت يغطّي أبرز أحداث العالم العربي والعالم في 5-8 دقائق.</itunes:summary>
  <itunes:owner>
    <itunes:name><?php echo pod_rss_xml_escape($siteName); ?></itunes:name>
    <itunes:email><?php echo pod_rss_xml_escape(getSetting('contact_email', 'contact@' . ($_SERVER['HTTP_HOST'] ?? 'example.com'))); ?></itunes:email>
  </itunes:owner>
  <itunes:image href="<?php echo pod_rss_xml_escape($imageUrl); ?>"/>
  <itunes:category text="News">
    <itunes:category text="Daily News"/>
  </itunes:category>
  <itunes:explicit>false</itunes:explicit>
  <itunes:type>episodic</itunes:type>
  <image>
    <url><?php echo pod_rss_xml_escape($imageUrl); ?></url>
    <title><?php echo pod_rss_xml_escape($siteName); ?></title>
    <link><?php echo pod_rss_xml_escape($pageUrl); ?></link>
  </image>

<?php foreach ($episodes as $ep): ?>
<?php
    $epUrl   = $siteUrl . '/podcast/' . $ep['episode_date'];
    $audio   = $siteUrl . '/' . ltrim($ep['audio_path'], '/');
    $summary = $ep['subtitle'] ?: mb_substr($ep['intro'], 0, 300);
    $descTxt = $ep['intro']
            . ($ep['subtitle'] ? "\n\nالعناوين: " . $ep['subtitle'] : '');
    $guid    = 'tag:' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . ',' . $ep['episode_date'] . ':podcast';
?>
  <item>
    <title><?php echo pod_rss_xml_escape($ep['title']); ?></title>
    <link><?php echo pod_rss_xml_escape($epUrl); ?></link>
    <guid isPermaLink="false"><?php echo pod_rss_xml_escape($guid); ?></guid>
    <pubDate><?php echo pod_rss_rfc822($ep['published_at']); ?></pubDate>
    <description><?php echo pod_rss_xml_escape($descTxt); ?></description>
    <content:encoded><![CDATA[<p><?php echo nl2br(htmlspecialchars($descTxt, ENT_QUOTES, 'UTF-8')); ?></p>]]></content:encoded>
    <enclosure url="<?php echo pod_rss_xml_escape($audio); ?>"
               length="<?php echo (int)$ep['audio_bytes']; ?>"
               type="audio/mpeg"/>
    <itunes:author><?php echo pod_rss_xml_escape($siteName); ?></itunes:author>
    <itunes:summary><?php echo pod_rss_xml_escape($summary); ?></itunes:summary>
    <itunes:duration><?php echo pod_rss_fmt_duration((int)$ep['duration_seconds']); ?></itunes:duration>
    <itunes:explicit>false</itunes:explicit>
    <itunes:episodeType>full</itunes:episodeType>
    <itunes:image href="<?php echo pod_rss_xml_escape($imageUrl); ?>"/>
  </item>
<?php endforeach; ?>

</channel>
</rss>
