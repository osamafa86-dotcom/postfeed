<?php
/**
 * نيوزفلو - سحب أخبار RSS تلقائياً (متوازي)
 * يتم تشغيله عبر Cron Job
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/article_fetch.php';
require_once __DIR__ . '/includes/cache.php';

$db = getDB();
$startTime = microtime(true);

// Auto-migrate tracking columns
try {
    $cols = $db->query("SHOW COLUMNS FROM sources LIKE 'last_fetched_at'")->fetch();
    if (!$cols) {
        $db->exec("ALTER TABLE sources
            ADD COLUMN last_fetched_at TIMESTAMP NULL DEFAULT NULL,
            ADD COLUMN last_error VARCHAR(500) DEFAULT NULL,
            ADD COLUMN last_new_count INT DEFAULT 0,
            ADD COLUMN total_articles INT DEFAULT 0");
    }
} catch (Exception $e) {}

$sources = $db->query("SELECT * FROM sources WHERE is_active = 1 AND rss_url IS NOT NULL AND rss_url != ''")->fetchAll();

if (empty($sources)) {
    echo "لا توجد مصادر RSS نشطة\n";
    exit;
}

echo "بدء السحب المتوازي لـ " . count($sources) . " مصدر...\n";

// ============ FETCH IN PARALLEL ============
$multi = curl_multi_init();
$handles = [];
foreach ($sources as $i => $src) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $src['rss_url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NewsFlow/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[$i] = $ch;
}

$active = null;
do {
    $status = curl_multi_exec($multi, $active);
    if ($active) curl_multi_select($multi, 1.0);
} while ($active && $status === CURLM_OK);

$results = [];
foreach ($handles as $i => $ch) {
    $results[$i] = [
        'body' => curl_multi_getcontent($ch),
        'http' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'err'  => curl_error($ch),
    ];
    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}
curl_multi_close($multi);

// ============ PARSE FEEDS → COLLECT NEW ITEMS ============
$totalNew = 0;
$totalErr = 0;
$pendingInserts = []; // items to insert after parallel page fetch
$sourceErrors  = []; // source_id => error string
$sourceCounts  = []; // source_id => new count

foreach ($sources as $i => $source) {
    $r = $results[$i];
    $sourceCounts[$source['id']] = 0;

    try {
        if ($r['http'] >= 400 || empty($r['body'])) {
            throw new Exception("HTTP {$r['http']} " . ($r['err'] ?: 'empty body'));
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($r['body']);
        if ($xml === false) throw new Exception('XML parse failed');

        $items = [];
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $items[] = [
                    'title' => (string) $item->title,
                    'link' => (string) $item->link,
                    'description' => (string) $item->description,
                    'pubDate' => (string) $item->pubDate,
                    'image' => extractImage($item),
                    'category' => (string) ($item->category ?? ''),
                ];
            }
        } elseif (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $link = '';
                if (isset($entry->link)) {
                    foreach ($entry->link as $l) {
                        if ((string) $l['rel'] === 'alternate' || empty($link)) {
                            $link = (string) $l['href'];
                        }
                    }
                }
                $items[] = [
                    'title' => (string) $entry->title,
                    'link' => $link,
                    'description' => (string) ($entry->summary ?? $entry->content ?? ''),
                    'pubDate' => (string) ($entry->published ?? $entry->updated ?? ''),
                    'image' => '',
                    'category' => '',
                ];
            }
        }

        foreach ($items as $item) {
            if (empty($item['title'])) continue;
            $check = $db->prepare("SELECT COUNT(*) FROM articles WHERE title = ? AND source_id = ?");
            $check->execute([trim($item['title']), $source['id']]);
            if ($check->fetchColumn() > 0) continue;

            $title = trim(strip_tags($item['title']));
            $excerpt = mb_substr(trim(strip_tags($item['description'])), 0, 500);
            $imageUrl = $item['image'];
            $slug = preg_replace('/[^a-zA-Z0-9\x{0600}-\x{06FF}\s-]/u', '', $title);
            $slug = preg_replace('/[\s]+/', '-', trim($slug));
            $slug = mb_substr($slug, 0, 200) . '-' . time() . rand(100, 999);
            $categoryId = detectCategory($db, $title . ' ' . $excerpt, $item['category']);

            $publishedAt = null;
            if (!empty($item['pubDate'])) {
                $ts = strtotime($item['pubDate']);
                if ($ts) $publishedAt = date('Y-m-d H:i:s', $ts);
            }
            if (!$publishedAt) $publishedAt = date('Y-m-d H:i:s');

            $pendingInserts[] = [
                'source_id'   => $source['id'],
                'title'       => $title,
                'slug'        => $slug,
                'excerpt'     => $excerpt,
                'image_url'   => $imageUrl,
                'source_url'  => trim($item['link']),
                'category_id' => $categoryId,
                'published_at'=> $publishedAt,
            ];
            $sourceCounts[$source['id']]++;
        }
    } catch (Exception $ex) {
        $sourceErrors[$source['id']] = mb_substr($ex->getMessage(), 0, 500);
        $totalErr++;
        echo "  ✗ {$source['name']}: {$sourceErrors[$source['id']]}\n";
    }
}

// ============ FETCH ARTICLE PAGES IN PARALLEL ============
$pageUrls = array_filter(array_column($pendingInserts, 'source_url'));
$pageHtmls = [];
if (!empty($pageUrls)) {
    echo "\nجلب " . count($pageUrls) . " صفحة مقال بالتوازي...\n";
    $pageStart = microtime(true);
    $pageHtmls = fetchUrlsHtmlMulti($pageUrls, 12);
    echo "تم الجلب في " . round(microtime(true) - $pageStart, 2) . "s\n";
}

// ============ INSERT ============
$insertStmt = $db->prepare("INSERT INTO articles (title, slug, excerpt, content, image_url, source_url, category_id, source_id, status, published_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'published', ?, NOW())");

foreach ($pendingInserts as $it) {
    $pageHtml = $pageHtmls[$it['source_url']] ?? '';
    $fullContent = '';
    $imageUrl = $it['image_url'];
    if (!empty($pageHtml)) {
        $fullContent = fetchArticleBodyFromHtml($pageHtml);
        if (empty($imageUrl)) {
            $imageUrl = extractArticleImage($pageHtml, $it['source_url']);
        }
    }
    if (empty($fullContent)) {
        $fullContent = '<p>' . nl2br($it['excerpt']) . '</p>';
    }

    try {
        $insertStmt->execute([
            $it['title'], $it['slug'], $it['excerpt'], $fullContent,
            $imageUrl, $it['source_url'], $it['category_id'],
            $it['source_id'], $it['published_at'],
        ]);
        $totalNew++;
    } catch (Exception $e) {
        error_log('insert fail: ' . $e->getMessage());
    }
}

// ============ UPDATE SOURCE TRACKING ============
foreach ($sources as $source) {
    $sid = $source['id'];
    $error = $sourceErrors[$sid] ?? null;
    $newCount = $sourceCounts[$sid] ?? 0;
    if (!isset($sourceErrors[$sid])) {
        echo "  ✓ {$source['name']}: {$newCount} جديد\n";
    }
    try {
        $totalArticles = $db->prepare("SELECT COUNT(*) FROM articles WHERE source_id = ?");
        $totalArticles->execute([$sid]);
        $tot = (int)$totalArticles->fetchColumn();

        $db->prepare("UPDATE sources SET last_fetched_at = NOW(), last_error = ?, last_new_count = ?, total_articles = ? WHERE id = ?")
           ->execute([$error, $newCount, $tot, $sid]);
    } catch (Exception $e) {}
}

// Flush homepage cache so new articles appear immediately
if ($totalNew > 0) {
    cache_flush();
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "\nالمجموع: {$totalNew} خبر جديد | أخطاء: {$totalErr} | الوقت: {$elapsed}s\n";

// ============ AUTO-SUMMARIZE NEW ARTICLES ============
if ($totalNew > 0) {
    require_once __DIR__ . '/includes/functions.php';
    require_once __DIR__ . '/includes/ai_helper.php';
    $apiKey = env('ANTHROPIC_API_KEY', '') ?: getSetting('anthropic_api_key', '');
    if (!empty($apiKey)) {
        try {
            $cols = $db->query("SHOW COLUMNS FROM articles LIKE 'ai_summary'")->fetch();
            if (!$cols) {
                $db->exec("ALTER TABLE articles
                    ADD COLUMN ai_summary TEXT,
                    ADD COLUMN ai_key_points TEXT,
                    ADD COLUMN ai_keywords VARCHAR(500),
                    ADD COLUMN ai_processed_at TIMESTAMP NULL");
            }
        } catch (Exception $e) {}

        $aiLimit = (int) min($totalNew, 15);
        echo "\nبدء التلخيص لـ $aiLimit خبر...\n";
        $stmtP = $db->prepare("SELECT id, title, content FROM articles
                               WHERE ai_summary IS NULL AND status = 'published'
                               ORDER BY created_at DESC LIMIT ?");
        $stmtP->bindValue(1, $aiLimit, PDO::PARAM_INT);
        $stmtP->execute();
        $pending = $stmtP->fetchAll();
        $aiDone = 0; $aiFail = 0;
        foreach ($pending as $a) {
            $r = ai_summarize_article($a['title'], $a['content']);
            if ($r['ok']) { ai_save_summary($a['id'], $r); $aiDone++; }
            else { $aiFail++; }
            usleep(200000);
        }
        echo "التلخيص: $aiDone نجح | $aiFail فشل\n";
    }
}

// ============ HELPERS ============
function extractImage($item) {
    $namespaces = $item->getNamespaces(true);
    if (isset($namespaces['media'])) {
        $media = $item->children($namespaces['media']);
        if (isset($media->content)) {
            $url = (string) $media->content->attributes()['url'];
            if (!empty($url)) return $url;
        }
        if (isset($media->thumbnail)) {
            $url = (string) $media->thumbnail->attributes()['url'];
            if (!empty($url)) return $url;
        }
    }
    if (isset($item->enclosure)) {
        $type = (string) $item->enclosure['type'];
        if (strpos($type, 'image') !== false) {
            return (string) $item->enclosure['url'];
        }
    }
    $desc = (string) $item->description;
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $desc, $m)) return $m[1];
    return '';
}

function detectCategory($db, $text, $rssCategory = '') {
    $keywords = [
        'political' => ['سياس','رئيس','وزير','حكوم','برلمان','انتخاب','حزب','دبلوماس','سفير','قمة','مجلس','أمم متحدة','حرب','عسكر','جيش','صراع','احتلال','مقاوم','فلسطين','غزة'],
        'economy'   => ['اقتصاد','مال','بورصة','سوق','تجار','استثمار','بنك','دولار','نفط','أسعار','تضخم','ناتج','ميزانية','ضريب'],
        'sports'    => ['رياض','كرة','ملعب','دوري','بطولة','منتخب','لاعب','مباراة','هدف','تدريب','فيفا','أولمب'],
        'arts'      => ['فن','ثقاف','سينما','فيلم','مسلسل','موسيق','معرض','كتاب','رواية','مسرح','غناء','ألبوم'],
        'media'     => ['إعلام','صحاف','تلفزيون','قناة','بث','بودكاست','يوتيوب','سوشيال'],
        'reports'   => ['تقرير','دراسة','إحصا','بحث','تحليل','مؤشر','استطلاع'],
        'tech'      => ['تكنولوج','تقن','ذكاء اصطناع','هاتف','تطبيق','إنترنت','برمج','آبل','جوجل','سامسونج'],
        'health'    => ['صح','طب','مرض','علاج','مستشفى','وباء','لقاح','دواء','جراح'],
    ];
    $best = null; $score = 0;
    foreach ($keywords as $slug => $words) {
        $s = 0;
        foreach ($words as $w) if (mb_strpos($text, $w) !== false) $s++;
        if ($s > $score) { $score = $s; $best = $slug; }
    }
    if ($score === 0) $best = 'political';
    $stmt = $db->prepare("SELECT id FROM categories WHERE slug = ?");
    $stmt->execute([$best]);
    return $stmt->fetchColumn() ?: 1;
}
