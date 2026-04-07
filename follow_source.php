<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

if (!$id || !in_array($action, ['follow','unfollow'])) {
    echo json_encode(['ok'=>false]); exit;
}

// Auto-migrate
try {
    $cols = $db->query("SHOW COLUMNS FROM sources LIKE 'followers_count'")->fetch();
    if (!$cols) $db->exec("ALTER TABLE sources ADD COLUMN followers_count INT DEFAULT 0");
} catch (Exception $e) {}

$followed = isset($_COOKIE['followed_sources']) && $_COOKIE['followed_sources'] !== ''
    ? explode(',', $_COOKIE['followed_sources']) : [];
$followed = array_filter(array_unique($followed));

$already = in_array((string)$id, $followed, true);

if ($action === 'follow' && !$already) {
    $followed[] = (string)$id;
    $db->prepare("UPDATE sources SET followers_count = followers_count + 1 WHERE id = ?")->execute([$id]);
} elseif ($action === 'unfollow' && $already) {
    $followed = array_values(array_diff($followed, [(string)$id]));
    $db->prepare("UPDATE sources SET followers_count = GREATEST(followers_count - 1, 0) WHERE id = ?")->execute([$id]);
}

setcookie('followed_sources', implode(',', $followed), time() + 86400*365, '/');

$count = (int)$db->query("SELECT followers_count FROM sources WHERE id = " . (int)$id)->fetchColumn();
echo json_encode(['ok'=>true, 'count'=>$count, 'following'=>in_array((string)$id, $followed, true)]);
