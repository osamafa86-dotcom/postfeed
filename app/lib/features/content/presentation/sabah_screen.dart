import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/widgets/loading_state.dart';

final _sabahProvider = FutureProvider((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/content/sabah',
      decode: (d) => (d as Map).cast<String, dynamic>());
  return res.data!;
});

class SabahScreen extends ConsumerWidget {
  const SabahScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_sabahProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('صباح فيد نيوز')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(_sabahProvider)),
        data: (d) {
          final sections = (d['sections'] as List? ?? []);
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text(d['title']?.toString() ?? '—',
                  style: Theme.of(context).textTheme.headlineMedium),
              const SizedBox(height: 8),
              Text(d['date']?.toString() ?? '',
                  style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 16),
              if ((d['summary'] ?? '').toString().isNotEmpty)
                Text(d['summary'].toString(),
                    style: Theme.of(context).textTheme.bodyLarge),
              const SizedBox(height: 24),
              for (final s in sections)
                if (s is Map)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 18),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(s['title']?.toString() ?? '',
                            style: Theme.of(context).textTheme.titleMedium),
                        const SizedBox(height: 6),
                        Text(s['body']?.toString() ?? '',
                            style: Theme.of(context).textTheme.bodyMedium),
                      ],
                    ),
                  ),
            ],
          );
        },
      ),
    );
  }
}
