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

// Step 1: ask the legacy IID info endpoint for the FID belonging to this
// FCM token. There's no public token→FID lookup, but this endpoint still
// works (it's the only path that does on most projects). We need the FID
// because the modern Installations DELETE only accepts FIDs, not tokens.
echo "1) ask IID info endpoint for FID...\n";
$infoUrl = "https://iid.googleapis.com/iid/info/" . rawurlencode($fcmToken) . "?details=true";
$ch = curl_init($infoUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $accessToken,
        'access_token_auth: true',
    ],
]);
$infoResp = curl_exec($ch);
$infoCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "   HTTP $infoCode\n";
echo "   " . substr($infoResp, 0, 400) . "\n\n";

$fid = null;
if ($infoCode === 200) {
    $info = json_decode($infoResp, true);
    $fid = $info['rel']['installation']['fid']
        ?? $info['installation']['fid']
        ?? $info['fid']
        ?? null;
}

// Step 2: if we got the FID, delete the Installation via the modern API.
if ($fid) {
    echo "2) FID = $fid — delete via Installations API...\n";
    $delUrl = "https://firebaseinstallations.googleapis.com/v1/projects/{$projectId}/installations/{$fid}";
    $ch = curl_init($delUrl);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    ]);
    $delResp = curl_exec($ch);
    $delCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "   HTTP $delCode\n";
    echo "   " . ($delResp === '' ? "(empty body)" : substr($delResp, 0, 300)) . "\n\n";

    if ($delCode === 200 || $delCode === 204 || $delCode === 404) {
        echo "✓ Installation deleted at Firebase backend.\n";
        $db->prepare("DELETE FROM user_devices WHERE id=?")->execute([$row['id']]);
        echo "✓ device row mمسوح من DB.\n\n";
        echo "الخطوات التالية:\n";
        echo "  1. على iPhone: App Switcher → اقفل التطبيق كامل\n";
        echo "  2. افتحه من جديد — Firebase رح ينشئ Installation + token جديد\n";
        echo "  3. استنى 10 ثوانٍ، ثم: php diag_fcm.php send\n";
    } else {
        echo "❌ فشل حذف Installation رغم توفر FID.\n";
    }
} else {
    echo "❌ ما قدرنا نطلع FID من التوكن.\n\n";
    echo "هذا يعني الـ IID API لم يعد متاحاً لهذا المشروع، ولا يوجد طريق\n";
    echo "آخر لحذف الـ Installation من السيرفر. الحل الوحيد:\n\n";
    echo "  1. ابني build 43 من Xcode (الكود الجديد فيه force-refresh).\n";
    echo "  2. ارفع للـ TestFlight عبر Transporter.\n";
    echo "  3. لما تثبّت 43 على iPhone، الكود تلقائياً يحذف التوكن القديم\n";
    echo "     وينشئ مزواج FCM↔APNs جديد للبيئة الصحيحة (production).\n\n";
    // Drop the device row anyway, build 43 will register a fresh one.
    $db->prepare("DELETE FROM user_devices WHERE id=?")->execute([$row['id']]);
    echo "✓ مسحت row الجهاز من DB استعداداً للتسجيل الجديد من build 43.\n";
}
