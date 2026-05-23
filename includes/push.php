<?php
/**
 * Firebase Cloud Messaging — server-side sender (HTTP v1 API).
 *
 * Setup (one-time):
 *   1. Firebase console → Project settings → Service accounts →
 *      "Generate new private key". Save the JSON.
 *   2. Either:
 *        - set env FCM_SERVICE_ACCOUNT_JSON to the file path, OR
 *        - drop it at storage/fcm-service-account.json, OR
 *        - store the raw JSON in settings key 'fcm_service_account'.
 *   3. Set env FCM_PROJECT_ID (or settings 'fcm_project_id') — the
 *      Firebase project id. If omitted we read it from the JSON.
 *
 * Everything no-ops gracefully (returns 0 sent) when unconfigured, so
 * crons that call it won't fail on installs without push set up.
 */

require_once __DIR__ . '/functions.php';

/**
 * Load and cache the service account credentials.
 * @return array|null Decoded JSON or null when not configured.
 */
function fcm_service_account(): ?array {
    static $cached = false;
    static $value = null;
    if ($cached) return $value;
    $cached = true;

    // 1. Inline JSON in settings.
    $raw = trim((string)getSetting('fcm_service_account', ''));
    // 2. Path via env.
    if ($raw === '') {
        $path = trim((string)env('FCM_SERVICE_ACCOUNT_JSON', ''));
        if ($path === '' && is_file(__DIR__ . '/../storage/fcm-service-account.json')) {
            $path = __DIR__ . '/../storage/fcm-service-account.json';
        }
        if ($path !== '' && is_file($path)) {
            $raw = (string)@file_get_contents($path);
        }
    }
    if ($raw === '') return null;

    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
        error_log('[push] service account JSON invalid');
        return null;
    }
    $value = $json;
    return $value;
}

function fcm_project_id(): string {
    $id = trim((string)getSetting('fcm_project_id', ''));
    if ($id === '') $id = trim((string)env('FCM_PROJECT_ID', ''));
    if ($id === '') {
        $sa = fcm_service_account();
        $id = (string)($sa['project_id'] ?? '');
    }
    return $id;
}

function fcm_is_configured(): bool {
    return fcm_service_account() !== null && fcm_project_id() !== '';
}

/**
 * Mint a short-lived OAuth2 access token from the service account using
 * the JWT-bearer grant. Cached in storage for ~55 min.
 */
function fcm_access_token(): ?string {
    $sa = fcm_service_account();
    if (!$sa) return null;

    $cacheFile = __DIR__ . '/../storage/.fcm_token_cache';
    if (is_file($cacheFile)) {
        $c = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($c) && ($c['exp'] ?? 0) > time() + 60) {
            return $c['token'];
        }
    }

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claim = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $signingInput = $b64(json_encode($header)) . '.' . $b64(json_encode($claim));

    $signature = '';
    if (!openssl_sign($signingInput, $signature, $sa['private_key'], 'sha256WithRSAEncryption')) {
        error_log('[push] openssl_sign failed');
        return null;
    }
    $jwt = $signingInput . '.' . $b64($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) {
        error_log("[push] token exchange failed (HTTP $code): $resp");
        return null;
    }
    $data = json_decode($resp, true);
    $token = $data['access_token'] ?? null;
    if (!$token) return null;

    @file_put_contents($cacheFile, json_encode([
        'token' => $token,
        'exp'   => $now + (int)($data['expires_in'] ?? 3600),
    ]), LOCK_EX);
    return $token;
}

/**
 * Send one message to a single device token.
 * @return string 'ok' | 'unregistered' | 'error'
 */
function fcm_send_one(string $accessToken, string $projectId, string $token, array $message): string {
    $payload = [
        'message' => array_merge(['token' => $token], $message),
    ];
    $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) return 'ok';
    // 404 UNREGISTERED / 400 invalid token → caller should prune it.
    if ($code === 404 || $code === 400) return 'unregistered';
    error_log("[push] send failed (HTTP $code): $resp");
    return 'error';
}

/**
 * Broadcast a notification to every active device whose owner has the
 * given notify_* preference enabled.
 *
 * @param string $title
 * @param string $body
 * @param array  $data    Extra data payload (channel, link, article_id...)
 * @param string $prefColumn  users column gating this push (e.g. notify_digest)
 * @return array{sent:int, pruned:int, skipped:bool}
 */
function push_broadcast(string $title, string $body, array $data = [], string $prefColumn = 'notify_digest'): array {
    if (!fcm_is_configured()) {
        error_log('[push] not configured — skipping broadcast');
        return ['sent' => 0, 'pruned' => 0, 'skipped' => true];
    }
    $accessToken = fcm_access_token();
    $projectId = fcm_project_id();
    if (!$accessToken || $projectId === '') {
        return ['sent' => 0, 'pruned' => 0, 'skipped' => true];
    }

    $db = getDB();

    // Only message users who opted into this notification type. The
    // column is validated against a whitelist to avoid SQL injection
    // via the caller.
    $allowedPrefs = ['notify_breaking', 'notify_followed', 'notify_digest'];
    if (!in_array($prefColumn, $allowedPrefs, true)) {
        $prefColumn = 'notify_digest';
    }

    $sql = "SELECT d.token
            FROM user_devices d
            INNER JOIN users u ON u.id = d.user_id
            WHERE d.is_active = 1 AND COALESCE(u.$prefColumn, 1) = 1";
    $tokens = [];
    try {
        $tokens = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        error_log('[push] token query failed: ' . $e->getMessage());
        return ['sent' => 0, 'pruned' => 0, 'skipped' => true];
    }

    // Stringify all data values — FCM data payload must be string→string.
    $dataStr = [];
    foreach ($data as $k => $v) $dataStr[(string)$k] = (string)$v;

    $message = [
        'notification' => ['title' => $title, 'body' => $body],
        'data'         => $dataStr,
        'android'      => ['priority' => 'high'],
        'apns'         => [
            'headers' => ['apns-priority' => '10'],
            'payload' => ['aps' => ['sound' => 'default']],
        ],
    ];

    $sent = 0;
    $stale = [];
    foreach ($tokens as $token) {
        $result = fcm_send_one($accessToken, $projectId, $token, $message);
        if ($result === 'ok') {
            $sent++;
        } elseif ($result === 'unregistered') {
            $stale[] = $token;
        }
    }

    // Prune dead tokens so the list doesn't grow forever.
    $pruned = 0;
    if ($stale) {
        try {
            $in = implode(',', array_fill(0, count($stale), '?'));
            $del = $db->prepare("DELETE FROM user_devices WHERE token IN ($in)");
            $del->execute($stale);
            $pruned = $del->rowCount();
        } catch (Throwable $e) {
            error_log('[push] prune failed: ' . $e->getMessage());
        }
    }

    return ['sent' => $sent, 'pruned' => $pruned, 'skipped' => false];
}
