<?php
/**
 * Daily Podcast — TTS assembler.
 *
 * Converts the flat speech string from podcast_script.php
 * into a single MP3 at storage/podcast/<date>.mp3 using
 * whichever cloud TTS provider the admin has enabled in
 * /panel/tts.php. Works with all three supported providers
 * (ElevenLabs, Google Cloud, OpenAI) — we reuse the same
 * low-level generators already battle-tested by the per-
 * article audio path.
 *
 * Design notes:
 *   - Long scripts (~1000 words ≈ 6000 chars) exceed the
 *     per-request cap of every provider, so we split on
 *     paragraph boundaries and call TTS once per chunk.
 *   - The MP3 binary chunks are concatenated byte-by-byte.
 *     All three providers emit MP3 frames that decode
 *     correctly when appended; the only side effect is a
 *     sub-second drift in the ID3 duration tag, which we
 *     fix by storing the calculated duration in the DB and
 *     exposing it to the player via <audio preload>.
 *   - Chunk size ≤ 4500 chars so we stay comfortably under
 *     ElevenLabs's 5000-char cap and OpenAI's 4096.
 */

require_once __DIR__ . '/tts.php';

if (!function_exists('pod_synthesize')) {

function pod_synthesize(string $speech, string $date): array {
    if (!tts_is_enabled()) {
        return ['ok' => false, 'error' => 'TTS not enabled (/panel/tts.php)'];
    }
    $provider = tts_provider();
    $voice    = tts_voice_id($provider);
    if (!$voice) {
        return ['ok' => false, 'error' => 'TTS voice not configured for provider: ' . $provider];
    }

    $chunks = pod_chunk_for_tts($speech, 4500);
    if (!$chunks) {
        return ['ok' => false, 'error' => 'empty speech'];
    }

    $mp3Bytes = '';
    foreach ($chunks as $i => $chunk) {
        $bytes = pod_call_tts_provider($provider, $chunk, $voice);
        if ($bytes === null || $bytes === '') {
            return [
                'ok'    => false,
                'error' => "TTS provider failed on chunk " . ($i + 1) . "/" . count($chunks),
            ];
        }
        $mp3Bytes .= $bytes;
        // Tiny pause between chunks helps providers that rate-limit
        // per-request rather than per-minute.
        if ($i < count($chunks) - 1) usleep(250000);
    }

    $dir = pod_audio_dir();
    $filename = $date . '.mp3';
    $target = $dir . '/' . $filename;
    if (@file_put_contents($target, $mp3Bytes) === false) {
        return ['ok' => false, 'error' => 'could not write audio file: ' . $target];
    }

    return [
        'ok'        => true,
        'path'      => pod_audio_public_path($date),
        'abs_path'  => $target,
        'bytes'     => strlen($mp3Bytes),
        'provider'  => $provider,
        'voice'     => $voice,
        'chunks'    => count($chunks),
    ];
}

/**
 * Split long speech text on paragraph boundaries, packing
 * pieces into <= $max char chunks. Preserves the paragraph
 * break so the TTS engine inserts a natural pause between
 * sections instead of treating them as a single run-on.
 */
function pod_chunk_for_tts(string $speech, int $max = 4500): array {
    $speech = trim($speech);
    if ($speech === '') return [];
    $paragraphs = preg_split("/\n\s*\n+/u", $speech) ?: [$speech];
    $out = [];
    $current = '';

    foreach ($paragraphs as $p) {
        $p = trim($p);
        if ($p === '') continue;
        // If a single paragraph is > $max, hard-split on sentence.
        if (mb_strlen($p) > $max) {
            $sentences = preg_split('/(?<=[.!?؟۔])\s+/u', $p) ?: [$p];
            foreach ($sentences as $s) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($s) + 2 > $max) {
                    $out[] = $current; $current = '';
                }
                $current = $current === '' ? $s : ($current . ' ' . $s);
            }
            continue;
        }
        if ($current !== '' && mb_strlen($current) + mb_strlen($p) + 2 > $max) {
            $out[] = $current; $current = '';
        }
        $current = $current === '' ? $p : ($current . "\n\n" . $p);
    }
    if ($current !== '') $out[] = $current;
    return $out;
}

function pod_call_tts_provider(string $provider, string $text, string $voice): ?string {
    switch ($provider) {
        case 'elevenlabs': return tts_generate_elevenlabs($text, $voice);
        case 'google':     return tts_generate_google($text, $voice);
        case 'openai':     return tts_generate_openai($text, $voice);
    }
    error_log('[podcast_tts] unknown provider: ' . $provider);
    return null;
}

/**
 * Best-effort MP3 duration from ID3 / frame headers. Falls
 * back to the word-count estimate the script generator
 * already computed. Used for the <audio> preload hint and
 * the RSS enclosure duration tag.
 *
 * If ffprobe is available on the host we prefer that because
 * it reads the real frame timing. Otherwise we use an
 * approximation: bytes / (bitrate / 8). Assumes 128 kbps
 * which is what all three providers emit by default.
 */
function pod_mp3_duration_seconds(string $absPath, int $fallback = 0): int {
    if (!is_file($absPath)) return $fallback;
    $bytes = filesize($absPath);

    $ffprobe = trim((string)(@shell_exec('which ffprobe 2>/dev/null') ?? ''));
    if ($ffprobe !== '') {
        $cmd = escapeshellarg($ffprobe) . ' -v error -show_entries format=duration '
             . "-of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($absPath) . ' 2>/dev/null';
        $out = (string)(@shell_exec($cmd) ?? '');
        $secs = (float)trim($out);
        if ($secs > 0) return (int)round($secs);
    }

    // Fallback: 128 kbps = 16 KB/sec → seconds = bytes / 16000
    $approx = (int)round($bytes / 16000);
    return $approx > 0 ? $approx : $fallback;
}

} // function_exists guard
