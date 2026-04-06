<?php
/**
 * نيوزفلو - سحب أخبار RSS تلقائياً
 * يتم تشغيله كل 5 دقائق عبر Cron Job
 */

require_once __DIR__ . '/includes/config.php';

$db = getDB();

// جلب المصادر النشطة اللي عندها رابط RSS
$sources = $db->query("SELECT * FROM sources WHERE is_active = 1 AND rss_url IS NOT NULL AND rss_url != ''")->fetchAll();

if (empty($sources)) {
    echo "لا توجد مصادر RSS نشطة\n";
    exit;
}

$totalNew = 0;

foreach ($sources as $source) {
    echo "جاري سحب: {$source['name']} ({$source['rss_url']})\n";

    try {
        $rssContent = @file_get_contents($source['rss_url']);
        if ($rssContent === false) {
            // محاولة ثانية مع curl
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $source['rss_url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NewsFlow/1.0)',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $rssContent = curl_exec($ch);
            curl_close($ch);
        }

        if (empty($rssContent)) {
            echo "  ✗ فشل تحميل RSS\n";
            continue;
        }

        // تحليل XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($rssContent);
        if ($xml === false) {
            echo "  ✗ فشل تحليل XML\n";
            continue;
        }

        // دعم RSS 2.0 و Atom
        $items = [];
        if (isset($xml->channel->item)) {
            // RSS 2.0
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
            // Atom
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

        $newCount = 0;
        foreach ($items as $item) {
            if (empty($item['title'])) continue;

            // تحقق إن الخبر مش موجود مسبقاً (بالعنوان والمصدر)
            $check = $db->prepare("SELECT COUNT(*) FROM articles WHERE title = ? AND source_id = ?");
            $check->execute([trim($item['title']), $source['id']]);
            if ($check->fetchColumn() > 0) continue;

            // تنظيف النص
            $title = trim(strip_tags($item['title']));
            $excerpt = trim(strip_tags($item['description']));
            $excerpt = mb_substr($excerpt, 0, 500);
            $imageUrl = $item['image'];

            // توليد slug
            $slug = preg_replace('/[^a-zA-Z0-9\x{0600}-\x{06FF}\s-]/u', '', $title);
            $slug = preg_replace('/[\s]+/', '-', trim($slug));
            $slug = mb_substr($slug, 0, 200) . '-' . time() . rand(100, 999);

            // تحديد التصنيف من محتوى الخبر
            $categoryId = detectCategory($db, $title . ' ' . $excerpt, $item['category']);

            // تاريخ النشر
            $publishedAt = null;
            if (!empty($item['pubDate'])) {
                $timestamp = strtotime($item['pubDate']);
                if ($timestamp) {
                    $publishedAt = date('Y-m-d H:i:s', $timestamp);
                }
            }
            if (!$publishedAt) {
                $publishedAt = date('Y-m-d H:i:s');
            }

            // إدخال الخبر
            $stmt = $db->prepare("
                INSERT INTO articles (title, slug, excerpt, content, image_url, category_id, source_id, status, published_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'published', ?, NOW())
            ");
            $stmt->execute([
                $title,
                $slug,
                $excerpt,
                '<p>' . nl2br(e($excerpt)) . '</p>',
                $imageUrl,
                $categoryId,
                $source['id'],
                $publishedAt,
            ]);
            $newCount++;
        }

        $totalNew += $newCount;
        echo "  ✓ تم إضافة {$newCount} خبر جديد\n";

    } catch (Exception $ex) {
        echo "  ✗ خطأ: {$ex->getMessage()}\n";
    }
}

echo "\nالمجموع: {$totalNew} خبر جديد\n";

// ============================================
// دوال مساعدة
// ============================================

/**
 * استخراج صورة من عنصر RSS
 */
function extractImage($item) {
    // media:content
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

    // enclosure
    if (isset($item->enclosure)) {
        $type = (string) $item->enclosure['type'];
        if (strpos($type, 'image') !== false) {
            return (string) $item->enclosure['url'];
        }
    }

    // استخراج من الوصف
    $desc = (string) $item->description;
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $desc, $matches)) {
        return $matches[1];
    }

    return '';
}

/**
 * تحديد التصنيف تلقائياً من المحتوى
 */
function detectCategory($db, $text, $rssCategory = '') {
    $keywords = [
        'political' => ['سياس', 'رئيس', 'وزير', 'حكوم', 'برلمان', 'انتخاب', 'حزب', 'دبلوماس', 'سفير', 'قمة', 'مجلس', 'أمم متحدة', 'حرب', 'عسكر', 'جيش', 'صراع', 'احتلال', 'مقاوم', 'فلسطين', 'غزة'],
        'economy'   => ['اقتصاد', 'مال', 'بورصة', 'سوق', 'تجار', 'استثمار', 'بنك', 'دولار', 'نفط', 'أسعار', 'تضخم', 'ناتج', 'ميزانية', 'ضريب'],
        'sports'    => ['رياض', 'كرة', 'ملعب', 'دوري', 'بطولة', 'منتخب', 'لاعب', 'مباراة', 'هدف', 'تدريب', 'فيفا', 'أولمب'],
        'arts'      => ['فن', 'ثقاف', 'سينما', 'فيلم', 'مسلسل', 'موسيق', 'معرض', 'كتاب', 'رواية', 'مسرح', 'غناء', 'ألبوم'],
        'media'     => ['إعلام', 'صحاف', 'تلفزيون', 'قناة', 'بث', 'بودكاست', 'يوتيوب', 'سوشيال'],
        'reports'   => ['تقرير', 'دراسة', 'إحصا', 'بحث', 'تحليل', 'مؤشر', 'استطلاع'],
        'tech'      => ['تكنولوج', 'تقن', 'ذكاء اصطناع', 'هاتف', 'تطبيق', 'إنترنت', 'برمج', 'آبل', 'جوجل', 'سامسونج'],
        'health'    => ['صح', 'طب', 'مرض', 'علاج', 'مستشفى', 'وباء', 'لقاح', 'دواء', 'جراح'],
    ];

    $bestMatch = null;
    $bestScore = 0;

    foreach ($keywords as $slug => $words) {
        $score = 0;
        foreach ($words as $word) {
            if (mb_strpos($text, $word) !== false) {
                $score++;
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $slug;
        }
    }

    // إذا ما لقينا تطابق، نحط سياسة كافتراضي
    if ($bestScore === 0) {
        $bestMatch = 'political';
    }

    // جلب ID التصنيف
    $stmt = $db->prepare("SELECT id FROM categories WHERE slug = ?");
    $stmt->execute([$bestMatch]);
    $catId = $stmt->fetchColumn();

    return $catId ?: 1;
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
