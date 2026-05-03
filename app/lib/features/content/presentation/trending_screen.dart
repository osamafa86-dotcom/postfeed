import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

final _trendingProvider = FutureProvider((ref) => ref.watch(contentRepositoryProvider).trending());

class TrendingScreen extends ConsumerWidget {
  const TrendingScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_trendingProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('الأكثر تداولاً')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(_trendingProvider)),
        data: (d) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (d.tags.isNotEmpty) ...[
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final t in d.tags)
                    ActionChip(
                      label: Text('# ${t.title}',
                        style: TextStyle(
                          fontWeight: FontWeight.w700,
                          color: Theme.of(context).brightness == Brightness.dark
                              ? AppColors.textDark
                              : AppColors.textLight,
                        )),
                      onPressed: () => context.push('/search?q=${Uri.encodeComponent(t.title)}'),
                    ),
                ],
              ),
              const SizedBox(height: 16),
            ],
            for (final a in d.articles) Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: ArticleCard(article: a),
            ),
          ],
        ),
      ),
    );
  }
}
