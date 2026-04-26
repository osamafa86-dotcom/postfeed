# فيد نيوز — Feed News Mobile App

Flutter app (iOS + Android) replicating the full feedsnews.net experience.

## Stack
- Flutter 3.24+ (Dart 3.4+)
- Riverpod for state management
- Dio + Retrofit for networking
- go_router for navigation
- just_audio for podcast playback
- Firebase Cloud Messaging for push
- flutter_map for the news map

## Features (mirrors website v1.23)
- المقالات + التصنيفات + المصادر + البحث + الأكثر قراءة
- البودكاست اليومي (تشغيل في الخلفية + قائمة الحلقات)
- خلاصات Telegram / Twitter / YouTube
- Reels (Instagram embeds)
- Gallery اليومي
- News Map (خريطة جغرافية تفاعلية)
- Evolving Stories + Quotes + Network + Book
- Timelines (story timelines)
- Sabah (الموجز الصباحي)
- Weekly Rewind
- Ask AI (سؤال وجواب على الأرشيف)
- TTS لقراءة المقالات
- Bookmarks + Follows + History + Reactions + Comments
- Newsletter signup
- Push notifications (breaking, followed, digest)
- Dark / Light / Auto theme
- Full RTL Arabic UX
- Sign in with Apple + Google

## Building

```bash
cd app
flutter pub get
dart run build_runner build --delete-conflicting-outputs
flutter run
```

## API
The app talks to `https://feedsnews.net/api/v1/`. See `lib/core/api/` for endpoint
definitions and `../api/v1/` for the PHP server.

## Bundle IDs
- iOS:     `net.feedsnews.app`
- Android: `net.feedsnews.app`

## Build & ship
- iOS: `flutter build ipa --release`, then upload via Transporter to App Store Connect.
- Android: `flutter build appbundle --release`, then upload to Play Console.
