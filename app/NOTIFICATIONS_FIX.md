# إصلاح الإشعارات الفورية (FCM / APNs) — فيد نيوز

> هذا الدليل يوثّق إصلاح مشكلة **عدم وصول الإشعارات للموبايل**. الإصلاحات
> البرمجية (المرحلة 4) **مُنفّذة بالفعل في الكود**. أما المراحل السحابية/اليدوية
> (1، 2، 3، 5، 6) فلا يمكن تنفيذها من داخل المستودع لأنها تحتاج وصولاً إلى
> Firebase Console و Apple Developer و خادمك — وهي مشروحة هنا خطوة بخطوة.

---

## معرّفات الحزم (مهم — مختلفة بين المنصتين!)

| المنصة | المعرّف | يُستخدم عند تسجيل التطبيق في Firebase |
|--------|---------|----------------------------------------|
| **iOS** | `net.feedsnews.newsfeed` | Bundle ID لتطبيق iOS |
| **Android** | `net.feedsnews.app` | package name لتطبيق Android |

> ⚠️ لا تستخدم نفس المعرّف للاثنين. تسجيل iOS بمعرّف خاطئ = لا إشعارات على الآيفون.

---

## ✅ المرحلة 4 — إصلاحات الكود (مُنفّذة)

تم تعديل ملفين:

### `lib/main.dart`
- تهيئة `Firebase.initializeApp()` **مبكراً** داخل `main()` قبل `runApp` (مع حارس `Firebase.apps.isEmpty` و try/catch حتى لا يعطّل الإقلاع عند غياب الإعداد).
- تسجيل **معالج الرسائل في الخلفية** `_firebaseMessagingBackgroundHandler` عبر
  `FirebaseMessaging.onBackgroundMessage(...)` — دالة top-level بـ
  `@pragma('vm:entry-point')`. بدونه كانت رسائل data-only تُفقد عند إغلاق التطبيق.

### `lib/core/notifications/push_service.dart`
- **حارس التهيئة المزدوجة**: `if (Firebase.apps.isEmpty)` — كانت التهيئة الثانية
  ترمي `duplicate-app` فتُجهض كل تسجيل التوكن.
- **فحص رفض الإذن**: عند `AuthorizationStatus.denied` نتوقف بدل تسجيل توكن ميّت.
- **انتظار APNs token قبل `getToken()` على iOS** (أهم إصلاح لـ iOS): استطلاع حتى
  ~3 ثوانٍ لـ `getAPNSToken()`. بدونه `getToken()` يرمي `apns-token-not-set`
  ويعيد null — وهي «العلّة الكلاسيكية: التوكنات لا تُسجَّل على الآيفون».
- **إنشاء قنوات Android صراحةً عند الإقلاع** (Android 8+) حتى تجد رسائل الخلفية
  قناة `breaking` (المضبوطة كـ `default_notification_channel_id`) جاهزة.
- معالجة `getToken()` داخل try/catch.

> بعد سحب التعديلات شغّل `flutter pub get` ثم `cd ios && pod install`.

---

## 🔧 المرحلة 1 — إعداد Firebase Console (إلزامي أولاً)

1. افتح [Firebase Console](https://console.firebase.google.com) → مشروعك (أو أنشئ واحداً).
2. **Project Settings → General → Your apps**:
   - **Add app → iOS**: Bundle ID = `net.feedsnews.newsfeed` → حمّل
     **`GoogleService-Info.plist`**.
   - **Add app → Android**: package = `net.feedsnews.app` → حمّل
     **`google-services.json`**.
3. **Project Settings → Cloud Messaging → Apple app configuration** (الأهم لـ iOS):
   - ارفع **APNs Authentication Key (.p8)**.
   - مصدره: [Apple Developer](https://developer.apple.com) → Certificates,
     Identifiers & Profiles → **Keys** → ‎+‎ → فعّل **Apple Push Notifications
     service (APNs)** → نزّل ملف `.p8` (مرة واحدة فقط!) وسجّل **Key ID**.
   - في Firebase أدخل: ملف `.p8` + Key ID + **Team ID** (من أعلى يمين حساب آبل).
   - بدون هذه الخطوة **لن يصل أي إشعار على iOS** مهما فعلت بالكود.
4. **Project Settings → Service accounts → Generate new private key** → احفظ ملف
   JSON (للخادم، المرحلة التالية).

---

## 🔧 المرحلة 2 — الخادم (PHP backend)

1. ارفع ملف الـ service account إلى المسار: `storage/fcm-service-account.json`
   (هو المسار الذي يفحصه `includes/push.php`)، أو اضبط متغيّر البيئة
   `FCM_SERVICE_ACCOUNT_JSON` بمحتوى الملف.
2. تأكّد أن مجلد `storage/` قابل للكتابة (يُخزّن `.fcm_token_cache`).
3. شغّل أداة التشخيص الجاهزة للتأكيد:
   ```bash
   php diag_fcm.php
   ```
   يجب أن تظهر: `fcm_is_configured() = ✅ true`، نجاح OAuth، وعدد الأجهزة المسجّلة.
4. **أثناء الاختبار**: ساعات الهدوء الافتراضية (23:00→07:00 بتوقيت القدس) تحجب كل
   شيء عدا «عاجل». اختبر بقناة **«عاجل»** (تتجاوز الهدوء)، أو عطّل النافذة مؤقتاً
   عبر ضبط `push_quiet_start == push_quiet_end`.

> ملاحظة: الـ backend سليم ويستخدم **FCM HTTP v1** (الحديث) — لا حاجة لأي تغيير
> فيه سوى رفع ملف الـ service account.

---

## 🔧 المرحلة 3 — وضع ملفات الإعداد في التطبيق

1. ضع `GoogleService-Info.plist` في `app/ios/Runner/` **وأضِفه لهدف Runner داخل
   Xcode** (اسحبه إلى المشروع وفعّل Target Membership = Runner).
2. ضع `google-services.json` في `app/android/app/`.
3. أعد تثبيت pods (الـ Podfile.lock الحالي قديم بلا Firebase):
   ```bash
   cd app/ios && pod install
   ```

> هذان الملفان مُستثنيان في `.gitignore` عمداً (خاصّان بمشروعك) — لذلك لن تجدهما
> في المستودع ويجب وضعهما يدوياً.

---

## 🔧 المرحلة 5 — قدرات Xcode

في Xcode → هدف **Runner** → **Signing & Capabilities**:
- فعّل **Push Notifications**.
- فعّل **Background Modes** → ✅ **Remote notifications**.
- تأكّد أن إعداد **Release** يربط `RunnerRelease.entitlements`
  (`aps-environment = production`)، و**Debug** يربط `Runner.entitlements`
  (`aps-environment = development`). كلاهما مضبوط مسبقاً في المستودع.

---

## 🔧 المرحلة 6 — التحقق النهائي

1. ابنِ التطبيق على **جهاز فعلي** (الإشعارات لا تعمل على iOS Simulator).
2. سجّل الدخول، واقبل إذن الإشعارات. راقب في الـ logs:
   - `FCM permission: AuthorizationStatus.authorized`
   - غياب `device register failed` و `APNs token unavailable`.
3. على الخادم: `php diag_fcm.php` يجب أن يُظهر «أجهزة نشطة ≥ 1».
4. أرسل اختبار **«عاجل»** (يتجاوز ساعات الهدوء).

---

## ⚠️ ملاحظة معمارية (للمتابعة لاحقاً)

- قنوات `trending / weekly / comments / stories / categories / sources` معرّفة في
  واجهة التطبيق، لكن **لا يوجد كرون على الخادم يرسل لها فعلياً** — فقط
  `عاجل/فلسطين` (`cron_rss.php`) و«الصباح» (`cron_sabah.php`). لتفعيلها يلزم
  إضافة استدعاءات `push_broadcast` في الكرونات المناسبة.
- التطبيق يشترك في **topics** (`feed_*`, `category_*`, `source_*`) بينما الخادم
  يرسل بالـ **token المباشر** عبر join على `user_devices`. هذا تعارض معماري يُفضّل
  توحيده (إما الاعتماد كلياً على topics أو كلياً على التوكن المباشر).
