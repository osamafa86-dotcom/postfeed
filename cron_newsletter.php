<?php
/**
 * نيوز فيد — إرسال النشرة اليومية
 * Daily digest sender. Triggered by cron once a day (typically 7am
 * Asia/Amman). Picks the top N stories from the last 24h, builds an
 * Arabic HTML digest using existing ai_summary columns, and emails
 * every confirmed subscriber.
 *
 * Safety:
 *   - Skips silently if no confirmed subscribers exist.
 *   - Skips if a digest was already sent in the last 12 hours
 *     (records in newsletter_sends table) — so accidental double-cron
 *     doesn't double-email.
 *   - Sleeps 200ms between sends to be polite to the SMTP relay.
 *
 * URL: cron_newsletter.php?key=CRON_KEY  (or run via php-cli)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';
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
$startTime = microtime(true);

// 0. Make sure tables exist on a fresh deploy.
try {
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
        INDEX idx_confirmed (confirmed)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
} catch (Throwable $e) {}

// 1. Subscriber count guard.
$confirmedCount = (int)$db->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE confirmed = 1")->fetchColumn();
if ($confirmedCount === 0) {
    echo "لا يوجد مشتركون مؤكّدون — تخطي.\n";
    exit;
}

// 2. Throttle — skip if we already sent within the last 12h, unless
//    ?force=1 is passed (admin manual trigger).
$force = !empty($_GET['force']) || (PHP_SAPI === 'cli' && in_array('--force', $_SERVER['argv'] ?? [], true));
if (!$force) {
    $recent = (int)$db->query("SELECT COUNT(*) FROM newsletter_sends WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)")->fetchColumn();
    if ($recent > 0) {
        echo "أُرسلت نشرة خلال آخر 12 ساعة — تخطي. أعد التشغيل بـ ?force=1 لتجاوز ذلك.\n";
        exit;
    }
}

// 3. Pick top stories from the last 24h.
//    Strategy:
//      a) Up to 2 hero/breaking flagged articles
//      b) Top 6 by view_count
//      c) Drop duplicates by cluster_key so the digest shows distinct
//         stories rather than 4 versions of the same headline.
$articles = [];
$seenIds = [];
$seenClusters = [];

$pickRows = function(string $sql, array $bind = []) use ($db, &$articles, &$seenIds, &$seenClusters, &$confirmedCount) {
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        if (isset($seenIds[$id])) continue;
        $ck = (string)($row['cluster_key'] ?? '');
        if ($ck !== '' && $ck !== '-' && isset($seenClusters[$ck])) continue;
        $seenIds[$id] = true;
        if ($ck !== '' && $ck !== '-') $seenClusters[$ck] = true;
        $articles[] = $row;
    }
};

$pickRows("SELECT a.id, a.title, a.slug, a.excerpt, a.image_url, a.ai_summary, a.cluster_key, a.published_at, a.view_count,
                  c.name AS cat_name, s.name AS source_name
             FROM articles a
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN sources s ON a.source_id = s.id
            WHERE a.status = 'published'
              AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND (a.is_hero = 1 OR a.is_breaking = 1)
            ORDER BY a.published_at DESC
            LIMIT 4");

$pickRows("SELECT a.id, a.title, a.slug, a.excerpt, a.image_url, a.ai_summary, a.cluster_key, a.published_at, a.view_count,
                  c.name AS cat_name, s.name AS source_name
             FROM articles a
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN sources s ON a.source_id = s.id
            WHERE a.status = 'published'
              AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY a.view_count DESC, a.published_at DESC
            LIMIT 30");

// Trim to 8 final picks.
$articles = array_slice($articles, 0, 8);

if (empty($articles)) {
    echo "لا توجد أخبار جديدة في آخر 24 ساعة — تخطي.\n";
    exit;
}

echo "اختيار {$confirmedCount} مشترك و " . count($articles) . " خبر للإرسال...\n";

// 4. Build the digest body once, then template per-subscriber so the
//    only thing that changes per email is the unsubscribe URL.
$dateStr = (function() {
    $months = [1=>'يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
    $d = (int)date('j');
    $m = $months[(int)date('n')] ?? '';
    $y = date('Y');
    return "$d $m $y";
})();

$siteName = getSetting('site_name', SITE_NAME);
$subject  = "نشرة $siteName اليومية — $dateStr";
$brand    = '#1a5c5c';

$articlesHtml = '';
foreach ($articles as $i => $a) {
    $url   = SITE_URL . '/' . articleUrl($a);
    $title = e((string)$a['title']);
    $sum   = trim((string)($a['ai_summary'] ?? ''));
    if ($sum === '') $sum = trim(strip_tags((string)$a['excerpt']));
    $sum   = mb_substr($sum, 0, 220);
    if (mb_strlen($sum) >= 220) $sum .= '…';
    $sumE  = e($sum);
    $src   = e((string)($a['source_name'] ?? ''));
    $cat   = e((string)($a['cat_name'] ?? ''));
    $img   = trim((string)($a['image_url'] ?? ''));
    $imgHtml = '';
    if ($img !== '' && preg_match('#^https?://#', $img)) {
        $imgHtml = '<img src="' . e($img) . '" alt="" style="display:block;width:100%;max-height:240px;object-fit:cover;border-radius:8px;margin-bottom:12px;">';
    }
    $articlesHtml .= '<div style="margin:0 0 28px;padding-bottom:24px;border-bottom:1px solid #e2e8f0;">'
                   . $imgHtml
                   . '<div style="font-size:11px;color:' . $brand . ';font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">' . $cat . ' · ' . $src . '</div>'
                   . '<h2 style="font-size:18px;line-height:1.4;margin:0 0 10px;color:#0f172a;"><a href="' . e($url) . '" style="color:#0f172a;text-decoration:none;">' . $title . '</a></h2>'
                   . '<p style="font-size:14px;line-height:1.7;color:#475569;margin:0 0 12px;">' . $sumE . '</p>'
                   . '<a href="' . e($url) . '" style="display:inline-block;background:' . $brand . ';color:#fff;padding:8px 18px;border-radius:6px;font-size:13px;font-weight:700;text-decoration:none;">اقرأ المقال ›</a>'
                   . '</div>';
}

$introHtml = '<p style="margin:0 0 8px;font-size:18px;font-weight:700;color:#0f172a;">صباح الخير ☀️</p>'
           . '<p style="margin:0 0 24px;font-size:15px;color:#64748b;">إليك ملخّص أبرز ما رصدناه اليوم. اضغط على أي خبر لقراءته كاملًا.</p>';

$bodyHtml = $introHtml . $articlesHtml
          . '<p style="text-align:center;margin:24px 0 8px;"><a href="' . e(SITE_URL) . '" style="background:#f59e0b;color:#fff;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block;">زيارة ' . e($siteName) . ' ←</a></p>';

// 5. Send loop.
$success = 0; $fail = 0;
$subStmt = $db->query("SELECT id, email, unsubscribe_token FROM newsletter_subscribers WHERE confirmed = 1");
$bumpStmt = $db->prepare("UPDATE newsletter_subscribers SET last_sent_at = NOW() WHERE id = ?");

foreach ($subStmt->fetchAll(PDO::FETCH_ASSOC) as $sub) {
    $unsubUrl = SITE_URL . '/newsletter/unsubscribe/' . $sub['unsubscribe_token'];
    $html = newsletter_email_html($subject, $bodyHtml, $unsubUrl);
    $ok = mailer_send((string)$sub['email'], $subject, $html);
    if ($ok) {
        $success++;
        try { $bumpStmt->execute([(int)$sub['id']]); } catch (Throwable $e) {}
    } else {
        $fail++;
    }
    usleep(200000); // 200ms between sends
}

// 6. Log the send.
try {
    $log = $db->prepare("INSERT INTO newsletter_sends (subject, article_count, recipient_count, success_count, fail_count) VALUES (?, ?, ?, ?, ?)");
    $log->execute([$subject, count($articles), $confirmedCount, $success, $fail]);
} catch (Throwable $e) {}

$elapsed = round(microtime(true) - $startTime, 2);
echo "تم: نجح $success | فشل $fail | الوقت: {$elapsed}s\n";
