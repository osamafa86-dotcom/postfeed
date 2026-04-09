<?php
/**
 * AI-generated news briefing for the Telegram page.
 *
 * Pulls the Telegram messages that arrived in the last N minutes
 * (default 30) and hands them to Claude for a compact Arabic news
 * summary (headline + paragraph + bullets + topics).
 *
 * Query params:
 *   window  — lookback window in minutes. Default 30, clamped 5..240.
 *   force   — if "1", bypass the cache and regenerate. Useful for a
 *             "تحديث" button. Throttled by a minimum regeneration gap.
 *
 * Caching model:
 *   Summaries are cached under a half-hour "bucket" key derived from
 *   floor(now / 30min). All visitors landing in the same half-hour see
 *   the same summary, so Claude is only called once per bucket. The
 *   bucket rolls forward automatically every 30 minutes.
 *
 *   On top of that, we also respect a minimum regeneration gap
 *   (MIN_REGEN_SECS) against abusive ?force=1 use.
 *
 * Contract:
 *   Always returns application/json. Any fatal/warning/exception is
 *   caught and turned into {"ok":false,"error":"..."}.
 */

// Lock down the response shape before anything can print.
ini_set('display_errors', '0');
error_reporting(0);
while (ob_get_level() > 0) { ob_end_clean(); }
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/ai_helper.php';

const TG_SUMMARY_DEFAULT_WINDOW = 30;   // minutes
const TG_SUMMARY_MIN_WINDOW     = 5;
const TG_SUMMARY_MAX_WINDOW     = 240;
const TG_SUMMARY_BUCKET_SECS    = 1800; // 30 minutes
const TG_SUMMARY_MIN_MSGS       = 3;
const TG_SUMMARY_MAX_MSGS       = 60;
const TG_SUMMARY_CACHE_TTL      = 7200; // keep stale result for 2h as fallback
const TG_SUMMARY_MIN_REGEN_SECS = 120;  // minimum gap between forced regenerations

/** Emit a JSON response and exit, clearing any stray output. */
function tgs_json_exit(array $payload, int $code = 200): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Turn fatal errors into JSON too.
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        tgs_json_exit(['ok' => false, 'error' => 'fatal: ' . $err['message']], 500);
    }
});

try {
    $window = (int)($_GET['window'] ?? TG_SUMMARY_DEFAULT_WINDOW);
    if ($window < TG_SUMMARY_MIN_WINDOW) $window = TG_SUMMARY_MIN_WINDOW;
    if ($window > TG_SUMMARY_MAX_WINDOW) $window = TG_SUMMARY_MAX_WINDOW;

    $force = isset($_GET['force']) && $_GET['force'] == '1';

    // Half-hour bucket id. Same id → shared cache entry across viewers.
    $bucket    = (int)floor(time() / TG_SUMMARY_BUCKET_SECS);
    $cacheKey  = 'tg_summary_w' . $window . '_b' . $bucket;
    $throttleKey = 'tg_summary_last_regen_w' . $window;

    // Serve the cached summary unless an uncontested force was passed.
    if (!$force) {
        $hit = cache_get($cacheKey);
        if (is_array($hit) && !empty($hit['ok'])) {
            $hit['cached'] = true;
            tgs_json_exit($hit);
        }
    } else {
        // Throttle ?force=1 so a hot-reload loop can't run up the API bill.
        $lastAt = (int)(cache_get($throttleKey) ?: 0);
        if ($lastAt > 0 && (time() - $lastAt) < TG_SUMMARY_MIN_REGEN_SECS) {
            $hit = cache_get($cacheKey);
            if (is_array($hit) && !empty($hit['ok'])) {
                $hit['cached']   = true;
                $hit['throttled'] = true;
                tgs_json_exit($hit);
            }
        }
    }

    $db = getDB();

    // Pull the newest messages within the window, limited so the AI
    // prompt stays small. We select the source handle too so Claude
    // can attribute context when needed.
    $stmt = $db->prepare("SELECT m.id, m.text, m.posted_at, s.username, s.display_name
                          FROM telegram_messages m
                          JOIN telegram_sources s ON m.source_id = s.id
                          WHERE m.is_active = 1
                            AND s.is_active = 1
                            AND m.text IS NOT NULL
                            AND m.text <> ''
                            AND m.posted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                          ORDER BY m.posted_at DESC, m.id DESC
                          LIMIT " . (int)TG_SUMMARY_MAX_MSGS);
    $stmt->execute([$window]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If this window is quiet, widen the lookback to the most recent N
    // messages so the user always sees something useful on first click.
    if (count($messages) < TG_SUMMARY_MIN_MSGS) {
        $stmt = $db->query("SELECT m.id, m.text, m.posted_at, s.username, s.display_name
                            FROM telegram_messages m
                            JOIN telegram_sources s ON m.source_id = s.id
                            WHERE m.is_active = 1
                              AND s.is_active = 1
                              AND m.text IS NOT NULL
                              AND m.text <> ''
                            ORDER BY m.posted_at DESC, m.id DESC
                            LIMIT " . (int)TG_SUMMARY_MAX_MSGS);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (count($messages) < TG_SUMMARY_MIN_MSGS) {
        tgs_json_exit([
            'ok'    => false,
            'error' => 'لا توجد رسائل كافية للتلخيص بعد. حاول مرة أخرى بعد قليل.',
        ]);
    }

    $ai = ai_summarize_telegram($messages);
    if (empty($ai['ok'])) {
        // If the AI failed but we still have a stale cached payload, fall
        // back to it so the UI isn't empty.
        $stale = cache_get($cacheKey);
        if (is_array($stale) && !empty($stale['ok'])) {
            $stale['cached'] = true;
            $stale['stale']  = true;
            tgs_json_exit($stale);
        }
        tgs_json_exit([
            'ok'    => false,
            'error' => $ai['error'] ?? 'تعذّر توليد الملخص حالياً.',
        ]);
    }

    // Attach generation metadata that the UI needs to render the card.
    $bucketStart = $bucket * TG_SUMMARY_BUCKET_SECS;
    $payload = [
        'ok'           => true,
        'headline'     => $ai['headline'] ?? '',
        'summary'      => $ai['summary']  ?? '',
        'bullets'      => $ai['bullets']  ?? [],
        'topics'       => $ai['topics']   ?? [],
        'window_mins'  => $window,
        'message_count'=> count($messages),
        'generated_at' => date('c'),
        'bucket_start' => date('c', $bucketStart),
        'next_refresh' => date('c', $bucketStart + TG_SUMMARY_BUCKET_SECS),
        'cached'       => false,
    ];

    cache_set($cacheKey, $payload, TG_SUMMARY_CACHE_TTL);
    cache_set($throttleKey, time(), TG_SUMMARY_CACHE_TTL);

    tgs_json_exit($payload);
} catch (Throwable $e) {
    tgs_json_exit(['ok' => false, 'error' => 'server error'], 500);
}
