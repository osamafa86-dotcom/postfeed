<?php
/**
 * GET /api/v1/content/weekly-rewind
 * Weekly digest of top stories. Backed by the website's weekly_rewind table.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/weekly_rewind.php';

api_method('GET');
api_rate_limit('content:weekly', 120, 60);

$week = trim((string)($_GET['week'] ?? ''));
$rewind = null;

try {
    $db = getDB();
    if ($week) {
        $st = $db->prepare("SELECT * FROM weekly_rewinds WHERE week_start=? ORDER BY id DESC LIMIT 1");
        $st->execute([$week]);
    } else {
        $st = $db->query("SELECT * FROM weekly_rewinds ORDER BY week_start DESC LIMIT 1");
    }
    $rewind = $st->fetch() ?: null;
} catch (Throwable $e) {
    error_log('weekly-rewind: ' . $e->getMessage());
}

if (!$rewind) api_err('not_found', 'لا توجد نشرة أسبوعية متاحة', 404);

api_ok([
    'week_start' => $rewind['week_start'] ?? null,
    'week_end'   => $rewind['week_end'] ?? null,
    'title'      => $rewind['title'] ?? 'مراجعة الأسبوع',
    'summary'    => $rewind['summary'] ?? '',
    'sections'   => isset($rewind['sections']) ? json_decode((string)$rewind['sections'], true) : [],
    'top_stories' => isset($rewind['top_stories']) ? json_decode((string)$rewind['top_stories'], true) : [],
    'audio_url'  => isset($rewind['audio_url']) ? api_absolute_url($rewind['audio_url']) : null,
    'created_at' => $rewind['created_at'] ?? null,
]);
