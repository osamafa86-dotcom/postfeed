<?php
/**
 * GET /api/v1/content/evolving-story-network?slug=...
 * Returns entity-network graph (people / places / orgs) extracted from
 * articles linked to this evolving story.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:evolving:network', 120, 60);

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') api_err('invalid_input', 'يلزم slug', 422);

$db = getDB();
$st = $db->prepare("SELECT id, name, accent_color FROM evolving_stories WHERE slug=? AND is_active=1 LIMIT 1");
$st->execute([$slug]);
$story = $st->fetch();
if (!$story) api_err('not_found', 'القصة غير موجودة', 404);

$entities = [];
try {
    $sql = "SELECT id, entity_name, entity_type, mentions, last_seen
            FROM evolving_story_entities
            WHERE story_id=?
            ORDER BY mentions DESC, entity_name ASC LIMIT 200";
    $ps = $db->prepare($sql);
    $ps->execute([(int)$story['id']]);
    $rows = $ps->fetchAll();
    foreach ($rows as $r) {
        $entities[] = [
            'id' => (int)$r['id'],
            'name' => $r['entity_name'],
            'type' => $r['entity_type'],
            'mentions' => (int)$r['mentions'],
            'last_seen' => $r['last_seen'],
        ];
    }
} catch (Throwable $e) {}

api_ok([
    'story' => [
        'id' => (int)$story['id'],
        'name' => $story['name'],
        'slug' => $slug,
        'accent_color' => $story['accent_color'],
    ],
    'entities' => $entities,
]);
