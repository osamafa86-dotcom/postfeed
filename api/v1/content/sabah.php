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

// sabah_get / sabah_get_latest already decode the JSON columns into
// native arrays before returning. Don't double-decode here.
$sections    = is_array($brief['sections']    ?? null) ? $brief['sections']    : [];
$keyNumbers  = is_array($brief['key_numbers'] ?? null) ? $brief['key_numbers'] : [];
$regions     = is_array($brief['regions']     ?? null) ? $brief['regions']     : [];
$quoteOfDay  = is_array($brief['quote_of_day'] ?? null) ? $brief['quote_of_day'] : null;

api_ok([
    'date'             => $brief['briefing_date'] ?? $date,
    'title'            => $brief['headline'] ?? 'صباح فيد نيوز',
    'subtitle'         => $brief['subheadline'] ?? '',
    'summary'          => $brief['hook'] ?? '',
    'sections'         => $sections,
    'key_numbers'      => $keyNumbers,
    'regions'          => $regions,
    'quote_of_day'     => $quoteOfDay,
    'closing_question' => $brief['closing_question'] ?? null,
    'article_count'    => (int)($brief['article_count'] ?? 0),
    'created_at'       => $brief['generated_at'] ?? null,
]);
