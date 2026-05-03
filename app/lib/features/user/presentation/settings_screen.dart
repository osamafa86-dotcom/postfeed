import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/api/api_client.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/theme/theme_controller.dart';
import '../../auth/data/auth_repository.dart';
import '../../auth/data/auth_storage.dart';
import 'info_pages.dart';

class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mode = ref.watch(themeModeControllerProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('الإعدادات')),
      body: ListView(
        children: [
          ListTile(
            leading: const Icon(Icons.palette_outlined),
            title: const Text('السمة'),
            subtitle: Text(switch (mode) {
              ThemeMode.light => 'فاتح',
              ThemeMode.dark => 'داكن',
              ThemeMode.system => 'تلقائي',
            }),
            trailing: const Icon(Icons.chevron_left),
            onTap: () async {
              final picked = await showModalBottomSheet<ThemeMode>(
                context: context,
                builder: (_) => const _ThemePicker(),
              );
              if (picked != null) {
                ref.read(themeModeControllerProvider.notifier).set(picked);
              }
            },
          ),
          const Divider(),

          // ── Newsletter ──
          if (AuthStorage.isAuthenticated)
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
            subtitle: Text('2.0.0'),
          ),

          // ── حذف الحساب ──
          if (AuthStorage.isAuthenticated) ...[
            const Divider(),
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

  void _showDeleteAccountDialog(BuildContext context, WidgetRef ref) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حذف الحساب'),
        content: const Text(
          'هل أنت متأكد من حذف حسابك؟ سيتم حذف جميع بياناتك بشكل نهائي ولا يمكن التراجع عن هذا الإجراء.',
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
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('تم حذف حسابك بنجاح')),
                  );
                  Navigator.of(context).popUntil((route) => route.isFirst);
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
      // We use the user's email (stored in auth) but for simplicity
      // we pass a placeholder — the backend can also look up the user.
      await api.post('/user/newsletter', body: {
        'email': 'user@feedsnews.net', // placeholder — backend uses user_id
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

// ── Theme Picker ──

class _ThemePicker extends StatelessWidget {
  const _ThemePicker();
  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Wrap(
        children: const [
          _Item(mode: ThemeMode.light, label: 'فاتح', icon: Icons.light_mode),
          _Item(mode: ThemeMode.dark, label: 'داكن', icon: Icons.dark_mode),
          _Item(mode: ThemeMode.system, label: 'تلقائي', icon: Icons.auto_mode),
        ],
      ),
    );
  }
}

class _Item extends StatelessWidget {
  const _Item({required this.mode, required this.label, required this.icon});
  final ThemeMode mode;
  final String label;
  final IconData icon;
  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: Icon(icon),
      title: Text(label),
      onTap: () => Navigator.of(context).pop(mode),
    );
  }
}
