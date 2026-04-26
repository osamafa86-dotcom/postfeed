import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/widgets/loading_state.dart';

final _timelinesProvider = FutureProvider((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<List<dynamic>>('/content/timelines',
      decode: (d) => (d as List).cast<dynamic>());
  return res.data ?? const <dynamic>[];
});

class TimelinesScreen extends ConsumerWidget {
  const TimelinesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_timelinesProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('الجداول الزمنية')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(_timelinesProvider)),
        data: (list) => list.isEmpty
            ? const EmptyView(message: 'لا توجد جداول زمنية متاحة')
            : ListView.separated(
                padding: const EdgeInsets.all(16),
                itemCount: list.length,
                separatorBuilder: (_, __) => const Divider(),
                itemBuilder: (_, i) {
                  final t = (list[i] as Map).cast<String, dynamic>();
                  return ListTile(
                    title: Text(t['title']?.toString() ?? '—',
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text(t['summary']?.toString() ?? '',
                        maxLines: 2, overflow: TextOverflow.ellipsis),
                    trailing: Text('${t['article_count'] ?? 0}'),
                  );
                },
              ),
      ),
    );
  }
}
