<?php
/**
 * نيوزفلو - الدوال المساعدة
 */

require_once __DIR__ . '/config.php';

// ============================================
// دوال الأخبار
// ============================================

function getHeroArticles() {
    $db = getDB();
    $stmt = $db->query("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                         s.name as source_name, s.logo_letter, s.logo_color, s.logo_bg
                         FROM articles a
                         LEFT JOIN categories c ON a.category_id = c.id
                         LEFT JOIN sources s ON a.source_id = s.id
                         WHERE a.is_hero = 1 AND a.status = 'published'
                         ORDER BY a.published_at DESC LIMIT 3");
    return $stmt->fetchAll();
}

function getPalestineNews($limit = 6) {
    $db = getDB();
    $keywords = ['فلسطين', 'غزة', 'الضفة', 'القدس', 'الاحتلال', 'الفلسطيني', 'حماس', 'المقاومة', 'الأقصى', 'رفح', 'خان يونس', 'جنين', 'نابلس', 'طوفان', 'الشهداء', 'شهيد'];
    $conditions = [];
    $params = [];
    foreach ($keywords as $kw) {
        $conditions[] = "a.title LIKE ?";
        $params[] = '%' . $kw . '%';
    }
    $where = '(' . implode(' OR ', $conditions) . ')';
    $params[] = $limit;
    $stmt = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                           s.name as source_name, s.logo_color
                           FROM articles a
                           LEFT JOIN categories c ON a.category_id = c.id
                           LEFT JOIN sources s ON a.source_id = s.id
                           WHERE {$where} AND a.status = 'published'
                           ORDER BY a.published_at DESC LIMIT ?");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getBreakingNews() {
    $db = getDB();
    // Prefer flagged breaking news from the last 24h
    $stmt = $db->query("SELECT a.*, c.name as cat_name, c.css_class,
                         s.name as source_name
                         FROM articles a
                         LEFT JOIN categories c ON a.category_id = c.id
                         LEFT JOIN sources s ON a.source_id = s.id
                         WHERE a.is_breaking = 1 AND a.status = 'published'
                         AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                         ORDER BY a.published_at DESC LIMIT 5");
    $rows = $stmt->fetchAll();
    if (count($rows) >= 3) return $rows;

    // Fallback: latest 5 published articles so the section stays fresh
    $stmt = $db->query("SELECT a.*, c.name as cat_name, c.css_class,
                         s.name as source_name
                         FROM articles a
                         LEFT JOIN categories c ON a.category_id = c.id
                         LEFT JOIN sources s ON a.source_id = s.id
                         WHERE a.status = 'published'
                         ORDER BY a.published_at DESC LIMIT 5");
    return $stmt->fetchAll();
}

function getArticlesByCategory($categorySlug, $limit = 4) {
    $db = getDB();
    $stmt = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                           s.name as source_name, s.logo_color
                           FROM articles a
                           LEFT JOIN categories c ON a.category_id = c.id
                           LEFT JOIN sources s ON a.source_id = s.id
                           WHERE c.slug = ? AND a.status = 'published' AND a.is_breaking = 0 AND a.is_hero = 0
                           ORDER BY a.published_at DESC LIMIT ?");
    $stmt->execute([$categorySlug, $limit]);
    return $stmt->fetchAll();
}

function getLatestArticles($limit = 6) {
    $db = getDB();
    $stmt = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                           s.name as source_name, s.logo_color
                           FROM articles a
                           LEFT JOIN categories c ON a.category_id = c.id
                           LEFT JOIN sources s ON a.source_id = s.id
                           WHERE a.status = 'published' AND a.is_breaking = 0 AND a.is_hero = 0
                           ORDER BY a.published_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getArticleById($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                           s.name as source_name, s.logo_letter, s.logo_color, s.logo_bg, s.url as source_website
                           FROM articles a
                           LEFT JOIN categories c ON a.category_id = c.id
                           LEFT JOIN sources s ON a.source_id = s.id
                           WHERE a.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getAllArticles($page = 1, $perPage = 20) {
    $db = getDB();
    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare("SELECT a.*, c.name as cat_name, s.name as source_name
                           FROM articles a
                           LEFT JOIN categories c ON a.category_id = c.id
                           LEFT JOIN sources s ON a.source_id = s.id
                           ORDER BY a.published_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$perPage, $offset]);
    return $stmt->fetchAll();
}

function countArticles() {
    $db = getDB();
    return $db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
}

// ============================================
// دوال الشريط الإخباري
// ============================================

function getTickerItems() {
    $db = getDB();
    return $db->query("SELECT * FROM ticker_items WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
}

// ============================================
// دوال الترندات
// ============================================

function getTrends() {
    $db = getDB();
    return $db->query("SELECT * FROM trends WHERE is_active = 1 ORDER BY sort_order LIMIT 5")->fetchAll();
}

// ============================================
// دوال المصادر
// ============================================

function getActiveSources() {
    $db = getDB();
    return $db->query("SELECT * FROM sources WHERE is_active = 1 ORDER BY name")->fetchAll();
}

function getAllSources() {
    $db = getDB();
    return $db->query("SELECT * FROM sources ORDER BY name")->fetchAll();
}

// ============================================
// دوال الإشعارات
// ============================================

function getNotifications($limit = 6) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM notifications ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getUnreadNotifCount() {
    $db = getDB();
    return $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
}

// ============================================
// دوال الاستطلاعات
// ============================================

function getActivePoll() {
    $db = getDB();
    $poll = $db->query("SELECT * FROM polls WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch();
    if ($poll) {
        $stmt = $db->prepare("SELECT * FROM poll_options WHERE poll_id = ? ORDER BY sort_order");
        $stmt->execute([$poll['id']]);
        $poll['options'] = $stmt->fetchAll();
    }
    return $poll;
}

// ============================================
// دوال الوسائط
// ============================================

function getMediaItems($limit = 4) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM media_items WHERE is_active = 1 ORDER BY sort_order LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// ============================================
// دوال الأكثر قراءة
// ============================================

function getMostRead() {
    $db = getDB();
    return $db->query("SELECT * FROM most_read ORDER BY sort_order LIMIT 5")->fetchAll();
}

// ============================================
// دوال التصنيفات
// ============================================

function getCategories() {
    $db = getDB();
    return $db->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
}

// ============================================
// دوال الإعدادات
// ============================================

function getSetting($key, $default = '') {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

// ============================================
// دوال مساعدة
// ============================================

function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->d >= 2) return 'منذ ' . $diff->d . ' أيام';
    if ($diff->d == 1) return 'منذ يوم';
    if ($diff->h >= 2) return 'منذ ' . $diff->h . ' سا';
    if ($diff->h == 1) return 'منذ ساعة';
    if ($diff->i >= 2) return 'منذ ' . $diff->i . ' دق';
    if ($diff->i == 1) return 'منذ دقيقة';
    return 'الآن';
}

function formatViews($num) {
    if ($num >= 1000000) return round($num / 1000000, 1) . 'M';
    if ($num >= 1000) return round($num / 1000, 1) . 'K';
    return number_format($num);
}

function e($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function isAdmin() {
    return isset($_SESSION[ADMIN_SESSION_NAME]) && $_SESSION[ADMIN_SESSION_NAME] === true;
}

function requireAdmin() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isAdmin()) {
        header('Location: login.php');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify($_POST['_csrf'] ?? '')) {
        http_response_code(403);
        exit('CSRF token mismatch');
    }
}

// ============================================
// CSRF
// ============================================
function csrf_token() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_verify($token) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return !empty($_SESSION['_csrf']) && is_string($token) && hash_equals($_SESSION['_csrf'], $token);
}
