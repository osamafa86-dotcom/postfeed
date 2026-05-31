<?php
/**
 * Dump the RAW response body from each Nitter mirror for a given handle,
 * so we can see WHAT each instance is actually returning — RSS, Atom,
 * HTML challenge, error page, or empty shell.
 *
 * Each response gets saved to /tmp/nf_tw_raw_<host>_<user>.txt and the
 * first 600 bytes are echoed inline so you can spot the format at a glance.
 *
 * Run: php diag_tw_raw.php palpostn
 *      php diag_tw_raw.php qudsn
 *      php diag_tw_raw.php AJArabic   (control: known-working)
 *
 * Then inspect a specific dump with:
 *      head -c 2000 /tmp/nf_tw_raw_nitter.tiekoetter.com_palpostn.txt
 *      grep -c '<item' /tmp/nf_tw_raw_nitter.tiekoetter.com_palpostn.txt
 *      grep -c '<entry' /tmp/nf_tw_raw_nitter.adminforge.de_palpostn.txt
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/twitter_fetch.php';

$username = $argv[1] ?? 'palpostn';
echo "=== فحص استجابات Nitter الخام لـ @{$username} ===\n\n";

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

foreach (TW_NITTER_INSTANCES as $host) {
    $url = 'https://' . $host . '/' . rawurlencode($username) . '/rss?_cb=' . time() . mt_rand(100, 999);
    echo "─────────────────────────────\n";
    echo "🔍 {$host}\n";
    echo "   URL: {$url}\n";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xml,application/atom+xml,application/rss+xml',
            'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
            'Cache-Control: no-cache',
        ],
    ]);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    $code = (int)($info['http_code'] ?? 0);
    $size = is_string($body) ? strlen($body) : 0;
    echo "   HTTP {$code} | {$size} bytes | " . round((float)$info['total_time'], 2) . "s";
    if ($err) echo " | curl_error: {$err}";
    echo "\n";

    if (!is_string($body) || $body === '') {
        echo "   ⚠️  لا يوجد body\n\n";
        continue;
    }

    // Classify the response shape.
    $head = ltrim(substr($body, 0, 300));
    $shape = '?';
    if (stripos($head, '<!doctype html') === 0 || stripos($head, '<html') === 0) {
        $shape = '🚫 HTML (probably gated/challenge/login page)';
    } elseif (stripos($head, '<?xml') === 0) {
        // Drill deeper.
        if (strpos($body, '<rss') !== false) {
            $itemCount = preg_match_all('#<item\b#', $body, $_);
            $shape = "✓ RSS 2.0 with {$itemCount} <item> elements";
        } elseif (strpos($body, '<feed') !== false) {
            $entryCount = preg_match_all('#<entry\b#', $body, $_);
            $shape = "✓ Atom 1.0 with {$entryCount} <entry> elements";
        } else {
            $shape = '? XML but neither RSS nor Atom';
        }
    } elseif (stripos($head, '<rss') === 0) {
        $itemCount = preg_match_all('#<item\b#', $body, $_);
        $shape = "✓ RSS (no XML prolog) with {$itemCount} <item> elements";
    } elseif (stripos($head, '<feed') === 0) {
        $entryCount = preg_match_all('#<entry\b#', $body, $_);
        $shape = "✓ Atom (no XML prolog) with {$entryCount} <entry> elements";
    } elseif (stripos($head, '{') === 0) {
        $shape = '📊 JSON';
    } else {
        $shape = 'unknown (no recognized marker)';
    }
    echo "   شكل الاستجابة: {$shape}\n";

    // Run our parser and report how many tweets actually come out.
    $parsed = tw_parse_rss_feed($body);
    echo "   مُحلَّل بواسطة tw_parse_rss_feed: " . count($parsed) . " تغريدة\n";
    if (!empty($parsed)) {
        echo "   أحدث تغريدة: [" . $parsed[0]['posted_at'] . "] " . mb_substr($parsed[0]['text'], 0, 80) . "...\n";
    }

    // Look for /status/N links to confirm the regex side of the parser.
    $statusCount = preg_match_all('#/status/\d+#', $body, $_);
    echo "   روابط /status/N الموجودة: {$statusCount}\n";

    // Save the full body for offline inspection.
    $dumpPath = sys_get_temp_dir() . '/nf_tw_raw_' . $host . '_' . $username . '.txt';
    @file_put_contents($dumpPath, $body);
    echo "   💾 حُفظ في: {$dumpPath}\n";

    // Echo a short snippet so you can see the format at a glance.
    echo "   --- أول 400 بايت ---\n";
    echo "   " . str_replace("\n", "\n   ", mb_substr($body, 0, 400)) . "\n\n";
}

echo "\n=== ملاحظات ===\n";
echo "• إذا كل المرايا ترجع HTML → تويتر بلوك Nitter بالكامل، الحل عبر fxtwitter/x-api أو fallback RSS.\n";
echo "• إذا واحدة ترجع Atom (<feed><entry>) → الآن tw_parse_atom_entries يعالجها.\n";
echo "• إذا واحدة ترجع RSS مع <item> لكن بدون /status/N → تويتر شال الروابط، أو الحساب فاضي.\n";
echo "• إذا ما واحدة ترجع شي مفيد → ضع fallback_rss_url في twitter_sources للحساب.\n";
