<?php
/**
 * GET /api/v1/content/ticker
 *
 * Old query SELECTed a `link` column that doesn't exist on
 * ticker_items in production — catch swallowed the error, so the
 * ticker always came back empty. Drop the column from the query
 * (the app currently ignores the field anyway).
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:ticker', 240, 60);

$db = getDB();
$rows = [];
try {
    $rows = $db->query("SELECT id, text, sort_order
                        FROM ticker_items
                        WHERE is_active=1
                        ORDER BY sort_order, id LIMIT 30")->fetchAll();
} catch (Throwable $e) {
    error_log('ticker: ' . $e->getMessage());
}

$out = array_map(function ($r) {
    return [
        'id'   => (int)$r['id'],
        'text' => $r['text'],
        'link' => null,
    ];
}, $rows);

api_ok($out);
