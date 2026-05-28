import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:go_router/go_router.dart';

import '../../../core/api/api_client.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/theme/theme_controller.dart';
import '../../auth/data/auth_repository.dart';
import '../../auth/data/auth_state_provider.dart';
import '../data/user_repository.dart' show followedIdsProvider, bookmarkedIdsProvider;
import 'blocked_users_screen.dart';
import 'info_pages.dart';
import 'notification_settings_screen.dart';

class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mode = ref.watch(themeModeControllerProvider);
    final isAuthed = ref.watch(authStateProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('الإعدادات')),
      body: ListView(
        children: [
          // ── Notification Settings ──
          ListTile(
            leading: const Icon(Icons.notifications_active_outlined),
            title: const Text('الإشعارات الذكية'),
            subtitle: const Text('خصّص الأخبار التي تصلك'),
            trailing: const Icon(Icons.chevron_left),
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const NotificationSettingsScreen())),
          ),
          const Divider(),

          _ThemeTile(mode: mode, ref: ref),
          const Divider(),

          // ── Newsletter ──
          if (isAuthed)
            const _NewsletterTile(),

          const Divider(),

          ListTile(
            leading: const Icon(Icons.info_outline),
            title: const Text('من نحن'),
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const AboutPage())),
          ),
          ListTile(
            leading: const Icon(Icons.privacy_tip_outlined),
            title: const Text('سياسة الخصوصية'),
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const PrivacyPolicyPage())),
          ),
          ListTile(
            leading: const Icon(Icons.description_outlined),
            title: const Text('شروط الاستخدام'),
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const TermsOfServicePage())),
          ),
          if (isAuthed)
            ListTile(
              leading: const Icon(Icons.block),
              title: const Text('المستخدمون المحظورون'),
              subtitle: const Text('إدارة قائمة الحظر'),
              onTap: () => Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const BlockedUsersScreen())),
            ),
          ListTile(
            leading: const Icon(Icons.gavel_outlined),
            title: const Text('السياسة التحريرية'),
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const EditorialPolicyPage())),
          ),
          ListTile(
            leading: const Icon(Icons.fact_check_outlined),
            title: const Text('سياسة التصحيح'),
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const CorrectionsPolicyPage())),
          ),
          ListTile(
            leading: const Icon(Icons.mail_outline),
            title: const Text('تواصل معنا'),
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const ContactPage())),
          ),
          const Divider(),
          const ListTile(
            leading: Icon(Icons.info_outline),
            title: Text('الإصدار'),
            subtitle: Text('2.2.1'),
          ),

          // ── حذف الحساب ──
          if (isAuthed) ...[
            const Divider(),
            ListTile(
              leading: const Icon(Icons.logout),
              title: const Text('تسجيل الخروج'),
              subtitle: const Text('تخرج من حسابك مع الاحتفاظ ببياناتك'),
              onTap: () => _showLogoutDialog(context, ref),
            ),
            ListTile(
              leading: const Icon(Icons.delete_forever, color: Colors.red),
              title: const Text('حذف الحساب', style: TextStyle(color: Colors.red)),
              subtitle: const Text('حذف حسابك نهائياً مع جميع بياناتك'),
              onTap: () => _showDeleteAccountDialog(context, ref),
            ),
          ],
        ],
      ),
    );
  }

  void _showLogoutDialog(BuildContext context, WidgetRef ref) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('تسجيل الخروج'),
        content: const Text('هل تريد تسجيل الخروج من حسابك؟ يمكنك العودة في أيّ وقت.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () async {
              Navigator.of(ctx).pop();
              try {
                await ref.read(authRepositoryProvider).logout();
                // Broadcast to every ConsumerWidget watching authStateProvider
                // (Follow/Notifications/Bookmarks/Home For-You) so they
                // swap back to their signed-out branch immediately.
                ref.read(authStateProvider.notifier).refresh();
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('تمّ تسجيل الخروج')),
                  );
                  ref.invalidate(currentUserProvider);
                  ref.invalidate(followedIdsProvider);
                  ref.invalidate(bookmarkedIdsProvider);
                  context.go('/');
                }
              } catch (_) {
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('تعذّر تسجيل الخروج')),
                  );
                }
              }
            },
            child: const Text('تسجيل الخروج'),
          ),
        ],
      ),
    );
  }

  void _showDeleteAccountDialog(BuildContext context, WidgetRef ref) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حذف الحساب'),
        content: const Text(
          'سيتم حذف ما يلي نهائياً ولا يمكن التراجع:\n\n'
          '• ملفك الشخصي (الاسم، البريد، الصورة الرمزية)\n'
          '• المقالات المحفوظة والإعجابات\n'
          '• المتابعات (المصادر، الأقسام، القصص)\n'
          '• التعليقات التي كتبتها\n'
          '• سجل القراءة والإشعارات\n\n'
          'هل أنت متأكد؟',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () async {
              Navigator.of(ctx).pop();
              try {
                await ref.read(authRepositoryProvider).deleteAccount();
                ref.read(authStateProvider.notifier).refresh();
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('تم حذف حسابك بنجاح')),
                  );
                  // Drop cached user/follows so the next screen
                  // doesn't briefly show the deleted account's data.
                  ref.invalidate(currentUserProvider);
                  ref.invalidate(followedIdsProvider);
                  // Land on the root tab instead of popping until the
                  // first route — popUntil sometimes pops too far in
                  // a shell-route nav and lands on a blank screen.
                  context.go('/');
                }
              } catch (_) {
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('تعذّر حذف الحساب، حاول مرة أخرى')),
                  );
                }
              }
            },
            child: const Text('حذف نهائي'),
          ),
        ],
      ),
    );
  }
}

// ── Newsletter Toggle ──

class _NewsletterTile extends ConsumerStatefulWidget {
  const _NewsletterTile();

  @override
  ConsumerState<_NewsletterTile> createState() => _NewsletterTileState();
}

class _NewsletterTileState extends ConsumerState<_NewsletterTile> {
  bool _subscribed = false;
  bool _loading = false;

  Future<void> _toggle() async {
    setState(() => _loading = true);
    try {
      final api = ref.read(apiClientProvider);
      // Send the user's real email — the previous placeholder
      // 'user@feedsnews.net' polluted the subscribers list and made
      // the toggle look like it did nothing functional.
      final user = await ref.read(currentUserProvider.future);
      final email = (user?.email ?? '').isNotEmpty ? user!.email : null;
      if (email == null) {
        throw const FormatException('بريد المستخدم غير متاح، أعد تسجيل الدخول');
      }
      await api.post('/user/newsletter', body: {
        'email': email,
        'action': _subscribed ? 'unsubscribe' : 'subscribe',
      });
      setState(() => _subscribed = !_subscribed);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(_subscribed ? 'تم الاشتراك في النشرة البريدية' : 'تم إلغاء الاشتراك')),
        );
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('تعذّر تحديث الاشتراك')),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SwitchListTile(
      secondary: const Icon(Icons.newspaper),
      title: const Text('النشرة البريدية'),
      subtitle: const Text('تلقّي ملخص يومي بأهم الأخبار'),
      value: _subscribed,
      onChanged: _loading ? null : (_) => _toggle(),
      activeColor: AppColors.primary,
    );
  }
}

// ── Theme Tile (shows current mode + scheduled info) ──

class _ThemeTile extends StatelessWidget {
  const _ThemeTile({required this.mode, required this.ref});
  final ThemeMode mode;
  final WidgetRef ref;

  @override
  Widget build(BuildContext context) {
    final ctrl = ref.read(themeModeControllerProvider.notifier);
    final appMode = ctrl.appMode;

    String subtitle;
    switch (appMode) {
      case AppThemeMode.light:
        subtitle = 'فاتح';
      case AppThemeMode.dark:
        subtitle = 'داكن';
      case AppThemeMode.system:
        subtitle = 'تلقائي (حسب الجهاز)';
      case AppThemeMode.scheduled:
        final from = _formatHour(ctrl.darkFromHour);
        final to = _formatHour(ctrl.darkToHour);
        subtitle = 'ليلي تلقائي ($from → $to)';
    }

    return ListTile(
      leading: const Icon(Icons.palette_outlined),
      title: const Text('السمة'),
      subtitle: Text(subtitle),
      trailing: const Icon(Icons.chevron_left),
      onTap: () async {
        final picked = await showModalBottomSheet<AppThemeMode>(
          context: context,
          builder: (_) => _ThemePicker(currentMode: appMode, ctrl: ctrl),
        );
        if (picked != null) {
          ref.read(themeModeControllerProvider.notifier).setMode(picked);
        }
      },
    );
  }

  static String _formatHour(int h) {
    final period = h >= 12 ? 'م' : 'ص';
    final display = h == 0 ? 12 : (h > 12 ? h - 12 : h);
    return '$display $period';
  }
}

// ── Theme Picker (4 options including scheduled night) ──

class _ThemePicker extends StatefulWidget {
  const _ThemePicker({required this.currentMode, required this.ctrl});
  final AppThemeMode currentMode;
  final ThemeModeController ctrl;

  @override
  State<_ThemePicker> createState() => _ThemePickerState();
}

class _ThemePickerState extends State<_ThemePicker> {
  late int _fromHour;
  late int _toHour;

  @override
  void initState() {
    super.initState();
    _fromHour = widget.ctrl.darkFromHour;
    _toHour = widget.ctrl.darkToHour;
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Handle bar
            Container(
              width: 40, height: 4,
              margin: const EdgeInsets.only(bottom: 8),
              decoration: BoxDecoration(
                color: Theme.of(context).dividerColor,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            _PickerItem(
              icon: Icons.light_mode,
              label: 'فاتح',
              subtitle: 'وضع فاتح دائماً',
              selected: widget.currentMode == AppThemeMode.light,
              onTap: () => Navigator.of(context).pop(AppThemeMode.light),
            ),
            _PickerItem(
              icon: Icons.dark_mode,
              label: 'داكن',
              subtitle: 'وضع داكن دائماً',
              selected: widget.currentMode == AppThemeMode.dark,
              onTap: () => Navigator.of(context).pop(AppThemeMode.dark),
            ),
            _PickerItem(
              icon: Icons.auto_mode,
              label: 'تلقائي',
              subtitle: 'يتبع إعدادات جهازك',
              selected: widget.currentMode == AppThemeMode.system,
              onTap: () => Navigator.of(context).pop(AppThemeMode.system),
            ),
            const Divider(height: 1),
            _PickerItem(
              icon: Icons.nightlight_round,
              label: 'ليلي تلقائي',
              subtitle: 'داكن مساءً، فاتح صباحاً',
              selected: widget.currentMode == AppThemeMode.scheduled,
              onTap: () async {
                // Save times then return scheduled
                await widget.ctrl.setNightWindow(_fromHour, _toHour);
                if (context.mounted) Navigator.of(context).pop(AppThemeMode.scheduled);
              },
            ),
            // Time config (shown always so user can adjust before picking)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: isDark ? AppColors.neoDarkMid : AppColors.neoSurfaceMid,
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.schedule, size: 18),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('فترة الوضع الداكن',
                            style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700,
                              color: isDark ? AppColors.textDark : AppColors.textLight)),
                          const SizedBox(height: 4),
                          Row(
                            children: [
                              _TimeButton(
                                label: 'من ${_ThemeTile._formatHour(_fromHour)}',
                                onTap: () => _pickHour(true),
                              ),
                              const Padding(
                                padding: EdgeInsets.symmetric(horizontal: 8),
                                child: Icon(Icons.arrow_forward, size: 14),
                              ),
                              _TimeButton(
                                label: 'إلى ${_ThemeTile._formatHour(_toHour)}',
                                onTap: () => _pickHour(false),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _pickHour(bool isFrom) async {
    final initial = isFrom ? _fromHour : _toHour;
    final picked = await showTimePicker(
      context: context,
      initialTime: TimeOfDay(hour: initial, minute: 0),
      builder: (ctx, child) => MediaQuery(
        data: MediaQuery.of(ctx).copyWith(alwaysUse24HourFormat: false),
        child: Directionality(textDirection: TextDirection.rtl, child: child!),
      ),
    );
    if (picked != null && mounted) {
      setState(() {
        if (isFrom) {
          _fromHour = picked.hour;
        } else {
          _toHour = picked.hour;
        }
      });
    }
  }
}

class _PickerItem extends StatelessWidget {
  const _PickerItem({
    required this.icon, required this.label,
    required this.subtitle, required this.selected,
    required this.onTap,
  });
  final IconData icon;
  final String label;
  final String subtitle;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: Icon(icon, color: selected ? AppColors.primary : null),
      title: Text(label, style: TextStyle(
        fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
        color: selected ? AppColors.primary : null,
      )),
      subtitle: Text(subtitle, style: const TextStyle(fontSize: 12)),
      trailing: selected ? const Icon(Icons.check_circle, color: AppColors.primary) : null,
      onTap: onTap,
    );
  }
}

class _TimeButton extends StatelessWidget {
  const _TimeButton({required this.label, required this.onTap});
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: AppColors.primary.withOpacity(0.1),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: AppColors.primary.withOpacity(0.3)),
        ),
        child: Text(label, style: const TextStyle(
          fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.primary)),
      ),
    );
  }
}
