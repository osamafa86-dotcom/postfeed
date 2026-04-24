<?php
/**
 * GET /api/v1/weekly.php              — latest weekly rewind
 * GET /api/v1/weekly.php?week=2026-17 — specific ISO year-week
 * GET /api/v1/weekly.php?archive=1    — list of available weeks
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET');
api_rate_limit('weekly', 60, 60);

try {
    $db = getDB();

    if (!empty($_GET['archive'])) {
        $items = [];
        try {
            $rows = $db->query("SELECT year_week, start_date, end_date, cover_title, cover_subtitle, cover_image_url
                                FROM weekly_rewinds ORDER BY start_date DESC LIMIT 20")->fetchAll();
            $items = array_map(fn($r) => [
                'year_week' => $r['year_week'],
                'start_date' => $r['start_date'],
                'end_date' => $r['end_date'],
                'title' => $r['cover_title'],
                'subtitle' => $r['cover_subtitle'],
                'cover_image_url' => $r['cover_image_url'],
            ], $rows);
        } catch (Throwable $e) {}
        api_json(['ok' => true, 'count' => count($items), 'items' => $items]);
    }

    $week = trim((string)($_GET['week'] ?? ''));
    $row = null;
    try {
        if ($week !== '') {
            $stmt = $db->prepare("SELECT * FROM weekly_rewinds WHERE year_week = ? LIMIT 1");
            $stmt->execute([$week]);
            $row = $stmt->fetch();
        } else {
            $row = $db->query("SELECT * FROM weekly_rewinds ORDER BY start_date DESC LIMIT 1")->fetch();
        }
    } catch (Throwable $e) {
        api_json(['ok' => true, 'rewind' => null]);
    }
    if (!$row) api_json(['ok' => true, 'rewind' => null]);

    $content = [];
    if (!empty($row['content_json'])) {
        $d = json_decode($row['content_json'], true);
        if (is_array($d)) $content = $d;
    }
    $stats = [];
    if (!empty($row['stats_json'])) {
        $d = json_decode($row['stats_json'], true);
        if (is_array($d)) $stats = $d;
    }

    api_json([
        'ok' => true,
        'rewind' => [
            'year_week' => $row['year_week'],
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'],
            'title' => $row['cover_title'],
            'subtitle' => $row['cover_subtitle'],
            'cover_image_url' => $row['cover_image_url'],
            'intro_text' => $row['intro_text'] ?? null,
            'content' => $content,
            'stats' => $stats,
        ],
    ]);
} catch (Throwable $e) {
    error_log('v1/weekly: ' . $e->getMessage());
    api_error('server_error', '', 500);
}
