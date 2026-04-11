<?php
/**
 * نيوزفلو - الدوال المساعدة
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache.php';

// Homepage cache TTL (seconds). Override in config.php if needed.
if (!defined('HOMEPAGE_CACHE_TTL')) define('HOMEPAGE_CACHE_TTL', 120);
// Rarely-changing reference data (settings, categories, sources,
// total article counts) uses a longer TTL — these don't need minute-
// by-minute freshness and bumping them saves DB round-trips on cold
// page-cache misses.
if (!defined('REFERENCE_CACHE_TTL')) define('REFERENCE_CACHE_TTL', 3600);

// ============================================
// دوال الأخبار
// ============================================

function getHeroArticles() {
    return cache_remember('hero_articles', HOMEPAGE_CACHE_TTL, function() {
        $db = getDB();
        $stmt = $db->query("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                             s.name as source_name, s.logo_letter, s.logo_color, s.logo_bg
                             FROM articles a
                             LEFT JOIN categories c ON a.category_id = c.id
                             LEFT JOIN sources s ON a.source_id = s.id
                             WHERE a.is_hero = 1 AND a.status = 'published'
                             ORDER BY a.published_at DESC LIMIT 3");
        return $stmt->fetchAll();
    });
}

function getPalestineNews($limit = 6) {
    return cache_remember('palestine_news_' . (int)$limit, HOMEPAGE_CACHE_TTL, function() use ($limit) {
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
    });
}

function getBreakingNews($limit = 5) {
    $limit = max(1, (int)$limit);
    return cache_remember('breaking_news_' . $limit, HOMEPAGE_CACHE_TTL, function() use ($limit) {
        $db = getDB();
        // Prefer flagged breaking news from the last 24h
        $stmt = $db->query("SELECT a.*, c.name as cat_name, c.css_class,
                             s.name as source_name
                             FROM articles a
                             LEFT JOIN categories c ON a.category_id = c.id
                             LEFT JOIN sources s ON a.source_id = s.id
                             WHERE a.is_breaking = 1 AND a.status = 'published'
                             AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                             ORDER BY a.published_at DESC LIMIT " . $limit);
        $rows = $stmt->fetchAll();
        if (count($rows) >= 3) return $rows;

        // Fallback: latest published articles so the section stays fresh
        $stmt = $db->query("SELECT a.*, c.name as cat_name, c.css_class,
                             s.name as source_name
                             FROM articles a
                             LEFT JOIN categories c ON a.category_id = c.id
                             LEFT JOIN sources s ON a.source_id = s.id
                             WHERE a.status = 'published'
                             ORDER BY a.published_at DESC LIMIT " . $limit);
        return $stmt->fetchAll();
    });
}

function getArticlesByCategory($categorySlug, $limit = 4) {
    return cache_remember('cat_' . $categorySlug . '_' . (int)$limit, HOMEPAGE_CACHE_TTL, function() use ($categorySlug, $limit) {
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
    });
}

function getLatestArticles($limit = 6) {
    return cache_remember('latest_articles_' . (int)$limit, HOMEPAGE_CACHE_TTL, function() use ($limit) {
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
    });
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
    return cache_remember('count_articles', REFERENCE_CACHE_TTL, function() {
        $db = getDB();
        return $db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
    });
}

// ============================================
// دوال الشريط الإخباري
// ============================================

function getTickerItems() {
    return cache_remember('ticker_items', HOMEPAGE_CACHE_TTL, function() {
        $db = getDB();
        return $db->query("SELECT * FROM ticker_items WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
    });
}

// ============================================
// دوال الترندات
// ============================================

function getTrends() {
    return cache_remember('trends_active', HOMEPAGE_CACHE_TTL, function() {
        $db = getDB();
        return $db->query("SELECT * FROM trends WHERE is_active = 1 ORDER BY sort_order LIMIT 5")->fetchAll();
    });
}

// ============================================
// دوال المصادر
// ============================================

function getActiveSources() {
    return cache_remember('sources_active', REFERENCE_CACHE_TTL, function() {
        $db = getDB();
        return $db->query("SELECT * FROM sources WHERE is_active = 1 ORDER BY name")->fetchAll();
    });
}

function getAllSources() {
    $db = getDB();
    return $db->query("SELECT * FROM sources ORDER BY name")->fetchAll();
}

// ============================================
// دوال الإشعارات
// ============================================

function getNotifications($limit = 6) {
    return cache_remember('notifications_' . (int)$limit, HOMEPAGE_CACHE_TTL, function() use ($limit) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM notifications ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    });
}

function getUnreadNotifCount() {
    return cache_remember('notifications_unread_count', HOMEPAGE_CACHE_TTL, function() {
        $db = getDB();
        return $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
    });
}

// ============================================
// دوال الاستطلاعات
// ============================================

function getActivePoll() {
    return cache_remember('active_poll', HOMEPAGE_CACHE_TTL, function() {
        $db = getDB();
        $poll = $db->query("SELECT * FROM polls WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch();
        if ($poll) {
            $stmt = $db->prepare("SELECT * FROM poll_options WHERE poll_id = ? ORDER BY sort_order");
            $stmt->execute([$poll['id']]);
            $poll['options'] = $stmt->fetchAll();
        }
        return $poll;
    });
}

// ============================================
// دوال الوسائط
// ============================================

function getMediaItems($limit = 4) {
    return cache_remember('media_items_' . (int)$limit, HOMEPAGE_CACHE_TTL, function() use ($limit) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM media_items WHERE is_active = 1 ORDER BY sort_order LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    });
}

// ============================================
// دوال الأكثر قراءة
// ============================================

function getMostRead($limit = 5) {
    $limit = max(1, (int)$limit);
    return cache_remember('most_read_' . $limit, HOMEPAGE_CACHE_TTL, function() use ($limit) {
        $db = getDB();
        return $db->query("SELECT * FROM most_read ORDER BY sort_order LIMIT " . $limit)->fetchAll();
    });
}

// ============================================
// دوال التصنيفات
// ============================================

function getCategories() {
    return cache_remember('categories_active', REFERENCE_CACHE_TTL, function() {
        $db = getDB();
        return $db->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
    });
}

// ============================================
// دوال الإعدادات
// ============================================

function getSetting($key, $default = '') {
    static $settings = null;
    if ($settings === null) {
        $settings = cache_remember('settings_all', REFERENCE_CACHE_TTL, function() {
            try {
                $db = getDB();
                $rows = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
                return $rows ?: [];
            } catch (Throwable $e) {
                return [];
            }
        });
        if (!is_array($settings)) $settings = [];
    }
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
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

/**
 * Returns a tiny inline SVG data URI used when an article has no image.
 * Avoids any external HTTP request (previously used picsum.photos).
 */
function placeholderImage(int $w = 800, int $h = 500): string {
    static $cache = [];
    $key = $w . 'x' . $h;
    if (isset($cache[$key])) return $cache[$key];
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '">'
         . '<defs><linearGradient id="g" x1="0" x2="1" y1="0" y2="1">'
         . '<stop offset="0" stop-color="#1e293b"/><stop offset="1" stop-color="#334155"/>'
         . '</linearGradient></defs>'
         . '<rect width="100%" height="100%" fill="url(#g)"/>'
         . '<text x="50%" y="50%" fill="#94a3b8" font-family="Tajawal,sans-serif" font-size="' . (int)($w/14) . '" text-anchor="middle" dominant-baseline="middle">نيوزفلو</text>'
         . '</svg>';
    return $cache[$key] = 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

function articleUrl($article) {
    $id = (int)($article['id'] ?? 0);
    $slug = $article['slug'] ?? '';
    if ($slug) {
        $slug = preg_replace('/[^a-zA-Z0-9\x{0600}-\x{06FF}-]+/u', '-', $slug);
        $slug = trim($slug, '-');
        $slug = mb_substr($slug, 0, 80);
        return 'article/' . $id . '/' . rawurlencode($slug);
    }
    return 'article/' . $id;
}

function categoryUrl($slug) {
    return 'category/' . rawurlencode($slug);
}

function sourceUrl($id) {
    // Root-absolute so the link resolves correctly even when rendered
    // from a friendly URL like /article/123/slug (where a relative
    // "source/123" would become /article/123/source/123 → 404).
    return '/source/' . (int)$id;
}

/**
 * Generate a responsive <img> tag with srcset via the image proxy.
 *
 * @param string $url      Original image URL
 * @param string $alt      Alt text
 * @param string $sizes    CSS sizes attribute (e.g. "(max-width:768px) 100vw, 400px")
 * @param array  $widths   Array of pixel widths for srcset
 * @param string $class    Optional CSS class
 * @param string $loading  'lazy' or 'eager'
 * @param string $extra    Extra attributes (e.g. 'fetchpriority="high"')
 * @return string          HTML <img> tag
 */
function responsiveImg(string $url, string $alt = '', string $sizes = '100vw',
                       array $widths = [320, 640, 800], string $class = '',
                       string $loading = 'lazy', string $extra = ''): string {
    if (empty($url) || strpos($url, 'data:') === 0) {
        // Placeholder SVGs — no proxy needed
        $cls = $class ? ' class="' . e($class) . '"' : '';
        return '<img src="' . e($url) . '" alt="' . e($alt) . '"' . $cls
             . ' loading="' . $loading . '" decoding="async" ' . $extra . '>';
    }
    $srcset = [];
    foreach ($widths as $w) {
        $proxyUrl = '/api/img.php?url=' . rawurlencode($url) . '&w=' . (int)$w;
        $srcset[] = e($proxyUrl) . ' ' . (int)$w . 'w';
    }
    // Fallback src uses the middle size
    $midW = $widths[(int)(count($widths) / 2)] ?? $widths[0];
    $fallback = '/api/img.php?url=' . rawurlencode($url) . '&w=' . (int)$midW;
    $cls = $class ? ' class="' . e($class) . '"' : '';
    return '<img src="' . e($fallback) . '"'
         . ' srcset="' . implode(', ', $srcset) . '"'
         . ' sizes="' . e($sizes) . '"'
         . ' alt="' . e($alt) . '"'
         . $cls
         . ' loading="' . $loading . '" decoding="async" ' . $extra . '>';
}

function isAdmin() {
    return isset($_SESSION[ADMIN_SESSION_NAME]) && $_SESSION[ADMIN_SESSION_NAME] === true;
}

// ============================================
// Role-based access control
// ============================================
// Hierarchy: viewer (1) < editor (2) < admin (3). Helpers below
// let panel pages declare the minimum role they require so we can
// finally use the editor/viewer slots in the users.role enum that
// were previously dead.
const ROLE_LEVELS = ['viewer' => 1, 'editor' => 2, 'admin' => 3];

/** Current logged-in panel user's role, or null if not logged in. */
function currentRole(): ?string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isAdmin()) return null;
    $role = $_SESSION['admin_role'] ?? 'admin'; // legacy sessions default to admin
    return isset(ROLE_LEVELS[$role]) ? $role : 'viewer';
}

/** True if the current user holds at least the requested role. */
function hasRole(string $minRole): bool {
    $current = currentRole();
    if ($current === null) return false;
    $need = ROLE_LEVELS[$minRole] ?? 99;
    $have = ROLE_LEVELS[$current] ?? 0;
    return $have >= $need;
}

/**
 * Gate a panel page on minimum role. Replaces requireAdmin() at
 * the top of admin-only pages; pages that should also be reachable
 * by editor or viewer pass the appropriate level here.
 *
 *   requireRole('viewer') — anyone signed in (read-only OK)
 *   requireRole('editor') — content editing pages
 *   requireRole('admin')  — settings, users, AI keys, etc.
 *
 * Also handles the CSRF check that requireAdmin() used to do, so
 * existing pages keep the same protection on POST.
 */
function requireRole(string $minRole = 'admin'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isAdmin()) {
        header('Location: login.php');
        exit;
    }
    if (!hasRole($minRole)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>غير مصرّح</title>'
           . '<body style="font-family:Tajawal,Tahoma,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;">'
           . '<div style="text-align:center;background:#fff;padding:40px;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.08);max-width:420px;">'
           . '<div style="font-size:48px;margin-bottom:12px;">🔒</div>'
           . '<h1 style="font-size:20px;margin:0 0 8px;">غير مصرّح بالوصول</h1>'
           . '<p style="color:#64748b;font-size:14px;margin:0 0 20px;">صلاحيتك الحالية لا تسمح بفتح هذه الصفحة.</p>'
           . '<a href="index.php" style="background:#1a5c5c;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;">العودة للوحة</a>'
           . '</div></body></html>';
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify($_POST['_csrf'] ?? '')) {
        http_response_code(403);
        exit('CSRF token mismatch');
    }
}

function requireAdmin() {
    requireRole('admin');
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
