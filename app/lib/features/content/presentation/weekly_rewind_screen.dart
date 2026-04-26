import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/widgets/loading_state.dart';

final _weeklyProvider = FutureProvider((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/content/weekly-rewind',
      decode: (d) => (d as Map).cast<String, dynamic>());
  return res.data!;
});

class WeeklyRewindScreen extends ConsumerWidget {
  const WeeklyRewindScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_weeklyProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('مراجعة الأسبوع')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(_weeklyProvider)),
        data: (d) {
          final stories = (d['top_stories'] as List? ?? []);
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text(d['title']?.toString() ?? '',
                  style: Theme.of(context).textTheme.headlineMedium),
              const SizedBox(height: 8),
              Text('${d['week_start']} → ${d['week_end']}',
                  style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 16),
              if ((d['summary'] ?? '').toString().isNotEmpty)
                Text(d['summary'].toString(),
                    style: Theme.of(context).textTheme.bodyLarge),
              const SizedBox(height: 24),
              for (final s in stories)
                if (s is Map)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: ListTile(
                      title: Text(s['title']?.toString() ?? ''),
                      subtitle: Text(s['summary']?.toString() ?? '',
                          maxLines: 3, overflow: TextOverflow.ellipsis),
                    ),
                  ),
            ],
          );
        },
      ),
    );
  }
}
