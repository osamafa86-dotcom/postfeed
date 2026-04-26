<?php
/**
 * GET  /api/v1/user/follows                       — list all follows
 * POST /api/v1/user/follows                       — { type, target }
 *   type ∈ category|source|story    target = id or slug
 *   Toggles the follow.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET', 'POST');
$user = api_require_user();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cats = $db->prepare("SELECT c.id, c.name, c.slug, c.icon, c.css_class
                           FROM user_category_follows ucf
                           INNER JOIN categories c ON c.id = ucf.category_id
                           WHERE ucf.user_id=? AND c.is_active=1
                           ORDER BY ucf.created_at DESC");
    $cats->execute([(int)$user['id']]);

    $sources = $db->prepare("SELECT s.id, s.name, s.slug, s.logo_letter, s.logo_color, s.logo_bg, s.url
                              FROM user_source_follows usf
                              INNER JOIN sources s ON s.id = usf.source_id
                              WHERE usf.user_id=? AND s.is_active=1
                              ORDER BY usf.created_at DESC");
    $sources->execute([(int)$user['id']]);

    api_ok([
        'categories' => $cats->fetchAll(),
        'sources'    => $sources->fetchAll(),
    ]);
}

$body = api_body();
$type   = (string)($body['type'] ?? '');
$target = $body['target'] ?? null;
if (!in_array($type, ['category', 'source', 'story'], true)) api_err('invalid_input', 'type غير صالح', 422);
if ($target === null || $target === '') api_err('invalid_input', 'يلزم target', 422);

if ($type === 'category') {
    $cid = is_numeric($target)
        ? (int)$target
        : (int)$db->prepare("SELECT id FROM categories WHERE slug=?")
                 ->execute([(string)$target]) ?: 0;
    if (!is_numeric($target)) {
        $st = $db->prepare("SELECT id FROM categories WHERE slug=? LIMIT 1");
        $st->execute([(string)$target]);
        $cid = (int)$st->fetchColumn();
    }
    if (!$cid) api_err('not_found', 'القسم غير موجود', 404);

    $check = $db->prepare("SELECT 1 FROM user_category_follows WHERE user_id=? AND category_id=?");
    $check->execute([(int)$user['id'], $cid]);
    if ($check->fetchColumn()) {
        $db->prepare("DELETE FROM user_category_follows WHERE user_id=? AND category_id=?")
           ->execute([(int)$user['id'], $cid]);
        api_ok(['following' => false]);
    }
    $db->prepare("INSERT INTO user_category_follows (user_id, category_id, created_at) VALUES (?,?,NOW())")
       ->execute([(int)$user['id'], $cid]);
    api_ok(['following' => true]);
}

if ($type === 'source') {
    if (is_numeric($target)) { $sid = (int)$target; }
    else {
        $st = $db->prepare("SELECT id FROM sources WHERE slug=? LIMIT 1");
        $st->execute([(string)$target]);
        $sid = (int)$st->fetchColumn();
    }
    if (!$sid) api_err('not_found', 'المصدر غير موجود', 404);

    $check = $db->prepare("SELECT 1 FROM user_source_follows WHERE user_id=? AND source_id=?");
    $check->execute([(int)$user['id'], $sid]);
    if ($check->fetchColumn()) {
        $db->prepare("DELETE FROM user_source_follows WHERE user_id=? AND source_id=?")
           ->execute([(int)$user['id'], $sid]);
        api_ok(['following' => false]);
    }
    $db->prepare("INSERT INTO user_source_follows (user_id, source_id, created_at) VALUES (?,?,NOW())")
       ->execute([(int)$user['id'], $sid]);
    api_ok(['following' => true]);
}

// type === 'story' (evolving story). The website uses a similar pattern.
if (is_numeric($target)) { $tid = (int)$target; }
else {
    $st = $db->prepare("SELECT id FROM evolving_stories WHERE slug=? LIMIT 1");
    $st->execute([(string)$target]);
    $tid = (int)$st->fetchColumn();
}
if (!$tid) api_err('not_found', 'القصة غير موجودة', 404);

// Lazy-create the table — keeps the migration optional.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_story_follows (
        user_id INT NOT NULL,
        story_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, story_id),
        KEY idx_story (story_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

$check = $db->prepare("SELECT 1 FROM user_story_follows WHERE user_id=? AND story_id=?");
$check->execute([(int)$user['id'], $tid]);
if ($check->fetchColumn()) {
    $db->prepare("DELETE FROM user_story_follows WHERE user_id=? AND story_id=?")
       ->execute([(int)$user['id'], $tid]);
    api_ok(['following' => false]);
}
$db->prepare("INSERT INTO user_story_follows (user_id, story_id, created_at) VALUES (?,?,NOW())")
   ->execute([(int)$user['id'], $tid]);
api_ok(['following' => true]);
