<?php
/**
 * نيوز فيد — Weekly Rewind mailer.
 *
 * Runs Sunday ~07:00 to deliver the current week's digest to all
 * confirmed newsletter subscribers. Uses the shared mailer_send()
 * + newsletter_email_html() pair so throttling and SMTP config
 * match the daily newsletter.
 *
 * Safety:
 *   - Finds the latest rewind by year_week.
 *   - Skips if it was already emailed (weekly_rewinds.emailed_at).
 *   - Records one row per recipient in weekly_rewind_deliveries so
 *     re-runs don't double-send.
 *   - --force / ?force=1 bypasses both guards (admin only).
 *
 * Invocation:
 *   CLI:   php cron_weekly_rewind_send.php [--force] [--week=2026-17] [--dry]
 *   HTTP:  curl "https://site/cron_weekly_rewind_send.php?key=CRON_KEY[&force=1][&week=YYYY-WW][&dry=1]"
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/weekly_rewind.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(600);
$start = microtime(true);

// ---- Flags -------------------------------------------------------
$force = !empty($_GET['force']) || (PHP_SAPI === 'cli' && in_array('--force', $argv ?? [], true));
$dry   = !empty($_GET['dry'])   || (PHP_SAPI === 'cli' && in_array('--dry',   $argv ?? [], true));
$week  = $_GET['week'] ?? null;
if (!$week && PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $a) {
        if (strpos($a, '--week=') === 0) $week = substr($a, 7);
    }
}

// ---- Pick rewind -------------------------------------------------
$rewind = $week ? wr_get_by_week((string)$week) : wr_get_latest();
if (!$rewind) {
    echo "skip: no rewind available to send" . ($week ? " for week {$week}" : '') . ".\n";
    exit;
}
echo "rewind #{$rewind['id']} ({$rewind['year_week']}): {$rewind['cover_title']}\n";

if (!empty($rewind['emailed_at']) && !$force) {
    echo "skip: already emailed at {$rewind['emailed_at']}. Use --force to resend.\n";
    exit;
}

// ---- Subscribers -------------------------------------------------
$db = getDB();
$subs = $db->query("SELECT id, email, unsubscribe_token FROM newsletter_subscribers
                    WHERE confirmed = 1")->fetchAll(PDO::FETCH_ASSOC);
if (!$subs) {
    echo "skip: no confirmed subscribers.\n";
    exit;
}
echo "subscribers: " . count($subs) . "\n";

wr_ensure_table();  // makes sure weekly_rewind_deliveries exists

// ---- Send loop ---------------------------------------------------
$subject = 'مراجعة الأسبوع — ' . ($rewind['cover_title'] ?: $rewind['year_week']);
$success = 0; $fail = 0; $skipped = 0;

$delStmt = $db->prepare("INSERT IGNORE INTO weekly_rewind_deliveries
                         (rewind_id, recipient_kind, recipient_id, delivered_at)
                         VALUES (?, 'subscriber', ?, NOW())");
$checkStmt = $db->prepare("SELECT 1 FROM weekly_rewind_deliveries
                           WHERE rewind_id = ? AND recipient_kind='subscriber' AND recipient_id = ?");

foreach ($subs as $sub) {
    $subId = (int)$sub['id'];

    if (!$force) {
        $checkStmt->execute([$rewind['id'], $subId]);
        if ($checkStmt->fetchColumn()) { $skipped++; continue; }
    }

    $unsubUrl = SITE_URL . '/newsletter/unsubscribe/' . $sub['unsubscribe_token'];
    $webUrl   = SITE_URL . '/weekly/' . $rewind['year_week'];
    $html     = wr_email_html($rewind, $unsubUrl, $webUrl);

    if ($dry) {
        echo "[dry] would send to {$sub['email']}\n";
        $success++;
        continue;
    }

    $ok = mailer_send((string)$sub['email'], $subject, $html);
    if ($ok) {
        $success++;
        try { $delStmt->execute([$rewind['id'], $subId]); } catch (Throwable $e) {}
    } else {
        $fail++;
        error_log("[weekly_rewind_send] failed to send to {$sub['email']}: " . mailer_last_error());
    }

    usleep(200000); // 200ms to be polite to SMTP relay
}

// ---- Mark emailed ------------------------------------------------
if (!$dry && $success > 0) {
    wr_mark_emailed((int)$rewind['id']);
}

$elapsed = round(microtime(true) - $start, 2);
echo "done: sent {$success} | failed {$fail} | skipped {$skipped} | {$elapsed}s\n";
