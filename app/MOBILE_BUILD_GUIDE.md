# فيد نيوز — دليل البناء والنشر

دليل لبناء التطبيق ورفعه على متجر آبل ومتجر جوجل.

## المتطلبات

| الأداة | الإصدار |
|--------|---------|
| Flutter SDK | 3.24+ |
| Dart | 3.4+ |
| Xcode | 15+ (للـ iOS) |
| Android Studio | Hedgehog 2023.1+ |
| Apple Developer Account | ✅ مطلوب |
| Google Play Console | $25 (مرة واحدة) |

## الخطوات الأولى

```bash
cd app
flutter pub get
dart run build_runner build --delete-conflicting-outputs
```

اضبط `API_BASE` لو كان السيرفر مختلفاً عن الافتراضي:
```bash
flutter run --dart-define=API_BASE=https://feedsnews.net/api/v1
```

## أيقونات التطبيق + Splash

ضع `assets/icons/app_icon.png` (1024x1024) و `assets/icons/splash_logo.png` (512x512)، ثم:
```bash
dart run flutter_launcher_icons
dart run flutter_native_splash:create
```

## بناء iOS للـ App Store

1. افتح المشروع: `open ios/Runner.xcworkspace`
2. في Xcode → Signing & Capabilities:
   - اختر Team
   - تأكد من Bundle Identifier: `net.feedsnews.app`
   - أضف Capabilities: **Push Notifications**, **Background Modes (audio + remote-notification + fetch)**, **Sign in with Apple**
3. ضع `GoogleService-Info.plist` في `ios/Runner/`
4. حدّث `ios/Runner/Info.plist` (موجود بالفعل) — تحقق من نصوص الأذونات

```bash
flutter build ipa --release
# الناتج: build/ios/ipa/feedsnews.ipa
# ارفعه عبر Transporter إلى App Store Connect
```

## بناء Android للـ Play Store

1. أنشئ keystore (مرة واحدة):
   ```bash
   keytool -genkey -v -keystore ~/feedsnews-release.jks \
     -keyalg RSA -keysize 2048 -validity 10000 -alias feedsnews
   ```
2. أنشئ `android/key.properties`:
   ```properties
   storePassword=...
   keyPassword=...
   keyAlias=feedsnews
   storeFile=/Users/<you>/feedsnews-release.jks
   ```
3. ضع `google-services.json` في `android/app/`
4. ابنِ:
   ```bash
   flutter build appbundle --release
   # الناتج: build/app/outputs/bundle/release/app-release.aab
   ```
5. ارفعه على Google Play Console

## Deep Links + Universal Links

- iOS: حدّث `apple-app-site-association` بـ Team ID الفعلي عبر الموقع
- Android: حدّث `assetlinks.json` بـ SHA-256 fingerprint من keystore:
  ```bash
  keytool -list -v -keystore ~/feedsnews-release.jks -alias feedsnews | grep SHA256
  ```
- ارفع الملفين على الموقع (موجودة بالفعل في `/.well-known/`)

## الـ APIs

التطبيق يتحدث مع:
- إنتاج: `https://feedsnews.net/api/v1`
- محلي: `http://10.0.2.2/api/v1` (Android emulator) أو `http://localhost/api/v1`

## الميزات المُضمَّنة (تطابق موقع v1.23)

✅ المقالات + التصنيفات + المصادر  
✅ البحث + الأكثر تداولاً + التيكر  
✅ البودكاست اليومي مع تشغيل في الخلفية  
✅ Telegram / Twitter / YouTube feeds  
✅ Reels (Instagram)  
✅ Gallery اليومي  
✅ خريطة الأخبار التفاعلية  
✅ Evolving Stories (5 قصص: الأقصى، الأسرى، غزة، الضفة، الاستيطان)  
✅ Story Quotes + Network + Book  
✅ Story Timelines  
✅ Sabah (موجز صباحي) + Weekly Rewind  
✅ Ask AI (Q&A)  
✅ TTS (قراءة المقالات)  
✅ Bookmarks + Follows + Notifications  
✅ Push (FCM/APNs)  
✅ Offline cache (Hive)  
✅ Sign in with Apple + Google  
✅ Dark / Light / Auto theme  
✅ Full RTL Arabic  

## Sentry (اختياري)

```bash
flutter run --dart-define=SENTRY_DSN=https://...@sentry.io/...
```
