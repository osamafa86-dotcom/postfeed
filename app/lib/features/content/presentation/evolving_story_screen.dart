import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../../../core/widgets/section_header.dart';
import '../data/content_repository.dart';

final _storyDetailProvider = FutureProvider.family((ref, String slug) {
  return ref.watch(contentRepositoryProvider).evolvingStory(slug);
});

final _storyQuotesProvider = FutureProvider.family((ref, String slug) {
  return ref.watch(contentRepositoryProvider).storyQuotes(slug);
});

class EvolvingStoryScreen extends ConsumerWidget {
  const EvolvingStoryScreen({super.key, required this.slug});
  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(_storyDetailProvider(slug));
    final quotes = ref.watch(_storyQuotesProvider(slug));

    return Scaffold(
      appBar: AppBar(title: Text(detail.maybeWhen(data: (d) => d.story.name, orElse: () => 'قصة'))),
      body: detail.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(_storyDetailProvider(slug))),
        data: (d) => RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(_storyDetailProvider(slug));
            ref.invalidate(_storyQuotesProvider(slug));
          },
          child: ListView(
            padding: EdgeInsets.zero,
            children: [
              if ((d.story.description ?? '').isNotEmpty)
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(d.story.description!,
                      style: Theme.of(context).textTheme.bodyLarge),
                ),
              quotes.maybeWhen(
                data: (qs) => qs.isEmpty ? const SizedBox.shrink() : Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const SectionHeader(title: 'اقتباسات', icon: Icons.format_quote),
                    SizedBox(
                      height: 160,
                      child: ListView.separated(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        scrollDirection: Axis.horizontal,
                        itemCount: qs.length,
                        separatorBuilder: (_, __) => const SizedBox(width: 10),
                        itemBuilder: (_, i) => Container(
                          width: 280,
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: Theme.of(context).cardColor,
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(color: Theme.of(context).dividerColor),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('"${qs[i].quote}"', maxLines: 4, overflow: TextOverflow.ellipsis),
                              const Spacer(),
                              if (qs[i].speaker != null)
                                Text('— ${qs[i].speaker}',
                                    style: Theme.of(context).textTheme.bodySmall),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
                orElse: () => const SizedBox.shrink(),
              ),
              const SectionHeader(title: 'الأخبار', icon: Icons.article_outlined),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Column(
                  children: [
                    for (final a in d.articles)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: ArticleCard(article: a),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 24),
            ],
          ),
        ),
      ),
    );
  }
}
