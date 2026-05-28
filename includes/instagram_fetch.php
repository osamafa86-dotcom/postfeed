<?php
/**
 * Instagram Reels fetcher — pulls the latest video posts from public
 * Instagram profiles listed in `reels_sources`.
 *
 * Strategy: hit Instagram's mobile web profile endpoint with a
 * Graph-API-style App ID header. The endpoint is public (no auth
 * cookies) but Instagram aggressively rate-limits, so the cron is
 * intentionally slow (2s pause between accounts, max 12 accounts
 * per run).
 *
 * Realistic expectation: works most days. Occasionally Instagram
 * returns 401/429 and the run yields zero new reels. Re-run later.
 * If it stops working entirely for >24h, Instagram has tightened
 * their block again — switch to a paid scraper service or manual
 * paste via panel/reels.php.
 */

const IG_API_BASE = 'https://i.instagram.com/api/v1/users/web_profile_info/';
const IG_APP_ID   = '936619743392459';
const IG_UA       = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

function ig_fetch_user(string $username): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => IG_API_BASE . '?username=' . urlencode($username),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ' . IG_UA,
            'X-IG-App-ID: ' . IG_APP_ID,
            'Accept: */*',
            'Accept-Language: ar,en-US;q=0.7,en;q=0.3',
        ],
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) {
        return ['ok' => false, 'error' => "HTTP $http"];
    }
    $data = json_decode((string)$body, true);
    $user = $data['data']['user'] ?? null;
    if (!$user) {
        return ['ok' => false, 'error' => 'unexpected response shape'];
    }
    return ['ok' => true, 'user' => $user];
}

function ig_extract_reels(array $userData): array {
    $out = [];
    $edges = $userData['edge_owner_to_timeline_media']['edges'] ?? [];
    foreach ($edges as $e) {
        $n = $e['node'] ?? null;
        if (!$n) continue;
        // Only video posts (reels). Skip photo/carousel.
        if (empty($n['is_video'])) continue;
        $caption = '';
        $capEdges = $n['edge_media_to_caption']['edges'] ?? [];
        if (!empty($capEdges)) {
            $caption = (string)($capEdges[0]['node']['text'] ?? '');
        }
        $out[] = [
            'shortcode'     => (string)($n['shortcode']     ?? ''),
            'caption'       => mb_substr($caption, 0, 480, 'UTF-8'),
            'thumbnail_url' => (string)($n['display_url']   ?? $n['thumbnail_src'] ?? ''),
            'posted_at'     => (int)($n['taken_at_timestamp'] ?? 0),
        ];
    }
    return $out;
}
