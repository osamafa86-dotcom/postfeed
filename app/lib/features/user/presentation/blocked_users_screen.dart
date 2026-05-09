import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/user_repository.dart';

final _blockedUsersProvider = FutureProvider<List<BlockedUser>>((ref) {
  return ref.watch(userRepositoryProvider).blockedUsers();
});

class BlockedUsersScreen extends ConsumerWidget {
  const BlockedUsersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asyncBlocked = ref.watch(_blockedUsersProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('المستخدمون المحظورون')),
      body: asyncBlocked.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(child: Text('تعذّر تحميل القائمة\n$e')),
        data: (list) {
          if (list.isEmpty) {
            return const Center(
              child: Padding(
                padding: EdgeInsets.all(24),
                child: Text(
                  'لم تحظر أي مستخدم بعد.\nيمكنك حظر مستخدم من قائمة التعليقات.',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 14, height: 1.6, color: Colors.grey),
                ),
              ),
            );
          }
          return ListView.separated(
            itemCount: list.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (_, i) {
              final u = list[i];
              return ListTile(
                leading: CircleAvatar(
                  child: Text(
                    (u.avatarLetter?.isNotEmpty == true)
                        ? u.avatarLetter!
                        : (u.name?.isNotEmpty == true
                            ? u.name!.substring(0, 1).toUpperCase()
                            : '؟'),
                  ),
                ),
                title: Text(u.name ?? 'مستخدم #${u.userId}'),
                trailing: TextButton(
                  onPressed: () => _confirmUnblock(context, ref, u),
                  child: const Text('إلغاء الحظر'),
                ),
              );
            },
          );
        },
      ),
    );
  }

  Future<void> _confirmUnblock(
    BuildContext context,
    WidgetRef ref,
    BlockedUser user,
  ) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إلغاء الحظر'),
        content: Text('هل تريد إلغاء حظر "${user.name ?? 'هذا المستخدم'}"؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('إلغاء'),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('نعم'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    try {
      await ref.read(userRepositoryProvider).unblockUser(user.userId);
      ref.invalidate(_blockedUsersProvider);
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('تعذّر إلغاء الحظر: $e')),
        );
      }
    }
  }
}
