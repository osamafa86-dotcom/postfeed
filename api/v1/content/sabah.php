<?php
/**
 * GET /api/v1/content/sabah?date=YYYY-MM-DD
 *
 * Morning brief. Previously called sabah_get_for_date()/sabah_latest()
 * (don't exist — real names are sabah_get/sabah_get_latest) and the
 * fallback queried `sabah_briefs.brief_date` (real table is
 * `sabah_briefings` with `briefing_date`). Both paths silently 404'd.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/sabah.php';

api_method('GET');
api_rate_limit('content:sabah', 120, 60);

$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
$brief = null;
try {
    $brief = sabah_get($date);
    if (!$brief) $brief = sabah_get_latest();
} catch (Throwable $e) {
    error_log('sabah: ' . $e->getMessage());
}

if (!$brief) api_err('not_found', 'لا يوجد ملخص صباحي لهذا التاريخ', 404);

// sabah_briefings cols: briefing_date, headline, hook, sections (JSON),
// closing_question, article_count, generated_at
$sections = isset($brief['sections']) ? json_decode((string)$brief['sections'], true) : [];

api_ok([
    'date'             => $brief['briefing_date'] ?? $date,
    'title'            => $brief['headline'] ?? 'صباح فيد نيوز',
    'summary'          => $brief['hook'] ?? '',
    'sections'         => is_array($sections) ? $sections : [],
    'closing_question' => $brief['closing_question'] ?? null,
    'article_count'    => (int)($brief['article_count'] ?? 0),
    'created_at'       => $brief['generated_at'] ?? null,
]);
