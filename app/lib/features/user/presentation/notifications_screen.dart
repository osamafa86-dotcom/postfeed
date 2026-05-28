import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:timeago/timeago.dart' as timeago;

import '../../../core/api/api_client.dart';
import '../../../core/widgets/loading_state.dart';
import '../../auth/data/auth_state_provider.dart';
import '../../auth/data/auth_storage.dart';

final _notificationsProvider = FutureProvider((ref) async {
  // Re-fetch on auth changes so a logout+login cycle on the same
  // device shows the new user's notifications, not the old user's.
  ref.watch(authStateProvider);
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
    // See FollowScreen for the rationale — watching the reactive auth
    // provider is what makes this widget rebuild after sign-in when it
    // was first built (cached signed-out) inside MainShell's IndexedStack.
    final isAuthed = ref.watch(authStateProvider);
    final asy = ref.watch(_notificationsProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('الإشعارات')),
      body: !isAuthed
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
              data: (list) => RefreshIndicator(
                onRefresh: () async => ref.invalidate(_notificationsProvider),
                child: list.isEmpty
                  ? ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      children: const [
                        SizedBox(height: 80),
                        EmptyView(
                          icon: Icons.notifications_off_outlined,
                          message: 'لا إشعارات بعد',
                          hint: 'سنرسل لك تنبيهات حين تصلك أخبار عاجلة أو تحديثات من المصادر التي تتابعها.',
                        ),
                      ],
                    )
                  : ListView.separated(
                      physics: const AlwaysScrollableScrollPhysics(),
                      itemCount: list.length,
                      separatorBuilder: (_, __) => const Divider(height: 1),
                      itemBuilder: (_, i) {
                        final n = list[i];
                        final dt = n['created_at'] != null
                            ? DateTime.tryParse(n['created_at'].toString().replaceFirst(' ', 'T'))
                            : null;
                        final articleId = (n['article_id'] as num?)?.toInt();
                        // Server sends `link` (the in-app path);
                        // accept `url` too in case an older
                        // notification was queued with the legacy key.
                        final url = (n['link'] ?? n['url'])?.toString();
                        return ListTile(
                          leading: Text(n['icon']?.toString() ?? '🔔', style: const TextStyle(fontSize: 28)),
                          title: Text(n['title']?.toString() ?? '—',
                              style: TextStyle(fontWeight: n['is_read'] == true ? FontWeight.normal : FontWeight.w700)),
                          subtitle: Text(n['body']?.toString() ?? ''),
                          trailing: dt != null
                              ? Text(timeago.format(dt, locale: 'ar'),
                                  style: Theme.of(context).textTheme.bodySmall)
                              : null,
                          onTap: (articleId != null && articleId > 0)
                              ? () => context.push('/article/$articleId')
                              : (url != null && url.isNotEmpty
                                  ? () => context.push(url.startsWith('/') ? url : '/article/0')
                                  : null),
                        );
                      },
                    ),
              ),
            ),
    );
  }
}
