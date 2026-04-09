<?php
/**
 * Article clustering helper.
 *
 * Computes a stable "cluster_key" fingerprint for an article title
 * so the same story republished by N sources collapses into one
 * group. The fingerprint is built from normalized Arabic tokens
 * (diacritics stripped, common variants unified, stop words and
 * leading particles removed) sorted alphabetically and hashed.
 *
 * Lifecycle:
 *   - cron_rss.php computes the key on insert.
 *   - migrate.php backfills any rows missing it.
 *   - index.php / category.php read $GLOBALS['__nf_cluster_counts']
 *     populated by cluster_counts_for() to render the
 *     "نُشر في N مصادر" badge on cards.
 *
 * The token rules deliberately mirror the existing nf_title_tokens
 * routine in index.php so legacy in-memory dedup and the new DB
 * column agree on what counts as "the same story".
 */

if (!function_exists('article_cluster_tokens')) {
    function article_cluster_tokens(string $title): array {
        // Strip Arabic diacritics + tatweel.
        $t = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $title);
        // Normalize letter variants.
        $t = strtr((string)$t, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي', 'ئ' => 'ي',
            'ؤ' => 'و',
        ]);
        // Punctuation -> spaces.
        $t = preg_replace('/[\p{P}\p{S}«»"\'"""‚„]/u', ' ', $t);
        $t = preg_replace('/\s+/u', ' ', trim((string)$t));
        $t = mb_strtolower((string)$t);

        $tokens = preg_split('/\s+/u', (string)$t) ?: [];
        $stop = ['في','من','على','الى','إلى','عن','مع','بعد','قبل','هذا','هذه','ذلك','تلك','التي','الذي','بين','كل','او','أو','ما','ان','أن','إن','قد','هو','هي','هم','لم','لن','لا','وقد','الف','هذي'];
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

if (!function_exists('compute_cluster_key')) {
    /**
     * Stable 40-char fingerprint for the article title.
     * Returns '' for titles too short or generic to be useful — those
     * rows simply don't participate in clustering.
     */
    function compute_cluster_key(string $title): string {
        $tokens = article_cluster_tokens($title);
        if (count($tokens) < 3) return '';
        sort($tokens, SORT_STRING);
        return sha1(implode(' ', $tokens));
    }
}

if (!function_exists('cluster_counts_for')) {
    /**
     * For an array of cluster keys, return [key => total_articles_in_cluster].
     * Single-row clusters are omitted (callers only render the badge for >= 2).
     */
    function cluster_counts_for(array $keys): array {
        $keys = array_values(array_unique(array_filter($keys, fn($k) => is_string($k) && $k !== '')));
        if (!$keys) return [];
        try {
            $db = getDB();
            $place = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $db->prepare("SELECT cluster_key, COUNT(*) AS cnt
                                    FROM articles
                                   WHERE status = 'published'
                                     AND cluster_key IN ($place)
                                   GROUP BY cluster_key
                                  HAVING cnt >= 2");
            $stmt->execute($keys);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(string)$row['cluster_key']] = (int)$row['cnt'];
            }
            return $out;
        } catch (Throwable $e) {
            // Cluster column probably hasn't been migrated yet — degrade
            // silently so a stale deploy doesn't break the homepage.
            return [];
        }
    }
}

if (!function_exists('renderClusterBadge')) {
    /**
     * Inline badge for an article card. Returns '' when the cluster
     * has only one member or counts haven't been pre-loaded yet.
     *
     * The badge is a real link to /cluster/{key} so readers can jump
     * straight to the side-by-side "قارن التغطية" page. We swallow
     * card clicks via stopPropagation so the badge doesn't trip the
     * outer <a> wrapper used by most card layouts.
     */
    function renderClusterBadge(array $article): string {
        $key = (string)($article['cluster_key'] ?? '');
        if ($key === '' || $key === '-') return '';
        // Only render for valid SHA1 hex keys to be safe in URLs.
        if (!preg_match('/^[a-f0-9]{40}$/', $key)) return '';
        $counts = $GLOBALS['__nf_cluster_counts'] ?? [];
        $cnt = (int)($counts[$key] ?? 0);
        if ($cnt < 2) return '';
        $href = '/cluster/' . $key;
        return '<a class="cluster-badge" href="' . htmlspecialchars($href, ENT_QUOTES) . '"'
             . ' title="قارن التغطية — هذا الخبر في ' . (int)$cnt . ' مصادر"'
             . ' onclick="event.stopPropagation()">📰 ' . (int)$cnt . ' مصادر ›</a>';
    }
}
