<?php
/**
 * GET /api/v1/content/stories-network
 *
 * Global graph of evolving stories — nodes are stories, edges connect
 * stories that share articles. The app's StoriesNetworkScreen reads
 * `{nodes: [...], edges: [...]}` and renders an interactive graph.
 *
 * This endpoint didn't exist before — the app was hitting a 404 which
 * surfaced as "تعذّر إكمال الطلب" in the UI.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:stories-network', 120, 60);

$db = getDB();

// ── Nodes ── one per active story, with how many articles are linked.
$nodes = [];
try {
    $rows = $db->query("
        SELECT s.id, s.slug, s.name, s.icon, s.accent_color,
               COALESCE(c.cnt, 0) AS article_count
        FROM evolving_stories s
        LEFT JOIN (
            SELECT story_id, COUNT(*) AS cnt
            FROM evolving_story_articles
            GROUP BY story_id
        ) c ON c.story_id = s.id
        WHERE s.is_active = 1
        ORDER BY c.cnt DESC, s.sort_order ASC, s.id ASC
        LIMIT 60
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $nodes[] = [
            'id'            => (int)$r['id'],
            'slug'          => $r['slug'],
            'name'          => $r['name'],
            'icon'          => $r['icon'] ?? '📰',
            'accent_color'  => $r['accent_color'] ?? '#0D9488',
            'article_count' => (int)$r['article_count'],
        ];
    }
} catch (Throwable $e) {
    error_log('stories-network nodes: ' . $e->getMessage());
}

// Map id → slug for the edges below.
$idToSlug = [];
foreach ($nodes as $n) {
    $idToSlug[(int)$n['id']] = $n['slug'];
}

// ── Edges ── two stories are connected when they share articles. The
// weight is the count of shared articles. Cap to the strongest 200 so
// the graph stays renderable.
$edges = [];
try {
    if (!empty($idToSlug)) {
        $rows = $db->query("
            SELECT a.story_id AS from_id, b.story_id AS to_id, COUNT(*) AS weight
            FROM evolving_story_articles a
            INNER JOIN evolving_story_articles b
                ON b.article_id = a.article_id
                AND b.story_id > a.story_id
            INNER JOIN evolving_stories s1 ON s1.id = a.story_id AND s1.is_active = 1
            INNER JOIN evolving_stories s2 ON s2.id = b.story_id AND s2.is_active = 1
            GROUP BY a.story_id, b.story_id
            HAVING weight >= 2
            ORDER BY weight DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $from = $idToSlug[(int)$r['from_id']] ?? null;
            $to   = $idToSlug[(int)$r['to_id']]   ?? null;
            if ($from === null || $to === null) continue;
            $edges[] = [
                'from'   => $from,
                'to'     => $to,
                'weight' => (int)$r['weight'],
            ];
        }
    }
} catch (Throwable $e) {
    error_log('stories-network edges: ' . $e->getMessage());
}

api_ok([
    'nodes' => $nodes,
    'edges' => $edges,
]);
