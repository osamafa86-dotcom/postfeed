import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/models/article.dart';
import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../../auth/data/auth_storage.dart';
import '../data/user_repository.dart';

final _bookmarksProvider = FutureProvider<List<Article>>((ref) async {
  if (!AuthStorage.isAuthenticated) return [];
  return ref.watch(userRepositoryProvider).bookmarks();
});

class BookmarksScreen extends ConsumerWidget {
  const BookmarksScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_bookmarksProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('المحفوظات')),
      body: !AuthStorage.isAuthenticated
          ? const EmptyView(message: 'سجّل دخولك أولاً لحفظ المقالات')
          : asy.when(
              loading: () => const LoadingShimmerList(),
              error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(_bookmarksProvider)),
              data: (list) => list.isEmpty
                  ? const EmptyView(message: 'لا توجد مقالات محفوظة بعد', icon: Icons.bookmark_border)
                  : RefreshIndicator(
                      onRefresh: () async {
                        ref.invalidate(_bookmarksProvider);
                        ref.read(bookmarkedIdsProvider.notifier).refresh();
                      },
                      child: ListView.separated(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.all(16),
                        itemCount: list.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (_, i) => ArticleCard(article: list[i]),
                      ),
                    ),
            ),
    );
  }
}
