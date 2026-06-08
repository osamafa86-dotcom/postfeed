<?php
/**
 * User-owned sources — the foundation of "ابنِ صحيفتك" (build your own feed).
 *
 * Users add their own sources (websites / RSS / Telegram / X / YouTube) and
 * the platform ingests + AI-processes them the same way it does system
 * sources. This file is the data layer: detection + CRUD.
 *
 * Table `user_sources` is created idempotently by migrate.php.
 */

require_once __DIR__ . '/config.php';

/**
 * Figure out the source type + canonical url/handle/name from a pasted
 * string (a URL or an @handle). Returns null when nothing recognizable.
 *
 * type ∈ rss | website | telegram | x | youtube
 */
function user_source_detect(string $in): ?array {
    $in = trim($in);
    if ($in === '') return null;
    $low = mb_strtolower($in);

    // Telegram
    if (strpos($low, 't.me/') !== false || strpos($low, 'telegram.me/') !== false) {
        $h = null;
        if (preg_match('#t(?:elegram)?\.me/(?:s/)?@?([A-Za-z0-9_]{3,})#', $in, $m)) $h = $m[1];
        return ['type' => 'telegram', 'name' => $h ? ('@' . $h) : 'قناة تلغرام',
                'url' => 'https://t.me/' . ($h ?: ''), 'handle' => $h ? ('@' . $h) : null];
    }
    // X / Twitter
    if (strpos($low, 'twitter.com/') !== false || strpos($low, 'x.com/') !== false) {
        $h = null;
        if (preg_match('#(?:twitter|x)\.com/@?([A-Za-z0-9_]{1,30})#', $in, $m)) $h = $m[1];
        return ['type' => 'x', 'name' => $h ? ('@' . $h) : 'حساب إكس',
                'url' => 'https://x.com/' . ($h ?: ''), 'handle' => $h ? ('@' . $h) : null];
    }
    // YouTube
    if (strpos($low, 'youtube.com/') !== false || strpos($low, 'youtu.be/') !== false) {
        $h = null;
        if (preg_match('#youtube\.com/(?:@|channel/|c/|user/)?([A-Za-z0-9_\-]{2,})#', $in, $m)) $h = $m[1];
        return ['type' => 'youtube', 'name' => $h ? ('قناة ' . $h) : 'قناة يوتيوب',
                'url' => (preg_match('#^https?://#i', $in) ? $in : ('https://' . $in)),
                'handle' => $h ? ('@' . ltrim($h, '@')) : null];
    }
    // bare @handle → default to X (most common bare handle)
    if (preg_match('#^@([A-Za-z0-9_]{2,30})$#', $in, $m)) {
        return ['type' => 'x', 'name' => '@' . $m[1],
                'url' => 'https://x.com/' . $m[1], 'handle' => '@' . $m[1]];
    }
    // explicit RSS / Atom feed
    if (preg_match('#\.(xml|rss|atom)(\?|#|$)#i', $in)
        || strpos($low, '/feed') !== false || strpos($low, '/rss') !== false) {
        $url  = preg_match('#^https?://#i', $in) ? $in : ('https://' . $in);
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        return ['type' => 'rss', 'name' => preg_replace('#^www\.#', '', $host),
                'url' => $url, 'handle' => null];
    }
    // generic site (RSS auto-discovery happens later, at ingest time)
    if (preg_match('#^https?://#i', $in) || preg_match('#^[a-z0-9.\-]+\.[a-z]{2,}#i', $low)) {
        $url  = preg_match('#^https?://#i', $in) ? $in : ('https://' . $in);
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        return ['type' => 'website', 'name' => preg_replace('#^www\.#', '', $host),
                'url' => $url, 'handle' => null];
    }
    return null;
}

/** Arabic label + brand color for a source type (UI helper). */
function user_source_meta(string $type): array {
    switch ($type) {
        case 'rss':      return ['label' => 'RSS · موقع', 'color' => '#E8820B'];
        case 'website':  return ['label' => 'موقع',       'color' => '#5B7F3B'];
        case 'telegram': return ['label' => 'تلغرام',     'color' => '#229ED9'];
        case 'x':        return ['label' => 'إكس',         'color' => '#15181C'];
        case 'youtube':  return ['label' => 'يوتيوب',     'color' => '#E0281E'];
        default:         return ['label' => 'مصدر',        'color' => '#5B7F3B'];
    }
}

/** Self-healing: create the table on first use so the feature works even
 *  before migrate.php is run on the server. Runs at most once per request. */
function user_sources_ensure(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS user_sources (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(20) NOT NULL,
            name VARCHAR(200) NOT NULL,
            url VARCHAR(500) NOT NULL,
            handle VARCHAR(150) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_fetched_at TIMESTAMP NULL DEFAULT NULL,
            article_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_url (user_id, url(191)),
            KEY idx_user_active (user_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* lacking CREATE priv → migrate.php covers it */ }
}

function user_sources_list(int $uid): array {
    user_sources_ensure();
    try {
        $db = getDB();
        $st = $db->prepare("SELECT * FROM user_sources WHERE user_id = ? ORDER BY is_active DESC, created_at DESC");
        $st->execute([$uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

function user_sources_count(int $uid, bool $activeOnly = false): int {
    user_sources_ensure();
    try {
        $db = getDB();
        $sql = "SELECT COUNT(*) FROM user_sources WHERE user_id = ?" . ($activeOnly ? " AND is_active = 1" : "");
        $st = $db->prepare($sql);
        $st->execute([$uid]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Add a source from a pasted string. Returns the row on success. */
function user_source_add(int $uid, string $in): array {
    $d = user_source_detect($in);
    if (!$d) return ['ok' => false, 'error' => 'unrecognized'];
    user_sources_ensure();
    if (user_sources_count($uid) >= 100) return ['ok' => false, 'error' => 'limit'];
    $db = getDB();
    try {
        $st = $db->prepare("INSERT INTO user_sources (user_id, type, name, url, handle, is_active)
                            VALUES (?, ?, ?, ?, ?, 1)");
        $st->execute([$uid, $d['type'], mb_substr($d['name'], 0, 200), mb_substr($d['url'], 0, 500), $d['handle']]);
        $d['id'] = (int)$db->lastInsertId();
        $d['is_active'] = 1;
        $d['ok'] = true;
        return $d;
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'duplicate'];
    }
}

function user_source_set_active(int $uid, int $id, bool $on): void {
    $db = getDB();
    $st = $db->prepare("UPDATE user_sources SET is_active = ? WHERE id = ? AND user_id = ?");
    $st->execute([$on ? 1 : 0, $id, $uid]);
}

function user_source_delete(int $uid, int $id): void {
    $db = getDB();
    $st = $db->prepare("DELETE FROM user_sources WHERE id = ? AND user_id = ?");
    $st->execute([$id, $uid]);
}
