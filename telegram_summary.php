<?php
/**
 * Read-only endpoint for the Telegram news briefings stored in the
 * telegram_summaries table. All generation is now handled offline by
 * cron_tg_summary.php, so this file never calls Claude on a normal
 * request — which is the whole point of moving to scheduled
 * generation: cost stays flat at one briefing per hour regardless
 * of how many visitors open the panel.
 *
 * Query params:
 *   id   — fetch a specific stored briefing by id (archive pill click).
 *   list — "1" to return recent briefings metadata (for the archive pills).
 *   (default) — latest briefing.
 *
 * Cold-start seed:
 *   If the table is completely empty (fresh deploy, cron hasn't run
 *   yet) we do a single inline generation so the first visitor sees
 *   a briefing instead of a placeholder. That seed is throttled so
 *   a broken cron can't fall back to per-visitor generation.
 *
 * Contract:
 *   Always returns application/json. Fatals and exceptions are
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

const TG_SUMMARY_LIST_DEFAULT    = 12;
const TG_SUMMARY_LIST_MAX        = 48;
const TG_SUMMARY_SEED_THROTTLE   = 600;    // seconds between cold-start seed attempts
const TG_SUMMARY_STALE_AFTER     = 3900;   // 65 min — anything older counts as "stale"
const TG_SUMMARY_REFRESH_THROTTLE = 600;   // seconds between inline refresh attempts

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

/** Decorate a DB briefing row with the fields the UI expects. */
function tgs_render_payload(array $row): array {
    return [
        'ok'            => true,
        'id'            => $row['id'],
        'headline'      => $row['headline'],
        'summary'       => $row['summary'],
        'sections'      => $row['sections'],
        'bullets'       => [], // legacy fallback slot, unused now
        'topics'        => $row['topics'],
        'window_mins'   => $row['window_mins'],
        'message_count' => $row['message_count'],
        'generated_at'  => $row['generated_at'],
    ];
}

/**
 * Generate ONE briefing inline and save it. Used either as a cold-start
 * seed (empty table) or as a self-healing refresh when the latest briefing
 * is older than TG_SUMMARY_STALE_AFTER and the external cron hasn't
 * caught up. Guarded by a short cache throttle so a broken cron can't
 * cause per-visitor generation.
 */
function tgs_generate_inline(string $throttleKey, int $throttleSecs): ?array {
    $lastAt = (int)(cache_get($throttleKey) ?: 0);
    if ($lastAt > 0 && (time() - $lastAt) < $throttleSecs) {
        return null;
    }
    cache_set($throttleKey, time(), $throttleSecs * 2);

    $messages = tg_summary_collect_messages(60, 250);
    if (count($messages) < 3) return null;

    $ai = ai_summarize_telegram($messages);
    if (empty($ai['ok'])) return null;

    $id = tg_summary_save($ai, count($messages), 60);
    if (!$id) return null;

    // Keep archive size sane on the refresh path too.
    if (function_exists('tg_summary_prune')) tg_summary_prune(72);

    return tg_summary_get_by_id($id);
}

try {
    // ---- list mode: archive pill bar data --------------------------
    if (isset($_GET['list'])) {
        $limit = (int)($_GET['list'] ?: TG_SUMMARY_LIST_DEFAULT);
        if ($limit < 1) $limit = TG_SUMMARY_LIST_DEFAULT;
        if ($limit > TG_SUMMARY_LIST_MAX) $limit = TG_SUMMARY_LIST_MAX;
        $items = tg_summary_list($limit);
        tgs_json_exit([
            'ok'    => true,
            'items' => array_map(function($r) {
                return [
                    'id'            => (int)$r['id'],
                    'headline'      => (string)$r['headline'],
                    'generated_at'  => (string)$r['generated_at'],
                    'message_count' => (int)$r['message_count'],
                    'window_mins'   => (int)$r['window_mins'],
                ];
            }, $items),
        ]);
    }

    // ---- specific briefing by id -----------------------------------
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        if ($id <= 0) {
            tgs_json_exit(['ok' => false, 'error' => 'معرّف غير صالح']);
        }
        $row = tg_summary_get_by_id($id);
        if (!$row) {
            tgs_json_exit(['ok' => false, 'error' => 'لم يُعثر على الملخص']);
        }
        tgs_json_exit(tgs_render_payload($row));
    }

    // ---- default: latest briefing ----------------------------------
    $latest = tg_summary_get_latest();

    // Cold-start: table is empty → generate one inline so the first
    // visitor sees real content.
    if (!$latest) {
        $latest = tgs_generate_inline('tg_summary_seed_attempt', TG_SUMMARY_SEED_THROTTLE);
    }

    // Self-healing: table has a briefing but it's older than 65 min,
    // which means the hourly cron didn't run (broken key, cPanel cron
    // missing, network blip, etc.). Try to refresh inline — throttled
    // to 10 min so a broken cron can't spam Claude. On failure (no
    // fresh messages, API error, still throttled), we fall back to
    // returning the stale briefing so the UI is never empty.
    if ($latest) {
        $ageSecs = time() - (strtotime($latest['generated_at'] ?? '') ?: 0);
        if ($ageSecs > TG_SUMMARY_STALE_AFTER) {
            $fresh = tgs_generate_inline('tg_summary_refresh_attempt', TG_SUMMARY_REFRESH_THROTTLE);
            if ($fresh) $latest = $fresh;
        }
    }

    if (!$latest) {
        tgs_json_exit([
            'ok'    => false,
            'error' => 'لم يتم توليد أي ملخص بعد. يتم التوليد التلقائي كل ساعة.',
        ]);
    }

    tgs_json_exit(tgs_render_payload($latest));
} catch (Throwable $e) {
    error_log('[telegram_summary] ' . $e->getMessage());
    tgs_json_exit(['ok' => false, 'error' => 'server error'], 500);
}
