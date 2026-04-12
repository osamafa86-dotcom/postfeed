<?php
/**
 * نيوزفلو — طبقة تجريد لمزوّدي الذكاء الاصطناعي
 * (Unified AI provider — Anthropic Claude / Google Gemini)
 *
 * Every AI call in the app used to hit api.anthropic.com directly,
 * which worked fine until the Anthropic subscription lapsed. To keep
 * the features running on the free tier, all provider-specific curl
 * lives here and the rest of the codebase only ever calls:
 *
 *   ai_provider_tool_call($prompt, $tool, $max_tokens)
 *      → ['ok'=>bool, 'input'=>array, 'error'=>?string]
 *
 *   ai_provider_text_call($prompt, $max_tokens)
 *      → ['ok'=>bool, 'text'=>string, 'error'=>?string]
 *
 * The active provider is picked by the `ai_provider` setting (values:
 * `gemini` [default] or `anthropic`). Each provider has its own API key
 * setting (`gemini_api_key`, `anthropic_api_key`) so rotating between
 * them is just a dropdown flip in panel/ai.php.
 *
 * The tool schema is passed in Anthropic's shape (`name`, `description`,
 * `input_schema`) because that's what every existing caller already
 * builds — the Gemini adapter rewrites it into `functionDeclarations`
 * with `parameters` internally, so callers stay agnostic.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

/**
 * Return the active provider id, normalized. Falls back to Gemini so a
 * fresh install on the free tier works out of the box without any
 * settings touched.
 */
function ai_provider_active(): string {
    $p = strtolower(trim((string)getSetting('ai_provider', 'gemini')));
    return in_array($p, ['anthropic', 'gemini'], true) ? $p : 'gemini';
}

/**
 * Structured-output call. Pass the prompt plus an Anthropic-shaped tool
 * definition; get back the tool `input` as a PHP array (or an error).
 *
 * @param string $prompt      Plain text, already fully assembled.
 * @param array  $tool        ['name','description','input_schema'] (Anthropic shape).
 * @param int    $max_tokens  Upper bound on the model's output tokens.
 * @return array              ['ok'=>bool, 'input'=>array|null, 'error'=>?string]
 */
function ai_provider_tool_call(string $prompt, array $tool, int $max_tokens = 2000): array {
    $provider = ai_provider_active();
    if ($provider === 'anthropic') {
        return _ai_anthropic_tool_call($prompt, $tool, $max_tokens);
    }
    return _ai_gemini_tool_call($prompt, $tool, $max_tokens);
}

/**
 * Plain-text call. Used by ai_summarize_article() which just wants a
 * short JSON blob in a single text response — no forced tool use.
 *
 * @return array ['ok'=>bool, 'text'=>string, 'error'=>?string]
 */
function ai_provider_text_call(string $prompt, int $max_tokens = 500): array {
    $provider = ai_provider_active();
    if ($provider === 'anthropic') {
        return _ai_anthropic_text_call($prompt, $max_tokens);
    }
    return _ai_gemini_text_call($prompt, $max_tokens);
}

// ---------------------------------------------------------------------
// Anthropic adapter
// ---------------------------------------------------------------------

function _ai_anthropic_key(): string {
    $k = trim((string)getSetting('anthropic_api_key', ''));
    if ($k === '') $k = trim((string)env('ANTHROPIC_API_KEY', ''));
    return $k;
}

function _ai_anthropic_model(): string {
    // Haiku 4.5 is our default workhorse — cheap, fast, 200K context,
    // and the model all legacy call sites were already hitting.
    return (string)getSetting('anthropic_model', 'claude-haiku-4-5-20251001');
}

function _ai_anthropic_tool_call(string $prompt, array $tool, int $max_tokens): array {
    $apiKey = _ai_anthropic_key();
    if ($apiKey === '') {
        return ['ok' => false, 'input' => null, 'error' => 'مفتاح Anthropic API غير مُعدّ.'];
    }

    $toolName = (string)($tool['name'] ?? '');
    if ($toolName === '') {
        return ['ok' => false, 'input' => null, 'error' => 'tool name missing'];
    }

    $payload = json_encode([
        'model'       => _ai_anthropic_model(),
        'max_tokens'  => $max_tokens,
        'tools'       => [$tool],
        'tool_choice' => ['type' => 'tool', 'name' => $toolName],
        'messages'    => [['role' => 'user', 'content' => $prompt]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $friendly = _ai_http_to_friendly($http, $err, $resp, 'Anthropic');
    if ($friendly !== null) return ['ok' => false, 'input' => null, 'error' => $friendly];

    $data = json_decode((string)$resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'input' => null, 'error' => 'رد غير صالح من Anthropic.'];
    }

    foreach ((array)($data['content'] ?? []) as $block) {
        if (is_array($block)
            && ($block['type'] ?? '') === 'tool_use'
            && ($block['name'] ?? '') === $toolName
            && is_array($block['input'] ?? null)) {
            return ['ok' => true, 'input' => $block['input'], 'error' => null];
        }
    }

    $stop = (string)($data['stop_reason'] ?? '');
    if ($stop === 'max_tokens') {
        return ['ok' => false, 'input' => null, 'error' => 'الرد طويل جداً ولم يكتمل.'];
    }
    return ['ok' => false, 'input' => null, 'error' => 'تعذّر قراءة ردّ الأداة من Anthropic.'];
}

function _ai_anthropic_text_call(string $prompt, int $max_tokens): array {
    $apiKey = _ai_anthropic_key();
    if ($apiKey === '') {
        return ['ok' => false, 'text' => '', 'error' => 'مفتاح Anthropic API غير مُعدّ.'];
    }

    $payload = json_encode([
        'model'      => _ai_anthropic_model(),
        'max_tokens' => $max_tokens,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $friendly = _ai_http_to_friendly($http, $err, $resp, 'Anthropic');
    if ($friendly !== null) return ['ok' => false, 'text' => '', 'error' => $friendly];

    $data = json_decode((string)$resp, true);
    $text = (string)($data['content'][0]['text'] ?? '');
    if ($text === '') {
        return ['ok' => false, 'text' => '', 'error' => 'رد فارغ من Anthropic.'];
    }
    return ['ok' => true, 'text' => $text, 'error' => null];
}

// ---------------------------------------------------------------------
// Gemini adapter (generativelanguage.googleapis.com v1beta)
// ---------------------------------------------------------------------

function _ai_gemini_key(): string {
    $k = trim((string)getSetting('gemini_api_key', ''));
    if ($k === '') $k = trim((string)env('GEMINI_API_KEY', ''));
    return $k;
}

function _ai_gemini_model(): string {
    // Gemini 2.5 Flash: free tier, ~15 req/min, 1500 req/day, native
    // function calling, 1M-token context. More than enough for us.
    return (string)getSetting('gemini_model', 'gemini-2.5-flash');
}

function _ai_gemini_endpoint(string $model, string $apiKey): string {
    return 'https://generativelanguage.googleapis.com/v1beta/models/'
         . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
}

/**
 * Translate an Anthropic-shaped tool schema into Gemini's
 * functionDeclarations format. Anthropic uses `input_schema` and
 * Gemini uses `parameters`; everything inside the schema is
 * JSON-Schema-compatible and can be passed through as-is.
 *
 * We also strip any `$schema`, `additionalProperties` or `default`
 * keys that Gemini's stricter validator sometimes rejects, so the
 * existing schemas don't need to be rewritten.
 */
function _ai_gemini_sanitize_schema($schema) {
    if (!is_array($schema)) return $schema;
    // Drop keys Gemini's OpenAPI-ish validator doesn't understand.
    $drop = ['$schema', 'additionalProperties', 'default', '$id', '$ref'];
    foreach ($drop as $k) unset($schema[$k]);
    if (isset($schema['properties']) && is_array($schema['properties'])) {
        foreach ($schema['properties'] as $k => $v) {
            $schema['properties'][$k] = _ai_gemini_sanitize_schema($v);
        }
    }
    if (isset($schema['items'])) {
        $schema['items'] = _ai_gemini_sanitize_schema($schema['items']);
    }
    return $schema;
}

function _ai_gemini_tool_from_anthropic(array $tool): array {
    $name = (string)($tool['name'] ?? '');
    $desc = (string)($tool['description'] ?? '');
    $schema = _ai_gemini_sanitize_schema($tool['input_schema'] ?? ['type' => 'object']);
    return [
        'functionDeclarations' => [[
            'name'        => $name,
            'description' => $desc,
            'parameters'  => $schema,
        ]],
    ];
}

function _ai_gemini_tool_call(string $prompt, array $tool, int $max_tokens): array {
    $apiKey = _ai_gemini_key();
    if ($apiKey === '') {
        return ['ok' => false, 'input' => null, 'error' => 'مفتاح Gemini API غير مُعدّ.'];
    }
    $toolName = (string)($tool['name'] ?? '');
    if ($toolName === '') {
        return ['ok' => false, 'input' => null, 'error' => 'tool name missing'];
    }

    $geminiTool = _ai_gemini_tool_from_anthropic($tool);

    // Gemini 2.5 Flash has built-in "thinking" that consumes output
    // tokens before the actual response. For tool calls we disable
    // thinking so all tokens go to the function call payload, and we
    // add a generous buffer to avoid truncation.
    $payload = json_encode([
        'contents' => [[
            'role'  => 'user',
            'parts' => [['text' => $prompt]],
        ]],
        'tools' => [$geminiTool],
        'toolConfig' => [
            'functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => [$toolName],
            ],
        ],
        'generationConfig' => [
            'maxOutputTokens' => max($max_tokens, 8192),
            'temperature'     => 0.3,
            'thinkingConfig'  => ['thinkingBudget' => 0],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(_ai_gemini_endpoint(_ai_gemini_model(), $apiKey));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $friendly = _ai_http_to_friendly($http, $err, $resp, 'Gemini');
    if ($friendly !== null) return ['ok' => false, 'input' => null, 'error' => $friendly];

    $data = json_decode((string)$resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'input' => null, 'error' => 'رد غير صالح من Gemini.'];
    }

    // Gemini structure:
    //   candidates[0].content.parts[*].functionCall.{name,args}
    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    if (!is_array($parts)) $parts = [];
    foreach ($parts as $part) {
        if (!is_array($part)) continue;
        $fc = $part['functionCall'] ?? null;
        if (is_array($fc)
            && (string)($fc['name'] ?? '') === $toolName
            && is_array($fc['args'] ?? null)) {
            return ['ok' => true, 'input' => $fc['args'], 'error' => null];
        }
    }

    // Fallback: if Gemini returned text instead of a function call,
    // try to extract JSON from it. This handles edge cases where the
    // model ignores the forced tool mode and responds with plain text.
    $textFallback = '';
    foreach ($parts as $part) {
        if (is_array($part) && isset($part['text'])) {
            $textFallback .= (string)$part['text'];
        }
    }
    if ($textFallback !== '' && preg_match('/\{.*\}/s', $textFallback, $m)) {
        $parsed = json_decode($m[0], true);
        if (is_array($parsed) && count($parsed) >= 2) {
            return ['ok' => true, 'input' => $parsed, 'error' => null];
        }
    }

    $finish = (string)($data['candidates'][0]['finishReason'] ?? '');
    if ($finish === 'MAX_TOKENS') {
        return ['ok' => false, 'input' => null, 'error' => 'الرد طويل جداً ولم يكتمل.'];
    }
    error_log('[ai_provider] Gemini tool parse failed. finish=' . $finish
        . ' raw=' . mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 1500));
    return ['ok' => false, 'input' => null, 'error' => 'تعذّر قراءة ردّ الأداة من Gemini.'];
}

function _ai_gemini_text_call(string $prompt, int $max_tokens): array {
    $apiKey = _ai_gemini_key();
    if ($apiKey === '') {
        return ['ok' => false, 'text' => '', 'error' => 'مفتاح Gemini API غير مُعدّ.'];
    }

    $payload = json_encode([
        'contents' => [[
            'role'  => 'user',
            'parts' => [['text' => $prompt]],
        ]],
        'generationConfig' => [
            'maxOutputTokens' => $max_tokens,
            'temperature'     => 0.4,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(_ai_gemini_endpoint(_ai_gemini_model(), $apiKey));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $friendly = _ai_http_to_friendly($http, $err, $resp, 'Gemini');
    if ($friendly !== null) return ['ok' => false, 'text' => '', 'error' => $friendly];

    $data  = json_decode((string)$resp, true);
    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    $text  = '';
    if (is_array($parts)) {
        foreach ($parts as $p) {
            if (is_array($p) && isset($p['text'])) $text .= (string)$p['text'];
        }
    }
    if ($text === '') {
        return ['ok' => false, 'text' => '', 'error' => 'رد فارغ من Gemini.'];
    }
    return ['ok' => true, 'text' => $text, 'error' => null];
}

// ---------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------

/**
 * Map curl/HTTP outcome to an Arabic error string the UI can show
 * directly. Returns null when the call succeeded (HTTP 200) so the
 * caller can continue with response parsing.
 */
function _ai_http_to_friendly(int $http, string $err, $resp, string $vendor): ?string {
    if ($http === 200) return null;
    if ($http === 401 || $http === 403) {
        return "مفتاح {$vendor} API غير صالح أو منتهي الصلاحية.";
    }
    if ($http === 429) {
        return 'تم تجاوز حد الطلبات مؤقتاً. حاول لاحقاً.';
    }
    if ($http === 0 || $http >= 500) {
        return "تعذّر الاتصال بخدمة {$vendor} الآن.";
    }
    $snippet = $err ?: mb_substr((string)$resp, 0, 200);
    return "HTTP {$http}: " . $snippet;
}
