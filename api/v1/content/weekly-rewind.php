<?php
/**
 * GET /api/v1/content/weekly-rewind?week=YYYY-WW
 *
 * The previous version queried columns that don't exist in
 * `weekly_rewinds` (week_start/week_end/title/summary/sections/...),
 * so the endpoint always 500'd silently and the screen looked broken.
 * Use the wr_* helpers in includes/weekly_rewind.php — they hydrate
 * the actual columns (cover_title, intro_text, start_date, end_date,
 * content_json) into a stable shape.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/weekly_rewind.php';

api_method('GET');
api_rate_limit('content:weekly', 120, 60);

$week = trim((string)($_GET['week'] ?? ''));
$rewind = null;
try {
    $rewind = $week !== '' ? wr_get_by_week($week) : wr_get_latest();
} catch (Throwable $e) {
    error_log('weekly-rewind: ' . $e->getMessage());
}

if (!$rewind) api_err('not_found', 'لا توجد نشرة أسبوعية متاحة', 404);

// content_json holds AI-generated editorial blocks. Pull any blocks
// that look like top-story cards into a flat top_stories array.
$content = is_array($rewind['content'] ?? null) ? $rewind['content'] : [];
$topStories = [];
foreach ($content as $block) {
    if (!is_array($block)) continue;
    $type = (string)($block['type'] ?? '');
    if (in_array($type, ['story', 'top_story', 'article'], true)
        || isset($block['title']) || isset($block['headline'])) {
        $topStories[] = [
            'title'      => (string)($block['title'] ?? $block['headline'] ?? ''),
            'summary'    => (string)($block['summary'] ?? $block['excerpt'] ?? ''),
            'article_id' => isset($block['article_id']) ? (int)$block['article_id'] : null,
            'image_url'  => api_image_url($block['image_url'] ?? null),
            'category'   => $block['category'] ?? null,
        ];
    }
}

// Archive — previous weeks for the bottom of the screen.
$archive = [];
try {
    foreach (wr_list(12) as $r) {
        if (($r['year_week'] ?? '') === ($rewind['year_week'] ?? '')) continue;
        $archive[] = [
            'year_week'  => $r['year_week'] ?? '',
            'week_start' => $r['start_date'] ?? '',
            'week_end'   => $r['end_date'] ?? '',
            'title'      => $r['cover_title'] ?: 'مراجعة الأسبوع',
        ];
    }
} catch (Throwable $e) {}

api_ok([
    'year_week'  => $rewind['year_week'] ?? '',
    'week_start' => $rewind['start_date'] ?? '',
    'week_end'   => $rewind['end_date'] ?? '',
    'title'      => $rewind['cover_title'] ?: 'مراجعة الأسبوع',
    'summary'    => $rewind['intro_text'] !== '' ? $rewind['intro_text'] : ($rewind['cover_subtitle'] ?? ''),
    'sections'   => $content,
    'top_stories' => $topStories,
    'archive'    => $archive,
    'created_at' => $rewind['published_at'] ?? null,
]);
