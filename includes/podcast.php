<?php
/**
 * Daily AI Podcast — data layer.
 *
 * One row per daily episode keyed on the broadcast date.
 * Stores:
 *   - The AI-written radio script (for transcript + accessibility)
 *   - The final MP3 path + bytes + duration
 *   - Chapter markers so the player can jump between segments
 *   - Which article ids fed the episode (provenance)
 *
 * Lifecycle:
 *   cron_podcast.php  →  pod_generate_today()   builds + saves
 *   podcast.php       →  pod_get_by_date()       public read
 *   podcast.xml       →  pod_list_recent()        RSS feed
 *   panel/podcast.php →  admin ops
 */

if (!function_exists('pod_ensure_table')) {

function pod_ensure_table(): void {
    static $ensured = false;
    if ($ensured) return;
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS podcast_episodes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        episode_date DATE NOT NULL UNIQUE,
        title VARCHAR(300) NOT NULL DEFAULT '',
        subtitle VARCHAR(500) NOT NULL DEFAULT '',
        intro TEXT,                        -- AI-generated opener
        script_text LONGTEXT,              -- full spoken script (transcript)
        chapters_json TEXT,                -- [{time, title, article_id}, ...]
        article_ids TEXT,                  -- comma-separated contributing ids
        audio_path VARCHAR(500) NOT NULL DEFAULT '',
        audio_bytes INT NOT NULL DEFAULT 0,
        duration_seconds INT NOT NULL DEFAULT 0,
        tts_provider VARCHAR(20) NOT NULL DEFAULT '',
        tts_voice VARCHAR(80) NOT NULL DEFAULT '',
        play_count INT NOT NULL DEFAULT 0,
        published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_date (episode_date DESC),
        INDEX idx_published (published_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ensured = true;
}

function pod_hydrate(array $row): array {
    $chapters = json_decode((string)($row['chapters_json'] ?? '[]'), true);
    $ids      = array_filter(array_map('intval', explode(',', (string)($row['article_ids'] ?? ''))));
    return [
        'id'               => (int)$row['id'],
        'episode_date'     => (string)$row['episode_date'],
        'title'            => (string)$row['title'],
        'subtitle'         => (string)$row['subtitle'],
        'intro'            => (string)$row['intro'],
        'script_text'      => (string)$row['script_text'],
        'chapters'         => is_array($chapters) ? $chapters : [],
        'article_ids'      => array_values($ids),
        'audio_path'       => (string)$row['audio_path'],
        'audio_url'        => $row['audio_path'] ? '/' . ltrim((string)$row['audio_path'], '/') : '',
        'audio_bytes'      => (int)$row['audio_bytes'],
        'duration_seconds' => (int)$row['duration_seconds'],
        'tts_provider'     => (string)$row['tts_provider'],
        'tts_voice'        => (string)$row['tts_voice'],
        'play_count'       => (int)$row['play_count'],
        'published_at'     => (string)$row['published_at'],
    ];
}

function pod_save(string $episodeDate, array $payload): int {
    pod_ensure_table();
    $db = getDB();
    $existing = pod_get_by_date($episodeDate);

    $params = [
        ':date'     => $episodeDate,
        ':title'    => (string)($payload['title']       ?? ''),
        ':subtitle' => (string)($payload['subtitle']    ?? ''),
        ':intro'    => (string)($payload['intro']       ?? ''),
        ':script'   => (string)($payload['script_text'] ?? ''),
        ':chapters' => json_encode($payload['chapters'] ?? [], JSON_UNESCAPED_UNICODE),
        ':ids'      => implode(',', array_map('intval', (array)($payload['article_ids'] ?? []))),
        ':path'     => (string)($payload['audio_path']        ?? ''),
        ':bytes'    => (int)   ($payload['audio_bytes']       ?? 0),
        ':dur'      => (int)   ($payload['duration_seconds']  ?? 0),
        ':provider' => (string)($payload['tts_provider']      ?? ''),
        ':voice'    => (string)($payload['tts_voice']         ?? ''),
    ];

    if ($existing) {
        unset($params[':date']);
        $params[':id'] = (int)$existing['id'];
        $sql = "UPDATE podcast_episodes SET
                  title=:title, subtitle=:subtitle, intro=:intro,
                  script_text=:script, chapters_json=:chapters, article_ids=:ids,
                  audio_path=:path, audio_bytes=:bytes, duration_seconds=:dur,
                  tts_provider=:provider, tts_voice=:voice
                WHERE id=:id";
        $db->prepare($sql)->execute($params);
        return (int)$existing['id'];
    }

    $sql = "INSERT INTO podcast_episodes
              (episode_date, title, subtitle, intro, script_text, chapters_json,
               article_ids, audio_path, audio_bytes, duration_seconds,
               tts_provider, tts_voice)
            VALUES
              (:date, :title, :subtitle, :intro, :script, :chapters,
               :ids, :path, :bytes, :dur, :provider, :voice)";
    $db->prepare($sql)->execute($params);
    return (int)$db->lastInsertId();
}

function pod_get_by_date(string $episodeDate): ?array {
    pod_ensure_table();
    $stmt = getDB()->prepare("SELECT * FROM podcast_episodes WHERE episode_date = ?");
    $stmt->execute([$episodeDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? pod_hydrate($row) : null;
}

function pod_get_latest(): ?array {
    pod_ensure_table();
    $row = getDB()->query("SELECT * FROM podcast_episodes
                           WHERE audio_path <> ''
                           ORDER BY episode_date DESC LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC);
    return $row ? pod_hydrate($row) : null;
}

function pod_list_recent(int $limit = 30): array {
    pod_ensure_table();
    $limit = max(1, min(100, $limit));
    $rows = getDB()->query("SELECT * FROM podcast_episodes
                            WHERE audio_path <> ''
                            ORDER BY episode_date DESC LIMIT {$limit}")
                   ->fetchAll(PDO::FETCH_ASSOC);
    return array_map('pod_hydrate', $rows ?: []);
}

function pod_bump_plays(int $id): void {
    try {
        $stmt = getDB()->prepare("UPDATE podcast_episodes SET play_count = play_count + 1 WHERE id = ?");
        $stmt->execute([$id]);
    } catch (Throwable $e) {}
}

function pod_audio_dir(): string {
    $dir = __DIR__ . '/../storage/podcast';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return $dir;
}

function pod_audio_public_path(string $date): string {
    return 'storage/podcast/' . $date . '.mp3';
}

} // function_exists guard
