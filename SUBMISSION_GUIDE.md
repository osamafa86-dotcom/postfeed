# دليل النشر على App Store + Google Play

## نظرة عامة

كل push على `main` يبني تلقائياً:
- **APK + AAB** للأندرويد (سيرفر Ubuntu)
- **IPA** لـ iOS (سيرفر macOS مع Xcode 16 الثابت)

تنزّل الملفات من تبويب **Actions** على GitHub.

---

## ١. أندرويد — Google Play

### الخطوات (لمرة واحدة):

1. **أنشئ keystore للتوقيع:**
   ```bash
   keytool -genkey -v -keystore feedsnews.jks \
     -keyalg RSA -keysize 2048 -validity 10000 \
     -alias feedsnews
   ```

2. **أضف Secrets على GitHub:**
   - `ANDROID_KEYSTORE_BASE64`: `base64 < feedsnews.jks`
   - `ANDROID_KEYSTORE_PASSWORD`
   - `ANDROID_KEY_ALIAS` = `feedsnews`
   - `ANDROID_KEY_PASSWORD`

3. **حساب Google Play Console** ($25 مرة واحدة):
   - https://play.google.com/console
   - أنشئ تطبيق جديد
   - Bundle ID: `net.feedsnews.app`

4. **رفع AAB:**
   - نزّل `feedsnews-release-aab` من GitHub Actions
   - ارفعه في Play Console > Internal Testing
   - اختبر، ثم Production

---

## ٢. iOS — App Store

### المتطلبات (من حسابك Apple Developer):

1. **App ID مسجّل:**
   - https://developer.apple.com/account/resources/identifiers/list
   - أنشئ App ID بـ Bundle ID: `net.feedsnews.app`
   - فعّل: Push Notifications, Sign In with Apple

2. **Distribution Certificate (.p12):**
   - https://developer.apple.com/account/resources/certificates/list
   - أنشئ "Apple Distribution"
   - نزّل الشهادة وحوّلها لـ .p12 من Keychain

3. **Provisioning Profile (App Store):**
   - https://developer.apple.com/account/resources/profiles/list
   - نوع: App Store Distribution
   - اربطه بـ App ID + Distribution Certificate

4. **App Store Connect API Key:**
   - https://appstoreconnect.apple.com/access/api
   - Issuer ID + Key ID + .p8 file

### GitHub Secrets المطلوبة:

```
IOS_CERTIFICATE_BASE64       = base64 < dist.p12
IOS_CERTIFICATE_PASSWORD     = كلمة سر الشهادة
IOS_KEYCHAIN_PASSWORD        = أي كلمة عشوائية
IOS_PROVISIONING_PROFILE_BASE64 = base64 < feedsnews.mobileprovision
APPLE_TEAM_ID                = من Apple Developer
APP_STORE_CONNECT_API_KEY_ID
APP_STORE_CONNECT_API_ISSUER_ID
APP_STORE_CONNECT_API_KEY_BASE64 = base64 < AuthKey_XXX.p8
```

### بعد الإعداد:

1. كل push على main → IPA يُرفع لـ TestFlight تلقائياً
2. على https://appstoreconnect.apple.com:
   - تأكد من ظهور البناء (5-10 دقائق)
   - أضف Tester (نفسك)
   - اختبر التطبيق على iPhone
3. عندما تكون جاهز للنشر:
   - Submit for Review
   - مراجعة Apple: 1-3 أيام
   - التطبيق ينشر

---

## ٣. ما تحتاج تجهيزه (محتوى)

### App Store Connect:
- [ ] **اسم التطبيق:** فيد نيوز
- [ ] **Subtitle:** مجمع المصادر الإخبارية
- [ ] **الوصف العربي + الإنجليزي** (4000 حرف لكل)
- [ ] **Keywords** (100 حرف)
- [ ] **App Icon 1024×1024** (PNG, no transparency)
- [ ] **5 لقطات شاشة** لكل: iPhone 6.7", 6.5", 5.5", iPad 12.9"
- [ ] **Privacy Policy URL:** https://feedsnews.net/privacy.php
- [ ] **Support URL:** https://feedsnews.net/contact.php
- [ ] **Category:** News
- [ ] **Age Rating:** 12+ (لمحتوى أخبار العنف)
- [ ] **Privacy Practices** (App Privacy):
  - Email (لينكنت بالحساب)
  - User Content (تعليقات)
  - Identifiers (Device ID للإشعارات)

### Google Play Console:
- [ ] **Title:** فيد نيوز - أخبار
- [ ] **Short description** (80 حرف)
- [ ] **Full description** (4000 حرف)
- [ ] **App Icon 512×512**
- [ ] **Feature Graphic 1024×500**
- [ ] **8 لقطات شاشة** (Phone + Tablet)
- [ ] **Privacy Policy URL**
- [ ] **Content Rating** (questionnaire)
- [ ] **Target Audience:** 13+

---

## ٤. مشاكل شائعة عند المراجعة

### Apple Rejection Reasons:
1. **"Sign in with Apple" مفقود** → موجود في الكود ✓
2. **Privacy Practices ناقصة** → اكتبها بدقة في App Store Connect
3. **التطبيق يكرر محتوى الويب** → ميزات native (offline, push, audio bg)
4. **Test account credentials** → أعطِ Apple حساب تجريبي للاختبار
5. **Crashes في الاختبار** → اختبر TestFlight قبل Submit

### Google Play Rejection Reasons:
1. **Permissions غير مبررة** → نص واضح في Manifest
2. **Privacy Policy غير صحيح** → رابط شغّال
3. **Target API level** → Android 14 (مضبوط) ✓

---

## ٥. الخطوات الآن

1. ✅ GitHub Actions جاهز
2. ⬜ افتح Apple Developer + Google Play Console وجهّز الحسابات
3. ⬜ أعطني صور شاشات + أيقونة 1024×1024 + الوصف
4. ⬜ أنشئ Bundle ID + Certificate + Provisioning Profile
5. ⬜ أضف Secrets على GitHub
6. ⬜ أول build يُرفع تلقائياً لـ TestFlight + Play Internal Testing
