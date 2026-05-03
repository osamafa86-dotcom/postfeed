import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../api/api_client.dart';
import '../../features/auth/data/auth_storage.dart';

/// Bootstraps Firebase + FCM + APNs and registers the token with our backend
/// once the user is authenticated. Safe to call before login — the token
/// will be uploaded as soon as a JWT becomes available.
class PushService {
  static final FlutterLocalNotificationsPlugin _local = FlutterLocalNotificationsPlugin();

  static Future<void> init({required ApiClient api}) async {
    try {
      await Firebase.initializeApp();
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
      );
    });

    // Initial token + listener for refreshes.
    final token = await messaging.getToken();
    if (token != null) await _registerToken(api, token);
    messaging.onTokenRefresh.listen((t) => _registerToken(api, t));
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
