<?php
/**
 * Temporary diagnostic: test each AI provider directly with a tiny
 * prompt so we can see exactly which one works. Delete after use.
 *
 * Run: php diag_ai.php
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ai_provider.php';

echo "=== الإعدادات ===\n";
echo "ai_provider (الأساسي): " . getSetting('ai_provider', 'gemini') . "\n";
$gkey = getSetting('gemini_api_key', '');
$akey = getSetting('anthropic_api_key', '');
echo "gemini_api_key:    " . ($gkey !== '' ? mb_substr($gkey, 0, 10) . '... (' . mb_strlen($gkey) . ' حرف)' : 'فارغ') . "\n";
echo "anthropic_api_key: " . ($akey !== '' ? mb_substr($akey, 0, 14) . '... (' . mb_strlen($akey) . ' حرف)' : 'فارغ') . "\n";
echo "anthropic_model:   " . getSetting('anthropic_model', 'claude-haiku-4-5-20251001') . "\n\n";

$tool = [
    'name' => 'echo_back',
    'description' => 'Echo a short greeting.',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'greeting' => ['type' => 'string', 'description' => 'كلمة ترحيب قصيرة'],
        ],
        'required' => ['greeting'],
    ],
];

echo "=== اختبار Gemini مباشرة ===\n";
$g = _ai_gemini_tool_call('قل مرحبا', $tool, 100);
echo "ok: " . (!empty($g['ok']) ? 'نعم ✅' : 'لا ❌') . "\n";
if (empty($g['ok'])) echo "error: " . ($g['error'] ?? '?') . "\n";
else echo "input: " . json_encode($g['input'], JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== اختبار Anthropic مباشرة ===\n";
$a = _ai_anthropic_tool_call('قل مرحبا', $tool, 100);
echo "ok: " . (!empty($a['ok']) ? 'نعم ✅' : 'لا ❌') . "\n";
if (empty($a['ok'])) echo "error: " . ($a['error'] ?? '?') . "\n";
else echo "input: " . json_encode($a['input'], JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== الخلاصة ===\n";
if (!empty($a['ok'])) {
    echo "✅ Anthropic يعمل — الـ failover سيشتغل. المشكلة فقط أن Gemini مزحوم.\n";
} elseif (!empty($g['ok'])) {
    echo "✅ Gemini يعمل الآن (الحد تجدّد).\n";
} else {
    echo "❌ كلا المزوّدين فشلا. Anthropic error أعلاه يوضّح السبب.\n";
}
