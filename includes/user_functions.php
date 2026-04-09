<?php
/**
 * نيوزفلو - دوال المستخدم (قارئ)
 * Helper functions backing the user dashboard, save button, and personalization.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/user_auth.php';

// ============================================
// Bookmarks
// ============================================

function user_bookmark_ids_for(int $userId, array $articleIds): array {
    if (!$articleIds) return [];
    $ids = array_values(array_unique(array_map('intval', $articleIds)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT article_id FROM user_bookmarks WHERE user_id = ? AND article_id IN ($placeholders)");
        $stmt->execute(array_merge([$userId], $ids));
        return array_map('intval', array_column($stmt->fetchAll(), 'article_id'));
    } catch (Throwable $e) { return []; }
}

function user_bookmark_toggle(int $userId, int $articleId): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM user_bookmarks WHERE user_id = ? AND article_id = ?");
    $stmt->execute([$userId, $articleId]);
    if ($row = $stmt->fetch()) {
        $db->prepare("DELETE FROM user_bookmarks WHERE id = ?")->execute([$row['id']]);
        return false;
    }
    $db->prepare("INSERT IGNORE INTO user_bookmarks (user_id, article_id) VALUES (?, ?)")
       ->execute([$userId, $articleId]);
    return true;
}

function user_bookmark_count(int $userId): int {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM user_bookmarks WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function user_bookmarks(int $userId, int $limit = 30, int $offset = 0): array {
    try {
        $db = getDB();
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                                     s.name as source_name, s.logo_color,
                                     b.created_at as saved_at
                              FROM user_bookmarks b
                              INNER JOIN articles a ON a.id = b.article_id
                              LEFT JOIN categories c ON a.category_id = c.id
                              LEFT JOIN sources s ON a.source_id = s.id
                              WHERE b.user_id = ?
                              ORDER BY b.created_at DESC
                              LIMIT $limit OFFSET $offset");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) { return []; }
}

// ============================================
// Follows (categories + sources)
// ============================================

function user_followed_category_ids(int $userId): array {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT category_id FROM user_category_follows WHERE user_id = ? ORDER BY priority ASC, id ASC");
        $stmt->execute([$userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'category_id'));
    } catch (Throwable $e) { return []; }
}

function user_followed_categories(int $userId): array {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT c.*, f.priority FROM user_category_follows f
                              INNER JOIN categories c ON c.id = f.category_id
                              WHERE f.user_id = ?
                              ORDER BY f.priority ASC, f.id ASC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) { return []; }
}

function user_follow_category(int $userId, int $categoryId): void {
    $db = getDB();
    $max = (int)$db->query("SELECT COALESCE(MAX(priority),0) FROM user_category_follows WHERE user_id = $userId")->fetchColumn();
    $db->prepare("INSERT IGNORE INTO user_category_follows (user_id, category_id, priority) VALUES (?, ?, ?)")
       ->execute([$userId, $categoryId, $max + 1]);
}

function user_unfollow_category(int $userId, int $categoryId): void {
    $db = getDB();
    $db->prepare("DELETE FROM user_category_follows WHERE user_id = ? AND category_id = ?")
       ->execute([$userId, $categoryId]);
}

function user_reorder_categories(int $userId, array $orderedCategoryIds): void {
    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE user_category_follows SET priority = ? WHERE user_id = ? AND category_id = ?");
        foreach ($orderedCategoryIds as $i => $cid) {
            $stmt->execute([$i, $userId, (int)$cid]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function user_followed_source_ids(int $userId): array {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT source_id FROM user_source_follows WHERE user_id = ?");
        $stmt->execute([$userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'source_id'));
    } catch (Throwable $e) { return []; }
}

function user_follow_source(int $userId, int $sourceId): void {
    $db = getDB();
    $db->prepare("INSERT IGNORE INTO user_source_follows (user_id, source_id) VALUES (?, ?)")
       ->execute([$userId, $sourceId]);
}

function user_unfollow_source(int $userId, int $sourceId): void {
    $db = getDB();
    $db->prepare("DELETE FROM user_source_follows WHERE user_id = ? AND source_id = ?")
       ->execute([$userId, $sourceId]);
}

// ============================================
// Personalized feed
// ============================================

function user_personal_feed(int $userId, int $limit = 12): array {
    $catIds = user_followed_category_ids($userId);
    $srcIds = user_followed_source_ids($userId);

    if (!$catIds && !$srcIds) {
        // Fallback to latest
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                                         s.name as source_name, s.logo_color
                                  FROM articles a
                                  LEFT JOIN categories c ON a.category_id = c.id
                                  LEFT JOIN sources s ON a.source_id = s.id
                                  WHERE a.status = 'published'
                                  ORDER BY a.published_at DESC LIMIT ?");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    $where = [];
    $params = [];
    if ($catIds) {
        $where[] = 'a.category_id IN (' . implode(',', array_fill(0, count($catIds), '?')) . ')';
        $params = array_merge($params, $catIds);
    }
    if ($srcIds) {
        $where[] = 'a.source_id IN (' . implode(',', array_fill(0, count($srcIds), '?')) . ')';
        $params = array_merge($params, $srcIds);
    }
    $whereSql = '(' . implode(' OR ', $where) . ')';
    $params[] = $limit;

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                                     s.name as source_name, s.logo_color
                              FROM articles a
                              LEFT JOIN categories c ON a.category_id = c.id
                              LEFT JOIN sources s ON a.source_id = s.id
                              WHERE a.status = 'published' AND $whereSql
                              ORDER BY a.published_at DESC LIMIT ?");
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) { return []; }
}

// ============================================
// Reading history + streak
// ============================================

function user_log_read(int $userId, int $articleId): void {
    try {
        $db = getDB();
        $db->prepare("INSERT INTO user_reading_history (user_id, article_id) VALUES (?, ?)")
           ->execute([$userId, $articleId]);

        // Update streak
        $today = date('Y-m-d');
        $stmt = $db->prepare("SELECT reading_streak, last_read_date FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if (!$u) return;

        $streak = (int)($u['reading_streak'] ?? 0);
        $last = $u['last_read_date'] ?? null;
        if ($last === $today) return;

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $newStreak = ($last === $yesterday) ? ($streak + 1) : 1;
        $db->prepare("UPDATE users SET reading_streak = ?, last_read_date = ? WHERE id = ?")
           ->execute([$newStreak, $today, $userId]);
    } catch (Throwable $e) {}
}

function user_reading_history(int $userId, int $limit = 30): array {
    try {
        $db = getDB();
        $limit = max(1, min(100, $limit));
        $stmt = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                                     s.name as source_name, s.logo_color,
                                     h.read_at
                              FROM (
                                  SELECT article_id, MAX(read_at) AS read_at
                                  FROM user_reading_history
                                  WHERE user_id = ?
                                  GROUP BY article_id
                                  ORDER BY read_at DESC
                                  LIMIT $limit
                              ) h
                              INNER JOIN articles a ON a.id = h.article_id
                              LEFT JOIN categories c ON a.category_id = c.id
                              LEFT JOIN sources s ON a.source_id = s.id
                              ORDER BY h.read_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) { return []; }
}

// ============================================
// Notifications
// ============================================

function user_notify(int $userId, string $title, string $body = '', string $link = '', ?int $articleId = null, string $type = 'info', string $icon = '🔔'): void {
    try {
        $db = getDB();
        $db->prepare("INSERT INTO user_notifications (user_id, type, title, body, link, article_id, icon) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$userId, $type, $title, $body, $link, $articleId, $icon]);
    } catch (Throwable $e) {}
}

function user_notifications(int $userId, int $limit = 20): array {
    try {
        $db = getDB();
        $limit = max(1, min(50, $limit));
        $stmt = $db->prepare("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) { return []; }
}

function user_unread_notifications_count(int $userId): int {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function user_notifications_mark_all_read(int $userId): void {
    try {
        $db = getDB();
        $db->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ?")->execute([$userId]);
    } catch (Throwable $e) {}
}

function user_notification_mark_read(int $userId, int $notifId): void {
    try {
        $db = getDB();
        $db->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
           ->execute([$notifId, $userId]);
    } catch (Throwable $e) {}
}

// ============================================
// Stats / achievements
// ============================================

function user_stats(int $userId): array {
    $db = getDB();
    $stats = [
        'bookmarks' => 0, 'followed_categories' => 0, 'followed_sources' => 0,
        'reads_total' => 0, 'reads_today' => 0, 'reads_week' => 0, 'streak' => 0,
        'top_category' => null,
    ];
    try {
        $stats['bookmarks'] = (int)$db->query("SELECT COUNT(*) FROM user_bookmarks WHERE user_id = $userId")->fetchColumn();
        $stats['followed_categories'] = (int)$db->query("SELECT COUNT(*) FROM user_category_follows WHERE user_id = $userId")->fetchColumn();
        $stats['followed_sources'] = (int)$db->query("SELECT COUNT(*) FROM user_source_follows WHERE user_id = $userId")->fetchColumn();
        $stats['reads_total'] = (int)$db->query("SELECT COUNT(DISTINCT article_id) FROM user_reading_history WHERE user_id = $userId")->fetchColumn();
        $stats['reads_today'] = (int)$db->query("SELECT COUNT(DISTINCT article_id) FROM user_reading_history WHERE user_id = $userId AND read_at >= CURDATE()")->fetchColumn();
        $stats['reads_week']  = (int)$db->query("SELECT COUNT(DISTINCT article_id) FROM user_reading_history WHERE user_id = $userId AND read_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
        $stmt = $db->prepare("SELECT reading_streak FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $stats['streak'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT c.name, COUNT(*) as c
                              FROM user_reading_history h
                              INNER JOIN articles a ON a.id = h.article_id
                              LEFT JOIN categories c ON c.id = a.category_id
                              WHERE h.user_id = ? AND c.name IS NOT NULL
                              GROUP BY c.name ORDER BY c DESC LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $stats['top_category'] = $row['name'] ?? null;
    } catch (Throwable $e) {}
    return $stats;
}

function user_achievements(array $stats): array {
    $all = [
        ['key' => 'first_read',   'title' => 'أول قراءة',        'icon' => '📖', 'desc' => 'اقرأ أول خبر',           'cond' => $stats['reads_total'] >= 1],
        ['key' => 'ten_reads',    'title' => 'قارئ نشط',         'icon' => '🔥', 'desc' => 'اقرأ 10 أخبار',         'cond' => $stats['reads_total'] >= 10],
        ['key' => 'century',      'title' => 'قارئ محترف',       'icon' => '🏆', 'desc' => 'اقرأ 100 خبر',          'cond' => $stats['reads_total'] >= 100],
        ['key' => 'first_save',   'title' => 'أول حفظ',          'icon' => '🔖', 'desc' => 'احفظ مقال',              'cond' => $stats['bookmarks'] >= 1],
        ['key' => 'streak_3',     'title' => 'استمرارية 3 أيام', 'icon' => '⚡', 'desc' => '3 أيام متتالية قراءة',   'cond' => $stats['streak'] >= 3],
        ['key' => 'streak_7',     'title' => 'أسبوع كامل',       'icon' => '🌟', 'desc' => '7 أيام قراءة متتالية',   'cond' => $stats['streak'] >= 7],
        ['key' => 'curator',      'title' => 'مُنسّق',            'icon' => '🎯', 'desc' => 'تابع 5 أقسام',           'cond' => $stats['followed_categories'] >= 5],
        ['key' => 'explorer',     'title' => 'مستكشف',           'icon' => '🧭', 'desc' => 'تابع 3 مصادر',           'cond' => $stats['followed_sources'] >= 3],
    ];
    return $all;
}

// ============================================
// Comments
// ============================================

function article_comments(int $articleId, ?int $viewerId = null): array {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT c.*, u.name as user_name, u.avatar_letter
                              FROM article_comments c
                              INNER JOIN users u ON u.id = c.user_id
                              WHERE c.article_id = ? AND c.is_hidden = 0
                              ORDER BY c.created_at DESC LIMIT 100");
        $stmt->execute([$articleId]);
        $rows = $stmt->fetchAll();
        if ($viewerId && $rows) {
            $ids = array_map(fn($r) => (int)$r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT comment_id FROM comment_likes WHERE user_id = ? AND comment_id IN ($placeholders)");
            $stmt->execute(array_merge([$viewerId], $ids));
            $likedSet = array_flip(array_map('intval', array_column($stmt->fetchAll(), 'comment_id')));
            foreach ($rows as &$r) $r['viewer_liked'] = isset($likedSet[(int)$r['id']]);
        }
        return $rows;
    } catch (Throwable $e) { return []; }
}

function article_comment_count(int $articleId): int {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM article_comments WHERE article_id = ? AND is_hidden = 0");
        $stmt->execute([$articleId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function add_article_comment(int $articleId, int $userId, string $body): ?int {
    $body = trim($body);
    if ($body === '' || mb_strlen($body) < 2 || mb_strlen($body) > 2000) return null;
    try {
        $db = getDB();
        $db->prepare("INSERT INTO article_comments (article_id, user_id, body) VALUES (?, ?, ?)")
           ->execute([$articleId, $userId, $body]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) { return null; }
}

function toggle_comment_like(int $userId, int $commentId): array {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT 1 FROM comment_likes WHERE user_id = ? AND comment_id = ?");
        $stmt->execute([$userId, $commentId]);
        if ($stmt->fetchColumn()) {
            $db->prepare("DELETE FROM comment_likes WHERE user_id = ? AND comment_id = ?")->execute([$userId, $commentId]);
            $db->prepare("UPDATE article_comments SET likes = GREATEST(0, likes - 1) WHERE id = ?")->execute([$commentId]);
            $liked = false;
        } else {
            $db->prepare("INSERT IGNORE INTO comment_likes (user_id, comment_id) VALUES (?, ?)")->execute([$userId, $commentId]);
            $db->prepare("UPDATE article_comments SET likes = likes + 1 WHERE id = ?")->execute([$commentId]);
            $liked = true;
        }
        $stmt = $db->prepare("SELECT likes FROM article_comments WHERE id = ?");
        $stmt->execute([$commentId]);
        return ['liked' => $liked, 'likes' => (int)$stmt->fetchColumn()];
    } catch (Throwable $e) { return ['liked' => false, 'likes' => 0]; }
}

// ============================================
// Article reactions (like / dislike) + share count
// ============================================

/**
 * Get reaction counts for a set of articles.
 * Returns: [ article_id => ['like' => N, 'dislike' => N, 'share' => N] ]
 */
function article_reactions_counts_for(array $articleIds): array {
    $out = [];
    if (!$articleIds) return $out;
    $ids = array_values(array_unique(array_map('intval', $articleIds)));
    if (!$ids) return $out;
    foreach ($ids as $id) { $out[$id] = ['like' => 0, 'dislike' => 0, 'share' => 0]; }

    // The homepage calls this once per request with ~60 ids. Two DB
    // round-trips per request add up under load, so we cache the
    // per-batch result for 60s keyed on the sorted id list.
    sort($ids);
    $cacheKey = 'reactions_counts_' . md5(implode(',', $ids));
    $cached = cache_get($cacheKey);
    if (is_array($cached)) return $cached;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT article_id, reaction, COUNT(*) c FROM article_reactions WHERE article_id IN ($placeholders) GROUP BY article_id, reaction");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $aid = (int)$r['article_id'];
            $out[$aid][$r['reaction']] = (int)$r['c'];
        }
        $stmt = $db->prepare("SELECT article_id, count FROM article_share_counts WHERE article_id IN ($placeholders)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['article_id']]['share'] = (int)$r['count'];
        }
    } catch (Throwable $e) {}

    cache_set($cacheKey, $out, 60);
    return $out;
}

/**
 * Get current user's reactions for a set of articles.
 * Returns: [ article_id => 'like' | 'dislike' ]
 */
function user_article_reactions_for(int $userId, array $articleIds): array {
    if (!$userId || !$articleIds) return [];
    $ids = array_values(array_unique(array_map('intval', $articleIds)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT article_id, reaction FROM article_reactions WHERE user_id = ? AND article_id IN ($placeholders)");
        $stmt->execute(array_merge([$userId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['article_id']] = $r['reaction'];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * Toggle a reaction. If the user already has the same reaction, remove it.
 * If they have the opposite reaction, replace it. Otherwise insert.
 * Returns the new state: ['reaction' => 'like'|'dislike'|null, 'like' => N, 'dislike' => N]
 */
function toggle_article_reaction(int $userId, int $articleId, string $reaction): array {
    if (!in_array($reaction, ['like', 'dislike'], true)) {
        return ['reaction' => null, 'like' => 0, 'dislike' => 0];
    }
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT reaction FROM article_reactions WHERE user_id = ? AND article_id = ?");
        $stmt->execute([$userId, $articleId]);
        $existing = $stmt->fetchColumn();
        if ($existing === $reaction) {
            $db->prepare("DELETE FROM article_reactions WHERE user_id = ? AND article_id = ?")->execute([$userId, $articleId]);
            $new = null;
        } elseif ($existing) {
            $db->prepare("UPDATE article_reactions SET reaction = ?, created_at = NOW() WHERE user_id = ? AND article_id = ?")->execute([$reaction, $userId, $articleId]);
            $new = $reaction;
        } else {
            $db->prepare("INSERT INTO article_reactions (user_id, article_id, reaction) VALUES (?, ?, ?)")->execute([$userId, $articleId, $reaction]);
            $new = $reaction;
        }
        $stmt = $db->prepare("SELECT reaction, COUNT(*) c FROM article_reactions WHERE article_id = ? GROUP BY reaction");
        $stmt->execute([$articleId]);
        $counts = ['like' => 0, 'dislike' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $counts[$r['reaction']] = (int)$r['c'];
        }
        return ['reaction' => $new, 'like' => $counts['like'], 'dislike' => $counts['dislike']];
    } catch (Throwable $e) {
        return ['reaction' => null, 'like' => 0, 'dislike' => 0];
    }
}

/**
 * Increment share count for an article. Returns new count.
 */
function bump_article_share_count(int $articleId): int {
    try {
        $db = getDB();
        $db->prepare("INSERT INTO article_share_counts (article_id, count) VALUES (?, 1)
                      ON DUPLICATE KEY UPDATE count = count + 1")->execute([$articleId]);
        $stmt = $db->prepare("SELECT count FROM article_share_counts WHERE article_id = ?");
        $stmt->execute([$articleId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
