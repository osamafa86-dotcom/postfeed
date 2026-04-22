<?php
/**
 * Location extraction pipeline.
 *
 * Two-stage design keeps the cost profile low:
 *   1. Gazetteer pass — longest-token-first match against the
 *      static MENA dictionary. Zero-cost, handles 70-80% of
 *      Arab-news stories where a known city/country is in the
 *      headline. Multi-word entries (بيت لحم، رام الله، قطاع
 *      غزة، دير البلح) checked first so they don't lose to
 *      their single-word prefixes.
 *   2. AI fallback — only if stage 1 misses and the caller
 *      explicitly opts in via $allowAi. Uses the same
 *      ai_provider_tool_call() path as the rest of the site.
 *
 * Returns the shape that nm_save_location() expects:
 *   ['lat','lng','place_ar','place_en','country','region',
 *    'confidence','by'] or null on no match.
 */

require_once __DIR__ . '/news_map_gazetteer.php';

if (!function_exists('nm_extract_location')) {

/**
 * Run stage 1 + (optional) stage 2 on an article. $text should
 * be the title + excerpt concatenated; adding the full body
 * tends to produce noisier matches (foreign VIPs mentioned in
 * passing).
 */
function nm_extract_location(string $text, bool $allowAi = false): ?array {
    $text = trim($text);
    if ($text === '') return null;

    $hit = nm_match_gazetteer($text);
    if ($hit) return $hit;

    if ($allowAi && function_exists('ai_provider_tool_call')) {
        return nm_extract_with_ai($text);
    }
    return null;
}

/**
 * Gazetteer matcher — longest-key-first so multi-word entries
 * win. Returns the highest-confidence match (scored by key
 * length, so "بيت لحم" beats "بيت", and countries lose to
 * their cities which is what we want).
 */
function nm_match_gazetteer(string $text): ?array {
    static $sortedKeys = null;
    $gaz = nm_gazetteer_all();
    if ($sortedKeys === null) {
        $sortedKeys = array_keys($gaz);
        // Longest first so "بيت لحم" is checked before "بيت".
        usort($sortedKeys, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    }

    $normalized = nm_normalize($text);
    $best = null; $bestLen = 0;

    foreach ($sortedKeys as $key) {
        $k = nm_normalize($key);
        if ($k === '' || mb_strlen($k) <= $bestLen) continue;
        if (mb_strpos($normalized, $k) !== false) {
            $row = $gaz[$key];
            $bestLen = mb_strlen($k);
            $best = [
                'lat'        => (float)$row[0],
                'lng'        => (float)$row[1],
                'place_ar'   => (string)$row[2],
                'place_en'   => (string)$row[3],
                'country'    => (string)$row[4],
                'region'     => (string)$row[5],
                'confidence' => $bestLen >= 6 ? 0.9 : 0.75,
                'by'         => 'gazetteer',
            ];
        }
    }
    return $best;
}

/**
 * Normalise Arabic text for token matching: strip tashkeel,
 * unify alef/ya/ta-marbuta variants, collapse whitespace.
 * Without this "القدس" wouldn't match a headline with "القُدس"
 * or "الْقُدْس".
 */
function nm_normalize(string $text): string {
    // Remove diacritics.
    $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
    // Unify alef variants → bare alef.
    $text = str_replace(['أ','إ','آ','ٱ'], 'ا', $text);
    // Ya / alef-maksura.
    $text = str_replace('ى', 'ي', $text);
    // Ta-marbuta → ha (title wins both).
    $text = str_replace('ة', 'ه', $text);
    // Remove punctuation that might glue tokens.
    $text = preg_replace('/[،.,;:!?()\\[\\]«»"\'“”]/u', ' ', $text);
    // Collapse whitespace.
    $text = preg_replace('/\s+/u', ' ', $text);
    return mb_strtolower(trim($text));
}

/**
 * AI fallback. Asks the model for lat/lng + names for the
 * primary location. Returns null on empty/low-confidence
 * responses so we don't save junk into the map.
 */
function nm_extract_with_ai(string $text): ?array {
    if (!function_exists('ai_provider_tool_call')) return null;

    $prompt = "استخرج الموقع الجغرافي الرئيسي من الخبر التالي.\n"
            . "إذا لا يوجد موقع جغرافي محدّد (مثلاً خبر عام أو اقتصادي بدون\n"
            . "مكان)، اترك الحقول فارغة وضع confidence = 0.\n\n"
            . "الخبر:\n" . mb_substr($text, 0, 1200);

    $tool = [
        'name'         => 'record_location',
        'description'  => 'سجّل الموقع الجغرافي الرئيسي للخبر',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'latitude'   => ['type' => 'number', 'description' => 'خط العرض (-90 to 90)'],
                'longitude'  => ['type' => 'number', 'description' => 'خط الطول (-180 to 180)'],
                'place_ar'   => ['type' => 'string', 'description' => 'اسم المكان بالعربية'],
                'place_en'   => ['type' => 'string', 'description' => 'English name'],
                'country'    => ['type' => 'string', 'description' => 'ISO country code (2 letters)'],
                'confidence' => ['type' => 'number', 'description' => '0.0 to 1.0'],
            ],
            'required' => ['latitude', 'longitude', 'place_ar', 'confidence'],
        ],
    ];

    $call = ai_provider_tool_call($prompt, $tool, 200);
    if (empty($call['ok'])) return null;
    $data = $call['input'] ?? $call['args'] ?? null;
    if (!is_array($data)) return null;

    $conf = (float)($data['confidence'] ?? 0);
    if ($conf < 0.4) return null;

    $lat = (float)($data['latitude']  ?? 0);
    $lng = (float)($data['longitude'] ?? 0);
    if ($lat == 0.0 && $lng == 0.0) return null;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;

    return [
        'lat'        => $lat,
        'lng'        => $lng,
        'place_ar'   => (string)($data['place_ar'] ?? ''),
        'place_en'   => (string)($data['place_en'] ?? ''),
        'country'    => strtoupper(substr((string)($data['country'] ?? ''), 0, 2)),
        'region'     => '',
        'confidence' => $conf,
        'by'         => 'ai',
    ];
}

} // function_exists guard
