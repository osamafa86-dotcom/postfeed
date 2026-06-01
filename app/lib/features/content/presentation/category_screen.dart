import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

// Virtual slugs from the home payload: content_type filters + topical
// aggregates. Keep the routing key under one provider so callers don't
// have to care which kind of bucket they're viewing.
final _articlesByCategoryProvider =
    FutureProvider.family((ref, String slug) {
  final repo = ref.watch(contentRepositoryProvider);
  switch (slug) {
    case 'ct-reports':
      return repo.articles(contentType: 'report', limit: 30);
    case 'ct-articles':
      return repo.articles(contentType: 'article', limit: 30);
    case 'agg-variety':
      return repo.articles(
        categorySlugs: const ['sports', 'arts', 'tech', 'media'],
        limit: 30,
      );
    default:
      return repo.articles(category: slug, limit: 30);
  }
});

/// Human-readable titles for the virtual category slugs.
const _virtualSlugTitles = <String, String>{
  'ct-reports':  'تقارير',
  'ct-articles': 'مقالات',
  'agg-variety': 'منوعات',
};

class CategoryScreen extends ConsumerWidget {
  const CategoryScreen({super.key, required this.slug});
  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_articlesByCategoryProvider(slug));
    // Resolve the human-readable category name. Virtual slugs come from
    // the home payload and aren't in the categories table, so they get
    // their own lookup table. Real-category slugs fall through to the
    // DB-backed name, with the slug itself as the last-resort fallback.
    final cats = ref.watch(categoriesProvider).asData?.value ?? const [];
    String title = _virtualSlugTitles[slug] ?? slug;
    if (!_virtualSlugTitles.containsKey(slug)) {
      for (final c in cats) {
        if (c.slug == slug) { title = c.name; break; }
      }
    }
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: '$e',
          onRetry: () => ref.invalidate(_articlesByCategoryProvider(slug)),
        ),
        data: (p) => p.items.isEmpty
            ? const EmptyView(message: 'لا توجد مقالات في هذا القسم')
            : RefreshIndicator(
                onRefresh: () async => ref.invalidate(_articlesByCategoryProvider(slug)),
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
