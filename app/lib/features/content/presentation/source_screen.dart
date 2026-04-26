import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

final _articlesBySourceProvider = FutureProvider.family((ref, String slug) {
  return ref.watch(contentRepositoryProvider).articles(source: slug, limit: 30);
});

class SourceScreen extends ConsumerWidget {
  const SourceScreen({super.key, required this.slug});
  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_articlesBySourceProvider(slug));
    return Scaffold(
      appBar: AppBar(title: Text('مصدر: $slug')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: '$e',
          onRetry: () => ref.invalidate(_articlesBySourceProvider(slug)),
        ),
        data: (p) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(_articlesBySourceProvider(slug)),
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: p.items.length,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (_, i) => ArticleCard(article: p.items[i]),
          ),
        ),
      ),
    );
  }
}
