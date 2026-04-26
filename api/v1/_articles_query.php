<?php
/**
 * Shared article-fetch helpers used by multiple endpoints.
 */

function articles_select_sql(): string {
    return "SELECT
        a.id, a.title, a.slug, a.excerpt, a.image_url, a.source_url,
        a.is_breaking, a.is_featured, a.is_hero,
        a.view_count, a.comments, a.published_at, a.created_at,
        a.category_id, a.source_id,
        c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, c.css_class,
        s.name AS source_name, s.slug AS source_slug, s.logo_letter, s.logo_color, s.logo_bg, s.url AS source_site
      FROM articles a
      LEFT JOIN categories c ON c.id = a.category_id
      LEFT JOIN sources    s ON s.id = a.source_id";
}

function fetch_articles(array $filters = [], int $limit = 20, int $offset = 0): array {
    $db = getDB();
    $where = ["a.status = 'published'"];
    $params = [];

    if (!empty($filters['category'])) {
        $where[] = 'c.slug = ?';
        $params[] = $filters['category'];
    }
    if (!empty($filters['category_id'])) {
        $where[] = 'a.category_id = ?';
        $params[] = (int)$filters['category_id'];
    }
    if (!empty($filters['source'])) {
        $where[] = 's.slug = ?';
        $params[] = $filters['source'];
    }
    if (!empty($filters['source_id'])) {
        $where[] = 'a.source_id = ?';
        $params[] = (int)$filters['source_id'];
    }
    if (!empty($filters['breaking'])) {
        $where[] = 'a.is_breaking = 1';
    }
    if (!empty($filters['featured'])) {
        $where[] = 'a.is_featured = 1';
    }
    if (!empty($filters['hero'])) {
        $where[] = 'a.is_hero = 1';
    }
    if (!empty($filters['since'])) {
        $where[] = 'a.published_at >= ?';
        $params[] = $filters['since'];
    }
    if (!empty($filters['until'])) {
        $where[] = 'a.published_at <= ?';
        $params[] = $filters['until'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(a.title LIKE ? OR a.excerpt LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        $params[] = $like; $params[] = $like;
    }
    if (!empty($filters['ids']) && is_array($filters['ids'])) {
        $in = implode(',', array_map('intval', $filters['ids']));
        if ($in !== '') $where[] = "a.id IN ($in)";
    }

    $order = $filters['order'] ?? 'published_at DESC';
    $allowedOrders = [
        'published_at DESC', 'published_at ASC',
        'view_count DESC', 'view_count ASC',
        'created_at DESC',
    ];
    if (!in_array($order, $allowedOrders, true)) $order = 'published_at DESC';

    $sql = articles_select_sql()
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . $order
        . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    return array_map('api_format_article', $rows);
}

function count_articles(array $filters = []): int {
    $db = getDB();
    $where = ["a.status = 'published'"];
    $params = [];
    if (!empty($filters['category'])) { $where[] = 'c.slug = ?'; $params[] = $filters['category']; }
    if (!empty($filters['source']))   { $where[] = 's.slug = ?'; $params[] = $filters['source']; }
    if (!empty($filters['breaking'])) { $where[] = 'a.is_breaking = 1'; }
    if (!empty($filters['q'])) {
        $where[] = '(a.title LIKE ? OR a.excerpt LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        $params[] = $like; $params[] = $like;
    }
    $sql = "SELECT COUNT(*) FROM articles a
            LEFT JOIN categories c ON c.id = a.category_id
            LEFT JOIN sources    s ON s.id = a.source_id
            WHERE " . implode(' AND ', $where);
    $st = $db->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}
