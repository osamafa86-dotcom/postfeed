<?php
/**
 * Fetch full article body (≥3 paragraphs) from a source URL.
 * Returns sanitized <p>...</p> HTML, or '' on failure.
 */
function fetchArticleBody($url) {
    if (empty($url)) return '';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NewsFlow/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => '',
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (empty($html)) return '';

    $body = $html;
    if (preg_match('#<article[^>]*>(.*?)</article>#is', $html, $m)) {
        $body = $m[1];
    } elseif (preg_match('#<div[^>]*class="[^"]*(?:article|content|entry|post)[^"]*"[^>]*>(.*?)</div>\s*</div>#is', $html, $m)) {
        $body = $m[1];
    }

    $body = preg_replace('#<(script|style|nav|aside|header|footer|form|iframe)[^>]*>.*?</\1>#is', '', $body);

    if (!preg_match_all('#<p[^>]*>(.*?)</p>#is', $body, $matches)) return '';

    $paragraphs = [];
    foreach ($matches[1] as $p) {
        $text = trim(strip_tags($p));
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        if (mb_strlen($text) < 40) continue;
        $paragraphs[] = $text;
        if (count($paragraphs) >= 6) break;
    }
    if (count($paragraphs) < 3) return '';

    $out = '';
    foreach ($paragraphs as $p) {
        $out .= '<p>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";
    }
    return $out;
}
