import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:go_router/go_router.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../router/app_router.dart';
import '../../features/auth/data/auth_storage.dart';
import '../../firebase_options.dart';

/// Bootstraps Firebase + FCM + APNs and registers the token with our backend
/// once the user is authenticated. Safe to call before login — the token
/// will be uploaded as soon as a JWT becomes available.
class PushService {
  static const _installationsChannel = MethodChannel('postfeed.feedsnews/installations');
  static final FlutterLocalNotificationsPlugin _local = FlutterLocalNotificationsPlugin();

  static Future<void> init({required ApiClient api}) async {
    try {
      // iOS: pass options explicitly so the build does not depend on a
      // GoogleService-Info.plist being wired into the Xcode target
      // (easy to forget; fails silently). Android reads its own
      // google-services.json via the Gradle plugin, unchanged.
      if (Platform.isIOS) {
        await Firebase.initializeApp(options: kFeedsNewsIosFirebaseOptions);
      } else {
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

    // Keep the api reference so a post-login call can flush the pending
    // token without having to thread the client back through.
    _api = api;

    // Obtain + register the token. On iOS this has to wait for the APNs
    // token first (see _obtainAndRegister), so it's its own method with retry.
    await _obtainAndRegister(messaging);
    messaging.onTokenRefresh.listen((t) {
      _lastToken = t;
      _registerToken(api, t);
    });
  }

  /// iOS will not hand out an FCM token until the APNs device token has
  /// been registered with Apple and set on the Firebase instance. If we
  /// call getToken() too early it returns null and — with the old code —
  /// the device was simply never registered, so no push ever arrived.
  /// Wait for the APNs token (a few short retries cover the round-trip),
  /// then fetch the FCM token, also with a couple of retries.
  static Future<void> _obtainAndRegister(FirebaseMessaging messaging) async {
    try {
      if (Platform.isIOS) {
        String? apns;
        for (var i = 0; i < 8; i++) {
          apns = await messaging.getAPNSToken();
          if (apns != null) break;
          await Future.delayed(const Duration(milliseconds: 1500));
        }
        if (apns == null) {
          debugPrint('[push] APNs token unavailable after retries — '
              'check: Push capability, provisioning profile, and that an '
              'APNs key is uploaded in Firebase Console.');
          // Still try getToken below; it may succeed on a later refresh.
        }

        // iOS Keychain preserves the Firebase Installation ID across app
        // uninstall/reinstall AND across the debug→TestFlight environment
        // transition. The cached FCM token then stays paired with a stale
        // APNs token from a previous build, and every push fails with
        // BadEnvironmentKeyInToken. Wiping the Installation natively (via
        // the AppDelegate Method Channel) and then deleting the FCM token
        // forces Firebase to mint a fresh FID + FCM + APNs chain tied to
        // the current build's environment. Gated by version so it fires
        // once per install/update.
        try {
          final prefs = await SharedPreferences.getInstance();
          final info = await PackageInfo.fromPlatform();
          final ver = '${info.version}+${info.buildNumber}';
          const flagKey = '_fcm_refreshed_for_version';
          if (prefs.getString(flagKey) != ver) {
            try { await _installationsChannel.invokeMethod('deleteInstallation'); } catch (_) {}
            try { await messaging.deleteToken(); } catch (_) {}
            await prefs.setString(flagKey, ver);
            debugPrint('[push] forced Installation+token refresh for $ver');
          }
        } catch (e) {
          debugPrint('[push] forced refresh check failed: $e');
        }
      }

      String? token;
      for (var i = 0; i < 3; i++) {
        try {
          token = await messaging.getToken();
        } catch (e) {
          debugPrint('[push] getToken attempt ${i + 1} failed: $e');
        }
        if (token != null) break;
        await Future.delayed(const Duration(seconds: 2));
      }

      if (token != null) {
        _lastToken = token;
        if (_api != null) await _registerToken(_api!, token);
        debugPrint('[push] FCM token obtained (${token.substring(0, 12)}…)');
      } else {
        debugPrint('[push] no FCM token — push disabled on this device');
      }
    } catch (e) {
      debugPrint('[push] _obtainAndRegister failed: $e');
    }
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

  static ApiClient? _api;
  // The most recent FCM token, kept regardless of auth state so we can
  // register it the moment the user logs in (the /user/devices endpoint
  // requires a JWT, so anonymous tokens can't be sent yet).
  static String? _lastToken;

  static Future<void> _registerToken(ApiClient api, String token) async {
    _lastToken = token;
    if (!AuthStorage.isAuthenticated) {
      debugPrint('[push] token held — will register after login');
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
      debugPrint('[push] device registered');
    } catch (e) {
      debugPrint('[push] device register failed: $e');
    }
  }

  /// Call this right after a successful login/register so the token we
  /// already hold (obtained while anonymous) gets sent to the backend.
  /// Previously this existed but was never invoked, so a user who logged
  /// in *after* launch never had their device registered → no push.
  static Future<void> registerPendingTokenIfAny([ApiClient? api]) async {
    final client = api ?? _api;
    final token = _lastToken;
    if (client != null && token != null && AuthStorage.isAuthenticated) {
      await _registerToken(client, token);
    }
  }
}
