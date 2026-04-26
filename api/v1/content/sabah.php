<?php
/**
 * GET /api/v1/content/sabah
 * Morning brief — the website's "صباح" digest.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/sabah.php';

api_method('GET');
api_rate_limit('content:sabah', 120, 60);

$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
$brief = null;
try {
    if (function_exists('sabah_get_for_date')) {
        $brief = sabah_get_for_date($date);
    } elseif (function_exists('sabah_latest')) {
        $brief = sabah_latest();
    }
} catch (Throwable $e) {
    error_log('sabah: ' . $e->getMessage());
}

if (!$brief) {
    // Fallback: load directly from sabah_briefs table if it exists.
    try {
        $db = getDB();
        $st = $db->prepare("SELECT * FROM sabah_briefs WHERE brief_date=? ORDER BY id DESC LIMIT 1");
        $st->execute([$date]);
        $brief = $st->fetch() ?: null;
    } catch (Throwable $e) {}
}

if (!$brief) api_err('not_found', 'لا يوجد ملخص صباحي لهذا التاريخ', 404);

api_ok([
    'date'      => $brief['brief_date'] ?? $date,
    'title'     => $brief['title'] ?? 'صباح فيد نيوز',
    'summary'   => $brief['summary'] ?? ($brief['body'] ?? ''),
    'sections'  => isset($brief['sections']) ? json_decode((string)$brief['sections'], true) : [],
    'highlights' => isset($brief['highlights']) ? json_decode((string)$brief['highlights'], true) : [],
    'audio_url' => isset($brief['audio_url']) ? api_absolute_url($brief['audio_url']) : null,
    'created_at' => $brief['created_at'] ?? null,
]);
