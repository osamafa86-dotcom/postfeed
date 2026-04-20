<?php
/**
 * Trending topics API — extracts hot keywords from recent articles.
 * Returns JSON {topics: [{keyword, count, icon}]}
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('editor');

header('Content-Type: application/json; charset=utf-8');

$db = getDB();

try {
    $stmt = $db->query("
        SELECT a.title, c.name as cat_name
        FROM articles a
        LEFT JOIN categories c ON a.category_id = c.id
        WHERE a.status = 'published'
          AND a.published_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
        ORDER BY a.published_at DESC
        LIMIT 100
    ");
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Extract keywords from titles
    $stopWords = ['في','من','إلى','على','عن','بعد','قبل','مع','بين','حول',
                  'أن','لا','هل','ما','هذا','هذه','التي','الذي','كان','كانت',
                  'ذلك','تلك','هناك','أو','لم','لن','قد','عند','حتى','أي',
                  'ثم','أما','إذا','منذ','خلال','ضد','نحو','فوق','تحت','دون',
                  'يتم','سيتم','أكثر','كل','بشكل','يوم','عام','الأول','جديد',
                  'أحد','عبر','ضمن','أمام','أعلن','قال','قالت','أكد','أضاف'];

    $wordFreq = [];
    foreach ($articles as $art) {
        $title = $art['title'] ?? '';
        $title = preg_replace('/[^\p{Arabic}\s]/u', '', $title);
        $words = preg_split('/\s+/', trim($title));
        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word) < 3) continue;
            if (in_array($word, $stopWords)) continue;
            $wordFreq[$word] = ($wordFreq[$word] ?? 0) + 1;
        }
    }

    arsort($wordFreq);
    $topWords = array_slice($wordFreq, 0, 8, true);

    $icons = ['📈','🔥','💬','📰','🌍','⚡','📊','🏷'];
    $topics = [];
    $i = 0;
    foreach ($topWords as $kw => $cnt) {
        if ($cnt < 2) continue;
        $topics[] = [
            'keyword' => $kw,
            'count'   => $cnt,
            'icon'    => $icons[$i % count($icons)]
        ];
        $i++;
    }

    echo json_encode(['topics' => $topics], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['topics' => []], JSON_UNESCAPED_UNICODE);
}
