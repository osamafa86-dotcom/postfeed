<?php
/**
 * Daily Podcast — orchestrator.
 *
 * Lives in its own include so both cron_podcast.php (which
 * has a cron_key guard at the top of the file) AND the
 * admin panel can share the same codepath. Putting this in
 * cron_podcast.php caused a "forbidden" 403 whenever the
 * panel required the cron script — the top-of-file guard
 * ran on every include.
 */

require_once __DIR__ . '/podcast.php';
require_once __DIR__ . '/podcast_script.php';
require_once __DIR__ . '/podcast_tts.php';

if (!function_exists('pod_run_generate_day')) {

/**
 * Build (or rebuild) the podcast episode for a given day.
 *
 * @param string $date       YYYY-MM-DD
 * @param bool   $force      overwrite if an episode already exists
 * @param bool   $scriptOnly skip the TTS step (saves AI cost when
 *                           the operator just wants to preview)
 * @return array ['ok'=>bool, 'log'=>string]
 */
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
        'audio_path'       => '',
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

} // function_exists guard
