<?php
/**
 * GET /api/v1/media/social-summary?platform=telegram|twitter|youtube
 * Returns the latest AI-generated rich daily summary for a platform:
 * headline, subheadline, summary, sections[], key_numbers[], regions[],
 * topics[]. Telegram comes from the dedicated telegram_summaries table;
 * twitter/youtube from the generic social_summaries table.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/ai_helper.php';

api_method('GET');
api_rate_limit('media:social-summary', 120, 60);

$platform = strtolower(trim((string)($_GET['platform'] ?? '')));

if (!in_array($platform, ['telegram', 'twitter', 'youtube', 'all'], true)) {
    api_err('invalid_platform', 'يرجى تحديد المنصة: telegram أو twitter أو youtube أو all', 400);
}

// Telegram keeps its dedicated store; the others use the generic one.
$latest = $platform === 'telegram'
    ? tg_summary_get_latest()
    : social_summary_get_latest($platform);

if (!$latest || empty($latest['summary'])) {
    api_err('not_found', 'لا يوجد ملخص متاح حالياً لهذه المنصة', 404);
}

api_ok([
    'platform'     => $platform,
    'headline'     => $latest['headline']     ?? '',
    'subheadline'  => $latest['subheadline']  ?? '',
    'summary'      => $latest['summary']      ?? '',
    'sections'     => $latest['sections']     ?? [],
    'key_numbers'  => $latest['key_numbers']  ?? [],
    'regions'      => $latest['regions']      ?? [],
    'topics'       => $latest['topics']       ?? [],
    'message_count'=> $latest['message_count']?? 0,
    'generated_at' => $latest['generated_at'] ?? null,
]);
