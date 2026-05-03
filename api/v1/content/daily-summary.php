<?php
/**
 * GET /api/v1/content/daily-summary
 * Returns the daily AI summary (sourced from the morning brief / sabah).
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:daily-summary', 120, 60);

$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
$brief = null;

try {
    $db = getDB();

    // Try today first, then fall back to the most recent available brief.
    $st = $db->prepare("SELECT * FROM sabah_briefs WHERE brief_date = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$date]);
    $brief = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$brief) {
        $st = $db->query("SELECT * FROM sabah_briefs ORDER BY brief_date DESC, id DESC LIMIT 1");
        $brief = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    error_log('daily-summary: ' . $e->getMessage());
}

if (!$brief) {
    api_err('not_found', 'لا يوجد ملخص يومي متاح حالياً', 404);
}

api_ok([
    'date'    => $brief['brief_date'] ?? $date,
    'title'   => $brief['title'] ?? 'ملخص أخبار اليوم',
    'summary' => $brief['summary'] ?? ($brief['body'] ?? ''),
]);
