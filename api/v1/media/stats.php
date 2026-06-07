<?php
/**
 * GET /api/v1/media/stats?platform=telegram|twitter|youtube&range=24h|7d|30d
 *
 * Lightweight analytics for the platforms section, computed live with
 * GROUP BY over the existing message tables (no extra storage):
 *   - total posts in range
 *   - active sources (distinct)
 *   - activity timeline (hourly for 24h, daily for 7d/30d)
 *   - top sources by volume
 *   - top topics (hashtags extracted from post text)
 *   - Palestinian-coverage share
 *   - peak bucket
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/ai_helper.php'; // nf_palestine_regex()

api_method('GET');
api_rate_limit('media:stats', 120, 60);

$platform = strtolower(trim((string)($_GET['platform'] ?? '')));
$range    = strtolower(trim((string)($_GET['range'] ?? '24h')));

if (!in_array($platform, ['telegram', 'twitter', 'youtube'], true)) {
    api_err('invalid_platform', 'يرجى تحديد المنصة: telegram أو twitter أو youtube', 400);
}
if (!in_array($range, ['24h', '7d', '30d'], true)) $range = '24h';

// Map platform -> table / columns. YouTube has no `text`, so we use the
// video title as its textual content for topic + Palestine analysis.
$map = [
    'telegram' => ['t' => 'telegram_messages', 's' => 'telegram_sources', 'txt' => 'm.text'],
    'twitter'  => ['t' => 'twitter_messages',  's' => 'twitter_sources',  'txt' => 'm.text'],
    'youtube'  => ['t' => 'youtube_videos',    's' => 'youtube_sources',  'txt' => 'm.title'],
][$platform];

// Range -> window minutes + bucket unit.
$cfg = [
    '24h' => ['mins' => 1440,  'unit' => 'HOUR', 'buckets' => 24],
    '7d'  => ['mins' => 10080, 'unit' => 'DAY',  'buckets' => 7],
    '30d' => ['mins' => 43200, 'unit' => 'DAY',  'buckets' => 30],
][$range];

$db = getDB();
$out = [
    'platform'       => $platform,
    'range'          => $range,
    'total'          => 0,
    'active_sources' => 0,
    'palestine_share'=> 0.0,
    'timeline'       => [],
    'top_sources'    => [],
    'top_topics'     => [],
    'peak'           => null,
];

try {
    $base = "FROM {$map['t']} m JOIN {$map['s']} s ON m.source_id = s.id
             WHERE m.is_active=1 AND s.is_active=1
               AND m.posted_at >= DATE_SUB(NOW(), INTERVAL :mins MINUTE)";

    // Totals.
    $stmt = $db->prepare("SELECT COUNT(*) AS total, COUNT(DISTINCT m.source_id) AS sources {$base}");
    $stmt->execute([':mins' => $cfg['mins']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $out['total']          = (int)($row['total'] ?? 0);
    $out['active_sources'] = (int)($row['sources'] ?? 0);

    // Timeline — bucket by "units ago" so it orders cleanly across midnight.
    $stmt = $db->prepare("SELECT TIMESTAMPDIFF({$cfg['unit']}, m.posted_at, NOW()) AS ago,
                                 COUNT(*) AS c
                          {$base}
                          GROUP BY ago");
    $stmt->execute([':mins' => $cfg['mins']]);
    $byAgo = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byAgo[(int)$r['ago']] = (int)$r['c'];
    }
    // Build oldest -> newest with human labels.
    $now = time();
    $timeline = [];
    $peakCount = -1;
    for ($i = $cfg['buckets'] - 1; $i >= 0; $i--) {
        $count = $byAgo[$i] ?? 0;
        if ($cfg['unit'] === 'HOUR') {
            $label = date('H:00', $now - $i * 3600);
        } else {
            $label = date('m-d', $now - $i * 86400);
        }
        $timeline[] = ['label' => $label, 'count' => $count];
        if ($count > $peakCount) { $peakCount = $count; $out['peak'] = $label; }
    }
    $out['timeline'] = $timeline;

    // Top sources by volume.
    $stmt = $db->prepare("SELECT s.display_name AS name, COUNT(*) AS c
                          {$base}
                          GROUP BY m.source_id, s.display_name
                          ORDER BY c DESC LIMIT 6");
    $stmt->execute([':mins' => $cfg['mins']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out['top_sources'][] = ['name' => (string)$r['name'], 'count' => (int)$r['c']];
    }

    // Palestine share — single REGEXP pass over the window.
    $paleKeywords = 'فلسطين|غزة|الضفة|القدس|الأقصى|الأسرى|أسير|معتقل|اعتقال|'
                  . 'مستوطن|الاستيطان|الاحتلال|إسرائيل|نتنياهو|رفح|خان يونس|جباليا|'
                  . 'نابلس|جنين|رام الله|الخليل|طولكرم|بيت لحم|حزب الله|الحوثي|'
                  . 'حماس|القسام|شهيد|شهداء|استشهاد|قصف|اقتحام|مجزرة|إبادة';
    $stmt = $db->prepare("SELECT COUNT(*) AS c {$base} AND {$map['txt']} REGEXP :re");
    $stmt->execute([':mins' => $cfg['mins'], ':re' => $paleKeywords]);
    $paleCount = (int)($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    $out['palestine_share'] = $out['total'] > 0
        ? round($paleCount / $out['total'], 3)
        : 0.0;

    // Top topics — extract hashtags from the most recent texts in PHP
    // (MySQL has no easy hashtag tokenizer). Cap the scan for speed.
    $stmt = $db->prepare("SELECT {$map['txt']} AS txt {$base}
                          ORDER BY m.posted_at DESC LIMIT 600");
    $stmt->execute([':mins' => $cfg['mins']]);
    $tags = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $txt) {
        if (!$txt) continue;
        if (preg_match_all('/#([\p{Arabic}\p{L}0-9_]{2,30})/u', (string)$txt, $mm)) {
            foreach ($mm[1] as $tag) {
                $tag = trim($tag);
                if ($tag === '') continue;
                $tags[$tag] = ($tags[$tag] ?? 0) + 1;
            }
        }
    }
    arsort($tags);
    foreach (array_slice($tags, 0, 10, true) as $tag => $c) {
        $out['top_topics'][] = ['tag' => $tag, 'count' => $c];
    }
} catch (Throwable $e) {
    error_log('media stats: ' . $e->getMessage());
}

api_ok($out);
