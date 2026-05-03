import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../../features/auth/data/auth_storage.dart';

// ═══════════════════════════════════════════════════════════════
// إشعارات ذكية — Smart Notification Preferences
// ═══════════════════════════════════════════════════════════════

/// All notification channels the user can toggle.
enum NotifChannel {
  breaking   ('breaking',    '🔴 أخبار عاجلة',           'إشعار فوري عند ورود خبر عاجل'),
  daily      ('daily',       '☀️ ملخص الصباح',           'ملخص يومي بأهم الأخبار صباحاً'),
  categories ('categories',  '📂 أقسامك المفضلة',        'أخبار جديدة في الأقسام التي تتابعها'),
  sources    ('sources',     '📰 مصادرك المفضلة',        'تحديثات من المصادر التي تتابعها'),
  stories    ('stories',     '📖 القصص المتطورة',        'تطورات جديدة في القصص التي تتابعها'),
  trending   ('trending',    '🔥 الأكثر تداولاً',        'عندما يبدأ موضوع بالانتشار'),
  weekly     ('weekly',      '📊 مراجعة الأسبوع',        'ملخص أسبوعي بأهم الأحداث'),
  comments   ('comments',    '💬 ردود وتفاعلات',         'عندما يرد أحد على تعليقك'),
  ;

  const NotifChannel(this.key, this.label, this.description);
  final String key;
  final String label;
  final String description;
}

/// Manages notification preferences — FCM topic subscriptions + backend sync.
class NotificationPreferences extends ChangeNotifier {
  NotificationPreferences(this._api);
  final ApiClient _api;

  static const _prefix = 'notif_';
  final Map<String, bool> _enabled = {};
  bool _loaded = false;

  bool get loaded => _loaded;

  /// Whether a specific channel is enabled.
  bool isEnabled(NotifChannel ch) => _enabled[ch.key] ?? _defaultFor(ch);

  /// Default state for each channel.
  bool _defaultFor(NotifChannel ch) {
    switch (ch) {
      case NotifChannel.breaking:
      case NotifChannel.daily:
      case NotifChannel.categories:
      case NotifChannel.sources:
        return true; // On by default
      case NotifChannel.stories:
      case NotifChannel.trending:
      case NotifChannel.weekly:
      case NotifChannel.comments:
        return false; // Off by default, user opts in
    }
  }

  /// Load saved preferences from SharedPreferences.
  Future<void> load() async {
    final p = await SharedPreferences.getInstance();
    for (final ch in NotifChannel.values) {
      final val = p.getBool('$_prefix${ch.key}');
      _enabled[ch.key] = val ?? _defaultFor(ch);
    }
    _loaded = true;
    notifyListeners();
    // Ensure FCM topics match saved state
    await _syncTopics();
  }

  /// Toggle a channel on/off.
  Future<void> toggle(NotifChannel ch) async {
    final current = isEnabled(ch);
    _enabled[ch.key] = !current;
    notifyListeners();

    final p = await SharedPreferences.getInstance();
    await p.setBool('$_prefix${ch.key}', !current);

    // Update FCM topic subscription
    await _updateTopic(ch, !current);

    // Sync with backend
    await _syncBackend();
  }

  /// Set a channel explicitly.
  Future<void> setEnabled(NotifChannel ch, bool value) async {
    if (isEnabled(ch) == value) return;
    _enabled[ch.key] = value;
    notifyListeners();

    final p = await SharedPreferences.getInstance();
    await p.setBool('$_prefix${ch.key}', value);

    await _updateTopic(ch, value);
    await _syncBackend();
  }

  /// Subscribe/unsubscribe from FCM topic.
  Future<void> _updateTopic(NotifChannel ch, bool subscribe) async {
    try {
      final messaging = FirebaseMessaging.instance;
      final topic = 'feed_${ch.key}';
      if (subscribe) {
        await messaging.subscribeToTopic(topic);
        debugPrint('FCM subscribed to $topic');
      } else {
        await messaging.unsubscribeFromTopic(topic);
        debugPrint('FCM unsubscribed from $topic');
      }
    } catch (e) {
      debugPrint('FCM topic update failed: $e');
    }
  }

  /// Subscribe to all enabled topics (call after login or first launch).
  Future<void> _syncTopics() async {
    for (final ch in NotifChannel.values) {
      await _updateTopic(ch, isEnabled(ch));
    }
  }

  /// Send preferences to backend so server can filter push notifications.
  Future<void> _syncBackend() async {
    if (!AuthStorage.isAuthenticated) return;
    try {
      final prefs = <String, bool>{};
      for (final ch in NotifChannel.values) {
        prefs[ch.key] = isEnabled(ch);
      }
      await _api.post('/user/notification-preferences', body: prefs);
    } catch (e) {
      debugPrint('Notification prefs sync failed: $e');
    }
  }

  /// Sync followed categories/sources as individual FCM topics.
  Future<void> syncFollowedTopics(Map<String, Set<int>> follows) async {
    if (!isEnabled(NotifChannel.categories) && !isEnabled(NotifChannel.sources)) return;

    final messaging = FirebaseMessaging.instance;

    // Subscribe to followed categories
    if (isEnabled(NotifChannel.categories)) {
      for (final catId in (follows['category'] ?? <int>{})) {
        try {
          await messaging.subscribeToTopic('category_$catId');
        } catch (_) {}
      }
    }

    // Subscribe to followed sources
    if (isEnabled(NotifChannel.sources)) {
      for (final srcId in (follows['source'] ?? <int>{})) {
        try {
          await messaging.subscribeToTopic('source_$srcId');
        } catch (_) {}
      }
    }

    // Subscribe to followed stories
    if (isEnabled(NotifChannel.stories)) {
      for (final storyId in (follows['story'] ?? <int>{})) {
        try {
          await messaging.subscribeToTopic('story_$storyId');
        } catch (_) {}
      }
    }
  }
}

// ═══════════════════════════════════════════════════════════════
// PROVIDER
// ═══════════════════════════════════════════════════════════════

final notificationPrefsProvider = ChangeNotifierProvider<NotificationPreferences>((ref) {
  final prefs = NotificationPreferences(ref.watch(apiClientProvider));
  prefs.load();
  return prefs;
});
