<?php
/**
 * نيوز فيد - Text-to-Speech helper
 * ================================
 * Multi-provider cloud TTS with on-disk MP3 caching.
 *
 * Design goals:
 *   - Keep the existing browser Web Speech API path as a graceful
 *     fallback: if cloud TTS is disabled or all providers fail, the
 *     frontend can still use `window.speechSynthesis`.
 *   - Pay the API cost exactly once per article. The MP3 is written
 *     to storage/tts/{hash}.mp3 and every subsequent request is a
 *     plain file read served through api/tts.php.
 *   - Swap providers without touching the article page. The admin
 *     picks the provider in panel/tts.php, voice IDs and API keys
 *     are stored in the settings table.
 *
 * Supported providers (all high-quality Arabic):
 *   - elevenlabs  (eleven_multilingual_v2, default voice "Rachel")
 *   - google      (Cloud TTS Neural2 ar-XA-Wavenet-B)
 *   - openai      (tts-1-hd, voice "alloy")
 *
 * Entry points:
 *   tts_get_or_generate($article)   -> ['path'=>..., 'url'=>..., 'cached'=>bool, 'bytes'=>int]
 *   tts_cache_stats()               -> ['count'=>int, 'bytes'=>int]
 *   tts_build_text($article)        -> string (title + summary/excerpt, capped)
 */

require_once __DIR__ . '/functions.php';

// ---------------------------------------------------------------------
// Cache directory
// ---------------------------------------------------------------------

function tts_cache_dir(): string {
    // Live under storage/ so existing "Deny from all" htaccess protects
    // the raw files from direct web access. Audio is streamed by the
    // api/tts.php wrapper instead.
    $dir = __DIR__ . '/../storage/tts';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Hash key for a given provider+voice+text combination. Changing any
 * of the three busts the cache automatically, so rotating to a new
 * voice immediately re-generates the MP3 on next request.
 */
function tts_cache_key(string $provider, string $voice, string $text): string {
    return sha1($provider . '|' . $voice . '|' . $text);
}

function tts_cache_path(string $key): string {
    return tts_cache_dir() . '/' . $key . '.mp3';
}

// ---------------------------------------------------------------------
// Settings access
// ---------------------------------------------------------------------

function tts_is_enabled(): bool {
    return (int)getSetting('tts_enabled', '0') === 1;
}

function tts_provider(): string {
    $p = strtolower(trim((string)getSetting('tts_provider', 'google')));
    return in_array($p, ['elevenlabs', 'google', 'openai'], true) ? $p : 'google';
}

/**
 * Resolve a voice id for the current provider. Falls back to sensible
 * defaults so a fresh install works as soon as an API key is pasted in.
 */
function tts_voice_id(string $provider): string {
    $configured = trim((string)getSetting('tts_voice_' . $provider, ''));
    if ($configured !== '') return $configured;
    switch ($provider) {
        case 'elevenlabs':
            // "Rachel" — ElevenLabs' default multilingual voice.
            return '21m00Tcm4TlvDq8ikWAM';
        case 'google':
            // Arabic MSA male Wavenet voice. Wavenet is cheap and good.
            return 'ar-XA-Wavenet-B';
        case 'openai':
            return 'alloy';
    }
    return '';
}

// ---------------------------------------------------------------------
// Text preparation
// ---------------------------------------------------------------------

/**
 * Build the text that will actually be read aloud. We intentionally
 * keep this short (title + AI summary or excerpt, capped at 2000
 * chars) because:
 *   - ElevenLabs bills per character (≈$0.30 / 1K chars)
 *   - Google caps a single request at 5000 chars
 *   - Listeners bounce if the audio is > 90s
 * The full article body is therefore NOT sent to the TTS API.
 */
function tts_build_text(array $article): string {
    $parts = [];
    $title = trim((string)($article['title'] ?? ''));
    if ($title !== '') $parts[] = $title;

    // Prefer the AI summary (already clean Arabic prose). Fall back to
    // the excerpt, then a truncated version of the raw content.
    $body = trim((string)($article['ai_summary'] ?? ''));
    if ($body === '') $body = trim((string)($article['excerpt'] ?? ''));
    if ($body === '') {
        $raw = trim(strip_tags((string)($article['content'] ?? '')));
        $body = mb_substr(preg_replace('/\s+/u', ' ', $raw), 0, 1500);
    }
    if ($body !== '') $parts[] = $body;

    $text = implode('. ', $parts);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim((string)$text);

    // Hard cap: 2000 chars ≈ ~60–75 seconds of Arabic speech.
    if (mb_strlen($text) > 2000) {
        $text = mb_substr($text, 0, 2000);
    }
    return $text;
}

// ---------------------------------------------------------------------
// Main entry: get cached MP3 or generate via the configured provider
// ---------------------------------------------------------------------

/**
 * @return array|null ['path'=>..., 'key'=>..., 'cached'=>bool,
 *                     'bytes'=>int, 'provider'=>..., 'voice'=>...]
 *                    or null on failure (cloud TTS disabled, no text,
 *                    or the provider returned an error).
 */
function tts_get_or_generate(array $article): ?array {
    if (!tts_is_enabled()) return null;

    $text = tts_build_text($article);
    if ($text === '') return null;

    $provider = tts_provider();
    $voice    = tts_voice_id($provider);
    $key      = tts_cache_key($provider, $voice, $text);
    $path     = tts_cache_path($key);

    if (is_file($path) && filesize($path) > 0) {
        return [
            'path'     => $path,
            'key'      => $key,
            'cached'   => true,
            'bytes'    => (int)filesize($path),
            'provider' => $provider,
            'voice'    => $voice,
        ];
    }

    // Generate via the selected provider.
    $mp3 = null;
    switch ($provider) {
        case 'elevenlabs': $mp3 = tts_generate_elevenlabs($text, $voice); break;
        case 'google':     $mp3 = tts_generate_google($text, $voice);     break;
        case 'openai':     $mp3 = tts_generate_openai($text, $voice);     break;
    }

    if ($mp3 === null || $mp3 === '') {
        return null;
    }

    // Write atomically so a concurrent request can never observe a
    // half-written file.
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $mp3, LOCK_EX) === false) {
        return null;
    }
    @rename($tmp, $path);

    return [
        'path'     => $path,
        'key'      => $key,
        'cached'   => false,
        'bytes'    => strlen($mp3),
        'provider' => $provider,
        'voice'    => $voice,
    ];
}

// ---------------------------------------------------------------------
// Provider: ElevenLabs
// ---------------------------------------------------------------------

function tts_generate_elevenlabs(string $text, string $voice): ?string {
    $apiKey = trim((string)getSetting('tts_elevenlabs_key', ''));
    if ($apiKey === '') $apiKey = trim((string)env('ELEVENLABS_API_KEY', ''));
    if ($apiKey === '') {
        error_log('[tts] ElevenLabs: API key not configured');
        return null;
    }
    $model = trim((string)getSetting('tts_elevenlabs_model', 'eleven_multilingual_v2'));
    if ($model === '') $model = 'eleven_multilingual_v2';

    $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voice)
         . '?output_format=mp3_44100_128';
    $body = json_encode([
        'text'           => $text,
        'model_id'       => $model,
        'voice_settings' => [
            'stability'        => 0.5,
            'similarity_boost' => 0.75,
            'style'            => 0.0,
            'use_speaker_boost'=> true,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: audio/mpeg',
            'xi-api-key: ' . $apiKey,
        ],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($http !== 200 || !is_string($resp) || $resp === '') {
        error_log('[tts] ElevenLabs HTTP ' . $http . ': ' . ($err ?: mb_substr((string)$resp, 0, 200)));
        return null;
    }
    return $resp;
}

// ---------------------------------------------------------------------
// Provider: Google Cloud Text-to-Speech
// ---------------------------------------------------------------------

function tts_generate_google(string $text, string $voice): ?string {
    $apiKey = trim((string)getSetting('tts_google_key', ''));
    if ($apiKey === '') $apiKey = trim((string)env('GOOGLE_TTS_API_KEY', ''));
    if ($apiKey === '') {
        error_log('[tts] Google: API key not configured');
        return null;
    }

    // Derive the language code from the voice name (e.g. ar-XA-...).
    $langCode = 'ar-XA';
    if (preg_match('/^([a-z]{2}-[A-Z]{2})/', $voice, $m)) {
        $langCode = $m[1];
    }

    $url = 'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . rawurlencode($apiKey);
    $body = json_encode([
        'input' => ['text' => $text],
        'voice' => [
            'languageCode' => $langCode,
            'name'         => $voice,
        ],
        'audioConfig' => [
            'audioEncoding'   => 'MP3',
            'speakingRate'    => 1.0,
            'pitch'           => 0.0,
            'sampleRateHertz' => 24000,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($http !== 200 || !is_string($resp) || $resp === '') {
        error_log('[tts] Google HTTP ' . $http . ': ' . ($err ?: mb_substr((string)$resp, 0, 200)));
        return null;
    }
    $data = json_decode($resp, true);
    $b64  = $data['audioContent'] ?? '';
    if (!is_string($b64) || $b64 === '') {
        error_log('[tts] Google: empty audioContent in response');
        return null;
    }
    $mp3 = base64_decode($b64, true);
    return $mp3 !== false && $mp3 !== '' ? $mp3 : null;
}

// ---------------------------------------------------------------------
// Provider: OpenAI
// ---------------------------------------------------------------------

function tts_generate_openai(string $text, string $voice): ?string {
    $apiKey = trim((string)getSetting('tts_openai_key', ''));
    if ($apiKey === '') $apiKey = trim((string)env('OPENAI_API_KEY', ''));
    if ($apiKey === '') {
        error_log('[tts] OpenAI: API key not configured');
        return null;
    }
    $model = trim((string)getSetting('tts_openai_model', 'tts-1-hd'));
    if ($model === '') $model = 'tts-1-hd';

    $url  = 'https://api.openai.com/v1/audio/speech';
    $body = json_encode([
        'model'           => $model,
        'input'           => $text,
        'voice'           => $voice,
        'response_format' => 'mp3',
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($http !== 200 || !is_string($resp) || $resp === '') {
        error_log('[tts] OpenAI HTTP ' . $http . ': ' . ($err ?: mb_substr((string)$resp, 0, 200)));
        return null;
    }
    // Sanity check: if the body parses as JSON we got an error
    // response instead of audio bytes.
    if ($resp[0] === '{') {
        error_log('[tts] OpenAI: got JSON instead of audio — ' . mb_substr($resp, 0, 200));
        return null;
    }
    return $resp;
}

// ---------------------------------------------------------------------
// Stats for the admin panel
// ---------------------------------------------------------------------

function tts_cache_stats(): array {
    $dir   = tts_cache_dir();
    $count = 0;
    $bytes = 0;
    foreach (glob($dir . '/*.mp3') ?: [] as $f) {
        $count++;
        $bytes += (int)@filesize($f);
    }
    return ['count' => $count, 'bytes' => $bytes];
}

function tts_cache_clear(): int {
    $dir = tts_cache_dir();
    $n = 0;
    foreach (glob($dir . '/*.mp3') ?: [] as $f) {
        if (@unlink($f)) $n++;
    }
    // Also clean up any stragglers from interrupted writes.
    foreach (glob($dir . '/*.mp3.tmp') ?: [] as $f) {
        @unlink($f);
    }
    return $n;
}
