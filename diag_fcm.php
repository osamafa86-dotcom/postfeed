<?php
/**
 * FCM (push notifications) configuration check.
 *
 * Run on the server:
 *   php diag_fcm.php
 *
 * Tells you exactly which of the three credential sources is present,
 * whether the service-account JSON parses, whether we can mint an OAuth
 * token, and how many devices are registered to receive pushes.
 *
 * No notifications are actually sent.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/push.php';

echo "═══════════════════════════════════════════════\n";
echo "  فحص إعداد الإشعارات (FCM)\n";
echo "═══════════════════════════════════════════════\n\n";

// ── 1. Where is the service account coming from? ──
echo "1) مصدر بيانات الاعتماد (service account):\n";

$inlineSetting = trim((string)getSetting('fcm_service_account', ''));
echo "   • settings['fcm_service_account']: "
   . ($inlineSetting !== '' ? '✓ موجود (' . strlen($inlineSetting) . ' حرف)' : '✗ فارغ') . "\n";

$envPath = trim((string)env('FCM_SERVICE_ACCOUNT_JSON', ''));
echo "   • env FCM_SERVICE_ACCOUNT_JSON: "
   . ($envPath !== '' ? "✓ = $envPath" : '✗ غير مضبوط') . "\n";
if ($envPath !== '') {
    echo "       └─ الملف موجود فعلياً؟ " . (is_file($envPath) ? '✓ نعم' : '✗ لا — المسار خطأ') . "\n";
}

$storagePath = __DIR__ . '/storage/fcm-service-account.json';
echo "   • storage/fcm-service-account.json: "
   . (is_file($storagePath) ? '✓ موجود' : '✗ غير موجود') . "\n";

echo "\n";

// ── 2. Does it parse into a valid service account? ──
echo "2) صحة بيانات الاعتماد:\n";
$sa = fcm_service_account();
if ($sa === null) {
    echo "   ✗ لم يُعثر على service account صالح في أي مصدر.\n";
    echo "     → الإشعارات لن تُرسل (الكود يتجاوز بهدوء بدون أخطاء).\n\n";
} else {
    echo "   ✓ JSON صالح\n";
    echo "     • project_id:   " . ($sa['project_id'] ?? '—') . "\n";
    echo "     • client_email: " . ($sa['client_email'] ?? '—') . "\n";
    echo "     • private_key:  " . (!empty($sa['private_key']) ? '✓ موجود' : '✗ ناقص') . "\n\n";
}

// ── 3. Project id resolution ──
echo "3) معرّف المشروع (project id):\n";
$pid = fcm_project_id();
echo "   " . ($pid !== '' ? "✓ $pid" : '✗ غير محدد') . "\n\n";

// ── 4. Overall configured flag ──
echo "4) الحالة النهائية:\n";
$configured = fcm_is_configured();
echo "   fcm_is_configured() = " . ($configured ? '✅ true — جاهز' : '❌ false — غير جاهز') . "\n\n";

// ── 5. Can we actually mint a token? (real network round-trip) ──
if ($configured) {
    echo "5) اختبار جلب OAuth token (اتصال فعلي بجوجل):\n";
    $token = fcm_access_token();
    if ($token) {
        echo "   ✅ نجح — الخادم يستطيع المصادقة مع FCM.\n";
        echo "      (token يبدأ بـ " . substr($token, 0, 12) . "...)\n\n";
    } else {
        echo "   ❌ فشل — راجع error log. غالباً private_key تالف أو الساعة غير مضبوطة.\n\n";
    }
} else {
    echo "5) اختبار OAuth token: تخطّي (غير مُعد)\n\n";
}

// ── 6. Registered devices ──
echo "6) الأجهزة المسجّلة لاستقبال الإشعارات:\n";
try {
    $db = getDB();
    $total = (int)$db->query("SELECT COUNT(*) FROM user_devices WHERE is_active=1")->fetchColumn();
    echo "   • أجهزة نشطة: $total\n";
    if ($total === 0) {
        echo "     → لا يوجد جهاز مسجل بعد. سجّل دخولك في التطبيق واسمح بالإشعارات،\n";
        echo "       ثم أعد تشغيل هذا الفحص.\n";
    }
} catch (Throwable $e) {
    echo "   ✗ جدول user_devices غير موجود بعد (لا أجهزة مسجلة).\n";
}

echo "\n";

// ── 7. Quiet-hours preview ──
echo "7) ساعات الهدوء:\n";
$qStart = (int)getSetting('push_quiet_start', 23);
$qEnd   = (int)getSetting('push_quiet_end', 7);
$nowJ   = (new DateTime('now', new DateTimeZone('Asia/Jerusalem')))->format('H:i');
echo "   • النافذة: من {$qStart}:00 إلى {$qEnd}:00 (توقيت القدس)\n";
echo "   • الساعة الآن في القدس: $nowJ\n";
echo "   • هل نحن في وقت هدوء الآن؟ " . (push_is_quiet_hour() ? 'نعم (لا إشعارات عدا العاجل)' : 'لا (الإشعارات تعمل)') . "\n\n";

echo "═══════════════════════════════════════════════\n";
if ($configured) {
    echo "✅ كل شيء جاهز. الإشعارات ستعمل عند ورود أخبار عاجلة / فلسطينية.\n";
} else {
    echo "⚠️  FCM غير مُعد. اتبع الخطوات في رأس includes/push.php:\n";
    echo "   1. Firebase Console → Project settings → Service accounts →\n";
    echo "      Generate new private key (يحمّل ملف JSON)\n";
    echo "   2. ارفع الملف إلى: storage/fcm-service-account.json\n";
    echo "      (أو اضبط env FCM_SERVICE_ACCOUNT_JSON على مساره)\n";
    echo "   3. أعد تشغيل هذا الفحص.\n";
}
echo "═══════════════════════════════════════════════\n";
