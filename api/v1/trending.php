<?php
/**
 * GET /api/v1/trending — lightweight wrapper around the existing trending store.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/trending.php';

api_method('GET');
api_rate_limit('trending', 120, 60);

$limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 50)) : 10;

try {
    $items = function_exists('trending_fetch') ? (trending_fetch($limit) ?: []) : [];
    $readers = function_exists('trending_readers_now') ? (int)trending_readers_now() : 0;

    $shaped = array_map(function($t) {
        return [
            'id' => isset($t['id']) ? (int)$t['id'] : null,
            'title' => $t['title'] ?? '',
            'image_url' => $t['image_url'] ?? null,
            'published_at' => $t['published_at'] ?? null,
            'category' => $t['category'] ?? null,
            'category_slug' => $t['category_slug'] ?? null,
            'source' => $t['source'] ?? null,
            'velocity_score' => isset($t['velocity_score']) ? (int)$t['velocity_score'] : null,
            'views_last_hour' => isset($t['views_last_hour']) ? (int)$t['views_last_hour'] : 0,
            'views_last_6h'   => isset($t['views_last_6h']) ? (int)$t['views_last_6h'] : 0,
            'cluster_key' => $t['cluster_key'] ?? null,
            'cluster_size' => isset($t['cluster_size']) ? (int)$t['cluster_size'] : 1,
        ];
    }, $items);

    api_json([
        'ok' => true,
        'count' => count($shaped),
        'readers_now' => $readers,
        'generated_at' => date('c'),
        'items' => $shaped,
    ]);
} catch (Throwable $e) {
    error_log('v1/trending: ' . $e->getMessage());
    api_error('server_error', 'تعذّر جلب الترند', 500);
}
