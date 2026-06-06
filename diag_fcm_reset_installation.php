<?php
/**
 * Force-delete a Firebase Installation server-side.
 *
 * iOS Keychain preserves Firebase Installation ID across uninstall/
 * reinstall, leaving the FCM token paired with a stale APNs token from a
 * previous environment (sandbox debug build). Push then fails with
 * BadEnvironmentKeyInToken even after the user reinstalls from TestFlight.
 *
 * Deleting the Installation server-side forces the SDK on next launch to
 * mint a brand-new Installation + APNs pairing using the current build's
 * environment, breaking the stale mapping without a code change.
 *
 * Usage: php diag_fcm_reset_installation.php
 *        (deletes the Installation for the newest active device row)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/push.php';

$db = getDB();
$row = $db->query("SELECT id, user_id, token, created_at, last_seen FROM user_devices WHERE is_active=1 ORDER BY id DESC LIMIT 1")
          ->fetch(PDO::FETCH_ASSOC);
if (!$row) { exit("لا يوجد جهاز نشط.\n"); }

$fcmToken = $row['token'];
echo "device id:        {$row['id']}\n";
echo "fcm token (head): " . substr($fcmToken, 0, 30) . "...\n\n";

$sa = fcm_service_account();
if (!$sa) { exit("FCM service account غير موجود.\n"); }
$projectId = fcm_project_id();

// Mint an access token with the broader 'firebase' scope (Installations
// API needs more than messaging-only).
$now = time();
$header = ['alg' => 'RS256', 'typ' => 'JWT'];
$claim = [
    'iss'   => $sa['client_email'],
    'scope' => 'https://www.googleapis.com/auth/firebase.messaging https://www.googleapis.com/auth/cloud-platform',
    'aud'   => 'https://oauth2.googleapis.com/token',
    'iat'   => $now,
    'exp'   => $now + 3600,
];
$b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
$signingInput = $b64(json_encode($header)) . '.' . $b64(json_encode($claim));
$signature = '';
if (!openssl_sign($signingInput, $signature, $sa['private_key'], 'sha256WithRSAEncryption')) {
    exit("openssl_sign failed\n");
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
if ($code !== 200) { exit("token exchange failed HTTP $code: $resp\n"); }
$data = json_decode($resp, true);
$accessToken = $data['access_token'] ?? null;
if (!$accessToken) { exit("no access token in response\n"); }
echo "✓ minted access token\n\n";

// Use the legacy Instance ID (IID) admin API — it accepts the full FCM
// token directly (unlike Firebase Installations v1, which needs the FID
// and there's no public lookup from token→FID). Deleting the IID removes
// the FCM↔APNs pairing at Firebase backend, so the SDK on next launch
// mints a fresh pairing using the current build's APNs environment.
$url = "https://iid.googleapis.com/iid/v1/{$fcmToken}";
echo "DELETE https://iid.googleapis.com/iid/v1/<fcm_token>\n";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => 'DELETE',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $accessToken,
        'access_token_auth: true',
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "=== HTTP $code ===\n";
echo ($resp === '' ? "(empty body)" : $resp) . "\n\n";

if ($code === 200 || $code === 404) {
    echo "✓ IID deleted (or already gone).\n\n";
    echo "الخطوات التالية:\n";
    echo "  1. على iPhone: force-quit التطبيق (App Switcher → swipe up)\n";
    echo "  2. افتح التطبيق من جديد — Firebase رح ينشئ token جديد\n";
    echo "  3. استنى 10 ثوانٍ، ثم اختبر:\n";
    echo "       php diag_fcm.php send\n";
    // Also drop the device row so the next register gets a fresh INSERT.
    $db->prepare("DELETE FROM user_devices WHERE id=?")->execute([$row['id']]);
    echo "\n✓ كذلك مسحت row الجهاز من DB — التطبيق رح يسجل توكن جديد عند الفتح.\n";
} else {
    echo "❌ فشل الحذف.\n";
    echo "  - 401/403: service account ينقصه دور 'Firebase Cloud Messaging Admin'\n";
    echo "  - 400: التوكن غير صالح أصلاً (نادر)\n";
}
