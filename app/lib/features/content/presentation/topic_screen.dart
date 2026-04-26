import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

final _topicProvider = FutureProvider.family((ref, String slug) {
  return ref.watch(contentRepositoryProvider).articles(category: slug, limit: 30);
});

class TopicScreen extends ConsumerWidget {
  const TopicScreen({super.key, required this.slug});
  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_topicProvider(slug));
    return Scaffold(
      appBar: AppBar(title: Text(slug)),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(_topicProvider(slug))),
        data: (p) => ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: p.items.length,
          separatorBuilder: (_, __) => const SizedBox(height: 10),
          itemBuilder: (_, i) => ArticleCard(article: p.items[i]),
        ),
      ),
    );
  }
}
