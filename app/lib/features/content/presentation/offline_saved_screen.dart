import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

/// Articles the user explicitly saved for offline reading. Reads entirely
/// from the on-device Hive cache, so it works with no connection.
class OfflineSavedScreen extends ConsumerWidget {
  const OfflineSavedScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ids = ref.watch(offlineSavedIdsProvider);
    final articles = ref.watch(contentRepositoryProvider).savedArticles();

    return Scaffold(
      appBar: AppBar(
        title: Text(ids.isEmpty
            ? 'القراءة دون اتصال'
            : 'القراءة دون اتصال (${ids.length})'),
      ),
      body: articles.isEmpty
          ? EmptyView(
              icon: Icons.download_for_offline_outlined,
              message: 'لا توجد مقالات محفوظة للقراءة دون اتصال',
              hint:
                  'افتح أي مقال واضغط أيقونة التنزيل ⬇ لحفظه هنا وقراءته بلا إنترنت.',
              actionLabel: 'تصفّح الأخبار',
              onAction: () => context.go('/'),
            )
          : ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: articles.length,
              separatorBuilder: (_, __) => const SizedBox(height: 10),
              itemBuilder: (_, i) => ArticleCard(article: articles[i]),
            ),
    );
  }
}
