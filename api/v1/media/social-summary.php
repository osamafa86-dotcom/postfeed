<?php
/**
 * GET /api/v1/media/social-summary?platform=telegram|twitter
 * Returns the latest AI-generated summary for a social platform.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/ai_helper.php';

api_method('GET');
api_rate_limit('media:social-summary', 120, 60);

$platform = strtolower(trim((string)($_GET['platform'] ?? '')));

if (!in_array($platform, ['telegram', 'twitter'], true)) {
    api_err('invalid_platform', 'يرجى تحديد المنصة: telegram أو twitter', 400);
}

// ── Telegram summary ──
if ($platform === 'telegram') {
    $latest = tg_summary_get_latest();
    if (!$latest) {
        api_err('not_found', 'لا يوجد ملخص تلغرام متاح حالياً', 404);
    }
    api_ok([
        'platform'   => 'telegram',
        'summary'    => $latest['summary'] ?? '',
        'headline'   => $latest['headline'] ?? '',
        'sections'   => $latest['sections'] ?? [],
        'topics'     => $latest['topics'] ?? [],
        'generated_at' => $latest['generated_at'] ?? null,
    ]);
}

// ── Twitter summary ──
if ($platform === 'twitter') {
    $summary = null;
    try {
        $db = getDB();
        // Try to get from twitter_summaries table if it exists
        $tables = $db->query("SHOW TABLES LIKE 'twitter_summaries'")->fetchAll();
        if (!empty($tables)) {
            $row = $db->query("SELECT * FROM twitter_summaries ORDER BY created_at DESC LIMIT 1")
                      ->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $summary = $row['summary'] ?? ($row['body'] ?? '');
            }
        }

        // Fallback: generate a simple summary from recent tweets
        if (!$summary) {
            $tables = $db->query("SHOW TABLES LIKE 'tweets'")->fetchAll();
            if (!empty($tables)) {
                $tweets = $db->query("SELECT text FROM tweets WHERE is_active = 1 ORDER BY posted_at DESC LIMIT 20")
                             ->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($tweets)) {
                    $summary = 'أبرز ما نُشر على تويتر: ' . implode(' | ', array_slice($tweets, 0, 5));
                }
            }
        }
    } catch (Throwable $e) {
        error_log('social-summary twitter: ' . $e->getMessage());
    }

    if (!$summary) {
        api_err('not_found', 'لا يوجد ملخص تويتر متاح حالياً', 404);
    }

    api_ok([
        'platform' => 'twitter',
        'summary'  => $summary,
    ]);
}
