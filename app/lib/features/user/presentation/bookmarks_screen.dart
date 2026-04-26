import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/models/article.dart';
import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../../auth/data/auth_storage.dart';

final _bookmarksProvider = FutureProvider<List<Article>>((ref) async {
  if (!AuthStorage.isAuthenticated) return [];
  final api = ref.watch(apiClientProvider);
  final res = await api.get<List<Article>>(
    '/user/bookmarks',
    decode: (d) => (d as List).whereType<Map>().map((m) => Article.fromJson(m.cast())).toList(),
  );
  return res.data ?? const [];
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
                  : ListView.separated(
                      padding: const EdgeInsets.all(16),
                      itemCount: list.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 10),
                      itemBuilder: (_, i) => ArticleCard(article: list[i]),
                    ),
            ),
    );
  }
}
