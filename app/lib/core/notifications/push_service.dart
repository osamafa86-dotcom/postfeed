import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:go_router/go_router.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../api/api_client.dart';
import '../router/app_router.dart';
import '../../features/auth/data/auth_storage.dart';

/// Bootstraps Firebase + FCM + APNs and registers the token with our backend
/// once the user is authenticated. Safe to call before login — the token
/// will be uploaded as soon as a JWT becomes available.
class PushService {
  static final FlutterLocalNotificationsPlugin _local = FlutterLocalNotificationsPlugin();

  static Future<void> init({required ApiClient api}) async {
    try {
      // Guard against a double init: main() already initializes Firebase so
      // it can register the background handler. Calling initializeApp() a
      // second time throws `duplicate-app`, which previously aborted this
      // whole method (and with it token registration).
      if (Firebase.apps.isEmpty) {
        await Firebase.initializeApp();
      }
    } catch (e) {
      debugPrint('Firebase init failed: $e');
      return;
    }

    final messaging = FirebaseMessaging.instance;
    final settings = await messaging.requestPermission(
      alert: true, badge: true, sound: true, provisional: false,
    );
    debugPrint('FCM permission: ${settings.authorizationStatus}');

    // If the user explicitly denied notifications there is no point
    // continuing: iOS will never deliver an APNs token, so getToken()
    // would fail and we'd register a dead token. Bail out cleanly.
    if (settings.authorizationStatus == AuthorizationStatus.denied) {
      debugPrint('FCM permission denied — skipping token registration');
      return;
    }

    // Local notifications channel (Android requires explicit channel creation).
    const androidInit = AndroidInitializationSettings('@mipmap/ic_launcher');
    const iosInit = DarwinInitializationSettings(
      requestAlertPermission: true,
      requestBadgePermission: true,
      requestSoundPermission: true,
    );
    await _local.initialize(
      const InitializationSettings(android: androidInit, iOS: iosInit),
      onDidReceiveNotificationResponse: (resp) {
        final payload = resp.payload;
        if (payload != null && payload.isNotEmpty) _navigateTo(payload);
      },
    );

    // Android 8+ requires a channel to exist before any notification posts.
    // A backgrounded FCM `notification` message is routed by the OS to
    // `default_notification_channel_id` (= `breaking` in AndroidManifest),
    // so that channel must already exist — the foreground path used to
    // create channels lazily, which left background messages on a silent
    // fallback channel. Create them all up front.
    if (Platform.isAndroid) {
      final android = _local.resolvePlatformSpecificImplementation<
          AndroidFlutterLocalNotificationsPlugin>();
      for (final entry in _channelNames.entries) {
        await android?.createNotificationChannel(
          AndroidNotificationChannel(
            entry.key,
            entry.value,
            description: 'إشعارات فيد نيوز',
            importance:
                entry.key == 'breaking' ? Importance.max : Importance.high,
          ),
        );
      }
    }

    if (Platform.isIOS) {
      await messaging.setForegroundNotificationPresentationOptions(
        alert: true, badge: true, sound: true,
      );
    }

    // Foreground messages -> show local notification with correct channel.
    FirebaseMessaging.onMessage.listen((msg) {
      final n = msg.notification;
      if (n == null) return;

      // Determine which channel this notification belongs to
      final channelId = msg.data['channel'] as String? ?? 'breaking';
      final channelName = _channelNames[channelId] ?? 'الأخبار العاجلة';

      _local.show(
        msg.hashCode,
        n.title ?? 'فيد نيوز',
        n.body ?? '',
        NotificationDetails(
          android: AndroidNotificationDetails(
            channelId,
            channelName,
            channelDescription: 'إشعارات فيد نيوز',
            importance: channelId == 'breaking' ? Importance.max : Importance.high,
            priority: channelId == 'breaking' ? Priority.max : Priority.high,
            styleInformation: BigTextStyleInformation(n.body ?? ''),
          ),
          iOS: const DarwinNotificationDetails(),
        ),
        payload: _linkFrom(msg.data),
      );
    });

    // Tap handling: background→foreground, and cold start.
    FirebaseMessaging.onMessageOpenedApp.listen((msg) => _navigateTo(_linkFrom(msg.data)));
    final initial = await messaging.getInitialMessage();
    if (initial != null) {
      _navigateTo(_linkFrom(initial.data));
    }

    // On iOS the APNs token must be set before requesting the FCM token,
    // otherwise getToken() throws `apns-token-not-set` and returns null —
    // which is the classic "tokens never register on iPhone" bug. With the
    // default AppDelegate proxy enabled the APNs token arrives shortly after
    // permission is granted, so poll briefly (up to ~3s) for it.
    if (Platform.isIOS) {
      String? apns = await messaging.getAPNSToken();
      for (var i = 0; i < 6 && apns == null; i++) {
        await Future.delayed(const Duration(milliseconds: 500));
        apns = await messaging.getAPNSToken();
      }
      if (apns == null) {
        debugPrint(
            'APNs token unavailable — verify Push capability + APNs .p8 key in Firebase');
      }
    }

    // Initial token + listener for refreshes.
    String? token;
    try {
      token = await messaging.getToken();
    } catch (e) {
      debugPrint('getToken failed: $e');
    }
    if (token != null) await _registerToken(api, token);
    messaging.onTokenRefresh.listen((t) => _registerToken(api, t));
  }

  /// Extract an in-app route from a message's data payload. Falls back
  /// to an article deep link when only article_id is present.
  static String _linkFrom(Map<String, dynamic> data) {
    final link = data['link']?.toString();
    if (link != null && link.startsWith('/')) return link;
    final articleId = data['article_id']?.toString();
    if (articleId != null && articleId.isNotEmpty) return '/article/$articleId';
    return '';
  }

  static void _navigateTo(String route) {
    if (route.isEmpty) return;
    // The router may not be mounted yet on cold start; defer a frame.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      rootNavigatorKey.currentContext?.push(route);
    });
  }

  /// Channel display names for Android notifications.
  static const _channelNames = <String, String>{
    'breaking':   'الأخبار العاجلة',
    'daily':      'ملخص الصباح',
    'categories': 'أقسامك المفضلة',
    'sources':    'مصادرك المفضلة',
    'stories':    'القصص المتطورة',
    'trending':   'الأكثر تداولاً',
    'weekly':     'مراجعة الأسبوع',
    'comments':   'ردود وتفاعلات',
  };

  static Future<void> _registerToken(ApiClient api, String token) async {
    if (!AuthStorage.isAuthenticated) {
      _pendingToken = token;
      return;
    }
    try {
      final info = await PackageInfo.fromPlatform();
      await api.post('/user/devices', body: {
        'token': token,
        'platform': Platform.isIOS ? 'ios' : 'android',
        'app_version': info.version,
        'locale': 'ar',
      });
    } catch (e) {
      debugPrint('device register failed: $e');
    }
  }

  static String? _pendingToken;
  static Future<void> registerPendingTokenIfAny(ApiClient api) async {
    if (_pendingToken != null && AuthStorage.isAuthenticated) {
      await _registerToken(api, _pendingToken!);
      _pendingToken = null;
    }
  }
}
