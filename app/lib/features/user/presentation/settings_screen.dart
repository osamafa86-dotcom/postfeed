import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/theme/theme_controller.dart';

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
          ListTile(
            title: const Text('سياسة الخصوصية'),
            onTap: () => launchUrl(Uri.parse('https://feedsnews.net/privacy.php')),
          ),
          ListTile(
            title: const Text('شروط الاستخدام'),
            onTap: () => launchUrl(Uri.parse('https://feedsnews.net/about.php')),
          ),
          ListTile(
            title: const Text('تواصل معنا'),
            onTap: () => launchUrl(Uri.parse('https://feedsnews.net/contact.php')),
          ),
          const Divider(),
          const ListTile(
            title: Text('الإصدار'),
            subtitle: Text('1.23.0'),
          ),
        ],
      ),
    );
  }
}

class _ThemePicker extends StatelessWidget {
  const _ThemePicker();
  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Wrap(
        children: const [
          _Item(mode: ThemeMode.light, label: 'فاتح', icon: Icons.light_mode),
          _Item(mode: ThemeMode.dark,  label: 'داكن', icon: Icons.dark_mode),
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
