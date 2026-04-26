import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../auth/data/auth_repository.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    return Scaffold(
      appBar: AppBar(
        title: const Text('حسابي'),
        actions: [
          IconButton(
            icon: const Icon(Icons.settings_outlined),
            onPressed: () => context.push('/settings'),
          ),
        ],
      ),
      body: user.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(child: Text('$e')),
        data: (u) => u == null ? _Guest(context, ref) : _AuthedView(u: u, ref: ref),
      ),
    );
  }

  Widget _Guest(BuildContext context, WidgetRef ref) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.person_outline, size: 64, color: AppColors.primary),
            const SizedBox(height: 12),
            const Text('سجّل دخولك للمتابعة',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            const Text('احفظ المقالات، اتبع المصادر، استلم إشعارات الأخبار العاجلة',
                textAlign: TextAlign.center),
            const SizedBox(height: 18),
            ElevatedButton.icon(
              onPressed: () => context.push('/login'),
              icon: const Icon(Icons.login),
              label: const Text('تسجيل الدخول'),
            ),
            const SizedBox(height: 8),
            TextButton(
              onPressed: () => context.push('/register'),
              child: const Text('إنشاء حساب جديد'),
            ),
          ],
        ),
      ),
    );
  }
}

class _AuthedView extends StatelessWidget {
  const _AuthedView({required this.u, required this.ref});
  final dynamic u;
  final WidgetRef ref;

  @override
  Widget build(BuildContext context) {
    return ListView(
      children: [
        const SizedBox(height: 16),
        CircleAvatar(
          radius: 36,
          backgroundColor: AppColors.primary,
          child: Text(
            (u.avatarLetter ?? 'م').toString(),
            style: const TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.w800),
          ),
        ),
        const SizedBox(height: 12),
        Center(child: Text(u.name, style: Theme.of(context).textTheme.titleLarge)),
        if ((u.email ?? '').isNotEmpty)
          Center(child: Text(u.email, style: Theme.of(context).textTheme.bodySmall)),
        const SizedBox(height: 24),
        ListTile(
          leading: const Icon(Icons.bookmark_outline),
          title: const Text('المقالات المحفوظة'),
          onTap: () => context.push('/bookmarks'),
        ),
        ListTile(
          leading: const Icon(Icons.notifications_outlined),
          title: const Text('الإشعارات'),
          onTap: () => context.push('/notifications'),
        ),
        ListTile(
          leading: const Icon(Icons.history),
          title: const Text('سجل القراءة'),
          onTap: () {},
        ),
        ListTile(
          leading: const Icon(Icons.local_fire_department, color: Colors.orange),
          title: const Text('سلسلة القراءة'),
          subtitle: Text('${u.readingStreak} يوم متواصل'),
        ),
        const Divider(),
        ListTile(
          leading: const Icon(Icons.settings_outlined),
          title: const Text('الإعدادات'),
          onTap: () => context.push('/settings'),
        ),
        ListTile(
          leading: const Icon(Icons.logout, color: Colors.red),
          title: const Text('تسجيل الخروج', style: TextStyle(color: Colors.red)),
          onTap: () async {
            await ref.read(authRepositoryProvider).logout();
            ref.invalidate(currentUserProvider);
          },
        ),
      ],
    );
  }
}
