import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

/// Articles that share a `cluster_key` — the same story covered by
/// multiple sources. Surfaced when the user taps the "📰 N مصادر"
/// badge on any home card; mirrors the website's /cluster/<key> page.
final _clusterArticlesProvider = FutureProvider.family((ref, String key) {
  return ref.watch(contentRepositoryProvider).articles(
        clusterKey: key,
        limit: 50,
      );
});

class ClusterScreen extends ConsumerWidget {
  const ClusterScreen({super.key, required this.clusterKey});

  /// 40-char SHA-1 hex key that groups the articles.
  final String clusterKey;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_clusterArticlesProvider(clusterKey));
    return Scaffold(
      appBar: AppBar(title: const Text('مقارنة التغطية')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: '$e',
          onRetry: () => ref.invalidate(_clusterArticlesProvider(clusterKey)),
        ),
        data: (p) {
          if (p.items.isEmpty) {
            return const EmptyView(
              icon: Icons.layers_outlined,
              message: 'لم نعثر على تغطيات أخرى لهذا الخبر',
              hint: 'تظهر المقارنة عندما يغطّي نفس الخبر أكثر من مصدر.',
            );
          }
          final sources = p.items
              .map((a) => a.source?.name)
              .whereType<String>()
              .toSet()
              .length;
          return RefreshIndicator(
            onRefresh: () async =>
                ref.invalidate(_clusterArticlesProvider(clusterKey)),
            child: CustomScrollView(
              slivers: [
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                    child: _Header(
                      articleCount: p.items.length,
                      sourceCount: sources,
                    ),
                  ),
                ),
                SliverPadding(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                  sliver: SliverList.separated(
                    itemCount: p.items.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 10),
                    itemBuilder: (_, i) => ArticleCard(article: p.items[i]),
                  ),
                ),
                const SliverToBoxAdapter(child: SizedBox(height: 24)),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.articleCount, required this.sourceCount});
  final int articleCount;
  final int sourceCount;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF1F4D5C), Color(0xFF2D6E84)],
          begin: Alignment.topLeft, end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(children: [
        Container(
          width: 42, height: 42,
          decoration: BoxDecoration(
            color: Colors.white.withOpacity(0.2),
            borderRadius: BorderRadius.circular(12),
          ),
          alignment: Alignment.center,
          child: const Icon(Icons.layers_rounded, color: Colors.white, size: 22),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('تغطية متعددة المصادر',
                style: TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 15)),
              const SizedBox(height: 4),
              Text(
                '$articleCount مقالات من $sourceCount ${sourceCount == 1 ? 'مصدر' : 'مصادر'}',
                style: TextStyle(
                  color: Colors.white.withOpacity(0.85),
                  fontSize: 12, fontWeight: FontWeight.w600),
              ),
            ],
          ),
        ),
      ]),
    );
  }
}
