<?php
/**
 * Weekly Rewind — data layer.
 *
 * One row per ISO week, generated every Saturday evening, surfaced
 * on a public /weekly/<year>-<week> page and emailed to subscribers
 * Sunday morning. The actual content (curated stories, intro copy,
 * stats, "what to watch next") lives in `content_json` so the
 * schema stays stable as the editorial format evolves.
 *
 * Lifecycle:
 *   1. cron_weekly_rewind.php → wr_generate(...)  builds & saves
 *   2. weekly.php             → wr_get_by_week    public read
 *   3. cron_weekly_rewind.php → wr_send_emails     Sunday delivery
 *   4. panel/weekly_rewind.php → admin tools (regenerate, preview)
 */

if (!function_exists('wr_ensure_table')) {

function wr_ensure_table(): void {
    static $ensured = false;
    if ($ensured) return;
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS weekly_rewinds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year_week VARCHAR(10) NOT NULL UNIQUE,        -- '2026-17'
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        cover_title VARCHAR(500) NOT NULL DEFAULT '',
        cover_subtitle VARCHAR(500) NOT NULL DEFAULT '',
        cover_image_url VARCHAR(500) NOT NULL DEFAULT '',
        intro_text TEXT,
        content_json LONGTEXT,                         -- structured editorial blocks
        stats_json TEXT,                               -- {articles, sources, breaking, ...}
        article_ids TEXT,                              -- comma-separated ids included
        view_count INT NOT NULL DEFAULT 0,
        share_count INT NOT NULL DEFAULT 0,
        published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        emailed_at TIMESTAMP NULL,
        regenerated_at TIMESTAMP NULL,
        INDEX idx_year_week (year_week),
        INDEX idx_published (published_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS weekly_rewind_deliveries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rewind_id INT NOT NULL,
        recipient_kind ENUM('subscriber','user') NOT NULL DEFAULT 'subscriber',
        recipient_id INT NOT NULL,
        delivered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        opened_at TIMESTAMP NULL,
        clicked_at TIMESTAMP NULL,
        UNIQUE KEY uniq_send (rewind_id, recipient_kind, recipient_id),
        INDEX idx_rewind (rewind_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ensured = true;
}

/** Resolve the ISO year-week label (e.g. "2026-17") for a given date. */
function wr_year_week_for(int $ts): string {
    return date('o-W', $ts);
}

/** Saturday-to-Friday window for a given ISO year-week (Sun-of-week-1 anchor). */
function wr_dates_for_year_week(string $yearWeek): array {
    [$year, $week] = array_map('intval', explode('-', $yearWeek));
    $weekStr = str_pad((string)$week, 2, '0', STR_PAD_LEFT);
    // ISO week starts Monday; we want Saturday as first to match an
    // Arab newsroom week. Compute Monday, then back up two days.
    $monday = new DateTime();
    $monday->setISODate($year, (int)$weekStr);
    $start = clone $monday;
    $start->modify('-2 days');                  // Saturday
    $end   = clone $start;
    $end->modify('+6 days');                     // Friday
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function wr_save(string $yearWeek, array $payload): int {
    wr_ensure_table();
    $db = getDB();
    [$start, $end] = wr_dates_for_year_week($yearWeek);
    $existing = wr_get_by_week($yearWeek);

    $params = [
        ':yw'        => $yearWeek,
        ':start'     => $start,
        ':end'       => $end,
        ':title'     => (string)($payload['cover_title']    ?? ''),
        ':subtitle'  => (string)($payload['cover_subtitle'] ?? ''),
        ':cover'     => (string)($payload['cover_image_url']?? ''),
        ':intro'     => (string)($payload['intro_text']     ?? ''),
        ':content'   => json_encode($payload['content']     ?? [], JSON_UNESCAPED_UNICODE),
        ':stats'     => json_encode($payload['stats']       ?? [], JSON_UNESCAPED_UNICODE),
        ':ids'       => implode(',', array_map('intval', (array)($payload['article_ids'] ?? []))),
    ];

    if ($existing) {
        $params[':id'] = (int)$existing['id'];
        $sql = "UPDATE weekly_rewinds SET
                  cover_title=:title, cover_subtitle=:subtitle, cover_image_url=:cover,
                  intro_text=:intro, content_json=:content, stats_json=:stats,
                  article_ids=:ids, regenerated_at=NOW()
                WHERE id=:id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$existing['id'];
    }

    $sql = "INSERT INTO weekly_rewinds
              (year_week, start_date, end_date, cover_title, cover_subtitle,
               cover_image_url, intro_text, content_json, stats_json, article_ids)
            VALUES
              (:yw, :start, :end, :title, :subtitle, :cover, :intro, :content, :stats, :ids)";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$db->lastInsertId();
}

function wr_hydrate(array $row): array {
    $content = json_decode((string)($row['content_json'] ?? '[]'), true);
    $stats   = json_decode((string)($row['stats_json']   ?? '{}'), true);
    $ids     = array_filter(array_map('intval', explode(',', (string)($row['article_ids'] ?? ''))));
    return [
        'id'              => (int)$row['id'],
        'year_week'       => (string)$row['year_week'],
        'start_date'      => (string)$row['start_date'],
        'end_date'        => (string)$row['end_date'],
        'cover_title'     => (string)$row['cover_title'],
        'cover_subtitle'  => (string)$row['cover_subtitle'],
        'cover_image_url' => (string)$row['cover_image_url'],
        'intro_text'      => (string)$row['intro_text'],
        'content'         => is_array($content) ? $content : [],
        'stats'           => is_array($stats)   ? $stats   : [],
        'article_ids'     => array_values($ids),
        'view_count'      => (int)$row['view_count'],
        'share_count'     => (int)$row['share_count'],
        'published_at'    => (string)$row['published_at'],
        'emailed_at'      => (string)($row['emailed_at'] ?? ''),
    ];
}

function wr_get_by_week(string $yearWeek): ?array {
    wr_ensure_table();
    $stmt = getDB()->prepare("SELECT * FROM weekly_rewinds WHERE year_week = ?");
    $stmt->execute([$yearWeek]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? wr_hydrate($row) : null;
}

function wr_get_latest(): ?array {
    wr_ensure_table();
    $row = getDB()->query("SELECT * FROM weekly_rewinds ORDER BY published_at DESC LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC);
    return $row ? wr_hydrate($row) : null;
}

function wr_list(int $limit = 20): array {
    wr_ensure_table();
    $limit = max(1, min(100, $limit));
    $rows = getDB()->query("SELECT * FROM weekly_rewinds
                            ORDER BY published_at DESC LIMIT {$limit}")
                   ->fetchAll(PDO::FETCH_ASSOC);
    return array_map('wr_hydrate', $rows ?: []);
}

function wr_bump_views(int $id): void {
    try {
        $stmt = getDB()->prepare("UPDATE weekly_rewinds SET view_count = view_count + 1 WHERE id = ?");
        $stmt->execute([$id]);
    } catch (Throwable $e) {}
}

function wr_mark_emailed(int $id): void {
    $stmt = getDB()->prepare("UPDATE weekly_rewinds SET emailed_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
}

} // function_exists guard
