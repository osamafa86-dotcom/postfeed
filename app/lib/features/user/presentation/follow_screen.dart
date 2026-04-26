import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../auth/data/auth_repository.dart';

class FollowScreen extends ConsumerWidget {
  const FollowScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('متابعتي')),
      body: user.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(child: Text('$e')),
        data: (u) {
          if (u == null) {
            return Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Text('سجّل دخولك لمتابعة المصادر والأقسام'),
                  const SizedBox(height: 12),
                  ElevatedButton(
                    onPressed: () => context.push('/login'),
                    child: const Text('دخول'),
                  ),
                ],
              ),
            );
          }
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              ListTile(
                leading: const Icon(Icons.bookmark),
                title: const Text('المحفوظات'),
                onTap: () => context.push('/bookmarks'),
              ),
              ListTile(
                leading: const Icon(Icons.label),
                title: const Text('الأقسام المتابَعة'),
                onTap: () {},
              ),
              ListTile(
                leading: const Icon(Icons.public),
                title: const Text('المصادر المتابَعة'),
                onTap: () {},
              ),
              ListTile(
                leading: const Icon(Icons.bookmark_added_outlined),
                title: const Text('القصص المتابَعة'),
                onTap: () => context.push('/stories'),
              ),
            ],
          );
        },
      ),
    );
  }
}
