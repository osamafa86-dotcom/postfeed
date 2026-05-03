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

  /// Android notification channels for smart notifications.
  static const _channels = [
    AndroidNotificationChannel(
      'breaking', 'الأخبار العاجلة',
      description: 'إشعارات الأخبار العاجلة والتحديثات الهامة',
      importance: Importance.max,
    ),
    AndroidNotificationChannel(
      'daily', 'ملخص الصباح',
      description: 'ملخص يومي بأهم الأخبار',
      importance: Importance.defaultImportance,
    ),
    AndroidNotificationChannel(
      'categories', 'أقسامك المفضلة',
      description: 'أخبار جديدة في الأقسام التي تتابعها',
      importance: Importance.defaultImportance,
    ),
    AndroidNotificationChannel(
      'sources', 'مصادرك المفضلة',
      description: 'تحديثات من المصادر التي تتابعها',
      importance: Importance.defaultImportance,
    ),
    AndroidNotificationChannel(
      'stories', 'القصص المتطورة',
      description: 'تطورات جديدة في القصص التي تتابعها',
      importance: Importance.defaultImportance,
    ),
    AndroidNotificationChannel(
      'trending', 'الأكثر تداولاً',
      description: 'عندما يبدأ موضوع بالانتشار',
      importance: Importance.low,
    ),
    AndroidNotificationChannel(
      'weekly', 'مراجعة الأسبوع',
      description: 'ملخص أسبوعي بأهم الأحداث',
      importance: Importance.low,
    ),
    AndroidNotificationChannel(
      'comments', 'ردود وتفاعلات',
      description: 'عندما يرد أحد على تعليقك',
      importance: Importance.defaultImportance,
    ),
  ];

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

    // Create all Android notification channels
    if (Platform.isAndroid) {
      final androidPlugin = _local.resolvePlatformSpecificImplementation<
          AndroidFlutterLocalNotificationsPlugin>();
      if (androidPlugin != null) {
        for (final ch in _channels) {
          await androidPlugin.createNotificationChannel(ch);
        }
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

      _local.show(
        msg.hashCode,
        n.title ?? 'فيد نيوز',
        n.body ?? '',
        NotificationDetails(
          android: AndroidNotificationDetails(
            channelId,
            _channelName(channelId),
            channelDescription: _channelDesc(channelId),
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

  static String _channelName(String id) {
    for (final ch in _channels) {
      if (ch.id == id) return ch.name;
    }
    return 'الأخبار العاجلة';
  }

  static String _channelDesc(String id) {
    for (final ch in _channels) {
      if (ch.id == id) return ch.description ?? '';
    }
    return '';
  }

  static Future<void> _registerToken(ApiClient api, String token) async {
    if (!AuthStorage.isAuthenticated) {
      // Will be registered after login by a deferred call.
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
