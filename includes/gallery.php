<?php
/**
 * نيوزفلو — معرض "المشهد اليومي" (Picture of the Day)
 *
 * Guardian-inspired daily photo gallery. Picks the best images from
 * the last 24 hours of ingested articles, ranked by cluster source
 * diversity (bigger story = better photo context). Each image gets an
 * Arabic caption generated from its article title + AI summary.
 *
 * No Gemini Vision needed — captions come from the text metadata that
 * already exists. We optionally call Gemini text once to generate a
 * gallery headline + intro, but the photo selection is deterministic.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/ai_provider.php';

function gallery_ensure_table(): void {
    static $done = false;
    if ($done) return;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS daily_gallery (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gallery_date DATE NOT NULL,
            headline VARCHAR(300) NOT NULL DEFAULT '',
            intro TEXT,
            photos TEXT,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_date (gallery_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    } catch (Throwable $e) {
        error_log('[gallery] ensure_table: ' . $e->getMessage());
    }
}

/**
 * Pick the best images from the last 24 hours. Ranked by cluster
 * source count (bigger story = more prominent photo) then by recency.
 * Deduplicates by image URL hostname to avoid N copies of the same
 * agency photo.
 */
function gallery_pick_photos(int $maxPhotos = 12): array {
    try {
        $db = getDB();
        $sql = "SELECT a.id, a.title, a.slug, a.image_url, a.published_at,
                       a.ai_summary, a.excerpt, a.cluster_key,
                       s.name AS source_name, s.logo_color,
                       c.name AS cat_name,
                       (SELECT COUNT(DISTINCT a2.source_id)
                          FROM articles a2
                         WHERE a2.cluster_key = a.cluster_key
                           AND a.cluster_key IS NOT NULL AND a.cluster_key <> '-'
                           AND a2.status = 'published') AS cluster_size
                  FROM articles a
                  LEFT JOIN sources s ON a.source_id = s.id
                  LEFT JOIN categories c ON a.category_id = c.id
                 WHERE a.status = 'published'
                   AND a.image_url IS NOT NULL AND a.image_url <> ''
                   AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 ORDER BY cluster_size DESC, a.published_at DESC
                 LIMIT 80";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    // Dedup by image URL hostname — same agency photo often appears
    // across multiple articles from the same wire service.
    $seen = [];
    $out  = [];
    foreach ($rows as $r) {
        $url = (string)$r['image_url'];
        if ($url === '') continue;
        // Skip tiny placeholder images.
        if (preg_match('/\b(logo|icon|avatar|sprite|1x1|pixel)\b/i', $url)) continue;
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $imgKey = $host . ':' . basename(parse_url($url, PHP_URL_PATH) ?: '');
        if (isset($seen[$imgKey])) continue;
        $seen[$imgKey] = true;

        // Build a caption from the article metadata.
        $summary = trim(strip_tags((string)($r['ai_summary'] ?? $r['excerpt'] ?? '')));
        $caption = mb_substr($summary, 0, 200);
        if (mb_strlen($summary) > 200) $caption .= '…';

        $out[] = [
            'article_id' => (int)$r['id'],
            'title'      => $r['title'],
            'slug'       => $r['slug'],
            'image_url'  => $url,
            'caption'    => $caption,
            'source'     => $r['source_name'] ?? '',
            'logo_color' => $r['logo_color'] ?? '#0d9488',
            'category'   => $r['cat_name'] ?? '',
            'cluster_key'=> $r['cluster_key'] ?? '',
            'cluster_size' => (int)$r['cluster_size'],
            'published_at' => $r['published_at'],
        ];
        if (count($out) >= $maxPhotos) break;
    }
    return $out;
}

/**
 * Generate gallery headline + intro from the photo list via Gemini.
 * One call for the whole gallery, not per photo.
 */
function gallery_generate_intro(array $photos): array {
    if (count($photos) < 3) {
        return ['headline' => 'المشهد اليومي', 'intro' => ''];
    }

    $lines = [];
    foreach ($photos as $i => $p) {
        $lines[] = ($i + 1) . '. ' . $p['title'] . ' (' . $p['source'] . ')';
    }
    $list = implode("\n", $lines);

    $prompt = "أنت محرر صور في غرفة أخبار عربية. لديك هذه القائمة من " . count($photos) . " صورة إخبارية من اليوم. "
            . "اكتب عنواناً قصيراً جذاباً للمعرض (أقل من 60 حرفاً) ومقدمة من جملتين تصف المشهد البصري لليوم.\n\n"
            . "القائمة:\n" . $list;

    $tool = [
        'name'        => 'submit_gallery_intro',
        'description' => 'Submit headline and intro for the daily photo gallery.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'headline' => ['type' => 'string', 'description' => 'عنوان المعرض أقل من 60 حرفاً.'],
                'intro'    => ['type' => 'string', 'description' => 'مقدمة من جملتين.'],
            ],
            'required' => ['headline', 'intro'],
        ],
    ];

    $call = ai_provider_tool_call($prompt, $tool, 400);
    if (!empty($call['ok']) && is_array($call['input'])) {
        return [
            'headline' => trim((string)($call['input']['headline'] ?? 'المشهد اليومي')),
            'intro'    => trim((string)($call['input']['intro'] ?? '')),
        ];
    }
    return ['headline' => 'المشهد اليومي', 'intro' => ''];
}

/**
 * Build and save today's gallery.
 */
function gallery_build(string $date): ?array {
    gallery_ensure_table();
    $photos = gallery_pick_photos(12);
    if (count($photos) < 3) return null;

    $intro = gallery_generate_intro($photos);

    $row = [
        'gallery_date' => $date,
        'headline'     => $intro['headline'],
        'intro'        => $intro['intro'],
        'photos'       => $photos,
    ];

    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO daily_gallery (gallery_date, headline, intro, photos)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                headline = VALUES(headline), intro = VALUES(intro),
                photos = VALUES(photos), generated_at = NOW()"
        )->execute([
            $date,
            $row['headline'],
            $row['intro'],
            json_encode($row['photos'], JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        error_log('[gallery] save: ' . $e->getMessage());
    }

    return $row;
}

/**
 * Get gallery for a specific date.
 */
function gallery_get(string $date): ?array {
    gallery_ensure_table();
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM daily_gallery WHERE gallery_date = ?");
        $stmt->execute([$date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['photos'] = json_decode((string)$row['photos'], true) ?: [];
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function gallery_get_latest(): ?array {
    gallery_ensure_table();
    try {
        $db = getDB();
        $row = $db->query("SELECT * FROM daily_gallery ORDER BY gallery_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['photos'] = json_decode((string)$row['photos'], true) ?: [];
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function gallery_list(int $limit = 14): array {
    gallery_ensure_table();
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, gallery_date, headline FROM daily_gallery ORDER BY gallery_date DESC LIMIT ?");
        $stmt->bindValue(1, max(1, min(60, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
