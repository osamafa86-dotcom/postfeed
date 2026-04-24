# NewsFeed iOS App

تطبيق iOS أصيل (Native SwiftUI) لموقع **نيوز فيد**، جاهز للرفع على App Store.
يستهلك الـ Backend عبر `/api/v1/*` بمصادقة Bearer Token.

- **اللغة الأساسية:** العربية (RTL مُفعّل تلقائياً)
- **الحد الأدنى من iOS:** 16.0
- **Swift:** 5.9 · **SwiftUI** · **async/await**
- **Bundle Identifier:** `net.feedsnews.newsfeed` (عدّله من `project.yml`)

## البنية

```
ios/
├── project.yml                 # XcodeGen spec — يولّد NewsFeed.xcodeproj
├── README.md                   # هذا الملف
└── NewsFeed/
    ├── App/                    # نقطة الدخول + TabView الرئيسي
    ├── Core/
    │   ├── API/                # APIClient + Endpoints + Models
    │   ├── Models/             # Codable structs
    │   ├── Storage/            # Keychain · SessionStore · ThemeStore
    │   ├── Utilities/          # RelativeTime · Color · RemoteImage
    │   └── Push/               # NotificationManager (APNs)
    ├── Features/
    │   ├── Feed/               # الشاشة الرئيسية + الفئات
    │   ├── Article/            # تفاصيل الخبر + التعليقات + الإبلاغ
    │   ├── Search/             # البحث
    │   ├── Trending/           # الأكثر قراءة
    │   ├── Ask/                # AI Q&A
    │   ├── Bookmarks/          # المحفوظات
    │   ├── Notifications/      # الإشعارات
    │   ├── Auth/               # تسجيل الدخول/الإنشاء
    │   └── Profile/            # الحساب + الإعدادات + حذف الحساب
    ├── Info.plist
    ├── NewsFeed.entitlements   # aps-environment
    ├── PrivacyInfo.xcprivacy   # Privacy Manifest (مطلوب منذ 2024)
    ├── LaunchScreen.storyboard
    ├── Assets.xcassets/        # ضع AppIcon-1024.png هنا
    ├── ar.lproj/               # تعريب
    └── en.lproj/               # English
```

## توليد مشروع Xcode

الطريقة الموصى بها — **XcodeGen** (يخلق `NewsFeed.xcodeproj` من `project.yml`):

```bash
brew install xcodegen
cd ios
xcodegen generate
open NewsFeed.xcodeproj
```

أو بدون XcodeGen: افتح Xcode → **File → New → Project → iOS → App**،
اضبط `Product Name: NewsFeed`، Bundle ID: `net.feedsnews.newsfeed`،
Language: Swift، Interface: SwiftUI. ثم اسحب مجلد `NewsFeed/` كاملاً
إلى الـ Navigator واختر "Create groups".

## الإعداد قبل أول Build

1. **Apple Developer Team ID** — في `project.yml` ضع الـ Team ID الخاص
   بحسابك في `DEVELOPMENT_TEAM`، أو اختر الحساب من تبويب **Signing &
   Capabilities** داخل Xcode.

2. **App Icon** — نزّل أيقونة 1024×1024 من الباكند:
   ```bash
   curl -o NewsFeed/Assets.xcassets/AppIcon.appiconset/AppIcon-1024.png \
        "https://feedsnews.net/icon.php?size=1024"
   ```

3. **Capability: Push Notifications** — في تبويب **Signing &
   Capabilities** اضغط `+ Capability` وأضف **Push Notifications**.
   الإعدادات موجودة في `NewsFeed.entitlements`.

4. **APNs Key** — في [App Store Connect](https://appstoreconnect.apple.com)
   → Keys → `+` → Apple Push Notifications service. نزّل `.p8`
   واحفظه. ستستخدمه في الباكند لإرسال الإشعارات (`cron_notifications.php`
   يمكن إضافته لاحقاً).

5. **API Base URL** — يعمل افتراضياً على `https://feedsnews.net/api/v1`.
   للتطوير/الـ staging، ضع `API_BASE_URL` في `Info.plist` (المفتاح موجود فارغاً).

## تشغيل قاعدة البيانات

الهجرة `migrations/004_api_tokens.sql` تنشئ جداول `api_tokens` و
`device_tokens`. تُنفَّذ تلقائياً من `includes/api_tokens_migrate.php`
في أول طلب على `/api/v1/*`.

للتنفيذ اليدوي:
```bash
mysql -u newsfeed -p newsfeed < migrations/004_api_tokens.sql
```

## قائمة مراجعة App Store

### مواصفات مطلوبة (4.2 Minimum Functionality)
- ✅ الاستهلاك عبر API أصيل (Bearer) وليس WebView للموقع.
- ✅ تجربة iOS أصيلة: TabView، Navigation، Swipe، Refresh.
- ✅ Push Notifications أصيلة (APNs) وليس Web Push.
- ✅ Dark Mode تلقائي + يدوي.
- ✅ RTL كامل للعربية.

### محتوى المستخدمين (1.2 UGC)
- ✅ تسجيل + تسجيل دخول.
- ✅ شروط استخدام وسياسة خصوصية مرتبطة في شاشة التسجيل.
- ✅ آلية **إبلاغ** عن المحتوى المسيء (`ReportSheet`).
- ✅ آلية **حظر** المستخدمين (`/api/v1/report.php` kind=block_user).
- ✅ حذف التعليقات من قِبَل صاحبها.

### الخصوصية (5.1)
- ✅ `PrivacyInfo.xcprivacy` يصرّح ببيانات الحساب والإشعارات (مطلوب منذ مايو 2024).
- ✅ `NSAppTransportSecurity` = HTTPS فقط.
- ✅ `NSUserTrackingUsageDescription` موجود حتى عند عدم التتبع.
- ✅ **حذف الحساب** (5.1.1(v)) عبر `/api/v1/auth/delete_account.php`
  و زر "حذف الحساب نهائياً" في `ProfileView`.
- ✅ كلمات المرور عبر `password_hash(PASSWORD_BCRYPT, cost=11)`.
- ✅ التوكنات محفوظة في Keychain بـ
  `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`.

### التصدير (5.2)
- ✅ `ITSAppUsesNonExemptEncryption = false` — نستخدم HTTPS الأصيل فقط.

### تسجيل الدخول (4.8 Sign in with Apple)
- ⚠️ **اختياري حالياً**: التطبيق يعرض تسجيل دخول مجاني بالبريد.
  إذا أضفت Google/Facebook/X لاحقاً ستحتاج Sign in with Apple أيضاً.

### أصول التسليم (لـ App Store Connect)

| البند | المواصفات |
|------|-----------|
| App Icon | 1024×1024 PNG بدون شفافية |
| Screenshots iPhone 6.7" | 1290×2796 (3 صور على الأقل) |
| Screenshots iPhone 6.1" | 1179×2556 (3 صور على الأقل) |
| Screenshots iPad 13" | 2064×2752 (اختياري) |
| Preview Video | ≤30 ثانية، MOV/M4V (اختياري) |
| وصف عربي | ≤4000 حرف |
| كلمات دلالية | ≤100 حرف مفصولة بفواصل |
| Support URL | `https://feedsnews.net/contact.php` |
| Privacy Policy URL | `https://feedsnews.net/privacy.php` |
| Marketing URL | `https://feedsnews.net` (اختياري) |

## خطوات الرفع على App Store

```bash
# 1. رفع الإصدار
# في Xcode: Product → Archive → Distribute App → App Store Connect → Upload

# 2. انتظار المعالجة (~15 دقيقة)
# 3. TestFlight للاختبار الداخلي
# 4. App Store → إضافة اللقطات + الوصف → Submit for Review
```

## مصادر مفيدة

- [App Store Review Guidelines](https://developer.apple.com/app-store/review/guidelines/)
- [Privacy Manifest Reference](https://developer.apple.com/documentation/bundleresources/privacy_manifest_files)
- [Human Interface Guidelines — Arabic/RTL](https://developer.apple.com/design/human-interface-guidelines/right-to-left)
- [APNs Overview](https://developer.apple.com/documentation/usernotifications/setting_up_a_remote_notification_server)

## ترخيص

نفس ترخيص الموقع الأساسي.
