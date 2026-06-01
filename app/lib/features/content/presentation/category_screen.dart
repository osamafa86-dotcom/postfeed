import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

// Virtual slugs from the home payload: content_type filters, palestine
// keyword splits, and topical aggregates. One provider so callers don't
// branch on bucket kind — they just push '/category/<slug>'.
final _articlesByCategoryProvider =
    FutureProvider.family((ref, String slug) {
  final repo = ref.watch(contentRepositoryProvider);
  switch (slug) {
    case 'palestine-news':
      return repo.articles(contentType: 'news', palestine: true, limit: 30);
    case 'arab-intl':
      return repo.articles(contentType: 'news', notPalestine: true, limit: 30);
    case 'ct-reports':
      return repo.articles(contentType: 'report', limit: 30);
    case 'ct-articles':
      return repo.articles(contentType: 'article', limit: 30);
    case 'agg-variety':
      return repo.articles(
        categorySlugs: const ['sports', 'arts', 'tech', 'media'],
        limit: 30,
      );
    case 'cat-health':
      return repo.articles(category: 'health', limit: 30);
    default:
      return repo.articles(category: slug, limit: 30);
  }
});

/// Human-readable titles for the virtual category slugs.
const _virtualSlugTitles = <String, String>{
  'palestine-news': 'أخبار فلسطين',
  'arab-intl':      'عربي ودولي',
  'ct-reports':     'تقارير',
  'ct-articles':    'مقالات رأي',
  'agg-variety':    'منوعات',
  'cat-health':     'صحة',
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
