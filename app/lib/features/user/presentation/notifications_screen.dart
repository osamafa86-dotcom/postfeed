import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:timeago/timeago.dart' as timeago;

import '../../../core/api/api_client.dart';
import '../../../core/widgets/loading_state.dart';
import '../../auth/data/auth_storage.dart';

final _notificationsProvider = FutureProvider((ref) async {
  if (!AuthStorage.isAuthenticated) return <Map<String, dynamic>>[];
  final api = ref.watch(apiClientProvider);
  final res = await api.get<List<dynamic>>('/user/notifications',
      decode: (d) => (d as List).cast<dynamic>());
  return (res.data ?? const []).whereType<Map>().map((m) => m.cast<String, dynamic>()).toList();
});

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_notificationsProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('الإشعارات')),
      body: !AuthStorage.isAuthenticated
          ? EmptyView(
              icon: Icons.notifications_outlined,
              message: 'سجّل دخولك لرؤية الإشعارات',
              hint: 'الإشعارات تبقيك على اطّلاع بالأخبار العاجلة من مصادرك المتابعَة.',
              actionLabel: 'تسجيل الدخول',
              onAction: () => context.push('/login'),
            )
          : asy.when(
              loading: () => const LoadingShimmerList(),
              error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(_notificationsProvider)),
              data: (list) => list.isEmpty
                  ? const EmptyView(
                      icon: Icons.notifications_off_outlined,
                      message: 'لا إشعارات بعد',
                      hint: 'سنرسل لك تنبيهات حين تصلك أخبار عاجلة أو تحديثات من المصادر التي تتابعها.',
                    )
                  : ListView.separated(
                      itemCount: list.length,
                      separatorBuilder: (_, __) => const Divider(height: 1),
                      itemBuilder: (_, i) {
                        final n = list[i];
                        final dt = n['created_at'] != null
                            ? DateTime.tryParse(n['created_at'].toString().replaceFirst(' ', 'T'))
                            : null;
                        return ListTile(
                          leading: Text(n['icon']?.toString() ?? '🔔', style: const TextStyle(fontSize: 28)),
                          title: Text(n['title']?.toString() ?? '—',
                              style: TextStyle(fontWeight: n['is_read'] == true ? FontWeight.normal : FontWeight.w700)),
                          subtitle: Text(n['body']?.toString() ?? ''),
                          trailing: dt != null
                              ? Text(timeago.format(dt, locale: 'ar'),
                                  style: Theme.of(context).textTheme.bodySmall)
                              : null,
                        );
                      },
                    ),
            ),
    );
  }
}
