import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

class EvolvingStoriesScreen extends ConsumerWidget {
  const EvolvingStoriesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(evolvingStoriesProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('القصص المتطورة')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(evolvingStoriesProvider)),
        data: (list) => list.isEmpty
            ? const EmptyView(message: 'لا توجد قصص متاحة')
            : ListView.separated(
                padding: const EdgeInsets.all(16),
                itemCount: list.length,
                separatorBuilder: (_, __) => const SizedBox(height: 12),
                itemBuilder: (_, i) {
                  final s = list[i];
                  Color accent;
                  try { accent = Color(int.parse(s.accentColor.replaceAll('#', '0xFF'))); }
                  catch (_) { accent = Theme.of(context).colorScheme.primary; }
                  return InkWell(
                    onTap: () => context.push('/stories/${s.slug}'),
                    borderRadius: BorderRadius.circular(14),
                    child: Container(
                      decoration: BoxDecoration(
                        color: accent.withOpacity(0.06),
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(color: accent.withOpacity(0.25)),
                      ),
                      padding: const EdgeInsets.all(12),
                      child: Row(
                        children: [
                          if (s.coverImage != null)
                            ClipRRect(
                              borderRadius: BorderRadius.circular(10),
                              child: SizedBox(
                                width: 76, height: 76,
                                child: CachedNetworkImage(imageUrl: s.coverImage!, fit: BoxFit.cover),
                              ),
                            )
                          else
                            Container(
                              width: 76, height: 76,
                              decoration: BoxDecoration(color: accent.withOpacity(0.1), borderRadius: BorderRadius.circular(10)),
                              alignment: Alignment.center,
                              child: Text(s.icon, style: const TextStyle(fontSize: 32)),
                            ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(s.name, style: TextStyle(color: accent, fontSize: 16, fontWeight: FontWeight.w800)),
                                if ((s.description ?? '').isNotEmpty)
                                  Padding(
                                    padding: const EdgeInsets.only(top: 4),
                                    child: Text(
                                      s.description!,
                                      style: Theme.of(context).textTheme.bodySmall,
                                      maxLines: 2, overflow: TextOverflow.ellipsis,
                                    ),
                                  ),
                                const SizedBox(height: 6),
                                Text('${s.articleCount} خبر',
                                    style: Theme.of(context).textTheme.bodySmall),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  );
                },
              ),
      ),
    );
  }
}
