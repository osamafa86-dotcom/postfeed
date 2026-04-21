<?php
/**
 * Unified migrations runner.
 * CLI: php migrate.php
 * HTTP: migrate.php?key=CRON_KEY
 *
 * Idempotent — safe to run repeatedly.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/article_cluster.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$db = getDB();
$applied = [];

function col_exists($db, $table, $col) {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $col]);
    return (bool) $stmt->fetchColumn();
}
function idx_exists($db, $table, $name) {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $name]);
    return (bool) $stmt->fetchColumn();
}
function add_col($db, $table, $col, $ddl, &$applied) {
    if (!col_exists($db, $table, $col)) {
        $db->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
        $applied[] = "+ $table.$col";
    }
}
function add_idx($db, $table, $name, $cols, &$applied) {
    if (!idx_exists($db, $table, $name)) {
        $db->exec("CREATE INDEX `$name` ON `$table` ($cols)");
        $applied[] = "+ idx $table.$name";
    }
}

// ---------- sources tracking ----------
add_col($db, 'sources', 'last_fetched_at', 'last_fetched_at TIMESTAMP NULL DEFAULT NULL', $applied);
add_col($db, 'sources', 'last_error',      'last_error VARCHAR(500) DEFAULT NULL', $applied);
add_col($db, 'sources', 'last_new_count',  'last_new_count INT DEFAULT 0', $applied);
add_col($db, 'sources', 'total_articles',  'total_articles INT DEFAULT 0', $applied);
add_col($db, 'sources', 'cover_image',     'cover_image VARCHAR(500) DEFAULT NULL', $applied);
add_col($db, 'sources', 'followers_count', 'followers_count INT DEFAULT 0', $applied);

// ---------- articles AI columns ----------
add_col($db, 'articles', 'ai_summary',      'ai_summary TEXT', $applied);
add_col($db, 'articles', 'ai_key_points',   'ai_key_points TEXT', $applied);
add_col($db, 'articles', 'ai_keywords',     'ai_keywords VARCHAR(500)', $applied);
add_col($db, 'articles', 'ai_processed_at', 'ai_processed_at TIMESTAMP NULL', $applied);
add_col($db, 'articles', 'source_url',      'source_url VARCHAR(1000) DEFAULT NULL', $applied);
add_col($db, 'articles', 'cluster_key',     'cluster_key VARCHAR(64) NULL', $applied);

// ---------- performance indexes ----------
add_idx($db, 'articles', 'idx_status_pub',     '`status`, `published_at` DESC',                       $applied);
add_idx($db, 'articles', 'idx_cat_status_pub', '`category_id`, `status`, `published_at` DESC',        $applied);
add_idx($db, 'articles', 'idx_src_status_pub', '`source_id`, `status`, `published_at` DESC',          $applied);
add_idx($db, 'articles', 'idx_breaking_pub',   '`is_breaking`, `published_at` DESC',                  $applied);
add_idx($db, 'articles', 'idx_hero_pub',       '`is_hero`, `published_at` DESC',                      $applied);
add_idx($db, 'articles', 'idx_ai_null',        '`ai_summary`(1)',                                     $applied);
add_idx($db, 'articles', 'idx_cluster_key',    '`cluster_key`',                                       $applied);
// Composite variants that include `status` — the homepage/hero/breaking
// queries all filter by status='published' alongside the flag. MySQL can
// pick these over the flag-only indexes to avoid the extra filesort step.
add_idx($db, 'articles', 'idx_hero_status_pub',     '`is_hero`, `status`, `published_at` DESC',      $applied);
add_idx($db, 'articles', 'idx_breaking_status_pub', '`is_breaking`, `status`, `published_at` DESC',  $applied);
add_idx($db, 'articles', 'idx_cluster_status_pub',  '`cluster_key`, `status`, `published_at` DESC',  $applied);

// ---------- telegram_messages performance ----------
// The Telegram feed + summary cron scan by (is_active, posted_at) very
// frequently. A composite index avoids the full-range sort on big archives.
add_idx($db, 'telegram_messages', 'idx_active_posted', '`is_active`, `posted_at` DESC', $applied);

// ---------- telegram tables ----------
$db->exec("CREATE TABLE IF NOT EXISTS telegram_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(150),
    avatar_url VARCHAR(500),
    is_active TINYINT(1) DEFAULT 1,
    last_fetched_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS telegram_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_id INT NOT NULL,
    post_id VARCHAR(100) NOT NULL,
    text TEXT,
    image_url VARCHAR(500),
    post_url VARCHAR(500),
    posted_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_source_post (source_id, post_id),
    INDEX idx_posted (posted_at DESC),
    FOREIGN KEY (source_id) REFERENCES telegram_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ---------- twitter sources & tweets ----------
// Mirrors the telegram_* tables. We scrape X/Twitter via the public
// syndication endpoint (same one Twitter's embed widgets use), so no
// API key or paid plan is required. Tweet IDs are snowflake BIGINT,
// stored as VARCHAR to avoid 32-bit overflow on shared hosting.
$db->exec("CREATE TABLE IF NOT EXISTS twitter_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(150) NOT NULL,
    avatar_url VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    last_fetched_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS twitter_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_id INT NOT NULL,
    tweet_id VARCHAR(32) NOT NULL,
    post_url VARCHAR(500) NOT NULL,
    text TEXT,
    image_url VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    posted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tweet (source_id, tweet_id),
    INDEX idx_posted (posted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ---------- newsletter subscribers ----------
// Daily digest sign-ups: double opt-in via confirm_token, one-click
// unsubscribe via unsubscribe_token. last_sent_at is bumped by
// cron_newsletter.php so we can throttle / show "next send" in admin.
$db->exec("CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    confirmed TINYINT(1) NOT NULL DEFAULT 0,
    confirm_token VARCHAR(64) NOT NULL,
    unsubscribe_token VARCHAR(64) NOT NULL,
    subscribed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TIMESTAMP NULL,
    last_sent_at TIMESTAMP NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    INDEX idx_confirmed (confirmed),
    INDEX idx_confirm_token (confirm_token),
    INDEX idx_unsubscribe_token (unsubscribe_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Track each digest send so we can show stats in the admin and avoid
// double-sending if cron runs twice in the same window.
$db->exec("CREATE TABLE IF NOT EXISTS newsletter_sends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    subject VARCHAR(255) NOT NULL,
    article_count INT NOT NULL DEFAULT 0,
    recipient_count INT NOT NULL DEFAULT 0,
    success_count INT NOT NULL DEFAULT 0,
    fail_count INT NOT NULL DEFAULT 0,
    INDEX idx_sent (sent_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ---------- trending now (velocity tracking) ----------
// One row per page view, pruned to a 48h window. Used by
// includes/trending.php to compute velocity scores for the
// "🔥 الأكثر تداولاً الآن" rail. Two indexes: viewed_at for the
// time-window scan, and (article_id, viewed_at) for per-article
// lookups (debug pages, future "trending in category" rails).
$db->exec("CREATE TABLE IF NOT EXISTS article_view_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_viewed_at (viewed_at),
    INDEX idx_article_viewed (article_id, viewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ---------- reels tables ----------
$db->exec("CREATE TABLE IF NOT EXISTS reels_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(150),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS reels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_id INT,
    title VARCHAR(500),
    video_url VARCHAR(500),
    thumbnail_url VARCHAR(500),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ---------- backfill cluster_key for legacy rows ----------
// Walks rows in chronological order so each new article can fuzzy-match
// against the keys we already assigned earlier in the same run via
// cluster_assign(). Processes batches of 2000 per request — re-running
// migrate.php picks up where the last batch stopped (NULL filter), so a
// huge archive backfills incrementally without timing out.
//
// To force a re-cluster (e.g. after improving the tokenization rules)
// hit /migrate.php?key=...&recluster=1 once and it will reset the column
// before this block runs.
try {
    if (col_exists($db, 'articles', 'cluster_key')) {
        if (!empty($_GET['recluster'])) {
            $db->exec("UPDATE articles SET cluster_key = NULL");
            $applied[] = "+ cluster_key reset (recluster requested)";
        }

        $bf = $db->prepare("SELECT id, title FROM articles
                             WHERE cluster_key IS NULL AND title IS NOT NULL AND title <> ''
                             ORDER BY published_at ASC
                             LIMIT 2000");
        $bf->execute();
        $rows = $bf->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            // Seed the fuzzy index with whatever has already been
            // clustered (from prior batches or live cron inserts).
            $idx = cluster_index_build($db, 30, 1500);
            $upd = $db->prepare("UPDATE articles SET cluster_key = ? WHERE id = ?");
            $done = 0;
            foreach ($rows as $row) {
                $key = cluster_assign((string)$row['title'], $idx);
                $upd->execute([$key, (int)$row['id']]);
                $done++;
            }
            $applied[] = "+ backfilled cluster_key for $done rows";
        }
    }
} catch (Throwable $e) {
    $applied[] = "! cluster backfill skipped: " . $e->getMessage();
}

// --- FULLTEXT index on articles for search ---
try {
    $hasIdx = $db->query("SHOW INDEX FROM articles WHERE Key_name = 'ft_title_excerpt'")->fetch();
    if (!$hasIdx) {
        $db->exec("ALTER TABLE articles ADD FULLTEXT INDEX ft_title_excerpt (title, excerpt)");
        $applied[] = "+ added FULLTEXT index ft_title_excerpt on articles(title, excerpt)";
    }
} catch (Throwable $e) {
    $applied[] = "! FULLTEXT index skipped: " . $e->getMessage();
}

// ---------- evolving stories (admin-defined persistent topics) ----------
// Creates the tables and, on first deploy, seeds the 5 initial
// stories (الأقصى، الأسرى، غزة، الضفة، الاستيطان). Subsequent runs
// are no-ops — the INSERT IGNORE on slug guarantees we never
// overwrite admin edits.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS evolving_stories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        slug VARCHAR(150) NOT NULL,
        description TEXT NULL,
        icon VARCHAR(20) NOT NULL DEFAULT '',
        cover_image VARCHAR(500) NULL,
        accent_color VARCHAR(20) NOT NULL DEFAULT '#0d9488',
        keywords TEXT NOT NULL,
        exclude_keywords TEXT NULL,
        min_match_score TINYINT UNSIGNED NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        article_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_matched_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_slug (slug),
        KEY idx_active_order (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS evolving_story_articles (
        story_id INT NOT NULL,
        article_id INT NOT NULL,
        match_score TINYINT UNSIGNED NOT NULL DEFAULT 1,
        matched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (story_id, article_id),
        KEY idx_article (article_id),
        KEY idx_story_matched (story_id, matched_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed the five initial stories on first deploy. INSERT IGNORE
    // so admin edits to slug/name are preserved across migrations.
    $seedStories = [
        ['أخبار الأقصى', 'al-aqsa', 'كل ما يتعلق بالمسجد الأقصى المبارك وقبة الصخرة وساحات الحرم القدسي — اقتحامات، صلوات، قرارات، وردود الأفعال.', '🕌', '#d97706',
            ["الأقصى","المسجد الأقصى","قبة الصخرة","الحرم القدسي","باب العمود","باب الأسباط","اقتحام الأقصى","اقتحام المسجد","ساحات الأقصى","المصلى القبلي","المصلى المرواني"], 1],
        ['أخبار الأسرى', 'prisoners', 'آخر المستجدات المتعلقة بالأسرى الفلسطينيين في سجون الاحتلال — اعتقالات، إضرابات، صفقات تبادل، وشهادات.', '⛓️', '#475569',
            ["الأسرى","الأسير","الأسيرات","أسير فلسطيني","المعتقلين","معتقل إداري","الاعتقال الإداري","صفقة تبادل","صفقة التبادل","إضراب عن الطعام","نادي الأسير","هيئة الأسرى","سجون الاحتلال","سجن عوفر","سجن النقب","سجن مجدو"], 2],
        ['أخبار غزة', 'gaza', 'تغطية شاملة لقطاع غزة — العدوان، المساعدات، الهدنة، والمواقف الإقليمية والدولية.', '🇵🇸', '#dc2626',
            ["غزة","قطاع غزة","رفح","خان يونس","بيت حانون","بيت لاهيا","جباليا","الشجاعية","دير البلح","النصيرات","المغازي","البريج","مخيم الشاطئ","شمال غزة","جنوب غزة","معبر رفح","معبر كرم أبو سالم"], 3],
        ['أخبار الضفة', 'west-bank', 'مستجدات الضفة الغربية — اقتحامات، اعتقالات، عمليات، وحياة المدن والقرى تحت الاحتلال.', '🏔️', '#059669',
            ["الضفة","الضفة الغربية","نابلس","جنين","مخيم جنين","رام الله","الخليل","طولكرم","قلقيلية","بيت لحم","أريحا","سلفيت","طوباس","بيتا","حوارة","مخيم بلاطة","مخيم نور شمس"], 4],
        ['الاستيطان', 'settlements', 'تتبّع المشروع الاستيطاني الإسرائيلي في الضفة والقدس — بناء، مصادرة، بؤر، واعتداءات المستوطنين.', '🏗️', '#7c2d12',
            ["مستوطن","مستوطنين","المستوطنين","الاستيطان","استيطان","مستوطنة","مستوطنات","بؤرة استيطانية","بؤر استيطانية","قافلة استيطانية","اعتداءات المستوطنين","إرهاب المستوطنين","وحدات استيطانية","معاليه أدوميم","كريات أربع","يتسهار"], 5],
    ];
    $seedStmt = $db->prepare("INSERT IGNORE INTO evolving_stories
        (name, slug, description, icon, accent_color, keywords, min_match_score, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
    $seeded = 0;
    foreach ($seedStories as $s) {
        $seedStmt->execute([
            $s[0], $s[1], $s[2], $s[3], $s[4],
            json_encode($s[5], JSON_UNESCAPED_UNICODE),
            $s[6]
        ]);
        if ($seedStmt->rowCount() > 0) $seeded++;
    }
    if ($seeded > 0) {
        $applied[] = "+ seeded $seeded evolving stories";
    }
} catch (Throwable $e) {
    $applied[] = "! evolving stories skipped: " . $e->getMessage();
}

if (empty($applied)) {
    echo "✓ لا تغييرات — قاعدة البيانات محدّثة\n";
} else {
    echo "تم تطبيق:\n" . implode("\n", $applied) . "\n";
}
