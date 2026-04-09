<?php
/**
 * نيوزفلو - التخصيص الذكي للصفحة الرئيسية
 * =======================================
 * Scoring-based personalized feed combining four signals:
 *
 *   1. Followed categories  (explicit, +10, weighted by follow priority)
 *   2. Followed sources     (explicit, +8)
 *   3. Reading history      (implicit, top categories +3 / top sources +2,
 *                            computed from the last 30 days)
 *   4. ai_keywords overlap  (implicit, +5 per keyword matched against the
 *                            user's most-read articles of the last 30 days,
 *                            capped at 15)
 *
 * Final score is multiplied by a recency decay (12-hour half-life) so fresh
 * stories float above anything more than a day old. Already-read articles and
 * bookmarked ones are filtered out so the section stays useful on repeat
 * visits. Diversity cap: at most 3 articles per category per section so a
 * politics-heavy reader still sees variety.
 *
 * Cached per-user for 10 minutes. Invalidated by /api/follow.php on every
 * follow/unfollow and by user_log_read() on each new read, so the feed stays
 * in sync with the user's behaviour without recomputing on every request.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/user_functions.php';

/**
 * Clear the cached personalized feed for a user. Called after follow/unfollow
 * and after a new read is logged.
 */
function personalize_invalidate(int $userId): void {
    if ($userId <= 0) return;
    cache_forget('personal_feed_v1_' . $userId);
}

/**
 * Top N categories the user has actually read in the last $days days.
 * Returns [category_id => read_count] sorted by count desc.
 */
function personalize_top_categories_from_history(int $userId, int $days = 30, int $limit = 5): array {
    if ($userId <= 0) return [];
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT a.category_id, COUNT(*) as n
                              FROM user_reading_history h
                              INNER JOIN articles a ON a.id = h.article_id
                              WHERE h.user_id = ?
                                AND h.read_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                                AND a.category_id IS NOT NULL
                              GROUP BY a.category_id
                              ORDER BY n DESC
                              LIMIT ?");
        $stmt->execute([$userId, $days, $limit]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int)$r['category_id']] = (int)$r['n'];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * Top N sources the user has actually read in the last $days days.
 * Returns [source_id => read_count] sorted by count desc.
 */
function personalize_top_sources_from_history(int $userId, int $days = 30, int $limit = 5): array {
    if ($userId <= 0) return [];
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT a.source_id, COUNT(*) as n
                              FROM user_reading_history h
                              INNER JOIN articles a ON a.id = h.article_id
                              WHERE h.user_id = ?
                                AND h.read_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                                AND a.source_id IS NOT NULL
                              GROUP BY a.source_id
                              ORDER BY n DESC
                              LIMIT ?");
        $stmt->execute([$userId, $days, $limit]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int)$r['source_id']] = (int)$r['n'];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * Top keywords from the user's most-read articles of the last $days days.
 * ai_keywords is stored as a comma-separated string; we split, normalize,
 * and count occurrences across their recent reads + bookmarks.
 * Returns [normalized_keyword => occurrence_count] sorted desc, limited.
 */
function personalize_top_keywords_from_history(int $userId, int $days = 30, int $limit = 12): array {
    if ($userId <= 0) return [];
    try {
        $db = getDB();
        // Pull ai_keywords from both reading history and bookmarks.
        $stmt = $db->prepare("(SELECT a.ai_keywords
                               FROM user_reading_history h
                               INNER JOIN articles a ON a.id = h.article_id
                               WHERE h.user_id = ?
                                 AND h.read_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                                 AND a.ai_keywords IS NOT NULL AND a.ai_keywords <> ''
                               LIMIT 100)
                              UNION ALL
                              (SELECT a.ai_keywords
                               FROM user_bookmarks b
                               INNER JOIN articles a ON a.id = b.article_id
                               WHERE b.user_id = ?
                                 AND a.ai_keywords IS NOT NULL AND a.ai_keywords <> ''
                               LIMIT 100)");
        $stmt->execute([$userId, $days, $userId]);
        $counts = [];
        foreach ($stmt->fetchAll() as $r) {
            $raw = (string)$r['ai_keywords'];
            foreach (preg_split('/[,،]/u', $raw) as $kw) {
                $kw = personalize_normalize_keyword($kw);
                if ($kw === '' || mb_strlen($kw) < 3) continue;
                $counts[$kw] = ($counts[$kw] ?? 0) + 1;
            }
        }
        arsort($counts);
        return array_slice($counts, 0, $limit, true);
    } catch (Throwable $e) { return []; }
}

/**
 * Normalize an Arabic keyword so that minor spelling variants collapse together
 * (أ/إ/آ → ا, ة → ه, etc.). Mirrors the dedup tokenizer on the homepage so the
 * matcher works on the same normalized form.
 */
function personalize_normalize_keyword(string $kw): string {
    $kw = trim($kw);
    if ($kw === '') return '';
    $kw = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $kw);
    $kw = strtr($kw, [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه',
        'ى' => 'ي', 'ئ' => 'ي',
        'ؤ' => 'و',
    ]);
    $kw = mb_strtolower($kw);
    return trim($kw);
}

/**
 * The main personalization entry point.
 *
 * Returns a scored list of articles targeted at $userId, freshest-first with
 * diversity, capped at $limit items. Pulls candidates from the last
 * $windowHours hours, so the section always feels current. Cached per user
 * for 10 minutes.
 *
 * Each returned row gets two extra fields on top of the normal article shape:
 *   - `_score` (float)         — the final ranking score (mostly for debug)
 *   - `_reason` (string)       — short Arabic explainer like "لأنك تتابع الاقتصاد"
 *
 * Returns [] when:
 *   - user has no follows and no reading history (let the caller show
 *     onboarding CTA instead)
 *   - no candidate articles matched (very quiet site)
 */
function personalize_feed_for(int $userId, int $limit = 6, int $windowHours = 72): array {
    if ($userId <= 0) return [];
    $cacheKey = 'personal_feed_v1_' . $userId . '_' . $limit . '_' . $windowHours;
    return cache_remember($cacheKey, 600, function() use ($userId, $limit, $windowHours) {
        return personalize_feed_compute($userId, $limit, $windowHours);
    });
}

/**
 * Uncached computation. Kept separate so the caller (cron, debug) can bypass
 * the cache when it wants fresh numbers.
 */
function personalize_feed_compute(int $userId, int $limit, int $windowHours): array {
    // ============================================================
    // 1. Gather signals
    // ============================================================
    $followedCats  = user_followed_category_ids($userId);       // priority order
    $followedSrcs  = user_followed_source_ids($userId);
    $historyCats   = personalize_top_categories_from_history($userId, 30, 5);
    $historySrcs   = personalize_top_sources_from_history($userId, 30, 5);
    $topKeywords   = personalize_top_keywords_from_history($userId, 30, 12);

    // If we have literally nothing to go on, return empty so the caller can
    // show the onboarding CTA. (A brand-new user with zero behaviour.)
    if (!$followedCats && !$followedSrcs && !$historyCats && !$historySrcs) {
        return [];
    }

    // Weight followed-categories by their stored priority. Earliest priority
    // (smallest number) gets the full bonus; later ones get a bit less.
    $catBonus = []; // category_id => bonus points
    foreach ($followedCats as $idx => $cid) {
        $catBonus[$cid] = max(6, 10 - $idx); // 10, 9, 8, ..., floor 6
    }
    foreach ($historyCats as $cid => $cnt) {
        $catBonus[$cid] = ($catBonus[$cid] ?? 0) + 3;
    }

    $srcBonus = [];
    foreach ($followedSrcs as $sid) {
        $srcBonus[$sid] = 8;
    }
    foreach ($historySrcs as $sid => $cnt) {
        $srcBonus[$sid] = ($srcBonus[$sid] ?? 0) + 2;
    }

    // ============================================================
    // 2. Pull candidate articles from the last $windowHours hours
    // ============================================================
    try {
        $db = getDB();

        // Exclude articles the user already read or already saved — no point
        // re-surfacing them in the "for you" rail.
        $excludeIds = [];
        $stmt = $db->prepare("SELECT article_id FROM user_reading_history WHERE user_id = ? ORDER BY read_at DESC LIMIT 200");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $r) $excludeIds[(int)$r['article_id']] = true;
        $stmt = $db->prepare("SELECT article_id FROM user_bookmarks WHERE user_id = ?");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $r) $excludeIds[(int)$r['article_id']] = true;

        // Pool size: 150 candidates scored in PHP. Big enough that diversity
        // filtering still leaves $limit winners without re-querying.
        $poolSize = 150;
        $stmt = $db->prepare("SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                                     s.name as source_name, s.logo_color
                              FROM articles a
                              LEFT JOIN categories c ON a.category_id = c.id
                              LEFT JOIN sources s ON a.source_id = s.id
                              WHERE a.status = 'published'
                                AND a.published_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                              ORDER BY a.published_at DESC
                              LIMIT ?");
        $stmt->bindValue(1, $windowHours, PDO::PARAM_INT);
        $stmt->bindValue(2, $poolSize, PDO::PARAM_INT);
        $stmt->execute();
        $candidates = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    if (!$candidates) return [];

    // Flatten the user's top-keyword map to a set for O(1) lookups.
    $kwSet = array_flip(array_keys($topKeywords));

    // ============================================================
    // 3. Score every candidate
    // ============================================================
    $now = time();
    $scored = [];
    foreach ($candidates as $a) {
        $aid = (int)($a['id'] ?? 0);
        if ($aid <= 0) continue;
        if (isset($excludeIds[$aid])) continue;

        $cid = (int)($a['category_id'] ?? 0);
        $sid = (int)($a['source_id'] ?? 0);

        $score = 0.0;
        $reasons = [];

        if ($cid && isset($catBonus[$cid])) {
            $score += $catBonus[$cid];
            if (in_array($cid, $followedCats, true)) {
                $reasons[] = 'cat_follow:' . ($a['cat_name'] ?? '');
            } elseif (isset($historyCats[$cid])) {
                $reasons[] = 'cat_history:' . ($a['cat_name'] ?? '');
            }
        }
        if ($sid && isset($srcBonus[$sid])) {
            $score += $srcBonus[$sid];
            if (in_array($sid, $followedSrcs, true)) {
                $reasons[] = 'src_follow:' . ($a['source_name'] ?? '');
            } elseif (isset($historySrcs[$sid])) {
                $reasons[] = 'src_history:' . ($a['source_name'] ?? '');
            }
        }

        // Keyword overlap (capped at 15 pts so a single article can't dominate)
        if ($kwSet && !empty($a['ai_keywords'])) {
            $kwHit = 0;
            foreach (preg_split('/[,،]/u', (string)$a['ai_keywords']) as $kw) {
                $kw = personalize_normalize_keyword($kw);
                if ($kw === '' || mb_strlen($kw) < 3) continue;
                if (isset($kwSet[$kw])) {
                    $kwHit++;
                    if ($kwHit <= 3) $reasons[] = 'kw:' . $kw;
                }
            }
            $score += min(15, $kwHit * 5);
        }

        if ($score <= 0) continue; // no signals = not personalized, skip

        // Recency decay — 12-hour half-life. A 12h-old story keeps ~0.5x of
        // its score, a 24h story ~0.33x, a 3-day story ~0.14x.
        $ts = strtotime((string)($a['published_at'] ?? 'now')) ?: $now;
        $ageHours = max(0, ($now - $ts) / 3600.0);
        $decay = 1 / (1 + $ageHours / 12.0);
        $score *= $decay;

        $a['_score']   = round($score, 3);
        $a['_reasons'] = $reasons; // raw list, resolved to a display string below
        $scored[] = $a;
    }

    if (!$scored) return [];

    // Sort by score desc
    usort($scored, function($x, $y) {
        return ($y['_score'] <=> $x['_score']);
    });

    // ============================================================
    // 4. Diversity cap + limit
    // ============================================================
    $out = [];
    $perCat = [];
    $seenTitles = []; // crude title dedup — same cluster republished
    foreach ($scored as $a) {
        $cid = (int)($a['category_id'] ?? 0);
        if ($cid && ($perCat[$cid] ?? 0) >= 3) continue; // max 3 per category
        // Near-duplicate title check: simple prefix hash so "X قال A" variants collapse.
        $titleKey = mb_strtolower(mb_substr((string)($a['title'] ?? ''), 0, 40));
        if (isset($seenTitles[$titleKey])) continue;
        $seenTitles[$titleKey] = true;

        $a['_reason'] = personalize_format_reason($a['_reasons'] ?? []);
        unset($a['_reasons']);
        $out[] = $a;
        if ($cid) $perCat[$cid] = ($perCat[$cid] ?? 0) + 1;
        if (count($out) >= $limit) break;
    }

    return $out;
}

/**
 * Convert a machine-readable reason list ("cat_follow:اقتصاد", "kw:غزة") into
 * a single short Arabic explainer line shown under each card.
 */
function personalize_format_reason(array $reasons): string {
    if (!$reasons) return 'مُقترح لك';
    foreach ($reasons as $r) {
        if (strpos($r, 'cat_follow:') === 0) {
            $name = substr($r, strlen('cat_follow:'));
            if ($name !== '') return 'لأنك تتابع ' . $name;
        }
    }
    foreach ($reasons as $r) {
        if (strpos($r, 'src_follow:') === 0) {
            $name = substr($r, strlen('src_follow:'));
            if ($name !== '') return 'من ' . $name . ' — تتابعه';
        }
    }
    foreach ($reasons as $r) {
        if (strpos($r, 'cat_history:') === 0) {
            $name = substr($r, strlen('cat_history:'));
            if ($name !== '') return 'لأنك تقرأ ' . $name . ' كثيراً';
        }
    }
    foreach ($reasons as $r) {
        if (strpos($r, 'kw:') === 0) {
            $kw = substr($r, strlen('kw:'));
            if ($kw !== '') return 'مشابه لما قرأته مؤخراً';
        }
    }
    return 'مُقترح لك';
}
