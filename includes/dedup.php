<?php
/**
 * Query-time near-duplicate collapsing for the social feeds.
 *
 * The same story reaches us from 5-10 channels with near-identical
 * wording. This collapses such posts within a returned batch into one
 * representative (the newest), annotating it with how many other
 * sources reported it. It is intentionally batch-local and lossless for
 * cursor pagination: representatives preserve order, so the smallest
 * representative id is always a valid "before_id" for the next page —
 * only absorbed duplicates (which we want gone) are skipped.
 */

declare(strict_types=1);

/** Normalize Arabic/social text so trivial differences don't block a match. */
function nf_normalize_for_dedup(string $text): string {
    // Drop URLs and @/# symbols (keep the following word).
    $t = preg_replace('#https?://\S+#u', ' ', $text) ?? $text;
    $t = preg_replace('/[@#]/u', ' ', $t) ?? $t;
    // Strip Arabic diacritics (tashkeel) and tatweel.
    $t = preg_replace('/[\x{0617}-\x{061A}\x{064B}-\x{0652}\x{0640}]/u', '', $t) ?? $t;
    // Unify alef / ya / ta-marbuta variants.
    $t = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $t);
    $t = str_replace(['ى'], 'ي', $t);
    $t = str_replace(['ة'], 'ه', $t);
    // Keep only letters, digits and whitespace (drops emoji/punctuation).
    $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t) ?? $t;
    $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
    return trim(mb_strtolower($t));
}

/**
 * Collapse near-duplicate messages. Each input item is an API-shaped row
 * with at least `text` and `source.display_name`. Returns the same shape
 * with two extra keys on every item:
 *   - duplicate_count  : how many other posts were merged in
 *   - also_reported_by : display names of those other sources
 *
 * @param float $threshold Jaccard similarity (word sets) to treat as duplicate.
 */
function nf_dedup_messages(array $messages, float $threshold = 0.6): array {
    $result = [];   // representatives, in order
    $repTokens = []; // parallel: token-set (word => 1) per representative

    foreach ($messages as $msg) {
        $norm = nf_normalize_for_dedup((string)($msg['text'] ?? ''));

        // Very short / image-only posts are always treated as unique —
        // there's not enough signal to safely merge them.
        if (mb_strlen($norm) < 12) {
            $msg['duplicate_count']  = 0;
            $msg['also_reported_by'] = [];
            $result[]   = $msg;
            $repTokens[] = null;
            continue;
        }

        $words = array_values(array_unique(array_filter(
            explode(' ', $norm),
            fn($w) => mb_strlen($w) >= 2
        )));
        $tokens = array_fill_keys($words, true);

        $matched = null;
        foreach ($repTokens as $k => $rt) {
            if ($rt === null) continue;
            $inter = 0;
            foreach ($tokens as $w => $_) {
                if (isset($rt[$w])) $inter++;
            }
            $union = count($tokens) + count($rt) - $inter;
            $sim = $union > 0 ? $inter / $union : 0.0;
            if ($sim >= $threshold) { $matched = $k; break; }
        }

        if ($matched !== null) {
            $result[$matched]['duplicate_count']++;
            $name = (string)($msg['source']['display_name'] ?? '');
            if ($name !== '' && !in_array($name, $result[$matched]['also_reported_by'], true)) {
                $result[$matched]['also_reported_by'][] = $name;
            }
        } else {
            $msg['duplicate_count']  = 0;
            $msg['also_reported_by'] = [];
            $result[]    = $msg;
            $repTokens[] = $tokens;
        }
    }

    return $result;
}
