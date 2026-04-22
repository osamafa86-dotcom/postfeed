<?php
/**
 * نيوز فيد — Daily Podcast generator.
 *
 * Composes today's (or a specified date's) episode and
 * writes the MP3 to storage/podcast/<date>.mp3.
 *
 * Safety:
 *   - Skip if an episode already exists for the target date
 *     (unless --force / ?force=1).
 *   - Skip if TTS is disabled in settings (script is still
 *     saved so the operator can preview before turning TTS on,
 *     behind --script-only).
 *   - Soft-fail on AI rate limit so the crontab line doesn't
 *     spam the error log.
 *
 * Invocation:
 *   CLI:   php cron_podcast.php [--force] [--date=2026-04-22] [--script-only]
 *   HTTP:  curl "…/cron_podcast.php?key=CRON_KEY[&force=1][&date=YYYY-MM-DD]"
 *
 * Recommended schedule: 0 6 * * *  (6 AM local time)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/podcast.php';
require_once __DIR__ . '/includes/podcast_script.php';
require_once __DIR__ . '/includes/podcast_tts.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(600);

// ---- Flags ---------------------------------------------------
$force      = !empty($_GET['force']);
$scriptOnly = !empty($_GET['script_only']) || !empty($_GET['script-only']);
$targetDate = $_GET['date'] ?? null;
if (PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $a) {
        if ($a === '--force') $force = true;
        if ($a === '--script-only') $scriptOnly = true;
        if (strpos($a, '--date=') === 0) $targetDate = substr($a, 7);
    }
}
if (!$targetDate) $targetDate = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$targetDate)) {
    echo "bad date: {$targetDate}\n"; exit(1);
}

$r = pod_run_generate_day($targetDate, $force, $scriptOnly);
echo $r['log'] . "\n";
exit($r['ok'] ? 0 : 1);

// ---- Orchestrator ------------------------------------------------
// In its own namespace-like block so the panel + the daily +
// the "retry today" button all share one codepath.
function pod_run_generate_day(string $date, bool $force, bool $scriptOnly): array {
    $log = ["📻 podcast for {$date}"];
    $existing = pod_get_by_date($date);
    if ($existing && !empty($existing['audio_path']) && !$force) {
        $log[] = "skip: episode already exists (#{$existing['id']}). Use --force to overwrite.";
        return ['ok' => false, 'log' => implode("\n", $log)];
    }

    // 1) Collect candidate stories.
    $candidates = pod_collect_candidates(24, 30);
    $log[] = "candidates: " . count($candidates);
    if (count($candidates) < 5) {
        $log[] = "fail: not enough stories in the last 24h (need 5+).";
        return ['ok' => false, 'log' => implode("\n", $log)];
    }

    // 2) AI writes the script.
    $humanDate = pod_human_date($date);
    $t0 = microtime(true);
    $script = pod_generate_script($candidates, $humanDate);
    $aiMs = (int)round((microtime(true) - $t0) * 1000);
    if (empty($script['ok'])) {
        $log[] = "fail: script generation — " . ($script['error'] ?? '?') . " ({$aiMs}ms)";
        return ['ok' => false, 'log' => implode("\n", $log)];
    }
    $payload = $script['payload'];
    $log[] = sprintf("script: %d segments (%dms)", count($payload['segments']), $aiMs);

    // 3) Flatten → speech text + chapter map.
    $flat = pod_script_to_speech($payload);
    $scriptText = $flat['speech'];
    $chapters   = $flat['chapters'];
    $estSec     = (int)$flat['estimated_duration'];

    // 4) Persist script NOW so the operator can preview even if
    //    TTS fails or is disabled.
    $savedId = pod_save($date, [
        'title'            => $payload['title']    ?: ('موجز ' . $humanDate),
        'subtitle'         => $payload['subtitle'] ?? '',
        'intro'            => $payload['intro']    ?? '',
        'script_text'      => $scriptText,
        'chapters'         => $chapters,
        'article_ids'      => $payload['article_ids'] ?? [],
        'audio_path'       => '',  // filled in below if TTS succeeds
        'audio_bytes'      => 0,
        'duration_seconds' => $estSec,
        'tts_provider'     => '',
        'tts_voice'        => '',
    ]);
    $log[] = "saved script row #{$savedId}";

    if ($scriptOnly) {
        $log[] = "done (script-only)";
        return ['ok' => true, 'log' => implode("\n", $log)];
    }

    // 5) TTS → MP3.
    $t0 = microtime(true);
    $audio = pod_synthesize($scriptText, $date);
    $ttsMs = (int)round((microtime(true) - $t0) * 1000);
    if (empty($audio['ok'])) {
        $log[] = "fail: TTS — " . ($audio['error'] ?? '?') . " ({$ttsMs}ms)";
        $log[] = "(script is saved; re-run after enabling / reconfiguring TTS)";
        return ['ok' => false, 'log' => implode("\n", $log)];
    }
    $log[] = sprintf(
        "audio: %s chunks, %d KB (%dms)",
        $audio['chunks'], (int)round($audio['bytes'] / 1024), $ttsMs
    );

    // 6) Prefer real duration from ffprobe; fall back to estimate.
    $duration = pod_mp3_duration_seconds($audio['abs_path'], $estSec);

    // 7) Update the row with the real MP3 info.
    pod_save($date, [
        'title'            => $payload['title']    ?: ('موجز ' . $humanDate),
        'subtitle'         => $payload['subtitle'] ?? '',
        'intro'            => $payload['intro']    ?? '',
        'script_text'      => $scriptText,
        'chapters'         => $chapters,
        'article_ids'      => $payload['article_ids'] ?? [],
        'audio_path'       => $audio['path'],
        'audio_bytes'      => (int)$audio['bytes'],
        'duration_seconds' => $duration,
        'tts_provider'     => (string)$audio['provider'],
        'tts_voice'        => (string)$audio['voice'],
    ]);

    $mins = (int)floor($duration / 60);
    $secs = $duration % 60;
    $log[] = sprintf("✓ done: %d:%02d @ /%s", $mins, $secs, $audio['path']);
    return ['ok' => true, 'log' => implode("\n", $log)];
}

/** "الأربعاء ٢٢ أبريل ٢٠٢٦" */
function pod_human_date(string $ymd): string {
    $ts = strtotime($ymd);
    if (!$ts) return $ymd;
    $days   = ['Sunday'=>'الأحد','Monday'=>'الإثنين','Tuesday'=>'الثلاثاء','Wednesday'=>'الأربعاء','Thursday'=>'الخميس','Friday'=>'الجمعة','Saturday'=>'السبت'];
    $months = [1=>'يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
    $day    = $days[date('l', $ts)] ?? '';
    $d      = (int)date('j', $ts);
    $m      = $months[(int)date('n', $ts)] ?? '';
    $y      = date('Y', $ts);
    return trim("{$day} {$d} {$m} {$y}");
}
