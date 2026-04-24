<?php
/**
 * GET /api/v1/podcast.php              — latest episode + archive
 * GET /api/v1/podcast.php?date=YYYY-MM-DD — a specific episode
 * GET /api/v1/podcast.php?archive=1    — list all episodes (paginated)
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET');
api_rate_limit('podcast', 120, 60);

try {
    $db = getDB();

    if (!empty($_GET['archive'])) {
        $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 100)) : 30;
        $rows = [];
        try {
            $stmt = $db->prepare("SELECT id, episode_date, title, subtitle, duration_seconds, audio_path, play_count
                                  FROM podcast_episodes
                                  ORDER BY episode_date DESC LIMIT ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {}
        $items = array_map(fn($r) => [
            'id' => (int)$r['id'],
            'date' => $r['episode_date'],
            'title' => $r['title'],
            'subtitle' => $r['subtitle'],
            'duration_seconds' => (int)$r['duration_seconds'],
            'audio_url' => podcast_audio_url($r['audio_path']),
            'play_count' => (int)$r['play_count'],
        ], $rows);
        api_json(['ok' => true, 'count' => count($items), 'items' => $items]);
    }

    $date = trim((string)($_GET['date'] ?? ''));
    try {
        if ($date !== '') {
            $stmt = $db->prepare("SELECT * FROM podcast_episodes WHERE episode_date = ? LIMIT 1");
            $stmt->execute([$date]);
        } else {
            $stmt = $db->query("SELECT * FROM podcast_episodes ORDER BY episode_date DESC LIMIT 1");
        }
        $ep = $stmt->fetch();
    } catch (Throwable $e) {
        api_json(['ok' => true, 'episode' => null]);
    }
    if (!$ep) api_json(['ok' => true, 'episode' => null]);

    $chapters = [];
    if (!empty($ep['chapters_json'])) {
        $decoded = json_decode($ep['chapters_json'], true);
        if (is_array($decoded)) $chapters = $decoded;
    }

    api_json([
        'ok' => true,
        'episode' => [
            'id' => (int)$ep['id'],
            'date' => $ep['episode_date'],
            'title' => $ep['title'],
            'subtitle' => $ep['subtitle'],
            'intro' => $ep['intro'] ?? null,
            'script' => $ep['script_text'] ?? null,
            'chapters' => $chapters,
            'article_ids' => !empty($ep['article_ids']) ? array_values(array_filter(array_map('intval', explode(',', (string)$ep['article_ids'])))) : [],
            'audio_url' => podcast_audio_url($ep['audio_path'] ?? null),
            'duration_seconds' => (int)($ep['duration_seconds'] ?? 0),
            'tts_provider' => $ep['tts_provider'] ?? null,
            'play_count' => (int)($ep['play_count'] ?? 0),
        ],
    ]);
} catch (Throwable $e) {
    error_log('v1/podcast: ' . $e->getMessage());
    api_error('server_error', '', 500);
}

function podcast_audio_url(?string $path): ?string {
    if (!$path) return null;
    if (preg_match('#^https?://#i', $path)) return $path;
    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}
