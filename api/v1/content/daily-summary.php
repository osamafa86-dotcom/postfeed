<?php
/**
 * GET /api/v1/content/daily-summary?date=YYYY-MM-DD
 *
 * Reuses the morning brief as the daily summary. Previously queried
 * `sabah_briefs.brief_date` (real table is `sabah_briefings` with
 * `briefing_date`), so it always 404'd.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/sabah.php';

api_method('GET');
api_rate_limit('content:daily-summary', 120, 60);

$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
$brief = null;
try {
    $brief = sabah_get($date);
    if (!$brief) $brief = sabah_get_latest();
} catch (Throwable $e) {
    error_log('daily-summary: ' . $e->getMessage());
}

if (!$brief) api_err('not_found', 'لا يوجد ملخص يومي متاح حالياً', 404);

$sections = isset($brief['sections']) ? json_decode((string)$brief['sections'], true) : [];

api_ok([
    'date'     => $brief['briefing_date'] ?? $date,
    'title'    => $brief['headline'] ?? 'ملخص أخبار اليوم',
    'summary'  => $brief['hook'] ?? '',
    'sections' => is_array($sections) ? $sections : [],
]);
