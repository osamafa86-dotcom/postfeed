<?php
/**
 * GET /api/v1/media/podcast/episodes
 * GET /api/v1/media/podcast/latest
 * GET /api/v1/media/podcast/episode?id=|date=
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/podcast.php';

api_method('GET');
api_rate_limit('media:podcast', 240, 60);

pod_ensure_table();

$mode = $_GET['mode'] ?? 'list'; // list | latest | episode

if ($mode === 'latest') {
    $ep = pod_get_latest();
    if (!$ep) api_err('not_found', 'لا توجد حلقة', 404);
    api_ok(format_episode($ep));
}

if ($mode === 'episode') {
    if (!empty($_GET['date'])) {
        $ep = pod_get_by_date((string)$_GET['date']);
    } elseif (!empty($_GET['id'])) {
        $st = getDB()->prepare("SELECT * FROM podcast_episodes WHERE id=? LIMIT 1");
        $st->execute([(int)$_GET['id']]);
        $row = $st->fetch();
        $ep = $row ? pod_hydrate($row) : null;
    } else {
        api_err('invalid_input', 'يلزم id أو date', 422);
    }
    if (!$ep) api_err('not_found', 'الحلقة غير موجودة', 404);

    // Best-effort play count bump.
    try { pod_bump_plays((int)$ep['id']); } catch (Throwable $e) {}

    api_ok(format_episode($ep));
}

// Default: list recent episodes.
[$page, $limit, $offset] = api_pagination(20, 100);
$rows = pod_list_recent($limit + $offset);
$slice = array_slice($rows, $offset, $limit);
$out = array_map('format_episode', $slice);

api_ok($out, ['page' => $page, 'limit' => $limit]);

function format_episode(array $ep): array {
    return [
        'id' => (int)$ep['id'],
        'date' => $ep['episode_date'] ?? null,
        'title' => $ep['title'] ?? '',
        'subtitle' => $ep['subtitle'] ?? '',
        'intro' => $ep['intro'] ?? '',
        'script_text' => $ep['script_text'] ?? '',
        'chapters' => $ep['chapters'] ?? [],
        'article_ids' => $ep['article_ids'] ?? [],
        'audio_url' => isset($ep['audio_url']) && $ep['audio_url'] !== '' ? api_absolute_url($ep['audio_url']) : null,
        'audio_bytes' => (int)($ep['audio_bytes'] ?? 0),
        'duration_seconds' => (int)($ep['duration_seconds'] ?? 0),
        'tts_provider' => $ep['tts_provider'] ?? '',
        'tts_voice' => $ep['tts_voice'] ?? '',
        'play_count' => (int)($ep['play_count'] ?? 0),
        'published_at' => $ep['published_at'] ?? null,
    ];
}
