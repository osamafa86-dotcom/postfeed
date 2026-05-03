import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/notifications/notification_preferences.dart';
import '../../../core/theme/app_theme.dart';

// ═══════════════════════════════════════════════════════════════
// شاشة إعدادات الإشعارات الذكية
// ═══════════════════════════════════════════════════════════════

class NotificationSettingsScreen extends ConsumerWidget {
  const NotificationSettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final prefs = ref.watch(notificationPrefsProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      appBar: AppBar(title: const Text('الإشعارات الذكية')),
      body: ListView(
        padding: const EdgeInsets.symmetric(vertical: 8),
        children: [
          // Header
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
            child: Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: [
                    AppColors.primary.withOpacity(0.12),
                    AppColors.accent.withOpacity(0.08),
                  ],
                ),
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: AppColors.primary.withOpacity(0.2)),
              ),
              child: Row(
                children: [
                  Container(
                    width: 48, height: 48,
                    decoration: BoxDecoration(
                      color: AppColors.primary.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    alignment: Alignment.center,
                    child: const Text('🔔', style: TextStyle(fontSize: 24)),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('خصّص إشعاراتك',
                          style: TextStyle(
                            fontSize: 16, fontWeight: FontWeight.w800,
                            color: isDark ? AppColors.textDark : AppColors.textLight)),
                        const SizedBox(height: 4),
                        Text('اختر الأخبار التي تهمّك فقط',
                          style: TextStyle(fontSize: 13,
                            color: isDark ? AppColors.textMutedDark : AppColors.textMutedLight)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),

          // ── Essential Section ──
          _SectionHeader(title: 'أساسية', icon: '⚡'),
          _NotifToggle(channel: NotifChannel.breaking, prefs: prefs),
          _NotifToggle(channel: NotifChannel.daily, prefs: prefs),
          const Divider(height: 24),

          // ── Based on Interests ──
          _SectionHeader(title: 'حسب اهتماماتك', icon: '🎯'),
          _NotifToggle(channel: NotifChannel.categories, prefs: prefs),
          _NotifToggle(channel: NotifChannel.sources, prefs: prefs),
          _NotifToggle(channel: NotifChannel.stories, prefs: prefs),
          const Divider(height: 24),

          // ── Discovery ──
          _SectionHeader(title: 'اكتشاف', icon: '🌟'),
          _NotifToggle(channel: NotifChannel.trending, prefs: prefs),
          _NotifToggle(channel: NotifChannel.weekly, prefs: prefs),
          const Divider(height: 24),

          // ── Social ──
          _SectionHeader(title: 'تفاعل', icon: '💬'),
          _NotifToggle(channel: NotifChannel.comments, prefs: prefs),

          const SizedBox(height: 24),

          // Footer note
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Text(
              'الإشعارات الذكية تعتمد على الأقسام والمصادر التي تتابعها. '
              'كلما تابعت أقسام ومصادر أكثر، كانت الإشعارات أدق.',
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 12, height: 1.6,
                color: isDark ? AppColors.textMutedDark : AppColors.textMutedLight,
              ),
            ),
          ),
          const SizedBox(height: 32),
        ],
      ),
    );
  }
}

// ── Section Header ──

class _SectionHeader extends StatelessWidget {
  const _SectionHeader({required this.title, required this.icon});
  final String title;
  final String icon;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
      child: Row(
        children: [
          Text(icon, style: const TextStyle(fontSize: 16)),
          const SizedBox(width: 8),
          Text(title, style: TextStyle(
            fontSize: 14, fontWeight: FontWeight.w800,
            color: isDark ? AppColors.textDark : AppColors.textLight)),
        ],
      ),
    );
  }
}

// ── Notification Toggle Tile ──

class _NotifToggle extends StatelessWidget {
  const _NotifToggle({required this.channel, required this.prefs});
  final NotifChannel channel;
  final NotificationPreferences prefs;

  @override
  Widget build(BuildContext context) {
    final enabled = prefs.isEnabled(channel);
    // Split label into emoji + text
    final parts = channel.label.split(' ');
    final emoji = parts.first;
    final text = parts.skip(1).join(' ');

    return SwitchListTile(
      secondary: Text(emoji, style: const TextStyle(fontSize: 22)),
      title: Text(text, style: const TextStyle(fontWeight: FontWeight.w600)),
      subtitle: Text(channel.description,
        style: const TextStyle(fontSize: 12)),
      value: enabled,
      onChanged: (_) => prefs.toggle(channel),
      activeColor: AppColors.primary,
    );
  }
}
