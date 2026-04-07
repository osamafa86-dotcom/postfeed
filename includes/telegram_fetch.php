<?php
/**
 * Telegram channel fetcher - scrapes t.me/s/{channel} public preview.
 * No API key required.
 */

function tg_fetch_channel($username, $limit = 20) {
    $username = ltrim($username, '@');
    $url = 'https://t.me/s/' . urlencode($username);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NewsFlowBot/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return [];

    $messages = [];
    // Each message wrapper
    if (preg_match_all('#<div class="tgme_widget_message[^"]*"[^>]*data-post="([^"]+)"[^>]*>(.*?)</div>\s*</div>\s*</div>#s', $html, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $b) {
            $postId = $b[1]; // channel/123
            $body = $b[2];

            // text
            $text = '';
            if (preg_match('#<div class="tgme_widget_message_text[^"]*"[^>]*>(.*?)</div>#s', $body, $t)) {
                $text = trim(strip_tags(str_replace(['<br/>', '<br>', '<br />'], "\n", $t[1])));
            }

            // image
            $image = '';
            if (preg_match("#tgme_widget_message_photo_wrap[^\"]*\"[^>]*style=\"background-image:url\\('([^']+)'\\)#", $body, $img)) {
                $image = $img[1];
            }

            // datetime
            $datetime = null;
            if (preg_match('#<time[^>]*datetime="([^"]+)"#', $body, $dt)) {
                $datetime = $dt[1];
            }

            if (empty($text) && empty($image)) continue;

            $messages[] = [
                'post_id' => $postId,
                'message_id' => (int)substr($postId, strrpos($postId, '/') + 1),
                'text' => $text,
                'image_url' => $image,
                'posted_at' => $datetime ? date('Y-m-d H:i:s', strtotime($datetime)) : date('Y-m-d H:i:s'),
                'url' => 'https://t.me/' . $postId,
            ];
        }
    }

    return array_slice(array_reverse($messages), 0, $limit);
}

function tg_sync_all_sources() {
    $db = getDB();
    $sources = $db->query("SELECT * FROM telegram_sources WHERE is_active = 1")->fetchAll();
    $total = 0;
    foreach ($sources as $src) {
        $messages = tg_fetch_channel($src['username'], 20);
        foreach ($messages as $m) {
            try {
                $stmt = $db->prepare("INSERT IGNORE INTO telegram_messages
                    (source_id, message_id, post_url, text, image_url, posted_at)
                    VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $src['id'], $m['message_id'], $m['url'],
                    $m['text'], $m['image_url'], $m['posted_at']
                ]);
                if ($stmt->rowCount() > 0) $total++;
            } catch (Exception $e) {}
        }
        $db->prepare("UPDATE telegram_sources SET last_fetched_at = NOW() WHERE id = ?")->execute([$src['id']]);
    }
    return $total;
}
