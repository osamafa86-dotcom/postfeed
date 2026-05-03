import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../../user/data/user_repository.dart';

final _clustersProvider = FutureProvider<List<ArticleCluster>>((ref) {
  return ref.watch(userRepositoryProvider).clusters();
});

class ClustersScreen extends ConsumerWidget {
  const ClustersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_clustersProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('مقارنة التغطية')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: '$e',
          onRetry: () => ref.invalidate(_clustersProvider),
        ),
        data: (clusters) => clusters.isEmpty
            ? const EmptyView(message: 'لا توجد مجموعات حالياً')
            : RefreshIndicator(
                onRefresh: () async => ref.invalidate(_clustersProvider),
                child: ListView.builder(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(16),
                  itemCount: clusters.length,
                  itemBuilder: (_, i) => _ClusterCard(cluster: clusters[i]),
                ),
              ),
      ),
    );
  }
}

class _ClusterCard extends StatefulWidget {
  const _ClusterCard({required this.cluster});
  final ArticleCluster cluster;

  @override
  State<_ClusterCard> createState() => _ClusterCardState();
}

class _ClusterCardState extends State<_ClusterCard> {
  bool _expanded = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    final articles = widget.cluster.articles;
    final sourceCount = articles.map((a) => a.source?.name ?? '').toSet().length;

    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      decoration: BoxDecoration(
        color: theme.cardColor,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: theme.dividerColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Cluster header
          InkWell(
            onTap: () => setState(() => _expanded = !_expanded),
            borderRadius: const BorderRadius.vertical(top: Radius.circular(16)),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          widget.cluster.title,
                          style: TextStyle(
                            fontSize: 16, fontWeight: FontWeight.w800,
                            color: isDark ? Colors.white : AppColors.textLight,
                          ),
                          maxLines: 2, overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      Icon(
                        _expanded ? Icons.keyboard_arrow_up : Icons.keyboard_arrow_down,
                        color: isDark ? Colors.white38 : AppColors.textMutedLight,
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      _InfoChip(icon: Icons.article_outlined,
                          label: '${articles.length} تقرير', isDark: isDark),
                      const SizedBox(width: 8),
                      _InfoChip(icon: Icons.public,
                          label: '$sourceCount مصدر', isDark: isDark),
                    ],
                  ),

                  // Source logos row
                  if (articles.isNotEmpty) ...[
                    const SizedBox(height: 10),
                    Wrap(
                      spacing: 6,
                      runSpacing: 4,
                      children: articles
                          .where((a) => a.source != null)
                          .map((a) => a.source!)
                          .toSet()
                          .take(6)
                          .map((src) => Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(
                                  color: AppColors.primary.withOpacity(0.1),
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: Text(src.name,
                                    style: const TextStyle(
                                      color: AppColors.primary,
                                      fontSize: 10,
                                      fontWeight: FontWeight.w700,
                                    )),
                              ))
                          .toList(),
                    ),
                  ],
                ],
              ),
            ),
          ),

          // Expanded articles
          if (_expanded) ...[
            Divider(height: 1, color: theme.dividerColor),
            Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                children: articles
                    .map((a) => Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: ArticleCard(article: a, compact: true),
                        ))
                    .toList(),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _InfoChip extends StatelessWidget {
  const _InfoChip({required this.icon, required this.label, required this.isDark});
  final IconData icon;
  final String label;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: isDark ? Colors.white.withOpacity(0.06) : Colors.grey.withOpacity(0.08),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 13,
              color: isDark ? Colors.white38 : AppColors.textMutedLight),
          const SizedBox(width: 4),
          Text(label,
              style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600,
                  color: isDark ? Colors.white54 : AppColors.textMutedLight)),
        ],
      ),
    );
  }
}
