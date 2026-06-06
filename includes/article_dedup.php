<?php
/**
 * Cross-section article dedup helpers.
 *
 * The homepage (web + API) surfaces several news rails — hero, breaking,
 * latest, six topical buckets, plus the personalized feed. Without
 * dedup, the same story shows up in 3-4 of them when it happens to fit
 * multiple filters. The web index.php has been deduping by title-token
 * Jaccard for a while; this module lifts that logic into a reusable
 * helper so /api/v1/content/home (mobile app) gets the same behaviour
 * the website has — exactly what the user requested.
 *
 * The clustering pipeline (article_cluster.php) already groups same-
 * story articles from different sources under a single cluster_key,
 * so we use that for the first-pass exact dedup; the Jaccard pass is
 * a defensive net that catches cases where two sources cover the
 * same story but the cluster_key drifted (e.g. published hours apart,
 * different titles after editorial rewrites).
 */

if (!function_exists('article_dedup_tokens')) {
    /**
     * Tokenize an Arabic headline for fuzzy dedup. Strips diacritics,
     * normalizes letter variants, peels off the common Arabic article
     * prefixes ("ال", "وال", "بال", …) so "الأقصى" and "أقصى" share
     * tokens. Returns the unique token set ready for Jaccard.
     */
    function article_dedup_tokens(string $title): array {
        // Strip Arabic diacritics (harakat + tatweel).
        $t = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $title);
        // Normalize Arabic letter variants.
        $t = strtr((string)$t, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي', 'ئ' => 'ي',
            'ؤ' => 'و',
        ]);
        // Replace punctuation / symbols with spaces.
        $t = preg_replace('/[\p{P}\p{S}«»"\'""""‚„]/u', ' ', $t);
        $t = preg_replace('/\s+/u', ' ', trim((string)$t));
        $t = mb_strtolower((string)$t);
        $tokens = preg_split('/\s+/u', $t) ?: [];

        // Arabic stop words (articles, prepositions, common fillers).
        $stop = ['في','من','على','الى','إلى','عن','مع','بعد','قبل','هذا','هذه','ذلك','تلك','التي','الذي','بين','كل','او','أو','ما','ان','أن','إن','قد','هو','هي','هم','لم','لن','لا','وقد','الف','هذي'];
        // Multi-char prefixes first (longest match wins), then single-letter
        // conjunctions/prepositions. Keeps a floor of 3 chars after stripping.
        $multi  = ['وال','فال','بال','كال','لل'];
        $single = ['و','ف','ب','ل','ك','س'];

        $out = [];
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) < 3) continue;
            if (in_array($tok, $stop, true)) continue;
            foreach ($multi as $p) {
                $pl = mb_strlen($p);
                if (mb_strlen($tok) >= $pl + 3 && mb_substr($tok, 0, $pl) === $p) {
                    $tok = mb_substr($tok, $pl);
                    break;
                }
            }
            if (mb_strlen($tok) >= 5 && mb_substr($tok, 0, 2) === 'ال') {
                $tok = mb_substr($tok, 2);
            }
            if (mb_strlen($tok) >= 5) {
                $first = mb_substr($tok, 0, 1);
                if (in_array($first, $single, true)) {
                    $tok = mb_substr($tok, 1);
                }
            }
            if (mb_strlen($tok) < 3) continue;
            $out[$tok] = true;
        }
        return array_keys($out);
    }
}

if (!function_exists('article_dedup_is_duplicate')) {
    /**
     * Decide whether the given token set Jaccard-matches anything in
     * $seenTokensList above the threshold. 0.55 is the same threshold
     * the website index.php uses — high enough to allow genuinely
     * different stories that happen to share a couple of words.
     */
    function article_dedup_is_duplicate(array $tokens, array $seenTokensList, float $threshold = 0.55): bool {
        if (!$tokens) return false;
        foreach ($seenTokensList as $seen) {
            if (!$seen) continue;
            $inter = array_intersect($seen, $tokens);
            if (!$inter) continue;
            $union = array_unique(array_merge($seen, $tokens));
            if (!$union) continue;
            if (count($inter) / count($union) >= $threshold) return true;
        }
        return false;
    }
}

if (!function_exists('article_dedup_filter')) {
    /**
     * Filter $list against an existing &$usedIds map + &$usedTokens list,
     * keeping at most $keep articles. Updates both state maps so the
     * caller can chain dedup across multiple rails in declaration order
     * (the first rail to claim a story owns it; later rails skip it).
     *
     * Also dedupes by exact cluster_key when present — two articles in
     * the same cluster definitely cover the same story.
     */
    function article_dedup_filter(array $list, int $keep, array &$usedIds, array &$usedTokens, array &$usedClusters): array {
        $out = [];
        foreach ($list as $a) {
            $id = (int)($a['id'] ?? 0);
            if ($id && isset($usedIds[$id])) continue;

            $ck = (string)($a['cluster_key'] ?? '');
            if ($ck !== '' && $ck !== '-' && isset($usedClusters[$ck])) continue;

            $tokens = article_dedup_tokens((string)($a['title'] ?? ''));
            if (article_dedup_is_duplicate($tokens, $usedTokens)) continue;

            if ($id) $usedIds[$id] = true;
            if ($ck !== '' && $ck !== '-') $usedClusters[$ck] = true;
            $usedTokens[] = $tokens;
            $out[] = $a;
            if (count($out) >= $keep) break;
        }
        return $out;
    }
}

if (!function_exists('article_dedup_seed')) {
    /**
     * Pre-populate the dedup state from an already-rendered list (e.g.
     * the hero article). Future calls to article_dedup_filter will
     * automatically skip anything that matches it.
     */
    function article_dedup_seed(array $list, array &$usedIds, array &$usedTokens, array &$usedClusters): void {
        foreach ($list as $a) {
            $id = (int)($a['id'] ?? 0);
            if ($id) $usedIds[$id] = true;
            $ck = (string)($a['cluster_key'] ?? '');
            if ($ck !== '' && $ck !== '-') $usedClusters[$ck] = true;
            $title = (string)($a['title'] ?? '');
            if ($title !== '') $usedTokens[] = article_dedup_tokens($title);
        }
    }
}
